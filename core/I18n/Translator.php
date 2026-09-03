<?php

declare(strict_types=1);

namespace Overlays\Core\I18n;

/**
 * Uebersetzungen.
 *
 * Der Schluessel ist der deutsche Text selbst - Deutsch ist die
 * Quellsprache:
 *
 *   translate('Benutzer')                  -> "Users"   (bei en)
 *   translate('%d Farben gespeichert', 3)  -> sprintf danach
 *
 * Fehlt eine Uebersetzung, kommt der Text unveraendert zurueck. Damit
 * funktioniert die Oberflaeche immer, auch bei halb gefuellter
 * Sprachdatei - kein leerer Knopf, keine Platzhalter im Text.
 *
 * Dateien:
 *   lang/<code>.json                    Kern
 *   plugins/<slug>/lang/<code>.json     je Plugin, beim Laden ergaenzt
 *
 * Format ist flach, Text auf Text:
 *
 *   { "Benutzer": "Users", "Speichern": "Save" }
 *
 * lang/de.json ist deshalb normalerweise leer - es gibt nichts zu
 * uebersetzen. Sie ist der Ort fuer Faelle, in denen man einzelne
 * Formulierungen nachtraeglich aendern will, ohne den Code anzufassen.
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
     * Wird einmal beim Start gesetzt. Ohne Aufruf arbeitet translate()
     * als Durchreiche - so scheitert nichts, wenn die Sprache (noch)
     * nicht bekannt ist, etwa waehrend der Ersteinrichtung.
     */
    public static function boot(string $language, string $coreLangDir): self
    {
        $language = self::normalize($language);

        self::$instance = new self($language);

        if ($language !== self::DEFAULT_LANGUAGE || is_file($coreLangDir . '/' . $language . '.json')) {
            self::$instance->loadFile($coreLangDir . '/' . $language . '.json');
        }

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
     * Ergaenzt Uebersetzungen, etwa aus einem Plugin. Spaeter geladene
     * gewinnen - ein Plugin darf also auch einen Kerntext umformulieren.
     */
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
            if (is_string($key) && is_string($value) && $value !== '') {
                $this->strings[$key] = $value;
            }
        }
    }

    /**
     * Laedt die Sprachdatei eines Verzeichnisses fuer die aktive Sprache.
     */
    public function loadDirectory(string $directory): void
    {
        $this->loadFile(rtrim($directory, '/') . '/' . $this->language . '.json');
    }

    /**
     * @param list<mixed> $args
     */
    public function translate(string $text, array $args = []): string
    {
        $translated = $this->strings[$text] ?? $text;

        if ($args === []) {
            return $translated;
        }

        // Bei kaputten Platzhaltern in einer Uebersetzung lieber den
        // Originaltext ausgeben als eine Fehlermeldung in der Seite.
        $formatted = @vsprintf($translated, $args);

        return is_string($formatted) ? $formatted : $translated;
    }

    public function has(string $text): bool
    {
        return isset($this->strings[$text]);
    }

    /**
     * Wie viele Texte sind uebersetzt? Fuer die Anzeige in den
     * Einstellungen.
     */
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
            if ($code !== '' && !in_array($code, $codes, true)) {
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
