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

    public function url(string $path = ''): string
    {
        return $this->env->url($path);
    }

    public function log(string $message): void
    {
        error_log('[overlays] ' . $message);
    }
}
