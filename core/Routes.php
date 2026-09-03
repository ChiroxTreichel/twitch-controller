<?php

declare(strict_types=1);

namespace TwitchController\Core;

use TwitchController\Core\Admin\AccountController;
use TwitchController\Core\Admin\ActivityController;
use TwitchController\Core\Admin\Nav;
use TwitchController\Core\Admin\PluginsController;
use TwitchController\Core\Obs\FeedController;
use TwitchController\Core\Auth\AuthController;
use TwitchController\Core\Http\Request;
use TwitchController\Core\Http\Response;
use TwitchController\Core\Setup\SetupController;
use TwitchController\Core\Twitch\WebhookController;

/**
 * Alle Routen des Kerns an einer Stelle. Plugins registrieren ihre
 * eigenen in plugin.php ueber $router.
 */
final class Routes
{
    public static function register(App $app): void
    {
        $router = $app->router;
        $auth = new AuthController($app);
        $setup = new SetupController($app);
        $account = new AccountController($app);
        $plugins = new PluginsController($app);
        $activity = new ActivityController($app);
        $feed = new FeedController($app);
        $webhook = new WebhookController($app);

        // --- Startseite ------------------------------------------------
        $router->get('/', static function (Request $request) use ($app): Response {
            // Ein Plugin darf die Startseite uebernehmen (Landingpage,
            // Spendenseite). Ohne Plugin geht es direkt in die Verwaltung.
            $handled = $app->hooks->filter('core.landing', null, $request);
            if ($handled instanceof Response) {
                return $handled;
            }

            if (!$app->auth->isLoggedIn()) {
                return Response::redirect($app->url('/login'));
            }

            return Response::redirect($app->url((new Nav($app))->firstAllowedHref()));
        });

        // --- Anmeldung -------------------------------------------------
        $router->get('/login', [$auth, 'showLogin']);
        $router->get('/login/start', [$auth, 'startLogin']);
        $router->get('/auth/callback', [$auth, 'callback']);
        $router->post('/logout', [$auth, 'logout']);

        // --- Ersteinrichtung -------------------------------------------
        $router->get('/setup', [$setup, 'show']);
        $router->post('/setup/database', [$setup, 'prepareDatabase']);
        $router->post('/setup/twitch', [$setup, 'saveCredentials']);
        $router->get('/setup/channel', [$setup, 'startChannelConnect']);
        $router->post('/setup/events', [$setup, 'syncEventSub']);
        $router->post('/setup/finish', [$setup, 'finish']);

        // --- Twitch-Webhook --------------------------------------------
        // Kein Login: Twitch authentifiziert sich per Signatur.
        $router->post('/hooks/twitch', [$webhook, 'handle']);

        // --- Konto ------------------------------------------------------
        $router->get('/account', static function () use ($app): Response {
            return Response::redirect($app->url((new Nav($app))->firstAllowedHref()));
        }, ['auth' => true]);

        $router->get('/account/users', [$account, 'users'], [
            'auth' => true,
            'permission' => 'Konto.Benutzer.View',
        ]);
        $router->post('/account/users', [$account, 'usersAction'], ['auth' => true]);

        // Zweiter Reiter und die Rechteseite je Benutzer. Eigene
        // Seite, weil es knapp hundert Kaestchen sind.
        $router->get('/account/users/permissions', [$account, 'permissions'], [
            'auth' => true,
            'permission' => 'Konto.Benutzer.View',
        ]);
        $router->get('/account/users/permissions/{id}', [$account, 'permissionsEdit'], [
            'auth' => true,
            'permission' => 'Konto.Benutzer.View',
        ]);

        // Aktivitäten: Einstellungen im Konto, der Feed selbst unter
        // /obs (gedacht als Browser-Dock in OBS).
        $router->get('/account/activities', [$activity, 'show'], [
            'auth' => true,
            'permission' => 'Konto.Aktivitaeten.View',
        ]);
        $router->post('/account/activities', [$activity, 'save'], ['auth' => true]);

        // Kurze Adresse, weil der Link in OBS eingetragen und
        // gelegentlich von Hand getippt wird.
        // --- Overlay ----------------------------------------------------
        // Zwei Seiten, wie beim Aktivitaeten-Feed: die Flaeche selbst
        // fuer OBS, und die Einstellungen dazu unter Konto.
        $overlay = new \TwitchController\Core\Overlay\OverlayController($app);

        $router->get('/overlay', [$overlay, 'show'], [
            'auth' => true,
            'permission' => 'Konto.Overlay.View',
        ]);
        $router->get('/overlay/stream', [$overlay, 'stream'], [
            'auth' => true,
            'permission' => 'Konto.Overlay.View',
        ]);
        $router->get('/account/overlay', [$overlay, 'settings'], [
            'auth' => true,
            'permission' => 'Konto.Overlay.View',
        ]);
        $router->post('/account/overlay', [$overlay, 'save'], ['auth' => true]);

        $router->get('/obs', [$feed, 'show'], [
            'auth' => true,
            'permission' => 'Konto.Aktivitaeten.View',
        ]);
        $router->get('/obs/updates', [$feed, 'updates'], [
            'auth' => true,
            'permission' => 'Konto.Aktivitaeten.View',
        ]);

        // Plugins: zwei Reiter, eigene Detailseiten
        $router->get('/account/plugins', [$plugins, 'installed'], [
            'auth' => true,
            'permission' => 'Konto.Plugins.View',
        ]);
        $router->post('/account/plugins', [$plugins, 'action'], ['auth' => true]);

        $router->get('/account/plugins/find', [$plugins, 'find'], [
            'auth' => true,
            'permission' => 'Konto.Plugins.View',
        ]);
        $router->post('/account/plugins/find', [$plugins, 'findAction'], ['auth' => true]);
        $router->get('/account/plugins/find/{slug}', [$plugins, 'detail'], [
            'auth' => true,
            'permission' => 'Konto.Plugins.View',
        ]);

        $router->get('/account/settings', [$account, 'settings'], [
            'auth' => true,
            'permission' => 'Konto.Einstellungen.View',
        ]);
        $router->post('/account/settings', [$account, 'settingsAction'], ['auth' => true]);

        // Drei Reiter, eine POST-Route: die Aktionen unterscheiden sich
        // im Feld "action", nicht in der Adresse.
        $router->get('/account/settings/channel', [$account, 'settingsChannel'], [
            'auth' => true,
            'permission' => 'Konto.Einstellungen.View',
        ]);
        $router->get('/account/settings/secrets', [$account, 'settingsSecrets'], [
            'auth' => true,
            'permission' => 'Konto.Einstellungen.View',
        ]);

        $router->get('/account/settings/connect', [$account, 'reconnectChannel'], ['auth' => true]);

        // Notausgang. Bewusst nicht unter /account: Update und Sprache
        // muessen erreichbar bleiben, wenn eine Seite dort nicht mehr
        // laedt - sonst liegt der Knopf, der den Fehler behebt, hinter
        // dem Fehler.
        $router->get('/rescue', [$account, 'rescue'], [
            'auth' => true,
            'permission' => 'Konto.Einstellungen.View',
        ]);
        $router->post('/rescue', [$account, 'rescueAction'], ['auth' => true]);

        // --- Plugin-Dateien ---------------------------------------------
        // Plugins liegen ausserhalb des DocumentRoots, ihre statischen
        // Dateien werden deshalb hier ausgeliefert:
        //   /plugin/alerts/assets/alert.js
        $router->get('/plugin/{slug}/assets/{path*}', static function (
            Request $request,
            array $params
        ) use ($app): Response {
            return self::pluginAsset($app, (string) $params['slug'], (string) $params['path']);
        });
    }

    /**
     * Statische Datei eines Plugins ausliefern. Nur aus dem
     * assets-Verzeichnis des Plugins und nur bekannte Dateitypen.
     */
    private static function pluginAsset(App $app, string $slug, string $path): Response
    {
        $manifest = $app->plugins->manifest($slug);
        if ($manifest === null || !$app->plugins->isEnabled($slug)) {
            return Response::text('Not Found', 404);
        }

        $base = $manifest->directory . '/assets';
        $file = realpath($base . '/' . $path);

        // Verzeichniswechsel verhindern: die aufgeloeste Datei muss
        // wirklich unter assets/ liegen.
        if ($file === false || !str_starts_with($file, (string) realpath($base))) {
            return Response::text('Not Found', 404);
        }

        $types = [
            'css'  => 'text/css; charset=utf-8',
            'js'   => 'application/javascript; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            'svg'  => 'image/svg+xml',
            'png'  => 'image/png',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'woff2' => 'font/woff2',
            'mp3'  => 'audio/mpeg',
            'ogg'  => 'audio/ogg',
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
        ];

        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!isset($types[$extension])) {
            return Response::text('Not Found', 404);
        }

        // Ein Jahr: die Adresse traegt den Aenderungsstempel,
        // siehe App::asset().
        return Response::file($file, $types[$extension], 31536000);
    }
}
