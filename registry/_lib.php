<?php

declare(strict_types=1);

/**
 * Gemeinsames Kleinzeug der drei Endpunkte.
 *
 * Diese vier Dateien kommen in den DocumentRoot:
 *
 *   /srv/raid/plugins.talutah.de/web/html/
 *       _lib.php
 *       index.php       der Katalog
 *       download.php    ?name=<slug>  das ZIP
 *       readme.php      ?name=<slug>  die Beschreibung
 *
 * Das Plugin-Repository liegt daneben, ausserhalb des DocumentRoots -
 * darin stehen plugin.php und install.php jedes Plugins, und Apache
 * wuerde sie ausfuehren, waeren sie erreichbar.
 */

/**
 * Wo das Plugin-Repository liegt. Die einzige Stelle zum Anpassen.
 */
const PLUGINS_DIR = __DIR__ . '/../plugins';

/**
 * Ordner eines Plugins, oder null.
 *
 * Der Slug landet in einem Dateipfad, wird also nicht bereinigt,
 * sondern abgelehnt, wenn er nicht genau die erlaubte Form hat.
 */
function plugin_ordner(string $slug): ?string
{
    $slug = strtolower(trim($slug));

    if (preg_match('/^[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]$/', $slug) !== 1) {
        return null;
    }

    $ordner = PLUGINS_DIR . '/' . $slug;

    return is_file($ordner . '/plugin.json') ? $ordner : null;
}

/**
 * Slug aus ?name=, oder Abbruch mit einer klaren Meldung.
 */
function slug_aus_anfrage(): string
{
    $slug = strtolower(trim((string) ($_GET['name'] ?? '')));

    if ($slug === '') {
        fehler(400, 'Erwartet: ?name=<slug>');
    }

    if (plugin_ordner($slug) === null) {
        fehler(404, 'Dieses Plugin gibt es hier nicht.');
    }

    return $slug;
}

function fehler(int $status, string $meldung): never
{
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
    echo $meldung . "\n";
    exit;
}

/**
 * Basis-Adresse dieser Installation, fuer die erzeugten Links.
 */
function basis(): string
{
    $schema = 'http';
    if (($_SERVER['HTTPS'] ?? 'off') !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        $schema = 'https';
    }

    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string) ($_SERVER['HTTP_HOST'] ?? '')) ?: 'localhost';

    return $schema . '://' . $host;
}

/**
 * Kleinschreibung, notfalls ohne mbstring - laeuft auch auf einer
 * karg gebauten PHP-Installation.
 */
function kleinschrift(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
}
