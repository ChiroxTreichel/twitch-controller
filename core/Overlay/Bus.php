<?php

declare(strict_types=1);

namespace TwitchController\Core\Overlay;

use TwitchController\Core\App;

/**
 * ===================================================================
 *  Die Leitung zwischen Server und Overlay
 * ===================================================================
 *
 * Ein Twitch-Event kommt in einem Webhook-Request an. Die Browserquelle
 * in OBS haengt an einem voellig anderen Request. Beide muessen sich
 * treffen - und PHP hat zwischen zwei Requests kein gemeinsames
 * Gedaechtnis.
 *
 * Deshalb geht es ueber eine Tabelle: wer etwas anzeigen will, legt
 * eine Nachricht ab, und die offene SSE-Antwort liest nach, was seit
 * ihrer letzten Nummer dazugekommen ist.
 *
 * Warum kein LISTEN/NOTIFY von Postgres, was ohne Nachfragen ginge:
 * es braeuchte eine zweite, dauerhaft offene Verbindung, und eine
 * verpasste Benachrichtigung ist unwiederbringlich. Ueber die Tabelle
 * holt eine Browserquelle, die kurz weg war, das Verpasste noch nach -
 * und man kann hinterher nachsehen, ob ein Alert ueberhaupt abgeschickt
 * wurde. Beim Suchen eines Fehlers ist das mehr wert als die
 * eingesparten Abfragen.
 *
 * Benutzung aus einem Plugin:
 *
 *   use TwitchController\Core\Overlay\Bus;
 *
 *   (new Bus($app))->send('alerts', [
 *       'kind'  => 'follow',
 *       'name'  => 'Chirox',
 *       'sound' => $app->asset('/plugin/alerts/assets/follow.mp3'),
 *   ]);
 *
 * Im Overlay kommt das als Ereignis mit dem Namen des Platzes an:
 *
 *   Overlay.on('alerts', function (data) { … });
 */
final class Bus
{
    /**
     * Wie lange Nachrichten liegen bleiben. Lang genug, dass eine
     * Browserquelle einen Neustart von OBS uebersteht, kurz genug,
     * dass die Tabelle nicht waechst.
     */
    private const KEEP_MINUTES = 15;

    /** Aufraeumen nicht bei jeder Nachricht, sondern etwa jede 20. */
    private const CLEAN_EVERY = 20;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Nachricht an einen Platz im Overlay.
     *
     * @param array<string, mixed> $payload
     * @return int Nummer der Nachricht, 0 wenn der Platzname nichts taugt
     */
    public function send(string $slot, array $payload): int
    {
        $slot = self::normalizeSlot($slot);
        if ($slot === '') {
            return 0;
        }

        $id = (int) $this->app->db->value(
            'INSERT INTO overlay_messages (slot, payload)
                  VALUES (:slot, CAST(:payload AS JSONB))
               RETURNING id',
            [
                'slot'    => $slot,
                'payload' => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        if ($id > 0 && $id % self::CLEAN_EVERY === 0) {
            $this->clean();
        }

        return $id;
    }

    /**
     * Alles, was nach dieser Nummer kam.
     *
     * @return list<array{id: int, slot: string, payload: array<string, mixed>}>
     */
    public function since(int $lastId, int $limit = 50): array
    {
        $rows = $this->app->db->all(
            'SELECT id, slot, payload
               FROM overlay_messages
              WHERE id > :last
              ORDER BY id
              LIMIT ' . max(1, min(200, $limit)),
            ['last' => max(0, $lastId)]
        );

        $messages = [];
        foreach ($rows as $row) {
            $payload = json_decode((string) $row['payload'], true);

            $messages[] = [
                'id'      => (int) $row['id'],
                'slot'    => (string) $row['slot'],
                'payload' => is_array($payload) ? $payload : [],
            ];
        }

        return $messages;
    }

    /**
     * Hoechste vergebene Nummer. Eine Browserquelle, die sich zum
     * ersten Mal verbindet, startet hier - sonst spielte sie beim
     * Verbinden alles nach, was in den letzten Minuten passiert ist.
     */
    public function latestId(): int
    {
        return (int) $this->app->db->value('SELECT COALESCE(MAX(id), 0) FROM overlay_messages');
    }

    public function clean(): void
    {
        // Schreibweise wie im uebrigen Kern: ein gebundener Parameter
        // kann in Postgres nicht direkt hinter INTERVAL stehen.
        $this->app->db->run(
            'DELETE FROM overlay_messages
              WHERE created_at < now() - (:keep || \' minutes\')::interval',
            ['keep' => (string) self::KEEP_MINUTES]
        );
    }

    /**
     * Plaetze, die die aktiven Plugins angemeldet haben.
     *
     * Ein Platz ist ein Kasten im Overlay mit einer Stelle und einer
     * Groesse. Was darin passiert, macht das Plugin selbst per
     * JavaScript - das Overlay stellt nur den Kasten.
     *
     * @return array<string, array{label: string, position: string, width: string, height: string, z: int, vars: array<string, string>}>
     */
    public static function slots(App $app): array
    {
        // Ein Platz gehoert dem Kern: darin zeigt "Test senden" seine
        // Nachricht. Ohne ihn waere die Flaeche erst zu pruefen,
        // nachdem irgendein Plugin einen Platz angemeldet hat - und
        // eine Kernfaehigkeit, die man nicht allein ausprobieren kann,
        // ist beim Suchen eines Fehlers wertlos.
        $eingebaut = [
            'system' => [
                'label'    => translate('overlay.slot.system'),
                'position' => 'top-center',
                'z'        => 1,
            ],
        ];

        $slots = $app->hooks->filter('overlay.slots', $eingebaut);
        if (!is_array($slots)) {
            $slots = $eingebaut;
        }

        $sauber = [];
        foreach ($slots as $id => $slot) {
            $id = self::normalizeSlot((string) $id);
            if ($id === '' || !is_array($slot)) {
                continue;
            }

            $sauber[$id] = [
                'label'    => trim((string) ($slot['label'] ?? $id)) ?: $id,
                'position' => self::normalizePosition((string) ($slot['position'] ?? 'center')),
                'width'    => self::normalizeLength((string) ($slot['width'] ?? '')),
                'height'   => self::normalizeLength((string) ($slot['height'] ?? '')),
                'z'        => (int) ($slot['z'] ?? 10),
                'vars'     => self::normalizeVars($slot['vars'] ?? []),
            ];
        }

        uasort($sauber, static fn (array $a, array $b): int => $a['z'] <=> $b['z']);

        return $sauber;
    }

    /**
     * Zusaetzliche CSS- und JS-Dateien der Plugins.
     *
     * @return array{css: list<string>, js: list<string>}
     */
    public static function assets(App $app): array
    {
        $assets = $app->hooks->filter('overlay.assets', ['css' => [], 'js' => []]);
        if (!is_array($assets)) {
            $assets = [];
        }

        $nurEigene = static function (mixed $liste) use ($app): array {
            if (!is_array($liste)) {
                return [];
            }

            return array_values(array_filter(
                array_map('strval', $liste),
                // Nur eigene Adressen. Ein Plugin soll nicht ungefragt
                // Code von einem fremden Server ins Overlay holen -
                // das laeuft im Stream, unbeaufsichtigt.
                static fn (string $url): bool => $app->ownUrl($url)
            ));
        };

        return [
            'css' => $nurEigene($assets['css'] ?? []),
            'js'  => $nurEigene($assets['js'] ?? []),
        ];
    }

    /**
     * Eigene CSS-Variablen eines Platzes.
     *
     * Damit kann ein Plugin einstellbare Werte ins Overlay bringen -
     * Abstand, Mediengroesse - ohne dafuer JavaScript zu brauchen. Der
     * Wert landet in einem style-Attribut, also wird beides eng
     * geprueft: Name wie eine CSS-Variable, Wert eine Laengenangabe
     * oder eines von wenigen Schluesselwoertern.
     *
     * @return array<string, string>
     */
    private static function normalizeVars(mixed $vars): array
    {
        if (!is_array($vars)) {
            return [];
        }

        $sauber = [];

        foreach ($vars as $name => $wert) {
            $name = trim((string) $name);
            if (preg_match('/^--[a-z][a-z0-9-]{0,40}$/', $name) !== 1) {
                continue;
            }

            $wert = trim((string) $wert);

            if (in_array($wert, ['auto', 'none', 'inherit', 'initial'], true)) {
                $sauber[$name] = $wert;
                continue;
            }

            $laenge = self::normalizeLength($wert);
            if ($laenge !== '') {
                $sauber[$name] = $laenge;
            }
        }

        return $sauber;
    }

    /**
     * Ein Platzname wird zum Namen eines SSE-Ereignisses und zu einem
     * Wert in einem HTML-Attribut. Deshalb eng halten.
     */
    public static function normalizeSlot(string $slot): string
    {
        $slot = strtolower(trim($slot));

        return preg_match('/^[a-z0-9][a-z0-9_-]{0,30}$/', $slot) === 1 ? $slot : '';
    }

    /**
     * @return list<string>
     */
    public static function positions(): array
    {
        return [
            'top-left', 'top-center', 'top-right',
            'middle-left', 'center', 'middle-right',
            'bottom-left', 'bottom-center', 'bottom-right',
            'fill',
        ];
    }

    private static function normalizePosition(string $position): string
    {
        $position = strtolower(trim($position));

        return in_array($position, self::positions(), true) ? $position : 'center';
    }

    /**
     * Eine Laengenangabe, die gefahrlos in ein style-Attribut darf.
     * Leer heisst: die Vorgabe aus dem CSS gilt.
     */
    private static function normalizeLength(string $wert): string
    {
        $wert = trim($wert);

        return preg_match('/^\d{1,5}(\.\d{1,2})?(px|%|vw|vh|em|rem)$/', $wert) === 1 ? $wert : '';
    }
}
