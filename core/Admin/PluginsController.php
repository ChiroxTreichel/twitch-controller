<?php

declare(strict_types=1);

namespace TwitchController\Core\Admin;

use TwitchController\Core\App;
use TwitchController\Core\Http\Request;
use TwitchController\Core\Http\Response;
use TwitchController\Core\Plugin\Manifest;
use TwitchController\Core\Registry\Client;
use TwitchController\Core\Registry\Dependencies;
use TwitchController\Core\Registry\Installer;
use RuntimeException;
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

        // Den Katalog einmal frisch holen. Vorher wurde nur der
        // Zwischenspeicher gelesen - war der Marktplatz noch nie offen,
        // stand dort nichts, und die Liste zeigte kein Update an,
        // obwohl eines dalag.
        //
        // Scheitert es, geht es weiter: der Katalogserver darf nicht
        // darueber entscheiden, ob man seine Plugins verwalten kann.
        $catalogError = '';
        try {
            $registry->all();
        } catch (Throwable $e) {
            $catalogError = $e->getMessage();
        }

        foreach ($this->app->plugins->discover(true) as $manifest) {
            $installedVersion = $this->app->plugins->installedVersion($manifest->slug);

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

        // Was liesse sich jetzt aktualisieren? Fuer den Knopf "Alle
        // aktualisieren" - und damit er verschwindet, wenn es nichts
        // zu tun gibt.
        $updates = 0;
        foreach ($rows as $row) {
            if ($row['catalog'] !== null || $row['updatable']) {
                $updates++;
            }
        }

        return Response::html($this->app->view->render('account/plugins', [
            'title'     => translate('nav.plugins'),
            'active'    => 'account/plugins',
            'updates'   => $updates,
            'catalogError' => $catalogError,
            'tab'       => 'installiert',
            'rows'      => $rows,
            'missing'   => $this->app->plugins->missing(),
            'canManage' => $this->app->auth->can('Account.Plugins.Manage'),
            'canWrite'  => (new Installer($this->app))->canWrite(),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('notice'),
            'error'     => $request->get('error'),
            'welcome'   => $request->get('welcome') !== '',
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
                        translate('account.plugins.enabled_hint'));

                case 'disable':
                    $this->app->plugins->disable($slug);

                    return $this->back('/account/plugins', 'Plugin deaktiviert.');


                case 'update':
                    $manifest = $this->app->plugins->manifest($slug);
                    if ($manifest !== null) {
                        $this->app->plugins->upgradeIfNeeded($manifest);
                    }

                    return $this->back('/account/plugins', 'Plugin aktualisiert.');

                case 'download_update':
                    return $this->installFromRegistry($slug, '/account/plugins');

                case 'update_all':
                    return $this->updateAll();

                case 'remove':
                    // Ein Schritt, nicht zwei: Daten abraeumen UND
                    // Dateien loeschen. Zwei Knoepfe fuer eine Absicht
                    // waren umstaendlich, und wer "Entfernen" drueckt,
                    // will das Plugin loswerden - nicht seine Dateien
                    // behalten.
                    $name = $this->app->plugins->manifest($slug)?->name ?? $slug;

                    // Wer wird dadurch kaputt? Ein Plugin, das dieses
                    // voraussetzt, laesst sich danach nie wieder
                    // einschalten - und der Grund dafuer waere nicht
                    // mehr zu sehen.
                    $abhaengige = $this->app->plugins->installedDependents($slug);
                    if ($abhaengige !== []) {
                        return $this->back('/account/plugins', null, translate(
                            'account.plugins.needed_by_others',
                            ['name' => $name, 'plugins' => implode(', ', $abhaengige)]
                        ));
                    }

                    // Reihenfolge zaehlt: uninstall() braucht die
                    // Dateien noch, weil darin die uninstall.php des
                    // Plugins liegt.
                    if ($this->app->plugins->isInstalled($slug)) {
                        $this->app->plugins->uninstall($slug);
                    }

                    try {
                        (new Installer($this->app))->remove($slug);
                    } catch (Throwable $e) {
                        // Die Daten sind schon weg. Das muss dastehen,
                        // sonst versucht es jemand noch einmal und
                        // wundert sich.
                        return $this->back('/account/plugins', null, translate(
                            'account.plugins.data_gone_files_stay',
                            ['name' => $name, 'reason' => $e->getMessage()]
                        ));
                    }

                    return $this->back(
                        '/account/plugins',
                        translate('account.plugins.removed', ['name' => $name])
                    );
            }
        } catch (Throwable $e) {
            return $this->back('/account/plugins', null, $e->getMessage());
        }

        return $this->back('/account/plugins', null, translate('common.error.unknown_action'));
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
        $needs = [];

        try {
            $plugins = $query !== '' ? $registry->search($query) : $registry->all();

            if ($tag !== '') {
                $plugins = array_values(array_filter(
                    $plugins,
                    static fn (array $p): bool => in_array($tag, $p['tags'], true)
                ));
            }

            $tags = $registry->tags();

            // Je Eintrag, was mitkaeme. Steht schon in der Liste, damit
            // die Ueberraschung nicht erst nach dem Klick kommt.
            $dependencies = new Dependencies($this->app);
            foreach ($plugins as $eintrag) {
                $needs[(string) $eintrag['slug']] = $dependencies->describe($eintrag);
            }
        } catch (Throwable $e) {
            if ($error === '') {
                $error = $e->getMessage();
            }
        }

        return Response::html($this->app->view->render('account/plugins_find', [
            'title' => translate('account.plugins.tab_find'),
            'active'     => 'account/plugins',
            'tab'        => 'finden',
            'plugins'    => $plugins,
            'tags'       => $tags,
            'query'      => $query,
            'tag'        => $tag,
            'needs'      => $needs,
            'registry'   => $registry->baseUrl(),
            'canManage'  => $this->app->auth->can('Account.Plugins.Manage'),
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
                'title'   => translate('common.error.not_found'),
                'heading' => translate('market.no_such_plugin'),
                'message' => translate('market.no_such_plugin_hint'),
            ], 'plain'), 404);
        }

        $states = $this->installStates();

        // Der Langtext haengt an einer eigenen Adresse und wird erst
        // hier geholt - nicht beim Laden des Katalogs.
        $readme = $registry->readme($plugin);

        // Was mitinstalliert wird, gehoert VOR den Knopf - nicht in
        // die Meldung danach.
        $needs = (new Dependencies($this->app))->describe($plugin);

        return Response::html($this->app->view->render('account/plugins_detail', [
            'title'     => $plugin['name'],
            'readme'    => $readme['html'],
            'readmeErr' => $readme['error'],
            'needs'     => $needs,
            'active'    => 'account/plugins',
            'tab'       => 'finden',
            'plugin'    => $plugin,
            'state'     => $states[$plugin['slug']] ?? null,
            'canManage' => $this->app->auth->can('Account.Plugins.Manage'),
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
                case 'install':
                    return $this->installFromRegistry($slug, $back);
            }
        } catch (Throwable $e) {
            return $this->back($back, null, $e->getMessage());
        }

        return $this->back($back, null, translate('common.error.unknown_action'));
    }

    // -----------------------------------------------------------------

    /**
     * Alle Plugins auf den neuesten Stand bringen.
     *
     * Zwei Arten von Update, und beide gehoeren hierher:
     *
     *   Katalog   im Katalog liegt eine neuere Fassung - Dateien holen
     *             und danach install.php nachziehen.
     *   Dateien   die Dateien sind schon neuer als der Stand in der
     *             Datenbank, etwa nach einem Update von Hand. Dann
     *             fehlt nur noch install.php.
     *
     * Ein gescheitertes Plugin haelt die uebrigen nicht auf: sonst
     * bliebe nach dem ersten Fehler alles andere alt, und man muesste
     * herausfinden, welches der Uebeltaeter war.
     */
    private function updateAll(): Response
    {
        $registry = new Client($this->app);

        try {
            $registry->all();
        } catch (Throwable $e) {
            return $this->back('/account/plugins', null, $e->getMessage());
        }

        $gemacht = [];
        $gescheitert = [];

        // Voraussetzung zuerst. Alphabetisch stimmt das zufaellig fuer
        // "alerts" vor "twitch-alerts", allgemein nicht - und ein
        // Plugin, dessen Voraussetzung noch alt ist, kann bei seinem
        // install.php ueber eine fehlende Klasse fallen.
        $vorhanden = $this->app->plugins->discover(true);
        $reihenfolge = $this->app->plugins->resolveOrder(array_keys($vorhanden));

        foreach ($reihenfolge as $slug) {
            $manifest = $vorhanden[$slug] ?? null;
            if ($manifest === null) {
                continue;
            }

            $installiert = $this->app->plugins->installedVersion($slug);

            if ($installiert === null) {
                continue;
            }

            $eintrag = $registry->cachedEntry($slug);
            $imKatalog = $eintrag === null ? null : (string) $eintrag['version'];

            $neueDateien = $imKatalog !== null
                && version_compare($manifest->version, $imKatalog, '<');
            $nurSchema = version_compare($installiert, $manifest->version, '<');

            if (!$neueDateien && !$nurSchema) {
                continue;
            }

            try {
                if ($neueDateien) {
                    $eintrag = $registry->find($slug);
                    if ($eintrag === null) {
                        throw new RuntimeException(translate('market.not_in_catalog'));
                    }

                    (new Installer($this->app))->fetch($eintrag);
                    $this->app->plugins->discover(true);

                    $frisch = $this->app->plugins->manifest($slug);
                    if ($frisch === null) {
                        throw new RuntimeException(translate('market.manifest_broken'));
                    }

                    $this->app->plugins->upgradeIfNeeded($frisch);
                    $gemacht[] = $frisch->name . ' ' . $frisch->version;

                    continue;
                }

                $this->app->plugins->upgradeIfNeeded($manifest);
                $gemacht[] = $manifest->name . ' ' . $manifest->version;
            } catch (Throwable $e) {
                // Weitermachen. Ein kaputtes Plugin darf die anderen
                // nicht alt lassen.
                $gescheitert[] = $manifest->name . ': ' . $e->getMessage();
            }
        }

        if ($gemacht === [] && $gescheitert === []) {
            return $this->back('/account/plugins', translate('account.plugins.all_current'));
        }

        if ($gescheitert !== []) {
            return $this->back(
                '/account/plugins',
                $gemacht === [] ? null : translate('account.plugins.updated_some', [
                    'plugins' => implode(', ', $gemacht),
                ]),
                translate('account.plugins.update_failed', [
                    'reasons' => implode(' / ', $gescheitert),
                ])
            );
        }

        return $this->back('/account/plugins', translate('account.plugins.updated_all', [
            'plugins' => implode(', ', $gemacht),
        ]));
    }

    /**
     * Ein Plugin aus dem Katalog installieren - samt allem, was es
     * voraussetzt.
     *
     * Wer auf "Installieren" klickt, soll nicht selbst herausfinden
     * muessen, dass Twitch-Alerts das Alerts-Plugin braucht. Er soll es
     * aber erfahren: die Detailseite sagt es vorher, und die Meldung
     * danach zaehlt auf, was zusaetzlich dazugekommen ist.
     */
    private function installFromRegistry(string $slug, string $back): Response
    {
        $registry = new Client($this->app);
        $package = $registry->find($slug);

        if ($package === null) {
            return $this->back($back, null, translate('market.not_in_catalog'));
        }

        $plan = (new Dependencies($this->app))->plan($slug);

        if ($plan['cycle']) {
            return $this->back($back, null, translate('market.dependency_cycle'));
        }

        if ($plan['unknown'] !== []) {
            return $this->back($back, null, translate('market.dependency_missing', [
                'plugins' => implode(', ', $plan['unknown']),
            ]));
        }

        // Voraussetzung zuerst. Andernfalls laeuft die install.php
        // eines Plugins, dessen Abhaengigkeit noch fehlt.
        $eingerichtet = [];

        foreach ($plan['order'] as $einzelner) {
            $eintrag = $einzelner === $slug ? $package : $registry->find($einzelner);
            if ($eintrag === null) {
                return $this->back($back, null, translate('market.dependency_missing', [
                    'plugins' => $einzelner,
                ]));
            }

            $manifest = $this->fetchAndRegister($eintrag);
            if ($manifest instanceof Response) {
                return $manifest;
            }

            $eingerichtet[] = $manifest->name;
        }

        // Ein Update des angeklickten Plugins: dann steht es nicht in
        // der Reihenfolge, weil es schon installiert ist.
        if (!in_array($slug, $plan['order'], true)) {
            (new Installer($this->app))->fetch($package);
            $this->app->plugins->discover(true);

            $manifest = $this->app->plugins->manifest($slug);
            if ($manifest === null) {
                return $this->back($back, null, translate('market.manifest_broken'));
            }

            $this->app->plugins->upgradeIfNeeded($manifest);

            return $this->back($back, translate('market.updated', [
                'name'    => $manifest->name,
                'version' => $manifest->version,
            ]));
        }

        $manifest = $this->app->plugins->manifest($slug);
        $name = $manifest?->name ?? $slug;

        // Erst nach allen Dateien einschalten: blockers() prueft, ob
        // die Abhaengigkeiten aktiv sind.
        $offen = [];
        foreach ($plan['order'] as $einzelner) {
            $blocker = $this->app->plugins->blockers($einzelner);

            if ($blocker === []) {
                $this->app->plugins->enable($einzelner);
                continue;
            }

            $offen[] = ($this->app->plugins->manifest($einzelner)?->name ?? $einzelner)
                . ': ' . implode(' ', $blocker);
        }

        $mitgekommen = array_map(
            static fn (array $eintrag): string => $eintrag['name'],
            $plan['also']
        );

        if ($offen !== []) {
            return $this->back('/account/plugins', null, translate('market.installed_blocked', [
                'name'    => $name,
                'reasons' => implode(' / ', $offen),
            ]));
        }

        if ($mitgekommen !== []) {
            return $this->back('/account/plugins', translate('market.installed_with', [
                'name'  => $name,
                'extra' => implode(', ', $mitgekommen),
            ]));
        }

        return $this->back('/account/plugins', translate('market.installed', ['name' => $name]));
    }

    /**
     * Dateien holen, Manifest neu einlesen, in der Datenbank anmelden.
     *
     * Gibt bei einem Fehler die fertige Antwort zurueck statt eines
     * Manifests - der Aufrufer gibt sie einfach weiter.
     *
     * @param array<string, mixed> $entry Katalogeintrag
     */
    private function fetchAndRegister(array $entry): Manifest|Response
    {
        $slug = (string) $entry['slug'];

        (new Installer($this->app))->fetch($entry);

        // Nach dem Dateitausch muss das Manifest neu eingelesen werden -
        // der Zwischenspeicher kennt noch die alte Fassung.
        $this->app->plugins->discover(true);
        $manifest = $this->app->plugins->manifest($slug);

        if ($manifest === null) {
            return $this->back('/account/plugins', null, translate('market.manifest_broken'));
        }

        if (!$this->app->plugins->isInstalled($slug)) {
            $this->app->plugins->install($slug);
        } else {
            $this->app->plugins->upgradeIfNeeded($manifest);
        }

        return $manifest;
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

        return \TwitchController\Core\Plugin\VersionConstraint::satisfies(App::VERSION, $constraint);
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
            return $this->back($back, null, translate('common.error.form_expired'));
        }

        if (!$this->app->auth->can('Account.Plugins.Manage')) {
            return $this->back($back, null, translate('common.error.no_permission'));
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
