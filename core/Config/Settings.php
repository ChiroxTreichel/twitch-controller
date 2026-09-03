<?php

declare(strict_types=1);

namespace TwitchController\Core\Config;

use TwitchController\Core\Database\Db;
use TwitchController\Core\Support\Crypto;

/**
 * Alle fachlichen Einstellungen liegen in der Datenbank, nicht in der
 * .env - damit sie in der Adminoberflaeche bearbeitbar sind und ein
 * Plugin seine eigenen Einstellungen mitbringen kann, ohne dass jemand
 * eine Datei anfasst.
 *
 * Getrennt nach Scope:
 *   'core'            Twitch-Credentials, Installationsstatus, Branding
 *   'plugin:<slug>'   alles, was ein Plugin sich selbst merkt
 *
 * Werte werden als JSON gespeichert, behalten also ihren Typ.
 * Geheimnisse laufen ueber secret()/setSecret() und liegen verschluesselt.
 */
final class Settings
{
    public const CORE = 'core';

    /** @var array<string, array<string, mixed>> */
    private array $cache = [];

    public function __construct(
        private readonly Db $db,
        private readonly Crypto $crypto,
    ) {
    }

    public static function pluginScope(string $slug): string
    {
        return 'plugin:' . strtolower($slug);
    }

    public function get(string $key, mixed $default = null, string $scope = self::CORE): mixed
    {
        $all = $this->all($scope);

        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    public function string(string $key, string $default = '', string $scope = self::CORE): string
    {
        $value = $this->get($key, $default, $scope);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0, string $scope = self::CORE): int
    {
        $value = $this->get($key, $default, $scope);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false, string $scope = self::CORE): bool
    {
        $value = $this->get($key, $default, $scope);

        return is_bool($value) ? $value : (bool) $value;
    }

    public function has(string $key, string $scope = self::CORE): bool
    {
        return array_key_exists($key, $this->all($scope));
    }

    public function set(string $key, mixed $value, string $scope = self::CORE): void
    {
        $this->db->run(
            'INSERT INTO settings (scope, key, value, updated_at)
             VALUES (:scope, :key, CAST(:value AS JSONB), now())
             ON CONFLICT (scope, key) DO UPDATE
                SET value = EXCLUDED.value, updated_at = now()',
            [
                'scope' => $scope,
                'key'   => $key,
                'value' => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]
        );

        $this->cache[$scope][$key] = $value;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setMany(array $values, string $scope = self::CORE): void
    {
        foreach ($values as $key => $value) {
            $this->set($key, $value, $scope);
        }
    }

    public function forget(string $key, string $scope = self::CORE): void
    {
        $this->db->run(
            'DELETE FROM settings WHERE scope = :scope AND key = :key',
            ['scope' => $scope, 'key' => $key]
        );

        unset($this->cache[$scope][$key]);
    }

    /**
     * Loescht alle Einstellungen eines Scopes - beim Deinstallieren eines
     * Plugins.
     */
    public function forgetScope(string $scope): void
    {
        $this->db->run('DELETE FROM settings WHERE scope = :scope', ['scope' => $scope]);
        unset($this->cache[$scope]);
    }

    /**
     * Verschluesselt ablegen (Client-Secret, Webhook-Secret, Tokens).
     */
    public function setSecret(string $key, string $value, string $scope = self::CORE): void
    {
        $this->set($key, $value === '' ? '' : $this->crypto->encrypt($value), $scope);
    }

    public function secret(string $key, string $default = '', string $scope = self::CORE): string
    {
        $stored = $this->get($key, null, $scope);
        if (!is_string($stored) || $stored === '') {
            return $default;
        }

        return $this->crypto->decrypt($stored);
    }

    /**
     * Ob ein Geheimnis gesetzt ist, ohne es zu entschluesseln - fuer
     * Formulare, die nur "gesetzt / nicht gesetzt" anzeigen sollen.
     */
    public function hasSecret(string $key, string $scope = self::CORE): bool
    {
        $stored = $this->get($key, null, $scope);

        return is_string($stored) && $stored !== '';
    }

    /**
     * @return array<string, mixed>
     */
    public function all(string $scope = self::CORE): array
    {
        if (isset($this->cache[$scope])) {
            return $this->cache[$scope];
        }

        $values = [];
        foreach ($this->db->all('SELECT key, value FROM settings WHERE scope = :scope', ['scope' => $scope]) as $row) {
            $values[(string) $row['key']] = json_decode((string) $row['value'], true);
        }

        return $this->cache[$scope] = $values;
    }

    public function flush(): void
    {
        $this->cache = [];
    }
}
