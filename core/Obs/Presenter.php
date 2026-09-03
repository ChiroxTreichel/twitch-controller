<?php

declare(strict_types=1);

namespace Overlays\Core\Obs;

use Overlays\Core\App;

/**
 * Macht aus einer Zeile der events-Tabelle das, was im Feed steht:
 * Badge-Text, Badge-Farbe, Name, Nachricht, Filterschluessel.
 *
 * Plugins mit eigenen Ereignisarten (PayPal, Throne, Kanalpunkte,
 * Unfollows) haengen sich an 'core.obs.present':
 *
 *   $hooks->on('core.obs.present', function (?array $view, array $row, array $payload) {
 *       if (($row['event_type'] ?? '') !== 'paypal.send_money') {
 *           return $view;
 *       }
 *       return [
 *           'badge'  => 'EUR 5,00',
 *           'style'  => 'paypal',
 *           'title'  => $row['actor_name'] ?? 'Anonym',
 *           'filter' => 'paypal.named',
 *       ];
 *   });
 *
 * Gibt der Hook null zurueck, erscheint das Ereignis nicht im Feed -
 * so lassen sich Zwischenmeldungen ausblenden.
 */
final class Presenter
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, time: string, badge: string, style: string, title: string, message: string, filter: string}|null
     */
    public function present(array $row): ?array
    {
        $payload = is_array($row['payload'] ?? null) ? $row['payload'] : [];
        $eventType = trim((string) ($row['event_type'] ?? ''));

        $view = $this->core($eventType, $row, $payload);

        $filtered = $this->app->hooks->filter('core.obs.present', $view, $row, $payload);
        if ($filtered !== null && !is_array($filtered)) {
            $filtered = $view;
        }

        if ($filtered === null) {
            return null;
        }

        return [
            'id'      => (int) ($row['id'] ?? 0),
            'time'    => self::time((string) ($row['occurred_at'] ?? '')),
            'badge'   => trim((string) ($filtered['badge'] ?? $eventType)),
            'style'   => (string) ($filtered['style'] ?? 'system'),
            'title'   => trim((string) ($filtered['title'] ?? self::actor($row, $payload))),
            'message' => self::message($row),
            'filter'  => (string) ($filtered['filter'] ?? 'system'),
        ];
    }

    /**
     * Die Ereignisarten, die der Kern selbst kennt. Alles andere landet
     * beim System-Badge und kann von einem Plugin uebernommen werden.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     * @return array{badge: string, style: string, title: string, filter: string}|null
     */
    private function core(string $eventType, array $row, array $payload): ?array
    {
        $actor = self::actor($row, $payload);
        $amount = (int) round((float) ($row['amount'] ?? 0));
        $suffix = Payload::tierSuffix($row, $payload);

        switch ($eventType) {
            case 'twitch.channel.follow':
                return ['badge' => 'Follow', 'style' => 'follow', 'title' => $actor, 'filter' => 'follows.new'];

            case 'twitch.channel.cheer':
                $anonymous = Payload::bool($payload, ['is_anonymous', 'anonymous']);

                return [
                    'badge'  => max(1, $amount) . ' Bits',
                    'style'  => 'bits',
                    'title'  => $anonymous ? 'Anonymer Cheer' : $actor,
                    'filter' => 'bits',
                ];

            case 'twitch.channel.subscribe':
                if (Payload::bool($payload, ['is_gift'])) {
                    return [
                        'badge'  => 'Gift erhalten' . $suffix,
                        'style'  => 'gift_received',
                        'title'  => $actor,
                        'filter' => 'subs.gifted.received',
                    ];
                }

                if (Payload::isPrime($row, $payload)) {
                    return ['badge' => 'Sub' . $suffix, 'style' => 'prime', 'title' => $actor, 'filter' => 'subs.prime'];
                }

                return [
                    'badge'  => 'Sub' . $suffix,
                    'style'  => 'sub',
                    'title'  => $actor,
                    'filter' => 'subs.tiered.' . Payload::tierSlug($payload),
                ];

            case 'twitch.channel.subscription.message':
                $badge = 'Resub' . $suffix;

                // Streak schlaegt Gesamtmonate: "12x" heisst hier
                // "12 Monate in Folge", falls Twitch das mitliefert.
                $streak = Payload::number($payload, ['streak_months', 'streakMonths', 'duration_months']);
                $months = Payload::number($payload, ['cumulative_months', 'cumulativeMonths']);
                if ($streak > 1) {
                    $badge .= ' ' . $streak . 'x';
                } elseif ($months > 1) {
                    $badge .= ' ' . $months . 'x';
                }

                return [
                    'badge'  => $badge,
                    'style'  => Payload::isPrime($row, $payload) ? 'prime' : 'resub',
                    'title'  => $actor,
                    'filter' => Payload::isPrime($row, $payload)
                        ? 'subs.prime'
                        : 'subs.tiered.' . Payload::tierSlug($payload),
                ];

            case 'twitch.channel.subscription.gift':
                $anonymous = $actor === self::UNKNOWN
                    || Payload::bool($payload, ['is_anonymous', 'anonymous']);
                $receiver = Payload::string($payload, [
                    'recipient_user_name', 'recipient_name', 'recipient_display_name', 'recipient_login',
                ]);

                return [
                    'badge'  => max(1, $amount) . 'x Gift' . $suffix,
                    'style'  => $anonymous ? 'gift_anon' : 'gift',
                    'title'  => ($anonymous ? 'Anonymer Gifter' : $actor)
                        . ($receiver !== '' ? ' → ' . $receiver : ''),
                    'filter' => 'subs.gifted.sent',
                ];

            case 'twitch.channel.subscription.end':
                return ['badge' => 'Sub Ende', 'style' => 'sub_end', 'title' => $actor, 'filter' => 'subs.end'];

            case 'twitch.channel.raid':
                return [
                    'badge'  => 'Raid ' . max(1, $amount) . 'x',
                    'style'  => 'raid',
                    'title'  => $actor === self::UNKNOWN ? 'Unbekannter Raider' : $actor,
                    'filter' => 'raids',
                ];

            case 'twitch.stream.online':
                return ['badge' => 'Stream an', 'style' => 'stream_online', 'title' => $actor, 'filter' => 'system.stream'];

            case 'twitch.stream.offline':
                return ['badge' => 'Stream aus', 'style' => 'stream_offline', 'title' => $actor, 'filter' => 'system.stream'];
        }

        // Unbekannt: sichtbar, aber unauffaellig - damit man merkt, dass
        // etwas ankommt, fuer das noch ein Plugin fehlt.
        return [
            'badge'  => \Overlays\Core\Events\Labels::of($eventType, $this->app->hooks),
            'style'  => 'system',
            'title'  => $actor,
            'filter' => 'system.other',
        ];
    }

    private const UNKNOWN = 'Unbekannt';

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     */
    private static function actor(array $row, array $payload, string $fallback = self::UNKNOWN): string
    {
        $name = trim((string) ($row['actor_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        $name = Payload::string($payload, [
            'user_name', 'user_login', 'display_name',
            'from_broadcaster_user_name', 'broadcaster_user_name',
        ]);

        return $name !== '' ? $name : $fallback;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function message(array $row): string
    {
        $message = trim((string) ($row['message'] ?? ''));
        if ($message === '') {
            return '';
        }

        // Steuerzeichen raus, Zeilenumbrueche zu Leerzeichen - im Feed
        // steht eine Zeile pro Ereignis.
        $message = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $message) ?? $message;
        $message = trim((string) preg_replace('/\s+/u', ' ', $message));

        if (function_exists('mb_strlen') && mb_strlen($message) > 300) {
            // Ohne Netz nicht mb_substr: fehlt die Erweiterung, waere
            // das ein Fatal Error mitten im Feed.
            return function_exists('mb_substr')
                ? mb_substr($message, 0, 300) . '…'
                : substr($message, 0, 300) . '…';
        }

        return strlen($message) > 300 ? substr($message, 0, 300) . '…' : $message;
    }

    private static function time(string $value): string
    {
        return \Overlays\Core\Support\Dates::short($value);
    }
}
