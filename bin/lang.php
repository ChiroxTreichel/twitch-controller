#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Prueft und pflegt die Sprachdateien.
 *
 *   php bin/lang.php                     Kern pruefen
 *   php bin/lang.php --plugin throne     ein Plugin pruefen
 *   php bin/lang.php --all               Kern und alle Plugins
 *   php bin/lang.php --fix               fehlende Schluessel anlegen (leer)
 *
 * Ohne --plugin wird zusaetzlich gemeldet, was gar nicht erst durch
 * translate() geht - siehe pruefeFestenText().
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
            if ($klammer !== '(') {
                continue;
            }

            // Leerraum und Kommentare zwischen Klammer und Schluessel
            // ueberspringen. Ohne das findet der Pruefer einen Aufruf
            // nicht, dessen Schluessel in der naechsten Zeile steht -
            // und meldet ihn dann als unbenutzt.
            $j = $i + 2;
            while (
                isset($tokens[$j])
                && is_array($tokens[$j])
                && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)
            ) {
                $j++;
            }

            $argument = $tokens[$j] ?? null;

            if (!is_array($argument) || $argument[0] !== T_CONSTANT_ENCAPSED_STRING) {
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
 * Schluessel aus den Sprachdateien der harten Voraussetzungen.
 *
 * Ein Plugin darf Woerter eines Plugins mitbenutzen, das es zwingend
 * braucht: zur Laufzeit sind beide geladen, und der Translator legt
 * ihre Dateien uebereinander. Ohne diese Funktion meldete der Pruefer
 * solche Schluessel als fehlend - und zwang dazu, "An" und "Aus" in
 * jedem Plugin erneut zu uebersetzen.
 *
 * @return array<string, string>
 */
function schluesselDerVoraussetzungen(string $root, string $verzeichnis): array
{
    $manifest = ladeJson($verzeichnis . '/plugin.json');
    $requires = $manifest['requires'] ?? [];

    if (!is_array($requires)) {
        return [];
    }

    $schluessel = [];

    foreach (array_keys($requires) as $slug) {
        $slug = strtolower(trim((string) $slug));

        // "core" ist eine Bedingung an die Kernversion, kein Plugin.
        if ($slug === '' || $slug === 'core') {
            continue;
        }

        $ordner = $root . '/plugins/' . $slug;
        $quelle = is_file($ordner . '/src/plugin.json') ? $ordner . '/src' : $ordner;

        $schluessel += ladeJson($quelle . '/lang/de.json');
    }

    return $schluessel;
}

/**
 * @return int Anzahl der Beanstandungen
 */
function pruefe(string $name, string|array $codeDir, string $langDir, bool $fix, array $zusaetzlich = []): int
{
    $abweichungen = 0;

    // Mehrere Verzeichnisse: der Kern besteht aus core/ UND public/ -
    // der Front-Controller uebersetzt auch. Ohne ihn galten seine
    // Schluessel als unbenutzt.
    $benutzt = [];
    foreach ((array) $codeDir as $verzeichnis) {
        foreach (schluesselIn($verzeichnis) as $key => $anzahl) {
            $benutzt[$key] = ($benutzt[$key] ?? 0) + $anzahl;
        }
    }
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


/**
 * Fest verdrahtete Anzeigetexte.
 *
 * Der Teil oben fragt: steht jeder benutzte Schluessel in der
 * Sprachdatei? Er kann nicht sehen, was gar nicht erst durch
 * translate() geht. Genau dort lagen die letzten Luecken - eine
 * Beschriftungstabelle aus Substantiven faellt keiner Wortsuche auf.
 *
 * Zwei Fragen, absichtlich getrennt:
 *
 *   in den Vorlagen   Text ausserhalb der PHP-Bloecke, also direkt als
 *                     HTML. Ueber token_get_all(), nicht per Regex: in
 *                     core/views/_confirm.php steht ein `?>` mitten in
 *                     einem Kommentar, und jeder Zeichen-Scanner haelt
 *                     danach den Rest der Datei fuer HTML.
 *   im PHP-Code       Zeichenketten an Stellen, die beim Benutzer
 *                     landen: 'label' => …, ->fail(…), Ausnahmen. Nach
 *                     der STELLE gefragt und nicht nach der Sprache -
 *                     so faellt auch ein englischer Text auf.
 */

/** Laeuft vor dem Uebersetzer oder geht nur ins Log. */
const OHNE_UEBERSETZER = [
    'core/Config/Env.php',       // liest die .env als erstes
    'core/Http/View.php',        // fehlende Vorlage: Programmierfehler
    'core/Plugin/Manifest.php',  // der Aufrufer faengt und loggt
    'core/Hook/Hooks.php',       // melde() baut nur eine Logzeile
    // Sprachnamen stehen immer in ihrer eigenen Sprache. «Türkçe» in
    // «Türkisch» zu uebersetzen waere genau falsch: die Liste soll der
    // lesen koennen, der die Oberflaeche noch nicht versteht.
    'core/I18n/Translator.php',
    'bin/worker.php',
    'bin/lang.php',
    'plugins/bin/pack.php',
];

/**
 * Rumpf einer Maschinenantwort.
 *
 * Response::text() mit Statuscode liest kein Mensch: Twitch bekommt es
 * beim Webhook, der Browser beim Nachladen einer Plugin-Datei. Wer es
 * doch zu sehen bekommt, sieht die Fehlerseite des Browsers. Sie
 * bleiben bei der Schreibweise aus dem HTTP-Standard.
 */
const PROTOKOLLTEXTE = ['Not Found', 'Method Not Allowed', 'Bad signature'];

/**
 * Was Twitch selbst so schreibt.
 *
 * Wer im Stream «Tier 1» sagt, sagt es auf Deutsch auch so - eine
 * Uebersetzung waere eine Erfindung. Der Filterbaum im Feed
 * (Obs/Filters) benutzt darum durchgehend die Vokabeln der Plattform.
 *
 * Beschreibende Woerter im selben Baum sind dagegen uebersetzt:
 * «Gesendet», «Empfangen», «Sonstiges». Die Grenze ist nicht die
 * Sprache, sondern die Herkunft - steht das Wort so auf Twitch, bleibt
 * es stehen.
 */
const TWITCH_BEGRIFFE = [
    'Follow', 'Follows', 'Bits', 'Subs', 'Prime', 'Tiered', 'Gifted',
    'Tier 1', 'Tier 2', 'Tier 3', 'Raid', 'Raids',
];

/**
 * Technische Namen, die keine Uebersetzung haben.
 *
 * Namen von Umgebungsvariablen und Protokollen. «APP_KEY» heisst in
 * jeder Sprache APP_KEY - das steht so in der .env, und wer die
 * Einrichtung durchgeht, sucht genau diese Zeichenfolge.
 */
const TECHNISCHE_NAMEN = ['APP_URL', 'APP_KEY', 'HTTPS'];

const DEUTSCH = '/[\x{00e4}\x{00f6}\x{00fc}\x{00c4}\x{00d6}\x{00dc}\x{00df}]'
    . '|(?:^|\s)(?:der|die|das|den|dem|des|nicht|ist|sind|wird|werden|kann|'
    . 'muss|bitte|und|oder|noch|schon|dann|wenn|weil|wurde|keine|kein|dir|'
    . 'dich|deine|eine|einen|einem|fuer|mit|ohne|vom|zum|zur|bei|auf|aus|'
    . 'nach|jetzt|bereits|Fehler|Hinweis)(?:\s|[.,!?:;]|$)/iu';

/** Stellen, an denen ein Text beim Benutzer landet. */
const ANZEIGESTELLEN = [
    ['/\'(?:label|title|heading|message|detail|summary|question|confirm|placeholder)\'\s*=>\s*\'([^\']{2,})\'/', 'Anzeigefeld'],
    ['/->back\([^)]*?\'([^\']{4,})\'/', 'Meldung'],
    ['/->fail\(\'([^\']{4,})\'/', 'Meldung'],
    ['/Response::text\(\'([^\']{4,})\'/', 'Antworttext'],
    ['/new (?:RuntimeException|InvalidArgumentException|LogicException)\(\'([^\']{4,})\'/', 'Ausnahme'],
];

/** Schluessel, Klassennamen, Zahlen - kein Anzeigetext. */
const HARMLOS = '/^(?:[a-z0-9_.:\/-]+|[A-Z][A-Za-z0-9_]*|%\{[a-z_]+\}|[0-9.,\s-]+)$/';

/**
 * Dasselbe ohne den Zweig fuer Klassennamen.
 *
 * An einer Beschriftungsstelle steht kein Klassenname, sondern ein
 * Wort fuer den Benutzer - und genau dieser Zweig hat
 * `'title' => 'Beispiel'` im Beispiel-Plugin durchgelassen. Was hier
 * echt bleiben soll, gehoert nach TWITCH_BEGRIFFE.
 */
const HARMLOS_BESCHRIFTUNG = '/^(?:[a-z0-9_.:\/-]+|%\{[a-z_]+\}|[0-9.,\s-]+)$/';

/**
 * PHP-Dateien unter den Wurzeln, ohne die ausgenommenen.
 *
 * @param list<string> $wurzeln
 * @return list<string>
 */
function dateien(array $wurzeln, string $mussEnthalten): array
{
    $root = str_replace(DIRECTORY_SEPARATOR, '/', dirname(__DIR__));
    $gefunden = [];

    foreach ($wurzeln as $wurzel) {
        if (!is_dir($wurzel)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($wurzel, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $eintrag) {
            $pfad = str_replace(DIRECTORY_SEPARATOR, '/', $eintrag->getPathname());

            if (!str_ends_with($pfad, '.php') || str_contains($pfad, '/.git/')) {
                continue;
            }
            if ($mussEnthalten !== '' && !str_contains($pfad, $mussEnthalten)) {
                continue;
            }

            $relativ = str_starts_with($pfad, $root . '/')
                ? substr($pfad, strlen($root) + 1)
                : $pfad;

            if (in_array($relativ, OHNE_UEBERSETZER, true)) {
                continue;
            }

            $gefunden[] = $pfad;
        }
    }

    sort($gefunden);

    return $gefunden;
}

/**
 * Deutscher Text in einer Vorlage, ausserhalb der PHP-Bloecke.
 *
 * @param list<string> $wurzeln
 * @return list<array{0: string, 1: int, 2: string}>
 */
function vorlagenTexte(array $wurzeln): array
{
    $treffer = [];

    foreach (dateien($wurzeln, '/views/') as $pfad) {
        $zeilen = [];

        foreach (token_get_all((string) file_get_contents($pfad)) as $token) {
            if (!is_array($token) || $token[0] !== T_INLINE_HTML) {
                continue;
            }

            foreach (explode("\n", $token[1]) as $i => $stueck) {
                $nummer = $token[2] + $i;
                $zeilen[$nummer] = ($zeilen[$nummer] ?? '') . $stueck;
            }
        }

        ksort($zeilen);

        // Skript- und Stilbloecke ueberspringen: dort steht CSS oder
        // JavaScript, und Text kommt ueber json_encode(translate(…)).
        $imBlock = false;

        foreach ($zeilen as $nummer => $html) {
            $oeffnet = (bool) preg_match('/<(script|style)\b[^>]*>(?!.*<\/\1>)/is', $html);
            $schliesst = (bool) preg_match('/<\/(script|style)>/i', $html);

            if ($imBlock) {
                $imBlock = !$schliesst;
                continue;
            }
            if ($oeffnet) {
                $imBlock = true;
                continue;
            }

            $html = (string) preg_replace('/<(script|style)\b.*?<\/\1>/is', ' ', $html);
            $html = (string) preg_replace('/<!--.*?-->/s', ' ', $html);
            $html = (string) preg_replace('/<[^>]*>/', ' ', $html);
            $html = (string) preg_replace('/&[a-zA-Z]+;|&#\d+;/', ' ', $html);
            $html = trim($html);

            if (strlen($html) < 3 || !preg_match(DEUTSCH, $html)) {
                continue;
            }

            $treffer[] = [$pfad, $nummer, $html];
        }
    }

    return $treffer;
}

/**
 * Zeichenketten an Stellen, die beim Benutzer landen.
 *
 * @param list<string> $wurzeln
 * @return list<array{0: string, 1: int, 2: string}>
 */
function anzeigeTexte(array $wurzeln): array
{
    $treffer = [];

    foreach (dateien($wurzeln, '') as $pfad) {
        $imBlock = false;

        foreach (explode("\n", (string) file_get_contents($pfad)) as $i => $zeile) {
            $nackt = trim($zeile);

            if ($imBlock) {
                $imBlock = !str_contains($nackt, '*/');
                continue;
            }
            if ($nackt === '' || $nackt[0] === '*'
                || str_starts_with($nackt, '//') || str_starts_with($nackt, '#')) {
                continue;
            }
            if (str_contains($nackt, '/*') && !str_contains($nackt, '*/')) {
                $imBlock = true;
                continue;
            }
            // Logzeilen gehen ins Log des Containers, nicht zum Benutzer.
            if (str_contains($zeile, '->log(')) {
                continue;
            }

            foreach (ANZEIGESTELLEN as [$regex, $art]) {
                if (!preg_match_all($regex, $zeile, $saetze, PREG_SET_ORDER)) {
                    continue;
                }

                foreach ($saetze as $satz) {
                    $wert = $satz[1];

                    $harmlos = $art === 'Anzeigefeld' ? HARMLOS_BESCHRIFTUNG : HARMLOS;

                    if (preg_match($harmlos, $wert)) {
                        continue;
                    }
                    if ($art === 'Antworttext' && in_array($wert, PROTOKOLLTEXTE, true)) {
                        continue;
                    }
                    if (in_array($wert, TWITCH_BEGRIFFE, true)) {
                        continue;
                    }
                    if (in_array($wert, TECHNISCHE_NAMEN, true)) {
                        continue;
                    }

                    $treffer[] = [$pfad, $i + 1, $art . ': ' . $wert];
                }
            }
        }
    }

    return $treffer;
}

/**
 * Deutsche Woerter in einer beliebigen Zeichenkette.
 *
 * Die Stellenliste oben kennt nur bekannte Muster. Ein nacktes
 * `return 'Alles ist bereits aktuell.';` faellt durch - das hat die
 * Gegenprobe gezeigt. Diese Pruefung fragt darum wieder nach der
 * Sprache, aber ueber die Tokens: Kommentare sind damit von sich aus
 * draussen, und nur das steht drin, was PHP als Zeichenkette liest.
 *
 * @param list<string> $wurzeln
 * @return list<array{0: string, 1: int, 2: string}>
 */
function deutscheTexte(array $wurzeln): array
{
    $treffer = [];

    foreach (dateien($wurzeln, '') as $pfad) {
        $tokens = token_get_all((string) file_get_contents($pfad));

        // Argumente von log() ueberspringen: die gehen ins Log des
        // Containers, nicht zum Benutzer.
        $imLog = 0;
        $klammern = 0;

        foreach ($tokens as $i => $token) {
            if ($token === '(') {
                $klammern++;
                continue;
            }
            if ($token === ')') {
                $klammern--;
                if ($imLog > 0 && $klammern < $imLog) {
                    $imLog = 0;
                }
                continue;
            }

            if (is_array($token) && $token[0] === T_STRING && $token[1] === 'log') {
                $imLog = $klammern + 1;
                continue;
            }

            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if ($imLog > 0) {
                continue;
            }

            $wert = substr($token[1], 1, -1);

            if (strlen($wert) < 8 || !preg_match(DEUTSCH, $wert)) {
                continue;
            }
            // SQL und Pfade sind kein Anzeigetext.
            if (preg_match('/^(?:SELECT|INSERT|UPDATE|DELETE|CREATE|ALTER|DROP)\b/i', $wert)) {
                continue;
            }
            if (str_contains($wert, '/') || str_contains($wert, DIRECTORY_SEPARATOR)) {
                continue;
            }
            // Schon uebersetzt: der Schluessel steht direkt hinter
            // translate( - dann ist die Zeichenkette der Schluessel.
            if (istSchluessel($tokens, $i)) {
                continue;
            }

            $treffer[] = [$pfad, $token[2], 'Text: ' . $wert];
        }
    }

    return $treffer;
}

/**
 * Steht diese Zeichenkette als erstes Argument in translate()?
 *
 * @param list<array{0: int, 1: string, 2: int}|string> $tokens
 */
function istSchluessel(array $tokens, int $stelle): bool
{
    for ($i = $stelle - 1; $i >= 0 && $i >= $stelle - 3; $i--) {
        $token = $tokens[$i];

        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        if ($token === '(') {
            continue;
        }
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'translate') {
            return true;
        }

        return false;
    }

    return false;
}

/**
 * @return int Anzahl der Beanstandungen
 */
function pruefeFestenText(string $root): int
{
    $wurzeln = [
        $root . '/core',
        $root . '/public',
        $root . '/bin',
        $root . '/plugins',
    ];

    $treffer = array_merge(
        vorlagenTexte($wurzeln),
        anzeigeTexte($wurzeln),
        deutscheTexte($wurzeln)
    );

    // Dieselbe Stelle kann in zwei Pruefungen auffallen.
    $treffer = array_values(array_unique($treffer, SORT_REGULAR));
    usort($treffer, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

    printf("\nFest verdrahtete Texte\n");

    if ($treffer === []) {
        printf("  keine - alle Anzeigetexte laufen über translate()\n");

        return 0;
    }

    foreach ($treffer as [$pfad, $nummer, $text]) {
        $relativ = str_starts_with($pfad, $root . '/')
            ? substr($pfad, strlen($root) + 1)
            : $pfad;

        printf("  %s:%d  %s\n", $relativ, $nummer, $text);
    }

    return count($treffer);
}

$beanstandungen = 0;

if ($plugin !== '') {
    $ordner = $root . '/plugins/' . $plugin;
    $verzeichnis = is_file($ordner . '/src/plugin.json') ? $ordner . '/src' : $ordner;

    $beanstandungen += pruefe(
        "Plugin {$plugin}",
        $verzeichnis,
        $verzeichnis . '/lang',
        $fix,
        ladeJson($root . '/lang/de.json')
            + schluesselDerVoraussetzungen($root, $verzeichnis)
    );
} else {
    $beanstandungen += pruefe(
        'Kern',
        [$root . '/core', $root . '/public'],
        $root . '/lang',
        $fix
    );

    if ($alle) {
        $kern = ladeJson($root . '/lang/de.json');

        // plugins/ hat zwei Gestalten: auf einer Installation liegt
        // dort das entpackte Plugin, waehrend der Entwicklung das
        // Plugin-Repository mit dem Quellcode unter <slug>/src/. Je
        // Plugin gilt genau ein Verzeichnis - sonst wird der Ordner
        // daneben, der nur die Katalogangaben haelt, als Plugin ohne
        // Sprachdatei gemeldet.
        foreach (glob($root . '/plugins/*', GLOB_ONLYDIR) ?: [] as $ordner) {
            $slug = basename($ordner);

            $verzeichnis = is_file($ordner . '/src/plugin.json')
                ? $ordner . '/src'
                : $ordner;

            if (!is_file($verzeichnis . '/plugin.json')) {
                continue;
            }

            $beanstandungen += pruefe(
                'Plugin ' . $slug,
                $verzeichnis,
                $verzeichnis . '/lang',
                $fix,
                $kern + schluesselDerVoraussetzungen($root, $verzeichnis)
            );
        }
    }
}

// Ohne --plugin auch das pruefen, was gar nicht erst durch
// translate() geht.
if ($plugin === '') {
    $beanstandungen += pruefeFestenText(
        str_replace(DIRECTORY_SEPARATOR, '/', $root)
    );
}

printf("\n%s\n", $beanstandungen === 0
    ? 'Nichts zu beanstanden.'
    : sprintf('%d Beanstandung(en).', $beanstandungen));

exit($beanstandungen === 0 ? 0 : 1);
