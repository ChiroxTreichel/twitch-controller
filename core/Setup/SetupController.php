<?php

declare(strict_types=1);

namespace Overlays\Core\Setup;

use Overlays\Core\App;
use Overlays\Core\Auth\Auth;
use Overlays\Core\Http\Request;
use Overlays\Core\Http\Response;
use Overlays\Core\Twitch\TokenStore;
use Throwable;

/**
 * Ersteinrichtung. Laeuft in vier Schritten:
 *
 *   1. Systemcheck      PHP, Erweiterungen, APP_KEY, Datenbank
 *   2. Twitch-App       Client-ID, Secret, Webhook-Secret
 *   3. Kanal verbinden  Twitch-Login des Kanalinhabers (wird superadmin)
 *   4. EventSub         Abos bei Twitch anlegen, dann fertig
 *
 * Der Schritt ergibt sich aus dem Zustand, nicht aus einem Zaehler -
 * ein Neuladen oder Abbrechen kann den Ablauf also nicht zerlegen.
 *
 * Solange noch kein Kanalinhaber existiert, ist die Einrichtung ohne
 * Login erreichbar (anders geht es nicht - es gibt ja noch niemanden).
 * Sobald einer existiert, darf nur er weitermachen.
 */
final class SetupController
{
    public const STEP_CHECK       = 'check';
    public const STEP_CREDENTIALS = 'credentials';
    public const STEP_CHANNEL     = 'channel';
    public const STEP_EVENTSUB    = 'eventsub';
    public const STEP_DONE        = 'done';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Welcher Schritt ist jetzt dran?
     */
    public function currentStep(): string
    {
        if (!$this->app->crypto->isConfigured() || !$this->app->db->isReachable()) {
            return self::STEP_CHECK;
        }

        try {
            if (!$this->app->twitch->isConfigured()) {
                return self::STEP_CREDENTIALS;
            }

            if (!$this->app->twitch->hasChannel()) {
                return self::STEP_CHANNEL;
            }

            if (!$this->app->settings->bool('installed', false)) {
                return self::STEP_EVENTSUB;
            }
        } catch (Throwable) {
            // Schema fehlt noch -> zurueck zum Systemcheck, der legt es an.
            return self::STEP_CHECK;
        }

        return self::STEP_DONE;
    }

    public function show(Request $request): Response
    {
        $step = $this->currentStep();

        if ($step === self::STEP_DONE) {
            return Response::redirect($this->app->url('/'));
        }

        $locked = $this->lockedResponse();
        if ($locked !== null) {
            return $locked;
        }

        return match ($step) {
            self::STEP_CREDENTIALS => $this->renderCredentials(),
            self::STEP_CHANNEL     => $this->renderChannel(),
            self::STEP_EVENTSUB    => $this->renderEventSub($request),
            default                => $this->renderCheck(),
        };
    }

    // -----------------------------------------------------------------
    //  Schritt 1: Systemcheck
    // -----------------------------------------------------------------

    private function renderCheck(?string $error = null): Response
    {
        $checks = $this->diagnostics();
        $ready = array_reduce(
            $checks,
            static fn (bool $carry, array $check): bool => $carry && ($check['ok'] || !$check['required']),
            true
        );

        return Response::html($this->app->view->render('setup/check', [
            'title'  => 'Einrichtung',
            'step'   => self::STEP_CHECK,
            'checks' => $checks,
            'ready'  => $ready,
            'error'  => $error,
        ], 'setup/layout'));
    }

    /**
     * Legt das Schema an, sobald der Systemcheck durch ist.
     */
    public function prepareDatabase(Request $request): Response
    {
        $locked = $this->lockedResponse();
        if ($locked !== null) {
            return $locked;
        }

        try {
            $this->app->installCore();
        } catch (Throwable $e) {
            return $this->renderCheck('Schema konnte nicht angelegt werden: ' . $e->getMessage());
        }

        return Response::redirect($this->app->url('/setup'));
    }

    /**
     * @return list<array{label: string, ok: bool, required: bool, detail: string}>
     */
    public function diagnostics(): array
    {
        $checks = [];

        $checks[] = [
            'label'    => 'PHP-Version',
            'ok'       => PHP_VERSION_ID >= 80200,
            'required' => true,
            'detail'   => PHP_VERSION . ' (benötigt: 8.2 oder neuer)',
        ];

        foreach (['pdo_pgsql' => 'Datenbank', 'curl' => 'Twitch-API', 'sodium' => 'Verschlüsselung', 'json' => 'Datenformat'] as $extension => $why) {
            $checks[] = [
                'label'    => 'PHP-Erweiterung ' . $extension,
                'ok'       => extension_loaded($extension),
                'required' => true,
                'detail'   => 'gebraucht für: ' . $why,
            ];
        }

        $appUrl = (string) $this->app->env->get('APP_URL', '');
        $checks[] = [
            'label'    => 'APP_URL',
            'ok'       => $appUrl !== '',
            'required' => true,
            'detail'   => $appUrl === '' ? 'fehlt in der .env' : $appUrl,
        ];

        $checks[] = [
            'label'    => 'HTTPS',
            'ok'       => str_starts_with($appUrl, 'https://'),
            'required' => false,
            'detail'   => str_starts_with($appUrl, 'https://')
                ? 'APP_URL nutzt HTTPS'
                : 'Twitch akzeptiert für EventSub und OAuth nur HTTPS-Adressen.',
        ];

        $checks[] = [
            'label'    => 'APP_KEY',
            'ok'       => $this->app->crypto->isConfigured(),
            'required' => true,
            'detail'   => $this->app->crypto->isConfigured()
                ? 'gesetzt'
                : 'fehlt oder ist keine 32 Byte. Erzeugen: openssl rand -hex 32',
        ];

        $dbReachable = $this->app->db->isReachable();
        $checks[] = [
            'label'    => 'Datenbank',
            'ok'       => $dbReachable,
            'required' => true,
            'detail'   => $dbReachable
                ? 'erreichbar (' . $this->app->env->get('DB_NAME', '?') . ')'
                : 'nicht erreichbar. DB_HOST, DB_NAME, DB_USER und DB_PASS in der .env prüfen.',
        ];

        $pluginsWritable = is_dir($this->app->root . '/plugins') && is_writable($this->app->root . '/plugins');
        $checks[] = [
            'label'    => 'Plugin-Verzeichnis beschreibbar',
            'ok'       => $pluginsWritable,
            'required' => false,
            'detail'   => $pluginsWritable
                ? 'Plugins können installiert werden'
                : 'Nur nötig, um Plugins über die Oberfläche zu installieren.',
        ];

        return $checks;
    }

    // -----------------------------------------------------------------
    //  Schritt 2: Twitch-Zugangsdaten
    // -----------------------------------------------------------------

    private function renderCredentials(?string $error = null): Response
    {
        return Response::html($this->app->view->render('setup/credentials', [
            'title'        => 'Einrichtung · Twitch-App',
            'step'         => self::STEP_CREDENTIALS,
            'error'        => $error,
            'redirectUri'  => $this->app->url('/auth/callback'),
            'callbackUrl'  => $this->app->url('/hooks/twitch'),
            'clientId'     => $this->app->settings->string('twitch_client_id'),
            'suggestedKey' => bin2hex(random_bytes(32)),
        ], 'setup/layout'));
    }

    public function saveCredentials(Request $request): Response
    {
        $locked = $this->lockedResponse();
        if ($locked !== null) {
            return $locked;
        }

        $clientId = $request->input('client_id');
        $clientSecret = $request->input('client_secret');
        $webhookSecret = $request->input('webhook_secret');

        if ($clientId === '' || $clientSecret === '') {
            return $this->renderCredentials('Client-ID und Client-Secret sind beide nötig.');
        }

        if (strlen($webhookSecret) < 10 || strlen($webhookSecret) > 100) {
            return $this->renderCredentials(
                'Das Webhook-Secret muss zwischen 10 und 100 Zeichen lang sein (Twitch-Vorgabe).'
            );
        }

        $this->app->settings->set('twitch_client_id', $clientId);
        $this->app->settings->setSecret('twitch_client_secret', $clientSecret);
        $this->app->settings->setSecret('twitch_webhook_secret', $webhookSecret);

        // Sofort gegen Twitch prüfen, damit ein Tippfehler hier auffällt
        // und nicht erst beim Login.
        try {
            $this->app->twitch->oauth()->appToken();
        } catch (Throwable $e) {
            $this->app->settings->forget('twitch_app_token');
            return $this->renderCredentials(
                'Twitch lehnt diese Zugangsdaten ab: ' . $e->getMessage()
            );
        }

        return Response::redirect($this->app->url('/setup'));
    }

    // -----------------------------------------------------------------
    //  Schritt 3: Kanal verbinden
    // -----------------------------------------------------------------

    private function renderChannel(?string $error = null): Response
    {
        return Response::html($this->app->view->render('setup/channel', [
            'title'  => 'Einrichtung · Kanal verbinden',
            'step'   => self::STEP_CHANNEL,
            'error'  => $error ?? ($_GET['fehler'] ?? null),
            'scopes' => $this->app->twitch->broadcasterScopes(),
        ], 'setup/layout'));
    }

    public function startChannelConnect(Request $request): Response
    {
        $locked = $this->lockedResponse();
        if ($locked !== null) {
            return $locked;
        }

        return Response::redirect($this->app->twitch->oauth()->authorizeUrl(
            'setup_channel',
            $this->app->twitch->broadcasterScopes(),
            true
        ));
    }

    /**
     * Wird vom OAuth-Rückweg aufgerufen (purpose "setup_channel").
     *
     * @param array{access_token: string, refresh_token: string, expires_in: int, scope: list<string>} $token
     * @param array<string, mixed> $twitchUser
     */
    public function completeChannelConnect(array $token, array $twitchUser): Response
    {
        $this->app->settings->set('twitch_broadcaster_id', (string) ($twitchUser['id'] ?? ''));
        $this->app->settings->set('twitch_broadcaster_login', strtolower((string) ($twitchUser['login'] ?? '')));
        $this->app->settings->set('twitch_broadcaster_name', (string) ($twitchUser['display_name'] ?? ''));

        $this->app->twitch->tokens()->save(
            TokenStore::BROADCASTER,
            $token['access_token'],
            $token['refresh_token'],
            $token['expires_in'],
            $token['scope'],
            (string) ($twitchUser['id'] ?? ''),
            (string) ($twitchUser['login'] ?? ''),
        );

        // Der Kanalinhaber ist gleichzeitig der erste Benutzer.
        $this->app->auth->completeLogin($twitchUser);

        return Response::redirect($this->app->url('/setup'));
    }

    // -----------------------------------------------------------------
    //  Schritt 4: EventSub
    // -----------------------------------------------------------------

    /**
     * @param array{created: list<string>, deleted: list<string>, kept: list<string>, failed: array<string, string>}|null $report
     */
    private function renderEventSub(Request $request, ?array $report = null, ?string $error = null): Response
    {
        return Response::html($this->app->view->render('setup/eventsub', [
            'title'    => 'Einrichtung · Events',
            'step'     => self::STEP_EVENTSUB,
            'report'   => $report,
            'error'    => $error,
            'callback' => $this->app->twitch->eventSub()->callbackUrl(),
            'desired'  => $this->app->twitch->eventSub()->desired(),
            'csrf'     => $this->app->auth->csrfToken(),
        ], 'setup/layout'));
    }

    public function syncEventSub(Request $request): Response
    {
        $locked = $this->lockedResponse();
        if ($locked !== null) {
            return $locked;
        }

        try {
            $report = $this->app->twitch->eventSub()->sync();
        } catch (Throwable $e) {
            return $this->renderEventSub($request, null, $e->getMessage());
        }

        if ($report['failed'] !== []) {
            return $this->renderEventSub($request, $report);
        }

        $this->app->settings->set('installed', true);
        $this->app->settings->set('installed_at', date('c'));

        return Response::redirect($this->app->url('/konto/plugins?willkommen=1'));
    }

    public function finish(Request $request): Response
    {
        $locked = $this->lockedResponse();
        if ($locked !== null) {
            return $locked;
        }

        $this->app->settings->set('installed', true);
        $this->app->settings->set('installed_at', date('c'));

        return Response::redirect($this->app->url('/konto/plugins?willkommen=1'));
    }

    // -----------------------------------------------------------------

    /**
     * Sobald ein Kanalinhaber existiert, ist die Einrichtung nur noch
     * fuer ihn zugaenglich - sonst koennte ein Fremder eine halb
     * eingerichtete Installation uebernehmen.
     */
    private function lockedResponse(): ?Response
    {
        try {
            $hasOwner = ((int) $this->app->db->value(
                'SELECT count(*) FROM users WHERE role = :role',
                ['role' => Auth::ROLE_SUPERADMIN]
            )) > 0;
        } catch (Throwable) {
            return null;
        }

        if (!$hasOwner || $this->app->auth->isSuperadmin()) {
            return null;
        }

        return Response::html($this->app->view->render('setup/locked', [
            'title' => 'Einrichtung',
            'step'  => self::STEP_CHECK,
        ], 'setup/layout'), 403);
    }
}
