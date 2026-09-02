<?php

declare(strict_types=1);

namespace Overlays\Core\Twitch;

use Overlays\Core\App;
use RuntimeException;

/**
 * Einstiegspunkt fuer alles Twitch-bezogene: $app->twitch
 *
 * Die Zugangsdaten liegen in der Datenbank (vom Installer gesetzt), nicht
 * in der .env - damit sie in der Oberflaeche aenderbar sind, ohne dass
 * jemand eine Datei auf dem Server bearbeiten muss.
 */
final class Twitch
{
    private ?OAuth $oauth = null;
    private ?TokenStore $tokens = null;
    private ?EventSub $eventSub = null;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Reicht die Konfiguration, um mit Twitch zu sprechen?
     */
    public function isConfigured(): bool
    {
        return $this->app->settings->string('twitch_client_id') !== ''
            && $this->app->settings->hasSecret('twitch_client_secret');
    }

    public function clientId(): string
    {
        $clientId = $this->app->settings->string('twitch_client_id');
        if ($clientId === '') {
            throw new RuntimeException('Twitch-Client-ID ist nicht gesetzt. Bitte die Einrichtung abschliessen.');
        }

        return $clientId;
    }

    public function clientSecret(): string
    {
        $secret = $this->app->settings->secret('twitch_client_secret');
        if ($secret === '') {
            throw new RuntimeException('Twitch-Client-Secret ist nicht gesetzt. Bitte die Einrichtung abschliessen.');
        }

        return $secret;
    }

    /**
     * Der Kanal, um den es geht. Wird beim Verbinden des Kanals gesetzt.
     */
    public function broadcasterId(): string
    {
        return $this->app->settings->string('twitch_broadcaster_id');
    }

    public function broadcasterLogin(): string
    {
        return $this->app->settings->string('twitch_broadcaster_login');
    }

    public function hasChannel(): bool
    {
        return $this->broadcasterId() !== '' && $this->tokens()->has(TokenStore::BROADCASTER);
    }

    public function oauth(): OAuth
    {
        return $this->oauth ??= new OAuth($this->app);
    }

    public function tokens(): TokenStore
    {
        return $this->tokens ??= new TokenStore($this->app);
    }

    public function eventSub(): EventSub
    {
        return $this->eventSub ??= new EventSub($this->app);
    }

    /**
     * Neue Instanz je Aufruf, weil as() einen eigenen Zustand hat.
     */
    public function api(): Api
    {
        return new Api($this->app);
    }

    /**
     * Scopes, die der Kanalinhaber-Login mitbringen muss. Der Kern
     * braucht nur wenig; Plugins melden ihre Scopes per Hook an, damit
     * beim Verbinden gleich alles Noetige abgefragt wird.
     *
     * @return list<string>
     */
    public function broadcasterScopes(): array
    {
        $base = [
            // Follows lesen (EventSub channel.follow v2) und Follower-Liste
            'moderator:read:followers',
            // Abos lesen
            'channel:read:subscriptions',
        ];

        $scopes = $this->app->hooks->filter('core.twitch.broadcaster_scopes', $base);
        if (!is_array($scopes)) {
            return $base;
        }

        return array_values(array_unique(array_filter(array_map('strval', $scopes))));
    }

    /**
     * Scopes fuer den Chat-Account. Ohne Chat-Plugin leer.
     *
     * @return list<string>
     */
    public function botScopes(): array
    {
        $scopes = $this->app->hooks->filter('core.twitch.bot_scopes', []);
        if (!is_array($scopes)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map('strval', $scopes))));
    }
}
