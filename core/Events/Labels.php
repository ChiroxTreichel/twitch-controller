<?php

declare(strict_types=1);

namespace TwitchController\Core\Events;

use TwitchController\Core\Hook\Hooks;

/**
 * Verstaendliche Namen fuer Event-Typen.
 *
 * In der Oberflaeche steht "Abo verschenkt" und nicht
 * "twitch.channel.subscription.gift". Plugins mit eigenen Quellen
 * ergaenzen ihre Typen ueber den Hook 'core.events.labels'.
 */
final class Labels
{
    /**
     * @return array<string, string>
     */
    public static function all(?Hooks $hooks = null): array
    {
        $labels = [
            'channel.follow'                => translate('events.follow'),
            'channel.subscribe'             => translate('events.new_sub'),
            'channel.subscription.message'  => translate('events.sub_renewed'),
            'channel.subscription.gift'     => translate('events.sub_gifted'),
            'channel.subscription.end'      => translate('events.sub_ended'),
            'channel.cheer'                 => translate('events.bits'),
            'channel.raid'                  => translate('events.raid'),
            'stream.online'                 => translate('events.stream_start'),
            'stream.offline'                => translate('events.stream_end'),
            'channel.channel_points_custom_reward_redemption.add'    => translate('events.channel_points'),
            'channel.channel_points_automatic_reward_redemption.add' => translate('events.points_auto'),
            'channel.hype_train.begin'      => translate('events.hype_start'),
            'channel.hype_train.progress'   => translate('events.hype_train'),
            'channel.hype_train.end'        => translate('events.hype_end'),
            'channel.goal.begin'            => translate('events.goal_start'),
            'channel.goal.progress'         => translate('events.goal_progress'),
            'channel.goal.end'              => translate('events.goal_end'),
        ];

        if ($hooks === null) {
            return $labels;
        }

        $filtered = $hooks->filter('core.events.labels', $labels);

        return is_array($filtered) ? $filtered : $labels;
    }

    /**
     * Nimmt sowohl "channel.cheer" als auch "twitch.channel.cheer".
     * Unbekannte Typen kommen unveraendert zurueck.
     */
    public static function of(string $eventType, ?Hooks $hooks = null): string
    {
        $labels = self::all($hooks);

        if (isset($labels[$eventType])) {
            return $labels[$eventType];
        }

        // Quellen-Praefix abschneiden: twitch.channel.cheer -> channel.cheer
        $withoutSource = str_contains($eventType, '.')
            ? substr($eventType, strpos($eventType, '.') + 1)
            : $eventType;

        return $labels[$withoutSource] ?? $eventType;
    }
}
