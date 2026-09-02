<?php

declare(strict_types=1);

/**
 * ===================================================================
 *  Beispiel-Plugin - die ausfuehrbare Dokumentation des Vertrags.
 * ===================================================================
 *
 * Diese Datei wird bei jedem Request geladen, sobald das Plugin aktiv
 * ist. Sie soll NUR registrieren, nicht arbeiten - schwere Arbeit
 * gehoert in die Hooks, sonst wird jeder Seitenaufruf langsam.
 *
 * Verfuegbare Variablen:
 *   $app      Overlays\Core\App
 *   $plugin   Overlays\Core\Plugin\Manifest  (slug, version, directory)
 *   $hooks    Overlays\Core\Hook\Hooks
 *   $router   Overlays\Core\Http\Router
 *   $settings Overlays\Core\Config\Settings
 *   $db       Overlays\Core\Database\Db
 *
 * Dateien eines Plugins:
 *   plugin.json      Manifest (slug, name, version, requires, optional)
 *   plugin.php       diese Datei
 *   install.php      Tabellen anlegen / hochziehen
 *   uninstall.php    Tabellen abraeumen
 *   views/           eigene Vorlagen (optional)
 *   assets/          CSS, JS, Bilder (optional, ueber /plugin/<slug>/assets/…)
 *   src/             Klassen, Namensraum Overlays\Plugin\<Slug>\… (optional)
 */

use Overlays\Core\Http\Request;
use Overlays\Core\Http\Response;

/** @var \Overlays\Core\App $app */
/** @var \Overlays\Core\Plugin\Manifest $plugin */
/** @var \Overlays\Core\Hook\Hooks $hooks */
/** @var \Overlays\Core\Http\Router $router */
/** @var \Overlays\Core\Config\Settings $settings */

// Eigener Einstellungs-Bereich. Alles, was das Plugin speichert, liegt
// unter diesem Scope und wird beim Entfernen mitgeloescht.
$scope = \Overlays\Core\Config\Settings::pluginScope($plugin->slug);

// -------------------------------------------------------------------
//  1. Eigene Rechte anmelden
// -------------------------------------------------------------------
// Schema: Bereich.Funktion.Recht - dieselbe Form wie im Kern. Rechte,
// die auf ".View" enden, bekommen neu eingeladene Benutzer automatisch.
$hooks->on('permissions.catalog', static function (array $catalog): array {
    $catalog['Beispiel'] = [
        'label' => 'Beispiel',
        'permissions' => [
            'Beispiel.Seite.View'   => 'darf die Beispielseite öffnen.',
            'Beispiel.Seite.Manage' => 'darf die Beispieleinstellung ändern.',
        ],
    ];

    return $catalog;
});

// -------------------------------------------------------------------
//  2. Menuepunkt anhaengen
// -------------------------------------------------------------------
// "order" bestimmt die Position: der Kern-Bereich "Konto" hat 0,
// alles darueber landet dahinter.
$hooks->on('admin.nav', static function (array $nav): array {
    $nav['beispiel'] = [
        'label' => 'Beispiel',
        'order' => 90,
        'items' => [
            [
                'label'      => 'Beispielseite',
                'href'       => '/beispiel',
                'permission' => 'Beispiel.Seite.View',
            ],
        ],
    ];

    return $nav;
});

// -------------------------------------------------------------------
//  3. Eigene Seiten
// -------------------------------------------------------------------
// Vorlagen liegen im Plugin, benutzen aber das Layout des Kerns.
$router->get('/beispiel', static function (Request $request) use ($app, $plugin, $scope): Response {
    return Response::html(
        $app->view->from($plugin->directory . '/views')->render('seite', [
            'title'     => 'Beispiel',
            'active'    => 'beispiel',
            'gruss'     => $app->settings->string('gruss', 'Moin', $scope),
            'zaehler'   => $app->settings->int('events_gesehen', 0, $scope),
            'canManage' => $app->auth->can('Beispiel.Seite.Manage'),
            'csrf'      => $app->auth->csrfToken(),
            'notice'    => $request->get('hinweis'),
        ])
    );
}, ['auth' => true, 'permission' => 'Beispiel.Seite.View']);

$router->post('/beispiel', static function (Request $request) use ($app, $scope): Response {
    if (!$app->auth->checkCsrf($request->input('csrf'))) {
        return Response::text('Formular abgelaufen.', 400);
    }

    if (!$app->auth->can('Beispiel.Seite.Manage')) {
        return Response::text('Keine Berechtigung.', 403);
    }

    $app->settings->set('gruss', $request->input('gruss'), $scope);

    return Response::redirect($app->url('/beispiel?hinweis=Gespeichert.'));
}, ['auth' => true]);

// -------------------------------------------------------------------
//  4. Auf Twitch-Events reagieren
// -------------------------------------------------------------------
// Laeuft direkt im Webhook-Request, muss also schnell sein. Fuer
// aufwendige Arbeit besser eine Notiz hinterlassen und sie im
// Hintergrundprozess (cron.tick) abarbeiten.
$hooks->on('core.event.stored', static function (array $event) use ($app, $scope): void {
    if (($event['event_type'] ?? '') !== 'twitch.channel.follow') {
        return;
    }

    $app->settings->set('events_gesehen', $app->settings->int('events_gesehen', 0, $scope) + 1, $scope);
    $app->settings->set('letzter_follower', (string) ($event['actor_name'] ?? '?'), $scope);
});

// -------------------------------------------------------------------
//  5. Zusaetzliche Twitch-Abos anfordern
// -------------------------------------------------------------------
// Nach dem Aktivieren einmal "Abos abgleichen" in den Einstellungen -
// dann bestellt der Kern die hier gemeldeten Typen bei Twitch nach.
$hooks->on('core.eventsub.subscriptions', static function (array $subscriptions, string $broadcasterId): array {
    $subscriptions[] = [
        'type'      => 'channel.channel_points_custom_reward_redemption.add',
        'version'   => '1',
        'condition' => ['broadcaster_user_id' => $broadcasterId],
    ];

    return $subscriptions;
});

// -------------------------------------------------------------------
//  6. Zusaetzliche Twitch-Berechtigungen anfordern
// -------------------------------------------------------------------
// Wird beim Verbinden des Kanals mitabgefragt. Fehlt ein Scope, weist
// die Einstellungsseite darauf hin und bietet "neu verbinden" an.
$hooks->on('core.twitch.broadcaster_scopes', static function (array $scopes): array {
    $scopes[] = 'channel:read:redemptions';

    return $scopes;
});

// -------------------------------------------------------------------
//  7. Wiederkehrende Aufgaben
// -------------------------------------------------------------------
// Laeuft im worker-Container im Takt von WORKER_INTERVAL.
$hooks->on('cron.tick', static function () use ($app, $scope): void {
    $app->settings->set('letzter_tick', date('c'), $scope);
});
