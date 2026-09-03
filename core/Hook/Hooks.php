<?php

declare(strict_types=1);

namespace TwitchController\Core\Hook;

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
 * Ein Zuhoerer, der eine Exception wirft, wird gemeldet und
 * uebersprungen - bei filter() bleibt der Wert dann unveraendert.
 * Sonst nimmt ein einziges kaputtes Plugin jede Seite mit, auf der
 * sein Hook laeuft.
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

    /**
     * Wohin gemeldet wird, wenn ein Zuhoerer scheitert. Wird von App
     * gesetzt; ohne das geht es an error_log.
     */
    private mixed $logger = null;

    public function setLogger(callable $logger): void
    {
        $this->logger = $logger;
    }

    public function dispatch(string $hook, mixed ...$args): void
    {
        foreach ($this->sorted($hook) as $listener) {
            try {
                ($listener['fn'])(...$args);
            } catch (\Throwable $e) {
                $this->melde($hook, $listener['source'], $e);
            }
        }
    }

    public function filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        foreach ($this->sorted($hook) as $listener) {
            try {
                $value = ($listener['fn'])($value, ...$args);
            } catch (\Throwable $e) {
                // Der Wert bleibt, wie er war - dieser Zuhoerer hat
                // eben nichts beigetragen.
                $this->melde($hook, $listener['source'], $e);
            }
        }

        return $value;
    }

    /**
     * Ein Fehler in einem Plugin darf die Verwaltung nicht unbenutzbar
     * machen. Deshalb wird er gemeldet und uebersprungen, nicht
     * weitergeworfen.
     *
     * Das ist bewusst keine stille Unterdrueckung: die Meldung nennt
     * Hook, Plugin und Ursache - genau die drei Angaben, mit denen man
     * es findet. Ohne diese Isolierung reisst ein einziges kaputtes
     * Plugin jede Seite mit, auf der sein Hook laeuft, und man kommt
     * nicht mehr dorthin, wo man es abschalten koennte.
     */
    private function melde(string $hook, string $source, \Throwable $e): void
    {
        $text = sprintf(
            'Hook "%s": %s ist gescheitert - %s in %s:%d',
            $hook,
            $source === 'core' ? 'der Kern' : 'Plugin "' . $source . '"',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );

        if (is_callable($this->logger)) {
            ($this->logger)($text);

            return;
        }

        error_log('[twitch-controller] ' . $text);
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
