<?php

declare(strict_types=1);

/**
 * Erzeugt public/index.json aus den Paketen in public/pkg/.
 *
 *   php bin/build.php
 *   php bin/build.php --base-url https://plugins.example.com
 *
 * Fuer jedes ZIP wird plugin.json aus dem Archiv gelesen, Pruefsumme und
 * Groesse berechnet und - falls vorhanden - meta/<slug>.json
 * darueberlegt. Liegen mehrere Versionen eines Plugins, gewinnt die
 * hoechste.
 *
 * Nach jeder Aenderung an public/pkg/ erneut ausfuehren.
 */

$root = dirname(__DIR__);
$publicDir = $root . '/public';
$packageDir = $publicDir . '/pkg';
$metaDir = $publicDir . '/meta';

$baseUrl = getenv('REGISTRY_BASE_URL') ?: 'https://plugins.talutah.de';
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--base-url' && isset($argv[$i + 1])) {
        $baseUrl = $argv[$i + 1];
        $i++;
    }
}
$baseUrl = rtrim($baseUrl, '/');

if (!is_dir($packageDir)) {
    fwrite(STDERR, "Kein Paketverzeichnis: {$packageDir}\n");
    exit(1);
}

if (!extension_loaded('zip')) {
    fwrite(STDERR, "Die PHP-Erweiterung zip fehlt.\n");
    exit(1);
}

/**
 * Liest plugin.json aus einem Archiv. Akzeptiert die Datei im
 * Wurzelverzeichnis oder in genau einem Unterordner - genauso wie der
 * Installer auf der Gegenseite.
 *
 * @return array<string, mixed>|null
 */
function manifestFromZip(string $file): ?array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        return null;
    }

    $json = $zip->getFromName('plugin.json');

    if ($json === false) {
        // Einen Unterordner tief suchen.
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (substr_count(trim($name, '/'), '/') === 1 && str_ends_with($name, '/plugin.json')) {
                $json = $zip->getFromName($name);
                break;
            }
        }
    }

    $zip->close();

    if (!is_string($json)) {
        return null;
    }

    $decoded = json_decode($json, true);

    return is_array($decoded) ? $decoded : null;
}

$plugins = [];
$skipped = [];

foreach (glob($packageDir . '/*.zip') ?: [] as $file) {
    $name = basename($file);
    $manifest = manifestFromZip($file);

    if ($manifest === null) {
        $skipped[] = "{$name}: kein lesbares plugin.json";
        continue;
    }

    $slug = strtolower(trim((string) ($manifest['slug'] ?? '')));
    $version = trim((string) ($manifest['version'] ?? ''));

    if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]$/', $slug)) {
        $skipped[] = "{$name}: ungültiger Slug \"{$slug}\"";
        continue;
    }

    if (!preg_match('/^\d+\.\d+\.\d+/', $version)) {
        $skipped[] = "{$name}: ungültige Version \"{$version}\"";
        continue;
    }

    // Nur die jeweils höchste Version aufnehmen.
    if (isset($plugins[$slug]) && version_compare($plugins[$slug]['version'], $version, '>=')) {
        continue;
    }

    $entry = [
        'slug'        => $slug,
        'name'        => trim((string) ($manifest['name'] ?? $slug)),
        'version'     => $version,
        'summary'     => trim((string) ($manifest['description'] ?? '')),
        'description' => '',
        'author'      => trim((string) ($manifest['author'] ?? '')),
        'homepage'    => '',
        'tags'        => [],
        'icon'        => '',
        'screenshots' => [],
        'requires'    => is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [],
        'optional'    => is_array($manifest['optional'] ?? null) ? $manifest['optional'] : [],
        'download'    => $baseUrl . '/pkg/' . rawurlencode($name),
        'sha256'      => (string) hash_file('sha256', $file),
        'size'        => (int) filesize($file),
        'updated_at'  => date('c', (int) filemtime($file)),
    ];

    // Zusatzangaben, die nicht ins Plugin gehören: Langtext, Bilder,
    // Schlagworte. Optional, überschreibt die Werte von oben.
    $metaFile = $metaDir . '/' . $slug . '.json';
    if (is_file($metaFile)) {
        $meta = json_decode((string) file_get_contents($metaFile), true);

        if (!is_array($meta)) {
            $skipped[] = "meta/{$slug}.json: kein gültiges JSON (ignoriert)";
        } else {
            foreach (['summary', 'description', 'author', 'homepage', 'icon'] as $key) {
                if (isset($meta[$key]) && is_string($meta[$key]) && trim($meta[$key]) !== '') {
                    $entry[$key] = trim($meta[$key]);
                }
            }

            foreach (['tags', 'screenshots'] as $key) {
                if (isset($meta[$key]) && is_array($meta[$key])) {
                    $entry[$key] = array_values(array_map('strval', $meta[$key]));
                }
            }
        }
    }

    // Relative Bildpfade zu vollständigen Adressen machen.
    foreach (['icon'] as $key) {
        if ($entry[$key] !== '' && !preg_match('#^https?://#', $entry[$key])) {
            $entry[$key] = $baseUrl . '/' . ltrim($entry[$key], '/');
        }
    }
    $entry['screenshots'] = array_map(
        static fn (string $url): string => preg_match('#^https?://#', $url) === 1
            ? $url
            : $baseUrl . '/' . ltrim($url, '/'),
        $entry['screenshots']
    );

    $plugins[$slug] = $entry;
}

ksort($plugins);

$catalog = [
    'format'       => 1,
    'generated_at' => date('c'),
    'plugins'      => array_values($plugins),
];

$target = $publicDir . '/index.json';
$json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

if ($json === false || file_put_contents($target, $json . "\n") === false) {
    fwrite(STDERR, "Konnte {$target} nicht schreiben.\n");
    exit(1);
}

printf("index.json geschrieben: %d Plugin(s), Basis %s\n", count($plugins), $baseUrl);

foreach ($plugins as $entry) {
    printf("  %-20s %-10s %6d KB\n", $entry['slug'], $entry['version'], (int) round($entry['size'] / 1024));
}

foreach ($skipped as $message) {
    fwrite(STDERR, '  übersprungen: ' . $message . "\n");
}
