<?php

declare(strict_types=1);

namespace TwitchController\Core\Support;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/**
 * Zeitstempel aus der Datenbank anzeigen.
 *
 * Warum eine eigene Klasse: Postgres liefert timestamptz mit Offset
 * ("2026-09-02 14:30:00+00"). DateTimeImmutable uebernimmt diesen Offset,
 * und format() rechnet dann NICHT in die Standardzeitzone um -
 * date_default_timezone_set() bleibt also wirkungslos. Ohne
 * ausdrueckliches setTimezone() steht im Feed UTC, auch wenn Berlin
 * eingestellt ist.
 */
final class Dates
{
    /** "02.09. 16:30" - kompakt fuer den Feed. */
    public static function short(?string $value): string
    {
        return self::format($value, 'd.m. H:i');
    }

    /** "02.09.2026 16:30" - fuer Tabellen. */
    public static function long(?string $value): string
    {
        return self::format($value, 'd.m.Y H:i');
    }

    /** "02.09.2026" */
    public static function day(?string $value): string
    {
        return self::format($value, 'd.m.Y');
    }

    public static function format(?string $value, string $pattern): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        try {
            return (new DateTimeImmutable($value))
                ->setTimezone(new DateTimeZone(date_default_timezone_get()))
                ->format($pattern);
        } catch (Throwable) {
            // Unlesbarer Wert: lieber gekuerzt anzeigen als nichts.
            return substr($value, 0, 16);
        }
    }
}
