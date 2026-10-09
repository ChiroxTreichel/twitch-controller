<?php

declare(strict_types=1);

namespace TwitchController\Core\Admin;

use Throwable;
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
 *
 * Die Reihenfolge gehoert dem Betreiber, nicht dem Plugin - fuer die
 * Bereiche wie fuer die Punkte darin. Ein Plugin weiss nicht, was
 * sonst noch im Menue steht, und wenn jedes nach oben will, steht
 * keines oben. Siehe rememberItems().
 *
 * Ausgenommen ist "Konto": es steht immer ganz unten, und seine Punkte
 * haben die feste Reihenfolge des Kerns. Dort liegt die Verwaltung des
 * Systems selbst - sie soll man immer an derselben Stelle finden, auch
 * nach dem zehnten Plugin.
 */
final class Nav
{
    /** Der Bereich des Kerns. Der Schluessel ist alt und bleibt deutsch. */
    public const ACCOUNT = 'konto';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @return array<string, array{label: string, order: int, items: list<array{label: string, href: string, key: string}>}>
     */
    public function build(): array
    {
        $nav = [
            self::ACCOUNT => [
                'label' => translate('nav.account'),
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

        // Gemerkt am vollstaendigen Menue, VOR dem Ausblenden nach
        // Rechten - sonst legte der Erste, der einen Punkt nicht sehen
        // darf, ohne ihn dessen Platz fest.
        $punkte = $this->rememberItems($filtered);

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
                    'key'    => self::itemKey($item),
                    'toggle' => $this->normalizeToggle($item['toggle'] ?? null),
                ];
            }

            if ($items === []) {
                continue;
            }

            $result[(string) $key] = [
                'label' => (string) ($group['label'] ?? $key),
                'order' => (int) ($group['order'] ?? 50),
                'items' => (string) $key === self::ACCOUNT
                    ? $items
                    : self::sortItems($items, $punkte[(string) $key] ?? []),
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

        // Konto immer ans Ende - egal, was in nav_order steht oder ein
        // Plugin als order setzt.
        if (isset($result[self::ACCOUNT])) {
            $konto = $result[self::ACCOUNT];
            unset($result[self::ACCOUNT]);
            $result[self::ACCOUNT] = $konto;
        }

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
     *
     * Konto zaehlt nicht mit: es laesst sich nicht verschieben, und
     * nichts laesst sich unter es schieben.
     */
    public function move(string $group, string $direction): bool
    {
        $sichtbar = array_values(array_diff(array_keys($this->build()), [self::ACCOUNT]));

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
        $rest = array_values(array_diff($this->savedOrder(), $sichtbar, [self::ACCOUNT]));

        $this->app->settings->set('nav_order', array_merge($sichtbar, $rest));

        return true;
    }

    // -----------------------------------------------------------------
    //  Punkte innerhalb eines Bereichs
    // -----------------------------------------------------------------

    /**
     * Die gemerkte Reihenfolge der Punkte, je Bereich.
     *
     * Wie bei den Bereichen nur Schluessel, und ein Schluessel, den es
     * nicht mehr gibt, bleibt stehen: wird das Plugin neu installiert,
     * steht sein Punkt wieder dort, wo er war.
     *
     * @return array<string, list<string>>
     */
    public function savedItemOrder(): array
    {
        $gespeichert = $this->app->settings->get('nav_item_order', null);

        if (!is_array($gespeichert)) {
            return [];
        }

        $reihenfolge = [];
        foreach ($gespeichert as $gruppe => $punkte) {
            if (is_array($punkte)) {
                $reihenfolge[(string) $gruppe] = array_values(array_map('strval', array_filter($punkte, 'is_scalar')));
            }
        }

        return $reihenfolge;
    }

    /**
     * Neue Punkte hinten an ihren Bereich anhaengen und merken.
     *
     * Innerhalb eines Bereichs gilt die Reihenfolge, in der die Punkte
     * zum ersten Mal auftauchten. Ohne das entschiede die
     * Ladereihenfolge der Plugins, und die geht nach dem Slug: ein
     * neues "mod-chat" stuende ueber "streaminfo", nur weil m vor s
     * kommt - der Punkt, fuer den man den Bereich aufklappt, rutschte
     * nach unten, ohne dass jemand etwas getan hat.
     *
     * Ein Plugin bekommt dafuer bewusst KEIN Feld fuer seinen Platz.
     * Verschieben kann nur der Betreiber, unter Einstellungen > System -
     * er sieht, was sonst noch im Bereich steht.
     *
     * Geschrieben wird nur, wenn wirklich ein neuer Punkt da ist - also
     * einmal nach dem Installieren eines Plugins, nicht bei jedem
     * Seitenaufruf.
     *
     * @param array<mixed> $nav Ergebnis von admin.nav, noch ungefiltert
     * @return array<string, list<string>>
     */
    private function rememberItems(array $nav): array
    {
        $gespeichert = $this->savedItemOrder();
        $reihenfolge = $gespeichert;

        // Konto hat die feste Reihenfolge des Kerns und steht hier
        // nicht drin. Ein Eintrag aus der Zeit davor wird beim naechsten
        // Speichern mit abgeraeumt.
        unset($reihenfolge[self::ACCOUNT]);

        foreach ($nav as $gruppe => $bereich) {
            if (!is_array($bereich) || (string) $gruppe === self::ACCOUNT) {
                continue;
            }

            $punkte = $reihenfolge[(string) $gruppe] ?? [];

            foreach ((array) ($bereich['items'] ?? []) as $item) {
                if (!is_array($item) || ($item['href'] ?? '') === '') {
                    continue;
                }

                $schluessel = self::itemKey($item);
                if (!in_array($schluessel, $punkte, true)) {
                    $punkte[] = $schluessel;
                }
            }

            if ($punkte !== []) {
                $reihenfolge[(string) $gruppe] = $punkte;
            }
        }

        if ($reihenfolge !== $gespeichert) {
            // Mit Netz: das Menue steht auf jeder Seite, und eine
            // Reihenfolge, die sich nicht merken liess, ist kein Grund,
            // es verschwinden zu lassen. Der neue Punkt steht dann bis
            // zum naechsten Aufruf eben dort, wo er geladen wurde.
            try {
                $this->app->settings->set('nav_item_order', $reihenfolge);
            } catch (Throwable $fehler) {
                $this->app->log('Menue: Reihenfolge der Punkte nicht gespeichert - ' . $fehler->getMessage());
            }
        }

        return $reihenfolge;
    }

    /**
     * Einen Punkt innerhalb seines Bereichs um einen Platz verschieben.
     *
     * Getauscht wird mit dem naechsten SICHTBAREN Nachbarn. Punkte, die
     * gerade fehlen - Plugin aus, Recht fehlt -, behalten ihre Stelle
     * in der Liste; sonst wanderte der Punkt an ihnen vorbei, und
     * scheinbar passierte nichts.
     */
    public function moveItem(string $group, string $item, string $direction): bool
    {
        $bereiche = $this->build();
        if ($group === self::ACCOUNT || !isset($bereiche[$group])) {
            return false;
        }

        $sichtbar = array_column($bereiche[$group]['items'], 'key');

        $index = array_search($item, $sichtbar, true);
        if ($index === false) {
            return false;
        }

        $ziel = $direction === 'up' ? $index - 1 : $index + 1;
        if ($ziel < 0 || $ziel >= count($sichtbar)) {
            return false;
        }

        // build() hat eben jeden Punkt gemerkt - beide stehen also in
        // der Liste.
        $alle = $this->savedItemOrder();
        $punkte = $alle[$group] ?? [];

        $hier = array_search($item, $punkte, true);
        $dort = array_search($sichtbar[$ziel], $punkte, true);
        if ($hier === false || $dort === false) {
            return false;
        }

        [$punkte[$hier], $punkte[$dort]] = [$punkte[$dort], $punkte[$hier]];
        $alle[$group] = $punkte;

        $this->app->settings->set('nav_item_order', $alle);

        return true;
    }

    /** Woran ein Punkt erkannt wird - beim Merken wie beim Anzeigen. */
    private static function itemKey(array $item): string
    {
        return (string) ($item['key'] ?? trim((string) $item['href'], '/'));
    }

    /**
     * Punkte in die gemerkte Reihenfolge bringen.
     *
     * @param list<array<string, mixed>> $items
     * @param list<string> $reihenfolge
     * @return list<array<string, mixed>>
     */
    private static function sortItems(array $items, array $reihenfolge): array
    {
        $platz = array_flip($reihenfolge);

        // usort ist seit PHP 8 stabil: was nicht in der Liste steht -
        // weil sie sich nicht speichern liess -, behaelt untereinander
        // die Ladereihenfolge.
        usort($items, static fn (array $a, array $b): int =>
            ($platz[$a['key']] ?? PHP_INT_MAX) <=> ($platz[$b['key']] ?? PHP_INT_MAX));

        return $items;
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
