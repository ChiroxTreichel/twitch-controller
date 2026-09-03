<?php

declare(strict_types=1);

namespace TwitchController\Core\Chat;

use DateTimeImmutable;
use Throwable;
use TwitchController\Core\App;
use TwitchController\Core\Twitch\TokenStore;

/**
 * ===================================================================
 *  Der Chat: mitlesen, schreiben, loeschen
 * ===================================================================
 *
 * Bis hierher lief Chat nur ueber IRC. Das hiess: eine dauerhaft
 * offene Verbindung, die abreissen kann, und ein Benutzername samt
 * Token in einer Datei. Beides ist weg.
 *
 * Stattdessen drei gewoehnliche Wege, die der Kern schon hat:
 *
 *   mitlesen    EventSub-Abo channel.chat.message, per Webhook auf
 *               dieselbe Adresse wie Follows und Subs
 *   schreiben   POST helix/chat/messages
 *   loeschen    DELETE helix/moderation/chat
 *
 * Kein Daemon, keine Verbindung, kein Passwort irgendwo.
 *
 * Warum eine eigene Tabelle und nicht 'events':
 *
 * Chat ist um Groessenordnungen mehr als Follows und Subs. Ein
 * lebhafter Stream schreibt tausende Zeilen pro Stunde. Landeten die
 * in 'events', waere der Aktivitaeten-Feed unbrauchbar und die Tabelle,
 * die den Verlauf des Kanals haelt, waechst ohne Grenze. chat_messages
 * hat darum eine eigene Aufbewahrungsfrist und wird von sich aus
 * wieder leer.
 *
 * Benutzung aus einem Plugin:
 *
 *   use TwitchController\Core\Chat\Chat;
 *
 *   $chat = new Chat($app);
 *   $chat->send('Moin!');
 *   $chat->delete($messageId);
 *
 *   $hooks->on('core.chat.message', function (array $message) {
 *       if (str_starts_with($message['text'], '!wuerfel')) { … }
 *   });
 */
final class Chat
{
    /**
     * Diese Abo-Typen gehoeren hierher und nicht in 'events'.
     *
     * Der Webhook-Eingang fragt danach, bevor er etwas speichert -
     * siehe Twitch\WebhookController::store().
     */
    public const TYPES = [
        'channel.chat.message',
        'channel.chat.message_delete',
        'channel.chat.clear',
    ];

    /**
     * Wie lange Zeilen liegen bleiben, falls kein Plugin etwas anderes
     * sagt.
     *
     * Ein Tag: lang genug, um nach einem Stream nachzusehen, wer was
     * geschrieben hat, und kurz genug, dass die Tabelle nicht zum
     * Archiv wird. Loeschen bei Twitch geht ohnehin nur sechs Stunden
     * rueckwaerts.
     *
     * Ein Plugin, das mehr braucht, hebt es an:
     *
     *   $hooks->on('core.chat.keep_hours', fn () => 72);
     */
    private const KEEP_HOURS = 24;

    /** Aufraeumen nicht bei jeder Zeile, sondern etwa jede 200. */
    private const CLEAN_EVERY = 200;

    public function __construct(private readonly App $app)
    {
    }

    // -----------------------------------------------------------------
    //  Mitlesen
    // -----------------------------------------------------------------

    /**
     * Eine Nachricht aus dem Webhook ablegen.
     *
     * @param array<string, mixed> $event Nutzlast von channel.chat.message
     * @return int Nummer der Zeile, 0 wenn schon bekannt oder unbrauchbar
     */
    public function store(array $event, string $occurredAt): int
    {
        $messageId = trim((string) ($event['message_id'] ?? ''));
        if ($messageId === '') {
            return 0;
        }

        $message = is_array($event['message'] ?? null) ? $event['message'] : [];
        $fragments = is_array($message['fragments'] ?? null) ? $message['fragments'] : [];
        $badges = is_array($event['badges'] ?? null) ? $event['badges'] : [];
        $reply = is_array($event['reply'] ?? null) ? $event['reply'] : [];
        $cheer = is_array($event['cheer'] ?? null) ? $event['cheer'] : [];

        $werte = [
            'message_id'    => $messageId,
            'chatter_id'    => (string) ($event['chatter_user_id'] ?? ''),
            'chatter_login' => (string) ($event['chatter_user_login'] ?? ''),
            'chatter_name'  => (string) ($event['chatter_user_name'] ?? ''),
            'color'         => (string) ($event['color'] ?? ''),
            'text'          => (string) ($message['text'] ?? ''),
            'fragments'     => self::json($fragments),
            'badges'        => self::json($badges),
            'message_type'  => (string) ($event['message_type'] ?? 'text'),
            'bits'          => (int) ($cheer['bits'] ?? 0),
            'reply_to'      => ((string) ($reply['parent_message_id'] ?? '')) ?: null,
            'sent_at'       => $occurredAt,
        ];

        // ON CONFLICT DO NOTHING ist hier kein Beiwerk: Twitch schickt
        // eine Nachricht erneut, wenn unsere Antwort nicht ankam oder
        // kein 2xx war. Ohne den Riegel stuende dieselbe Zeile dann
        // zweimal im Chatverlauf.
        $id = (int) $this->app->db->value(
            'INSERT INTO chat_messages
                    (message_id, chatter_id, chatter_login, chatter_name, color,
                     text, fragments, badges, message_type, bits, reply_to, sent_at)
             VALUES (:message_id, :chatter_id, :chatter_login, :chatter_name, :color,
                     :text, CAST(:fragments AS JSONB), CAST(:badges AS JSONB),
                     :message_type, :bits, :reply_to, :sent_at)
             ON CONFLICT (message_id) DO NOTHING
             RETURNING id',
            $werte
        );

        // Kein RETURNING-Wert heisst: die Zeile gab es schon. Dann auch
        // keinen Hook - sonst reagiert ein Plugin zweimal auf dieselbe
        // Nachricht, und aus einem !wuerfel werden zwei Wuerfe.
        if ($id <= 0) {
            return 0;
        }

        // Der Hook bekommt die Zeile aus den Werten, die schon hier
        // liegen - nicht aus einem SELECT hinterher. Das ist der
        // haeufigste Weg im ganzen Kern: bei tausend Zeilen pro Stunde
        // waere jede zweite Abfrage eine, die nichts Neues erfaehrt,
        // und Twitch wartet auf unsere Antwort.
        $this->app->hooks->dispatch(
            'core.chat.message',
            self::shape($werte + ['id' => $id, 'deleted_at' => null])
        );

        if ($id % self::CLEAN_EVERY === 0) {
            $this->clean();
        }

        return $id;
    }

    /**
     * Eine Nachricht als geloescht kennzeichnen.
     *
     * Die Zeile bleibt stehen. Wer den Chat ueberwacht, will sehen, dass
     * etwas geloescht wurde und was darin stand - genau das ist der
     * Grund, warum man hinsieht. Nur eben erkennbar als entfernt.
     */
    public function markDeleted(string $messageId): void
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return;
        }

        $this->app->db->run(
            'UPDATE chat_messages
                SET deleted_at = now()
              WHERE message_id = :message_id
                AND deleted_at IS NULL',
            ['message_id' => $messageId]
        );

        $this->app->hooks->dispatch('core.chat.deleted', $messageId);
    }

    /**
     * Der ganze Chat wurde geleert (von einem Moderator oder Twitch).
     *
     * Absichtlich ohne UPDATE auf die Tabelle: "geleert" heisst, dass
     * die Zuschauer den Verlauf nicht mehr sehen - nicht, dass die
     * Nachrichten nie geschrieben wurden. Wuerden hier alle Zeilen als
     * geloescht vermerkt, waere eine Nachricht von vor zwanzig Stunden
     * hinterher als entfernt ausgewiesen, und das Protokoll, um dessen
     * willen man hinsieht, waere weg. Ein Moderator kann Aufraeumen
     * nicht zum Vergessen machen.
     *
     * Der Hook sagt einer offenen Ansicht, dass hier ein Trennstrich
     * hingehoert.
     */
    public function markCleared(): void
    {
        $this->app->hooks->dispatch('core.chat.cleared', $this->latestId());
    }

    /**
     * Alles, was nach dieser Nummer kam - zum Nachziehen einer
     * offenen Ansicht.
     *
     * @return list<array<string, mixed>>
     */
    public function since(int $lastId, int $limit = 100): array
    {
        $rows = $this->app->db->all(
            'SELECT * FROM chat_messages
              WHERE id > :last
              ORDER BY id
              LIMIT ' . self::limit($limit),
            ['last' => max(0, $lastId)]
        );

        return array_map(self::shape(...), $rows);
    }

    /**
     * Die letzten Zeilen, aelteste zuerst - fuer den ersten Aufbau
     * einer Ansicht.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 100): array
    {
        $rows = $this->app->db->all(
            'SELECT * FROM (
                 SELECT * FROM chat_messages ORDER BY id DESC LIMIT ' . self::limit($limit) . '
             ) letzte
             ORDER BY id'
        );

        return array_map(self::shape(...), $rows);
    }

    /** Hoechste vergebene Nummer. */
    public function latestId(): int
    {
        return (int) $this->app->db->value('SELECT COALESCE(MAX(id), 0) FROM chat_messages');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function row(int $id): ?array
    {
        $row = $this->app->db->first('SELECT * FROM chat_messages WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::shape($row);
    }

    public function clean(): void
    {
        $stunden = $this->app->hooks->filter('core.chat.keep_hours', self::KEEP_HOURS);
        $stunden = is_numeric($stunden) ? max(1, (int) $stunden) : self::KEEP_HOURS;

        // Schreibweise wie im uebrigen Kern: ein gebundener Parameter
        // kann in Postgres nicht direkt hinter INTERVAL stehen.
        $this->app->db->run(
            'DELETE FROM chat_messages
              WHERE received_at < now() - (:keep || \' hours\')::interval',
            ['keep' => (string) $stunden]
        );
    }

    // -----------------------------------------------------------------
    //  Schreiben
    // -----------------------------------------------------------------

    /**
     * Eine Nachricht in den Chat schreiben.
     *
     * Wer schreibt: ist ein Bot-Konto verbunden, dann der Bot - sonst
     * der Kanalinhaber selbst. Ein eigenes Konto ist also moeglich,
     * aber nichts, was man erst einrichten muss.
     *
     * @param string $replyTo Nummer der Nachricht, auf die geantwortet
     *                        wird (die von Twitch, nicht unsere)
     * @return array{ok: bool, error: string, id: string}
     */
    public function send(string $text, string $replyTo = ''): array
    {
        $text = trim($text);
        if ($text === '') {
            return ['ok' => false, 'error' => translate('chat.error.empty'), 'id' => ''];
        }

        // Twitch nimmt 500 Zeichen. Laenger wird abgeschnitten, nicht
        // abgelehnt: eine zu lange Zeile ist ein Versehen, und eine
        // Fehlermeldung waere hier weniger hilfreich als die Nachricht.
        if (self::length($text) > 500) {
            $text = self::cut($text, 500);
        }

        $broadcasterId = $this->app->settings->string('twitch_broadcaster_id');
        if ($broadcasterId === '') {
            return ['ok' => false, 'error' => translate('chat.error.no_channel'), 'id' => ''];
        }

        $purpose = $this->senderPurpose();
        $senderId = $this->senderId($purpose);
        if ($senderId === '') {
            return ['ok' => false, 'error' => translate('chat.error.no_sender'), 'id' => ''];
        }

        $body = [
            'broadcaster_id' => $broadcasterId,
            'sender_id'      => $senderId,
            'message'        => $text,
        ];

        if (trim($replyTo) !== '') {
            $body['reply_parent_message_id'] = trim($replyTo);
        }

        try {
            $result = $this->app->twitch->api()->as($purpose)->post('chat/messages', [], $body);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage(), 'id' => ''];
        }

        if (!$result->ok()) {
            return ['ok' => false, 'error' => self::explain($result->status, $result->error()), 'id' => ''];
        }

        // Twitch antwortet mit einer Liste, in der genau ein Eintrag
        // steht. Darin kann is_sent false sein - dann wurde die
        // Nachricht angenommen und trotzdem nicht gesendet, etwa weil
        // AutoMod sie zurueckhaelt. Das ist kein Fehler der Anfrage und
        // faellt ohne diese Pruefung niemandem auf.
        $daten = is_array($result->json['data'] ?? null) ? $result->json['data'] : [];
        $erste = is_array($daten[0] ?? null) ? $daten[0] : [];

        if (($erste['is_sent'] ?? true) === false) {
            $grund = is_array($erste['drop_reason'] ?? null) ? $erste['drop_reason'] : [];

            return [
                'ok'    => false,
                'error' => translate('chat.error.dropped', [
                    'reason' => (string) ($grund['message'] ?? $grund['code'] ?? '?'),
                ]),
                'id'    => '',
            ];
        }

        return ['ok' => true, 'error' => '', 'id' => (string) ($erste['message_id'] ?? '')];
    }

    // -----------------------------------------------------------------
    //  Loeschen
    // -----------------------------------------------------------------

    /**
     * Eine Nachricht aus dem Chat entfernen.
     *
     * Twitch setzt hier zwei Grenzen, die man kennen muss, weil sie
     * sonst als unerklaerlicher Fehlschlag ankommen:
     *
     *   - die Nachricht darf nicht aelter als sechs Stunden sein
     *   - die eigenen Nachrichten des Kanalinhabers lassen sich nicht
     *     loeschen
     *
     * Geloescht wird mit dem Token des Kanalinhabers: er ist in seinem
     * eigenen Kanal immer Moderator, ein Bot-Konto dagegen nur, wenn
     * man es dazu gemacht hat.
     *
     * @param string $messageId Nummer von Twitch, nicht unsere
     * @return array{ok: bool, error: string}
     */
    public function delete(string $messageId): array
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            return ['ok' => false, 'error' => translate('chat.error.no_message')];
        }

        $broadcasterId = $this->app->settings->string('twitch_broadcaster_id');
        if ($broadcasterId === '') {
            return ['ok' => false, 'error' => translate('chat.error.no_channel')];
        }

        try {
            $result = $this->app->twitch->api()->as(TokenStore::BROADCASTER)->delete('moderation/chat', [
                'broadcaster_id' => $broadcasterId,
                'moderator_id'   => $broadcasterId,
                'message_id'     => $messageId,
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        if (!$result->ok()) {
            return ['ok' => false, 'error' => self::explain($result->status, $result->error())];
        }

        // Twitch schickt zusaetzlich channel.chat.message_delete. Der
        // Vermerk wird hier aber sofort gesetzt und nicht erst, wenn
        // der Webhook eintrifft: sonst sieht die Ansicht ein paar
        // hundert Millisekunden lang eine Nachricht, die es nicht mehr
        // gibt. Ein doppeltes Kennzeichnen ist unschaedlich, markDeleted
        // fasst nur an, was noch nicht vermerkt ist.
        $this->markDeleted($messageId);

        return ['ok' => true, 'error' => ''];
    }

    // -----------------------------------------------------------------
    //  Wer schreibt
    // -----------------------------------------------------------------

    /**
     * Bot-Konto, wenn eines verbunden ist - sonst der Kanalinhaber.
     */
    public function senderPurpose(): string
    {
        return $this->app->twitch->tokens()->has(TokenStore::BOT)
            ? TokenStore::BOT
            : TokenStore::BROADCASTER;
    }

    /**
     * Wer als Absender bei Twitch steht.
     */
    public function senderId(string $purpose): string
    {
        $info = $this->app->twitch->tokens()->info($purpose);
        $id = (string) ($info['twitch_id'] ?? '');

        if ($id !== '') {
            return $id;
        }

        // Der Kanalinhaber steht auch in den Einstellungen. Fuer den
        // Bot gibt es diesen Rueckweg nicht.
        return $purpose === TokenStore::BROADCASTER
            ? $this->app->settings->string('twitch_broadcaster_id')
            : '';
    }

    // -----------------------------------------------------------------
    //  Kleinteile
    // -----------------------------------------------------------------

    /**
     * Uebersetzt eine Ablehnung von Twitch in einen Satz, mit dem man
     * etwas anfangen kann.
     *
     * Twitch antwortet knapp: "message must be less than 6 hours old"
     * oder nur 403 ohne Text. Ohne Uebersetzung sucht man den Fehler
     * bei sich.
     */
    private static function explain(int $status, string $error): string
    {
        $klein = strtolower($error);

        if (str_contains($klein, '6 hours') || str_contains($klein, 'six hours')) {
            return translate('chat.error.too_old');
        }
        if (str_contains($klein, 'broadcaster')) {
            return translate('chat.error.own_message');
        }
        if ($status === 401) {
            return translate('chat.error.unauthorized');
        }
        if ($status === 403) {
            return translate('chat.error.forbidden');
        }
        if ($status === 404) {
            return translate('chat.error.gone');
        }
        if ($status === 429) {
            return translate('chat.error.rate_limit');
        }

        return $error !== '' ? $error : translate('chat.error.unknown', ['status' => (string) $status]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function shape(array $row): array
    {
        $fragments = json_decode((string) ($row['fragments'] ?? '[]'), true);
        $badges = json_decode((string) ($row['badges'] ?? '[]'), true);

        return [
            'id'            => (int) ($row['id'] ?? 0),
            'message_id'    => (string) ($row['message_id'] ?? ''),
            'chatter_id'    => (string) ($row['chatter_id'] ?? ''),
            'chatter_login' => (string) ($row['chatter_login'] ?? ''),
            'chatter_name'  => (string) ($row['chatter_name'] ?? ''),
            'color'         => (string) ($row['color'] ?? ''),
            'text'          => (string) ($row['text'] ?? ''),
            'fragments'     => is_array($fragments) ? $fragments : [],
            'badges'        => is_array($badges) ? $badges : [],
            'message_type'  => (string) ($row['message_type'] ?? 'text'),
            'bits'          => (int) ($row['bits'] ?? 0),
            'reply_to'      => ((string) ($row['reply_to'] ?? '')) ?: null,
            'deleted'       => ($row['deleted_at'] ?? null) !== null,
            'sent_at'       => (string) ($row['sent_at'] ?? ''),
        ];
    }

    /** @param array<int|string, mixed> $value */
    private static function json(array $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Grenze fuer LIMIT. Direkt in den SQL-Text, weil Postgres dort
     * keinen gebundenen Parameter nimmt - deshalb ueber int gefiltert
     * und nicht aus der Anfrage uebernommen.
     */
    private static function limit(int $limit): string
    {
        return (string) max(1, min(500, $limit));
    }

    private static function length(string $text): int
    {
        return function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
    }

    private static function cut(string $text, int $length): string
    {
        return function_exists('mb_substr')
            ? mb_substr($text, 0, $length)
            : substr($text, 0, $length);
    }

    /**
     * Zeitstempel von Twitch in die Schreibweise der Datenbank.
     */
    public static function timestamp(string $raw): string
    {
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d H:i:sP');
        } catch (Throwable) {
            return date('Y-m-d H:i:sP');
        }
    }
}
