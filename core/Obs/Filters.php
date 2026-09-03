<?php

declare(strict_types=1);

namespace Overlays\Core\Obs;

use Overlays\Core\App;

/**
 * Der Filterbaum ueber dem Feed.
 *
 * Beliebig tief, und Elternknoten sind mitschaltbar: ein Haken bei
 * "Subs" nimmt alle Abo-Arten, einer bei "Tiered" nur die drei Stufen.
 * In der Adresse stehen ausschliesslich Blaetter - Elternknoten sind
 * reine Bedienhilfe.
 *
 *   Follows
 *     follows.new
 *   Bits                      <- Blatt direkt oben
 *   Subs
 *     subs.prime
 *     Tiered
 *       subs.tiered.tier1
 *       ...
 *
 * Angemeldet werden Knoten flach, mit Verweis auf den Elternknoten -
 * so kann ein Plugin auch in einen vorhandenen Zweig einhaengen und
 * nicht nur oben etwas anfuegen:
 *
 *   $hooks->on('core.obs.filters', function (array $nodes) {
 *       // in den vorhandenen Zweig "Follows"
 *       $nodes[] = ['key' => 'follows.unfollow', 'label' => 'Unfollow',
 *                   'parent' => 'follows', 'order' => 20];
 *       // eigener Zweig oben
 *       $nodes[] = ['key' => 'paypal', 'label' => 'PayPal', 'order' => 35];
 *       $nodes[] = ['key' => 'paypal.named', 'label' => 'Mit Twitch-Name',
 *                   'parent' => 'paypal'];
 *       return $nodes;
 *   });
 *
 * Ob ein Knoten Blatt ist, ergibt sich daraus, ob ihn jemand als
 * Elternknoten nennt. Ein Plugin kann damit aus "Bits" nachtraeglich
 * eine Gruppe machen, ohne dass der Kern etwas davon wissen muss.
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

    /** @var list<array{key: string, label: string, parent: ?string, order: int}>|null */
    private ?array $nodes = null;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Alle angemeldeten Knoten, flach.
     *
     * Die Schluessel sind bewusst dieselben wie im alten obs.php, damit
     * gespeicherte Links weiter funktionieren.
     *
     * @return list<array{key: string, label: string, parent: ?string, order: int}>
     */
    public function nodes(): array
    {
        if ($this->nodes !== null) {
            return $this->nodes;
        }

        $nodes = [
            ['key' => 'follows',           'label' => 'Follows',   'order' => 10],
            ['key' => 'follows.new',       'label' => 'Follow',    'parent' => 'follows', 'order' => 10],

            ['key' => 'bits',              'label' => 'Bits',      'order' => 20],

            ['key' => 'subs',              'label' => 'Subs',      'order' => 30],
            ['key' => 'subs.prime',        'label' => 'Prime',     'parent' => 'subs', 'order' => 10],
            ['key' => 'subs.tiered',       'label' => 'Tiered',    'parent' => 'subs', 'order' => 20],
            ['key' => 'subs.tiered.tier1', 'label' => 'Tier 1',    'parent' => 'subs.tiered', 'order' => 10],
            ['key' => 'subs.tiered.tier2', 'label' => 'Tier 2',    'parent' => 'subs.tiered', 'order' => 20],
            ['key' => 'subs.tiered.tier3', 'label' => 'Tier 3',    'parent' => 'subs.tiered', 'order' => 30],
            ['key' => 'subs.gifted',       'label' => 'Gifted',    'parent' => 'subs', 'order' => 30],
            ['key' => 'subs.gifted.sent',     'label' => 'Gesendet',  'parent' => 'subs.gifted', 'order' => 10],
            ['key' => 'subs.gifted.received', 'label' => 'Empfangen', 'parent' => 'subs.gifted', 'order' => 20],
            ['key' => 'subs.end',          'label' => 'Sub Ende',  'parent' => 'subs', 'order' => 40],

            ['key' => 'raids',             'label' => 'Raids',     'order' => 60],

            ['key' => 'system',            'label' => 'System',    'order' => 90],
            ['key' => 'system.stream',     'label' => 'Stream',    'parent' => 'system', 'order' => 20],
            ['key' => 'system.other',      'label' => 'Sonstiges', 'parent' => 'system', 'order' => 90],
        ];

        $filtered = $this->app->hooks->filter('core.obs.filters', $nodes);
        if (!is_array($filtered)) {
            $filtered = $nodes;
        }

        // Auf eine feste Form bringen; spaetere Anmeldung mit gleichem
        // Schluessel gewinnt, damit ein Plugin eine Beschriftung
        // ueberschreiben kann.
        $clean = [];
        foreach ($filtered as $node) {
            if (!is_array($node)) {
                continue;
            }

            $key = trim((string) ($node['key'] ?? ''));
            if ($key === '' || preg_match('/^[a-z0-9][a-z0-9._-]*$/', $key) !== 1) {
                continue;
            }

            $parent = trim((string) ($node['parent'] ?? ''));

            $clean[$key] = [
                'key'    => $key,
                'label'  => trim((string) ($node['label'] ?? $key)),
                'parent' => $parent === '' ? null : $parent,
                'order'  => (int) ($node['order'] ?? 50),
            ];
        }

        // Verwaiste Knoten (Elternteil nicht angemeldet) nach oben
        // hängen, statt sie zu verschlucken.
        foreach ($clean as $key => $node) {
            if ($node['parent'] !== null && !isset($clean[$node['parent']])) {
                $clean[$key]['parent'] = null;
            }
        }

        return $this->nodes = array_values($clean);
    }

    /**
     * Verschachtelte Form fuer die Anzeige.
     *
     * @return list<array{key: string, label: string, children: list<array<string, mixed>>}>
     */
    public function tree(): array
    {
        $byParent = [];
        foreach ($this->nodes() as $node) {
            $byParent[$node['parent'] ?? ''][] = $node;
        }

        foreach ($byParent as $parent => $children) {
            usort($byParent[$parent], static function (array $a, array $b): int {
                return [$a['order'], $a['label']] <=> [$b['order'], $b['label']];
            });
        }

        $build = static function (string $parent) use (&$build, $byParent): array {
            $branch = [];

            foreach ($byParent[$parent] ?? [] as $node) {
                $branch[] = [
                    'key'      => $node['key'],
                    'label'    => $node['label'],
                    'children' => $build($node['key']),
                ];
            }

            return $branch;
        };

        return $build('');
    }

    /**
     * Alle Blaetter - also Knoten, die niemand als Elternteil nennt.
     * Nur diese landen in der Adresse.
     *
     * @return list<string>
     */
    public function leaves(): array
    {
        $parents = [];
        foreach ($this->nodes() as $node) {
            if ($node['parent'] !== null) {
                $parents[$node['parent']] = true;
            }
        }

        $leaves = [];
        foreach ($this->nodes() as $node) {
            if (!isset($parents[$node['key']])) {
                $leaves[] = $node['key'];
            }
        }

        return $leaves;
    }

    /**
     * Blaetter unter einem Knoten - fuer die Elternhaken in der Anzeige.
     *
     * @return list<string>
     */
    public function leavesOf(string $key): array
    {
        $children = [];
        foreach ($this->nodes() as $node) {
            if ($node['parent'] === $key) {
                $children[] = $node['key'];
            }
        }

        if ($children === []) {
            return [$key];
        }

        $leaves = [];
        foreach ($children as $child) {
            foreach ($this->leavesOf($child) as $leaf) {
                $leaves[] = $leaf;
            }
        }

        return $leaves;
    }

    /**
     * Auswahl aus der Adresse.
     *
     * Wichtig ist der Unterschied zwischen "kein Parameter" und
     * "Parameter mit leerer Auswahl":
     *
     *   /obs                  -> alles zeigen
     *   /obs?filter=bits      -> nur Bits
     *   /obs?filter=          -> nichts zeigen (alles abgewaehlt)
     *
     * Ohne diese Unterscheidung koennte man nicht alles abwaehlen, und
     * ein nackter Link auf den Feed wuerde nichts anzeigen.
     *
     * @param array<string, mixed> $query
     * @return list<string>
     */
    public function selected(array $query): array
    {
        if (!array_key_exists('filter', $query)) {
            return $this->leaves();
        }

        $raw = $query['filter'];
        $wanted = is_array($raw)
            ? array_map('strval', $raw)
            : explode(',', (string) $raw);

        $wanted = array_map(static fn (string $v): string => strtolower(trim($v)), $wanted);

        // Elternknoten in der Adresse werden zu ihren Blaettern
        // aufgeloest - so bleiben aeltere Links gueltig, und ein
        // handgeschriebenes ?filter=subs funktioniert auch.
        $leaves = [];
        foreach ($wanted as $key) {
            foreach ($this->leavesOf($key) as $leaf) {
                if (in_array($leaf, $this->leaves(), true)) {
                    $leaves[$leaf] = true;
                }
            }
        }

        return array_keys($leaves);
    }

    /**
     * Ist die Auswahl vollstaendig? Dann muss sie nicht in die Adresse.
     *
     * @param list<string> $selected
     */
    public function isAll(array $selected): bool
    {
        $leaves = $this->leaves();

        return count($selected) === count($leaves) && array_diff($leaves, $selected) === [];
    }

    /**
     * @param array<string, mixed> $query
     */
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
