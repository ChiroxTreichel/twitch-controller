<?php

declare(strict_types=1);

namespace Overlays\Core\Twitch;

use Overlays\Core\App;
use Overlays\Core\Http\Request;

/**
 * EventSub: Abos verwalten und eingehende Webhooks pruefen.
 *
 * Welche Abos gebraucht werden, entscheidet nicht diese Klasse allein.
 * Der Kern abonniert nur, was fuer "Aktivitaeten" noetig ist (Follows,
 * Abos, Bits, Raids, Stream an/aus). Alles weitere melden Plugins per
 * Hook an:
 *
 *   $hooks->on('core.eventsub.subscriptions', function (array $subs, string $broadcasterId) {
 *       $subs[] = ['type' => 'channel.goal.progress', 'version' => '1',
 *                  'condition' => ['broadcaster_user_id' => $broadcasterId]];
 *       return $subs;
 *   });
 *
 * Danach einmal "Abos abgleichen" in den Einstellungen - fehlende werden
 * angelegt, ueberzaehlige mit unserer Callback-URL entfernt.
 */
final class EventSub
{
    public function __construct(private readonly App $app)
    {
    }

    public function callbackUrl(): string
    {
        return $this->app->url('/hooks/twitch');
    }

    // -----------------------------------------------------------------
    //  Eingang
    // -----------------------------------------------------------------

    /**
     * Signatur pruefen. Twitch bildet den HMAC ueber
     * message-id + timestamp + Rohbody.
     */
    public function verify(Request $request, string $secret): bool
    {
        $messageId = $request->header('twitch-eventsub-message-id');
        $timestamp = $request->header('twitch-eventsub-message-timestamp');
        $signature = $request->header('twitch-eventsub-message-signature');

        if ($messageId === '' || $timestamp === '' || $signature === '' || $secret === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $messageId . $timestamp . $request->rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Alte Nachrichten abweisen (Replay-Schutz).
     */
    public function isFresh(Request $request, int $maxAgeSeconds = 600): bool
    {
        $timestamp = strtotime($request->header('twitch-eventsub-message-timestamp'));

        return $timestamp !== false && abs($timestamp - time()) <= $maxAgeSeconds;
    }

    // -----------------------------------------------------------------
    //  Abos
    // -----------------------------------------------------------------

    /**
     * Basisliste des Kerns plus alles, was Plugins ergaenzen.
     *
     * @return list<array{type: string, version: string, condition: array<string, string>}>
     */
    public function desired(): array
    {
        $broadcasterId = $this->app->settings->string('twitch_broadcaster_id');
        if ($broadcasterId === '') {
            return [];
        }

        $channel = ['broadcaster_user_id' => $broadcasterId];

        $base = [
            // Follows brauchen zusaetzlich einen Moderator-Bezug.
            ['type' => 'channel.follow', 'version' => '2', 'condition' => [
                'broadcaster_user_id' => $broadcasterId,
                'moderator_user_id'   => $broadcasterId,
            ]],
            ['type' => 'channel.subscribe',            'version' => '1', 'condition' => $channel],
            ['type' => 'channel.subscription.gift',    'version' => '1', 'condition' => $channel],
            ['type' => 'channel.subscription.message', 'version' => '1', 'condition' => $channel],
            ['type' => 'channel.subscription.end',     'version' => '1', 'condition' => $channel],
            ['type' => 'channel.cheer',                'version' => '1', 'condition' => $channel],
            ['type' => 'channel.raid',                 'version' => '1', 'condition' => [
                'to_broadcaster_user_id' => $broadcasterId,
            ]],
            ['type' => 'stream.online',  'version' => '1', 'condition' => $channel],
            ['type' => 'stream.offline', 'version' => '1', 'condition' => $channel],
        ];

        $all = $this->app->hooks->filter('core.eventsub.subscriptions', $base, $broadcasterId);
        if (!is_array($all)) {
            return $base;
        }

        // Doppelte zusammenfassen: gleicher Typ und gleiche Bedingung.
        $unique = [];
        foreach ($all as $subscription) {
            if (!is_array($subscription) || !isset($subscription['type'])) {
                continue;
            }

            $condition = is_array($subscription['condition'] ?? null) ? $subscription['condition'] : [];
            ksort($condition);

            $key = $subscription['type'] . '|' . (string) ($subscription['version'] ?? '1')
                . '|' . json_encode($condition);

            $unique[$key] = [
                'type'      => (string) $subscription['type'],
                'version'   => (string) ($subscription['version'] ?? '1'),
                'condition' => array_map('strval', $condition),
            ];
        }

        return array_values($unique);
    }

    /**
     * Bei Twitch registrierte Abos.
     *
     * @return list<array<string, mixed>>
     */
    public function existing(): array
    {
        $subscriptions = [];
        $cursor = null;

        do {
            $query = $cursor === null ? [] : ['after' => $cursor];
            $result = $this->app->twitch->api()->get('eventsub/subscriptions', $query);
            if (!$result->ok()) {
                break;
            }

            foreach ((array) ($result->json['data'] ?? []) as $row) {
                if (is_array($row)) {
                    $subscriptions[] = $row;
                }
            }

            $cursor = $result->json['pagination']['cursor'] ?? null;
            $cursor = is_string($cursor) && $cursor !== '' ? $cursor : null;
        } while ($cursor !== null);

        return $subscriptions;
    }

    /**
     * Soll- mit Ist-Stand abgleichen.
     *
     * @return array{created: list<string>, deleted: list<string>, kept: list<string>, failed: array<string, string>}
     */
    public function sync(): array
    {
        $report = ['created' => [], 'deleted' => [], 'kept' => [], 'failed' => []];

        $desired = $this->desired();
        if ($desired === []) {
            return $report;
        }

        $callback = $this->callbackUrl();
        $existing = $this->existing();

        // Twitch signiert jedes Abo mit dem Secret, das beim Anlegen
        // mitgegeben wurde - nachtraeglich aendern geht nicht. Ist das
        // Secret hier ein anderes als beim letzten Abgleich (neu
        // installiert, oder in den Einstellungen geaendert), sind alle
        // vorhandenen Abos unbrauchbar: sie kaemen mit einer Signatur an,
        // die wir nicht pruefen koennen, und wuerden verworfen.
        //
        // Ohne diese Pruefung waere der Fehler besonders unangenehm, weil
        // der Abgleich "alles in Ordnung" meldet und trotzdem nie ein
        // Event ankommt.
        $secret = $this->app->settings->secret('twitch_webhook_secret');
        $fingerprint = $secret === '' ? '' : hash('sha256', $secret);
        $knownFingerprint = $this->app->settings->string('eventsub_secret_fingerprint');

        if ($fingerprint !== '' && $fingerprint !== $knownFingerprint) {
            foreach ($existing as $subscription) {
                $transport = is_array($subscription['transport'] ?? null) ? $subscription['transport'] : [];
                if (((string) ($transport['callback'] ?? '')) !== $callback) {
                    continue;
                }

                if ($this->unsubscribe((string) ($subscription['id'] ?? ''))) {
                    $report['deleted'][] = (string) ($subscription['type'] ?? '?') . ' (Secret war veraltet)';
                }
            }

            // Alles neu anlegen - der Ist-Stand ist jetzt leer.
            $existing = [];
        }

        $existingKeys = [];
        foreach ($existing as $subscription) {
            $transport = is_array($subscription['transport'] ?? null) ? $subscription['transport'] : [];
            $isOurs = ((string) ($transport['callback'] ?? '')) === $callback;
            $status = (string) ($subscription['status'] ?? '');
            $id = (string) ($subscription['id'] ?? '');

            // Fremde Callbacks (andere Installation, alte Domain) nicht
            // anfassen ausser sie zeigen auf uns und sind kaputt.
            if (!$isOurs) {
                continue;
            }

            if ($status !== 'enabled' && $status !== 'webhook_callback_verification_pending') {
                if ($this->unsubscribe($id)) {
                    $report['deleted'][] = (string) $subscription['type'] . ' (' . $status . ')';
                }
                continue;
            }

            $condition = is_array($subscription['condition'] ?? null) ? $subscription['condition'] : [];
            $condition = array_filter(array_map('strval', $condition), static fn (string $v): bool => $v !== '');
            ksort($condition);

            $existingKeys[self::key(
                (string) $subscription['type'],
                (string) ($subscription['version'] ?? '1'),
                $condition
            )] = $id;
        }

        foreach ($desired as $subscription) {
            $condition = $subscription['condition'];
            ksort($condition);
            $key = self::key($subscription['type'], $subscription['version'], $condition);

            if (isset($existingKeys[$key])) {
                $report['kept'][] = $subscription['type'];
                unset($existingKeys[$key]);
                continue;
            }

            $error = $this->subscribe($subscription['type'], $subscription['version'], $condition);
            if ($error === null) {
                $report['created'][] = $subscription['type'];
            } else {
                $report['failed'][$subscription['type']] = $error;
            }
        }

        // Was uebrig bleibt, zeigt auf uns, wird aber nicht mehr
        // gebraucht - typisch nach dem Deaktivieren eines Plugins.
        foreach ($existingKeys as $id) {
            if ($this->unsubscribe($id)) {
                $report['deleted'][] = 'nicht mehr benoetigt';
            }
        }

        // Erst merken, wenn tatsaechlich Abos mit diesem Secret stehen -
        // sonst wuerde ein fehlgeschlagener Abgleich das Aufraeumen beim
        // naechsten Versuch verhindern.
        if ($fingerprint !== '' && $report['failed'] === []) {
            $this->app->settings->set('eventsub_secret_fingerprint', $fingerprint);
        }

        return $report;
    }

    /**
     * @param array<string, string> $condition
     * @return string|null Fehlermeldung oder null bei Erfolg
     */
    public function subscribe(string $type, string $version, array $condition): ?string
    {
        $secret = $this->app->settings->secret('twitch_webhook_secret');
        if ($secret === '') {
            return 'Kein Webhook-Secret gesetzt.';
        }

        $result = $this->app->twitch->api()->post('eventsub/subscriptions', [], [
            'type'      => $type,
            'version'   => $version,
            'condition' => $condition,
            'transport' => [
                'method'   => 'webhook',
                'callback' => $this->callbackUrl(),
                'secret'   => $secret,
            ],
        ]);

        return $result->ok() ? null : $result->error();
    }

    public function unsubscribe(string $id): bool
    {
        return $this->app->twitch->api()->delete('eventsub/subscriptions', ['id' => $id])->ok();
    }

    /**
     * Uebersetzt eine Twitch-Ablehnung in eine Ursache samt Loesungsweg.
     * Twitch antwortet knapp und technisch; ohne Uebersetzung sucht man
     * an der falschen Stelle.
     *
     * "neu_verbinden" sagt der Oberflaeche, ob sie einen Knopf zum
     * Neuverbinden anbieten soll. Bewusst ein Kennzeichen und keine
     * Textsuche im Loesungssatz - der ist uebersetzbar und wuerde beim
     * Sprachwechsel stillschweigend nicht mehr passen.
     *
     * @return array{ursache: string, loesung: string, neu_verbinden: bool}
     */
    public static function explain(string $message): array
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'missing proper authorization')
            || str_contains($lower, 'missing scope')
        ) {
            return [
                'ursache' => translate('twitch.eventsub.no_authorization'),
                'loesung' => translate('twitch.eventsub.no_authorization_fix'),
                'neu_verbinden' => true,
            ];
        }

        if (str_contains($lower, 'challenge')
            || str_contains($lower, 'webhook callback verification failed')
            || str_contains($lower, '10 seconds')
        ) {
            return [
                'ursache' => translate('twitch.eventsub.callback_failed'),
                'loesung' => translate('twitch.eventsub.callback_failed_fix'),
                'neu_verbinden' => false,
            ];
        }

        if (str_contains($lower, 'subscription already exists')) {
            return [
                'ursache' => translate('twitch.eventsub.already_exists'),
                'loesung' => translate('twitch.eventsub.already_exists_fix'),
                'neu_verbinden' => false,
            ];
        }

        if (str_contains($lower, 'exceeds the number of subscriptions')
            || str_contains($lower, 'too many requests')
        ) {
            return [
                'ursache' => translate('twitch.eventsub.limit'),
                'loesung' => translate('twitch.eventsub.limit_fix'),
                'neu_verbinden' => false,
            ];
        }

        if (str_contains($lower, 'must use https') || str_contains($lower, 'https')) {
            return [
                'ursache' => translate('twitch.eventsub.https'),
                'loesung' => translate('twitch.eventsub.https_fix'),
                'neu_verbinden' => false,
            ];
        }

        return [
            'ursache' => $message,
            'loesung' => '',
            'neu_verbinden' => false,
        ];
    }

    /**
     * @param array<string, string> $condition
     */
    private static function key(string $type, string $version, array $condition): string
    {
        return $type . '|' . $version . '|' . (string) json_encode($condition);
    }
}
