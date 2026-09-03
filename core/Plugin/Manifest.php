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
 *   "requires": { "core": ">=1.0.0" },
 *   "optional": { "alerts": ">=1.0.0" }
 * }
 *
 * "requires" sind harte Abhaengigkeiten: fehlt eine, laesst sich das
 * Plugin nicht aktivieren. "optional" sind weiche: das Plugin laeuft
 * auch ohne, kann aber mehr, wenn das andere da ist - Throne bringt zum
 * Beispiel nur dann Alerts, wenn das Alerts-Plugin aktiv ist.
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

    public function coreConstraint(): ?string
    {
        return $this->requires['core'] ?? null;
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
