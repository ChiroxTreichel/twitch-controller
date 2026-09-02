<?php

declare(strict_types=1);

namespace Overlays\Core\Admin;

use Overlays\Core\App;
use Overlays\Core\Http\Request;
use Overlays\Core\Http\Response;
use Overlays\Core\Twitch\TokenStore;
use Throwable;

/**
 * Konto > Benutzer / Aktivitaeten / Plugins / Einstellungen
 *
 * Das ist der komplette Umfang des Kerns. Alles Fachliche kommt von
 * Plugins, die sich per 'admin.nav' eigene Menuepunkte anhaengen.
 */
final class AccountController
{
    public function __construct(private readonly App $app)
    {
    }

    // -----------------------------------------------------------------
    //  Benutzer
    // -----------------------------------------------------------------

    public function users(Request $request): Response
    {
        return Response::html($this->app->view->render('account/users', [
            'title'    => 'Benutzer',
            'active'   => 'konto/benutzer',
            'users'    => $this->app->auth->users(),
            'invites'  => $this->app->auth->invites(),
            'catalog'  => $this->app->auth->permissionCatalog(),
            'canManage' => $this->app->auth->can('Konto.Benutzer.Manage'),
            'csrf'     => $this->app->auth->csrfToken(),
            'notice'   => $request->get('hinweis'),
            'error'    => $request->get('fehler'),
            'editing'  => $request->get('bearbeiten'),
        ]));
    }

    public function usersAction(Request $request): Response
    {
        $guard = $this->guardPost($request, 'Konto.Benutzer.Manage', '/konto/benutzer');
        if ($guard !== null) {
            return $guard;
        }

        $action = $request->input('action');

        try {
            switch ($action) {
                case 'invite':
                    $invite = $this->app->auth->createInvite((int) ($request->input('hours') ?: '72'));

                    return $this->back('/konto/benutzer', 'Einladungslink erstellt: ' . $invite['url']);

                case 'revoke_invite':
                    $this->app->auth->revokeInvite($request->input('code'));

                    return $this->back('/konto/benutzer', 'Einladung zurückgezogen.');

                case 'permissions':
                    $permissions = $request->post['permissions'] ?? [];
                    $this->app->auth->setPermissions(
                        $request->input('twitch_id'),
                        is_array($permissions) ? array_map('strval', $permissions) : []
                    );

                    return $this->back('/konto/benutzer', 'Rechte gespeichert.');

                case 'remove':
                    $this->app->auth->removeUser($request->input('twitch_id'));

                    return $this->back('/konto/benutzer', 'Benutzer entfernt.');
            }
        } catch (Throwable $e) {
            return $this->back('/konto/benutzer', null, $e->getMessage());
        }

        return $this->back('/konto/benutzer', null, 'Unbekannte Aktion.');
    }

    // -----------------------------------------------------------------
    //  Aktivitaeten
    // -----------------------------------------------------------------

    public function activities(Request $request): Response
    {
        $perPage = 50;
        $page = max(1, (int) ($request->get('seite') ?: '1'));

        $filters = [
            'event_type' => $request->get('typ'),
            'actor'      => $request->get('wer'),
        ];

        $total = $this->app->events->count($filters);

        return Response::html($this->app->view->render('account/activities', [
            'title'   => 'Aktivitäten',
            'active'  => 'konto/aktivitaeten',
            'events'  => $this->app->events->recent($perPage, ($page - 1) * $perPage, $filters),
            'types'   => $this->app->events->knownTypes(),
            'filters' => $filters,
            'page'    => $page,
            'pages'   => max(1, (int) ceil($total / $perPage)),
            'total'   => $total,
        ]));
    }

    // -----------------------------------------------------------------
    //  Plugins
    // -----------------------------------------------------------------

    public function plugins(Request $request): Response
    {
        $rows = [];

        foreach ($this->app->plugins->discover(true) as $manifest) {
            $installedVersion = $this->app->plugins->installedVersion($manifest->slug);

            $rows[] = [
                'manifest'  => $manifest,
                'installed' => $installedVersion !== null,
                'enabled'   => $this->app->plugins->isEnabled($manifest->slug),
                'version'   => $installedVersion,
                'updatable' => $installedVersion !== null
                    && version_compare($installedVersion, $manifest->version, '<'),
                'blockers'  => $this->app->plugins->blockers($manifest->slug),
                'dependents' => $this->app->plugins->activeDependents($manifest->slug),
            ];
        }

        return Response::html($this->app->view->render('account/plugins', [
            'title'     => 'Plugins',
            'active'    => 'konto/plugins',
            'rows'      => $rows,
            'missing'   => $this->app->plugins->missing(),
            'canManage' => $this->app->auth->can('Konto.Plugins.Manage'),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('hinweis'),
            'error'     => $request->get('fehler'),
            'welcome'   => $request->get('willkommen') !== '',
        ]));
    }

    public function pluginsAction(Request $request): Response
    {
        $guard = $this->guardPost($request, 'Konto.Plugins.Manage', '/konto/plugins');
        if ($guard !== null) {
            return $guard;
        }

        $slug = $request->input('slug');
        $action = $request->input('action');

        try {
            switch ($action) {
                case 'install':
                    $this->app->plugins->install($slug);

                    return $this->back('/konto/plugins', 'Plugin installiert.');

                case 'enable':
                    $this->app->plugins->enable($slug);

                    return $this->back('/konto/plugins', 'Plugin aktiviert. Eventuell neue Twitch-Abos nötig – siehe Einstellungen.');

                case 'disable':
                    $this->app->plugins->disable($slug);

                    return $this->back('/konto/plugins', 'Plugin deaktiviert.');

                case 'uninstall':
                    $this->app->plugins->uninstall($slug);

                    return $this->back('/konto/plugins', 'Plugin entfernt, Daten gelöscht.');

                case 'update':
                    $manifest = $this->app->plugins->manifest($slug);
                    if ($manifest !== null) {
                        $this->app->plugins->upgradeIfNeeded($manifest);
                    }

                    return $this->back('/konto/plugins', 'Plugin aktualisiert.');
            }
        } catch (Throwable $e) {
            return $this->back('/konto/plugins', null, $e->getMessage());
        }

        return $this->back('/konto/plugins', null, 'Unbekannte Aktion.');
    }

    // -----------------------------------------------------------------
    //  Einstellungen
    // -----------------------------------------------------------------

    public function settings(Request $request): Response
    {
        $tokens = $this->app->twitch->tokens();
        $updater = new \Overlays\Core\Update\Updater($this->app);
        $missingScopes = [];

        try {
            $missingScopes = $tokens->missingScopes(
                TokenStore::BROADCASTER,
                $this->app->twitch->broadcasterScopes()
            );
        } catch (Throwable) {
            $missingScopes = [];
        }

        return Response::html($this->app->view->render('account/settings', [
            'title'         => 'Einstellungen',
            'active'        => 'konto/einstellungen',
            'canManage'     => $this->app->auth->can('Konto.Einstellungen.Manage'),
            'csrf'          => $this->app->auth->csrfToken(),
            'notice'        => $request->get('hinweis'),
            'error'         => $request->get('fehler'),
            'clientId'      => $this->app->settings->string('twitch_client_id'),
            'hasSecret'     => $this->app->settings->hasSecret('twitch_client_secret'),
            'hasWebhook'    => $this->app->settings->hasSecret('twitch_webhook_secret'),
            'redirectUri'   => $this->app->twitch->oauth()->redirectUri(),
            'callbackUrl'   => $this->app->twitch->eventSub()->callbackUrl(),
            'channel'       => [
                'id'    => $this->app->settings->string('twitch_broadcaster_id'),
                'login' => $this->app->settings->string('twitch_broadcaster_login'),
                'name'  => $this->app->settings->string('twitch_broadcaster_name'),
            ],
            'broadcasterToken' => $tokens->info(TokenStore::BROADCASTER),
            'missingScopes'    => $missingScopes,
            'desired'          => $this->app->twitch->eventSub()->desired(),
            'report'           => null,
            'update'           => $updater->status(),
            'updateVersion'    => $updater->currentVersion(),
            'updatePossible'   => $updater->isGitCheckout() && $updater->gitAvailable(),
        ]));
    }

    public function settingsAction(Request $request): Response
    {
        $guard = $this->guardPost($request, 'Konto.Einstellungen.Manage', '/konto/einstellungen');
        if ($guard !== null) {
            return $guard;
        }

        $action = $request->input('action');

        try {
            switch ($action) {
                case 'credentials':
                    $clientId = $request->input('client_id');
                    if ($clientId !== '') {
                        $this->app->settings->set('twitch_client_id', $clientId);
                    }

                    $clientSecret = $request->input('client_secret');
                    if ($clientSecret !== '') {
                        $this->app->settings->setSecret('twitch_client_secret', $clientSecret);
                    }

                    $webhookSecret = $request->input('webhook_secret');
                    if ($webhookSecret !== '') {
                        if (strlen($webhookSecret) < 10 || strlen($webhookSecret) > 100) {
                            return $this->back(
                                '/konto/einstellungen',
                                null,
                                'Das Webhook-Secret muss 10 bis 100 Zeichen haben.'
                            );
                        }
                        $this->app->settings->setSecret('twitch_webhook_secret', $webhookSecret);
                    }

                    $this->app->settings->forget('twitch_app_token');
                    $this->app->settings->forget('twitch_app_token_expires');

                    return $this->back('/konto/einstellungen', 'Zugangsdaten gespeichert.');

                case 'eventsub':
                    $report = $this->app->twitch->eventSub()->sync();

                    if ($report['failed'] !== []) {
                        // Ursachen zusammenfassen statt jede Ablehnung
                        // einzeln aufzuzaehlen - meist ist es dieselbe.
                        $ursachen = [];
                        foreach ($report['failed'] as $message) {
                            $erklaerung = \Overlays\Core\Twitch\EventSub::explain((string) $message);
                            $text = $erklaerung['ursache'];
                            if ($erklaerung['loesung'] !== '') {
                                $text .= ' ' . $erklaerung['loesung'];
                            }
                            $ursachen[$text] = true;
                        }

                        return $this->back(
                            '/konto/einstellungen',
                            null,
                            implode(' ', array_keys($ursachen))
                        );
                    }

                    return $this->back('/konto/einstellungen', sprintf(
                        'Abos abgeglichen: %d neu, %d bestanden, %d entfernt.',
                        count($report['created']),
                        count($report['kept']),
                        count($report['deleted'])
                    ));

                case 'disconnect_channel':
                    $this->app->twitch->tokens()->delete(TokenStore::BROADCASTER);

                    return $this->back('/konto/einstellungen', 'Kanal-Verbindung getrennt.');

                case 'update_check':
                    $check = (new \Overlays\Core\Update\Updater($this->app))->check();

                    return $check['ok']
                        ? $this->back('/konto/einstellungen', $check['message'])
                        : $this->back('/konto/einstellungen', null, $check['message']);

                case 'update_apply':
                    // Ausgefuehrt wird das im worker-Container, weil der
                    // Webserver im Projektordner nicht schreiben darf.
                    (new \Overlays\Core\Update\Updater($this->app))->request();

                    return $this->back(
                        '/konto/einstellungen',
                        'Update ist beauftragt. Es läuft im Hintergrund an und dauert meist '
                        . 'weniger als eine Minute - diese Seite danach neu laden.'
                    );
            }
        } catch (Throwable $e) {
            return $this->back('/konto/einstellungen', null, $e->getMessage());
        }

        return $this->back('/konto/einstellungen', null, 'Unbekannte Aktion.');
    }

    /**
     * Kanal (neu) verbinden - laeuft ueber denselben OAuth-Rueckweg wie
     * die Ersteinrichtung.
     */
    public function reconnectChannel(Request $request): Response
    {
        if (!$this->app->auth->isSuperadmin()) {
            return $this->back('/konto/einstellungen', null, 'Das darf nur der Kanalinhaber.');
        }

        return Response::redirect($this->app->twitch->oauth()->authorizeUrl(
            'setup_channel',
            $this->app->twitch->broadcasterScopes(),
            true
        ));
    }

    // -----------------------------------------------------------------

    private function guardPost(Request $request, string $permission, string $back): ?Response
    {
        if (!$this->app->auth->checkCsrf($request->input('csrf'))) {
            return $this->back($back, null, 'Das Formular ist abgelaufen. Bitte erneut versuchen.');
        }

        if (!$this->app->auth->can($permission)) {
            return $this->back($back, null, 'Dafür fehlt dir die Berechtigung.');
        }

        return null;
    }

    private function back(string $path, ?string $notice = null, ?string $error = null): Response
    {
        $query = [];
        if ($notice !== null) {
            $query['hinweis'] = $notice;
        }
        if ($error !== null) {
            $query['fehler'] = $error;
        }

        return Response::redirect(
            $this->app->url($path) . ($query === [] ? '' : '?' . http_build_query($query))
        );
    }
}
