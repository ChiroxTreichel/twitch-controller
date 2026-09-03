<?php

declare(strict_types=1);

namespace TwitchController\Core\Obs;

/**
 * Lesehilfen fuer den Event-Payload.
 *
 * Twitch benennt dieselbe Sache je Event-Typ unterschiedlich
 * (cumulative_months / cumulativeMonths, is_anonymous / anonymous), und
 * Plugins bringen eigene Felder mit. Deshalb nehmen alle Leser eine
 * Liste moeglicher Schluessel und den ersten Treffer.
 */
final class Payload
{
    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    public static function string(array $payload, array $keys, string $default = ''): string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    public static function number(array $payload, array $keys, int $default = 0): int
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_numeric($value)) {
                return (int) $value;
            }
        }

        return $default;
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<string> $keys
     */
    public static function bool(array $payload, array $keys): bool
    {
        foreach ($keys as $key) {
            if (!array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            if (is_bool($value)) {
                return $value;
            }
            if (is_numeric($value)) {
                return ((int) $value) !== 0;
            }
            if (is_string($value)) {
                return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'ja'], true);
            }
        }

        return false;
    }

    /**
     * Twitch liefert die Abo-Stufe als "1000", "2000", "3000" oder
     * "Prime". Zurueck kommt die Zahl als Zeichenkette, oder ''.
     *
     * @param array<string, mixed> $payload
     */
    public static function tier(array $payload): string
    {
        $tier = self::string($payload, ['tier', 'sub_tier', 'subTier']);
        $tier = strtolower(str_replace(' ', '', $tier));

        return match ($tier) {
            '1000', 'tier1', '1' => '1000',
            '2000', 'tier2', '2' => '2000',
            '3000', 'tier3', '3' => '3000',
            default => '',
        };
    }

    /**
     * Prime-Abos erkennt Twitch nicht einheitlich: manchmal ueber
     * tier="Prime", manchmal ueber is_prime, manchmal ueber sub_plan.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     */
    public static function isPrime(array $row, array $payload): bool
    {
        if (self::bool($payload, ['is_prime', 'isPrime'])) {
            return true;
        }

        $candidates = [
            self::string($payload, ['tier', 'sub_tier', 'subTier']),
            self::string($payload, ['sub_plan', 'subPlan', 'plan']),
            (string) ($row['currency'] ?? ''),
        ];

        foreach ($candidates as $candidate) {
            if (strtolower(trim($candidate)) === 'prime') {
                return true;
            }
        }

        return false;
    }

    /**
     * "Tier 2" als Zusatz hinter dem Badge-Text. Stufe 1 bleibt leer,
     * weil sie der Normalfall ist.
     *
     * @param array<string, mixed> $row
     * @param array<string, mixed> $payload
     */
    public static function tierSuffix(array $row, array $payload): string
    {
        if (self::isPrime($row, $payload)) {
            return ' Prime';
        }

        return match (self::tier($payload)) {
            '2000' => ' Tier 2',
            '3000' => ' Tier 3',
            default => '',
        };
    }

    /**
     * Kurzform der Stufe fuer Filterschluessel: 1000 -> "tier1".
     * Die Namen sind bewusst dieselben wie im alten obs.php, damit
     * gespeicherte Feed-Links weiter funktionieren.
     *
     * @param array<string, mixed> $payload
     */
    public static function tierSlug(array $payload): string
    {
        return match (self::tier($payload)) {
            '2000' => 'tier2',
            '3000' => 'tier3',
            default => 'tier1',
        };
    }
}
