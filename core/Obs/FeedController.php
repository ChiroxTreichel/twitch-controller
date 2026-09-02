<?php

declare(strict_types=1);

namespace Overlays\Core\Obs;

use Overlays\Core\App;
use Overlays\Core\Http\Request;
use Overlays\Core\Http\Response;

/**
 * Der Aktivitaeten-Feed: was im Kanal passiert, als Liste mit farbigen
 * Badges. Gedacht als Fenster daneben oder als Browser-Dock in OBS
 * (Ansicht > Docks > Eigenes Browser-Dock) - ein Dock teilt die Cookies
 * mit dem Browser, deshalb funktioniert die Anmeldung dort.
 *
 * Adresse: /aktivitaeten
 * Einstellungen dazu: Konto > Aktivitaeten
 *
 * Nachladen laeuft ueber dieselbe Adresse mit ?format=json&since_id=…
 * Die Seite fragt in einem festen Takt nach und haengt Neues oben an,
 * ohne neu zu laden.
 */
final class FeedController
{
    private const DEFAULT_LIMIT = 100;

    public function __construct(private readonly App $app)
    {
    }

    public function show(Request $request): Response
    {
        $filters = new Filters($this->app);
        $badges = new Badges($this->app);

        $selected = $filters->selected($request->query);
        $range = $filters->range($request->query);
        $limit = self::limit($request);
        $page = max(1, (int) ($request->get('seite') ?: '1'));

        $result = $this->collect($selected, $range, $limit, ($page - 1) * $limit);

        return Response::html($this->app->view->render('feed', [
            'title'      => 'Aktivitäten',
            'events'     => $result['events'],
            'latest'     => $result['latest'],
            'total'      => $result['total'],
            'groups'     => $filters->groups(),
            'selected'   => $selected,
            'ranges'     => Filters::RANGES,
            'range'      => $range,
            'limit'      => $limit,
            'page'       => $page,
            'pages'      => max(1, (int) ceil($result['total'] / $limit)),
            'refresh'    => self::refresh($request, $this->app->settings->int('obs_feed_refresh', 5)),
            'compact'    => $request->get('kompakt') !== '' || $this->app->settings->bool('obs_feed_compact', false),
            'badges'     => $badges->resolved(),
            'query'      => $request->query,
        ], null));
    }

    /**
     * Nur die neuen Ereignisse seit einer bekannten ID - fuer das
     * Nachladen im Hintergrund.
     */
    public function updates(Request $request): Response
    {
        $filters = new Filters($this->app);

        $selected = $filters->selected($request->query);
        $range = $filters->range($request->query);
        $sinceId = max(0, (int) $request->get('since_id'));

        $result = $this->collect($selected, $range, self::DEFAULT_LIMIT, 0, $sinceId);

        return Response::json([
            'events' => $result['events'],
            'latest' => max($sinceId, $result['latest']),
        ]);
    }

    /**
     * Liest Ereignisse, bereitet sie auf und wirft aus, was nicht zur
     * Auswahl passt.
     *
     * Gefiltert wird erst nach dem Aufbereiten, weil sich der
     * Filterschluessel (etwa "Sub Stufe 2") erst aus dem Payload ergibt
     * und nicht als Spalte in der Datenbank steht. Bei aktiver Auswahl
     * wird deshalb ein groesseres Fenster gelesen.
     *
     * @param list<string> $selected
     * @return array{events: list<array<string, mixed>>, latest: int, total: int}
     */
    private function collect(
        array $selected,
        string $range,
        int $limit,
        int $offset,
        int $sinceId = 0,
    ): array {
        $presenter = new Presenter($this->app);
        $interval = (new Filters($this->app))->interval($range);

        $storeFilters = ['interval' => $interval];
        if ($sinceId > 0) {
            $storeFilters['min_id'] = $sinceId;
        }

        $fetch = $selected === [] ? $limit : min(500, max($limit * 5, 200));

        $rows = $this->app->events->recent($fetch, $offset, $storeFilters);
        $total = $this->app->events->count($storeFilters);

        $events = [];
        $latest = 0;

        foreach ($rows as $row) {
            $latest = max($latest, (int) ($row['id'] ?? 0));

            $view = $presenter->present($row);
            if ($view === null) {
                continue;
            }

            if ($selected !== [] && !in_array($view['filter'], $selected, true)) {
                continue;
            }

            $events[] = $view;

            if (count($events) >= $limit) {
                break;
            }
        }

        return ['events' => $events, 'latest' => $latest, 'total' => $total];
    }

    private static function limit(Request $request): int
    {
        $limit = (int) ($request->get('limit') ?: (string) self::DEFAULT_LIMIT);

        return max(10, min(500, $limit));
    }

    private static function refresh(Request $request, int $default): int
    {
        $refresh = (int) ($request->get('takt') ?: (string) $default);

        // 0 heisst "nicht nachladen". Sonst nicht schneller als alle
        // zwei Sekunden, damit der Server nicht unnoetig arbeitet.
        return $refresh === 0 ? 0 : max(2, min(120, $refresh));
    }
}
