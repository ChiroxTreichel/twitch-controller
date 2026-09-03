<?php

declare(strict_types=1);

namespace TwitchController\Core\Registry;

use TwitchController\Core\App;
use TwitchController\Core\Support\Http;
use TwitchController\Core\Support\Markdown;
use RuntimeException;

/**
 * Zugriff auf den Plugin-Katalog (Standard: plugins.talutah.de).
 *
 * Der Katalog wird bei jedem Aufruf live geholt, und gesucht wird auf
 * dem Katalogserver (?search=) - er kennt seinen Bestand. So sieht der
 * Marktplatz sofort, was dort veroeffentlicht wurde.
 *
 * Zwischengespeichert wird nur eines: die letzte vollstaendige Antwort,
 * als Rueckfall fuer die Liste der installierten Plugins. Die wird bei
 * jedem Seitenaufruf gebraucht und darf nicht auf einen fremden Server
 * warten. Siehe cached().
 *
 * Erwartetes Format siehe registry/README.md.
 */
final class Client
{
    public const DEFAULT_URL = 'https://plugins.talutah.de';

    /** Katalogformat, das dieses System versteht. */
    public const FORMAT = 1;

    /** Mehr als das ist keine Beschreibung mehr. */
    private const MAX_README_BYTES = 262144;

    /** Ab wann der Rueckfall-Katalog als veraltet gilt. */
    private const CACHE_SECONDS = 3600;

    /** @var list<array<string, mixed>>|null */
    private ?array $plugins = null;

    public function __construct(private readonly App $app)
    {
    }

    public function baseUrl(): string
    {
        $url = $this->app->settings->string('registry_url', self::DEFAULT_URL);

        return rtrim($url === '' ? self::DEFAULT_URL : $url, '/');
    }

    public function isConfigured(): bool
    {
        return str_starts_with($this->baseUrl(), 'https://')
            || str_starts_with($this->baseUrl(), 'http://');
    }

    // -----------------------------------------------------------------
    //  Katalog
    // -----------------------------------------------------------------

    /**
     * Alle Plugins aus dem Katalog - immer frisch vom Server.
     *
     * Der Katalog wird live abgefragt. Zwischengespeichert wird nur
     * innerhalb eines Requests ($this->plugins) und als Rueckfall fuer
     * die Liste der installierten Plugins (siehe cached()) - dort darf
     * kein Seitenaufruf auf einen fremden Server warten.
     *
     * @return list<array<string, mixed>>
     */
    public function all(bool $fresh = false): array
    {
        if ($this->plugins !== null && !$fresh) {
            return $this->plugins;
        }

        return $this->plugins = $this->refresh();
    }

    /**
     * Nur der zwischengespeicherte Katalog, ohne ins Netz zu gehen.
     * Dafuer, dass die Liste der installierten Plugins nicht bei jedem
     * Aufruf auf einen fremden Server warten muss.
     *
     * @return list<array<string, mixed>>
     */
    public function cached(): array
    {
        if ($this->plugins !== null) {
            return $this->plugins;
        }

        $cached = $this->app->settings->get('registry_cache', null);

        return is_array($cached) ? self::normalizeList($cached) : [];
    }

    /**
     * Katalogeintrag aus dem Zwischenspeicher, ohne Netzzugriff.
     *
     * @return array<string, mixed>|null
     */
    public function cachedEntry(string $slug): ?array
    {
        $slug = strtolower(trim($slug));

        foreach ($this->cached() as $plugin) {
            if ($plugin['slug'] === $slug) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * Ist der Rueckfall-Katalog veraltet? Betrifft nur cached(); die
     * Marktplatz-Ansichten holen ohnehin live.
     */
    public function isStale(): bool
    {
        $at = $this->app->settings->int('registry_fetched_at', 0);

        return $at === 0 || (time() - $at) > self::CACHE_SECONDS;
    }

    public function fetchedAt(): int
    {
        return $this->app->settings->int('registry_fetched_at', 0);
    }

    public function lastError(): string
    {
        return $this->app->settings->string('registry_error');
    }

    /**
     * Katalog holen. Mit Suchbegriff sucht der Katalogserver, nicht
     * dieser Rechner - dann geht nur ueber die Leitung, was gebraucht
     * wird.
     *
     * @return list<array<string, mixed>>
     */
    public function refresh(string $search = ''): array
    {
        // Genau eine Adresse. Der Katalog wird nicht hier erzeugt, er
        // wird geladen - dafuer hat der Katalogserver zu sorgen.
        //
        // Bewusst /index.php und nicht /index.json: die PHP-Datei
        // antwortet immer, auch bevor auf dem Katalogserver ein Paket
        // veroeffentlicht wurde und ohne dass dort mod_rewrite laeuft.
        $url = $this->baseUrl() . '/index.php';

        $search = trim($search);
        if ($search !== '') {
            $url .= '?search=' . rawurlencode($search);
        }

        try {
            $result = Http::get($url, ['Accept' => 'application/json'], 20);
        } catch (\Throwable $e) {
            $this->app->settings->set('registry_error', $e->getMessage());
            throw new RuntimeException('Katalog nicht erreichbar: ' . $e->getMessage());
        }

        if (!$result->ok()) {
            $message = sprintf('%s antwortet mit %d.', $url, $result->status);

            if ($result->status === 404) {
                $message .= ' Liegt der Katalogserver wirklich unter dieser Adresse,'
                    . ' und ist index.php dort erreichbar?';
            }

            $this->app->settings->set('registry_error', $message);
            throw new RuntimeException($message);
        }

        // Nicht ueber $result->json pruefen: das ist auch bei einer
        // Antwort, die gar kein JSON ist, ein leeres Array - eine
        // HTML-Fehlerseite waere von einem leeren Katalog nicht zu
        // unterscheiden.
        $decoded = json_decode($result->body, true);

        if (!is_array($decoded)) {
            $message = sprintf(
                '%s antwortet nicht mit JSON. Anfang der Antwort: %s',
                $url,
                self::anriss($result->body)
            );
            $this->app->settings->set('registry_error', $message);
            throw new RuntimeException($message);
        }

        // Zwei Formen sind erlaubt: das Objekt mit "format" und
        // "plugins", das der eigene Katalogserver liefert, oder eine
        // nackte Liste von Plugins. Eine leere Liste ist ein gueltiger
        // Katalog - der Server hat dann einfach noch nichts
        // veroeffentlicht - und darf deshalb kein Fehler sein.
        if (array_is_list($decoded)) {
            $raw = $decoded;
        } else {
            $format = (int) ($decoded['format'] ?? self::FORMAT);

            // Nur ein neueres Format ist ein echtes Problem: dort koennen
            // Felder anders gemeint sein. Aeltere lesen wir mit.
            if ($format > self::FORMAT) {
                $message = translate('registry.error.format_newer', [
                    'format'  => $format,
                    'support' => self::FORMAT,
                ]);
                $this->app->settings->set('registry_error', $message);
                throw new RuntimeException($message);
            }

            $raw = is_array($decoded['plugins'] ?? null) ? $decoded['plugins'] : [];
        }

        $plugins = self::normalizeList($raw);

        // Den Rueckfall-Katalog nur aus einer vollstaendigen Antwort
        // schreiben. Ein Suchergebnis ist ausschnittsweise - stuende es
        // hier, waere die Liste der installierten Plugins danach der
        // Meinung, die uebrigen gaebe es nicht mehr.
        if ($search === '') {
            $this->app->settings->setMany([
                'registry_cache'       => $plugins,
                'registry_fetched_at'  => time(),
                'registry_error'       => '',
            ]);
        } else {
            $this->app->settings->set('registry_error', '');
        }

        return $plugins;
    }

    /**
     * Beschreibungstext eines Plugins, als HTML zum Ausgeben.
     *
     * Der Text steht nicht im Katalog, sondern hinter einer eigenen
     * Adresse - so bleibt der Katalog klein, auch wenn eine README lang
     * ist. Geholt wird er erst hier, also wenn jemand die
     * Beschreibungsseite tatsaechlich oeffnet.
     *
     * Gerendert wird eine enge Markdown-Teilmenge, und zwar hier und
     * nicht auf dem Katalogserver: der Text kommt von fremd, und ein
     * Plugin-Autor soll kein HTML in diese Verwaltung schreiben
     * koennen. Siehe Support\Markdown.
     *
     * @param array<string, mixed> $plugin Katalogeintrag
     * @return array{html: string, error: string}
     */
    public function readme(array $plugin): array
    {
        $url = (string) ($plugin['readme'] ?? '');
        if ($url === '') {
            return ['html' => '', 'error' => ''];
        }

        // Nur von dem Host, der als Katalog eingestellt ist. Ein
        // manipulierter Katalog kann diese Verwaltung sonst dazu
        // bringen, eine beliebige Adresse abzurufen.
        if (!$this->sameHost($url)) {
            return ['html' => '', 'error' => translate('market.readme.foreign_host')];
        }

        $slug = (string) ($plugin['slug'] ?? '');
        $stand = (string) ($plugin['updated_at'] ?? '');
        $schluessel = 'registry_readme_' . $slug;

        // Klein zwischenlagern, mit der Fassung als Schluessel: solange
        // sich am Plugin nichts aendert, wird der Text nicht erneut
        // geholt.
        $gelagert = $this->app->settings->get($schluessel, null);
        if (is_array($gelagert)
            && ($gelagert['stand'] ?? '') === $stand
            && is_string($gelagert['markdown'] ?? null)
        ) {
            return ['html' => Markdown::render($gelagert['markdown']), 'error' => ''];
        }

        try {
            $ergebnis = Http::get($url, ['Accept' => 'text/markdown, text/plain'], 15);
        } catch (\Throwable $e) {
            return ['html' => '', 'error' => $e->getMessage()];
        }

        if (!$ergebnis->ok()) {
            return [
                'html'  => '',
                'error' => translate('market.readme.unreachable', ['status' => $ergebnis->status]),
            ];
        }

        $markdown = $ergebnis->body;
        if (strlen($markdown) > self::MAX_README_BYTES) {
            $markdown = substr($markdown, 0, self::MAX_README_BYTES);
        }

        $this->app->settings->set($schluessel, ['stand' => $stand, 'markdown' => $markdown]);

        return ['html' => Markdown::render($markdown), 'error' => ''];
    }

    /**
     * Zeigt die Adresse auf denselben Host wie der Katalog?
     */
    private function sameHost(string $url): bool
    {
        $ziel = parse_url($url, PHP_URL_HOST);
        $katalog = parse_url($this->baseUrl(), PHP_URL_HOST);

        return is_string($ziel)
            && is_string($katalog)
            && strcasecmp($ziel, $katalog) === 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        $slug = strtolower(trim($slug));

        foreach ($this->all() as $plugin) {
            if ($plugin['slug'] === $slug) {
                return $plugin;
            }
        }

        return null;
    }

    /**
     * Suche im Katalog.
     *
     * Gesucht wird auf dem Katalogserver (?search=), nicht hier - er
     * kennt seinen Bestand, und so geht nur das ueber die Leitung, was
     * angezeigt wird.
     *
     * Danach wird trotzdem noch lokal gefiltert. Nicht aus Misstrauen,
     * sondern weil ein Katalogserver den Parameter nicht kennen muss:
     * ignoriert er ihn und liefert alles, stimmt die Anzeige dennoch.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if ($query === '') {
            return $this->all();
        }

        $plugins = $this->plugins = $this->refresh($query);

        // Mehrere Wörter: alle müssen irgendwo vorkommen.
        $words = array_filter(preg_split('/\s+/', self::lower($query)) ?: []);

        return array_values(array_filter($plugins, static function (array $plugin) use ($words): bool {
            $haystack = self::lower(implode(' ', [
                (string) $plugin['slug'],
                (string) $plugin['name'],
                (string) $plugin['summary'],
                (string) $plugin['description'],
                (string) $plugin['author'],
                implode(' ', $plugin['tags']),
            ]));

            foreach ($words as $word) {
                if (!str_contains($haystack, $word)) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * Alle im Katalog vorkommenden Schlagworte, für die Filterleiste.
     *
     * @return list<string>
     */
    public function tags(): array
    {
        $tags = [];
        foreach ($this->all() as $plugin) {
            foreach ($plugin['tags'] as $tag) {
                $tags[$tag] = true;
            }
        }

        $names = array_keys($tags);
        sort($names);

        return $names;
    }

    // -----------------------------------------------------------------

    /**
     * @param array<mixed> $raw
     * @return list<array<string, mixed>>
     */
    private static function normalizeList(array $raw): array
    {
        $plugins = [];

        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $normalized = self::normalize($entry);
            if ($normalized !== null) {
                $plugins[$normalized['slug']] = $normalized;
            }
        }

        ksort($plugins);

        return array_values($plugins);
    }

    /**
     * Katalogeintrag auf eine feste Form bringen. Alles, was hinterher
     * angezeigt wird, ist danach garantiert vorhanden und vom richtigen
     * Typ - die Views brauchen keine Fallunterscheidungen.
     *
     * @param array<string, mixed> $entry
     * @return array<string, mixed>|null
     */
    private static function normalize(array $entry): ?array
    {
        $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]$/', $slug)) {
            return null;
        }

        $version = trim((string) ($entry['version'] ?? ''));
        if (!preg_match('/^\d+\.\d+\.\d+/', $version)) {
            return null;
        }

        $tags = $entry['tags'] ?? [];
        $tags = is_array($tags) ? array_values(array_map('strval', $tags)) : [];

        $screenshots = $entry['screenshots'] ?? [];
        $screenshots = is_array($screenshots)
            ? array_values(array_filter(array_map('strval', $screenshots), [self::class, 'isHttpUrl']))
            : [];

        $download = (string) ($entry['download'] ?? '');
        if (!self::isHttpUrl($download)) {
            return null;
        }

        return [
            'slug'        => $slug,
            'name'        => trim((string) ($entry['name'] ?? $slug)),
            'version'     => $version,
            // Ein schlanker Katalogserver schickt nur description.
            // Dann hier den ersten Satz als Kurzfassung nehmen, damit
            // die Liste im Marktplatz nicht leer aussieht.
            'summary'     => trim((string) ($entry['summary'] ?? '')) !== ''
                ? trim((string) $entry['summary'])
                : self::kurzfassung(trim((string) ($entry['description'] ?? ''))),
            'description' => trim((string) ($entry['description'] ?? '')),
            'author'      => trim((string) ($entry['author'] ?? '')),
            'homepage'    => self::isHttpUrl((string) ($entry['homepage'] ?? ''))
                ? (string) $entry['homepage']
                : '',
            'tags'        => $tags,
            'icon'        => self::isHttpUrl((string) ($entry['icon'] ?? ''))
                ? (string) $entry['icon']
                : '',
            'screenshots' => $screenshots,
            'requires'    => is_array($entry['requires'] ?? null) ? $entry['requires'] : [],
            'optional'    => is_array($entry['optional'] ?? null) ? $entry['optional'] : [],
            'download'    => $download,
            // Adresse des Langtextes. Geholt wird er erst, wenn jemand
            // die Beschreibungsseite oeffnet - so bleibt der Katalog
            // klein, auch wenn eine README lang ist.
            'readme'      => self::isHttpUrl((string) ($entry['readme'] ?? ''))
                ? (string) $entry['readme']
                : '',
            'sha256'      => strtolower(trim((string) ($entry['sha256'] ?? ''))),
            'signature'   => trim((string) ($entry['signature'] ?? '')),
            'size'        => (int) ($entry['size'] ?? 0),
            'updated_at'  => trim((string) ($entry['updated_at'] ?? '')),
        ];
    }

    /**
     * Kleinschreibung, notfalls ohne mbstring. Die Erweiterung ist im
     * mitgelieferten Image dabei, aber dieser Code soll auch auf einer
     * kargen PHP-Installation nicht mit einem Fatal Error aussteigen.
     */
    private static function lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
    }

    /**
     * Erster Satz einer Beschreibung, fuer die Liste.
     */
    private static function kurzfassung(string $beschreibung): string
    {
        $zeile = trim(explode("\n", str_replace("\r", "\n", $beschreibung))[0]);
        if ($zeile === '') {
            return '';
        }

        if (preg_match('/^(.{20,180}?[.!?])\s/u', $zeile . ' ', $treffer) === 1) {
            return $treffer[1];
        }

        // Nicht mb_strlen/mb_substr ohne Netz: die Erweiterung ist im
        // mitgelieferten Image dabei, aber diese Klasse soll auch auf
        // einer karg gebauten PHP-Installation nicht aussteigen. Siehe
        // lower() weiter unten.
        if (function_exists('mb_strlen')) {
            return mb_strlen($zeile) > 180 ? mb_substr($zeile, 0, 177) . '...' : $zeile;
        }

        return strlen($zeile) > 180 ? substr($zeile, 0, 177) . '...' : $zeile;
    }

    private static function isHttpUrl(string $url): bool
    {
        return $url !== ''
            && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'))
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Erste Zeichen einer Antwort, fuer die Fehlermeldung. Damit man
     * sieht, ob da eine Fehlerseite des Proxys statt des Katalogs kam.
     */
    private static function anriss(string $body): string
    {
        $body = trim((string) preg_replace('/\s+/', ' ', $body));

        if ($body === '') {
            return '(leer)';
        }

        return strlen($body) > 120 ? substr($body, 0, 120) . ' ...' : $body;
    }
}
