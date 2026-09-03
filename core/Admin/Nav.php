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
                    ['label' => translate('nav.users'),      'href' => '/account/users',      'permission' => 'Account.Users.View'],
                    ['label' => translate('nav.activity'),   'href' => '/account/activities',  'permission' => 'Account.Activity.View'],
                    ['label' => translate('nav.overlay'),     'href' => '/account/overlay',     'permission' => 'Account.Overlay.View'],
                    ['label' => translate('nav.plugins'),       'href' => '/account/plugins',       'permission' => 'Account.Plugins.View'],
                    ['label' => translate('nav.settings'), 'href' => '/account/settings', 'permission' => 'Account.Settings.View'],
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

        // Erst nach der Vorgabe des Plugins, dann nach der Reihenfolge,
        // die der Betreiber eingestellt hat. Ein Bereich, der dort
        // nicht steht - ein neues Plugin -, landet hinten und stoert
        // die eingestellte Reihenfolge nicht.
        $eigene = $this->savedOrder();

        uasort($result, static function (array $a, array $b) use ($eigene, $result): int {
            $platz = static function (array $gruppe) use ($eigene, $result): int {
                $key = (string) array_search($gruppe, $result, true);
                $index = array_search($key, $eigene, true);

                return $index === false ? PHP_INT_MAX : (int) $index;
            };

            return [$platz($a), $a['order']] <=> [$platz($b), $b['order']];
        });

        return $result;
    }

    /**
     * Wie der Betreiber die Bereiche sortiert hat.
     *
     * Nur die Schluessel, keine Namen: welche Bereiche es gibt,
     * entscheiden die Plugins - diese Liste sagt nur, in welcher
     * Reihenfolge. Ein Schluessel darin, den es nicht mehr gibt, bleibt
     * stehen: wird das Plugin neu installiert, landet es wieder an
     * seinem Platz.
     *
     * @return list<string>
     */
    public function savedOrder(): array
    {
        $gespeichert = $this->app->settings->get('nav_order', null);

        if (!is_array($gespeichert)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', $gespeichert)));
    }

    /**
     * Einen Bereich um einen Platz verschieben.
     *
     * Gerechnet wird auf der Liste der Bereiche, die es GERADE gibt -
     * sonst wandert ein Bereich an einem Schluessel vorbei, der zu
     * einem entfernten Plugin gehoert, und nichts scheint zu
     * passieren.
     */
    public function move(string $group, string $direction): bool
    {
        $sichtbar = array_keys($this->build());

        $index = array_search($group, $sichtbar, true);
        if ($index === false) {
            return false;
        }

        $ziel = $direction === 'up' ? $index - 1 : $index + 1;
        if ($ziel < 0 || $ziel >= count($sichtbar)) {
            return false;
        }

        [$sichtbar[$index], $sichtbar[$ziel]] = [$sichtbar[$ziel], $sichtbar[$index]];

        // Schluessel entfernter Plugins hinten anhaengen, damit ihr
        // Platz nicht verloren geht.
        $rest = array_values(array_diff($this->savedOrder(), $sichtbar));

        $this->app->settings->set('nav_order', array_merge($sichtbar, $rest));

        return true;
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
