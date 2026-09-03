<?php

declare(strict_types=1);

namespace TwitchController\Core\Twitch;

use TwitchController\Core\App;
use TwitchController\Core\Support\Http;
use TwitchController\Core\Support\HttpResult;
use RuntimeException;

/**
 * Zugang zur Twitch-Helix-API. Plugins benutzen diese Klasse statt
 * eigene curl-Aufrufe zu schreiben.
 *
 *   $app->twitch->api()->get('channels', ['broadcaster_id' => $id]);
 *
 * Ohne Angabe wird das App-Token benutzt (reicht fuer oeffentliche
 * Daten). Fuer Endpunkte mit Scopes den Zweck angeben:
 *
 *   $app->twitch->api()->as(TokenStore::BROADCASTER)->get('goals', [...]);
 */
final class Api
{
    private const BASE = 'https://api.twitch.tv/helix/';

    private ?string $purpose = null;

    public function __construct(private readonly App $app)
    {
    }

    /**
     * Kopie, die Aufrufe mit dem Token dieses Zwecks macht.
     */
    public function as(string $purpose): self
    {
        $clone = new self($this->app);
        $clone->purpose = $purpose;

        return $clone;
    }

    /**
     * @param array<string, string|int> $query
     */
    public function get(string $endpoint, array $query = []): HttpResult
    {
        return $this->request('GET', $endpoint, $query);
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>      $body
     */
    public function post(string $endpoint, array $query = [], array $body = []): HttpResult
    {
        return $this->request('POST', $endpoint, $query, $body);
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>      $body
     */
    public function patch(string $endpoint, array $query = [], array $body = []): HttpResult
    {
        return $this->request('PATCH', $endpoint, $query, $body);
    }

    /**
     * @param array<string, string|int> $query
     */
    public function delete(string $endpoint, array $query = []): HttpResult
    {
        return $this->request('DELETE', $endpoint, $query);
    }

    /**
     * Wie get(), wirft aber bei Fehlerstatus - fuer Aufrufe, bei denen
     * ein Fehlschlag ohnehin abgebrochen werden muss.
     *
     * @param array<string, string|int> $query
     * @return list<array<string, mixed>>
     */
    public function data(string $endpoint, array $query = []): array
    {
        $result = $this->get($endpoint, $query);
        if (!$result->ok()) {
            throw new RuntimeException(sprintf(
                'Twitch-Fehler bei %s (%d): %s',
                $endpoint,
                $result->status,
                $result->error()
            ));
        }

        $data = $result->json['data'] ?? [];

        return is_array($data) ? array_values($data) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userByLogin(string $login): ?array
    {
        $rows = $this->data('users', ['login' => strtolower(ltrim($login, '@'))]);

        return $rows[0] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function userById(string $id): ?array
    {
        $rows = $this->data('users', ['id' => $id]);

        return $rows[0] ?? null;
    }

    /**
     * Wer gehoert zu diesem Access-Token? Wird beim Login gebraucht,
     * bevor irgendetwas gespeichert ist.
     *
     * @return array<string, mixed>
     */
    public function userForToken(string $accessToken): array
    {
        $result = Http::get(self::BASE . 'users', [
            'Client-Id'     => $this->app->twitch->clientId(),
            'Authorization' => 'Bearer ' . $accessToken,
        ]);

        $user = $result->json['data'][0] ?? null;
        if (!$result->ok() || !is_array($user)) {
            throw new RuntimeException(translate('twitch.user_unreadable', [
                'reason' => $result->error(),
            ]));
        }

        return $user;
    }

    /**
     * @param array<string, string|int> $query
     * @param array<string, mixed>|null $body
     */
    private function request(string $method, string $endpoint, array $query = [], ?array $body = null): HttpResult
    {
        $url = self::BASE . ltrim($endpoint, '/');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $headers = [
            'Client-Id'     => $this->app->twitch->clientId(),
            'Authorization' => 'Bearer ' . $this->token(),
        ];

        if ($body === null) {
            return Http::request($method, $url, $headers);
        }

        return Http::json($method, $url, $body, $headers);
    }

    private function token(): string
    {
        if ($this->purpose === null) {
            return $this->app->twitch->oauth()->appToken();
        }

        return $this->app->twitch->tokens()->accessToken($this->purpose);
    }
}
