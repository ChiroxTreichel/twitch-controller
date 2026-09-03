<?php

declare(strict_types=1);

namespace TwitchController\Core\Twitch;

/**
 * Uebersetzt Twitch-Berechtigungen in verstaendliches Deutsch.
 *
 * "bits:read" sagt einem Streamer nichts. In der Oberflaeche steht
 * deshalb "Bits sehen - damit Bit-Alerts ausgeloest werden koennen".
 *
 * Plugins koennen eigene Uebersetzungen nachliefern, wenn sie Scopes
 * anfordern, die hier fehlen:
 *
 *   $hooks->on('core.twitch.scope_labels', function (array $labels) {
 *       $labels['channel:manage:polls'] = [
 *           'label'  => 'Umfragen starten',
 *           'reason' => 'damit das Umfragen-Plugin Abstimmungen anlegen kann',
 *       ];
 *       return $labels;
 *   });
 */
final class Scopes
{
    /**
     * @return array<string, array{label: string, reason: string}>
     */
    public static function catalog(?\TwitchController\Core\Hook\Hooks $hooks = null): array
    {
        $catalog = self::builtin();

        if ($hooks === null) {
            return $catalog;
        }

        $filtered = $hooks->filter('core.twitch.scope_labels', $catalog);

        return is_array($filtered) ? $filtered : $catalog;
    }

    /**
     * @return array<string, array{label: string, reason: string}>
     */
    private static function builtin(): array
    {
        return [
            'moderator:read:followers' => [
                'label'  => translate('scope.followers'),
                'reason' => translate('scope.followers.why'),
            ],
            'channel:read:subscriptions' => [
                'label'  => translate('scope.subs'),
                'reason' => translate('scope.subs.why'),
            ],
            'bits:read' => [
                'label'  => translate('scope.bits'),
                'reason' => translate('scope.bits.why'),
            ],
            'channel:read:redemptions' => [
                'label'  => translate('scope.points'),
                'reason' => translate('scope.points.why'),
            ],
            'channel:read:goals' => [
                'label'  => translate('scope.goals'),
                'reason' => translate('scope.goals.why'),
            ],
            'channel:read:hype_train' => [
                'label'  => translate('scope.hype'),
                'reason' => translate('scope.hype.why'),
            ],
            'channel:manage:broadcast' => [
                'label'  => translate('scope.broadcast'),
                'reason' => translate('scope.broadcast.why'),
            ],
            'channel:manage:raids' => [
                'label'  => translate('scope.raids'),
                'reason' => translate('scope.raids.why'),
            ],
            // Chat laeuft ueber EventSub und Helix, nicht mehr ueber
            // IRC. Die alten Scopes chat:read und chat:edit gehoerten
            // zum IRC-Weg und sind hier nutzlos - sie stehen darum
            // nicht mehr in dieser Liste, damit niemand sie anfordert
            // und sich wundert, warum der Chat leer bleibt.
            'user:read:chat' => [
                'label'  => translate('scope.chat_read'),
                'reason' => translate('scope.chat_read.why'),
            ],
            'user:write:chat' => [
                'label'  => translate('scope.chat_write'),
                'reason' => translate('scope.chat_write.why'),
            ],
            'user:bot' => [
                'label'  => translate('scope.bot_user'),
                'reason' => translate('scope.bot_user.why'),
            ],
            'channel:bot' => [
                'label'  => translate('scope.bot_channel'),
                'reason' => translate('scope.bot_channel.why'),
            ],
            'moderator:manage:banned_users' => [
                'label'  => translate('scope.ban'),
                'reason' => translate('scope.ban.why'),
            ],
            'moderator:manage:chat_messages' => [
                'label'  => translate('scope.delete'),
                'reason' => translate('scope.delete.why'),
            ],
            'user:read:email' => [
                'label'  => translate('scope.email'),
                'reason' => translate('scope.email.why'),
            ],
        ];
    }

    /**
     * Verständlicher Name einer Berechtigung. Unbekannte Scopes werden
     * unverändert zurückgegeben - besser der technische Name als nichts.
     */
    public static function label(string $scope): string
    {
        return self::catalog()[$scope]['label'] ?? $scope;
    }

    public static function reason(string $scope): string
    {
        return self::catalog()[$scope]['reason'] ?? '';
    }

    /**
     * @param list<string> $scopes
     * @return list<array{scope: string, label: string, reason: string}>
     */
    public static function describe(array $scopes, ?\TwitchController\Core\Hook\Hooks $hooks = null): array
    {
        $catalog = self::catalog($hooks);
        $result = [];

        foreach ($scopes as $scope) {
            $result[] = [
                'scope'  => $scope,
                'label'  => $catalog[$scope]['label'] ?? $scope,
                'reason' => $catalog[$scope]['reason'] ?? '',
            ];
        }

        return $result;
    }
}
