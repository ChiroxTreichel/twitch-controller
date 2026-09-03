<?php

declare(strict_types=1);

namespace Overlays\Core\I18n;

/**
 * Uebersetzungen.
 *
 * Im Code stehen englische Schluessel, nie fertige Texte:
 *
 *   translate('account.users.title')            -> "Benutzer" / "Users"
 *   translate('plugins.count', 3)               -> sprintf danach
 *
 * Vorteil gegenueber Texten als Schluessel: eine Umformulierung im
 * Deutschen macht nicht alle anderen Sprachen ungueltig.
 *
 * Geladen wird immer zweistufig:
 *
 *   1. de.json  - die Grundlage, sie enthaelt jeden Schluessel
 *   2. <code>.json - die aktive Sprache, legt sich darueber
 *
 * Fehlt ein Schluessel in der aktiven Sprache, erscheint also der
 * deutsche Text und nicht der nackte Schluessel. Fehlt er auch dort,
 * kommt der Schluessel selbst zurueck - dann sieht man sofort, wo etwas
 * nachzutragen ist.
 *
 * Dateien:
 *   lang/<code>.json                    Kern
 *   plugins/<slug>/lang/<code>.json     je Plugin, beim Laden ergaenzt
 *
 * Format ist flach, Schluessel auf Text:
 *
 *   { "account.users.title": "Benutzer" }
 */
final class Translator
{
    public const DEFAULT_LANGUAGE = 'de';

    private static ?self $instance = null;

    /** @var array<string, string> */
    private array $strings = [];

    private function __construct(private readonly string $language)
    {
    }

    /**
     * Wird einmal beim Start gesetzt.
     */
    public static function boot(string $language, string $coreLangDir): self
    {
        self::$instance = new self(self::normalize($language));
        self::$instance->loadDirectory($coreLangDir);

        return self::$instance;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self(self::DEFAULT_LANGUAGE);
    }

    public function language(): string
    {
        return $this->language;
    }

    /**
     * Laedt ein Sprachverzeichnis: zuerst die deutsche Grundlage, dann
     * die aktive Sprache darueber. Mehrfach aufrufbar - Plugins
     * ergaenzen so ihre eigenen Verzeichnisse.
     */
    public function loadDirectory(string $directory): void
    {
        $directory = rtrim($directory, '/');

        $this->loadFile($directory . '/' . self::DEFAULT_LANGUAGE . '.json');

        if ($this->language !== self::DEFAULT_LANGUAGE) {
            $this->loadFile($directory . '/' . $this->language . '.json');
        }
    }

    public function loadFile(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            return;
        }

        foreach ($decoded as $key => $value) {
            // Leere Werte ueberspringen: in einer halb gefuellten
            // Sprachdatei soll die deutsche Grundlage stehen bleiben.
            if (is_string($key) && is_string($value) && $value !== '') {
                $this->strings[$key] = $value;
            }
        }
    }

    /**
     * Zwei Sorten Platzhalter:
     *
     *   benannt      "%{name} hat %{count} Abos"   mit ['name' => …, 'count' => …]
     *   der Reihe    "%s hat %d Abos"              mit [$name, $anzahl]
     *
     * Benannt ist fuer Uebersetzer die bessere Wahl: die Wortstellung
     * ist je Sprache anders, und bei mehreren Werten muss niemand
     * mitzaehlen, welcher wo hingehoert.
     *
     * @param array<string|int, mixed> $args
     */
    public function translate(string $key, array $args = []): string
    {
        $text = $this->strings[$key] ?? $key;

        if ($args === []) {
            return $text;
        }

        // Zeichenketten als Schluessel bedeuten: benannte Platzhalter.
        $benannt = [];
        foreach (array_keys($args) as $schluessel) {
            if (is_string($schluessel)) {
                $benannt['%{' . $schluessel . '}'] = (string) $args[$schluessel];
            }
        }

        if ($benannt !== []) {
            return strtr($text, $benannt);
        }

        // Benannte Platzhalter im Text, aber Werte der Reihe nach
        // uebergeben? Dann der Reihe nach einsetzen. Das ist ein Fehler
        // im Aufruf, darf aber nicht die Seite kosten.
        if (str_contains($text, '%{')) {
            return (string) preg_replace_callback(
                '/%\{[a-zA-Z0-9_]+\}/',
                static function () use (&$args): string {
                    $wert = array_shift($args);

                    return $wert === null ? '' : (string) $wert;
                },
                $text
            );
        }

        // vsprintf wirft bei kaputten Platzhaltern eine ValueError - das
        // faengt kein @. Ohne dieses try/catch reisst eine einzige
        // schiefe Uebersetzung die ganze Seite mit.
        try {
            return vsprintf($text, array_values($args));
        } catch (\Throwable) {
            return $text;
        }
    }

    public function has(string $key): bool
    {
        return isset($this->strings[$key]);
    }

    public function count(): int
    {
        return count($this->strings);
    }

    /**
     * Vorhandene Sprachen eines Verzeichnisses.
     *
     * @return list<string>
     */
    public static function available(string $directory): array
    {
        $codes = [self::DEFAULT_LANGUAGE];

        foreach (glob(rtrim($directory, '/') . '/*.json') ?: [] as $file) {
            $code = self::normalize(basename($file, '.json'));
            if (!in_array($code, $codes, true)) {
                $codes[] = $code;
            }
        }

        sort($codes);

        return $codes;
    }

    /**
     * Klarname einer Sprache, soweit bekannt.
     */
    public static function label(string $code): string
    {
        return match (self::normalize($code)) {
            'de' => 'Deutsch',
            'en' => 'English',
            'fr' => 'Français',
            'es' => 'Español',
            'it' => 'Italiano',
            'nl' => 'Nederlands',
            'pl' => 'Polski',
            'pt' => 'Português',
            'tr' => 'Türkçe',
            'ru' => 'Русский',
            default => strtoupper($code),
        };
    }

    /**
     * "de", "de-DE", "DE", "en_GB" -> "de" bzw. "en"
     *
     * Unterstrich und Bindestrich sind beide Trenner - Browser und
     * Betriebssysteme schreiben es unterschiedlich, und ein "en_GB",
     * das als Deutsch landet, sucht man lange.
     */
    public static function normalize(string $code): string
    {
        $code = strtolower(trim($code));
        $code = (string) preg_replace('/[^a-z_\-]/', '', $code);
        $code = preg_split('/[_\-]/', $code)[0] ?? '';

        return preg_match('/^[a-z]{2}$/', $code) === 1 ? $code : self::DEFAULT_LANGUAGE;
    }
}
