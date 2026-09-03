<?php

declare(strict_types=1);

namespace TwitchController\Core\Admin;

use TwitchController\Core\App;
use TwitchController\Core\Http\Request;
use TwitchController\Core\Http\Response;
use TwitchController\Core\Obs\Badges;
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
            'active'    => 'account/activities',
            'badges'    => $badges->resolved(),
            'presets'   => $badges->catalog(),
            'feedUrl'   => $this->app->url('/obs'),
            'canManage' => $this->app->auth->can('Konto.Aktivitaeten.Manage'),
            'csrf'      => $this->app->auth->csrfToken(),
            'notice'    => $request->get('notice'),
            'error'     => $request->get('error'),
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

            return $this->back(sprintf('Gespeichert (%d Farben).', $saved));
        } catch (Throwable $e) {
            return $this->back(null, $e->getMessage());
        }
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
            $this->app->url('/account/activities') . ($query === [] ? '' : '?' . http_build_query($query))
        );
    }
}
