<?php

declare(strict_types=1);

namespace Overlays\Core\Obs;

use Overlays\Core\App;

/**
 * Der Filterbaum ueber dem Feed.
 *
 * Zwei Ebenen: Gruppe (Subs) und darin Auswahlpunkte
 * (subs.tiered.t1, subs.prime, ...). Die Schluessel sind dieselben, die
 * der Presenter je Ereignis zurueckgibt.
 *
 * Plugins ergaenzen eigene Gruppen oder haengen sich in vorhandene:
 *
 *   $hooks->on('core.obs.filters', function (array $groups) {
 *       $groups['spenden'] = [
 *           'label' => 'Spenden',
 *           'order' => 30,
 *           'items' => [
 *               'paypal.named'     => 'Mit Namen',
 *               'paypal.anonymous' => 'Anonym',
 *           ],
 *       ];
 *       return $groups;
 *   });
 */
final class Filters
{
    /** Zeitraeume fuer die Auswahl. */
    public const RANGES = [
        '1d'  => ['label' => '1 Tag',        'interval' => '1 day'],
        '3d'  => ['label' => '3 Tage',       'interval' => '3 days'],
        '7d'  => ['label' => '7 Tage',       'interval' => '7 days'],
        '1m'  => ['label' => '1 Monat',      'interval' => '1 month'],
        'all' => ['label' => 'Gesamte Zeit', 'interval' => null],
    ];

    public const DEFAULT_RANGE = '7d';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array<string, array{label: string, order: int, items: array<string, string>}>
     */
    public function groups(): array
    {
        $groups = [
            'follows' => [
                'label' => 'Follows',
                'order' => 10,
                'items' => [
                    'follows.new' => 'Neue Follows',
                ],
            ],
            'subs' => [
                'label' => 'Abos',
                'order' => 20,
                'items' => [
                    'subs.tiered.t1'       => 'Stufe 1',
                    'subs.tiered.t2'       => 'Stufe 2',
                    'subs.tiered.t3'       => 'Stufe 3',
                    'subs.prime'           => 'Prime',
                    'subs.gifted.sent'     => 'Verschenkt',
                    'subs.gifted.received' => 'Geschenk erhalten',
                    'subs.end'             => 'Beendet',
                ],
            ],
            'bits' => [
                'label' => 'Bits',
                'order' => 30,
                'items' => [
                    'bits' => 'Bits',
                ],
            ],
            'raids' => [
                'label' => 'Raids',
                'order' => 40,
                'items' => [
                    'raids' => 'Eingehende Raids',
                ],
            ],
            'stream' => [
                'label' => 'Stream',
                'order' => 50,
                'items' => [
                    'stream.online'  => 'Gestartet',
                    'stream.offline' => 'Beendet',
                ],
            ],
            'sonstiges' => [
                'label' => 'Sonstiges',
                'order' => 90,
                'items' => [
                    'system' => 'Noch nicht zugeordnet',
                ],
            ],
        ];

        $filtered = $this->app->hooks->filter('core.obs.filters', $groups);
        if (!is_array($filtered)) {
            $filtered = $groups;
        }

        $clean = [];
        foreach ($filtered as $key => $group) {
            if (!is_array($group) || !is_array($group['items'] ?? null) || $group['items'] === []) {
                continue;
            }

            $items = [];
            foreach ($group['items'] as $itemKey => $label) {
                $items[(string) $itemKey] = trim((string) $label);
            }

            $clean[(string) $key] = [
                'label' => trim((string) ($group['label'] ?? $key)),
                'order' => (int) ($group['order'] ?? 60),
                'items' => $items,
            ];
        }

        uasort($clean, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $clean;
    }

    /**
     * Alle Auswahlschluessel flach.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->groups() as $group) {
            foreach (array_keys($group['items']) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    /**
     * Auswahl aus der Adresszeile lesen. Nichts ausgewaehlt heisst
     * "alles zeigen" - so ist ein nackter Link auf den Feed sinnvoll.
     *
     * @param array<string, mixed> $query
     * @return list<string>
     */
    public function selected(array $query): array
    {
        $raw = $query['f'] ?? null;
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }

        $wanted = array_map('trim', explode(',', $raw));

        return array_values(array_intersect($this->keys(), $wanted));
    }

    public function range(array $query): string
    {
        $range = is_string($query['zeitraum'] ?? null) ? $query['zeitraum'] : '';

        return isset(self::RANGES[$range]) ? $range : self::DEFAULT_RANGE;
    }

    public function interval(string $range): ?string
    {
        // Bewusst array_key_exists statt ?? - bei "Gesamte Zeit" ist der
        // Wert null, und mit ?? wuerde stattdessen der Standardzeitraum
        // greifen. Der Feed haette dann trotz "alles" nur 7 Tage gezeigt.
        if (!array_key_exists($range, self::RANGES)) {
            $range = self::DEFAULT_RANGE;
        }

        return self::RANGES[$range]['interval'];
    }
}
