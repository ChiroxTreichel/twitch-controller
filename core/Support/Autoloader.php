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
                $slug = strtolower(array_shift($parts));
                if ($slug === '' || $parts === []) {
                    return;
                }
                $path = $root . '/plugins/' . $slug . '/src/' . implode('/', $parts) . '.php';
                if (is_file($path)) {
                    require $path;
                }
            }
        });
    }
}
