<?php

declare(strict_types=1);

/**
 * Globale Funktionen. Wird vom Autoloader mitgeladen, weil sich
 * Funktionen nicht automatisch nachladen lassen.
 */

use Overlays\Core\I18n\Translator;

if (!function_exists('translate')) {
    /**
     * Uebersetzt einen Text. Schluessel ist der deutsche Text selbst:
     *
     *   translate('Benutzer')
     *   translate('%d Farben gespeichert.', $anzahl)
     *   translate('%s wurde entfernt.', $name)
     *
     * Fehlt die Uebersetzung, kommt der Text unveraendert zurueck.
     *
     * In Vorlagen immer zusammen mit dem Escaping benutzen:
     *
     *   <?= $e(translate('Speichern')) ?>
     *
     * Zusaetzliche Argumente laufen durch sprintf(). Die Platzhalter
     * muessen in jeder Sprachdatei dieselben bleiben - stimmen sie
     * nicht, wird der Originaltext ausgegeben statt einer Fehlermeldung.
     */
    function translate(string $text, mixed ...$args): string
    {
        return Translator::instance()->translate($text, $args);
    }
}
