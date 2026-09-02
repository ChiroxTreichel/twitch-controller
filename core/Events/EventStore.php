<?php

declare(strict_types=1);

namespace Overlays\Core\Events;

use Overlays\Core\App;

/**
 * Der Event-Eingang des Kerns - das, was unter "Konto > Aktivitaeten"
 * sichtbar ist.
 *
 * Schreiben duerfen Kern und Plugins (Throne, PayPal). Lesen tun die
 * Aktivitaetenliste und Plugins, die auf Events reagieren.
 *
 * Nach jedem tatsaechlich neuen Event laeuft der Hook
 * 'core.event.stored' mit der kompletten Zeile - so kann ein
 * Alerts-Plugin sofort etwas in seine Warteschlange legen, ohne die
 * Tabelle pollen zu muessen.
 */
final class EventStore
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param array<string, mixed> $payload      normalisierter Event-Inhalt
     * @param array<string, mixed> $normalized   Ergebnis des Normalizers
     * @return int|null ID des neuen Events, null bei Doppelzustellung
     */
    public function store(
        string $source,
        string $eventType,
        string $externalId,
        string $occurredAt,
        array $normalized,
        array $payload,
        string $rawPayload,
    ): ?int {
        $id = $this->app->db->value(
            'INSERT INTO events (
                 source, event_type, external_id, occurred_at,
                 actor_name, actor_external_id, message, amount, currency,
                 payload, raw_payload
             ) VALUES (
                 :source, :event_type, :external_id, :occurred_at,
                 :actor_name, :actor_external_id, :message, :amount, :currency,
                 CAST(:payload AS JSONB), :raw_payload
             )
             ON CONFLICT (source, external_id) DO NOTHING
             RETURNING id',
            [
                'source'            => $source,
                'event_type'        => $eventType,
                'external_id'       => $externalId,
                'occurred_at'       => $occurredAt,
                'actor_name'        => $normalized['actor_name'] ?? null,
                'actor_external_id' => $normalized['actor_external_id'] ?? null,
                'message'           => $normalized['message'] ?? null,
                'amount'            => $normalized['amount'] ?? null,
                'currency'          => $normalized['currency'] ?? null,
                'payload'           => (string) json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'raw_payload'       => $rawPayload,
            ]
        );

        if ($id === null) {
            return null;
        }

        $row = $this->find((int) $id);
        if ($row !== null) {
            $this->app->hooks->dispatch('core.event.stored', $row);
        }

        return (int) $id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->app->db->first('SELECT * FROM events WHERE id = :id', ['id' => $id]);

        return $row === null ? null : self::decode($row);
    }

    /**
     * Neueste Events zuerst - fuer die Aktivitaetenliste.
     *
     * @param array{source?: string, event_type?: string, actor?: string} $filters
     * @return list<array<string, mixed>>
     */
    public function recent(int $limit = 50, int $offset = 0, array $filters = []): array
    {
        [$where, $params] = self::filterClause($filters);

        $params['limit'] = max(1, min(500, $limit));
        $params['offset'] = max(0, $offset);

        $rows = $this->app->db->all(
            'SELECT * FROM events ' . $where . '
              ORDER BY received_at DESC, id DESC
              LIMIT :limit OFFSET :offset',
            $params
        );

        return array_map([self::class, 'decode'], $rows);
    }

    /**
     * @param array{source?: string, event_type?: string, actor?: string} $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = self::filterClause($filters);

        return (int) $this->app->db->value('SELECT count(*) FROM events ' . $where, $params);
    }

    /**
     * Alles ab einer bekannten ID - fuer Worker, die der Reihe nach
     * abarbeiten. settleSeconds laesst Events kurz "abkuehlen", damit
     * zusammengehoerige Zustellungen (z.B. mehrere Gift-Subs) gemeinsam
     * verarbeitet werden koennen.
     *
     * @return list<array<string, mixed>>
     */
    public function since(int $lastSeenId, int $limit = 100, int $settleSeconds = 0): array
    {
        $rows = $this->app->db->all(
            'SELECT * FROM events
              WHERE id > :last
                AND received_at <= now() - (:settle || \' seconds\')::interval
              ORDER BY id
              LIMIT :limit',
            [
                'last'   => max(0, $lastSeenId),
                'settle' => (string) max(0, $settleSeconds),
                'limit'  => max(1, min(500, $limit)),
            ]
        );

        return array_map([self::class, 'decode'], $rows);
    }

    /**
     * Hoechste vergebene ID - damit ein neu installiertes Plugin nicht
     * die ganze Vergangenheit als "neu" abarbeitet.
     */
    public function latestId(): int
    {
        return (int) ($this->app->db->value('SELECT COALESCE(max(id), 0) FROM events') ?? 0);
    }

    /**
     * Vorhandene Event-Typen - fuer die Filterauswahl in der Oberflaeche.
     *
     * @return list<string>
     */
    public function knownTypes(): array
    {
        $rows = $this->app->db->all('SELECT DISTINCT event_type FROM events ORDER BY event_type');

        return array_map(static fn (array $row): string => (string) $row['event_type'], $rows);
    }

    /**
     * @param array{source?: string, event_type?: string, actor?: string} $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private static function filterClause(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (($filters['source'] ?? '') !== '') {
            $conditions[] = 'source = :source';
            $params['source'] = $filters['source'];
        }

        if (($filters['event_type'] ?? '') !== '') {
            $conditions[] = 'event_type = :event_type';
            $params['event_type'] = $filters['event_type'];
        }

        if (($filters['actor'] ?? '') !== '') {
            $conditions[] = 'actor_name ILIKE :actor';
            $params['actor'] = '%' . $filters['actor'] . '%';
        }

        return [$conditions === [] ? '' : 'WHERE ' . implode(' AND ', $conditions), $params];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function decode(array $row): array
    {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        $row['payload'] = is_array($payload) ? $payload : [];

        return $row;
    }
}
