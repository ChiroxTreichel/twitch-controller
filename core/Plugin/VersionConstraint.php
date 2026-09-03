<?php

declare(strict_types=1);

namespace TwitchController\Core\Plugin;

/**
 * Versionsvergleich fuer Plugin-Abhaengigkeiten. Absichtlich klein
 * gehalten - unterstuetzt wird, was in einem Manifest realistisch
 * vorkommt:
 *
 *   *                 alles
 *   1.2.3             genau diese Version
 *   >=1.2.3  >1.2.3   groesser (gleich)
 *   <=1.2.3  <1.2.3   kleiner (gleich)
 *   ^1.2.3            ab 1.2.3, aber unter 2.0.0
 *   ~1.2.3            ab 1.2.3, aber unter 1.3.0
 *   >=1.2.3 <2.0.0    Leerzeichen = alle Bedingungen muessen gelten
 */
final class VersionConstraint
{
    public static function satisfies(string $version, string $constraint): bool
    {
        $constraint = trim($constraint);
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        foreach (preg_split('/\s+/', $constraint) ?: [] as $part) {
            if (!self::satisfiesSingle($version, $part)) {
                return false;
            }
        }

        return true;
    }

    private static function satisfiesSingle(string $version, string $constraint): bool
    {
        if ($constraint === '' || $constraint === '*') {
            return true;
        }

        if (str_starts_with($constraint, '^')) {
            $base = substr($constraint, 1);
            $upper = (((int) explode('.', $base)[0]) + 1) . '.0.0';

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }

        if (str_starts_with($constraint, '~')) {
            $base = substr($constraint, 1);
            $parts = explode('.', $base);
            $upper = ($parts[0] ?? '0') . '.' . (((int) ($parts[1] ?? '0')) + 1) . '.0';

            return version_compare($version, $base, '>=') && version_compare($version, $upper, '<');
        }

        foreach (['>=', '<=', '!=', '>', '<', '='] as $operator) {
            if (str_starts_with($constraint, $operator)) {
                return version_compare($version, trim(substr($constraint, strlen($operator))), $operator);
            }
        }

        return version_compare($version, $constraint, '==');
    }
}
