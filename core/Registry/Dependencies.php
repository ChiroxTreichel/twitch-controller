<?php

declare(strict_types=1);

namespace TwitchController\Core\Registry;

use TwitchController\Core\App;
use TwitchController\Core\Plugin\VersionConstraint;

/**
 * ===================================================================
 *  Was mitinstalliert werden muss
 * ===================================================================
 *
 * Ein Plugin kann andere voraussetzen: Twitch-Alerts braucht Alerts.
 * Wer im Marktplatz auf "Installieren" klickt, soll das nicht selbst
 * herausfinden muessen - aber er soll es vorher erfahren.
 *
 * Diese Klasse beantwortet beides:
 *
 *   $plan = (new Dependencies($app))->plan('twitch-alerts');
 *
 *   $plan['order']     in dieser Reihenfolge installieren
 *   $plan['also']      das kommt zusaetzlich dazu (fuer die Ansage)
 *   $plan['present']   ist schon da, wird nicht angefasst
 *   $plan['unknown']   wird gebraucht, steht aber nicht im Katalog
 *   $plan['cycle']     zwei Plugins brauchen sich gegenseitig
 *
 * Die Reihenfolge ist Voraussetzung-zuerst. Andernfalls laeuft die
 * install.php eines Plugins, dessen Abhaengigkeit noch fehlt - und ob
 * das gut geht, haengt vom Plugin ab.
 */
final class Dependencies
{
    /**
     * Grenze fuer die Tiefe der Kette. Nicht gegen Zyklen - die
     * erkennt der Weg selbst - sondern gegen einen Katalog, der aus
     * Versehen tausend Stufen tief verweist.
     */
    private const MAX_DEPTH = 12;

    private Client $registry;

    public function __construct(private readonly App $app)
    {
        $this->registry = new Client($app);
    }

    /**
     * @return array{
     *     order: list<string>,
     *     also: list<array{slug: string, name: string, version: string}>,
     *     present: list<array{slug: string, name: string}>,
     *     unknown: list<string>,
     *     cycle: bool
     * }
     */
    public function plan(string $slug): array
    {
        $slug = strtolower(trim($slug));

        $order = [];
        $also = [];
        $present = [];
        $unknown = [];
        $cycle = false;

        // Der Weg von der Wurzel bis hierher. Steht ein Slug schon
        // darin, verweisen sich zwei Plugins gegenseitig.
        $besuche = function (string $aktuell, array $weg) use (
            &$besuche, &$order, &$also, &$present, &$unknown, &$cycle
        ): void {
            if (in_array($aktuell, $weg, true)) {
                $cycle = true;

                return;
            }

            if (in_array($aktuell, $order, true)) {
                return;
            }

            if (count($weg) >= self::MAX_DEPTH) {
                $unknown[] = $aktuell;

                return;
            }

            $eintrag = $this->registry->find($aktuell);
            if ($eintrag === null) {
                // Nicht im Katalog. Liegt es trotzdem schon auf der
                // Platte, ist alles gut - dann wurde es von Hand
                // hineingelegt.
                if ($this->app->plugins->manifest($aktuell) !== null) {
                    $present[] = ['slug' => $aktuell, 'name' => $aktuell];

                    return;
                }

                $unknown[] = $aktuell;

                return;
            }

            foreach (self::requiredOf($eintrag) as $benoetigt) {
                $besuche($benoetigt, [...$weg, $aktuell]);
            }

            // Schon installiert? Dann nicht anfassen. Ein Update
            // gehoert in die Plugin-Liste, nicht in eine
            // Abhaengigkeitskette - sonst aktualisiert ein Klick auf
            // "Installieren" ungefragt ein anderes Plugin mit.
            if ($this->app->plugins->isInstalled($aktuell)) {
                $present[] = [
                    'slug' => $aktuell,
                    'name' => (string) ($eintrag['name'] ?? $aktuell),
                ];

                return;
            }

            $order[] = $aktuell;

            if ($weg !== []) {
                $also[] = [
                    'slug'    => $aktuell,
                    'name'    => (string) ($eintrag['name'] ?? $aktuell),
                    'version' => (string) ($eintrag['version'] ?? ''),
                ];
            }
        };

        $besuche($slug, []);

        return [
            'order'   => $order,
            'also'    => $also,
            'present' => array_values($present),
            'unknown' => array_values(array_unique($unknown)),
            'cycle'   => $cycle,
        ];
    }

    /**
     * Die Plugin-Slugs aus "requires" eines Katalogeintrags. "core" ist
     * keine Abhaengigkeit, sondern eine Bedingung an die Kernversion.
     *
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    public static function requiredOf(array $entry): array
    {
        $requires = $entry['requires'] ?? [];
        if (!is_array($requires)) {
            return [];
        }

        $slugs = [];
        foreach (array_keys($requires) as $key) {
            $key = strtolower(trim((string) $key));

            if ($key === '' || $key === 'core') {
                continue;
            }

            $slugs[] = $key;
        }

        return array_values(array_unique($slugs));
    }

    /**
     * Womit sich dieser Katalogeintrag NICHT vertraegt - sofern es
     * installiert ist.
     *
     * Gefragt wird in BEIDE Richtungen: der Eintrag nennt ein
     * installiertes Plugin, oder ein installiertes Plugin nennt ihn.
     * Sonst haenge die Sperre daran, welches der beiden zuerst
     * geschrieben wurde.
     *
     * Die eine Richtung kommt aus dem KATALOG und nicht aus einem
     * Manifest: vor dem Download liegt hier keine plugin.json, und
     * genau dann soll die Sperre schon greifen.
     *
     * @param array<string, mixed> $entry Katalogeintrag
     * @return list<array{slug: string, name: string}>
     */
    public function conflictsOf(array $entry): array
    {
        $installiert = [];

        foreach ($this->app->plugins->discover() as $kandidat) {
            if (!$this->app->plugins->isInstalled($kandidat->slug)) {
                continue;
            }

            $installiert[$kandidat->slug] = [
                'name'      => $kandidat->name,
                'version'   => $kandidat->version,
                'conflicts' => $kandidat->conflictingPlugins(),
            ];
        }

        // Installiert, aber der Ordner ist weg: dann gibt es kein
        // Manifest und damit keine Gegenrichtung - der Name bleibt der
        // Slug. Ausgeschlossen wird trotzdem: die Tabellen und
        // Einstellungen dieses Plugins stehen noch.
        foreach (array_keys($this->app->plugins->registered()) as $slug) {
            if (!isset($installiert[$slug])) {
                $installiert[$slug] = [
                    'name'      => $slug,
                    'version'   => (string) $this->app->plugins->installedVersion($slug),
                    'conflicts' => [],
                ];
            }
        }

        return self::conflictsBetween($entry, $installiert);
    }

    /**
     * Die eigentliche Entscheidung, ohne Datenbank.
     *
     * Abgetrennt, weil sie darueber bestimmt, ob sich ein Plugin
     * installieren laesst - und weil sie in beide Richtungen fragt, was
     * man leicht falsch herum baut.
     *
     * @param array<string, mixed> $entry Katalogeintrag oder Manifest-Form
     * @param array<string, array{name: string, version: string, conflicts: array<string, string>}> $installed
     * @return list<array{slug: string, name: string}>
     */
    public static function conflictsBetween(array $entry, array $installed): array
    {
        $slug = strtolower(trim((string) ($entry['slug'] ?? '')));
        if ($slug === '') {
            return [];
        }

        $gefunden = [];

        // Richtung 1: was der Eintrag selbst ausschliesst.
        foreach (is_array($entry['conflicts'] ?? null) ? $entry['conflicts'] : [] as $anderer => $constraint) {
            $anderer = strtolower(trim((string) $anderer));

            if ($anderer === '' || $anderer === 'core' || $anderer === $slug || !isset($installed[$anderer])) {
                continue;
            }

            // Eine Fassungsangabe ist erlaubt: "vertraegt sich nicht mit
            // dem alten Foo" ist eine andere Aussage als "vertraegt sich
            // nicht mit Foo".
            if (!VersionConstraint::satisfies((string) $installed[$anderer]['version'], (string) $constraint)) {
                continue;
            }

            $gefunden[$anderer] = ['slug' => $anderer, 'name' => (string) $installed[$anderer]['name']];
        }

        // Richtung 2: was ein installiertes Plugin gegen diesen Eintrag
        // hat. Ohne sie haenge die Sperre daran, welches der beiden
        // zuerst geschrieben wurde - ein spaeter dazugekommenes Plugin
        // koennte sich neben ein aelteres setzen, das nichts von ihm
        // weiss.
        $fassung = (string) ($entry['version'] ?? '');

        foreach ($installed as $anderer => $zeile) {
            if ($anderer === $slug || isset($gefunden[$anderer])) {
                continue;
            }

            foreach ($zeile['conflicts'] as $genannt => $constraint) {
                if (strtolower(trim((string) $genannt)) !== $slug) {
                    continue;
                }

                // Ohne bekannte Fassung wird nicht gefiltert: lieber
                // sperren als zwei Plugins nebeneinander, die sich
                // ausschliessen.
                if ($fassung !== '' && !VersionConstraint::satisfies($fassung, (string) $constraint)) {
                    continue;
                }

                $gefunden[$anderer] = ['slug' => $anderer, 'name' => (string) $zeile['name']];
            }
        }

        return array_values($gefunden);
    }

    /**
     * Fuer die Ansicht: was braucht dieses Plugin, und wie steht es
     * damit?
     *
     * @param array<string, mixed> $entry Katalogeintrag
     * @return list<array{slug: string, name: string, state: string}>
     *         state: installed | will_install | unknown
     */
    public function describe(array $entry): array
    {
        $benoetigt = self::requiredOf($entry);
        if ($benoetigt === []) {
            return [];
        }

        $zeilen = [];

        foreach ($benoetigt as $slug) {
            $eintrag = $this->registry->find($slug);

            if ($this->app->plugins->isInstalled($slug)) {
                $zustand = 'installed';
            } elseif ($eintrag !== null) {
                $zustand = 'will_install';
            } else {
                $zustand = 'unknown';
            }

            $zeilen[] = [
                'slug'  => $slug,
                'name'  => (string) ($eintrag['name'] ?? $slug),
                'state' => $zustand,
            ];
        }

        return $zeilen;
    }
}
