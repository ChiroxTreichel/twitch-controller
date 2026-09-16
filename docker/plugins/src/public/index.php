<?php

declare(strict_types=1);

/**
 * ===================================================================
 *  Der Katalogserver
 * ===================================================================
 *
 * Diese Datei IST der Katalog. Sie liest bei jedem Aufruf das
 * Plugin-Repository neben sich und baut daraus die Antwort - es gibt
 * keine erzeugte index.json, die jemand nach einem Paket vergessen
 * koennte nachzuziehen.
 *
 * Veroeffentlichen heisst deshalb: packen, pushen, auf dem
 * Katalogserver "git pull" im Plugin-Repository. Mehr passiert nicht.
 *
 * Gesucht wird EBENFALLS hier und nicht beim Aufrufer. Der Controller
 * haengt ein ?search= an und erwartet eine gefilterte Liste - so geht
 * nur ueber die Leitung, was gebraucht wird. Siehe
 * core/Registry/Client.php::refresh().
 *
 * Bewusst index.php und nicht index.json: die Datei antwortet auch,
 * bevor hier jemals ein Paket veroeffentlicht wurde, und sie braucht
 * kein mod_rewrite. Ein leerer Katalog ist ein gueltiger Katalog.
 *
 * Aufbau:
 *
 *   src/public/index.php    diese Datei - und das ganze Docroot
 *   src/plugins/<slug>/     das geklonte Plugin-Repository
 *
 * Die Pakete liegen also NEBEN dem Docroot und nicht darin. Damit ist
 * der Quelltext der Plugins gar nicht erst erreichbar, statt ihn im
 * Auslieferer wieder aussperren zu muessen.
 */

/** Katalogformat. Muss zu Client::FORMAT passen. */
const FORMAT = 1;

/** Mehr Treffer als das schickt niemand ueber die Leitung. */
const MAX_RESULTS = 200;

/** Wo das Plugin-Repository liegt - eine Ebene neben dem Docroot. */
const PLUGIN_ROOT = __DIR__ . '/../plugins';

header('Content-Type: application/json; charset=utf-8');

// Der Katalog ist oeffentlich und wird von jeder Installation
// abgefragt - aber nie aus einem Browser heraus, der schon woanders
// angemeldet ist. Darum kein CORS-Freibrief.
header('X-Content-Type-Options: nosniff');

// Nicht zwischenspeichern: wer den Katalog abfragt, will wissen, was
// JETZT da ist. Ein eine Minute alter Stand heisst, dass ein frisch
// veroeffentlichtes Paket noch nicht sichtbar ist.
header('Cache-Control: no-store');

/**
 * Die Adresse, unter der dieser Server erreichbar ist.
 *
 * "download" muss eine vollstaendige http(s)-Adresse sein, sonst wirft
 * der Controller den Eintrag weg - und "readme" muss auf DEMSELBEN
 * Host liegen, sonst holt er sie gar nicht erst.
 *
 * Vorrang hat CATALOG_BASE_URL aus der Umgebung. Ohne die wird die
 * Adresse aus der Anfrage gebaut, und dann zaehlen die Kopfzeilen des
 * Proxys: hinter Nginx Proxy Manager kommt die Anfrage als http an,
 * nach draussen ist es https. Stuende hier http, zeigten alle
 * Download-Adressen ins Leere.
 */
function basisAdresse(): string
{
    $gesetzt = trim((string) (getenv('CATALOG_BASE_URL') ?: ''));

    if ($gesetzt !== '') {
        return rtrim($gesetzt, '/');
    }

    $schema = 'http';

    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        $schema = 'https';
    } elseif (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') !== '') {
        $schema = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_PROTO'])[0];
    }

    $host = (string) ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    $host = trim(explode(',', $host)[0]);

    return $schema . '://' . $host;
}

/**
 * Ein Ordnername, der ein Plugin sein darf.
 *
 * Dieselbe Form, die der Controller in Client::normalize() verlangt -
 * was hier nicht durchkommt, wuerde dort stillschweigend verworfen.
 */
function istSlug(string $name): bool
{
    return preg_match('/^[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]$/', $name) === 1;
}

/**
 * Ein einzelnes Plugin einlesen.
 *
 * Gibt null zurueck, wenn etwas fehlt oder nicht zusammenpasst. Ein
 * halber Eintrag ist schlimmer als keiner: der Controller zeigte ihn
 * im Marktplatz an, und die Installation schluege erst beim Klick
 * fehl.
 *
 * @return array<string, mixed>|null
 */
function plugin(string $ordner, string $basis): ?array
{
    $slug = basename($ordner);

    $manifestDatei = $ordner . '/plugin.json';
    $paketDatei = $ordner . '/' . $slug . '.zip';

    if (!is_file($manifestDatei) || !is_file($paketDatei)) {
        return null;
    }

    $manifest = json_decode((string) file_get_contents($manifestDatei), true);

    if (!is_array($manifest)) {
        return null;
    }

    // Der Ordner entscheidet, nicht das Manifest. Beides muss aber
    // uebereinstimmen - sonst laedt der Controller ein Paket herunter
    // und findet darin ein anderes Plugin.
    if (strtolower(trim((string) ($manifest['slug'] ?? ''))) !== $slug) {
        return null;
    }

    $version = trim((string) ($manifest['version'] ?? ''));

    if (preg_match('/^\d+\.\d+\.\d+/', $version) !== 1) {
        return null;
    }

    // Die Pruefsumme ist PFLICHT: der Controller verweigert die
    // Installation, wenn sie fehlt (Registry\Installer::verifyChecksum).
    // Sie wird hier gerechnet und nicht abgelegt - eine abgelegte
    // Pruefsumme, die keiner nachzieht, ist schlimmer als keine.
    $pruefsumme = hash_file('sha256', $paketDatei);

    if (!is_string($pruefsumme)) {
        return null;
    }

    $eintrag = [
        'slug'        => $slug,
        'name'        => trim((string) ($manifest['name'] ?? $slug)),
        'version'     => $version,
        'description' => trim((string) ($manifest['description'] ?? '')),
        'author'      => trim((string) ($manifest['author'] ?? '')),
        'tags'        => is_array($manifest['tags'] ?? null)
            ? array_values(array_map('strval', $manifest['tags']))
            : [],
        'requires'    => is_array($manifest['requires'] ?? null) ? $manifest['requires'] : [],
        'optional'    => is_array($manifest['optional'] ?? null) ? $manifest['optional'] : [],
        // Muss schon im Katalog stehen: die Sperre soll greifen, BEVOR
        // heruntergeladen wird - vorher gibt es kein plugin.json.
        'conflicts'   => is_array($manifest['conflicts'] ?? null) ? $manifest['conflicts'] : [],
        'download'    => $basis . '/' . $slug . '/' . $slug . '.zip',
        'sha256'      => $pruefsumme,
        'size'        => (int) filesize($paketDatei),
        // Der Stand ist zugleich der Schluessel, unter dem der
        // Controller die Beschreibung zwischenlagert. Er MUSS sich
        // aendern, wenn sich das Paket aendert - darum die Zeit des
        // Pakets und nicht die Version: ein erneutes Packen ohne
        // Versionssprung waere sonst unsichtbar.
        'updated_at'  => gmdate('c', (int) filemtime($paketDatei)),
    ];

    // Die Beschreibungsseite. Nur verlinken, was es gibt - der
    // Controller meldet sonst einen Abruffehler auf der Seite.
    if (is_file($ordner . '/README.md')) {
        $eintrag['readme'] = $basis . '/' . $slug . '/README.md';
    }

    return $eintrag;
}

/**
 * Passt ein Eintrag zum Suchbegriff?
 *
 * Gesucht wird ueber Name, Slug, Beschreibung und Schlagworte. Ohne
 * Begriff passt alles.
 */
function passt(array $eintrag, string $suche): bool
{
    if ($suche === '') {
        return true;
    }

    $heuhaufen = strtolower(implode(' ', [
        (string) $eintrag['slug'],
        (string) $eintrag['name'],
        (string) $eintrag['description'],
        implode(' ', $eintrag['tags']),
    ]));

    // Mehrere Woerter muessen ALLE vorkommen - "goals twitch" soll
    // nicht alles finden, was eines von beiden enthaelt.
    foreach (preg_split('/\s+/', $suche) ?: [] as $wort) {
        if ($wort !== '' && !str_contains($heuhaufen, $wort)) {
            return false;
        }
    }

    return true;
}

// -------------------------------------------------------------------
//  Antwort
// -------------------------------------------------------------------
$basis = basisAdresse();
$suche = strtolower(trim((string) ($_GET['search'] ?? '')));

// Laenger als das ist kein Suchbegriff mehr.
if (strlen($suche) > 100) {
    $suche = substr($suche, 0, 100);
}

$plugins = [];

// Ist noch nichts geklont, ist der Katalog leer - und das ist ein
// gueltiger Katalog. Der Controller soll dann "nichts veroeffentlicht"
// anzeigen und keine Fehlermeldung.
foreach (@scandir(PLUGIN_ROOT) ?: [] as $name) {
    if (!istSlug($name) || !is_dir(PLUGIN_ROOT . '/' . $name)) {
        continue;
    }

    $eintrag = plugin(PLUGIN_ROOT . '/' . $name, $basis);

    if ($eintrag !== null && passt($eintrag, $suche)) {
        $plugins[] = $eintrag;
    }
}

// Nach Namen, damit die Liste im Marktplatz eine Ordnung hat, die
// nicht von der Dateisystemreihenfolge abhaengt.
//
// Nicht mit strcasecmp: das vergleicht Bytes, und ein ö sind in UTF-8
// zwei davon, beide groesser als jeder Buchstabe. Sobald zwei Namen
// bis zum Umlaut gleich anfangen, stuende der mit dem Umlaut hinten -
// "Chat - Löschbot" hinter "Chat - Lupe". Und weil gleich danach auf
// MAX_RESULTS gekuerzt wird, entscheidet die Sortierung nicht nur
// ueber die Reihenfolge, sondern darueber, WER noch mitkommt.
// Gefaltet wird vor dem Kleinschreiben, und beide Schreibweisen stehen
// in der Tabelle: strtolower() kennt nur ASCII. Danach ist alles
// Uebriggebliebene ASCII - mbstring liegt in diesem Bild nicht bei.
$schluessel = static fn (string $name): string => strtolower(strtr($name, [
    'Ä' => 'a', 'ä' => 'a', 'Ö' => 'o', 'ö' => 'o',
    'Ü' => 'u', 'ü' => 'u', 'ß' => 'ss',
    'Á' => 'a', 'á' => 'a', 'À' => 'a', 'à' => 'a',
    'Â' => 'a', 'â' => 'a', 'Å' => 'a', 'å' => 'a',
    'É' => 'e', 'é' => 'e', 'È' => 'e', 'è' => 'e',
    'Ê' => 'e', 'ê' => 'e',
    'Í' => 'i', 'í' => 'i', 'Ì' => 'i', 'ì' => 'i',
    'Î' => 'i', 'î' => 'i',
    'Ó' => 'o', 'ó' => 'o', 'Ò' => 'o', 'ò' => 'o',
    'Ô' => 'o', 'ô' => 'o', 'Ø' => 'o', 'ø' => 'o',
    'Ú' => 'u', 'ú' => 'u', 'Ù' => 'u', 'ù' => 'u',
    'Û' => 'u', 'û' => 'u',
    'Ç' => 'c', 'ç' => 'c', 'Ñ' => 'n', 'ñ' => 'n',
]));

usort($plugins, static fn (array $a, array $b): int
    => strcmp($schluessel($a['name']), $schluessel($b['name'])));

echo json_encode(
    [
        'format'  => FORMAT,
        'plugins' => array_slice($plugins, 0, MAX_RESULTS),
    ],
    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
), "\n";
