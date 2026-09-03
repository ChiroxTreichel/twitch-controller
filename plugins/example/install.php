<?php

declare(strict_types=1);

/**
 * Laeuft beim Installieren und bei jedem Versionswechsel. Muss deshalb
 * idempotent sein - also "IF NOT EXISTS" statt blind anlegen.
 *
 * Verfuegbar:
 *   $db          Overlays\Core\Database\Db
 *   $settings    Overlays\Core\Config\Settings
 *   $plugin      Overlays\Core\Plugin\Manifest
 *   $fromVersion vorher installierte Version, null bei Erstinstallation
 *
 * Tabellennamen mit dem Slug praefixen, damit sich zwei Plugins nicht
 * in die Quere kommen.
 */

/** @var \Overlays\Core\Database\Db $db */
/** @var \Overlays\Core\Config\Settings $settings */
/** @var \Overlays\Core\Plugin\Manifest $plugin */
/** @var string|null $fromVersion */

$db->run('
    CREATE TABLE IF NOT EXISTS example_notes (
        id         BIGSERIAL   PRIMARY KEY,
        text       TEXT        NOT NULL,
        created_at TIMESTAMPTZ NOT NULL DEFAULT now()
    )
');

// Standardwerte nur bei der Erstinstallation setzen, damit ein Update
// nicht die Einstellungen des Nutzers ueberschreibt.
if ($fromVersion === null) {
    $scope = \Overlays\Core\Config\Settings::pluginScope($plugin->slug);
    $settings->set('gruss', 'Moin', $scope);
    $settings->set('events_gesehen', 0, $scope);
}

// Beispiel fuer einen gezielten Upgrade-Schritt:
//
// if ($fromVersion !== null && version_compare($fromVersion, '1.1.0', '<')) {
//     $db->run('ALTER TABLE example_notes ADD COLUMN IF NOT EXISTS farbe TEXT');
// }
