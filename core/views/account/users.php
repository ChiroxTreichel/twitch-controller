<?php
/**
 * @var \Overlays\Core\App $app
 * @var callable $e
 * @var callable $url
 * @var list<array<string, mixed>> $users
 * @var list<array<string, mixed>> $invites
 * @var array<string, array{label: string, permissions: array<string, string>}> $catalog
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var string $editing
 */
?>
<h1>Benutzer</h1>
<p class="lead">Wer darf mit an dieses Kontrollpult.</p>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <table>
        <thead>
        <tr>
            <th>Account</th>
            <th>Rolle</th>
            <th>Rechte</th>
            <th>Zuletzt gesehen</th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php foreach ($users as $user): ?>
            <tr>
                <td>
                    <strong><?= $e($user['display_name']) ?></strong>
                    <div class="hint mono"><?= $e($user['twitch_id']) ?></div>
                </td>
                <td>
                    <?php if ($user['role'] === 'superadmin'): ?>
                        <span class="badge badge-ok">Kanalinhaber</span>
                    <?php else: ?>
                        <span class="badge">Team</span>
                    <?php endif; ?>
                </td>
                <td class="hint">
                    <?php if ($user['role'] === 'superadmin'): ?>
                        alle
                    <?php else: ?>
                        <?= $e((string) count((array) $user['permissions'])) ?>
                    <?php endif; ?>
                </td>
                <td class="hint"><?= $e(substr((string) $user['last_seen_at'], 0, 16)) ?></td>
                <td class="actions">
                    <?php if ($canManage && $user['role'] !== 'superadmin'): ?>
                        <a class="btn btn-ghost btn-small"
                           href="<?= $e($url('/konto/benutzer?bearbeiten=' . rawurlencode((string) $user['twitch_id']))) ?>">Rechte</a>
                        <form method="post" action="<?= $e($url('/konto/benutzer')) ?>" style="display:inline;"
                              onsubmit="return confirm('<?= $e($user['display_name']) ?> wirklich entfernen?');">
                            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="twitch_id" value="<?= $e($user['twitch_id']) ?>">
                            <button class="btn btn-danger btn-small" type="submit">Entfernen</button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php
$editUser = null;
foreach ($users as $candidate) {
    if ($editing !== '' && (string) $candidate['twitch_id'] === $editing && $candidate['role'] !== 'superadmin') {
        $editUser = $candidate;
    }
}
?>

<?php if ($canManage && $editUser !== null): ?>
    <div class="card">
        <div class="card-head">
            <h2>Rechte für <?= $e($editUser['display_name']) ?></h2>
            <a class="btn btn-ghost btn-small" href="<?= $e($url('/konto/benutzer')) ?>">Abbrechen</a>
        </div>

        <form method="post" action="<?= $e($url('/konto/benutzer')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="permissions">
            <input type="hidden" name="twitch_id" value="<?= $e($editUser['twitch_id']) ?>">

            <?php foreach ($catalog as $group): ?>
                <div class="perm-group">
                    <h3><?= $e($group['label']) ?></h3>
                    <?php foreach ((array) $group['permissions'] as $key => $description): ?>
                        <label class="perm">
                            <input type="checkbox" name="permissions[]" value="<?= $e((string) $key) ?>"
                                <?= in_array((string) $key, (array) $editUser['permissions'], true) ? 'checked' : '' ?>>
                            <span>
                                <?= $e((string) $description) ?>
                                <br><code><?= $e((string) $key) ?></code>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>

            <button class="btn" type="submit">Rechte speichern</button>
        </form>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="card">
        <div class="card-head">
            <h2>Einladungen</h2>
            <form method="post" action="<?= $e($url('/konto/benutzer')) ?>" class="row">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="invite">
                <select name="hours" class="input" style="width:auto;">
                    <option value="24">24 Stunden gültig</option>
                    <option value="72" selected>3 Tage gültig</option>
                    <option value="168">7 Tage gültig</option>
                </select>
                <button class="btn btn-small" type="submit">Link erstellen</button>
            </form>
        </div>

        <?php if ($invites === []): ?>
            <div class="empty">Keine offenen Einladungen.</div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th>Link</th>
                    <th>Läuft ab</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($invites as $invite): ?>
                    <tr>
                        <td class="mono"><?= $e($url('/login?invite=' . $invite['code'])) ?></td>
                        <td class="hint"><?= $e(substr((string) $invite['expires_at'], 0, 16)) ?></td>
                        <td class="actions">
                            <form method="post" action="<?= $e($url('/konto/benutzer')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="revoke_invite">
                                <input type="hidden" name="code" value="<?= $e($invite['code']) ?>">
                                <button class="btn btn-ghost btn-small" type="submit">Zurückziehen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
