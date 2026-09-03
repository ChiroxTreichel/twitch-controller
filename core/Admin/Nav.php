<?php

declare(strict_types=1);

namespace TwitchController\Core\Admin;

use TwitchController\Core\App;

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
                'label' => translate('nav.account'),
                'order' => 0,
                'items' => [
                    ['label' => translate('nav.users'),      'href' => '/account/users',      'permission' => 'Konto.Benutzer.View'],
                    ['label' => translate('nav.activity'),   'href' => '/account/activities',  'permission' => 'Konto.Aktivitaeten.View'],
                    ['label' => translate('nav.overlay'),     'href' => '/account/overlay',     'permission' => 'Konto.Overlay.View'],
                    ['label' => translate('nav.plugins'),       'href' => '/account/plugins',       'permission' => 'Konto.Plugins.View'],
                    ['label' => translate('nav.settings'), 'href' => '/account/settings', 'permission' => 'Konto.Einstellungen.View'],
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
                    'label'  => (string) ($item['label'] ?? $item['href']),
                    'href'   => (string) $item['href'],
                    'key'    => (string) ($item['key'] ?? trim((string) $item['href'], '/')),
                    'toggle' => $this->normalizeToggle($item['toggle'] ?? null),
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
     * Schnellschalter eines Menuepunktes.
     *
     * Damit kann ein Plugin sich von jeder Seite aus abschalten
     * lassen - praktisch, wenn im Stream gerade Ruhe sein soll und man
     * nicht erst zur Einstellungsseite navigieren will.
     *
     * Ein Plugin meldet ihn am Menuepunkt an:
     *
     *   'toggle' => [
     *       'on'         => Alerts::enabled($app),
     *       'action'     => '/display/alerts',   // Ziel des Formulars
     *       'value'      => 'toggle',            // Wert fuer "action"
     *       'permission' => 'Alerts.Global.Toggle',
     *       'title'      => translate('alerts.toggle_hint'),
     *   ]
     *
     * Ohne das Recht erscheint kein Schalter - der Menuepunkt selbst
     * bleibt. Das Formular schickt der Kern samt CSRF-Kennung ab; das
     * Plugin muss nur seine POST-Route haben, die es fuer die eigene
     * Seite ohnehin hat.
     *
     * @return array{action: string, value: string, on: bool, title: string}|null
     */
    private function normalizeToggle(mixed $toggle): ?array
    {
        if (!is_array($toggle)) {
            return null;
        }

        $action = trim((string) ($toggle['action'] ?? ''));

        // Nur eigene Adressen: der Schalter steht auf jeder Seite, ein
        // fremdes Ziel waere ein Formular, das ungefragt nach draussen
        // schickt.
        if ($action === '' || !str_starts_with($action, '/')) {
            return null;
        }

        $permission = (string) ($toggle['permission'] ?? '');
        if ($permission !== '' && !$this->app->auth->can($permission)) {
            return null;
        }

        return [
            'action' => $action,
            'value'  => trim((string) ($toggle['value'] ?? 'toggle')),
            'on'     => (bool) ($toggle['on'] ?? false),
            'title'  => trim((string) ($toggle['title'] ?? '')),
        ];
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

        return '/account';
    }
}
