<?php

declare(strict_types=1);

namespace Overlays\Core\Support;

/**
 * Winziger Markdown-Ersatz fuer Beschreibungstexte aus dem Plugin-Katalog.
 *
 * Wichtig ist die Reihenfolge: der Text wird ZUERST vollstaendig
 * escaped, danach werden nur die eigenen Auszeichnungen wieder zu HTML.
 * Damit kann ein Katalogeintrag kein HTML und kein Skript einschmuggeln,
 * selbst wenn der Katalogserver uebernommen wurde.
 *
 * Unterstuetzt: Absaetze, Zeilenumbrueche, **fett**, *kursiv*, `Code`,
 * Aufzaehlungen mit "- " und Links (nur http/https).
 */
final class Markdown
{
    public static function render(string $text): string
    {
        $safe = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safe = str_replace(["\r\n", "\r"], "\n", $safe);

        $html = '';

        // In Blöcke teilen: Leerzeile trennt Absätze.
        foreach (preg_split('/\n{2,}/', trim($safe)) ?: [] as $block) {
            $block = trim($block);
            if ($block === '') {
                continue;
            }

            $lines = explode("\n", $block);
            $isList = true;
            foreach ($lines as $line) {
                if (!preg_match('/^[-*]\s+/', trim($line))) {
                    $isList = false;
                    break;
                }
            }

            if ($isList) {
                $html .= '<ul>';
                foreach ($lines as $line) {
                    $html .= '<li>' . self::inline(preg_replace('/^[-*]\s+/', '', trim($line)) ?? '') . '</li>';
                }
                $html .= '</ul>';
                continue;
            }

            $html .= '<p>' . self::inline(implode('<br>', array_map('trim', $lines))) . '</p>';
        }

        return $html;
    }

    /**
     * Auszeichnungen innerhalb einer Zeile. Arbeitet auf bereits
     * escaptem Text, deshalb sind die Muster hier gefahrlos.
     */
    private static function inline(string $text): string
    {
        // `Code`
        $text = preg_replace('/`([^`]+)`/', '<code>$1</code>', $text) ?? $text;

        // **fett**
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text) ?? $text;

        // *kursiv*
        $text = preg_replace('/(?<!\*)\*([^*\n]+)\*(?!\*)/', '<em>$1</em>', $text) ?? $text;

        // [Text](https://…) - nur http und https, sonst bleibt es Text
        $text = preg_replace_callback(
            '/\[([^\]]+)\]\((https?:&#0*39;?[^\s)]*|https?:\/\/[^\s)]+)\)/',
            static function (array $m): string {
                $href = $m[2];
                if (!str_starts_with($href, 'http://') && !str_starts_with($href, 'https://')) {
                    return $m[0];
                }

                return '<a href="' . $href . '" target="_blank" rel="noreferrer noopener">' . $m[1] . '</a>';
            },
            $text
        ) ?? $text;

        return $text;
    }
}
