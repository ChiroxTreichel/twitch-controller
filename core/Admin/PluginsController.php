<?php

declare(strict_types=1);

namespace Overlays\Core\Admin;

use Overlays\Core\App;
use Overlays\Core\Http\Request;
use Overlays\Core\Http\Response;
use Overlays\Core\Registry\Client;
use Overlays\Core\Registry\Installer;
use Throwable;

/**
 * Konto > Plugins, zwei Reiter:
 *
 *   Installierte Plugins   was auf diesem Server liegt, samt Verwaltung
 *                          und Verweis auf die Einstellungen des Plugins
 *   Plugins finden         Katalog von plugins.talutah.de mit Suche und
 *                          eigener Detailseite (kein iframe)
 */
final class PluginsController
{
    public function __construct(private readonly App $app)
    {
    }

    // -----------------------------------------------------------------
    //  Reiter 1: Installierte Plugins
    // -----------------------------------------------------------------

    public function installed(Request $request): Response
    {
        $registry = new Client($this->app);
        $settingsPages = $this->settingsPages();
        $rows = [];

        foreach ($this->app->plugins->discover(true) as $manifest) {
            $installedVersion = $this->app->plugins->installedVersion($manifest->slug);

            // Neuere Version im Katalog? Absichtlich nur aus dem
            // Zwischenspeicher - diese Seite soll nicht auf einen fremden
            // Server warten.
            $catalogEntry = $registry->cachedEntry($manifest->slug);
            $catalogVersion = $catalogEntry === null ? null : (string) $catalogEntry['version'];

            $rows[] = [
                'manifest'   => $manifest,
                'installed'  => $installedVersion !== null,
                'enabled'    => $this->app->plugins->isEnabled($manifest->slug),
                'version'    => $installedVersion,
                'updatable'  => $installedVersion !== null
                    && version_compare($installedVersion, $manifest->version, '<'),
                'catalog'    => $catalogVersion !== null
                    && version_compare($manifest->version, $catalogVersion, '<')
                        ? $catalogVersion
                        : null,
                'blockers'   => $this->app->plugins->blockers($manifest->slug),
                'dependents' => $this->app->plugins->activeDependents($manifest->slug),
                'settings'   => $settingsPages[$manifest->slug] ?? null,
            ];
        }

        return Response::html($this->app->view->render('account/plugins', [
            'title'     => 'Plugins',
            'active'    => 'konto/plugins',
            'tab'       => 'installiert',
            'rows'      => $rows,
            'missing'   => $this->app->plugins->missing(),
            'canManage' => $this->app->auth->can('Konto.Plugins.Manage'),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('notice'),
            'error'     => $request->get('error'),
            'welcome'   => $request->get('willkommen') !== '',
        ]));
    }

    public function action(Request $request): Response
    {
        $guard = $this->guard($request);
        if ($guard !== null) {
            return $guard;
        }

        $slug = $request->input('slug');
        $action = $request->input('action');

        try {
            switch ($action) {
                case 'enable':
                    $this->app->plugins->enable($slug);

                    return $this->back('/account/plugins',
                        'Plugin aktiviert. Falls es Twitch-Events braucht: einmal '
                        . 'Einstellungen → Abos abgleichen.');

                case 'disable':
                    $this->app->plugins->disable($slug);

                    return $this->back('/account/plugins', 'Plugin deaktiviert.');

                case 'uninstall':
                    $this->app->plugins->uninstall($slug);

                    return $this->back('/account/plugins', 'Plugin entfernt, seine Daten sind gelöscht.');

                case 'update':
                    $manifest = $this->app->plugins->manifest($slug);
                    if ($manifest !== null) {
                        $this->app->plugins->upgradeIfNeeded($manifest);
                    }

                    return $this->back('/account/plugins', 'Plugin aktualisiert.');

                case 'download_update':
                    return $this->installFromRegistry($slug, '/account/plugins');
            }
        } catch (Throwable $e) {
            return $this->back('/account/plugins', null, $e->getMessage());
        }

        return $this->back('/account/plugins', null, 'Unbekannte Aktion.');
    }

    // -----------------------------------------------------------------
    //  Reiter 2: Plugins finden
    // -----------------------------------------------------------------

    public function find(Request $request): Response
    {
        $registry = new Client($this->app);
        $query = trim($request->get('q'));
        $tag = trim($request->get('tag'));

        $error = $request->get('error');
        $plugins = [];
        $tags = [];

        try {
            $plugins = $query !== '' ? $registry->search($query) : $registry->all();

            if ($tag !== '') {
                $plugins = array_values(array_filter(
                    $plugins,
                    static fn (array $p): bool => in_array($tag, $p['tags'], true)
                ));
            }

            $tags = $registry->tags();
        } catch (Throwable $e) {
            if ($error === '') {
                $error = $e->getMessage();
            }
        }

        return Response::html($this->app->view->render('account/plugins_find', [
            'title'      => 'Plugins finden',
            'active'     => 'konto/plugins',
            'tab'        => 'finden',
            'plugins'    => $plugins,
            'tags'       => $tags,
            'query'      => $query,
            'tag'        => $tag,
            'registry'   => $registry->baseUrl(),
            'fetchedAt'  => $registry->fetchedAt(),
            'canManage'  => $this->app->auth->can('Konto.Plugins.Manage'),
            'canWrite'   => (new Installer($this->app))->canWrite(),
            'csrf'       => $this->app->auth->csrfToken(),
            'notice'     => $request->get('notice'),
            'error'      => $error,
            'states'     => $this->installStates(),
        ]));
    }

    public function detail(Request $request, array $params): Response
    {
        $registry = new Client($this->app);
        $slug = (string) ($params['slug'] ?? '');

        try {
            $plugin = $registry->find($slug);
        } catch (Throwable $e) {
            return $this->back('/account/plugins/find', null, $e->getMessage());
        }

        if ($plugin === null) {
            return Response::html($this->app->view->render('error', [
                'title'   => 'Nicht gefunden',
                'heading' => 'Dieses Plugin gibt es nicht',
                'message' => 'Es steht nicht im Katalog. Vielleicht wurde es zurückgezogen.',
            ], 'plain'), 404);
        }

        $states = $this->installStates();

        return Response::html($this->app->view->render('account/plugins_detail', [
            'title'     => $plugin['name'],
            'active'    => 'konto/plugins',
            'tab'       => 'finden',
            'plugin'    => $plugin,
            'state'     => $states[$plugin['slug']] ?? null,
            'canManage' => $this->app->auth->can('Konto.Plugins.Manage'),
            'canWrite'  => (new Installer($this->app))->canWrite(),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('notice'),
            'error'     => $request->get('error'),
            'coreOk'    => $this->coreSatisfies($plugin),
        ]));
    }

    public function findAction(Request $request): Response
    {
        $guard = $this->guard($request, '/account/plugins/find');
        if ($guard !== null) {
            return $guard;
        }

        $action = $request->input('action');
        $slug = $request->input('slug');
        $back = $slug !== '' && $action === 'install'
            ? '/account/plugins/find/' . rawurlencode($slug)
            : '/account/plugins/find';

        try {
            switch ($action) {
                case 'refresh':
                    $count = count((new Client($this->app))->refresh());

                    return $this->back('/account/plugins/find', sprintf(
                        'Katalog neu geladen: %d Plugins verfügbar.',
                        $count
                    ));

                case 'install':
                    return $this->installFromRegistry($slug, $back);
            }
        } catch (Throwable $e) {
            return $this->back($back, null, $e->getMessage());
        }

        return $this->back($back, null, 'Unbekannte Aktion.');
    }

    // -----------------------------------------------------------------

    /**
     * Holt ein Paket aus dem Katalog, legt die Dateien ab und zieht
     * Schema und Registrierung nach.
     */
    private function installFromRegistry(string $slug, string $back): Response
    {
        $registry = new Client($this->app);
        $package = $registry->find($slug);

        if ($package === null) {
            return $this->back($back, null, 'Dieses Plugin steht nicht im Katalog.');
        }

        (new Installer($this->app))->fetch($package);

        // Nach dem Dateitausch muss das Manifest neu eingelesen werden -
        // der Zwischenspeicher kennt noch die alte Fassung.
        $this->app->plugins->discover(true);
        $manifest = $this->app->plugins->manifest($slug);

        if ($manifest === null) {
            return $this->back($back, null,
                'Die Dateien sind da, aber das Plugin lässt sich nicht lesen. '
                . 'Vermutlich ist sein Manifest fehlerhaft.');
        }

        if ($this->app->plugins->isInstalled($slug)) {
            $this->app->plugins->upgradeIfNeeded($manifest);

            return $this->back($back, sprintf(
                '%s auf Version %s aktualisiert.',
                $manifest->name,
                $manifest->version
            ));
        }

        $this->app->plugins->install($slug);

        $blockers = $this->app->plugins->blockers($slug);
        if ($blockers === []) {
            $this->app->plugins->enable($slug);

            return $this->back('/account/plugins', sprintf(
                '%s installiert und aktiviert.',
                $manifest->name
            ));
        }

        return $this->back('/account/plugins', sprintf(
            '%s installiert, aber noch nicht aktiv: %s',
            $manifest->name,
            implode(' ', $blockers)
        ));
    }

    /**
     * Zustand je Slug für die Marktplatz-Ansicht.
     *
     * @return array<string, array{installed: bool, enabled: bool, version: ?string}>
     */
    private function installStates(): array
    {
        $states = [];

        foreach ($this->app->plugins->discover() as $manifest) {
            $states[$manifest->slug] = [
                'installed' => $this->app->plugins->isInstalled($manifest->slug),
                'enabled'   => $this->app->plugins->isEnabled($manifest->slug),
                'version'   => $manifest->version,
            ];
        }

        return $states;
    }

    /**
     * Passt die Kernversion zu den Anforderungen des Katalogeintrags?
     *
     * @param array<string, mixed> $plugin
     */
    private function coreSatisfies(array $plugin): bool
    {
        $constraint = (string) (($plugin['requires']['core'] ?? '') ?: '*');

        return \Overlays\Core\Plugin\VersionConstraint::satisfies(App::VERSION, $constraint);
    }

    /**
     * Einstellungsseiten, die Plugins anmelden.
     *
     * Ein Plugin mit eigenen Einstellungen - etwa PayPal-Zugangsdaten -
     * meldet sie so an:
     *
     *   $hooks->on('plugin.settings', function (array $pages) use ($plugin) {
     *       $pages[$plugin->slug] = [
     *           'label' => 'PayPal einrichten',
     *           'href'  => '/donations/settings',
     *       ];
     *       return $pages;
     *   });
     *
     * @return array<string, array{label: string, href: string}>
     */
    private function settingsPages(): array
    {
        $pages = $this->app->hooks->filter('plugin.settings', []);
        if (!is_array($pages)) {
            return [];
        }

        $clean = [];
        foreach ($pages as $slug => $page) {
            if (!is_array($page) || ($page['href'] ?? '') === '') {
                continue;
            }

            $clean[strtolower((string) $slug)] = [
                'label' => (string) ($page['label'] ?? 'Einstellungen'),
                'href'  => (string) $page['href'],
            ];
        }

        return $clean;
    }

    private function guard(Request $request, string $back = '/account/plugins'): ?Response
    {
        if (!$this->app->auth->checkCsrf($request->input('csrf'))) {
            return $this->back($back, null, 'Das Formular ist abgelaufen. Bitte erneut versuchen.');
        }

        if (!$this->app->auth->can('Konto.Plugins.Manage')) {
            return $this->back($back, null, 'Dafür fehlt dir die Berechtigung.');
        }

        return null;
    }

    private function back(string $path, ?string $notice = null, ?string $error = null): Response
    {
        $query = [];
        if ($notice !== null) {
            $query['notice'] = $notice;
        }
        if ($error !== null) {
            $query['error'] = $error;
        }

        return Response::redirect(
            $this->app->url($path) . ($query === [] ? '' : '?' . http_build_query($query))
        );
    }
}
