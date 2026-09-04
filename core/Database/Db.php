<?php

declare(strict_types=1);

namespace TwitchController\Core\Database;

use TwitchController\Core\Config\Env;
use PDO;
use PDOStatement;

/**
 * Einziger Datenbankzugang des Systems. Verbindet erst beim ersten
 * Zugriff, damit der Installer eine fehlende oder falsche Konfiguration
 * als lesbare Meldung anzeigen kann statt beim Booten zu sterben.
 */
final class Db
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Env $env)
    {
    }

    /**
     * Der erste gescheiterte Verbindungsversuch dieses Requests.
     *
     * Ohne ihn versucht JEDE Abfrage es neu, und ein Versuch kostet die
     * Zeitgrenze von PDO - zwei Sekunden, die sich nicht kleiner
     * einstellen lassen. Der Rahmen der Oberflaeche liest an drei
     * Stellen (Benutzer, Navigation, Kanalname), jede mit eigenem Netz;
     * bei ausgefallener Datenbank stand die Fehlerseite also erst nach
     * sechs Sekunden statt nach zwei.
     *
     * Gemerkt wird nur fuer diesen Request. Der naechste versucht es
     * wieder - eine Datenbank, die gerade hochfaehrt, soll nicht bis
     * zum Neustart des Webservers als ausgefallen gelten.
     */
    private ?\Throwable $verbindungsfehler = null;

    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        if ($this->verbindungsfehler !== null) {
            throw $this->verbindungsfehler;
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->env->get('DB_HOST', 'db'),
            $this->env->int('DB_PORT', 5432),
            $this->env->require('DB_NAME')
        );

        try {
            $this->pdo = new PDO(
                $dsn,
                $this->env->require('DB_USER'),
                $this->env->require('DB_PASS'),
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
        } catch (\Throwable $e) {
            $this->verbindungsfehler = $e;

            throw $e;
        }

        return $this->pdo;
    }

    /**
     * Verbindungstest fuer Installer und Healthcheck.
     */
    public function isReachable(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function transaction(callable $work): mixed
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $result = $work($this);
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
