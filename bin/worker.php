<?php

declare(strict_types=1);

/**
 * Hintergrundprozess. Laeuft als eigener Container und ruft in einem
 * festen Takt den Hook 'cron.tick' auf.
 *
 * Der Kern selbst hat hier nichts zu tun - das ist die Stelle, an der
 * Plugins ihre wiederkehrenden Aufgaben erledigen (Ziele aktualisieren,
 * Timer schicken, Follower nachziehen):
 *
 *   $hooks->on('cron.tick', function () use ($app) { ... });
 *
 * Ein Plugin, das laenger braucht, blockiert die anderen - lange
 * Aufgaben also selbst in Haeppchen aufteilen.
 */

use Overlays\Core\App;
use Overlays\Core\Support\Autoloader;

$root = dirname(__DIR__);

require $root . '/core/Support/Autoloader.php';
Autoloader::register($root);

$app = App::boot($root);

$interval = max(5, (int) ($app->env->int('WORKER_INTERVAL', 15)));

fwrite(STDOUT, "[worker] Start, Takt {$interval}s\n");

// Sauber beenden, wenn Docker das Signal schickt.
$running = true;
if (function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    $stop = static function () use (&$running): void {
        $running = false;
        fwrite(STDOUT, "[worker] Stoppe...\n");
    };
    pcntl_signal(SIGTERM, $stop);
    pcntl_signal(SIGINT, $stop);
}

$booted = false;

while ($running) {
    try {
        if (!$app->isInstalled()) {
            fwrite(STDOUT, "[worker] Warte auf abgeschlossene Einrichtung...\n");
            sleep(10);
            continue;
        }

        // Plugins erst laden, wenn eingerichtet ist. Danach einmal - der
        // Prozess wird bei Aenderungen neu gestartet.
        if (!$booted) {
            $app->plugins->boot();
            $booted = true;
            fwrite(STDOUT, '[worker] Plugins geladen: '
                . (implode(', ', $app->plugins->bootedSlugs()) ?: 'keine') . "\n");
        }

        $app->settings->flush();
        $app->hooks->dispatch('cron.tick');
    } catch (Throwable $e) {
        fwrite(STDERR, '[worker] Fehler: ' . $e->getMessage() . "\n");
    }

    sleep($interval);
}

fwrite(STDOUT, "[worker] Beendet.\n");
