<?php

declare(strict_types=1);

/**
 * Die Beschreibung.
 *
 *   GET /readme.php?name=<slug>
 *
 * Als Markdown-Quelltext. Gerendert wird im Kern - bewusst dort: der
 * Text kommt aus einem Repository, und ein Plugin-Autor soll kein HTML
 * in eine fremde Verwaltungsoberflaeche schreiben koennen.
 */

require __DIR__ . '/_lib.php';

$slug = slug_aus_anfrage();
$readme = PLUGINS_DIR . '/' . $slug . '/README.md';

if (!is_file($readme)) {
    fehler(404, 'Fuer dieses Plugin liegt keine Beschreibung bereit.');
}

header('Content-Type: text/markdown; charset=utf-8');
header('X-Content-Type-Options: nosniff');

readfile($readme);
