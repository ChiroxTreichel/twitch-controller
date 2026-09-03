<?php

declare(strict_types=1);

namespace TwitchController\Core\Support;

/**
 * Minimaler PSR-4-Autoloader. Absichtlich ohne Composer: das System soll
 * sich klonen und starten lassen, ohne dass vorher ein Build-Schritt oder
 * ein composer install noetig ist.
 *
 * Abbildung:
 *   TwitchController\Core\Http\Router        -> core/Http/Router.php
 *   TwitchController\Plugin\Alerts\Renderer  -> plugins/alerts/src/Renderer.php
 */
final class Autoloader
{
    public static function register(string $root): void
    {
        // Funktionen lassen sich nicht nachladen, also hier mitnehmen.
        // Das ist der eine Aufruf, den jeder Einstiegspunkt macht -
        // damit kann translate() nirgends fehlen.
        require_once $root . '/core/functions.php';

        spl_autoload_register(static function (string $class) use ($root): void {
            if (str_starts_with($class, 'TwitchController\\Core\\')) {
                $relative = substr($class, strlen('TwitchController\\Core\\'));
                $path = $root . '/core/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require $path;
                }
                return;
            }

            if (str_starts_with($class, 'TwitchController\\Plugin\\')) {
                $relative = substr($class, strlen('TwitchController\\Plugin\\'));
                $parts = explode('\\', $relative);
                $namensraum = (string) array_shift($parts);

                if ($namensraum === '' || $parts === []) {
                    return;
                }

                foreach (self::slugCandidates($namensraum) as $slug) {
                    $path = $root . '/plugins/' . $slug . '/src/' . implode('/', $parts) . '.php';

                    if (is_file($path)) {
                        require $path;

                        return;
                    }
                }
            }
        });
    }

    /**
     * Moegliche Ordnernamen zu einem Namensraum-Abschnitt.
     *
     * Ein Slug darf Bindestriche haben ("twitch-alerts"), ein
     * PHP-Namensraum nicht. Aus "twitch-alerts" wird deshalb
     * "TwitchAlerts" - und diese Richtung muss der Autoloader
     * zurueckrechnen. Er kann nicht wissen, ob "TwitchAlerts"
     * urspruenglich "twitchalerts" oder "twitch-alerts" hiess, also
     * probiert er beides.
     *
     * Ohne das laedt die Klasse eines Plugins mit Bindestrich im Slug
     * nie - und der Fehler zeigt sich erst dort, wo sie gebraucht
     * wird, weit weg von der Ursache.
     *
     * @return list<string>
     */
    private static function slugCandidates(string $namespaceSegment): array
    {
        $kandidaten = [strtolower($namespaceSegment)];

        // TwitchAlerts -> twitch-alerts
        $mitBindestrich = strtolower(
            (string) preg_replace('/(?<!^)([A-Z])/', '-$1', $namespaceSegment)
        );

        if ($mitBindestrich !== $kandidaten[0]) {
            $kandidaten[] = $mitBindestrich;
        }

        return $kandidaten;
    }
}
