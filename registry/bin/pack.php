<?php

declare(strict_types=1);

/**
 * Packt einen Plugin-Ordner zu einem ZIP in public/pkg/.
 *
 *   php bin/pack.php /pfad/zum/plugin
 *   php bin/pack.php ../plugins/alerts
 *
 * Der Dateiname ergibt sich aus Slug und Version im Manifest:
 * public/pkg/<slug>-<version>.zip
 *
 * Danach bin/build.php laufen lassen, damit der Katalog es kennt.
 */

$root = dirname(__DIR__);
$packageDir = $root . '/public/pkg';

if ($argc < 2) {
    fwrite(STDERR, "Aufruf: php bin/pack.php <plugin-ordner>\n");
    exit(1);
}

$source = rtrim((string) $argv[1], '/\\');
$source = realpath($source) ?: $source;

if (!is_dir($source)) {
    fwrite(STDERR, "Kein Verzeichnis: {$source}\n");
    exit(1);
}

foreach (['plugin.json', 'plugin.php'] as $required) {
    if (!is_file($source . '/' . $required)) {
        fwrite(STDERR, "Im Ordner fehlt {$required} - das ist kein Plugin.\n");
        exit(1);
    }
}

$manifest = json_decode((string) file_get_contents($source . '/plugin.json'), true);
if (!is_array($manifest)) {
    fwrite(STDERR, "plugin.json ist kein gültiges JSON.\n");
    exit(1);
}

$slug = strtolower(trim((string) ($manifest['slug'] ?? '')));
$version = trim((string) ($manifest['version'] ?? ''));

if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]$/', $slug)) {
    fwrite(STDERR, "Ungültiger Slug: \"{$slug}\"\n");
    exit(1);
}

if (!preg_match('/^\d+\.\d+\.\d+/', $version)) {
    fwrite(STDERR, "Version muss X.Y.Z sein, ist \"{$version}\"\n");
    exit(1);
}

if (basename($source) !== $slug) {
    fwrite(STDERR, "Warnung: Ordner heißt \"" . basename($source) . "\", Slug ist \"{$slug}\".\n");
}

if (!is_dir($packageDir) && !mkdir($packageDir, 0775, true) && !is_dir($packageDir)) {
    fwrite(STDERR, "Konnte {$packageDir} nicht anlegen.\n");
    exit(1);
}

$target = $packageDir . '/' . $slug . '-' . $version . '.zip';

// Nichts, was in einem Paket nichts zu suchen hat.
$skipNames = ['.git', '.gitignore', '.gitattributes', '.DS_Store', 'node_modules', '.idea', '.vscode'];

$zip = new ZipArchive();
if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Konnte {$target} nicht schreiben.\n");
    exit(1);
}

$count = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::SELF_FIRST
);

foreach ($iterator as $item) {
    /** @var SplFileInfo $item */
    $relative = str_replace('\\', '/', substr($item->getPathname(), strlen($source) + 1));

    $skip = false;
    foreach (explode('/', $relative) as $part) {
        if (in_array($part, $skipNames, true)) {
            $skip = true;
            break;
        }
    }
    if ($skip) {
        continue;
    }

    if ($item->isDir()) {
        $zip->addEmptyDir($relative);
        continue;
    }

    if ($item->isLink()) {
        fwrite(STDERR, "Übersprungen (Verknüpfung): {$relative}\n");
        continue;
    }

    $zip->addFile($item->getPathname(), $relative);
    $count++;
}

$zip->close();

printf(
    "%s geschrieben: %d Datei(en), %d KB\n",
    basename($target),
    $count,
    (int) round(((int) filesize($target)) / 1024)
);
printf("Prüfsumme: %s\n", (string) hash_file('sha256', $target));
printf("\nJetzt noch:  php bin/build.php\n");
