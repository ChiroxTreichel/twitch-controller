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
     * @return array{ursache: string, loesung: string}
     */
    public static function explain(string $message): array
    {
        $lower = strtolower($message);

        if (str_contains($lower, 'missing proper authorization')
            || str_contains($lower, 'missing scope')
        ) {
            return [
                'ursache' => 'Der Kanal hat dieser Twitch-App die nötige Berechtigung nicht erteilt.',
                'loesung' => 'Kanal einmal neu verbinden - dabei fragt Twitch die fehlende '
                    . 'Berechtigung mit ab.',
            ];
        }

        if (str_contains($lower, 'challenge')
            || str_contains($lower, 'webhook callback verification failed')
            || str_contains($lower, '10 seconds')
        ) {
            return [
                'ursache' => 'Twitch konnte die Adresse nicht bestätigen. Sie wird beim Anlegen '
                    . 'sofort aufgerufen und muss von außen über HTTPS erreichbar sein.',
                'loesung' => 'Adresse einmal von einem anderen Netz aus öffnen, z.B. vom Handy '
                    . 'im Mobilfunknetz. Klappt es dort nicht, klappt es auch für Twitch nicht.',
            ];
        }

        if (str_contains($lower, 'subscription already exists')) {
            return [
                'ursache' => 'Dieses Abo besteht bereits.',
                'loesung' => 'Nichts zu tun - beim nächsten Abgleich verschwindet die Meldung.',
            ];
        }

        if (str_contains($lower, 'exceeds the number of subscriptions')
            || str_contains($lower, 'too many requests')
        ) {
            return [
                'ursache' => 'Twitch hat ein Mengenlimit erreicht.',
                'loesung' => 'Ein paar Minuten warten und erneut abgleichen.',
            ];
        }

        if (str_contains($lower, 'must use https') || str_contains($lower, 'https')) {
            return [
                'ursache' => 'Twitch akzeptiert nur HTTPS-Adressen.',
                'loesung' => 'APP_URL in der .env muss mit https:// beginnen.',
            ];
        }

        return [
            'ursache' => $message,
            'loesung' => '',
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
