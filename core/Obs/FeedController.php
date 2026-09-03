<?php

declare(strict_types=1);

namespace TwitchController\Core\Obs;

use TwitchController\Core\App;
use TwitchController\Core\Http\Request;
use TwitchController\Core\Http\Response;

/**
 * Der Aktivitaeten-Feed: was im Kanal passiert, als Liste mit farbigen
 * Badges. Gedacht als Fenster daneben oder als Browser-Dock in OBS
 * (Ansicht > Docks > Eigenes Browser-Dock) - ein Dock teilt die Cookies
 * mit dem Browser, deshalb funktioniert die Anmeldung dort.
 *
 * Adresse: /obs
 * Einstellungen dazu: Konto > Aktivitaeten
 *
 * Nachladen laeuft ueber /obs/updates?since_id=…
 * Die Seite fragt in einem festen Takt nach und haengt Neues oben an,
 * ohne neu zu laden.
 */
final class FeedController
{
    private const DEFAULT_LIMIT = 100;

    /** Takt zum Nachladen in Sekunden. Ueber ?takt= aenderbar. */
    private const DEFAULT_REFRESH = 5;

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
            'tree'       => $filters->tree(),
            'leaves'     => $filters->leaves(),
            'selected'   => $selected,
            'allSelected' => $filters->isAll($selected),
            'ranges'     => Filters::RANGES,
            'range'      => $range,
            'limit'      => $limit,
            'page'       => $page,
            'pages'      => max(1, (int) ceil($result['total'] / $limit)),
            'refresh'    => self::refresh($request),
            'compact'    => $request->get('kompakt') !== '',
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
        $filters = new Filters($this->app);

        // Leere Auswahl heisst "alles abgewaehlt" - dann gibt es nichts
        // zu holen. "Kein Filter in der Adresse" liefert dagegen alle
        // Blaetter, das entscheidet Filters::selected().
        if ($selected === []) {
            return ['events' => [], 'latest' => $sinceId, 'total' => 0];
        }

        $presenter = new Presenter($this->app);

        $storeFilters = ['interval' => $filters->interval($range)];
        if ($sinceId > 0) {
            $storeFilters['min_id'] = $sinceId;
        }

        // Bei eingeschraenkter Auswahl ein groesseres Fenster lesen,
        // weil erst nach dem Aufbereiten gefiltert werden kann.
        $fetch = $filters->isAll($selected) ? $limit : min(500, max($limit * 5, 200));

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

            if (!in_array($view['filter'], $selected, true)) {
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

    private static function refresh(Request $request): int
    {
        $refresh = (int) ($request->get('takt') ?: (string) self::DEFAULT_REFRESH);

        // 0 heisst "nicht nachladen". Sonst nicht schneller als alle
        // zwei Sekunden, damit der Server nicht unnoetig arbeitet.
        return $refresh === 0 ? 0 : max(2, min(120, $refresh));
    }
}
