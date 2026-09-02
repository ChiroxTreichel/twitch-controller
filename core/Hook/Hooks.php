<?php

declare(strict_types=1);

namespace Overlays\Core\Hook;

/**
 * Hook-Verteiler: die einzige Stelle, an der Plugins den Kern erweitern.
 *
 * Es gibt zwei Sorten:
 *
 *   dispatch()  "es ist etwas passiert" - Rueckgabewerte werden ignoriert.
 *               Beispiel: core.twitch.event, cron.tick, plugin.activated
 *
 *   filter()    "hier ist ein Wert, gib ihn veraendert zurueck".
 *               Beispiel: admin.nav, permissions.catalog, overlay.widgets
 *
 * Kleinere Prioritaet laeuft frueher; gleiche Prioritaet in
 * Registrierungsreihenfolge. Plugins werden alphabetisch nach Slug
 * geladen, deshalb darf sich kein Plugin auf die Reihenfolge eines
 * anderen verlassen - dafuer sind Prioritaeten da.
 */
final class Hooks
{
    /** @var array<string, list<array{priority: int, seq: int, source: string, fn: callable}>> */
    private array $listeners = [];

    private int $sequence = 0;

    /**
     * Quelle, die aktuell registriert (Plugin-Slug oder "core"). Wird von
     * der Plugin-Ladelogik gesetzt, damit die Uebersichtsseite zeigen
     * kann, wer an welchem Hook haengt.
     */
    private string $source = 'core';

    public function withSource(string $source, callable $work): void
    {
        $previous = $this->source;
        $this->source = $source;

        try {
            $work($this);
        } finally {
            $this->source = $previous;
        }
    }

    public function on(string $hook, callable $fn, int $priority = 10): void
    {
        $this->listeners[$hook][] = [
            'priority' => $priority,
            'seq'      => $this->sequence++,
            'source'   => $this->source,
            'fn'       => $fn,
        ];
    }

    public function dispatch(string $hook, mixed ...$args): void
    {
        foreach ($this->sorted($hook) as $listener) {
            ($listener['fn'])(...$args);
        }
    }

    public function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->sorted($hook) as $listener) {
            $value = ($listener['fn'])($value, ...$args);
        }

        return $value;
    }

    public function has(string $hook): bool
    {
        return ($this->listeners[$hook] ?? []) !== [];
    }

    /**
     * Uebersicht fuer die Adminoberflaeche: Hook => Liste der Quellen.
     *
     * @return array<string, list<string>>
     */
    public function map(): array
    {
        $map = [];
        foreach ($this->listeners as $hook => $listeners) {
            foreach ($listeners as $listener) {
                $map[$hook][] = $listener['source'];
            }
            $map[$hook] = array_values(array_unique($map[$hook]));
        }
        ksort($map);

        return $map;
    }

    /**
     * @return list<array{priority: int, seq: int, source: string, fn: callable}>
     */
    private function sorted(string $hook): array
    {
        $listeners = $this->listeners[$hook] ?? [];
        if ($listeners === []) {
            return [];
        }

        usort($listeners, static function (array $a, array $b): int {
            return [$a['priority'], $a['seq']] <=> [$b['priority'], $b['seq']];
        });

        return $listeners;
    }
}
