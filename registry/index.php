<?php

declare(strict_types=1);

/**
 * Der Katalog.
 *
 *   GET /index.php
 *   GET /index.php?search=alerts
 *
 * Wird bei jedem Abruf aus dem Plugin-Repository gelesen. Kein
 * Bauschritt: ein "git pull" genuegt, und der Client sieht sofort den
 * neuen Stand.
 *
 * Gesucht wird hier, nicht im Client: der Katalog wird live abgefragt,
 * also soll auch nur das ueber die Leitung gehen, was gebraucht wird.
 *
 * Ein Plugin erscheint nur, wenn Ordnername und slug uebereinstimmen,
 * die Version dreiteilig ist und <slug>.zip daneben liegt. Ein
 * Eintrag, den man nicht installieren kann, ist schlimmer als keiner -
 * er scheitert erst beim Anwender.
 */

require __DIR__ . '/_lib.php';

$basis = basis();
$plugins = [];

foreach (glob(PLUGINS_DIR . '/*/plugin.json') ?: [] as $datei) {
    $ordner = dirname($datei);
    $slug = strtolower(basename($ordner));

    if (plugin_ordner($slug) === null) {
        continue;
    }

    $manifest = json_decode((string) file_get_contents($datei), true);
    if (!is_array($manifest) || strtolower(trim((string) ($manifest['slug'] ?? ''))) !== $slug) {
        continue;
    }

    $version = trim((string) ($manifest['version'] ?? ''));
    if (preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
        continue;
    }

    $paket = $ordner . '/' . $slug . '.zip';
    if (!is_file($paket)) {
        continue;
    }

    $plugins[$slug] = [
        'slug'        => $slug,
        'name'        => trim((string) ($manifest['name'] ?? '')) ?: $slug,
        'version'     => $version,
        'description' => trim((string) ($manifest['description'] ?? '')),
        'author'      => trim((string) ($manifest['author'] ?? '')),
        'tags'        => is_array($manifest['tags'] ?? null)
            ? array_values(array_map('strval', $manifest['tags']))
            : [],
        'requires'    => is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [],
        'optional'    => is_array($manifest['optional'] ?? null) ? $manifest['optional'] : [],
        'download'    => $basis . '/download.php?name=' . $slug,
        'readme'      => is_file($ordner . '/README.md') ? $basis . '/readme.php?name=' . $slug : '',
        'sha256'      => hash_file('sha256', $paket),
        'size'        => (int) filesize($paket),
        'updated_at'  => gmdate('c', (int) filemtime($paket)),
    ];
}

ksort($plugins);

// ?search=: alle Woerter muessen vorkommen, Gross- und Kleinschreibung
// egal. Gesucht wird ueber Slug, Name, Beschreibung, Autor und
// Schlagworte.
$suche = trim((string) ($_GET['search'] ?? ''));

if ($suche !== '') {
    $woerter = array_filter(preg_split('/\s+/', kleinschrift($suche)) ?: []);

    $plugins = array_filter($plugins, static function (array $plugin) use ($woerter): bool {
        $heu = kleinschrift(implode(' ', [
            $plugin['slug'],
            $plugin['name'],
            $plugin['description'],
            $plugin['author'],
            implode(' ', $plugin['tags']),
        ]));

        foreach ($woerter as $wort) {
            if (!str_contains($heu, $wort)) {
                return false;
            }
        }

        return true;
    });
}

header('Content-Type: application/json; charset=utf-8');

// Live abfragen heisst live: kein Zwischenspeichern unterwegs.
header('Cache-Control: no-store');

echo json_encode([
    'format'       => 1,
    'generated_at' => gmdate('c'),
    'search'       => $suche,
    'count'        => count($plugins),
    'plugins'      => array_values($plugins),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
