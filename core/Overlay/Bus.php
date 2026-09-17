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

    // -----------------------------------------------------------------
    //  Aufbaunummer: wann muss eine laufende Browserquelle neu laden?
    // -----------------------------------------------------------------

    /**
     * Was die Overlay-Seite beim Laden festlegt, steht danach fest:
     * welche Plaetze es gibt, welche Dateien geladen sind, und welchen
     * Stempel deren Adressen tragen. Schaltet man ein Plugin ab, aendert
     * das nichts an einer Seite, die schon laeuft - man musste die
     * Browserquelle in OBS von Hand neu laden.
     *
     * Das ist der Fall, in dem es am meisten stoert: bei einem
     * Follow-Bot-Angriff schaltet man die Alerts ab und will Ruhe, nicht
     * erst in OBS herumklicken. Und die Warteschlange im Browser laeuft
     * ohnehin weiter - dort stehen die Alerts schon, das Abschalten
     * verhindert nur neue.
     *
     * Darum diese Nummer: sie geht mit der Seite hinaus, die Leitung
     * vergleicht sie mit der aktuellen, und bei einem Unterschied laedt
     * die Seite neu. Das raeumt in einem Schritt alles ab, was veraltet
     * ist - Plaetze, Dateien, Warteschlangen und den Anfangszustand.
     */
    private const BUILD_KEY = 'overlay_build';

    /**
     * Die aktuelle Nummer - frisch aus der Datenbank.
     *
     * Am Zwischenspeicher der Einstellungen vorbei, und das ist der
     * ganze Grund fuer diese Methode: die SSE-Antwort lebt fast eine
     * Minute, und in dieser Zeit ist der Zwischenspeicher genau so alt
     * wie die Frage, die er beantworten soll. Ueber ihn gelesen wuerde
     * die Leitung eine Aenderung nie bemerken.
     */
    public function build(): int
    {
        $roh = $this->app->db->value(
            'SELECT value FROM settings WHERE scope = :scope AND key = :key',
            ['scope' => 'core', 'key' => self::BUILD_KEY]
        );

        return max(0, (int) json_decode((string) $roh, true));
    }

    /**
     * Die Seite ist veraltet - laufende Browserquellen sollen neu laden.
     *
     * Zu rufen, wenn sich aendert, was die Seite beim Laden festlegt:
     * ein Plugin kommt oder geht, ein Hauptschalter mit Overlay-Anteil
     * kippt, das Aussehen eines Ziels wird gespeichert.
     *
     * Zwei Aenderungen im selben Augenblick koennen sich zu einem
     * einzigen Schritt zusammenlegen. Das ist in Ordnung: gebraucht wird
     * nur, DASS die Nummer sich von der geladenen unterscheidet, nicht
     * um wie viel.
     */
    public function invalidate(): void
    {
        $this->app->settings->set(self::BUILD_KEY, $this->build() + 1);
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
     * Die Reihenfolge bestimmt NICHT das Plugin: ein z aus dem Haken
     * wird uebergangen. Sie steht in den Einstellungen und wird auf
     * der Overlay-Seite mit Pfeilen getauscht.
     *
     * @return array<string, array{label: string, position: string, width: string, height: string, z: int, vars: array<string, string>}>
     */
    public static function slots(App $app): array
    {
        $slots = $app->hooks->filter('overlay.slots', []);

        if (!is_array($slots)) {
            $slots = [];
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
                'vars'     => self::normalizeVars($slot['vars'] ?? []),
            ];
        }

        /*
         * Die Reihenfolge kommt aus den Einstellungen und nicht aus dem
         * Haken. Ein Plugin weiss nicht, was sonst noch im Overlay
         * liegt - es kann also gar nicht entscheiden, ob es vor oder
         * hinter etwas gehoert. Der weiss es, der beides sieht.
         */
        $reihenfolge = self::order($app, array_keys($sauber));
        $anzahl = count($reihenfolge);

        $sortiert = [];

        foreach ($reihenfolge as $stelle => $id) {
            $sortiert[$id] = $sauber[$id] + [
                // Oben ist vorne - wie in OBS. Der erste bekommt also
                // die hoechste Zahl. In Zehnerschritten, damit ein
                // Stylesheet dazwischen noch Platz haette.
                'z' => ($anzahl - $stelle) * 10,
            ];
        }

        return $sortiert;
    }

    /** Wo die Reihenfolge liegt. */
    public const ORDER_SETTING = 'overlay_order';

    /**
     * Alles, was die Flaeche ueber ihre Kaesten wissen muss.
     *
     * Groesse der Buehne, und je Platz Stelle, Groesse, Reihenfolge
     * und die eigenen CSS-Variablen. Genau das steht sonst beim Laden
     * im HTML - hier noch einmal, damit es sich auch waehrend des
     * Streams aendern laesst.
     *
     * @return array{width: int, height: int, slots: array<string, array<string, mixed>>}
     */
    public static function layout(App $app): array
    {
        $slots = [];

        foreach (self::slots($app) as $id => $slot) {
            $slots[$id] = [
                'position' => $slot['position'],
                'width'    => $slot['width'],
                'height'   => $slot['height'],
                'z'        => $slot['z'],
                'vars'     => $slot['vars'],
            ];
        }

        return [
            'width'  => max(320, min(7680, $app->settings->int('overlay_width', 1920))),
            'height' => max(180, min(4320, $app->settings->int('overlay_height', 1080))),
            'slots'  => $slots,
        ];
    }

    /** Ein kurzer Fingerabdruck des Layouts - zum Vergleichen. */
    public static function layoutFingerprint(App $app): string
    {
        return md5((string) json_encode(self::layout($app)));
    }

    /**
     * Die Reihenfolge der Plaetze, von vorne nach hinten.
     *
     * Was gespeichert ist, zaehlt - solange es den Platz noch gibt.
     * Was neu dazukommt, kommt nach VORNE: ein frisch eingerichtetes
     * Plugin soll man sehen. Landete es hinten, suchte man den Fehler
     * beim Plugin, obwohl es nur verdeckt ist - und das faellt
     * schwerer auf als etwas, das im Weg liegt.
     *
     * @param list<string> $vorhanden
     * @return list<string>
     */
    public static function order(App $app, array $vorhanden): array
    {
        return self::orderFrom(
            json_decode($app->settings->string(self::ORDER_SETTING, '[]'), true),
            $vorhanden
        );
    }

    /**
     * Dasselbe ohne Einstellungen: die gespeicherte Liste und die
     * vorhandenen Plaetze hinein, die Reihenfolge heraus.
     *
     * Eigene Funktion, damit die Regel pruefbar ist, ohne eine
     * Datenbank dafuer zu brauchen - und sie ist es wert: was neu ist,
     * was verschwunden ist und was doppelt dasteht, entscheidet sich
     * genau hier.
     *
     * @param list<string> $vorhanden
     * @return list<string>
     */
    public static function orderFrom(mixed $gespeichert, array $vorhanden): array
    {
        if (!is_array($gespeichert)) {
            $gespeichert = [];
        }

        $bekannt = [];

        foreach ($gespeichert as $id) {
            $id = self::normalizeSlot((string) $id);

            if ($id !== '' && in_array($id, $vorhanden, true) && !in_array($id, $bekannt, true)) {
                $bekannt[] = $id;
            }
        }

        $neu = array_values(array_diff($vorhanden, $bekannt));

        return array_merge($neu, $bekannt);
    }

    /**
     * Einen Platz einen Schritt nach vorne oder nach hinten.
     *
     * Getauscht wird mit dem Nachbarn - zwei Pfeile je Zeile, und
     * fertig. Ein Zahlenfeld je Platz waere die andere Moeglichkeit
     * gewesen: dann traegt man Zahlen ein und rechnet im Kopf, wer
     * damit vor wem liegt.
     *
     * @return bool ob sich etwas geaendert hat
     */
    public static function move(App $app, string $id, bool $nachVorne): bool
    {
        $id = self::normalizeSlot($id);
        $reihenfolge = array_keys(self::slots($app));
        $stelle = array_search($id, $reihenfolge, true);

        if ($stelle === false) {
            return false;
        }

        $ziel = $nachVorne ? $stelle - 1 : $stelle + 1;

        // Am Rand gibt es nichts zu tauschen. Das ist kein Fehler -
        // der Pfeil steht dort gar nicht erst.
        if ($ziel < 0 || $ziel >= count($reihenfolge)) {
            return false;
        }

        [$reihenfolge[$stelle], $reihenfolge[$ziel]] = [$reihenfolge[$ziel], $reihenfolge[$stelle]];

        $app->settings->set(self::ORDER_SETTING, json_encode(array_values($reihenfolge)));

        return true;
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
