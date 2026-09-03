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
            'channel.follow'                => 'Follow',
            'channel.subscribe'             => 'Neues Abo',
            'channel.subscription.message'  => 'Abo verlängert',
            'channel.subscription.gift'     => 'Abo verschenkt',
            'channel.subscription.end'      => 'Abo beendet',
            'channel.cheer'                 => 'Bits',
            'channel.raid'                  => 'Raid',
            'stream.online'                 => 'Stream gestartet',
            'stream.offline'                => 'Stream beendet',
            'channel.channel_points_custom_reward_redemption.add'    => 'Kanalpunkte eingelöst',
            'channel.channel_points_automatic_reward_redemption.add' => 'Kanalpunkte (automatisch)',
            'channel.hype_train.begin'      => 'Hype-Train gestartet',
            'channel.hype_train.progress'   => 'Hype-Train läuft',
            'channel.hype_train.end'        => 'Hype-Train beendet',
            'channel.goal.begin'            => 'Twitch-Ziel gestartet',
            'channel.goal.progress'         => 'Twitch-Ziel Fortschritt',
            'channel.goal.end'              => 'Twitch-Ziel beendet',
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
