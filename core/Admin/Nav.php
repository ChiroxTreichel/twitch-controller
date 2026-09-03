<?php

declare(strict_types=1);

namespace Overlays\Core\Admin;

use Overlays\Core\App;

/**
 * Navigation der Adminoberflaeche.
 *
 * Der Kern bringt nur "Konto" mit. Plugins haengen eigene Gruppen an:
 *
 *   $hooks->on('admin.nav', function (array $nav) {
 *       $nav['alerts'] = [
 *           'label' => 'Alerts',
 *           'order' => 20,
 *           'items' => [
 *               ['label' => 'Follow', 'href' => '/alerts/follow',
 *                'permission' => 'Alerts.Follow.View'],
 *           ],
 *       ];
 *       return $nav;
 *   });
 *
 * Eintraege ohne passendes Recht werden ausgeblendet; eine Gruppe ohne
 * sichtbare Eintraege verschwindet ganz.
 */
final class Nav
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array<string, array{label: string, order: int, items: list<array{label: string, href: string, key: string}>}>
     */
    public function build(): array
    {
        $nav = [
            'konto' => [
                'label' => translate('Konto'),
                'order' => 0,
                'items' => [
                    ['label' => translate('Benutzer'),      'href' => '/konto/benutzer',      'permission' => 'Konto.Benutzer.View'],
                    ['label' => translate('Aktivitäten'),   'href' => '/konto/aktivitaeten',  'permission' => 'Konto.Aktivitaeten.View'],
                    ['label' => translate('Plugins'),       'href' => '/konto/plugins',       'permission' => 'Konto.Plugins.View'],
                    ['label' => translate('Einstellungen'), 'href' => '/konto/einstellungen', 'permission' => 'Konto.Einstellungen.View'],
                ],
            ],
        ];

        $filtered = $this->app->hooks->filter('admin.nav', $nav);
        if (!is_array($filtered)) {
            $filtered = $nav;
        }

        $result = [];
        foreach ($filtered as $key => $group) {
            if (!is_array($group)) {
                continue;
            }

            $items = [];
            foreach ((array) ($group['items'] ?? []) as $item) {
                if (!is_array($item) || ($item['href'] ?? '') === '') {
                    continue;
                }

                $permission = (string) ($item['permission'] ?? '');
                if ($permission !== '' && !$this->app->auth->can($permission)) {
                    continue;
                }

                $items[] = [
                    'label' => (string) ($item['label'] ?? $item['href']),
                    'href'  => (string) $item['href'],
                    'key'   => (string) ($item['key'] ?? trim((string) $item['href'], '/')),
                ];
            }

            if ($items === []) {
                continue;
            }

            $result[(string) $key] = [
                'label' => (string) ($group['label'] ?? $key),
                'order' => (int) ($group['order'] ?? 50),
                'items' => $items,
            ];
        }

        uasort($result, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $result;
    }

    /**
     * Erste Seite, die der angemeldete Benutzer sehen darf - Ziel nach
     * dem Login und bei einem Aufruf von "/".
     */
    public function firstAllowedHref(): string
    {
        foreach ($this->build() as $group) {
            foreach ($group['items'] as $item) {
                return $item['href'];
            }
        }

        return '/konto';
    }
}
