<?php

declare(strict_types=1);

namespace TwitchController\Core\Plugin;

use TwitchController\Core\App;
use TwitchController\Core\Config\Settings;
use RuntimeException;
use Throwable;

/**
 * Lebenszyklus der Plugins.
 *
 * Zustaende:
 *   verfuegbar   Ordner unter plugins/ vorhanden, nicht in der DB
 *   installiert  in der DB, Schema angelegt, aber nicht aktiv
 *   aktiv        wird bei jedem Request geladen
 *   fehlend      in der DB, aber der Ordner ist weg
 *
 * Reihenfolge beim Laden ergibt sich aus den Abhaengigkeiten: ein Plugin
 * wird immer nach denen geladen, die es braucht (harte wie weiche). So
 * kann Throne beim Laden pruefen, ob Alerts da ist.
 */
final class PluginManager
{
    /** @var array<string, Manifest>|null */
    private ?array $discovered = null;

    /** @var array<string, array<string, mixed>>|null */
    private ?array $registered = null;

    /** @var list<string> */
    private array $booted = [];

    public function __construct(
        private readonly App $app,
        private readonly string $directory,
    ) {
    }

    // -----------------------------------------------------------------
    //  Lesen
    // -----------------------------------------------------------------

    /**
     * Alle Plugins, deren Ordner vorhanden ist.
     *
     * @return array<string, Manifest>
     */
    public function discover(bool $fresh = false): array
    {
        if ($this->discovered !== null && !$fresh) {
            return $this->discovered;
        }

        $found = [];
        foreach (glob($this->directory . '/*/plugin.json') ?: [] as $file) {
            try {
                $manifest = Manifest::fromDirectory(dirname($file));
                $found[$manifest->slug] = $manifest;
            } catch (Throwable $e) {
                $this->app->log('Plugin uebersprungen (' . dirname($file) . '): ' . $e->getMessage());
            }
        }
        ksort($found);

        return $this->discovered = $found;
    }

    public function manifest(string $slug): ?Manifest
    {
        return $this->discover()[strtolower($slug)] ?? null;
    }

    /**
     * Registrierte Plugins aus der Datenbank.
     *
     * @return array<string, array<string, mixed>>
     */
    public function registered(bool $fresh = false): array
    {
        if ($this->registered !== null && !$fresh) {
            return $this->registered;
        }

        $rows = [];
        foreach ($this->app->db->all('SELECT * FROM plugins ORDER BY slug') as $row) {
            $rows[(string) $row['slug']] = $row;
        }

        return $this->registered = $rows;
    }

    public function isInstalled(string $slug): bool
    {
        return isset($this->registered()[strtolower($slug)]);
    }

    public function isEnabled(string $slug): bool
    {
        $row = $this->registered()[strtolower($slug)] ?? null;

        return $row !== null && (bool) $row['enabled'];
    }

    public function installedVersion(string $slug): ?string
    {
        $row = $this->registered()[strtolower($slug)] ?? null;

        return $row === null ? null : (string) $row['version'];
    }

    /**
     * In der DB registriert, aber der Ordner ist verschwunden.
     *
     * @return list<string>
     */
    public function missing(): array
    {
        $available = $this->discover();

        return array_values(array_filter(
            array_keys($this->registered()),
            static fn (string $slug): bool => !isset($available[$slug])
        ));
    }

    // -----------------------------------------------------------------
    //  Abhaengigkeiten
    // -----------------------------------------------------------------

    /**
     * Gruende, die gegen ein Aktivieren sprechen. Leer = geht.
     *
     * @return list<string>
     */
    public function blockers(string $slug): array
    {
        $manifest = $this->manifest($slug);
        if ($manifest === null) {
            return ['Plugin nicht gefunden.'];
        }

        $problems = [];

        $coreConstraint = $manifest->coreConstraint();
        if ($coreConstraint !== null && !VersionConstraint::satisfies(App::VERSION, $coreConstraint)) {
            $problems[] = sprintf(
                'Braucht Kern %s, installiert ist %s.',
                $coreConstraint,
                App::VERSION
            );
        }

        foreach ($manifest->requiredPlugins() as $needed => $constraint) {
            $neededManifest = $this->manifest($needed);
            if ($neededManifest === null) {
                $problems[] = sprintf('Plugin "%s" fehlt (%s benoetigt).', $needed, $constraint);
                continue;
            }

            if (!$this->isEnabled($needed)) {
                $problems[] = sprintf('Plugin "%s" muss aktiv sein.', $neededManifest->name);
                continue;
            }

            if (!VersionConstraint::satisfies($neededManifest->version, $constraint)) {
                $problems[] = sprintf(
                    'Plugin "%s" %s installiert, gebraucht wird %s.',
                    $neededManifest->name,
                    $neededManifest->version,
                    $constraint
                );
            }
        }

        return $problems;
    }

    /**
     * Aktive Plugins, die dieses hier hart brauchen - also das, was beim
     * Deaktivieren mit abgeschaltet werden muesste.
     *
     * @return list<string>
     */
    public function activeDependents(string $slug): array
    {
        $slug = strtolower($slug);
        $dependents = [];

        foreach ($this->discover() as $candidate) {
            if (!$this->isEnabled($candidate->slug)) {
                continue;
            }
            if (array_key_exists($slug, $candidate->requiredPlugins())) {
                $dependents[] = $candidate->slug;
            }
        }

        return $dependents;
    }

    /**
     * Ladereihenfolge: Abhaengigkeiten zuerst. Zyklen werden gemeldet und
     * die betroffenen Plugins hinten angehaengt, damit ein Fehler im
     * Manifest nicht das ganze System lahmlegt.
     *
     * @param list<string> $slugs
     * @return list<string>
     */
    public function resolveOrder(array $slugs): array
    {
        $manifests = $this->discover();
        $wanted = array_values(array_filter($slugs, static fn (string $s): bool => isset($manifests[$s])));

        $ordered = [];
        $state = [];

        $visit = function (string $slug) use (&$visit, &$ordered, &$state, $manifests, $wanted): void {
            if (($state[$slug] ?? '') === 'done') {
                return;
            }
            if (($state[$slug] ?? '') === 'open') {
                $this->app->log("Plugin-Abhaengigkeiten bilden einen Zyklus bei \"{$slug}\".");
                return;
            }

            $state[$slug] = 'open';

            $manifest = $manifests[$slug] ?? null;
            if ($manifest !== null) {
                $dependencies = array_keys($manifest->requiredPlugins() + $manifest->optionalPlugins());
                foreach ($dependencies as $dependency) {
                    if (in_array($dependency, $wanted, true)) {
                        $visit($dependency);
                    }
                }
            }

            $state[$slug] = 'done';
            $ordered[] = $slug;
        };

        foreach ($wanted as $slug) {
            $visit($slug);
        }

        return $ordered;
    }

    // -----------------------------------------------------------------
    //  Laden
    // -----------------------------------------------------------------

    /**
     * Laedt alle aktiven Plugins. Ein Plugin, das beim Laden eine
     * Exception wirft, wird uebersprungen und protokolliert - der Rest
     * des Systems bleibt bedienbar, insbesondere die Plugin-Verwaltung.
     */
    public function boot(): void
    {
        $enabled = array_keys(array_filter(
            $this->registered(),
            static fn (array $row): bool => (bool) $row['enabled']
        ));

        foreach ($this->resolveOrder($enabled) as $slug) {
            $manifest = $this->manifest($slug);
            if ($manifest === null) {
                continue;
            }

            if ($this->blockers($slug) !== []) {
                $this->app->log("Plugin \"{$slug}\" nicht geladen: Abhaengigkeiten unerfuellt.");
                continue;
            }

            try {
                // Sprachdatei des Plugins, falls vorhanden. Vor dem
                // Laden, damit das Plugin beim Registrieren schon
                // uebersetzte Beschriftungen benutzen kann.
                \TwitchController\Core\I18n\Translator::instance()
                    ->loadDirectory($manifest->directory . '/lang');

                $this->app->hooks->withSource($slug, function () use ($manifest): void {
                    $this->app->router->withSource($manifest->slug, function () use ($manifest): void {
                        self::includeInIsolation($manifest->entryFile(), [
                            'app'      => $this->app,
                            'plugin'   => $manifest,
                            'settings' => $this->app->settings,
                            'hooks'    => $this->app->hooks,
                            'router'   => $this->app->router,
                            'db'       => $this->app->db,
                        ]);
                    });
                });

                $this->booted[] = $slug;
            } catch (Throwable $e) {
                $this->app->log("Plugin \"{$slug}\" konnte nicht geladen werden: " . $e->getMessage());
            }
        }

        $this->app->hooks->dispatch('plugins.booted', $this->booted);
    }

    /**
     * @return list<string>
     */
    public function bootedSlugs(): array
    {
        return $this->booted;
    }

    // -----------------------------------------------------------------
    //  Schreiben
    // -----------------------------------------------------------------

    /**
     * Schema anlegen und registrieren. Aktiviert noch nicht.
     */
    public function install(string $slug): void
    {
        $manifest = $this->manifest($slug);
        if ($manifest === null) {
            throw new RuntimeException("Plugin \"{$slug}\" nicht gefunden.");
        }

        if ($this->isInstalled($manifest->slug)) {
            $this->upgradeIfNeeded($manifest);
            return;
        }

        $this->runLifecycle($manifest, $manifest->installFile(), null);

        $this->app->db->run(
            'INSERT INTO plugins (slug, version, enabled, manifest)
             VALUES (:slug, :version, false, CAST(:manifest AS JSONB))
             ON CONFLICT (slug) DO UPDATE
                SET version = EXCLUDED.version,
                    manifest = EXCLUDED.manifest,
                    updated_at = now()',
            [
                'slug'     => $manifest->slug,
                'version'  => $manifest->version,
                'manifest' => (string) json_encode($manifest->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        $this->registered = null;
        $this->app->hooks->dispatch('plugin.installed', $manifest->slug);
    }

    public function enable(string $slug): void
    {
        $manifest = $this->manifest($slug);
        if ($manifest === null) {
            throw new RuntimeException("Plugin \"{$slug}\" nicht gefunden.");
        }

        if (!$this->isInstalled($manifest->slug)) {
            $this->install($manifest->slug);
        } else {
            $this->upgradeIfNeeded($manifest);
        }

        $blockers = $this->blockers($manifest->slug);
        if ($blockers !== []) {
            throw new RuntimeException(
                'Kann "' . $manifest->name . '" nicht aktivieren: ' . implode(' ', $blockers)
            );
        }

        $this->app->db->run(
            'UPDATE plugins SET enabled = true, updated_at = now() WHERE slug = :slug',
            ['slug' => $manifest->slug]
        );

        $this->registered = null;
        $this->app->hooks->dispatch('plugin.activated', $manifest->slug);
    }

    public function disable(string $slug): void
    {
        $slug = strtolower($slug);

        $dependents = $this->activeDependents($slug);
        if ($dependents !== []) {
            throw new RuntimeException(
                'Zuerst deaktivieren: ' . implode(', ', $dependents) . ' - haengt davon ab.'
            );
        }

        $this->app->db->run(
            'UPDATE plugins SET enabled = false, updated_at = now() WHERE slug = :slug',
            ['slug' => $slug]
        );

        $this->registered = null;
        $this->app->hooks->dispatch('plugin.deactivated', $slug);
    }

    /**
     * Entfernt Schema und Einstellungen. Der Ordner bleibt liegen - das
     * Loeschen von Dateien macht die Plugin-Quelle, nicht die Verwaltung.
     */
    public function uninstall(string $slug): void
    {
        $slug = strtolower($slug);

        if ($this->isEnabled($slug)) {
            $this->disable($slug);
        }

        $manifest = $this->manifest($slug);
        if ($manifest !== null) {
            $this->runLifecycle($manifest, $manifest->uninstallFile(), $this->installedVersion($slug));
        }

        $this->app->settings->forgetScope(Settings::pluginScope($slug));
        $this->app->db->run('DELETE FROM plugins WHERE slug = :slug', ['slug' => $slug]);

        $this->registered = null;
        $this->app->hooks->dispatch('plugin.uninstalled', $slug);
    }

    /**
     * Nach einem Update der Dateien: install.php erneut laufen lassen,
     * damit fehlende Spalten oder Tabellen nachgezogen werden.
     */
    public function upgradeIfNeeded(Manifest $manifest): void
    {
        $installed = $this->installedVersion($manifest->slug);
        if ($installed === null || version_compare($installed, $manifest->version, '>=')) {
            return;
        }

        $this->runLifecycle($manifest, $manifest->installFile(), $installed);

        $this->app->db->run(
            'UPDATE plugins
                SET version = :version, manifest = CAST(:manifest AS JSONB), updated_at = now()
              WHERE slug = :slug',
            [
                'slug'     => $manifest->slug,
                'version'  => $manifest->version,
                'manifest' => (string) json_encode($manifest->raw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        $this->registered = null;
        $this->app->hooks->dispatch('plugin.upgraded', $manifest->slug, $installed, $manifest->version);
    }

    private function runLifecycle(Manifest $manifest, ?string $file, ?string $fromVersion): void
    {
        if ($file === null) {
            return;
        }

        self::includeInIsolation($file, [
            'app'         => $this->app,
            'plugin'      => $manifest,
            'db'          => $this->app->db,
            'settings'    => $this->app->settings,
            'fromVersion' => $fromVersion,
        ]);
    }

    /**
     * Plugin-Datei ohne Zugriff auf $this einbinden.
     *
     * @param array<string, mixed> $variables
     */
    private static function includeInIsolation(string $file, array $variables): void
    {
        (static function (array $__variables, string $__file): void {
            extract($__variables, EXTR_SKIP);
            require $__file;
        })($variables, $file);
    }
}
