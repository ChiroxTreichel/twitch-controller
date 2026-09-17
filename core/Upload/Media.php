<?php

declare(strict_types=1);

namespace TwitchController\Core\Upload;

use TwitchController\Core\App;

/**
 * ===================================================================
 *  Hochgeladene Alert-Dateien
 * ===================================================================
 *
 * Der Knopf am Dateifeld loeste bisher nur die Dateiauswahl aus und
 * schrieb den NAMEN in das Textfeld - hochgeladen wurde nichts. Wer
 * das nicht wusste, trug damit den Pfad zu einer Datei ein, die es auf
 * dem Server nie gab: der Alert lief, und es war nichts zu sehen und
 * nichts zu hoeren.
 *
 * Diese Klasse nimmt die Datei entgegen. Sie liegt im Kern und nicht
 * in einem Plugin: das Dateifeld ist ein Baustein des Kerns, und
 * Alerts, Tip-Goals und Throne benutzen alle denselben.
 *
 * Was hier NICHT passiert: umkodieren, verkleinern, Vorschaubilder.
 * Die Datei geht so auf die Platte, wie sie kam - was im Browser des
 * Streamers laeuft, laeuft auch in OBS.
 */
final class Media
{
    /**
     * Wohin. Unterhalb von public/, damit der Webserver sie direkt
     * ausliefert - ohne PHP dazwischen, denn ein Alert-Video wird
     * mitten im Stream geladen.
     */
    public const DIRECTORY = 'public/uploads/alerts';

    /** Die Adresse, unter der dasselbe im Browser steht. */
    public const URL_PREFIX = '/uploads/alerts/';

    /**
     * Soviel wie das Bild erlaubt (docker/php/uploads.ini).
     *
     * Groesser anzunehmen waere eine Luege: PHP bricht vorher ab, und
     * zwar ohne Datei und ohne Fehler - $_FILES ist dann einfach leer.
     */
    public const MAX_BYTES = 50 * 1024 * 1024;

    /**
     * Was hereindarf. Eine Liste von Endungen und keine von MIME-Typen:
     * die Endung entscheidet, was der Webserver spaeter ausliefert, und
     * genau darum muss sie die Huerde sein.
     *
     * .php steht nicht drauf - und auch nichts anderes, was ein Server
     * ausfuehren koennte. Das ist der Grund fuer eine Erlaubnisliste
     * statt einer Verbotsliste: eine Verbotsliste vergisst immer etwas.
     *
     * @var list<string>
     */
    public const VIDEO = ['webm', 'mp4', 'm4v', 'ogv', 'mov'];

    /** @var list<string> */
    public const AUDIO = ['mp3', 'ogg', 'oga', 'wav', 'm4a', 'aac', 'flac', 'webm'];

    /** @return list<string> */
    public static function allowed(): array
    {
        return array_values(array_unique(array_merge(self::VIDEO, self::AUDIO)));
    }

    /**
     * Die Endung, klein geschrieben. Leer, wenn es keine gibt.
     */
    public static function extension(string $name): string
    {
        $punkt = strrpos($name, '.');

        if ($punkt === false || $punkt === strlen($name) - 1) {
            return '';
        }

        return strtolower(substr($name, $punkt + 1));
    }

    public static function isAllowed(string $name): bool
    {
        return in_array(self::extension($name), self::allowed(), true);
    }

    /**
     * Ein Dateiname, der auf jedem Dateisystem und in jeder Adresse
     * funktioniert.
     *
     * Der Name kommt vom Rechner des Streamers und kann alles
     * enthalten: Leerzeichen, Umlaute, Doppelpunkte, "../". Deshalb
     * wird er nicht bereinigt, sondern NEU GEBAUT - alles ausserhalb
     * von a-z, 0-9, Punkt, Strich und Unterstrich faellt weg.
     *
     * Der Pfad faellt damit von selbst mit: aus "../../.env" wird
     * "-.env" und daraus "env" - kein Pfad mehr, sondern ein Name.
     */
    public static function safeName(string $name): string
    {
        /*
         * Nur der letzte Teil, falls der Browser einen Pfad mitschickt.
         *
         * Ausdruecklich mit der Pruefung auf false: "(int) strrpos(...)"
         * waere ohne Schraegstrich 0 und damit zufaellig richtig, mit
         * einem Schraegstrich aber um eins daneben - der Strich bliebe
         * stehen. Dass am Ende trotzdem das Richtige herauskaeme, laege
         * dann nur am spaeteren trim(), und darauf will man sich nicht
         * verlassen.
         */
        $name = str_replace('\\', '/', $name);
        $strich = strrpos($name, '/');

        if ($strich !== false) {
            $name = substr($name, $strich + 1);
        }

        $endung = self::extension($name);
        $rumpf = $endung === '' ? $name : substr($name, 0, -(strlen($endung) + 1));

        $rumpf = strtolower($rumpf);
        $rumpf = strtr($rumpf, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $rumpf = (string) preg_replace('/[^a-z0-9._-]+/', '-', $rumpf);
        $rumpf = trim((string) preg_replace('/-{2,}/', '-', $rumpf), '-._');

        // Nichts Brauchbares uebrig - etwa bei einem rein
        // kyrillischen Namen. Dann ein Name, der wenigstens eindeutig
        // ist.
        if ($rumpf === '') {
            $rumpf = 'datei-' . substr(bin2hex(random_bytes(4)), 0, 8);
        }

        // Lang genug fuer einen sprechenden Namen, kurz genug fuer
        // jedes Dateisystem.
        $rumpf = substr($rumpf, 0, 80);

        return $endung === '' ? $rumpf : $rumpf . '.' . $endung;
    }

    /**
     * Ein Name, den es dort noch nicht gibt.
     *
     * Zweimal dieselbe Datei hochzuladen ist der Normalfall - man
     * bessert ein Video nach und laedt es erneut. Ueberschreiben waere
     * trotzdem falsch: ein anderer Alert kann auf derselben Datei
     * stehen, und der zeigte danach etwas anderes, ohne dass jemand
     * ihn angefasst hat.
     */
    public static function freeName(string $ordner, string $name): string
    {
        if (!file_exists($ordner . '/' . $name)) {
            return $name;
        }

        $endung = self::extension($name);
        $rumpf = $endung === '' ? $name : substr($name, 0, -(strlen($endung) + 1));

        for ($i = 2; $i < 1000; $i++) {
            $versuch = $rumpf . '-' . $i . ($endung === '' ? '' : '.' . $endung);

            if (!file_exists($ordner . '/' . $versuch)) {
                return $versuch;
            }
        }

        return $rumpf . '-' . bin2hex(random_bytes(4)) . ($endung === '' ? '' : '.' . $endung);
    }

    /**
     * Was PHP an Fehlern melden kann - in einen Satz uebersetzt.
     *
     * UPLOAD_ERR_INI_SIZE und _FORM_SIZE bedeuten fuer den Benutzer
     * dasselbe: zu gross. Die uebrigen sind Serverfehler und sollen
     * auch so heissen, damit niemand an seiner Datei sucht.
     */
    public static function errorText(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => translate('upload.error.too_big', [
                'max' => (string) (int) (self::MAX_BYTES / 1024 / 1024),
            ]),
            UPLOAD_ERR_PARTIAL => translate('upload.error.partial'),
            UPLOAD_ERR_NO_FILE => translate('upload.error.none'),
            default => translate('upload.error.server'),
        };
    }

    public static function directory(App $app): string
    {
        return $app->root . '/' . self::DIRECTORY;
    }
}
