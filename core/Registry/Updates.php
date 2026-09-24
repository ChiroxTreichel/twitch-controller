<?php

declare(strict_types=1);

namespace TwitchController\Core\Registry;

use RuntimeException;
use Throwable;
use TwitchController\Core\App;

/**
 * Plugins aktualisieren - einmal geschrieben, von zwei Stellen
 * benutzt.
 *
 * Der Knopf "Alle aktualisieren" in der Plugin-Liste und die
 * Automatik im Takt tun DASSELBE. Zweimal geschrieben liefen sie mit
 * der Zeit auseinander, und dann taete die Automatik etwas anderes
 * als der Knopf - das faellt niemandem auf, bis es schiefgeht.
 *
 * Was hier NICHT passiert: eine eigene Pruefung der Pakete. Die
 * steckt in Installer::fetch() - Pruefsumme, Unterschrift und die
 * Herkunft vom selben Rechner. Automatisch heisst nicht ungeprueft;
 * es heisst nur, dass niemand danebensteht.
 */
final class Updates
{
    /** Der Schalter unter Einstellungen > System. */
    public const SETTING = 'plugins_auto_update';

    /** Wann zuletzt automatisch nachgesehen wurde. */
    public const LAST_RUN = 'plugins_auto_update_at';

    /**
     * Wie oft die Automatik hoechstens laeuft.
     *
     * Eine Stunde, wie der Zwischenspeicher des Katalogs
     * (Client::CACHE_SECONDS). Oefter brauchte es einen frischen
     * Katalog, und den holt der Katalogserver nicht schneller.
     */
    public const EVERY = 3600;

    public function __construct(private readonly App $app)
    {
    }

    // -----------------------------------------------------------------
    //  Der Schalter
    // -----------------------------------------------------------------

    /**
     * Aus ist die Vorgabe.
     *
     * Ein System, das sich nach der Installation von selbst
     * veraendert, ohne dass jemand danach gefragt hat, ist eine
     * Zumutung - und bei einem Stream-Werkzeug faellt es im
     * schlimmsten Fall mitten in der Sendung auf.
     */
    public static function enabled(App $app): bool
    {
        return $app->settings->bool(self::SETTING, false);
    }

    public static function setEnabled(App $app, bool $an): void
    {
        $app->settings->set(self::SETTING, $an);
    }

    /**
     * Ist die Automatik wieder dran?
     *
     * Rein gerechnet, damit sich die Frage ohne Uhr und ohne
     * Datenbank pruefen laesst.
     */
    public static function isDue(int $zuletzt, int $jetzt): bool
    {
        return $zuletzt <= 0 || ($jetzt - $zuletzt) >= self::EVERY;
    }

    public function due(int $jetzt): bool
    {
        return self::isDue($this->app->settings->int(self::LAST_RUN, 0), $jetzt);
    }

    public function markRun(int $jetzt): void
    {
        $this->app->settings->set(self::LAST_RUN, $jetzt);
    }

    // -----------------------------------------------------------------
    //  Die Arbeit
    // -----------------------------------------------------------------

    /**
     * Alles aktualisieren, was neuer im Katalog steht.
     *
     * Zwei Faelle, und beide muessen mit:
     *
     *   neue Dateien  im Katalog steht eine hoehere Fassung als
     *                 die, die auf der Platte liegt
     *   nur Schema    die Dateien sind schon neu, in der
     *                 Datenbank steht aber noch die alte
     *                 Fassung - dann fehlt nur noch install.php
     *
     * Ein gescheitertes Plugin haelt die uebrigen nicht auf:
     * sonst bliebe nach dem ersten Fehler alles andere alt, und
     * man muesste herausfinden, welches der Uebeltaeter war.
     *
     * @return array{done: list<string>, failed: list<string>}
     */
    public function runAll(): array
    {
        $registry = new Client($this->app);

        try {
            $registry->all();
        } catch (Throwable $e) {
            // Der Katalogserver darf nicht darueber entscheiden, ob
            // man seine Plugins verwalten kann - und im Takt erst
            // recht nicht: ein Aussetzer dort soll keinen Fehler im
            // Protokoll hinterlassen, der nach einem Eingriff aussieht.
            return ['done' => [], 'failed' => [$e->getMessage()]];
        }

        $gemacht = [];
        $gescheitert = [];

        // Voraussetzung zuerst. Alphabetisch stimmt das zufaellig fuer
        // "alerts" vor "twitch-alerts", allgemein nicht - und ein
        // Plugin, dessen Voraussetzung noch alt ist, kann bei seinem
        // install.php ueber eine fehlende Klasse fallen.
        $vorhanden = $this->app->plugins->discover(true);
        $reihenfolge = $this->app->plugins->resolveOrder(array_keys($vorhanden));

        foreach ($reihenfolge as $slug) {
            $manifest = $vorhanden[$slug] ?? null;
            if ($manifest === null) {
                continue;
            }

            $installiert = $this->app->plugins->installedVersion($slug);

            if ($installiert === null) {
                continue;
            }

            $eintrag = $registry->cachedEntry($slug);
            $imKatalog = $eintrag === null ? null : (string) $eintrag['version'];

            $neueDateien = $imKatalog !== null
                && version_compare($manifest->version, $imKatalog, '<');
            $nurSchema = version_compare($installiert, $manifest->version, '<');

            if (!$neueDateien && !$nurSchema) {
                continue;
            }

            try {
                if ($neueDateien) {
                    $eintrag = $registry->find($slug);
                    if ($eintrag === null) {
                        throw new RuntimeException(translate('market.not_in_catalog'));
                    }

                    (new Installer($this->app))->fetch($eintrag);
                    $this->app->plugins->discover(true);

                    $frisch = $this->app->plugins->manifest($slug);
                    if ($frisch === null) {
                        throw new RuntimeException(translate('market.manifest_broken'));
                    }

                    $this->app->plugins->upgradeIfNeeded($frisch);
                    $gemacht[] = $frisch->name . ' ' . $frisch->version;

                    continue;
                }

                $this->app->plugins->upgradeIfNeeded($manifest);
                $gemacht[] = $manifest->name . ' ' . $manifest->version;
            } catch (Throwable $e) {
                // Weitermachen. Ein kaputtes Plugin darf die anderen
                // nicht alt lassen.
                $gescheitert[] = $manifest->name . ': ' . $e->getMessage();
            }
        }

        return ['done' => $gemacht, 'failed' => $gescheitert];
    }
}
