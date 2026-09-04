<?php

declare(strict_types=1);

namespace TwitchController\Core\Http;

use TwitchController\Core\App;
use TwitchController\Core\I18n\Translator;
use TwitchController\Core\Twitch\TokenStore;
use RuntimeException;
use Throwable;

/**
 * Sehr einfacher Renderer: PHP-Dateien als Vorlagen, ohne Template-
 * Sprache. In der Vorlage stehen zur Verfuegung:
 *
 *   $app     die Anwendung
 *   $e       Escaping-Funktion:  <?= $e($name) ?>
 *   $url     Link-Helfer:        <?= $url('/account/users') ?>
 *   dazu alle uebergebenen Daten
 *
 * Plugins koennen ihre eigenen Vorlagen rendern:
 *   $app->view->from($plugin->directory . '/views')->render('seite', [...]);
 */
final class View
{
    /** @var list<string> */
    private array $paths;

    /**
     * @param list<string> $paths
     */
    public function __construct(
        private readonly App $app,
        array $paths,
    ) {
        $this->paths = $paths;
    }

    /**
     * Renderer, der zuerst im angegebenen Verzeichnis sucht und danach
     * in den Kern-Vorlagen (damit Plugins das Layout mitbenutzen).
     */
    public function from(string $directory): self
    {
        return new self($this->app, array_merge([rtrim($directory, '/')], $this->paths));
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->capture($template, $data);

        if ($layout === null) {
            return $content;
        }

        // Das Layout sieht dieselben Daten wie die Seite, plus den
        // gerenderten Inhalt - so kann es z.B. den Einrichtungsschritt
        // oder den aktiven Menuepunkt kennen.
        return $this->capture($layout, array_merge($data, [
            'content' => $content,
            'title'   => (string) ($data['title'] ?? ''),
            'active'  => (string) ($data['active'] ?? ''),
        ]));
    }

    /**
     * Eigene CSS- und JS-Dateien der Plugins fuer die
     * Verwaltungsseiten.
     *
     * Gegenstueck zu overlay.assets: ein Plugin bringt eigene Seiten
     * mit und muss sie gestalten koennen, ohne das Stylesheet des
     * Kerns anzufassen.
     *
     * Nur eigene Adressen - alles, was nicht mit "/" beginnt, wird
     * verworfen. Ein Plugin soll nicht ungefragt Code von einem
     * fremden Server in die Verwaltung holen.
     *
     * @return array{css: list<string>, js: list<string>}
     */
    /**
     * Welche Twitch-Freigaben fehlen dem Kanal?
     *
     * Gefragt wird nur nach dem, was eine EINGESCHALTETE Funktion
     * braucht: broadcasterScopes() sammelt ueber einen Hook, und ein
     * Plugin mit ausgeschaltetem Hauptschalter traegt nichts bei. Sonst
     * mahnte die Oberflaeche eine Freigabe fuer etwas an, das bewusst
     * aus ist - und wer sie erteilt, merkte keinen Unterschied.
     *
     * Ist gar kein Kanal verbunden, wird nichts gemeldet: dann steht
     * die Einrichtung ohnehin am Anfang, und eine Liste fehlender
     * Freigaben waere dort nur Laerm.
     *
     * @return list<string>
     */
    private function missingScopes(): array
    {
        try {
            if ($this->app->settings->string('twitch_broadcaster_id') === '') {
                return [];
            }

            return $this->app->twitch->tokens()->missingScopes(
                TokenStore::BROADCASTER,
                $this->app->twitch->broadcasterScopes()
            );
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{css: list<string>, js: list<string>}
     */
    public function adminAssets(): array
    {
        $assets = $this->app->hooks->filter('admin.assets', ['css' => [], 'js' => []]);
        if (!is_array($assets)) {
            $assets = [];
        }

        $app = $this->app;

        $nurEigene = static function (mixed $liste) use ($app): array {
            if (!is_array($liste)) {
                return [];
            }

            return array_values(array_filter(
                array_map('strval', $liste),
                // Siehe App::ownUrl(): eine vollstaendige eigene
                // Adresse ist auch eine eigene.
                static fn (string $url): bool => $app->ownUrl($url)
            ));
        };

        return [
            'css' => $nurEigene($assets['css'] ?? []),
            'js'  => $nurEigene($assets['js'] ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function capture(string $template, array $data): string
    {
        $file = $this->locate($template);

        $e = static fn (mixed $value): string => htmlspecialchars(
            (string) ($value ?? ''),
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $url = fn (string $path = ''): string => $this->app->url($path);
        // Statische Dateien immer ueber $asset() einbinden, nicht ueber
        // $url() - nur so bekommen sie den Aenderungsstempel.
        $asset = fn (string $path): string => $this->app->asset($path);
        $app = $this->app;
        $view = $this;

        // Fuer das lang-Attribut im Layout. Mit Netz: die Fehlerseite
        // benutzt dasselbe Layout, und wenn die Datenbank weg ist, darf
        // die Sprachabfrage die Meldung darueber nicht verschlucken.
        try {
            $language = $this->app->language();
        } catch (Throwable) {
            $language = Translator::DEFAULT_LANGUAGE;
        }

        // Freigaben, die eine eingeschaltete Funktion braucht und die
        // der Kanal nicht erteilt hat.
        //
        // Hier und nicht im Controller, weil die Warnung auf JEDER
        // Seite oben stehen soll: fehlt eine Freigabe, tut ein Teil
        // der Anwendung stillschweigend nichts, und man sucht den
        // Fehler bei sich. Frueher stand der Hinweis nur unter
        // Konto > Einstellungen > Kanal - also genau dort, wo man
        // nicht nachsieht, solange man den Zusammenhang nicht kennt.
        //
        // Mit Netz wie die Sprache: die Fehlerseite benutzt dasselbe
        // Layout, und eine weggebrochene Datenbank darf die Meldung
        // darueber nicht verschlucken.
        $missingScopes = $this->missingScopes();

        ob_start();

        try {
            (static function (array $__scope, string $__file): void {
                extract($__scope, EXTR_SKIP);
                require $__file;
            })(
                $data + compact('e', 'url', 'asset', 'app', 'view', 'language', 'missingScopes'),
                $file
            );
        } catch (Throwable $exception) {
            ob_end_clean();
            throw $exception;
        }

        return (string) ob_get_clean();
    }

    private function locate(string $template): string
    {
        $relative = ltrim(str_replace(['..', '\\'], '', $template), '/');

        foreach ($this->paths as $path) {
            $file = $path . '/' . $relative . '.php';
            if (is_file($file)) {
                return $file;
            }
        }

        throw new RuntimeException("Vorlage nicht gefunden: {$template}");
    }
}
