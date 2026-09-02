<?php

declare(strict_types=1);

namespace Overlays\Core\Admin;

use Overlays\Core\App;
use Overlays\Core\Http\Request;
use Overlays\Core\Http\Response;
use Overlays\Core\Obs\Badges;
use Throwable;

/**
 * Konto > Aktivitaeten
 *
 * Nicht die Liste selbst - die liegt unter /aktivitaeten und ist als
 * eigenes Fenster oder Browser-Dock in OBS gedacht. Hier wird sie
 * eingestellt: Farben der Badges, Takt zum Nachladen, Voreinstellung
 * fuer die kompakte Ansicht, und der Link zum Kopieren.
 */
final class ActivityController
{
    public function __construct(private readonly App $app)
    {
    }

    public function show(Request $request): Response
    {
        $badges = new Badges($this->app);

        return Response::html($this->app->view->render('account/activities', [
            'title'     => 'Aktivitäten',
            'active'    => 'konto/aktivitaeten',
            'badges'    => $badges->resolved(),
            'presets'   => $badges->catalog(),
            'feedUrl'   => $this->app->url('/aktivitaeten'),
            'refresh'   => $this->app->settings->int('obs_feed_refresh', 5),
            'timezone'  => $this->app->timezone(),
            'timezones' => self::timezones(),
            'compact'   => $this->app->settings->bool('obs_feed_compact', false),
            'canManage' => $this->app->auth->can('Konto.Aktivitaeten.Manage'),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('hinweis'),
            'error'     => $request->get('fehler'),
        ]));
    }

    public function save(Request $request): Response
    {
        if (!$this->app->auth->checkCsrf($request->input('csrf'))) {
            return $this->back(null, 'Das Formular ist abgelaufen. Bitte erneut versuchen.');
        }

        if (!$this->app->auth->can('Konto.Aktivitaeten.Manage')) {
            return $this->back(null, 'Dafür fehlt dir die Berechtigung.');
        }

        try {
            if ($request->input('action') === 'reset') {
                (new Badges($this->app))->reset();

                return $this->back('Farben auf die Vorgaben zurückgesetzt.');
            }

            $saved = (new Badges($this->app))->save($request->post);

            // 0 heisst "nicht nachladen" - alles andere zwischen 2 und 120.
            $refresh = (int) $request->input('refresh');
            $this->app->settings->set('obs_feed_refresh', $refresh === 0 ? 0 : max(2, min(120, $refresh)));
            $this->app->settings->set('obs_feed_compact', $request->input('compact') !== '');

            $timezone = $request->input('timezone');
            if ($timezone !== '' && in_array($timezone, timezone_identifiers_list(), true)) {
                $this->app->settings->set('timezone', $timezone);
                $this->app->applyTimezone();
            }

            return $this->back(sprintf('Gespeichert (%d Farben).', $saved));
        } catch (Throwable $e) {
            return $this->back(null, $e->getMessage());
        }
    }

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

    private function back(?string $notice = null, ?string $error = null): Response
    {
        $query = [];
        if ($notice !== null) {
            $query['hinweis'] = $notice;
        }
        if ($error !== null) {
            $query['fehler'] = $error;
        }

        return Response::redirect(
            $this->app->url('/konto/aktivitaeten') . ($query === [] ? '' : '?' . http_build_query($query))
        );
    }
}
