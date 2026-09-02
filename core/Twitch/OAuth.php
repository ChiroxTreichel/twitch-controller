<?php

declare(strict_types=1);

namespace Overlays\Core\Twitch;

use Overlays\Core\App;
use Overlays\Core\Support\Http;
use RuntimeException;

/**
 * Twitch-OAuth. Es gibt genau eine Redirect-URI fuer das ganze System:
 *
 *   https://<domain>/auth/callback
 *
 * Wofuer der Login gerade laeuft (Admin-Anmeldung, Kanal verbinden,
 * Chatbot verbinden, Raid-Anfrage), steckt im signierten state-Parameter.
 * So muss bei Twitch nur eine einzige URL eingetragen werden.
 */
final class OAuth
{
    public const AUTHORIZE_URL = 'https://id.twitch.tv/oauth2/authorize';
    public const TOKEN_URL     = 'https://id.twitch.tv/oauth2/token';
    public const VALIDATE_URL  = 'https://id.twitch.tv/oauth2/validate';

    public const STATE_COOKIE = 'ov_oauth_state';

    public function __construct(private readonly App $app)
    {
    }

    public function redirectUri(): string
    {
        return $this->app->url('/auth/callback');
    }

    /**
     * @param list<string>         $scopes
     * @param array<string, mixed> $extra Wird signiert mit durch den Rueckweg
     *                                    getragen (z.B. Einladungscode).
     */
    public function authorizeUrl(
        string $purpose,
        array $scopes = [],
        bool $forceVerify = false,
        array $extra = [],
    ): string {
        $query = [
            'client_id'     => $this->app->twitch->clientId(),
            'redirect_uri'  => $this->redirectUri(),
            'response_type' => 'code',
            'scope'         => implode(' ', $scopes),
            'state'         => $this->issueState($purpose, $extra),
        ];

        if ($forceVerify) {
            $query['force_verify'] = 'true';
        }

        return self::AUTHORIZE_URL . '?' . http_build_query($query);
    }

    /**
     * Erzeugt den state-Parameter und legt sein Geheimnis als
     * kurzlebiges Cookie ab. Ohne passendes Cookie ist der Rueckweg
     * ungueltig - das ist der CSRF-Schutz des Login-Flows.
     */
    /**
     * @param array<string, mixed> $extra
     */
    public function issueState(string $purpose, array $extra = []): string
    {
        $nonce = bin2hex(random_bytes(16));

        setcookie(self::STATE_COOKIE, $nonce, [
            'expires'  => time() + 900,
            'path'     => '/',
            'secure'   => str_starts_with($this->app->url(), 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        $payload = base64_encode((string) json_encode([
            'purpose' => $purpose,
            'nonce'   => $nonce,
            'ts'      => time(),
            'extra'   => $extra,
        ]));

        return rtrim(strtr($payload, '+/', '-_'), '=') . '.' . $this->sign($payload);
    }

    /**
     * Prueft state gegen Signatur, Alter und Cookie.
     *
     * @return array{purpose: string, extra: array<string, mixed>}
     */
    public function consumeState(string $state, string $cookieNonce): array
    {
        $parts = explode('.', $state, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Login-Rueckweg ungueltig (state fehlt).');
        }

        $payload = strtr($parts[0], '-_', '+/');
        $payload .= str_repeat('=', (4 - strlen($payload) % 4) % 4);

        if (!hash_equals($this->sign($payload), $parts[1])) {
            throw new RuntimeException('Login-Rueckweg ungueltig (Signatur passt nicht).');
        }

        $decoded = json_decode((string) base64_decode($payload, true), true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Login-Rueckweg ungueltig (state unlesbar).');
        }

        if (abs(time() - (int) ($decoded['ts'] ?? 0)) > 900) {
            throw new RuntimeException('Login-Vorgang ist abgelaufen. Bitte erneut versuchen.');
        }

        if ($cookieNonce === '' || !hash_equals((string) ($decoded['nonce'] ?? ''), $cookieNonce)) {
            throw new RuntimeException(
                'Login-Rueckweg ungueltig. Cookies erlauben und den Login in demselben Browser abschliessen.'
            );
        }

        setcookie(self::STATE_COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);

        return [
            'purpose' => (string) ($decoded['purpose'] ?? ''),
            'extra'   => is_array($decoded['extra'] ?? null) ? $decoded['extra'] : [],
        ];
    }

    /**
     * Code gegen Tokens tauschen.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: list<string>}
     */
    public function exchangeCode(string $code): array
    {
        $result = Http::form(self::TOKEN_URL, [
            'client_id'     => $this->app->twitch->clientId(),
            'client_secret' => $this->app->twitch->clientSecret(),
            'code'          => $code,
            'grant_type'    => 'authorization_code',
            'redirect_uri'  => $this->redirectUri(),
        ]);

        if (!$result->ok() || !isset($result->json['access_token'])) {
            throw new RuntimeException('Twitch hat den Login abgelehnt: ' . $result->error());
        }

        return self::normalizeTokenResponse($result->json);
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: list<string>}
     */
    public function refresh(string $refreshToken): array
    {
        $result = Http::form(self::TOKEN_URL, [
            'client_id'     => $this->app->twitch->clientId(),
            'client_secret' => $this->app->twitch->clientSecret(),
            'grant_type'    => 'refresh_token',
            'refresh_token' => $refreshToken,
        ]);

        if (!$result->ok() || !isset($result->json['access_token'])) {
            throw new RuntimeException('Token-Erneuerung fehlgeschlagen: ' . $result->error());
        }

        $normalized = self::normalizeTokenResponse($result->json);
        if ($normalized['refresh_token'] === '') {
            $normalized['refresh_token'] = $refreshToken;
        }

        return $normalized;
    }

    /**
     * App-Token (client_credentials) fuer Aufrufe ohne Benutzerbezug -
     * Benutzersuche, EventSub-Verwaltung. Wird zwischengespeichert.
     */
    public function appToken(): string
    {
        $cached = $this->app->settings->secret('twitch_app_token');
        $expires = $this->app->settings->int('twitch_app_token_expires', 0);

        if ($cached !== '' && $expires > time() + 60) {
            return $cached;
        }

        $result = Http::form(self::TOKEN_URL, [
            'client_id'     => $this->app->twitch->clientId(),
            'client_secret' => $this->app->twitch->clientSecret(),
            'grant_type'    => 'client_credentials',
        ]);

        $token = (string) ($result->json['access_token'] ?? '');
        if (!$result->ok() || $token === '') {
            throw new RuntimeException('App-Token konnte nicht geholt werden: ' . $result->error());
        }

        $this->app->settings->setSecret('twitch_app_token', $token);
        $this->app->settings->set('twitch_app_token_expires', time() + (int) ($result->json['expires_in'] ?? 3600));

        return $token;
    }

    /**
     * @param array<string, mixed> $json
     * @return array{access_token: string, refresh_token: string, expires_in: int, scope: list<string>}
     */
    private static function normalizeTokenResponse(array $json): array
    {
        $scope = $json['scope'] ?? [];
        if (is_string($scope)) {
            $scope = preg_split('/\s+/', trim($scope)) ?: [];
        }

        return [
            'access_token'  => (string) ($json['access_token'] ?? ''),
            'refresh_token' => (string) ($json['refresh_token'] ?? ''),
            'expires_in'    => (int) ($json['expires_in'] ?? 0),
            'scope'         => array_values(array_filter(array_map('strval', (array) $scope))),
        ];
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->app->env->require('APP_KEY'));
    }
}
