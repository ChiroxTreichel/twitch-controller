<?php

declare(strict_types=1);

namespace Overlays\Core\Support;

/**
 * Minimaler PSR-4-Autoloader. Absichtlich ohne Composer: das System soll
 * sich klonen und starten lassen, ohne dass vorher ein Build-Schritt oder
 * ein composer install noetig ist.
 *
 * Abbildung:
 *   Overlays\Core\Http\Router        -> core/Http/Router.php
 *   Overlays\Plugin\Alerts\Renderer  -> plugins/alerts/src/Renderer.php
 */
final class Autoloader
{
    public static function register(string $root): void
    {
        spl_autoload_register(static function (string $class) use ($root): void {
            if (str_starts_with($class, 'Overlays\\Core\\')) {
                $relative = substr($class, strlen('Overlays\\Core\\'));
                $path = $root . '/core/' . str_replace('\\', '/', $relative) . '.php';
                if (is_file($path)) {
                    require $path;
                }
                return;
            }

            if (str_starts_with($class, 'Overlays\\Plugin\\')) {
                $relative = substr($class, strlen('Overlays\\Plugin\\'));
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
