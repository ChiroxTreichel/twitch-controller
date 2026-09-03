#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Sammelt alle translate()-Texte ein und schreibt sie in eine
 * Sprachdatei.
 *
 *   php bin/lang.php en                 -> lang/en.json
 *   php bin/lang.php en --plugin throne -> plugins/throne/lang/en.json
 *   php bin/lang.php --check            -> nur zaehlen, nichts schreiben
 *
 * Vorhandene Uebersetzungen bleiben erhalten. Neue Texte kommen mit
 * leerem Wert dazu - der Uebersetzer sieht also, was noch fehlt, und
 * die Oberflaeche zeigt bis dahin den deutschen Text.
 *
 * Texte, die aus der Datei verschwunden sind, werden am Ende unter
 * "_unbenutzt" gesammelt statt geloescht: manchmal ist es nur eine
 * Umformulierung, und dann will man die alte Uebersetzung noch sehen.
 */

$root = dirname(__DIR__);

$language = '';
$plugin = '';
$check = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = (string) $argv[$i];

    if ($arg === '--plugin' && isset($argv[$i + 1])) {
        $plugin = strtolower((string) $argv[$i + 1]);
        $i++;
        continue;
    }

    if ($arg === '--check') {
        $check = true;
        continue;
    }

    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "php bin/lang.php <sprachcode> [--plugin <slug>] [--check]\n");
        exit(0);
    }

    if (preg_match('/^[a-z]{2}$/', $arg) === 1) {
        $language = $arg;
    }
}

if ($language === '' && !$check) {
    fwrite(STDERR, "Bitte einen Sprachcode angeben, z.B.:  php bin/lang.php en\n");
    exit(1);
}

// --- Welche Dateien werden durchsucht? -------------------------------

if ($plugin !== '') {
    $scanDirs = [$root . '/plugins/' . $plugin];
    $target = $root . '/plugins/' . $plugin . '/lang/' . $language . '.json';

    if (!is_dir($scanDirs[0])) {
        fwrite(STDERR, "Kein Plugin: {$plugin}\n");
        exit(1);
    }
} else {
    // Der Kern - Plugins bringen ihre Texte selbst mit.
    $scanDirs = [$root . '/core'];
    $target = $root . '/lang/' . $language . '.json';
}

/**
 * @return list<string>
 */
function phpFiles(array $dirs): array
{
    $files = [];

    foreach ($dirs as $dir) {
        if (!is_dir($dir)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if ($item->isFile() && strtolower($item->getExtension()) === 'php') {
                $files[] = $item->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

// --- Texte einsammeln ------------------------------------------------
//
// Ueber die PHP-Tokens statt per Regex: nur so wird ein Aufruf im
// Kommentar nicht mitgezaehlt, und Anfuehrungszeichen im Text machen
// keinen Aerger.

$found = [];
$occurrences = [];

foreach (phpFiles($scanDirs) as $file) {
    $tokens = @token_get_all((string) file_get_contents($file));
    if (!is_array($tokens)) {
        continue;
    }

    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];

        if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'translate') {
            continue;
        }

        // Methodenaufrufe wie $x->translate() nicht mitnehmen.
        $before = $tokens[$i - 1] ?? null;
        if (is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            continue;
        }

        // Erstes Argument muss ein einfacher Text sein.
        $open = $tokens[$i + 1] ?? null;
        $arg = $tokens[$i + 2] ?? null;

        if ($open !== '(' || !is_array($arg) || $arg[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $raw = $arg[1];
        $quote = $raw[0];
        $text = substr($raw, 1, -1);

        // Maskierungen aufloesen, wie PHP sie sieht.
        $text = $quote === "'"
            ? str_replace(["\\'", '\\\\'], ["'", '\\'], $text)
            : stripcslashes($text);

        if (trim($text) === '') {
            continue;
        }

        $found[$text] = true;
        $occurrences[$text] = ($occurrences[$text] ?? 0) + 1;
    }
}

$texts = array_keys($found);
sort($texts, SORT_NATURAL | SORT_FLAG_CASE);

printf("%d übersetzbare Texte gefunden (%d Aufrufe).\n", count($texts), array_sum($occurrences));

if ($check) {
    foreach ($texts as $text) {
        printf("  %2dx  %s\n", $occurrences[$text], $text);
    }
    exit(0);
}

// --- Mit der vorhandenen Datei zusammenfuehren -----------------------

$existing = [];
if (is_file($target)) {
    $decoded = json_decode((string) file_get_contents($target), true);
    if (is_array($decoded)) {
        $existing = $decoded;
    }
}

$unused = is_array($existing['_unbenutzt'] ?? null) ? $existing['_unbenutzt'] : [];
unset($existing['_unbenutzt']);

$result = [];
$new = 0;
$kept = 0;

foreach ($texts as $text) {
    if (isset($existing[$text]) && is_string($existing[$text]) && $existing[$text] !== '') {
        $result[$text] = $existing[$text];
        $kept++;
        continue;
    }

    // Vielleicht lag die Uebersetzung schon unter den unbenutzten.
    if (isset($unused[$text]) && is_string($unused[$text]) && $unused[$text] !== '') {
        $result[$text] = $unused[$text];
        unset($unused[$text]);
        $kept++;
        continue;
    }

    $result[$text] = '';
    $new++;
}

// Was nicht mehr vorkommt, wandert nach unten statt zu verschwinden.
foreach ($existing as $text => $translation) {
    if (!isset($result[$text]) && is_string($translation) && $translation !== '') {
        $unused[$text] = $translation;
    }
}

if ($unused !== []) {
    ksort($unused);
    $result['_unbenutzt'] = $unused;
}

$directory = dirname($target);
if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
    fwrite(STDERR, "Konnte {$directory} nicht anlegen.\n");
    exit(1);
}

$json = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if ($json === false || file_put_contents($target, $json . "\n") === false) {
    fwrite(STDERR, "Konnte {$target} nicht schreiben.\n");
    exit(1);
}

$offen = count(array_filter(
    $result,
    static fn (mixed $value): bool => is_string($value) && $value === ''
));

printf(
    "%s geschrieben: %d übernommen, %d neu, %d noch offen%s\n",
    str_replace($root . '/', '', $target),
    $kept,
    $new,
    $offen,
    $unused !== [] ? sprintf(', %d unbenutzt aufbewahrt', count($unused)) : ''
);
