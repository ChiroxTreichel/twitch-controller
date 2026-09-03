#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Prueft und pflegt die Sprachdateien.
 *
 *   php bin/lang.php                     Kern pruefen
 *   php bin/lang.php --plugin example     ein Plugin pruefen
 *   php bin/lang.php --all               Kern und alle Plugins
 *   php bin/lang.php --fix               fehlende Schluessel anlegen (leer)
 *
 * Geprueft wird gegen lang/de.json - das ist die Grundlage, in der jeder
 * Schluessel stehen muss. Gemeldet wird:
 *
 *   fehlend    im Code benutzt, aber in de.json nicht vorhanden
 *              -> in der Oberflaeche erscheint der nackte Schluessel
 *   unbenutzt  in de.json, im Code aber nirgends
 *   offen      in einer Uebersetzung noch leer (dort greift Deutsch)
 *
 * Schluessel, die aus einer Variablen kommen, kann dieses Werkzeug nicht
 * sehen - im Code deshalb immer ausschreiben.
 */

$root = dirname(__DIR__);

$plugin = '';
$alle = false;
$fix = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = (string) $argv[$i];

    if ($arg === '--plugin' && isset($argv[$i + 1])) {
        $plugin = strtolower((string) $argv[$i + 1]);
        $i++;
        continue;
    }

    if ($arg === '--all') {
        $alle = true;
        continue;
    }

    if ($arg === '--fix') {
        $fix = true;
        continue;
    }

    if ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "php bin/lang.php [--plugin <slug>] [--all] [--fix]\n");
        exit(0);
    }
}

/**
 * Alle translate()-Schluessel in einem Verzeichnis.
 *
 * Ueber die PHP-Tokens statt per Regex: nur so wird ein Aufruf im
 * Kommentar nicht mitgezaehlt.
 *
 * @return array<string, int> Schluessel => Anzahl der Aufrufe
 */
function schluesselIn(string $verzeichnis): array
{
    if (!is_dir($verzeichnis)) {
        return [];
    }

    $gefunden = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($verzeichnis, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $item) {
        /** @var SplFileInfo $item */
        if (!$item->isFile() || strtolower($item->getExtension()) !== 'php') {
            continue;
        }

        $tokens = @token_get_all((string) file_get_contents($item->getPathname()));
        if (!is_array($tokens)) {
            continue;
        }

        $anzahl = count($tokens);

        for ($i = 0; $i < $anzahl; $i++) {
            $token = $tokens[$i];

            if (!is_array($token) || $token[0] !== T_STRING || strtolower($token[1]) !== 'translate') {
                continue;
            }

            $davor = $tokens[$i - 1] ?? null;
            if (is_array($davor) && in_array($davor[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
                continue;
            }

            $klammer = $tokens[$i + 1] ?? null;
            $argument = $tokens[$i + 2] ?? null;

            if ($klammer !== '(' || !is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $key = substr($argument[1], 1, -1);
            if ($key === '') {
                continue;
            }

            $gefunden[$key] = ($gefunden[$key] ?? 0) + 1;
        }
    }

    return $gefunden;
}

/**
 * @return array<string, string>
 */
function ladeJson(string $datei): array
{
    if (!is_file($datei)) {
        return [];
    }

    $daten = json_decode((string) file_get_contents($datei), true);

    return is_array($daten) ? $daten : [];
}

/**
 * @param array<string, string> $daten
 */
function schreibeJson(string $datei, array $daten): void
{
    $verzeichnis = dirname($datei);
    if (!is_dir($verzeichnis) && !mkdir($verzeichnis, 0775, true) && !is_dir($verzeichnis)) {
        fwrite(STDERR, "Konnte {$verzeichnis} nicht anlegen.\n");
        exit(1);
    }

    ksort($daten);
    file_put_contents(
        $datei,
        json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
    );
}

/**
 * Platzhalter eines Textes, sortiert - benannte wie positionelle.
 *
 * @return list<string>
 */
function platzhalter(string $text): array
{
    preg_match_all('/%[{][a-zA-Z0-9_]+[}]|%[sd]/', $text, $treffer);

    $gefunden = $treffer[0];
    sort($gefunden);

    return $gefunden;
}

/**
 * @return int Anzahl der Beanstandungen
 */
function pruefe(string $name, string $codeDir, string $langDir, bool $fix, array $zusaetzlich = []): int
{
    $abweichungen = 0;
    $benutzt = schluesselIn($codeDir);
    $basis = ladeJson($langDir . '/de.json');

    // Ein Plugin darf Kern-Schluessel mitbenutzen - die gelten hier als
    // vorhanden, auch wenn sie nicht in der Plugin-Datei stehen.
    $vorhanden = $basis + $zusaetzlich;

    $fehlend = array_keys(array_diff_key($benutzt, $vorhanden));
    $unbenutzt = array_keys(array_diff_key($basis, $benutzt));

    printf("\n%s\n", $name);
    printf("  %d Schlüssel im Code, %d in de.json\n", count($benutzt), count($basis));

    if ($fehlend !== []) {
        printf("  %d FEHLEN in de.json:\n", count($fehlend));
        sort($fehlend);
        foreach ($fehlend as $key) {
            printf("      %s\n", $key);
        }

        if ($fix) {
            foreach ($fehlend as $key) {
                $basis[$key] = '';
            }
            schreibeJson($langDir . '/de.json', $basis);
            printf("  -> mit leerem Wert in de.json angelegt\n");
        }
    }

    if ($unbenutzt !== []) {
        printf("  %d unbenutzt in de.json:\n", count($unbenutzt));
        sort($unbenutzt);
        foreach ($unbenutzt as $key) {
            printf("      %s\n", $key);
        }
    }

    // Uebersetzungen: was ist noch offen?
    foreach (glob($langDir . '/*.json') ?: [] as $datei) {
        $code = basename($datei, '.json');
        if ($code === 'de') {
            continue;
        }

        $uebersetzung = ladeJson($datei);
        $offen = array_keys(array_diff_key($basis, array_filter($uebersetzung)));

        printf(
            "  %s: %d von %d übersetzt%s\n",
            $code,
            count($basis) - count($offen),
            count($basis),
            $offen === [] ? '' : sprintf(', %d offen', count($offen))
        );

        // Platzhalter muessen in jeder Sprache dieselben sein. Stimmen
        // sie nicht, setzt die Uebersetzung Werte an der falschen Stelle
        // ein oder laesst sie weg - und wer den Aufruf schreibt, merkt
        // es nicht, weil er nur den deutschen Text vor Augen hat.
        foreach ($uebersetzung as $key => $text) {
            if (!is_string($text) || $text === '' || !isset($basis[$key])) {
                continue;
            }

            $hier = platzhalter($text);
            $dort = platzhalter($basis[$key]);

            if ($hier !== $dort) {
                printf(
                    "      ! %s: Platzhalter weichen ab (de: %s / %s: %s)\n",
                    $key,
                    implode(' ', $dort) ?: '–',
                    $code,
                    implode(' ', $hier) ?: '–'
                );
                $abweichungen++;
            }
        }

        if ($fix && $offen !== []) {
            foreach ($offen as $key) {
                $uebersetzung[$key] ??= '';
            }
            schreibeJson($datei, $uebersetzung);
        }
    }

    return count($fehlend) + $abweichungen;
}

$beanstandungen = 0;

if ($plugin !== '') {
    $beanstandungen += pruefe(
        "Plugin {$plugin}",
        $root . '/plugins/' . $plugin,
        $root . '/plugins/' . $plugin . '/lang',
        $fix,
        ladeJson($root . '/lang/de.json')
    );
} else {
    $beanstandungen += pruefe('Kern', $root . '/core', $root . '/lang', $fix);

    if ($alle) {
        $kern = ladeJson($root . '/lang/de.json');

        foreach (glob($root . '/plugins/*/plugin.json') ?: [] as $manifest) {
            $verzeichnis = dirname($manifest);
            $beanstandungen += pruefe(
                'Plugin ' . basename($verzeichnis),
                $verzeichnis,
                $verzeichnis . '/lang',
                $fix,
                $kern
            );
        }
    }
}

printf("\n%s\n", $beanstandungen === 0
    ? 'Keine fehlenden Schlüssel.'
    : sprintf('%d Schlüssel fehlen.', $beanstandungen));

exit($beanstandungen === 0 ? 0 : 1);
