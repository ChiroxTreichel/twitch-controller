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
<h1><?= $e(translate('nav.users')) ?></h1>
<p class="lead"><?= $e(translate('account.users.lead')) ?></p>

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
            <th><?= $e(translate('account.users.account')) ?></th>
            <th><?= $e(translate('account.users.role')) ?></th>
            <th><?= $e(translate('account.users.permissions')) ?></th>
            <th><?= $e(translate('account.users.last_seen')) ?></th>
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
                        <span class="badge badge-ok"><?= $e(translate('nav.owner')) ?></span>
                    <?php else: ?>
                        <span class="badge"><?= $e(translate('nav.team')) ?></span>
                    <?php endif; ?>
                </td>
                <td class="hint">
                    <?php if ($user['role'] === 'superadmin'): ?>
                        <?= $e(translate('account.users.all')) ?>
                    <?php else: ?>
                        <?= $e((string) count((array) $user['permissions'])) ?>
                    <?php endif; ?>
                </td>
                <td class="hint"><?= $e(\Overlays\Core\Support\Dates::long($user['last_seen_at'])) ?></td>
                <td class="actions">
                    <?php if ($canManage && $user['role'] !== 'superadmin'): ?>
                        <a class="btn btn-ghost btn-small"
                           href="<?= $e($url('/account/users?bearbeiten=' . rawurlencode((string) $user['twitch_id']))) ?>"><?= $e(translate('account.users.permissions')) ?></a>
                        <form method="post" action="<?= $e($url('/account/users')) ?>" style="display:inline;"
                              onsubmit="return confirm('<?= $e(translate('account.users.confirm_remove', ['name' => $user['display_name']])) ?>');">
                            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="action" value="remove">
                            <input type="hidden" name="twitch_id" value="<?= $e($user['twitch_id']) ?>">
                            <button class="btn btn-danger btn-small" type="submit"><?= $e(translate('common.remove')) ?></button>
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
            <h2><?= $e(translate('account.users.permissions_for', ['name' => $editUser['display_name']])) ?></h2>
            <a class="btn btn-ghost btn-small" href="<?= $e($url('/account/users')) ?>"><?= $e(translate('common.cancel')) ?></a>
        </div>

        <form method="post" action="<?= $e($url('/account/users')) ?>">
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

            <button class="btn" type="submit"><?= $e(translate('account.users.save_permissions')) ?></button>
        </form>
    </div>
<?php endif; ?>

<?php if ($canManage): ?>
    <div class="card">
        <div class="card-head">
            <h2><?= $e(translate('account.users.invites')) ?></h2>
            <form method="post" action="<?= $e($url('/account/users')) ?>" class="row">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="invite">
                <select name="hours" class="input" style="width:auto;">
                    <option value="24"><?= $e(translate('account.users.invite_24h')) ?></option>
                    <option value="72" selected><?= $e(translate('account.users.invite_3d')) ?></option>
                    <option value="168"><?= $e(translate('account.users.invite_7d')) ?></option>
                </select>
                <button class="btn btn-small" type="submit"><?= $e(translate('account.users.create_link')) ?></button>
            </form>
        </div>

        <?php if ($invites === []): ?>
            <div class="empty"><?= $e(translate('account.users.no_invites')) ?></div>
        <?php else: ?>
            <table>
                <thead>
                <tr>
                    <th><?= $e(translate('account.users.link')) ?></th>
                    <th><?= $e(translate('account.users.expires')) ?></th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($invites as $invite): ?>
                    <tr>
                        <td class="mono"><?= $e($url('/login?invite=' . $invite['code'])) ?></td>
                        <td class="hint"><?= $e(\Overlays\Core\Support\Dates::long($invite['expires_at'])) ?></td>
                        <td class="actions">
                            <form method="post" action="<?= $e($url('/account/users')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="revoke_invite">
                                <input type="hidden" name="code" value="<?= $e($invite['code']) ?>">
                                <button class="btn btn-ghost btn-small" type="submit"><?= $e(translate('account.users.revoke')) ?></button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
<?php endif; ?>
