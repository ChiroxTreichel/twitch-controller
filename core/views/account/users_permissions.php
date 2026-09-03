<?php
/**
 * Reiter "Benutzerrechte": dieselbe Liste, aber mit dem Blick auf die
 * Rechte - Rolle und Anzahl. Geändert wird auf einer eigenen Seite,
 * weil es knapp hundert Kästchen sind.
 *
 * @var \TwitchController\Core\App $app
 * @var \TwitchController\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var list<array<string, mixed>> $users
 * @var string $notice
 * @var string $error
 */

$darfVerwalten = permission('Konto.Benutzer.Manage');
?>
<h1><?= $e(translate('account.users.tab_permissions')) ?></h1>
<p class="lead"><?= $e(translate('account.users.permissions_lead')) ?></p>

<?= $view->render('account/_user_tabs', ['tab' => $tab], null) ?>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif ?>

<div class="card">
    <table>
        <thead>
        <tr>
            <th><?= $e(translate('account.users.name')) ?></th>
            <th><?= $e(translate('account.users.twitch_id')) ?></th>
            <th><?= $e(translate('account.users.role')) ?></th>
            <th><?= $e(translate('account.users.permission_count')) ?></th>
            <th></th>
        </tr>
        </thead>
        <tbody>
        <?php if ($users === []): ?>
            <tr><td colspan="5" class="hint"><?= $e(translate('account.users.none')) ?></td></tr>
        <?php endif ?>

        <?php foreach ($users as $user): ?>
            <?php
            $istSuper = ($user['role'] ?? '') === 'superadmin';
            $anzahl = $app->auth->permissionCount($user);
            ?>
            <tr>
                <td>
                    <strong><?= $e($user['display_name']) ?></strong>
                    <?php if ($istSuper): ?>
                        <span class="hint">(<?= $e(translate('roles.superadmin')) ?>)</span>
                    <?php endif ?>
                </td>
                <td class="mono"><?= $e($user['twitch_id']) ?></td>
                <td><?= $e($app->auth->roleLabel($user)) ?></td>
                <td class="mono">
                    <?php if ($anzahl['all']): ?>
                        <?= $e(translate('account.users.all_permissions')) ?>
                    <?php else: ?>
                        <?= (int) $anzahl['have'] ?> / <?= (int) $anzahl['total'] ?>
                    <?php endif ?>
                </td>
                <td class="actions">
                    <?php if ($istSuper): ?>
                        <span class="hint">–</span>
                    <?php elseif ($darfVerwalten): ?>
                        <a class="btn btn-ghost btn-small"
                           href="<?= $e($url('/account/users/permissions/' . rawurlencode((string) $user['twitch_id']))) ?>">
                            <?= $e(translate('common.edit')) ?>
                        </a>
                    <?php endif ?>
                </td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>
