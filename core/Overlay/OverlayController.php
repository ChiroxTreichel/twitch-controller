<?php

declare(strict_types=1);

namespace TwitchController\Core\Overlay;

use TwitchController\Core\App;
use TwitchController\Core\Http\Request;
use TwitchController\Core\Http\Response;

/**
 * Das Overlay: die Flaeche, die in OBS laeuft, und ihre Einstellungen.
 *
 * Zwei Seiten, genau wie beim Aktivitaeten-Feed:
 *
 *   /overlay           die Flaeche selbst, fuer OBS
 *   /account/overlay   die Einstellungen dazu
 *
 * Das Overlay bringt nichts zum Anzeigen mit. Es stellt Plaetze bereit,
 * die Plugins per Hook anmelden, und die Leitung dorthin. Siehe Bus.
 */
final class OverlayController
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Die Flaeche fuer OBS. Ohne Layout - kein Menue, keine Navigation,
     * nur ein durchsichtiger Kasten in Streamgroesse.
     */
    public function show(): Response
    {
        return Response::html(
            $this->app->view->render('overlay/source', [
                'width'   => $this->width(),
                'height'  => $this->height(),
                'slots'   => Bus::slots($this->app),
                'assets'  => Bus::assets($this->app),
                'stream'  => $this->app->url('/overlay/stream'),
                'debug'   => $this->app->settings->bool('overlay_debug'),
                // Von hier an weiterlesen. Ohne das spielte eine gerade
                // verbundene Quelle alles nach, was in den letzten
                // Minuten passiert ist.
                'startId' => (new Bus($this->app))->latestId(),
            ], null)
        );
    }

    /**
     * Die Leitung: Server-Sent Events.
     *
     * Warum SSE und nicht WebSocket: es ist gewoehnliches HTTP und
     * laeuft damit durch jeden Reverse Proxy ohne eigene Einstellung.
     * Es braucht keinen zweiten Port und keinen Dauerprozess. Den
     * Wiederaufbau der Verbindung macht der Browser von selbst.
     */
    public function stream(Request $request): Response
    {
        // Jede offene Antwort belegt einen PHP-Prozess, solange sie
        // laeuft. Deshalb hoert sie von selbst auf, bevor irgendein
        // Zwischenstueck sie fuer haengend haelt - der Browser
        // verbindet danach neu, es geht nichts verloren.
        $laufzeit = 50;
        $takt = 250_000;    // Mikrosekunden zwischen zwei Nachfragen
        $herzschlag = 15;   // Sekunden bis zum naechsten Lebenszeichen

        // Woher weiterlesen: beim Neuverbinden schickt der Browser die
        // letzte empfangene Nummer im Kopf Last-Event-ID mit.
        $letzte = (int) $request->header('Last-Event-ID');
        if ($letzte <= 0) {
            $letzte = (int) $request->get('since');
        }

        $bus = new Bus($this->app);
        if ($letzte <= 0) {
            $letzte = $bus->latestId();
        }

        return Response::stream(
            static function () use ($bus, $letzte, $laufzeit, $takt, $herzschlag): void {
                $ende = time() + $laufzeit;
                $zuletzt = time();

                // Wie lange der Browser nach einem Abbruch wartet.
                echo "retry: 2000\n\n";
                flush();

                while (time() < $ende) {
                    // Browserquelle geschlossen? Dann Prozess freigeben.
                    if (connection_aborted() !== 0) {
                        return;
                    }

                    foreach ($bus->since($letzte) as $nachricht) {
                        $letzte = $nachricht['id'];

                        printf(
                            "id: %d\nevent: %s\ndata: %s\n\n",
                            $nachricht['id'],
                            $nachricht['slot'],
                            (string) json_encode(
                                $nachricht['payload'],
                                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                            )
                        );

                        $zuletzt = time();
                        flush();
                    }

                    // Lebenszeichen als Kommentarzeile. Ohne das
                    // schliesst mancher Proxy eine stille Verbindung.
                    if (time() - $zuletzt >= $herzschlag) {
                        echo ": ping\n\n";
                        $zuletzt = time();
                        flush();
                    }

                    usleep($takt);
                }
            }
        );
    }

    /**
     * Einstellungen: Groesse der Flaeche, die Adresse fuer OBS und die
     * Plaetze, die die aktiven Plugins angemeldet haben.
     */
    public function settings(Request $request): Response
    {
        return Response::html($this->app->view->render('account/overlay', [
            'title'     => translate('overlay.title'),
            'active'    => 'account/overlay',
            'width'     => $this->width(),
            'height'    => $this->height(),
            'debug'     => $this->app->settings->bool('overlay_debug'),
            'sourceUrl' => $this->app->url('/overlay'),
            'slots'     => Bus::slots($this->app),
            'positions' => Bus::positions(),
            'canManage' => $this->app->auth->can('Konto.Overlay.Manage'),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('notice'),
            'error'     => $request->get('error'),
        ]));
    }

    public function save(Request $request): Response
    {
        if (!$this->app->auth->checkCsrf($request->input('csrf'))) {
            return $this->back(null, translate('common.error.form_expired'));
        }

        if (!$this->app->auth->can('Konto.Overlay.Manage')) {
            return $this->back(null, translate('common.error.no_permission'));
        }

        try {
            switch ($request->input('action')) {
                case 'canvas':
                    $breite = (int) $request->input('width');
                    $hoehe = (int) $request->input('height');

                    if ($breite < 320 || $breite > 7680 || $hoehe < 180 || $hoehe > 4320) {
                        return $this->back(null, translate('overlay.canvas_invalid'));
                    }

                    $this->app->settings->setMany([
                        'overlay_width'  => $breite,
                        'overlay_height' => $hoehe,
                        'overlay_debug'  => $request->input('debug') !== '',
                    ]);

                    return $this->back(translate('overlay.saved'));

                case 'test':
                    $slot = Bus::normalizeSlot($request->input('slot'));
                    if ($slot === '' || !isset(Bus::slots($this->app)[$slot])) {
                        return $this->back(null, translate('overlay.no_such_slot'));
                    }

                    (new Bus($this->app))->send($slot, [
                        'test'    => true,
                        'message' => translate('overlay.test_message'),
                        'at'      => date('c'),
                    ]);

                    return $this->back(translate('overlay.test_sent', ['slot' => $slot]));
            }
        } catch (\Throwable $e) {
            return $this->back(null, $e->getMessage());
        }

        return $this->back(null, translate('common.error.unknown_action'));
    }

    private function width(): int
    {
        return max(320, $this->app->settings->int('overlay_width', 1920));
    }

    private function height(): int
    {
        return max(180, $this->app->settings->int('overlay_height', 1080));
    }

    private function back(?string $notice = null, ?string $error = null): Response
    {
        $query = [];
        if ($notice !== null) {
            $query['notice'] = $notice;
        }
        if ($error !== null) {
            $query['error'] = $error;
        }

        return Response::redirect(
            $this->app->url('/account/overlay') . ($query === [] ? '' : '?' . http_build_query($query))
        );
    }
}
