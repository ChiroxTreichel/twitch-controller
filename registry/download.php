<?php

declare(strict_types=1);

/**
 * Das Paket.
 *
 *   GET /download.php?name=<slug>
 *
 * Der Kern prueft danach den SHA-256 aus dem Katalog. Diese Datei muss
 * also nur genau das Paket schicken.
 */

require __DIR__ . '/_lib.php';

$slug = slug_aus_anfrage();
$paket = PLUGINS_DIR . '/' . $slug . '/' . $slug . '.zip';

if (!is_file($paket)) {
    fehler(404, 'Fuer dieses Plugin liegt kein Paket bereit.');
}

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $slug . '.zip"');
header('Content-Length: ' . filesize($paket));
header('X-Content-Type-Options: nosniff');

// Puffer leeren, damit ein grosses Paket nicht komplett in den
// Speicher wandert.
while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile($paket);
