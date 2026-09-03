<?php

declare(strict_types=1);

namespace TwitchController\Core\Support;

/**
 * Sehr kleiner Markdown-Uebersetzer fuer die Beschreibungsseiten im
 * Plugin-Marktplatz.
 *
 * Wichtig ist nicht der Funktionsumfang, sondern die Richtung: der Text
 * kommt aus einem fremden Repository und wird in einer Seite angezeigt,
 * auf der jemand angemeldet ist. Deshalb
 *
 *   1. wird ZUERST alles escaped - danach kann kein Zeichen aus der
 *      Quelle mehr als HTML wirken,
 *   2. wird DANACH die erlaubte Teilmenge in Tags umgesetzt.
 *
 * HTML in der Quelle erscheint also als Text. Das ist Absicht: ein
 * Plugin-Autor soll kein Skript und kein Formular in eine fremde
 * Verwaltungsoberflaeche schreiben koennen.
 *
 * Unterstuetzt: Ueberschriften, Absaetze, Listen (eine Ebene, geordnet
 * und ungeordnet), Zitate, Code (eingerueckt, umzaeunt, inline), fett,
 * kursiv, durchgestrichen, Links, Trennlinien.
 *
 * Nicht unterstuetzt: Tabellen, verschachtelte Listen, Fussnoten, HTML
 * und Bilder - letztere waeren eine Anfrage an einen fremden Server aus
 * der Verwaltungsoberflaeche heraus. Wer mehr braucht, schreibt es in
 * Absaetze; eine Beschreibungsseite ist kein Handbuch.
 */
final class Markdown
{
    /** Laenge, ab der abgeschnitten wird. */
    private const MAX_BYTES = 262144;

    /**
     * @return string HTML, sicher zum direkten Ausgeben
     */
    public static function render(string $markdown): string
    {
        if (strlen($markdown) > self::MAX_BYTES) {
            $markdown = substr($markdown, 0, self::MAX_BYTES);
        }

        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);

        // Umzaeunte Codebloecke vorher herausnehmen. Sonst greift die
        // Inline-Auszeichnung im Code - "$a *b* c" wuerde kursiv.
        //
        // Der Platzhalter darf kein \0 enthalten: trim() entfernt das
        // stillschweigend mit, und der Block waere danach verloren.
        $bloecke = [];
        $markdown = (string) preg_replace_callback(
            '/^```[^\n]*\n(.*?)^```[ \t]*$/ms',
            static function (array $treffer) use (&$bloecke): string {
                $platz = "\x02CODE" . count($bloecke) . "\x02";
                $bloecke[] = '<pre class="md-code"><code>'
                    . self::escape($treffer[1])
                    . '</code></pre>';

                return "\n" . $platz . "\n";
            },
            $markdown
        );

        $zeilen = explode("\n", $markdown);
        $html = [];

        /** @var list<string> $offen Noch nicht geschlossene Blockelemente */
        $offen = [];
        $absatz = [];

        $schliesseAbsatz = static function () use (&$absatz, &$html): void {
            if ($absatz !== []) {
                $html[] = '<p>' . self::inline(implode(' ', $absatz)) . '</p>';
                $absatz = [];
            }
        };

        $schliesseAlles = static function () use (&$offen, &$html, $schliesseAbsatz): void {
            $schliesseAbsatz();
            while ($offen !== []) {
                $html[] = '</' . array_pop($offen) . '>';
            }
        };

        foreach ($zeilen as $zeile) {
            $roh = rtrim($zeile);
            $text = trim($roh);

            // Platzhalter eines Codeblocks
            if (preg_match('/^\x02CODE(\d+)\x02$/', $text, $treffer) === 1) {
                $schliesseAlles();
                $html[] = $bloecke[(int) $treffer[1]] ?? '';
                continue;
            }

            if ($text === '') {
                $schliesseAlles();
                continue;
            }

            // Trennlinie
            if (preg_match('/^(-{3,}|\*{3,}|_{3,})$/', $text) === 1) {
                $schliesseAlles();
                $html[] = '<hr>';
                continue;
            }

            // Ueberschrift. h1 der Quelle wird h2: die Seite hat schon
            // eine h1, und zwei davon sind fuer Vorleseprogramme falsch.
            if (preg_match('/^(#{1,6})\s+(.+)$/', $text, $treffer) === 1) {
                $schliesseAlles();
                $stufe = min(6, strlen($treffer[1]) + 1);
                $html[] = '<h' . $stufe . '>' . self::inline($treffer[2]) . '</h' . $stufe . '>';
                continue;
            }

            // Zitat
            if (preg_match('/^>\s?(.*)$/', $text, $treffer) === 1) {
                if ($offen === [] || end($offen) !== 'blockquote') {
                    $schliesseAlles();
                    $html[] = '<blockquote>';
                    $offen[] = 'blockquote';
                }
                $html[] = '<p>' . self::inline($treffer[1]) . '</p>';
                continue;
            }

            // Liste
            if (preg_match('/^([-*+]|\d+\.)\s+(.+)$/', $text, $treffer) === 1) {
                $sorte = preg_match('/^\d+\./', $treffer[1]) === 1 ? 'ol' : 'ul';

                if ($offen === [] || !in_array(end($offen), ['ul', 'ol'], true)) {
                    $schliesseAbsatz();
                    $html[] = '<' . $sorte . '>';
                    $offen[] = $sorte;
                } elseif (end($offen) !== $sorte) {
                    $html[] = '</' . array_pop($offen) . '>';
                    $html[] = '<' . $sorte . '>';
                    $offen[] = $sorte;
                }

                $html[] = '<li>' . self::inline($treffer[2]) . '</li>';
                continue;
            }

            // Eingerueckter Code
            if (preg_match('/^(?: {4}|\t)(.*)$/', $roh, $treffer) === 1 && $offen === []) {
                $schliesseAbsatz();
                $html[] = '<pre class="md-code"><code>' . self::escape($treffer[1]) . '</code></pre>';
                continue;
            }

            // Fortsetzung eines Listenpunktes
            if ($offen !== [] && in_array(end($offen), ['ul', 'ol'], true)) {
                $letzter = array_pop($html);
                if (is_string($letzter) && str_ends_with($letzter, '</li>')) {
                    $html[] = substr($letzter, 0, -5) . ' ' . self::inline($text) . '</li>';
                    continue;
                }
                $html[] = (string) $letzter;
            }

            $absatz[] = $text;
        }

        $schliesseAlles();

        return implode("\n", array_filter($html, static fn (string $teil): bool => $teil !== ''));
    }

    /**
     * Auszeichnung innerhalb einer Zeile. Escaped zuerst.
     */
    private static function inline(string $text): string
    {
        $text = self::escape($text);

        // Inline-Code zuerst und herausnehmen, damit darin keine
        // weitere Auszeichnung greift.
        $stuecke = [];
        $text = (string) preg_replace_callback(
            '/`([^`]+)`/',
            static function (array $treffer) use (&$stuecke): string {
                $platz = "\x01" . count($stuecke) . "\x01";
                $stuecke[] = '<code>' . $treffer[1] . '</code>';

                return $platz;
            },
            $text
        );

        // Links. Das Ziel muss http(s) sein - kein javascript:, kein
        // data:. Alles andere wird nur der Linktext.
        $text = (string) preg_replace_callback(
            '/\[([^\]]*)\]\(([^)\s]+)\)/',
            static function (array $treffer): string {
                $ziel = $treffer[2];

                if (!str_starts_with($ziel, 'https://') && !str_starts_with($ziel, 'http://')) {
                    return $treffer[1];
                }

                // rel: die Adresse dieser Verwaltung soll nicht in den
                // Referrer eines fremden Servers wandern.
                return '<a href="' . $ziel . '" target="_blank" rel="noopener noreferrer nofollow">'
                    . ($treffer[1] !== '' ? $treffer[1] : $ziel)
                    . '</a>';
            },
            $text
        );

        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);
        $text = (string) preg_replace('/(?<![\w*])\*([^*\n]+)\*(?![\w*])/', '<em>$1</em>', $text);
        $text = (string) preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $text);

        foreach ($stuecke as $i => $stueck) {
            $text = str_replace("\x01" . $i . "\x01", $stueck, $text);
        }

        return $text;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
