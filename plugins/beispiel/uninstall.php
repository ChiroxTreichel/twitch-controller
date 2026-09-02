<?php

declare(strict_types=1);

/**
 * Laeuft beim Entfernen des Plugins. Hier raeumt das Plugin seine
 * eigenen Tabellen ab.
 *
 * Die Einstellungen unter plugin:<slug> loescht der Kern selbst - die
 * muessen hier nicht angefasst werden.
 *
 * Verfuegbar: $db, $settings, $plugin, $fromVersion (installierte Version)
 */

/** @var \Overlays\Core\Database\Db $db */

$db->run('DROP TABLE IF EXISTS beispiel_notizen');
