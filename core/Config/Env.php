<?php

declare(strict_types=1);

namespace Overlays\Core\Config;

use RuntimeException;

/**
 * Bootstrap-Konfiguration aus der Umgebung.
 *
 * Hier stehen nur die Werte, die gebraucht werden, BEVOR die Datenbank
 * erreichbar ist: DB-Zugang, Basis-URL, App-Key. Alles Fachliche
 * (Twitch-Credentials, Plugin-Einstellungen) liegt in der Datenbank und
 * wird ueber den Installer bzw. die Adminoberflaeche gesetzt.
 *
 * Compose reicht die .env als Container-Umgebung durch, deshalb hat
 * getenv() Vorrang. Die Datei wird nur als Fallback gelesen, damit sich
 * CLI-Skripte auch ausserhalb von Docker starten lassen.
 */
final class Env
{
    /** @var array<string, string> */
    private array $file = [];

    public function __construct(private readonly string $path)
    {
        if (is_readable($this->path)) {
            $this->file = self::parse($this->path);
        }
    }

    public function get(string $key, ?string $default = null): ?string
    {
        $fromEnv = getenv($key);
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        $value = $this->file[$key] ?? null;
        if ($value !== null && $value !== '') {
            return $value;
        }

        return $default;
    }

    public function require(string $key): string
    {
        $value = $this->get($key);
        if ($value === null || $value === '') {
            throw new RuntimeException(
                "Fehlende Konfiguration: {$key}. Bitte in der .env setzen (Vorlage: .env.example)."
            );
        }

        return $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        return $value === null || !is_numeric($value) ? $default : (int) $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = $this->get($key);
        if ($value === null) {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * Basis-URL ohne Slash am Ende, optional mit angehaengtem Pfad.
     */
    public function url(string $path = ''): string
    {
        $base = rtrim($this->require('APP_URL'), '/');
        if ($path === '') {
            return $base;
        }

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * @return array<string, string>
     */
    private static function parse(string $path): array
    {
        $result = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return $result;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            if (strlen($value) >= 2
                && (($value[0] === '"' && $value[-1] === '"') || ($value[0] === "'" && $value[-1] === "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            $result[$key] = $value;
        }

        return $result;
    }
}
