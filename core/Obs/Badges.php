<?php

declare(strict_types=1);

namespace TwitchController\Core\Obs;

use TwitchController\Core\App;

/**
 * Farben der Badges im Aktivitaeten-Feed.
 *
 * Der Kern kennt nur seine eigenen Ereignisarten. Plugins mit eigenen
 * Quellen melden ihre Badges dazu:
 *
 *   $hooks->on('core.obs.badges', function (array $badges) {
 *       $badges['paypal'] = [
 *           'label' => 'PayPal',
 *           'bg'    => '#0070ba',
 *           'text'  => '#ffffff',
 *       ];
 *       return $badges;
 *   });
 *
 * Die gewaehlten Farben liegen in den Einstellungen unter
 * obs_badge_<schluessel>_bg und _text.
 */
final class Badges
{
    public function __construct(private readonly App $app)
    {
    }

    /**
     * Vorgaben des Kerns plus alles, was Plugins ergaenzen.
     *
     * @return array<string, array{label: string, bg: string, text: string}>
     */
    public function catalog(): array
    {
        $badges = [
            'follow'         => ['label' => 'Follow',         'bg' => '#1f8b4c', 'text' => '#ffffff'],
            'sub'            => ['label' => 'Sub',            'bg' => '#9146ff', 'text' => '#ffffff'],
            'resub'          => ['label' => 'Resub',          'bg' => '#7a2df0', 'text' => '#ffffff'],
            'prime'          => ['label' => 'Prime',          'bg' => '#00a4dc', 'text' => '#ffffff'],
            'gift'           => ['label' => 'Gift',           'bg' => '#e0a800', 'text' => '#1a1a1a'],
            'gift_anon'      => ['label' => 'Gift anonym',    'bg' => '#8a7300', 'text' => '#ffffff'],
            'gift_received'  => ['label' => 'Gift erhalten',  'bg' => '#c68a00', 'text' => '#1a1a1a'],
            'sub_end'        => ['label' => 'Sub Ende',       'bg' => '#4a4f5a', 'text' => '#ffffff'],
            'bits'           => ['label' => 'Bits',           'bg' => '#d94f8a', 'text' => '#ffffff'],
            'raid'           => ['label' => 'Raid',           'bg' => '#ff6a3d', 'text' => '#1a1a1a'],
            'stream_online'  => ['label' => 'Stream an',      'bg' => '#3ecf8e', 'text' => '#0e1014'],
            'stream_offline' => ['label' => 'Stream aus',     'bg' => '#3a3f4a', 'text' => '#ffffff'],
            'system'         => ['label' => 'System',         'bg' => '#272c36', 'text' => '#98a1b0'],
        ];

        $filtered = $this->app->hooks->filter('core.obs.badges', $badges);
        if (!is_array($filtered)) {
            return $badges;
        }

        // Auf eine feste Form bringen, damit die Oberflaeche sich auf
        // label/bg/text verlassen kann.
        $clean = [];
        foreach ($filtered as $key => $badge) {
            $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) $key)) ?? '';
            if ($key === '' || !is_array($badge)) {
                continue;
            }

            $clean[$key] = [
                'label' => trim((string) ($badge['label'] ?? $key)),
                'bg'    => self::color((string) ($badge['bg'] ?? ''), '#272c36'),
                'text'  => self::color((string) ($badge['text'] ?? ''), '#ffffff'),
            ];
        }

        return $clean;
    }

    /**
     * Tatsaechlich verwendete Farben: gespeicherte Wahl, sonst Vorgabe.
     *
     * @return array{bg: string, text: string}
     */
    public function colors(string $key): array
    {
        $catalog = $this->catalog();
        $preset = $catalog[$key] ?? $catalog['system'] ?? ['bg' => '#272c36', 'text' => '#ffffff'];

        return [
            'bg'   => self::color($this->app->settings->string('obs_badge_' . $key . '_bg'), $preset['bg']),
            'text' => self::color($this->app->settings->string('obs_badge_' . $key . '_text'), $preset['text']),
        ];
    }

    /**
     * Fertiges style-Attribut fuer ein Badge.
     */
    public function style(string $key): string
    {
        $colors = $this->colors($key);

        return sprintf('background:%s;color:%s;', $colors['bg'], $colors['text']);
    }

    /**
     * Alle Farben auf einmal - fuer die Feed-Seite, die sie als
     * CSS-Variablen ausgibt statt pro Zeile ein style-Attribut zu bauen.
     *
     * @return array<string, array{label: string, bg: string, text: string}>
     */
    public function resolved(): array
    {
        $resolved = [];

        foreach ($this->catalog() as $key => $preset) {
            $colors = $this->colors($key);
            $resolved[$key] = [
                'label' => $preset['label'],
                'bg'    => $colors['bg'],
                'text'  => $colors['text'],
            ];
        }

        return $resolved;
    }

    /**
     * Speichert die Farbwahl. Unbekannte Schluessel und ungueltige
     * Farbwerte werden verworfen.
     *
     * @param array<string, mixed> $input Formulardaten
     */
    public function save(array $input): int
    {
        $saved = 0;

        foreach (array_keys($this->catalog()) as $key) {
            foreach (['bg', 'text'] as $part) {
                $field = 'obs_badge_' . $key . '_' . $part;
                $value = $input[$field] ?? null;

                if (!is_string($value) || !self::isColor($value)) {
                    continue;
                }

                $this->app->settings->set($field, strtolower($value));
                $saved++;
            }
        }

        return $saved;
    }

    /**
     * Alle Farben auf die Vorgaben zuruecksetzen.
     */
    public function reset(): void
    {
        foreach (array_keys($this->catalog()) as $key) {
            $this->app->settings->forget('obs_badge_' . $key . '_bg');
            $this->app->settings->forget('obs_badge_' . $key . '_text');
        }
    }

    private static function isColor(string $value): bool
    {
        return preg_match('/^#[0-9a-fA-F]{6}$/', trim($value)) === 1;
    }

    private static function color(string $value, string $default): string
    {
        return self::isColor($value) ? strtolower(trim($value)) : $default;
    }
}
