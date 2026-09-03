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
     *   translate('nav.users')
     *   translate('account.activity.kinds', ['count' => $anzahl])
     *   translate('account.users.confirm_remove', [$name])
     *   translate('setup.step_of', ['step' => 2, 'total' => 4])
     *
     * Fehlt die Uebersetzung, kommt der Text unveraendert zurueck.
     *
     * In Vorlagen immer zusammen mit dem Escaping benutzen:
     *
     *   <?= $e(translate('common.save')) ?>
     *
     * Zusaetzliche Argumente laufen durch sprintf(). Die Platzhalter
     * muessen in jeder Sprachdatei dieselben bleiben - stimmen sie
     * nicht, wird der unformatierte Text ausgegeben statt einer
     * Fehlermeldung mitten in der Seite.
     */
    function translate(string $key, mixed ...$args): string
    {
        // Beide Schreibweisen erlaubt:
        //   translate('key', $a, $b)      der Reihe nach
        //   translate('key', [$a, $b])    dasselbe, nur ausdruecklich
        //   translate('key', ['n' => 3])  benannt, siehe Translator
        if (count($args) === 1 && is_array($args[0])) {
            $args = $args[0];
        }

        return Translator::instance()->translate($key, $args);
    }
}
