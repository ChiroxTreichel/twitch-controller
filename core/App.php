<?php

declare(strict_types=1);

namespace Overlays\Core;

use Overlays\Core\Auth\Auth;
use Overlays\Core\Config\Env;
use Overlays\Core\Config\Settings;
use Overlays\Core\Database\Db;
use Overlays\Core\Events\EventStore;
use Overlays\Core\Events\Normalizer;
use Overlays\Core\Hook\Hooks;
use Overlays\Core\Http\Router;
use Overlays\Core\Http\View;
use Overlays\Core\I18n\Translator;
use Overlays\Core\Plugin\PluginManager;
use Overlays\Core\Support\Crypto;
use Overlays\Core\Twitch\Twitch;
use Throwable;

/**
 * Zusammenhalt des Kerns. Es gibt genau eine Instanz pro Request bzw.
 * pro Worker-Durchlauf; Plugins bekommen sie als $app hereingegeben.
 */
final class App
{
    /**
     * Kernversion. Plugins koennen dagegen Bedingungen stellen
     * ("requires": { "core": ">=1.0.0" }).
     */
    public const VERSION = '1.0.0';

    public readonly Env $env;
    public readonly Crypto $crypto;
    public readonly Db $db;
    public readonly Settings $settings;
    public readonly Hooks $hooks;
    public readonly Router $router;
    public readonly View $view;
    public readonly PluginManager $plugins;
    public readonly Auth $auth;
    public readonly Twitch $twitch;
    public readonly Normalizer $normalizer;
    public readonly EventStore $events;

    private function __construct(public readonly string $root)
    {
        $this->env      = new Env($root . '/.env');
        $this->crypto   = new Crypto($this->env);
        $this->db       = new Db($this->env);
        $this->settings = new Settings($this->db, $this->crypto);
        $this->hooks    = new Hooks();
        $this->router   = new Router();
        $this->view       = new View($this, [$root . '/core/views']);
        $this->twitch     = new Twitch($this);
        $this->normalizer = new Normalizer($this->hooks);
        $this->events     = new EventStore($this);
    }

    public static function boot(string $root): self
    {
        $app = new self($root);
        $app->plugins = new PluginManager($app, $root . '/plugins');
        $app->auth = new Auth($app);

        return $app;
    }

    /**
     * Ob der Installer durchgelaufen ist. Faengt absichtlich alles ab:
     * solange die Datenbank nicht erreichbar ist oder das Schema fehlt,
     * gilt das System als nicht installiert und der Installer uebernimmt.
     */
    public function isInstalled(): bool
    {
        try {
            return $this->settings->bool('installed', false);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Legt das Kernschema an bzw. zieht es hoch. Wird vom Installer und
     * bei einem Versionswechsel aufgerufen.
     */
    public function installCore(): void
    {
        $fromVersion = null;

        try {
            $fromVersion = $this->settings->string('core_version', '') ?: null;
        } catch (Throwable) {
            // Tabelle settings existiert noch nicht - Erstinstallation.
        }

        (static function (Db $db, ?string $fromVersion, string $file): void {
            require $file;
        })($this->db, $fromVersion, $this->root . '/core/install.php');

        $this->settings->flush();
        $this->settings->set('core_version', self::VERSION);
    }

    /**
     * Zeitzone fuer alle Anzeigen. Der Container laeuft in UTC, ein
     * Streamer denkt aber in seiner Ortszeit - ohne das stehen im Feed
     * Zeiten, die zwei Stunden neben der Wirklichkeit liegen.
     *
     * Reihenfolge: Einstellung, dann TZ aus der Umgebung, dann Berlin.
     */
    public function timezone(): string
    {
        $timezone = '';

        try {
            $timezone = $this->settings->string('timezone');
        } catch (Throwable) {
            // Datenbank noch nicht da - dann eben aus der Umgebung.
        }

        if ($timezone === '') {
            $timezone = (string) $this->env->get('TZ', 'Europe/Berlin');
        }

        return in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Europe/Berlin';
    }

    /**
     * Wird von den Einstiegspunkten einmal aufgerufen.
     */
    public function applyTimezone(): void
    {
        date_default_timezone_set($this->timezone());
    }

    /**
     * Sprache der Oberflaeche. Reihenfolge wie bei der Zeitzone:
     * Einstellung, dann APP_LANG aus der Umgebung, dann Deutsch.
     */
    public function language(): string
    {
        $language = '';

        try {
            $language = $this->settings->string('language');
        } catch (Throwable) {
            // Datenbank noch nicht da - waehrend der Einrichtung normal.
        }

        if ($language === '') {
            $language = (string) $this->env->get('APP_LANG', Translator::DEFAULT_LANGUAGE);
        }

        return Translator::normalize($language);
    }

    /**
     * Laedt die Sprachdatei des Kerns. Plugins ergaenzen ihre eigenen,
     * wenn sie geladen werden (siehe PluginManager::boot).
     */
    public function applyLanguage(): void
    {
        Translator::boot($this->language(), $this->root . '/lang');
    }

    public function languageDirectory(): string
    {
        return $this->root . '/lang';
    }

    public function url(string $path = ''): string
    {
        return $this->env->url($path);
    }

    /**
     * Adresse einer statischen Datei mit Aenderungsstempel:
     *
     *   /assets/admin.css  ->  https://…/assets/admin.css?v=1756822931
     *
     * Damit holt der Browser eine geaenderte Datei sofort und darf sie
     * ansonsten beliebig lange behalten. Ohne das sehen Nutzer nach
     * einem Update das alte Aussehen, bis sie von Hand neu laden - und
     * genau das melden sie dann als Fehler.
     *
     * Kennt zwei Orte:
     *   /assets/…                      -> public/assets/…
     *   /plugin/<slug>/assets/…        -> plugins/<slug>/assets/…
     */
    public function asset(string $path): string
    {
        $path = '/' . ltrim($path, '/');
        $url = $this->url($path);

        // Kein Verzeichniswechsel - der Pfad kommt zwar aus eigenen
        // Vorlagen, aber Plugins duerfen ihn auch fuellen.
        if (str_contains($path, '..')) {
            return $url;
        }

        if (preg_match('#^/plugin/([a-z0-9][a-z0-9-]*)/assets/(.+)$#', $path, $match) === 1) {
            $file = $this->root . '/plugins/' . $match[1] . '/assets/' . $match[2];
        } else {
            $file = $this->root . '/public' . $path;
        }

        if (!is_file($file)) {
            return $url;
        }

        $stamp = (int) filemtime($file);

        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $stamp;
    }

    public function log(string $message): void
    {
        error_log('[overlays] ' . $message);
    }
}
