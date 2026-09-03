<?php

declare(strict_types=1);

namespace Overlays\Core\Registry;

use Overlays\Core\App;
use Overlays\Core\Support\Http;
use RuntimeException;

/**
 * Zugriff auf den Plugin-Katalog (Standard: plugins.talutah.de).
 *
 * Der Katalog ist eine einzige JSON-Datei. Sie wird geholt, in den
 * Einstellungen zwischengespeichert und danach lokal durchsucht - das
 * spart einen Suchdienst auf der Gegenseite und funktioniert auch, wenn
 * der Katalogserver mal nicht erreichbar ist.
 *
 * Erwartetes Format siehe registry/README.md.
 */
final class Client
{
    public const DEFAULT_URL = 'https://plugins.talutah.de';

    /** Wie lange der zwischengespeicherte Katalog als frisch gilt. */
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
     * Alle Plugins aus dem Katalog. Holt bei Bedarf nach.
     *
     * @return list<array<string, mixed>>
     */
    public function all(bool $fresh = false): array
    {
        if ($this->plugins !== null && !$fresh) {
            return $this->plugins;
        }

        if (!$fresh && !$this->isStale()) {
            $cached = $this->app->settings->get('registry_cache', null);
            if (is_array($cached)) {
                return $this->plugins = self::normalizeList($cached);
            }
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
     * Katalog neu holen.
     *
     * @return list<array<string, mixed>>
     */
    public function refresh(): array
    {
        // Genau eine Adresse. Der Katalog wird nicht hier erzeugt, er
        // wird geladen - dafuer hat der Katalogserver zu sorgen.
        $url = $this->baseUrl() . '/index.json';

        try {
            $result = Http::get($url, ['Accept' => 'application/json'], 20);
        } catch (\Throwable $e) {
            $this->app->settings->set('registry_error', $e->getMessage());
            throw new RuntimeException('Katalog nicht erreichbar: ' . $e->getMessage());
        }

        if (!$result->ok()) {
            $message = sprintf('%s antwortet mit %d.', $url, $result->status);

            if ($result->status === 404) {
                $message .= ' Der Katalogserver liefert dort keinen Katalog aus.';
            }

            $this->app->settings->set('registry_error', $message);
            throw new RuntimeException($message);
        }

        $format = (int) ($result->json['format'] ?? 0);
        if ($format !== 1) {
            $message = 'Der Katalog hat ein unbekanntes Format (' . $format . ').';
            $this->app->settings->set('registry_error', $message);
            throw new RuntimeException($message);
        }

        $raw = $result->json['plugins'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }

        $plugins = self::normalizeList($raw);

        $this->app->settings->setMany([
            'registry_cache'       => $plugins,
            'registry_fetched_at'  => time(),
            'registry_error'       => '',
        ]);

        return $plugins;
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
     * Textsuche über Name, Slug, Kurzbeschreibung, Schlagworte und Autor.
     *
     * @return list<array<string, mixed>>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        $plugins = $this->all();

        if ($query === '') {
            return $plugins;
        }

        // Mehrere Wörter: alle müssen irgendwo vorkommen.
        $words = array_filter(preg_split('/\s+/', self::lower($query)) ?: []);

        return array_values(array_filter($plugins, static function (array $plugin) use ($words): bool {
            $haystack = self::lower(implode(' ', [
                (string) $plugin['slug'],
                (string) $plugin['name'],
                (string) $plugin['summary'],
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
            'summary'     => trim((string) ($entry['summary'] ?? '')),
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

    private static function isHttpUrl(string $url): bool
    {
        return $url !== ''
            && (str_starts_with($url, 'https://') || str_starts_with($url, 'http://'))
            && filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}
