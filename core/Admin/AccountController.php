<?php

declare(strict_types=1);

namespace TwitchController\Core\Admin;

use TwitchController\Core\App;
use TwitchController\Core\Http\Request;
use TwitchController\Core\Http\Response;
use TwitchController\Core\I18n\Translator;
use TwitchController\Core\Twitch\TokenStore;
use TwitchController\Core\Update\Updater;
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

    /**
     * Reiter "Freigegebene Benutzer": wer Zugang hat, seit wann, und
     * wie man ihn wieder los wird.
     */
    public function users(Request $request): Response
    {
        return Response::html($this->app->view->render('account/users', [
            'title'     => translate('nav.users'),
            'active'    => 'account/users',
            'tab'       => 'granted',
            'users'     => $this->sortedUsers(),
            'invites'   => $this->app->auth->invites(),
            'canManage' => $this->app->auth->can('Konto.Benutzer.Manage'),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('notice'),
            'error'     => $request->get('error'),
            'link'      => $request->get('link'),
        ]));
    }

    /**
     * Reiter "Benutzerrechte": dieselbe Liste, aber mit dem Blick auf
     * die Rechte - Rolle und Anzahl.
     */
    public function permissions(Request $request): Response
    {
        return Response::html($this->app->view->render('account/users_permissions', [
            'title'     => translate('account.users.permissions'),
            'active'    => 'account/users',
            'tab'       => 'permissions',
            'users'     => $this->sortedUsers(),
            'canManage' => $this->app->auth->can('Konto.Benutzer.Manage'),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('notice'),
            'error'     => $request->get('error'),
        ]));
    }

    /**
     * Die Rechte eines Benutzers. Eigene Seite und nicht aufgeklappt in
     * der Liste: es sind knapp hundert Kaestchen.
     *
     * @param array<string, string> $params
     */
    public function permissionsEdit(Request $request, array $params): Response
    {
        $ziel = $this->app->auth->find((string) ($params['id'] ?? ''));

        if ($ziel === null) {
            return Response::html($this->app->view->render('error', [
                'title'   => translate('account.users.not_found'),
                'heading' => translate('account.users.not_found'),
                'message' => translate('account.users.not_found_hint'),
                'rescue'  => false,
            ], 'plain'), 404);
        }

        return Response::html($this->app->view->render('account/users_permissions_edit', [
            'title'     => translate('account.users.permissions_for', ['name' => $ziel['display_name']]),
            'active'    => 'account/users',
            'tab'       => 'permissions',
            'target'    => $ziel,
            'tree'      => $this->app->auth->permissionTree(),
            'presets'   => $this->app->auth->rolePresets(),
            'count'     => $this->app->auth->permissionCount($ziel),
            'isSuper'   => ($ziel['role'] ?? '') === 'superadmin',
            'canManage' => $this->app->auth->can('Konto.Benutzer.Manage'),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('notice'),
            'error'     => $request->get('error'),
        ]));
    }

    public function usersAction(Request $request): Response
    {
        $guard = $this->guardPost($request, 'Konto.Benutzer.Manage', '/account/users');
        if ($guard !== null) {
            return $guard;
        }

        $twitchId = $request->input('twitch_id');
        $rechteSeite = '/account/users/permissions'
            . ($twitchId !== '' ? '/' . rawurlencode($twitchId) : '');

        try {
            switch ($request->input('action')) {
                case 'invite':
                    $invite = $this->app->auth->createInvite((int) ($request->input('hours') ?: '72'));

                    // Der Link kommt in die Adresszeile zurueck, damit
                    // die Seite ihn zum Kopieren anzeigen kann.
                    return Response::redirect($this->app->url('/account/users') . '?' . http_build_query([
                        'notice' => translate('account.users.invite_created'),
                        'link'   => $invite['url'],
                    ]));

                case 'revoke_invite':
                    $this->app->auth->revokeInvite($request->input('code'));

                    return $this->back('/account/users', translate('account.users.invite_revoked'));

                case 'remove':
                    $this->app->auth->removeUser($twitchId);

                    return $this->back('/account/users', translate('account.users.removed'));

                case 'permissions':
                    $gewaehlt = $request->post['permissions'] ?? [];
                    $gewaehlt = is_array($gewaehlt) ? array_map('strval', $gewaehlt) : [];

                    // Nur Schluessel, die es wirklich gibt. Sonst
                    // sammelt die Datenbank Rechte an, die einmal in
                    // einem Formular standen und nie wieder gelten.
                    $bekannt = $this->app->auth->flatPermissionKeys();
                    $gewaehlt = array_values(array_intersect($gewaehlt, $bekannt));

                    $this->app->auth->setPermissions($twitchId, $gewaehlt);

                    return $this->back($rechteSeite, translate('account.users.permissions_saved'));

                case 'preset':
                    $vorlagen = $this->app->auth->rolePresets();
                    $name = $request->input('preset');

                    if (!isset($vorlagen[$name])) {
                        return $this->back($rechteSeite, null, translate('account.users.no_such_preset'));
                    }

                    $this->app->auth->setPermissions($twitchId, $vorlagen[$name]['keys']);

                    return $this->back($rechteSeite, translate('account.users.preset_applied', [
                        'role' => $vorlagen[$name]['label'],
                    ]));
            }
        } catch (Throwable $e) {
            return $this->back('/account/users', null, $e->getMessage());
        }

        return $this->back('/account/users', null, translate('common.error.unknown_action'));
    }

    /**
     * Benutzer nach Anzahl der Rechte absteigend, dann alphabetisch.
     * Der Superadmin hat alle und steht damit oben.
     *
     * @return list<array<string, mixed>>
     */
    private function sortedUsers(): array
    {
        $users = $this->app->auth->users();

        usort($users, function (array $a, array $b): int {
            $za = $this->app->auth->permissionCount($a)['have'];
            $zb = $this->app->auth->permissionCount($b)['have'];

            if ($za !== $zb) {
                return $zb <=> $za;
            }

            return strcasecmp(
                (string) ($a['display_name'] ?? ''),
                (string) ($b['display_name'] ?? '')
            );
        });

        return $users;
    }

    // -----------------------------------------------------------------
    //  Einstellungen
    // -----------------------------------------------------------------

    public function settings(Request $request): Response
    {
        $tokens = $this->app->twitch->tokens();
        $updater = new Updater($this->app);
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
            'active'        => 'account/settings',
            'canManage'     => $this->app->auth->can('Konto.Einstellungen.Manage'),
            'csrf'          => $this->app->auth->csrfToken(),
            'notice'        => $request->get('notice'),
            'error'         => $request->get('error'),
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
            'timezone'         => $this->app->timezone(),
            'language'         => $this->app->language(),
            'languages'        => Translator::available(
                $this->app->languageDirectory()
            ),
            'timezones'        => self::timezones(),
            'updateVersion'    => $updater->currentVersion(),
            // Der Pfad, unter dem diese Installation wirklich liegt -
            // bei der Einrichtung angegeben, nicht geraten.
            'installPath'      => $this->app->root,
            'updatePossible'   => $updater->isGitCheckout() && $updater->gitAvailable(),
        ]));
    }

    public function settingsAction(Request $request): Response
    {
        $guard = $this->guardPost($request, 'Konto.Einstellungen.Manage', '/account/settings');
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
                                '/account/settings',
                                null,
                                'Das Webhook-Secret muss 10 bis 100 Zeichen haben.'
                            );
                        }
                        $this->app->settings->setSecret('twitch_webhook_secret', $webhookSecret);
                    }

                    $this->app->settings->forget('twitch_app_token');
                    $this->app->settings->forget('twitch_app_token_expires');

                    return $this->back('/account/settings', 'Zugangsdaten gespeichert.');

                case 'eventsub':
                    $report = $this->app->twitch->eventSub()->sync();

                    if ($report['failed'] !== []) {
                        // Ursachen zusammenfassen statt jede Ablehnung
                        // einzeln aufzuzaehlen - meist ist es dieselbe.
                        $ursachen = [];
                        foreach ($report['failed'] as $message) {
                            $erklaerung = \TwitchController\Core\Twitch\EventSub::explain((string) $message);
                            $text = $erklaerung['ursache'];
                            if ($erklaerung['loesung'] !== '') {
                                $text .= ' ' . $erklaerung['loesung'];
                            }
                            $ursachen[$text] = true;
                        }

                        return $this->back(
                            '/account/settings',
                            null,
                            implode(' ', array_keys($ursachen))
                        );
                    }

                    return $this->back('/account/settings', sprintf(
                        'Abos abgeglichen: %d neu, %d bestanden, %d entfernt.',
                        count($report['created']),
                        count($report['kept']),
                        count($report['deleted'])
                    ));

                case 'disconnect_channel':
                    $this->app->twitch->tokens()->delete(TokenStore::BROADCASTER);

                    return $this->back('/account/settings', 'Kanal-Verbindung getrennt.');

                case 'timezone':
                    $timezone = $request->input('timezone');
                    if (!in_array($timezone, timezone_identifiers_list(), true)) {
                        return $this->back('/account/settings', null, 'Unbekannte Zeitzone.');
                    }

                    $this->app->settings->set('timezone', $timezone);
                    $this->app->applyTimezone();

                    return $this->back('/account/settings', 'Zeitzone gespeichert: ' . $timezone);

                case 'language':
                    $language = Translator::normalize($request->input('language'));
                    $this->app->settings->set('language', $language);
                    $this->app->applyLanguage();

                    return $this->back(
                        '/account/settings',
                        translate('account.settings.language_saved', ['language' => Translator::label($language)])
                    );

                case 'update_check':
                    $check = (new Updater($this->app))->check();

                    return $check['ok']
                        ? $this->back('/account/settings', $check['message'])
                        : $this->back('/account/settings', null, $check['message']);

                case 'update_apply':
                    // Ausgefuehrt wird das im worker-Container, weil der
                    // Webserver im Projektordner nicht schreiben darf.
                    (new Updater($this->app))->request();

                    return $this->back('/account/settings', translate('settings.update.queued'));
            }
        } catch (Throwable $e) {
            return $this->back('/account/settings', null, $e->getMessage());
        }

        return $this->back('/account/settings', null, 'Unbekannte Aktion.');
    }

    /**
     * Notausgang: Update und Sprache auf einer eigenen Seite.
     *
     * Update pruefen und einspielen lag ausschliesslich auf
     * /account/settings. Geht dort etwas kaputt - eine schiefe
     * Uebersetzung reicht -, ist genau der Knopf unerreichbar, der den
     * Fehler behebt, und es bleibt nur die Kommandozeile. Diese Seite
     * kommt ohne Navigation, ohne Plugins und ohne Twitch-Abfragen aus
     * und hat deshalb kaum etwas, das scheitern kann.
     */
    public function rescue(Request $request): Response
    {
        $updater = new Updater($this->app);

        $languages = [];
        foreach (Translator::available($this->app->languageDirectory()) as $code) {
            $languages[$code] = Translator::label($code);
        }

        return Response::html($this->app->view->render('rescue', [
            'notice'    => $request->get('notice'),
            'error'     => $request->get('error'),
            'csrf'      => $this->app->auth->csrfToken(),
            'version'   => $updater->currentVersion(),
            'language'  => $this->app->language(),
            'languages' => $languages,
        ], 'plain'));
    }

    public function rescueAction(Request $request): Response
    {
        if ($guard = $this->guardPost($request, 'Konto.Einstellungen.Edit', '/rescue')) {
            return $guard;
        }

        try {
            switch ($request->input('action')) {
                case 'update_check':
                    $check = (new Updater($this->app))->check();

                    return $check['ok']
                        ? $this->back('/rescue', $check['message'])
                        : $this->back('/rescue', null, $check['message']);

                case 'update_apply':
                    (new Updater($this->app))->request();

                    return $this->back('/rescue', translate('settings.update.queued'));

                case 'language':
                    $language = Translator::normalize($request->input('language'));
                    $this->app->settings->set('language', $language);
                    $this->app->applyLanguage();

                    return $this->back('/rescue', translate('account.settings.language_saved', [
                        'language' => Translator::label($language),
                    ]));
            }
        } catch (Throwable $e) {
            return $this->back('/rescue', null, $e->getMessage());
        }

        return $this->back('/rescue', null, translate('common.error.unknown_action'));
    }

    /**
     * Kanal (neu) verbinden - laeuft ueber denselben OAuth-Rueckweg wie
     * die Ersteinrichtung.
     */
    public function reconnectChannel(Request $request): Response
    {
        if (!$this->app->auth->isSuperadmin()) {
            return $this->back('/account/settings', null, 'Das darf nur der Kanalinhaber.');
        }

        return Response::redirect($this->app->twitch->oauth()->authorizeUrl(
            'setup_channel',
            $this->app->twitch->broadcasterScopes(),
            true
        ));
    }

    // -----------------------------------------------------------------

    /**
     * Nur die europaeischen Zonen plus UTC - die vollstaendige Liste hat
     * ueber 400 Eintraege und macht die Auswahl unbenutzbar.
     *
     * @return list<string>
     */
    private static function timezones(): array
    {
        $zones = array_values(array_filter(
            timezone_identifiers_list(),
            static fn (string $zone): bool => str_starts_with($zone, 'Europe/')
        ));

        array_unshift($zones, 'UTC');

        return $zones;
    }

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
