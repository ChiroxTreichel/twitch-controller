<?php

declare(strict_types=1);

namespace Overlays\Core\Twitch;

use Overlays\Core\App;
use RuntimeException;

/**
 * Verwahrt die langlebigen Twitch-Tokens - verschluesselt in der
 * Datenbank statt in data/twitch_token.json.
 *
 * "purpose" trennt die Zwecke:
 *   broadcaster  Token des Kanalinhabers (Ziele, Abos, Kanaldaten)
 *   bot          Token des Accounts, der im Chat schreibt
 *
 * Plugins duerfen eigene Zwecke anlegen, sollten sie aber mit ihrem
 * Slug praefixen (z.B. "plugin:spenden").
 */
final class TokenStore
{
    public const BROADCASTER = 'broadcaster';
    public const BOT         = 'bot';

    public function __construct(private readonly App $app)
    {
    }

    /**
     * @param list<string> $scopes
     */
    public function save(
        string $purpose,
        string $accessToken,
        string $refreshToken,
        int $expiresIn,
        array $scopes = [],
        ?string $twitchId = null,
        ?string $login = null,
    ): void {
        $this->app->db->run(
            'INSERT INTO twitch_tokens
                 (purpose, twitch_id, login, access_token, refresh_token, expires_at, scopes, updated_at)
             VALUES
                 (:purpose, :twitch_id, :login, :access, :refresh,
                  now() + (:expires_in || \' seconds\')::interval, CAST(:scopes AS JSONB), now())
             ON CONFLICT (purpose) DO UPDATE SET
                 twitch_id     = EXCLUDED.twitch_id,
                 login         = EXCLUDED.login,
                 access_token  = EXCLUDED.access_token,
                 refresh_token = EXCLUDED.refresh_token,
                 expires_at    = EXCLUDED.expires_at,
                 scopes        = EXCLUDED.scopes,
                 updated_at    = now()',
            [
                'purpose'    => $purpose,
                'twitch_id'  => $twitchId,
                'login'      => $login === null ? null : strtolower($login),
                'access'     => $this->app->crypto->encrypt($accessToken),
                'refresh'    => $this->app->crypto->encrypt($refreshToken),
                'expires_in' => (string) max(0, $expiresIn),
                'scopes'     => (string) json_encode(array_values($scopes)),
            ]
        );
    }

    /**
     * @return array{purpose: string, twitch_id: ?string, login: ?string, expires_at: ?string, scopes: list<string>, expires_in: int}|null
     */
    public function info(string $purpose): ?array
    {
        $row = $this->app->db->first(
            'SELECT purpose, twitch_id, login, scopes,
                    expires_at,
                    GREATEST(0, EXTRACT(EPOCH FROM (expires_at - now()))::int) AS expires_in
               FROM twitch_tokens
              WHERE purpose = :purpose',
            ['purpose' => $purpose]
        );

        if ($row === null) {
            return null;
        }

        $scopes = json_decode((string) $row['scopes'], true);

        return [
            'purpose'    => (string) $row['purpose'],
            'twitch_id'  => $row['twitch_id'] === null ? null : (string) $row['twitch_id'],
            'login'      => $row['login'] === null ? null : (string) $row['login'],
            'expires_at' => $row['expires_at'] === null ? null : (string) $row['expires_at'],
            'expires_in' => (int) $row['expires_in'],
            'scopes'     => is_array($scopes) ? array_values(array_map('strval', $scopes)) : [],
        ];
    }

    public function has(string $purpose): bool
    {
        return $this->info($purpose) !== null;
    }

    /**
     * Gueltiges Access-Token, bei Bedarf erneuert.
     */
    public function accessToken(string $purpose): string
    {
        $row = $this->app->db->first(
            'SELECT access_token, refresh_token, scopes, twitch_id, login,
                    COALESCE(EXTRACT(EPOCH FROM (expires_at - now()))::int, 0) AS remaining
               FROM twitch_tokens
              WHERE purpose = :purpose',
            ['purpose' => $purpose]
        );

        if ($row === null) {
            throw new RuntimeException(sprintf(
                'Kein Twitch-Token fuer "%s" vorhanden. Bitte in den Einstellungen verbinden.',
                $purpose
            ));
        }

        if ((int) $row['remaining'] > 60) {
            return $this->app->crypto->decrypt((string) $row['access_token']);
        }

        $refreshed = $this->app->twitch->oauth()->refresh(
            $this->app->crypto->decrypt((string) $row['refresh_token'])
        );

        $this->save(
            $purpose,
            $refreshed['access_token'],
            $refreshed['refresh_token'],
            $refreshed['expires_in'],
            $refreshed['scope'],
            $row['twitch_id'] === null ? null : (string) $row['twitch_id'],
            $row['login'] === null ? null : (string) $row['login'],
        );

        return $refreshed['access_token'];
    }

    public function delete(string $purpose): void
    {
        $this->app->db->run('DELETE FROM twitch_tokens WHERE purpose = :purpose', ['purpose' => $purpose]);
    }

    /**
     * Fehlt dem gespeicherten Token ein Scope, den ein Feature braucht?
     * Damit kann die Oberflaeche gezielt "bitte neu verbinden" sagen.
     *
     * @param list<string> $needed
     * @return list<string>
     */
    public function missingScopes(string $purpose, array $needed): array
    {
        $info = $this->info($purpose);
        if ($info === null) {
            return $needed;
        }

        return array_values(array_diff($needed, $info['scopes']));
    }
}
