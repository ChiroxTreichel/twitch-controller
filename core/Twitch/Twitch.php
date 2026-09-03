<?php

declare(strict_types=1);

namespace TwitchController\Core\Twitch;

use TwitchController\Core\App;
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
            throw new RuntimeException(translate('twitch.no_client_id'));
        }

        return $clientId;
    }

    public function clientSecret(): string
    {
        $secret = $this->app->settings->secret('twitch_client_secret');
        if ($secret === '') {
            throw new RuntimeException(translate('twitch.no_client_secret'));
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
        // Jeder EventSub-Typ, den der Kern abonniert, braucht den Scope, den
        // Twitch dafuer verlangt - sonst lehnt Twitch das Abo mit
        // "subscription missing proper authorization" ab. Die Zuordnung:
        //
        //   channel.follow (v2)      -> moderator:read:followers
        //   channel.subscribe        -> channel:read:subscriptions
        //   channel.subscription.*   -> channel:read:subscriptions
        //   channel.cheer            -> bits:read
        //   channel.raid             -> kein Scope
        //   stream.online / .offline -> kein Scope
        //   channel.chat.*           -> user:read:chat + user:bot am
        //                               mitlesenden Konto, channel:bot
        //                               am Kanal
        //
        // Die Chat-Scopes stehen hier alle, obwohl drei davon eigentlich
        // dem Bot-Konto gehoeren: ohne eigenes Bot-Konto ist der
        // Kanalinhaber selbst der, der im Chat sitzt. Wer einen Bot
        // einrichtet, bekommt sie ueber botScopes() ein zweites Mal -
        // doppelt vergeben ist harmlos, fehlend nicht.
        $base = [
            'moderator:read:followers',
            'channel:read:subscriptions',
            'bits:read',
            // Chat mitlesen und schreiben
            'user:read:chat',
            'user:write:chat',
            'user:bot',
            'channel:bot',
            'moderator:manage:chat_messages',
        ];

        $scopes = $this->app->hooks->filter('core.twitch.broadcaster_scopes', $base);
        if (!is_array($scopes)) {
            return $base;
        }

        return array_values(array_unique(array_filter(array_map('strval', $scopes))));
    }

    /**
     * Scopes fuer das Bot-Konto.
     *
     * Ein Bot-Konto ist freiwillig: ohne eines schreibt der
     * Kanalinhaber selbst. Wer eines verbindet, braucht daran genau
     * das, was Twitch vom mitlesenden und schreibenden Konto verlangt -
     * channel:bot gehoert dagegen an den Kanal und steht darum in
     * broadcasterScopes().
     *
     * @return list<string>
     */
    public function botScopes(): array
    {
        $base = [
            'user:read:chat',
            'user:write:chat',
            'user:bot',
        ];

        $scopes = $this->app->hooks->filter('core.twitch.bot_scopes', $base);
        if (!is_array($scopes)) {
            return $base;
        }

        return array_values(array_unique(array_filter(array_map('strval', $scopes))));
    }
}
