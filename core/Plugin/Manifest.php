<?php

declare(strict_types=1);

namespace TwitchController\Core\Plugin;

use RuntimeException;

/**
 * plugins/<slug>/plugin.json
 *
 * {
 *   "slug": "throne",
 *   "name": "Throne",
 *   "version": "1.0.0",
 *   "description": "Wunschlisten-Spenden von Throne als Alert",
 *   "author": "Talutah",
 *   "requires":  { "core": ">=1.0.0" },
 *   "optional":  { "alerts": ">=1.0.0" },
 *   "conflicts": { "streamlabs-tip-goals": "*" }
 * }
 *
 * "requires" sind harte Abhaengigkeiten: fehlt eine, laesst sich das
 * Plugin nicht aktivieren. "optional" sind weiche: das Plugin laeuft
 * auch ohne, kann aber mehr, wenn das andere da ist - Throne bringt zum
 * Beispiel nur dann Alerts, wenn das Alerts-Plugin aktiv ist.
 *
 * "conflicts" ist das Gegenteil von "requires": solange eines der
 * genannten Plugins INSTALLIERT ist, laesst sich dieses hier nicht
 * installieren. Gedacht fuer zwei, die dasselbe tun - zwei Plugins, die
 * beide Spenden auf dasselbe Ziel buchen, zaehlten jede Spende doppelt.
 *
 * Gemeint ist installiert, nicht eingeschaltet: ein abgeschaltetes
 * Plugin hat seine Tabelle und seine Einstellungen noch, und wer
 * wechseln will, soll das eine wirklich loswerden.
 *
 * Der Schluessel "core" in beiden Listen bezieht sich auf die
 * Kernversion, nicht auf ein Plugin.
 *
 * Feste Konventionen, die nicht im Manifest stehen:
 *   plugins/<slug>/plugin.php      Einstiegspunkt, registriert Hooks
 *   plugins/<slug>/install.php     Schema anlegen / hochziehen
 *   plugins/<slug>/uninstall.php   Schema abraeumen
 */
final class Manifest
{
    /**
     * @param array<string, string> $requires
     * @param array<string, string> $optional
     * @param array<string, string> $conflicts
     * @param array<string, mixed>  $raw
     */
    private function __construct(
        public readonly string $slug,
        public readonly string $name,
        public readonly string $version,
        public readonly string $description,
        public readonly string $author,
        public readonly array $requires,
        public readonly array $optional,
        public readonly array $conflicts,
        public readonly string $directory,
        public readonly array $raw,
    ) {
    }

    public static function fromDirectory(string $directory): self
    {
        $file = rtrim($directory, '/') . '/plugin.json';
        if (!is_file($file)) {
            throw new RuntimeException("Kein plugin.json in {$directory}");
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("plugin.json ist kein gueltiges JSON: {$file}");
        }

        $slug = strtolower(trim((string) ($decoded['slug'] ?? basename($directory))));
        if (!preg_match('/^[a-z0-9][a-z0-9\-]{1,38}[a-z0-9]$/', $slug)) {
            throw new RuntimeException("Ungueltiger Plugin-Slug: {$slug}");
        }

        if ($slug !== strtolower(basename($directory))) {
            throw new RuntimeException(
                "Slug \"{$slug}\" passt nicht zum Ordnernamen \"" . basename($directory) . '".'
            );
        }

        $version = trim((string) ($decoded['version'] ?? ''));
        if (!preg_match('/^\d+\.\d+\.\d+/', $version)) {
            throw new RuntimeException("Plugin {$slug}: version muss X.Y.Z sein, ist \"{$version}\".");
        }

        if (!is_file(rtrim($directory, '/') . '/plugin.php')) {
            throw new RuntimeException("Plugin {$slug}: plugin.php fehlt.");
        }

        return new self(
            slug: $slug,
            name: trim((string) ($decoded['name'] ?? $slug)),
            version: $version,
            description: trim((string) ($decoded['description'] ?? '')),
            author: trim((string) ($decoded['author'] ?? '')),
            requires: self::constraints($decoded['requires'] ?? []),
            optional: self::constraints($decoded['optional'] ?? []),
            conflicts: self::constraints($decoded['conflicts'] ?? []),
            directory: rtrim($directory, '/'),
            raw: $decoded,
        );
    }

    public function entryFile(): string
    {
        return $this->directory . '/plugin.php';
    }

    public function installFile(): ?string
    {
        $path = $this->directory . '/install.php';

        return is_file($path) ? $path : null;
    }

    public function uninstallFile(): ?string
    {
        $path = $this->directory . '/uninstall.php';

        return is_file($path) ? $path : null;
    }

    /**
     * Harte Abhaengigkeiten ohne den Sonderfall "core".
     *
     * @return array<string, string>
     */
    public function requiredPlugins(): array
    {
        $requires = $this->requires;
        unset($requires['core']);

        return $requires;
    }

    /**
     * @return array<string, string>
     */
    public function optionalPlugins(): array
    {
        $optional = $this->optional;
        unset($optional['core']);

        return $optional;
    }

    /**
     * Plugins, die neben diesem nicht installiert sein duerfen.
     *
     * "core" hat hier keinen Sinn - man kann den Kern nicht
     * deinstallieren - und wird darum wie bei den anderen beiden
     * herausgenommen, falls es jemand hineinschreibt.
     *
     * @return array<string, string>
     */
    public function conflictingPlugins(): array
    {
        $conflicts = $this->conflicts;
        unset($conflicts['core']);

        return $conflicts;
    }

    public function coreConstraint(): ?string
    {
        return $this->requires['core'] ?? null;
    }

    /**
     * Zwei Anzeigenamen vergleichen, fuer sort() und usort().
     *
     * Sortiert wurde frueher nach dem Slug. Solange "twitch-alerts"
     * auch "Twitch - Alerts" hiess, fiel das nicht auf; seit die Art
     * vorn steht, schon: in der Liste stand "Alerts - Throne" zwischen
     * "Streaminfo - Tags" und "Chat - Timer", weil der Ordner "throne"
     * heisst. Wer eine Liste liest, sortiert nach dem, was er SIEHT.
     *
     * Der Slug bleibt die Ordnung von discover(): daran haengt die
     * Reihenfolge, in der Plugins booten, und die soll sich nicht
     * aendern, nur weil jemand ein Plugin umbenennt.
     *
     * strcasecmp allein reicht nicht: es vergleicht Bytes, und ein ö
     * sind in UTF-8 zwei davon, beide groesser als jeder Buchstabe.
     * Sobald zwei Namen bis zum Umlaut gleich anfangen, stuende der mit
     * dem Umlaut hinten - "Chat - Löschbot" hinter "Chat - Lupe".
     */
    public static function compareNames(string $a, string $b): int
    {
        return strcmp(self::sortKey($a), self::sortKey($b));
    }

    /**
     * Der Name, auf das reduziert, wonach einer im Alphabet sucht:
     * Kleinschreibung, und Umlaute auf ihren Grundbuchstaben (DIN
     * 5007-1 - ö sortiert wie o, nicht wie oe).
     *
     * Gefaltet wird VOR dem Kleinschreiben, und beide Schreibweisen
     * stehen in der Tabelle: strtolower() kennt nur ASCII, ein "Ö"
     * bliebe also ein "Ö" und faende seinen Eintrag nicht mehr. Danach
     * ist alles Uebriggebliebene ASCII, und mbstring wird nicht
     * gebraucht - die Erweiterung ist auf keinem Server garantiert.
     */
    private static function sortKey(string $name): string
    {
        $gefaltet = strtr($name, [
            'Ä' => 'a', 'ä' => 'a', 'Ö' => 'o', 'ö' => 'o',
            'Ü' => 'u', 'ü' => 'u', 'ß' => 'ss',
            'Á' => 'a', 'á' => 'a', 'À' => 'a', 'à' => 'a',
            'Â' => 'a', 'â' => 'a', 'Å' => 'a', 'å' => 'a',
            'É' => 'e', 'é' => 'e', 'È' => 'e', 'è' => 'e',
            'Ê' => 'e', 'ê' => 'e',
            'Í' => 'i', 'í' => 'i', 'Ì' => 'i', 'ì' => 'i',
            'Î' => 'i', 'î' => 'i',
            'Ó' => 'o', 'ó' => 'o', 'Ò' => 'o', 'ò' => 'o',
            'Ô' => 'o', 'ô' => 'o', 'Ø' => 'o', 'ø' => 'o',
            'Ú' => 'u', 'ú' => 'u', 'Ù' => 'u', 'ù' => 'u',
            'Û' => 'u', 'û' => 'u',
            'Ç' => 'c', 'ç' => 'c', 'Ñ' => 'n', 'ñ' => 'n',
        ]);

        return strtolower($gefaltet);
    }

    /**
     * @param mixed $value
     * @return array<string, string>
     */
    private static function constraints(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $name => $constraint) {
            $name = strtolower(trim((string) $name));
            if ($name === '') {
                continue;
            }
            $result[$name] = trim((string) $constraint) === '' ? '*' : trim((string) $constraint);
        }

        return $result;
    }
}
