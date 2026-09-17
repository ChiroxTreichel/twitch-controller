<?php

declare(strict_types=1);

namespace TwitchController\Core\Auth;

use TwitchController\Core\App;
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

        $row = $this->hydrate($row);

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

    /**
     * Eine Benutzerzeile aus der Datenbank in brauchbare Werte.
     *
     * Die zwei JSONB-Felder kommen als Text zurueck. Frueher stand das
     * Auspacken zweimal da - in user() und in find() - und beim
     * Dazukommen von preferences waere es genau die Art Stelle, die man
     * an einem der beiden Orte vergisst. Dann fehlten die Vorlieben
     * ueberall dort, wo der Benutzer ueber find() geholt wird, ohne
     * dass irgendwo ein Fehler auftaucht.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function hydrate(array $row): array
    {
        $permissions = json_decode((string) $row['permissions'], true);
        $row['permissions'] = is_array($permissions) ? array_values(array_map('strval', $permissions)) : [];

        $preferences = json_decode((string) ($row['preferences'] ?? '{}'), true);
        $row['preferences'] = is_array($preferences) ? $preferences : [];

        $row['permission_role'] = trim((string) ($row['permission_role'] ?? ''));

        return $row;
    }

    /**
     * Die Rechte, die jemand WIRKLICH hat.
     *
     * Hat er eine Rolle, kommen sie aus ihr - und zwar so, wie sie
     * heute aussieht. Das ist der Unterschied zu frueher: damals wurden
     * die Rechte beim Zuweisen kopiert, und ein Recht, das spaeter
     * dazukam, erreichte keinen einzigen Editor.
     *
     * Aufgeloest wird HIER und nicht beim Laden des Benutzers. Der
     * Grund ist die Reihenfolge: rolePresets() fragt den Rechtekatalog,
     * und in dem stehen die Rechte der Plugins erst, wenn die geladen
     * sind. Wer den Benutzer vorher anfasst, bekaeme eine Rolle ohne
     * Plugin-Rechte - und weil der Benutzer zwischengespeichert wird,
     * bliebe das den ganzen Aufruf lang so.
     *
     * @param array<string, mixed> $user
     * @return list<string>
     */
    public function permissionsOf(array $user): array
    {
        $rolle = trim((string) ($user['permission_role'] ?? ''));
        $vorlagen = $this->rolePresets();

        if ($rolle !== '' && isset($vorlagen[$rolle])) {
            return $vorlagen[$rolle]['keys'];
        }

        // Ohne Rolle gilt die eigene Auswahl. Sie steht auch bei einem
        // Rolleninhaber daneben - als Ausgangspunkt, wenn er die Rolle
        // verlaesst, und als Rueckfall, wenn die Rolle verschwindet,
        // weil das Plugin ging, das sie mitbrachte.
        return array_values(array_map('strval', (array) ($user['permissions'] ?? [])));
    }

    // -----------------------------------------------------------------
    //  Vorlieben - pro Benutzer, nicht pro Installation
    // -----------------------------------------------------------------

    /**
     * Eine eigene Vorliebe des angemeldeten Benutzers.
     *
     * Der Unterschied zu $app->settings: dort liegt, was fuer den ganzen
     * Kanal gilt. Hier liegt, was nur diesen einen Menschen betrifft -
     * ob die Plugin-Liste knapp steht, zum Beispiel. Zwei Leute am
     * selben Kanal duerfen das verschieden haben.
     */
    public function preference(string $key, mixed $vorgabe = null): mixed
    {
        $user = $this->user();

        return $user === null ? $vorgabe : ($user['preferences'][$key] ?? $vorgabe);
    }

    public function setPreference(string $key, mixed $wert): void
    {
        $user = $this->user();
        if ($user === null) {
            return;
        }

        // Zusammenfuegen in der Datenbank und nicht hier: lesen, aendern,
        // schreiben wuerde bei zwei offenen Reitern die Aenderung des
        // einen wegwerfen. Der ||-Operator von JSONB legt nur das eine
        // Feld darueber.
        $this->app->db->run(
            'UPDATE users
                SET preferences = COALESCE(preferences, \'{}\'::jsonb) || CAST(:patch AS JSONB)
              WHERE twitch_id = :id',
            [
                'patch' => (string) json_encode([$key => $wert]),
                'id'    => (string) $user['twitch_id'],
            ]
        );

        // Der Benutzer liegt in diesem Request zwischengespeichert - ohne
        // das zeigte die Seite nach dem Umschalten noch den alten Wert.
        $this->user['preferences'][$key] = $wert;
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

        return in_array($permission, $this->permissionsOf($user), true);
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
            throw new RuntimeException(translate('auth.no_user'));
        }

        $existing = $this->find($twitchId);

        if ($existing === null) {
            $isFirstUser = ((int) $this->app->db->value('SELECT count(*) FROM users')) === 0;

            if (!$isFirstUser && !$this->redeemInvite($inviteCode, $twitchId)) {
                throw new RuntimeException(
                    translate('auth.no_invite')
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
            throw new RuntimeException(translate('auth.user_unreadable'));
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

        $row = $this->hydrate($row);

        return $row;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function users(): array
    {
        $rows = $this->app->db->all('SELECT * FROM users ORDER BY role DESC, display_name');

        foreach ($rows as $index => $row) {
            $rows[$index] = $this->hydrate($row);
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

        // Wer einzelne Haken setzt, hat keine Rolle mehr, sondern eine
        // eigene Auswahl. Bliebe die Rolle stehen, gewaenne sie beim
        // naechsten Laden - und die gerade gesetzten Haken waeren weg,
        // ohne dass eine Meldung es saegte.
        $this->app->db->run(
            'UPDATE users
                SET permissions = CAST(:permissions AS JSONB),
                    permission_role = \'\'
              WHERE twitch_id = :id',
            ['id' => $twitchId, 'permissions' => (string) json_encode($filtered)]
        );

        $this->app->hooks->dispatch('user.permissions_changed', $twitchId, $filtered);
    }

    /**
     * Eine Rolle zuweisen.
     *
     * Gespeichert wird der NAME. Die Rechte daraus werden bei jedem
     * Laden aufgeloest - deshalb kommt ein Recht, das spaeter zur Rolle
     * dazukommt, bei allen an, die sie haben.
     *
     * Die Liste wird trotzdem mitgeschrieben: sie ist der Ausgangspunkt,
     * wenn jemand die Rolle spaeter verlaesst, und der Rueckfall, wenn
     * die Rolle verschwindet - etwa weil ein Plugin ging, das sie
     * mitbrachte.
     */
    public function setRole(string $twitchId, string $rolle): void
    {
        $vorlagen = $this->rolePresets();

        if (!isset($vorlagen[$rolle])) {
            throw new RuntimeException(translate('account.users.no_such_preset'));
        }

        $keys = $vorlagen[$rolle]['keys'];

        $this->app->db->run(
            'UPDATE users
                SET permissions = CAST(:permissions AS JSONB),
                    permission_role = :rolle
              WHERE twitch_id = :id',
            [
                'id'          => $twitchId,
                'rolle'       => $rolle,
                'permissions' => (string) json_encode($keys),
            ]
        );

        $this->app->hooks->dispatch('user.permissions_changed', $twitchId, $keys);
    }

    public function removeUser(string $twitchId): void
    {
        if (($this->user()['twitch_id'] ?? null) === $twitchId) {
            throw new RuntimeException(translate('account.users.cannot_remove_self'));
        }

        $user = $this->find($twitchId);
        if ($user !== null && ($user['role'] ?? '') === self::ROLE_SUPERADMIN) {
            throw new RuntimeException(translate('account.users.cannot_remove_owner'));
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
            'Account' => [
                'label' => translate('nav.account'),
                'permissions' => [
                    'Account.Users.View'   => translate('permissions.users.view'),
                    'Account.Users.Manage' => translate('permissions.users.manage'),
                    'Account.Activity.View'   => translate('permissions.activity.view'),
                    'Account.Activity.Manage' => translate('permissions.activity.manage'),
                    'Account.Overlay.View'   => translate('permissions.overlay.view'),
                    'Account.Overlay.Manage' => translate('permissions.overlay.manage'),
                    'Account.Plugins.View'    => translate('permissions.plugins.view'),
                    'Account.Plugins.Manage'  => translate('permissions.plugins.manage'),
                    'Account.Settings.View'   => translate('permissions.settings.view'),
                    'Account.Settings.Manage' => translate('permissions.settings.manage'),
                ],
            ],

            /*
             * Hochladen steht ABSICHTLICH nicht unter "Account".
             *
             * Die Rolle "Stream-Helfer" bekommt alles ausserhalb von
             * Account - Alerts, Ziele, Overlay-Inhalte. Wer einen Alert
             * einrichten darf, muss auch das Video dazu hochladen
             * koennen; unter Account waere genau das ausgenommen, und
             * der Helfer stuende vor einem Feld, das er nicht fuellen
             * kann.
             */
            'Uploads' => [
                'label' => translate('permissions.uploads.group'),
                'permissions' => [
                    'Uploads.Media.Manage' => translate('permissions.uploads.manage'),
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
     * Der Rechte-Katalog als Baum: Bereich > Funktion > Recht.
     *
     * Die flachen Schluessel haben schon die Form Bereich.Funktion.Recht
     * ("Account.Users.View"). Der Baum entsteht deshalb durch Aufteilen
     * und braucht keine zweite Anmeldung - ein Plugin meldet seine
     * Rechte weiter ueber permissions.catalog an, unveraendert.
     *
     * Gebraucht wird der Baum fuer die Rechteseite: dort stehen die
     * Rechte einer Funktion nebeneinander in einer Reihe, mit der
     * Funktion als Zwischentitel. Bei knapp hundert Rechten ist das der
     * Unterschied zwischen benutzbar und unbenutzbar.
     *
     * @return array<string, array{label: string, features: array<string, array{label: string, permissions: array<string, array{label: string, description: string}>}>}>
     */
    public function permissionTree(): array
    {
        $rechte = self::rightLabels();
        $funktionen = self::featureLabels();

        $baum = [];

        foreach ($this->permissionCatalog() as $bereichKey => $bereich) {
            $bereichKey = (string) $bereichKey;

            foreach ((array) ($bereich['permissions'] ?? []) as $key => $beschreibung) {
                $teile = explode('.', (string) $key);

                // Alles, was nicht dem Schema entspricht, landet unter
                // einer Sammel-Funktion - lieber unsortiert angezeigt
                // als verschwiegen.
                $funktion = $teile[1] ?? 'General';
                $recht = $teile[2] ?? ($teile[1] ?? (string) $key);

                $baum[$bereichKey]['label'] = trim((string) ($bereich['label'] ?? $bereichKey)) ?: $bereichKey;
                $baum[$bereichKey]['features'][$funktion]['label'] = $funktionen[$funktion] ?? $funktion;
                $baum[$bereichKey]['features'][$funktion]['permissions'][(string) $key] = [
                    'label'       => $rechte[$recht] ?? $recht,
                    'description' => trim((string) $beschreibung),
                ];
            }
        }

        return $baum;
    }

    /**
     * Rollenvorlagen.
     *
     * Bewusst als Regel und nicht als Liste: Plugins bringen eigene
     * Rechte mit, und eine feste Liste waere nach dem ersten
     * installierten Plugin unvollstaendig - ohne dass es auffaellt.
     *
     * @return array<string, array{label: string, description: string, keys: list<string>}>
     */
    public function rolePresets(): array
    {
        $alle = $this->flatPermissionKeys();

        $nurAnsehen = array_values(array_filter(
            $alle,
            static fn (string $key): bool => str_ends_with($key, '.View')
        ));

        // Alles ausserhalb von "Account" ist Stream-Betrieb: Alerts,
        // Ziele, Overlay-Inhalte. Wer dort helfen soll, braucht das
        // ganz - aber nichts an Benutzern und Zugangsdaten.
        $streamHelfer = array_values(array_unique(array_merge(
            $nurAnsehen,
            array_values(array_filter(
                $alle,
                static fn (string $key): bool => !str_starts_with($key, 'Account.')
            ))
        )));

        // Editor: alles, ausser Benutzerverwaltung und Zugangsdaten.
        $editor = array_values(array_filter(
            $alle,
            static fn (string $key): bool => !str_starts_with($key, 'Account.Users.')
                && !str_starts_with($key, 'Account.Settings.')
        ));

        return [
            'readonly' => [
                'label'       => translate('roles.readonly'),
                'description' => translate('roles.readonly.hint'),
                'keys'        => $nurAnsehen,
            ],
            // Admin: wirklich alles, auch was spaeter dazukommt.
            //
            // Nicht dasselbe wie Superadmin: der ist der Kanalinhaber,
            // steht in users.role und laesst sich nicht vergeben. Ein
            // Admin ist ein Mensch, dem man alles anvertraut - und dem
            // man es auch wieder nehmen kann.
            'admin' => [
                'label'       => translate('roles.admin'),
                'description' => translate('roles.admin.hint'),
                'keys'        => $alle,
            ],
            'helper' => [
                'label'       => translate('roles.helper'),
                'description' => translate('roles.helper.hint'),
                'keys'        => $streamHelfer,
            ],
            'editor' => [
                'label'       => translate('roles.editor'),
                'description' => translate('roles.editor.hint'),
                'keys'        => $editor,
            ],
        ];
    }

    /**
     * Wie die Rolle eines Benutzers in der Liste heisst.
     *
     * Deckt sich seine Auswahl genau mit einer Vorlage, steht deren
     * Name da - sonst "Benutzerdefiniert". So sieht man auf einen Blick,
     * wer vom Schema abweicht.
     *
     * @param array<string, mixed> $user
     */
    public function roleLabel(array $user): string
    {
        if (($user['role'] ?? '') === 'superadmin') {
            return translate('roles.superadmin');
        }

        // Die zugewiesene Rolle, falls es eine gibt.
        //
        // Frueher wurde hier die Rechteliste mit jeder Rolle VERGLICHEN.
        // Das ging so lange gut, bis ein Plugin ein Recht mitbrachte:
        // die Rolle wuchs, die gespeicherte Liste nicht, und ab da stand
        // "Angepasst" an einem Benutzer, an dem niemand etwas angepasst
        // hatte.
        $vorlagen = $this->rolePresets();
        $rolle = trim((string) ($user['permission_role'] ?? ''));

        if ($rolle !== '' && isset($vorlagen[$rolle])) {
            return $vorlagen[$rolle]['label'];
        }

        // Ohne Rolle: passt die Auswahl trotzdem genau auf eine, wird
        // sie so benannt. Das betrifft die Benutzer, die es vor dieser
        // Aenderung schon gab - ihnen fehlt der Name, nicht die Rechte.
        $rechte = $this->permissionsOf($user);
        sort($rechte);

        foreach ($vorlagen as $vorlage) {
            $vergleich = $vorlage['keys'];
            sort($vergleich);

            if ($rechte === $vergleich) {
                return $vorlage['label'];
            }
        }

        return translate('roles.custom');
    }

    /**
     * Wie viele Rechte jemand hat, von wie vielen moeglichen.
     *
     * @param array<string, mixed> $user
     * @return array{have: int, total: int, all: bool}
     */
    public function permissionCount(array $user): array
    {
        $gesamt = count($this->flatPermissionKeys());

        if (($user['role'] ?? '') === 'superadmin') {
            return ['have' => $gesamt, 'total' => $gesamt, 'all' => true];
        }

        return [
            'have'  => count($this->permissionsOf($user)),
            'total' => $gesamt,
            'all'   => false,
        ];
    }

    /**
     * Klarnamen der letzten Schluesselstufe.
     *
     * Ausgeschrieben und nicht per translate('rights.' . $x)
     * zusammengesetzt: nur so sieht bin/lang.php die Schluessel und
     * kann fehlende melden.
     *
     * @return array<string, string>
     */
    private static function rightLabels(): array
    {
        return [
            'View'   => translate('rights.view'),
            'Manage' => translate('rights.manage'),
            'Edit'   => translate('rights.edit'),
            'Create' => translate('rights.create'),
            'Delete' => translate('rights.delete'),
            'Toggle' => translate('rights.toggle'),
            'Test'   => translate('rights.test'),
            'Sort'   => translate('rights.sort'),
            'Invite' => translate('rights.invite'),
            'Remove' => translate('rights.remove'),
        ];
    }

    /**
     * Klarnamen der mittleren Schluesselstufe. Unbekannte Funktionen -
     * etwa aus einem Plugin - erscheinen unter ihrem Schluessel.
     *
     * @return array<string, string>
     */
    private static function featureLabels(): array
    {
        return [
            'Users'         => translate('features.users'),
            'Activity'      => translate('features.activities'),
            'Overlay'       => translate('features.overlay'),
            'Plugins'       => translate('features.plugins'),
            'Settings'      => translate('features.settings'),
            // Sammelstelle fuer alles, was nicht dem Schema
            // Bereich.Funktion.Recht folgt - siehe permissionTree().
            'General'       => translate('features.general'),
        ];
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
