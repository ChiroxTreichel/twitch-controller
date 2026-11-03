<?php

declare(strict_types=1);

namespace TwitchController\Core\Events;

use TwitchController\Core\App;

/**
 * ===================================================================
 *  Ein Abo, zwei Meldungen
 * ===================================================================
 *
 * Laeuft ein Abo aus und wird neu abgeschlossen - bei Prime jeden
 * Monat, weil es sich nicht selbst verlaengert -, ist das fuer Twitch
 * ein NEUES Abo: channel.subscribe. Teilt der Zuschauer dabei seine
 * Verlaengerung im Chat ("seit 66 Monaten"), kommt in derselben
 * Sekunde channel.subscription.message dazu.
 *
 * Fuer uns waren das zwei Abos: zwei Alerts, zwei Zeilen im Feed, und
 * der Subathon buchte die Zeit zweimal.
 *
 * Die Regel hier: ein subscribe (nicht geschenkt) und eine
 * subscription.message desselben Zuschauers innerhalb von
 * WINDOW_SECONDS sind DASSELBE Abo. Eines davon wird als Doppel
 * markiert (suppressed_at) - gespeichert bleibt es, an die Plugins
 * geht es nicht, und der Feed blendet es aus.
 *
 * Welches gewinnt: lieber die Nachricht, sie kennt die Monate. Darum
 * wartet ein neues Abo HOLD_SECONDS (held_until), bevor es an die
 * Plugins geht. Kommt in der Zeit die Nachricht, wird das Abo zum
 * Doppel. Kommt sie spaeter, ist das Abo schon raus - dann wird die
 * Nachricht zum Doppel. Kam die Nachricht zuerst, wird das Abo zum
 * Doppel.
 *
 * Wer entscheidet, ob das wartende Abo noch zu haben ist, entscheidet
 * EINE Anweisung: UPDATE ... WHERE held_until IS NOT NULL. Freigabe
 * und Abfangen schliessen sich damit gegenseitig aus, auch wenn der
 * Worker und ein Webhook im selben Augenblick zugreifen.
 *
 * Freigegeben wird bei jedem eingehenden Webhook - im Stream kommen
 * sie im Sekundentakt, Chat eingeschlossen - und im Takt des Workers,
 * falls gerade nichts los ist.
 */
final class Subscriptions
{
    /** So lange wartet ein neues Abo auf seine Verlaengerungsnachricht. */
    public const HOLD_SECONDS = 5;

    /** So weit auseinander gelten beide Meldungen als dasselbe Abo. */
    public const WINDOW_SECONDS = 300;

    public const SUBSCRIBE = 'twitch.channel.subscribe';
    public const MESSAGE = 'twitch.channel.subscription.message';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Soll ein Ereignis beim Speichern warten? Nur ein neues Abo, das
     * nicht geschenkt ist - Geschenke kommen ohnehin als eigenes
     * Ereignis (subscription.gift), und eine Verlaengerungsnachricht
     * gibt es dazu nicht.
     *
     * @param array<string, mixed> $payload
     */
    public static function holdFor(string $source, string $eventType, array $payload): int
    {
        if ($source !== 'twitch' || $eventType !== self::SUBSCRIBE || self::isGift($payload)) {
            return 0;
        }

        return self::HOLD_SECONDS;
    }

    /**
     * Nach dem Speichern: an die Plugins damit, oder nicht?
     *
     * @param array<string, mixed> $row frisch gespeicherte Zeile
     */
    public function shouldDispatch(array $row): bool
    {
        $art = (string) ($row['event_type'] ?? '');
        $wer = trim((string) ($row['actor_external_id'] ?? ''));
        $id = (int) ($row['id'] ?? 0);

        if (($row['source'] ?? '') !== 'twitch' || $wer === '' || $id <= 0) {
            return true;
        }

        if ($art === self::SUBSCRIBE) {
            $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];

            if (self::isGift($payload)) {
                return true;
            }

            // Kam die Nachricht schon? Dann ist dieses Abo das Doppel.
            if ($this->counterpart($id, $wer, self::MESSAGE) !== null) {
                $this->suppress($id);
            }

            // Sonst wartet es (held_until steht seit dem Speichern) -
            // in keinem Fall geht es jetzt schon hinaus.
            return false;
        }

        if ($art === self::MESSAGE) {
            $abo = $this->counterpart($id, $wer, self::SUBSCRIBE);

            if ($abo === null) {
                return true;
            }

            // Wartet das Abo noch? Dann gewinnt die Nachricht.
            $abgefangen = $this->app->db->first(
                'UPDATE events SET suppressed_at = now(), held_until = NULL
                  WHERE id = :id AND held_until IS NOT NULL AND suppressed_at IS NULL
                  RETURNING id',
                ['id' => $abo]
            );

            if ($abgefangen !== null) {
                return true;
            }

            // Das Abo ist schon raus - Alert gelaufen, Zeit gebucht.
            $this->suppress($id);

            return false;
        }

        return true;
    }

    /**
     * Wartende Abos, deren Zeit um ist, freigeben.
     *
     * @return list<int> die freigegebenen Ereignisse - der Aufrufer
     *                   schickt sie an die Plugins
     */
    public function releaseDue(): array
    {
        $faellig = $this->app->db->all(
            'SELECT id FROM events
              WHERE held_until IS NOT NULL AND held_until <= now() AND suppressed_at IS NULL
              ORDER BY id'
        );

        $frei = [];

        foreach ($faellig as $zeile) {
            $meins = $this->app->db->first(
                'UPDATE events SET held_until = NULL
                  WHERE id = :id AND held_until IS NOT NULL AND suppressed_at IS NULL
                  RETURNING id',
                ['id' => (int) $zeile['id']]
            );

            if ($meins !== null) {
                $frei[] = (int) $meins['id'];
            }
        }

        return $frei;
    }

    /**
     * Die Gegenmeldung desselben Zuschauers - die juengste, die noch
     * kein Doppel ist.
     */
    private function counterpart(int $id, string $wer, string $art): ?int
    {
        $treffer = $this->app->db->value(
            'SELECT id FROM events
              WHERE source = \'twitch\'
                AND event_type = :art
                AND actor_external_id = :wer
                AND id <> :id
                AND suppressed_at IS NULL
                AND received_at >= now() - (:fenster || \' seconds\')::interval
                AND COALESCE(payload->>\'is_gift\', \'false\') <> \'true\'
              ORDER BY id DESC
              LIMIT 1',
            ['art' => $art, 'wer' => $wer, 'id' => $id, 'fenster' => (string) self::WINDOW_SECONDS]
        );

        return $treffer === null ? null : (int) $treffer;
    }

    private function suppress(int $id): void
    {
        $this->app->db->run(
            'UPDATE events SET suppressed_at = now(), held_until = NULL WHERE id = :id',
            ['id' => $id]
        );
    }

    /** @param array<string, mixed> $payload */
    private static function isGift(array $payload): bool
    {
        $wert = $payload['is_gift'] ?? $payload['isGift'] ?? false;

        return $wert === true || $wert === 'true' || $wert === 1 || $wert === '1';
    }
}
