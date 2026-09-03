<?php

declare(strict_types=1);

namespace Overlays\Core;

use Overlays\Core\Admin\AccountController;
use Overlays\Core\Admin\ActivityController;
use Overlays\Core\Admin\Nav;
use Overlays\Core\Admin\PluginsController;
use Overlays\Core\Obs\FeedController;
use Overlays\Core\Auth\AuthController;
use Overlays\Core\Http\Request;
use Overlays\Core\Http\Response;
use Overlays\Core\Setup\SetupController;
use Overlays\Core\Twitch\WebhookController;

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
        $router->post('/setup/datenbank', [$setup, 'prepareDatabase']);
        $router->post('/setup/twitch', [$setup, 'saveCredentials']);
        $router->get('/setup/kanal', [$setup, 'startChannelConnect']);
        $router->post('/setup/events', [$setup, 'syncEventSub']);
        $router->post('/setup/fertig', [$setup, 'finish']);

        // --- Twitch-Webhook --------------------------------------------
        // Kein Login: Twitch authentifiziert sich per Signatur.
        $router->post('/hooks/twitch', [$webhook, 'handle']);

        // --- Konto ------------------------------------------------------
        $router->get('/konto', static function () use ($app): Response {
            return Response::redirect($app->url((new Nav($app))->firstAllowedHref()));
        }, ['auth' => true]);

        $router->get('/konto/benutzer', [$account, 'users'], [
            'auth' => true,
            'permission' => 'Konto.Benutzer.View',
        ]);
        $router->post('/konto/benutzer', [$account, 'usersAction'], ['auth' => true]);

        // Aktivitäten: Einstellungen im Konto, der Feed selbst unter
        // /obs (gedacht als Browser-Dock in OBS).
        $router->get('/konto/aktivitaeten', [$activity, 'show'], [
            'auth' => true,
            'permission' => 'Konto.Aktivitaeten.View',
        ]);
        $router->post('/konto/aktivitaeten', [$activity, 'save'], ['auth' => true]);

        // Kurze Adresse, weil der Link in OBS eingetragen und
        // gelegentlich von Hand getippt wird.
        $router->get('/obs', [$feed, 'show'], [
            'auth' => true,
            'permission' => 'Konto.Aktivitaeten.View',
        ]);
        $router->get('/obs/neu', [$feed, 'updates'], [
            'auth' => true,
            'permission' => 'Konto.Aktivitaeten.View',
        ]);

        // Plugins: zwei Reiter, eigene Detailseiten
        $router->get('/konto/plugins', [$plugins, 'installed'], [
            'auth' => true,
            'permission' => 'Konto.Plugins.View',
        ]);
        $router->post('/konto/plugins', [$plugins, 'action'], ['auth' => true]);

        $router->get('/konto/plugins/finden', [$plugins, 'find'], [
            'auth' => true,
            'permission' => 'Konto.Plugins.View',
        ]);
        $router->post('/konto/plugins/finden', [$plugins, 'findAction'], ['auth' => true]);
        $router->get('/konto/plugins/finden/{slug}', [$plugins, 'detail'], [
            'auth' => true,
            'permission' => 'Konto.Plugins.View',
        ]);

        $router->get('/konto/einstellungen', [$account, 'settings'], [
            'auth' => true,
            'permission' => 'Konto.Einstellungen.View',
        ]);
        $router->post('/konto/einstellungen', [$account, 'settingsAction'], ['auth' => true]);
        $router->get('/konto/einstellungen/kanal', [$account, 'reconnectChannel'], ['auth' => true]);

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

        return Response::file($file, $types[$extension], 86400);
    }
}
