<?php

declare(strict_types=1);

namespace TwitchController\Core\Events;

use TwitchController\Core\Hook\Hooks;

/**
 * Zieht aus einem Twitch-EventSub-Payload die Felder heraus, die fuer
 * alle Events gleich sind: wer, was, wie viel. Der vollstaendige Payload
 * bleibt daneben in der Spalte payload erhalten, es geht also nichts
 * verloren.
 *
 * Plugins mit eigenen Quellen (Throne, PayPal) haengen sich an den Hook
 * 'core.events.normalize' und liefern dieselbe Struktur fuer ihre Typen.
 */
final class Normalizer
{
    public function __construct(private readonly Hooks $hooks)
    {
    }

    /**
     * @param array<string, mixed> $event
     * @return array{actor_name: ?string, actor_external_id: ?string, message: ?string, amount: ?string, currency: ?string}
     */
    public function normalize(string $source, string $eventType, array $event): array
    {
        $normalized = $source === 'twitch'
            ? self::twitch($eventType, $event)
            : self::empty();

        $filtered = $this->hooks->filter('core.events.normalize', $normalized, $source, $eventType, $event);

        if (!is_array($filtered)) {
            return $normalized;
        }

        return [
            'actor_name'        => self::nullableString($filtered['actor_name'] ?? null),
            'actor_external_id' => self::nullableString($filtered['actor_external_id'] ?? null),
            'message'           => self::nullableString($filtered['message'] ?? null),
            'amount'            => self::nullableString($filtered['amount'] ?? null),
            'currency'          => self::nullableString($filtered['currency'] ?? null),
        ];
    }

    /**
     * Eindeutiger Schluessel gegen Doppelzustellung.
     *
     * Follows bekommen einen berechneten Schluessel statt der zufaelligen
     * Nachrichten-ID: ein Follow-Plugin, das unterdrueckte Events per
     * Follower-Liste nachtraegt, benutzt denselben Schluessel - dann
     * dedupliziert die Datenbank, egal welcher Weg zuerst da war.
     *
     * @param array<string, mixed> $event
     */
    public static function externalId(string $eventType, array $event, string $fallback): string
    {
        if ($eventType === 'twitch.channel.follow') {
            $userId = (string) ($event['user_id'] ?? '');
            $followedAt = (string) ($event['followed_at'] ?? '');
            if ($userId !== '' && $followedAt !== '') {
                return 'follow:' . $userId . ':' . $followedAt;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $event
     * @return array{actor_name: ?string, actor_external_id: ?string, message: ?string, amount: ?string, currency: ?string}
     */
    private static function twitch(string $eventType, array $event): array
    {
        $type = str_starts_with($eventType, 'twitch.') ? substr($eventType, 7) : $eventType;

        switch ($type) {
            case 'channel.follow':
            case 'channel.subscribe':
            case 'channel.subscription.end':
                return self::of($event['user_name'] ?? null, $event['user_id'] ?? null);

            case 'channel.subscription.gift':
                $anonymous = (bool) ($event['is_anonymous'] ?? false);
                return self::of(
                    $anonymous ? null : ($event['user_name'] ?? null),
                    $anonymous ? null : ($event['user_id'] ?? null),
                    null,
                    $event['total'] ?? null,
                    'GIFT_SUBS'
                );

            case 'channel.subscription.message':
                return self::of(
                    $event['user_name'] ?? null,
                    $event['user_id'] ?? null,
                    $event['message']['text'] ?? null,
                    $event['cumulative_months'] ?? null,
                    'MONTHS'
                );

            case 'channel.cheer':
                $anonymous = (bool) ($event['is_anonymous'] ?? false);
                return self::of(
                    $anonymous ? null : ($event['user_name'] ?? null),
                    $anonymous ? null : ($event['user_id'] ?? null),
                    $event['message'] ?? null,
                    $event['bits'] ?? null,
                    'BITS'
                );

            case 'channel.raid':
                return self::of(
                    $event['from_broadcaster_user_name'] ?? null,
                    $event['from_broadcaster_user_id'] ?? null,
                    null,
                    $event['viewers'] ?? null,
                    'VIEWERS'
                );

            case 'channel.channel_points_custom_reward_redemption.add':
            case 'channel.channel_points_automatic_reward_redemption.add':
                $title = trim((string) ($event['reward']['title'] ?? ''));
                $input = trim((string) ($event['user_input'] ?? ''));
                $combined = trim(($title !== '' ? "[{$title}] " : '') . $input);
                return self::of(
                    $event['user_name'] ?? null,
                    $event['user_id'] ?? null,
                    $combined === '' ? null : $combined,
                    $event['reward']['cost'] ?? null,
                    'CHANNEL_POINTS'
                );

            case 'stream.online':
            case 'stream.offline':
                return self::of(
                    $event['broadcaster_user_name'] ?? null,
                    $event['broadcaster_user_id'] ?? null
                );

            default:
                return self::empty();
        }
    }

    /**
     * @return array{actor_name: ?string, actor_external_id: ?string, message: ?string, amount: ?string, currency: ?string}
     */
    private static function of(
        mixed $actorName = null,
        mixed $actorId = null,
        mixed $message = null,
        mixed $amount = null,
        mixed $currency = null,
    ): array {
        return [
            'actor_name'        => self::nullableString($actorName),
            'actor_external_id' => self::nullableString($actorId),
            'message'           => self::nullableString($message),
            'amount'            => self::nullableString($amount),
            'currency'          => self::nullableString($currency),
        ];
    }

    /**
     * @return array{actor_name: ?string, actor_external_id: ?string, message: ?string, amount: ?string, currency: ?string}
     */
    private static function empty(): array
    {
        return self::of();
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        return (string) $value;
    }
}
