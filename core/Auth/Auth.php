<?php

declare(strict_types=1);

namespace Overlays\Core\Auth;

use Overlays\Core\App;
use RuntimeException;

/**
 * Anmeldung, Sessions und Rechte.
 *
 * Identitaet kommt ausschliesslich von Twitch - es gibt keine eigenen
 * Passwoerter. Der erste Login wird superadmin, alle weiteren brauchen
 * einen Einladungscode.
 *
 * Im Cookie steht ein Zufallstoken, in der Datenbank nur dessen Hash.
 * Ein gestohlener Datenbank-Dump erlaubt damit keine Uebernahme
 * bestehender Sitzungen.
 */
final class Auth
{
    public const COOKIE = 'ov_session';
    public const SESSION_LIFETIME = 90 * 24 * 3600;

    public const ROLE_SUPERADMIN = 'superadmin';
    public const ROLE_MEMBER     = 'member';

    /** @var array<string, mixed>|null */
    private ?array $user = null;

    private bool $resolved = false;

    public function __construct(private readonly App $app)
    {
    }

    // -----------------------------------------------------------------
    //  Aktueller Benutzer
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;

        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($token === '') {
            return null;
        }

        $row = $this->app->db->first(
            'SELECT u.*
               FROM sessions s
               JOIN users u ON u.twitch_id = s.twitch_id
              WHERE s.token_hash = :hash
                AND s.last_seen_at > now() - (:lifetime || \' seconds\')::interval',
            ['hash' => self::hash($token), 'lifetime' => (string) self::SESSION_LIFETIME]
        );

        if ($row === null) {
            return null;
        }

        $permissions = json_decode((string) $row['permissions'], true);
        $row['permissions'] = is_array($permissions) ? array_values(array_map('strval', $permissions)) : [];

        // Sliding Session: bei jedem Request auffrischen, aber nicht
        // oefter als einmal pro Minute schreiben.
        $this->app->db->run(
            'UPDATE sessions SET last_seen_at = now()
              WHERE token_hash = :hash AND last_seen_at < now() - interval \'1 minute\'',
            ['hash' => self::hash($token)]
        );
        $this->app->db->run(
            'UPDATE users SET last_seen_at = now()
              WHERE twitch_id = :id AND last_seen_at < now() - interval \'1 minute\'',
            ['id' => (string) $row['twitch_id']]
        );

        return $this->user = $row;
    }

    public function isLoggedIn(): bool
    {
        return $this->user() !== null;
    }

    public function isSuperadmin(): bool
    {
        $user = $this->user();

        return $user !== null && ($user['role'] ?? '') === self::ROLE_SUPERADMIN;
    }

    /**
     * Rechtepruefung. Superadmin darf immer alles.
     */
    public function can(string $permission): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if (($user['role'] ?? '') === self::ROLE_SUPERADMIN) {
            return true;
        }

        return in_array($permission, (array) ($user['permissions'] ?? []), true);
    }

    // -----------------------------------------------------------------
    //  Anmelden und abmelden
    // -----------------------------------------------------------------

    /**
     * Uebernimmt einen bei Twitch verifizierten Benutzer.
     *
     * @param array<string, mixed> $twitchUser Antwort von /helix/users
     * @return array<string, mixed> Der angemeldete Benutzer
     */
    public function completeLogin(array $twitchUser, string $inviteCode = ''): array
    {
        $twitchId = (string) ($twitchUser['id'] ?? '');
        $login = strtolower((string) ($twitchUser['login'] ?? ''));
        $displayName = (string) ($twitchUser['display_name'] ?? $login);

        if ($twitchId === '' || $login === '') {
            throw new RuntimeException('Twitch hat keinen verwertbaren Benutzer geliefert.');
        }

        $existing = $this->find($twitchId);

        if ($existing === null) {
            $isFirstUser = ((int) $this->app->db->value('SELECT count(*) FROM users')) === 0;

            if (!$isFirstUser && !$this->redeemInvite($inviteCode, $twitchId)) {
                throw new RuntimeException(
                    'Fuer diesen Account liegt keine Einladung vor. Bitte den Kanalinhaber um einen Einladungslink bitten.'
                );
            }

            $this->app->db->run(
                'INSERT INTO users (twitch_id, login, display_name, role, permissions)
                 VALUES (:id, :login, :name, :role, CAST(:permissions AS JSONB))',
                [
                    'id'          => $twitchId,
                    'login'       => $login,
                    'name'        => $displayName,
                    'role'        => $isFirstUser ? self::ROLE_SUPERADMIN : self::ROLE_MEMBER,
                    'permissions' => (string) json_encode($isFirstUser ? [] : $this->defaultPermissions()),
                ]
            );

            $this->app->hooks->dispatch('user.created', $twitchId, $isFirstUser);
        } else {
            $this->app->db->run(
                'UPDATE users SET login = :login, display_name = :name, last_seen_at = now()
                  WHERE twitch_id = :id',
                ['id' => $twitchId, 'login' => $login, 'name' => $displayName]
            );
        }

        $this->startSession($twitchId);
        $this->resolved = false;
        $this->user = null;

        $user = $this->find($twitchId);
        if ($user === null) {
            throw new RuntimeException('Benutzer konnte nach dem Login nicht gelesen werden.');
        }

        $this->app->hooks->dispatch('user.login', $twitchId);

        return $user;
    }

    public function startSession(string $twitchId): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        $this->app->db->run(
            'INSERT INTO sessions (token_hash, twitch_id, ip, user_agent)
             VALUES (:hash, :id, :ip, :agent)',
            [
                'hash'  => self::hash($token),
                'id'    => $twitchId,
                'ip'    => (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
                'agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
            ]
        );

        setcookie(self::COOKIE, $token, [
            'expires'  => time() + self::SESSION_LIFETIME,
            'path'     => '/',
            'secure'   => str_starts_with($this->app->url(), 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        return $token;
    }

    public function logout(): void
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');
        if ($token !== '') {
            $this->app->db->run(
                'DELETE FROM sessions WHERE token_hash = :hash',
                ['hash' => self::hash($token)]
            );
        }

        setcookie(self::COOKIE, '', ['expires' => time() - 3600, 'path' => '/']);

        $this->user = null;
        $this->resolved = true;
    }

    /**
     * Alle Sitzungen eines Benutzers beenden - beim Entziehen von
     * Rechten oder beim Entfernen aus dem Team.
     */
    public function revokeSessions(string $twitchId): void
    {
        $this->app->db->run('DELETE FROM sessions WHERE twitch_id = :id', ['id' => $twitchId]);
    }

    // -----------------------------------------------------------------
    //  Benutzerverwaltung
    // -----------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $twitchId): ?array
    {
        $row = $this->app->db->first('SELECT * FROM users WHERE twitch_id = :id', ['id' => $twitchId]);
        if ($row === null) {
            return null;
        }

        $permissions = json_decode((string) $row['permissions'], true);
        $row['permissions'] = is_array($permissions) ? array_values(array_map('strval', $permissions)) : [];

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function users(): array
    {
        $rows = $this->app->db->all('SELECT * FROM users ORDER BY role DESC, display_name');

        foreach ($rows as $index => $row) {
            $permissions = json_decode((string) $row['permissions'], true);
            $rows[$index]['permissions'] = is_array($permissions)
                ? array_values(array_map('strval', $permissions))
                : [];
        }

        return $rows;
    }

    /**
     * @param list<string> $permissions
     */
    public function setPermissions(string $twitchId, array $permissions): void
    {
        $valid = $this->flatPermissionKeys();
        $filtered = array_values(array_intersect(array_map('strval', $permissions), $valid));

        $this->app->db->run(
            'UPDATE users SET permissions = CAST(:permissions AS JSONB) WHERE twitch_id = :id',
            ['id' => $twitchId, 'permissions' => (string) json_encode($filtered)]
        );

        $this->app->hooks->dispatch('user.permissions_changed', $twitchId, $filtered);
    }

    public function removeUser(string $twitchId): void
    {
        if (($this->user()['twitch_id'] ?? null) === $twitchId) {
            throw new RuntimeException('Der eigene Account kann nicht entfernt werden.');
        }

        $user = $this->find($twitchId);
        if ($user !== null && ($user['role'] ?? '') === self::ROLE_SUPERADMIN) {
            throw new RuntimeException('Der Kanalinhaber kann nicht entfernt werden.');
        }

        $this->app->db->run('DELETE FROM users WHERE twitch_id = :id', ['id' => $twitchId]);
        $this->app->hooks->dispatch('user.removed', $twitchId);
    }

    // -----------------------------------------------------------------
    //  Einladungen
    // -----------------------------------------------------------------

    /**
     * @return array{code: string, url: string}
     */
    public function createInvite(int $validForHours = 72): array
    {
        $user = $this->user();
        $code = strtolower(bin2hex(random_bytes(12)));

        $this->app->db->run(
            'INSERT INTO invites (code, created_by, expires_at)
             VALUES (:code, :by, now() + (:hours || \' hours\')::interval)',
            [
                'code'  => $code,
                'by'    => $user['twitch_id'] ?? null,
                'hours' => (string) max(1, $validForHours),
            ]
        );

        return ['code' => $code, 'url' => $this->app->url('/login?invite=' . $code)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function invites(): array
    {
        return $this->app->db->all(
            'SELECT * FROM invites
              WHERE used_by IS NULL AND (expires_at IS NULL OR expires_at > now())
              ORDER BY created_at DESC'
        );
    }

    public function revokeInvite(string $code): void
    {
        $this->app->db->run(
            'DELETE FROM invites WHERE code = :code AND used_by IS NULL',
            ['code' => strtolower(trim($code))]
        );
    }

    private function redeemInvite(string $code, string $twitchId): bool
    {
        $code = strtolower(trim($code));
        if ($code === '') {
            return false;
        }

        $affected = $this->app->db->run(
            'UPDATE invites
                SET used_by = :id, used_at = now()
              WHERE code = :code
                AND used_by IS NULL
                AND (expires_at IS NULL OR expires_at > now())',
            ['code' => $code, 'id' => $twitchId]
        )->rowCount();

        return $affected > 0;
    }

    // -----------------------------------------------------------------
    //  Rechtekatalog
    // -----------------------------------------------------------------

    /**
     * Der Kern bringt nur seine eigenen Rechte mit. Plugins ergaenzen per
     * Hook:
     *
     *   $hooks->on('permissions.catalog', function (array $catalog) {
     *       $catalog['Alerts'] = [
     *           'label' => 'Alerts',
     *           'permissions' => [
     *               'Alerts.Follow.Edit' => 'darf Follow-Alerts bearbeiten',
     *           ],
     *       ];
     *       return $catalog;
     *   });
     *
     * @return array<string, array{label: string, permissions: array<string, string>}>
     */
    public function permissionCatalog(): array
    {
        $catalog = [
            'Konto' => [
                'label' => 'Konto',
                'permissions' => [
                    'Konto.Benutzer.View'   => 'darf die Benutzerliste sehen.',
                    'Konto.Benutzer.Manage' => 'darf Benutzer einladen, Rechte setzen und entfernen.',
                    'Konto.Aktivitaeten.View' => 'darf die Aktivitaeten des Kanals sehen.',
                    'Konto.Plugins.View'    => 'darf die Plugin-Liste sehen.',
                    'Konto.Plugins.Manage'  => 'darf Plugins installieren, aktivieren und entfernen.',
                    'Konto.Einstellungen.View'   => 'darf die Einstellungen sehen.',
                    'Konto.Einstellungen.Manage' => 'darf die Einstellungen aendern.',
                ],
            ],
        ];

        $filtered = $this->app->hooks->filter('permissions.catalog', $catalog);

        return is_array($filtered) ? $filtered : $catalog;
    }

    /**
     * @return list<string>
     */
    public function flatPermissionKeys(): array
    {
        $keys = [];
        foreach ($this->permissionCatalog() as $group) {
            foreach (array_keys((array) ($group['permissions'] ?? [])) as $key) {
                $keys[] = (string) $key;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * Was ein neu eingeladener Benutzer bekommt: nur Lesen.
     *
     * @return list<string>
     */
    public function defaultPermissions(): array
    {
        return array_values(array_filter(
            $this->flatPermissionKeys(),
            static fn (string $key): bool => str_ends_with($key, '.View')
        ));
    }

    // -----------------------------------------------------------------
    //  CSRF
    // -----------------------------------------------------------------

    public function csrfToken(): string
    {
        $token = (string) ($_COOKIE[self::COOKIE] ?? '');

        return hash_hmac('sha256', 'csrf|' . $token, $this->app->env->require('APP_KEY'));
    }

    public function checkCsrf(string $candidate): bool
    {
        return $candidate !== '' && hash_equals($this->csrfToken(), $candidate);
    }

    private static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
