<?php

declare(strict_types=1);

/**
 * Einziger Einstiegspunkt. Apache schickt alles hierher, was keine
 * vorhandene Datei ist (siehe docker/apache/000-default.conf).
 *
 * Grund fuer den Front-Controller: Plugins liegen unter plugins/ und
 * koennen keine Dateien im DocumentRoot ablegen - sie registrieren ihre
 * Routen stattdessen im Router.
 */

use Overlays\Core\App;
use Overlays\Core\Http\Request;
use Overlays\Core\Http\Response;
use Overlays\Core\Routes;
use Overlays\Core\Support\Autoloader;

$root = dirname(__DIR__);

require $root . '/core/Support/Autoloader.php';
Autoloader::register($root);

$request = Request::fromGlobals();

try {
    $app = App::boot($root);
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Start fehlgeschlagen:\n" . $e->getMessage();
    exit;
}

// Zeiten in der Ortszeit des Streamers anzeigen, nicht in UTC.
$app->applyTimezone();

// Sprache der Oberflaeche laden.
$app->applyLanguage();

// Rechtepruefung fuer alle Routen, die es verlangen.
$app->router->setGuard(static function (array $options, Request $request) use ($app): ?Response {
    if (!empty($options['auth']) && !$app->auth->isLoggedIn()) {
        return Response::redirect($app->url('/login'));
    }

    $permission = (string) ($options['permission'] ?? '');
    if ($permission !== '' && !$app->auth->can($permission)) {
        return Response::html(
            $app->view->render('error', [
                'title'   => 'Kein Zugriff',
                'heading' => 'Kein Zugriff',
                'message' => 'Für diesen Bereich fehlt dir die Berechtigung. Der Kanalinhaber kann sie freischalten.',
            ], 'plain'),
            403
        );
    }

    return null;
});

Routes::register($app);

$installed = $app->isInstalled();

// Solange nicht eingerichtet ist, geht alles zum Installer - ausser dem
// Installer selbst, dem OAuth-Rueckweg und statischen Dateien.
if (!$installed) {
    $allowed = str_starts_with($request->path, '/setup')
        || str_starts_with($request->path, '/auth/')
        || str_starts_with($request->path, '/assets/');

    if (!$allowed) {
        $response = Response::redirect($app->url('/setup'));
        $response->send();
        exit;
    }
} else {
    // Plugins erst laden, wenn das System steht. Ein Fehler in einem
    // Plugin darf die Verwaltung nicht unbenutzbar machen, deshalb faengt
    // der PluginManager pro Plugin ab.
    $app->plugins->boot();

    // Nach einem Update der Kern-Dateien das Schema nachziehen.
    if ($app->settings->string('core_version') !== App::VERSION) {
        try {
            $app->installCore();
        } catch (Throwable $e) {
            $app->log('Kern-Update fehlgeschlagen: ' . $e->getMessage());
        }
    }
}

try {
    $response = $app->router->dispatch($request);
} catch (Throwable $e) {
    $app->log('Unbehandelter Fehler bei ' . $request->method . ' ' . $request->path . ': ' . $e->getMessage());
    $app->log($e->getTraceAsString());

    $response = Response::html(
        $app->view->render('error', [
            'title'   => 'Fehler',
            'heading' => 'Da ist etwas schiefgegangen',
            'message' => 'Die Einzelheiten stehen im Log des Containers: docker compose logs -f web',
        ], 'plain'),
        500
    );
}

$response->send();
