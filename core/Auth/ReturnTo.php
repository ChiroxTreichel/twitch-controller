<?php

declare(strict_types=1);

namespace TwitchController\Core\Auth;

use TwitchController\Core\Http\Request;

/**
 * ===================================================================
 *  Wohin nach der Anmeldung
 * ===================================================================
 *
 * Wer /overlay aufruft und nicht angemeldet ist, landet auf der
 * Anmeldeseite - und danach bitte wieder auf /overlay und nicht auf der
 * Startseite. Dazu wird das Ziel mitgefuehrt: als ?next= an der
 * Anmeldeseite, dann im signierten Zustandswert des OAuth-Umwegs.
 *
 * Das Ziel kommt aus der Adresszeile, also von aussen. Ungeprueft waere
 * es eine offene Weiterleitung: eine Adresse der Form
 *
 *     /login?next=https://phishing.example/
 *
 * fuehrte den Angemeldeten nach einer echten Anmeldung bei Twitch auf
 * eine fremde Seite - und der Weg dorthin begaenne bei uns.
 *
 * Darum gilt hier nur, was zweifelsfrei zu DIESEM System gehoert: ein
 * Pfad mit einem Schraegstrich am Anfang, ohne zweiten daneben, ohne
 * Rueckstrich und ohne Doppelpunkt.
 *
 * Geprueft wird zweimal - beim Entgegennehmen und beim Benutzen. Der
 * Zustandswert ist zwar signiert, aber signiert ist nicht geprueft: was
 * hineingeschrieben wurde, kam ebenfalls von aussen.
 */
final class ReturnTo
{
    /** Der Name des Feldes - an einer Stelle, nicht an vier. */
    public const PARAM = 'next';

    /**
     * Laenger als das ist kein Weg in diesem System. Die Grenze ist
     * gegen Adressen, die nur da sind, um eine Kopfzeile zu sprengen.
     */
    public const MAX = 512;

    /**
     * Adressen, die als Ziel nichts taugen.
     *
     * Die Anmeldung selbst waere eine Schleife: angemeldet, zurueck zur
     * Anmeldung, angemeldet. Und die Startseite ist das, was ohnehin
     * passiert - sie mitzuschleppen ist nur Ballast in der Adresszeile.
     */
    private const AUSGENOMMEN = ['/', '/login', '/login/start', '/auth/callback', '/logout'];

    /**
     * Ein Ziel annehmen - oder eine leere Zeichenkette.
     *
     * Leer heisst ueberall "nimm die Startseite". Es gibt keinen
     * Fehlerfall, denn es gibt nichts zu melden: wer eine unsinnige
     * Adresse mitschickt, bekommt die normale Anmeldung.
     */
    public static function sanitize(string $ziel): string
    {
        $ziel = trim($ziel);

        if ($ziel === '' || strlen($ziel) > self::MAX) {
            return '';
        }

        /*
         * Steuerzeichen raus, bevor irgendetwas geprueft wird. Ein
         * Zeilenumbruch in einer Adresse, die spaeter in einer
         * Location-Kopfzeile steht, waere eine zweite Kopfzeile.
         */
        if (preg_match('/[\x00-\x1f\x7f]/', $ziel) === 1) {
            return '';
        }

        // Nur ein Pfad auf diesem Server.
        if ($ziel[0] !== '/') {
            return '';
        }

        /*
         * "//example.com" ist fuer den Browser eine fremde Adresse mit
         * dem Schema der aktuellen Seite - und "/\example.com" nehmen
         * die meisten Browser genauso. Beides ist hier keines.
         */
        if (str_starts_with($ziel, '//') || str_starts_with($ziel, '/\\')) {
            return '';
        }

        if (str_contains($ziel, '\\')) {
            return '';
        }

        // Ein Doppelpunkt im Pfad heisst: da versucht jemand ein Schema.
        $pfad = explode('?', $ziel, 2)[0];

        if (str_contains($pfad, ':')) {
            return '';
        }

        if (in_array(rtrim($pfad, '/') === '' ? '/' : rtrim($pfad, '/'), self::AUSGENOMMEN, true)) {
            return '';
        }

        return $ziel;
    }

    /**
     * Das Ziel aus der laufenden Anfrage - fuer den Zugriffsschutz.
     *
     * Nur GET: ein abgewiesenes Formular laesst sich nach der Anmeldung
     * nicht wiederholen, und den Browser danach mit GET auf eine Adresse
     * zu schicken, die nur POST kennt, waere ein Fehler statt einer
     * Seite.
     *
     * Der Pfad kommt aus dem Request und nicht aus REQUEST_URI: dort
     * steht er schon geprueft und ohne Basisverzeichnis.
     */
    public static function fromRequest(Request $request): string
    {
        if ($request->method !== 'GET') {
            return '';
        }

        $ziel = $request->path;
        $query = http_build_query($request->query);

        if ($query !== '') {
            $ziel .= '?' . $query;
        }

        return self::sanitize($ziel);
    }

    /**
     * Das Ziel an eine Adresse haengen - ohne, wenn es keines gibt.
     *
     * @param array<string, string> $weitere weitere Felder, z. B. invite
     */
    public static function appendTo(string $adresse, string $ziel, array $weitere = []): string
    {
        $felder = array_filter($weitere, static fn (string $wert): bool => $wert !== '');

        $ziel = self::sanitize($ziel);

        if ($ziel !== '') {
            $felder[self::PARAM] = $ziel;
        }

        if ($felder === []) {
            return $adresse;
        }

        return $adresse . (str_contains($adresse, '?') ? '&' : '?') . http_build_query($felder);
    }
}
