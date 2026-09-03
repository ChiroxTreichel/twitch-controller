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
                'label'  => 'Follower sehen',
                'reason' => 'damit neue Follows im Stream ankommen',
            ],
            'channel:read:subscriptions' => [
                'label'  => 'Abos sehen',
                'reason' => 'für Abo-Alerts und Abo-Ziele',
            ],
            'bits:read' => [
                'label'  => 'Bits sehen',
                'reason' => 'damit gespendete Bits gemeldet werden',
            ],
            'channel:read:redemptions' => [
                'label'  => 'Kanalpunkte sehen',
                'reason' => 'um auf eingelöste Belohnungen zu reagieren',
            ],
            'channel:read:goals' => [
                'label'  => 'Twitch-Ziele lesen',
                'reason' => 'für die Fortschrittsbalken von Twitch',
            ],
            'channel:read:hype_train' => [
                'label'  => 'Hype-Train sehen',
                'reason' => 'um den Hype-Train im Overlay anzuzeigen',
            ],
            'channel:manage:broadcast' => [
                'label'  => 'Titel und Kategorie ändern',
                'reason' => 'um die Stream-Infos aus der Oberfläche zu setzen',
            ],
            'channel:manage:raids' => [
                'label'  => 'Raids starten',
                'reason' => 'um Raids aus der Oberfläche auszulösen',
            ],
            'chat:read' => [
                'label'  => 'Chat mitlesen',
                'reason' => 'damit Chat-Befehle erkannt werden',
            ],
            'chat:edit' => [
                'label'  => 'Im Chat schreiben',
                'reason' => 'damit der Bot antworten kann',
            ],
            'moderator:manage:banned_users' => [
                'label'  => 'Timeouts und Sperren setzen',
                'reason' => 'für die automatische Moderation',
            ],
            'moderator:manage:chat_messages' => [
                'label'  => 'Chat-Nachrichten löschen',
                'reason' => 'um unerwünschte Nachrichten zu entfernen',
            ],
            'user:read:email' => [
                'label'  => 'E-Mail-Adresse lesen',
                'reason' => 'wird zur Zuordnung des Accounts benutzt',
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
