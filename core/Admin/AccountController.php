<?php

declare(strict_types=1);

namespace Overlays\Core\Admin;

use Overlays\Core\App;
use Overlays\Core\Http\Request;
use Overlays\Core\Http\Response;
use Overlays\Core\I18n\Translator;
use Overlays\Core\Twitch\TokenStore;
use Overlays\Core\Update\Updater;
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

                case 'timezone':
                    $timezone = $request->input('timezone');
                    if (!in_array($timezone, timezone_identifiers_list(), true)) {
                        return $this->back('/konto/einstellungen', null, 'Unbekannte Zeitzone.');
                    }

                    $this->app->settings->set('timezone', $timezone);
                    $this->app->applyTimezone();

                    return $this->back('/konto/einstellungen', 'Zeitzone gespeichert: ' . $timezone);

                case 'language':
                    $language = Translator::normalize($request->input('language'));
                    $this->app->settings->set('language', $language);
                    $this->app->applyLanguage();

                    return $this->back(
                        '/konto/einstellungen',
                        translate('account.settings.language_saved', ['language' => Translator::label($language)])
                    );

                case 'update_check':
                    $check = (new Updater($this->app))->check();

                    return $check['ok']
                        ? $this->back('/konto/einstellungen', $check['message'])
                        : $this->back('/konto/einstellungen', null, $check['message']);

                case 'update_apply':
                    // Ausgefuehrt wird das im worker-Container, weil der
                    // Webserver im Projektordner nicht schreiben darf.
                    (new Updater($this->app))->request();

                    return $this->back('/konto/einstellungen', translate('settings.update.queued'));
            }
        } catch (Throwable $e) {
            return $this->back('/konto/einstellungen', null, $e->getMessage());
        }

        return $this->back('/konto/einstellungen', null, 'Unbekannte Aktion.');
    }

    /**
     * Notausgang: Update und Sprache auf einer eigenen Seite.
     *
     * Update pruefen und einspielen lag ausschliesslich auf
     * /konto/einstellungen. Geht dort etwas kaputt - eine schiefe
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
            'notice'    => $request->query('hinweis'),
            'error'     => $request->query('fehler'),
            'csrf'      => $this->app->auth->csrfToken(),
            'version'   => $updater->currentVersion(),
            'language'  => $this->app->language(),
            'languages' => $languages,
        ], 'plain'));
    }

    public function rescueAction(Request $request): Response
    {
        if ($guard = $this->guardPost($request, 'Konto.Einstellungen.Edit', '/rettung')) {
            return $guard;
        }

        try {
            switch ($request->input('action')) {
                case 'update_check':
                    $check = (new Updater($this->app))->check();

                    return $check['ok']
                        ? $this->back('/rettung', $check['message'])
                        : $this->back('/rettung', null, $check['message']);

                case 'update_apply':
                    (new Updater($this->app))->request();

                    return $this->back('/rettung', translate('settings.update.queued'));

                case 'language':
                    $language = Translator::normalize($request->input('language'));
                    $this->app->settings->set('language', $language);
                    $this->app->applyLanguage();

                    return $this->back('/rettung', translate('account.settings.language_saved', [
                        'language' => Translator::label($language),
                    ]));
            }
        } catch (Throwable $e) {
            return $this->back('/rettung', null, $e->getMessage());
        }

        return $this->back('/rettung', null, translate('common.error.unknown_action'));
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
