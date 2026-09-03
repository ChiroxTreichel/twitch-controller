<?php

declare(strict_types=1);

/**
 * Globale Funktionen. Wird vom Autoloader mitgeladen, weil sich
 * Funktionen nicht automatisch nachladen lassen.
 */

use Overlays\Core\App;
use Overlays\Core\I18n\Translator;

if (!function_exists('translate')) {
    /**
     * Uebersetzt einen Text. Schluessel ist ein englischer Punktpfad:
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

if (!function_exists('permission')) {
    /**
     * Darf der angemeldete Benutzer das?
     *
     *   if (permission('Konto.Benutzer.Manage')) { … }
     *
     *   <?php if (permission('Overlay.Einstellungen.Manage')): ?>
     *       <button …>
     *   <?php endif ?>
     *
     * Dasselbe wie $app->auth->can(), nur ohne dass $app durch jede
     * Vorlage und jeden Hook gereicht werden muss - genau wie bei
     * translate(). Der Superadmin umgeht jede Pruefung.
     *
     * Schluessel folgen dem Schema Bereich.Funktion.Recht und werden
     * ausgeschrieben, nicht zusammengesetzt: nur so lassen sie sich
     * im Code wiederfinden.
     *
     * Wichtig: das ist die Anzeige-Frage, nicht der Schutz. Eine Route
     * schuetzt ihr 'permission' im Router, und eine POST-Aktion prueft
     * zusaetzlich selbst. Wer nur den Knopf ausblendet, hat die
     * Aktion nicht abgesichert.
     */
    function permission(string $key): bool
    {
        $app = App::current();

        // Ohne laufende Anwendung - etwa in einem Testskript - gilt
        // nichts als erlaubt. Lieber ein fehlender Knopf als ein
        // ungeschuetzter.
        return $app !== null && $app->auth->can($key);
    }
}
