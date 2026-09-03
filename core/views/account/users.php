<?php
/**
 * Reiter "Freigegebene Benutzer": wer Zugang hat, seit wann, und wie
 * man ihn wieder los wird.
 *
 * @var \TwitchController\Core\App $app
 * @var \TwitchController\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var list<array<string, mixed>> $users
 * @var list<array<string, mixed>> $invites
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var string $link     Gerade erstellter Einladungslink, zum Kopieren
 */

$darfVerwalten = permission('Konto.Benutzer.Manage');
?>
<h1><?= $e(translate('account.users.tab_granted')) ?></h1>
<p class="lead"><?= $e(translate('account.users.lead')) ?></p>

<?= $view->render('account/_user_tabs', ['tab' => $tab], null) ?>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif ?>

<div class="card">
    <?php if ($darfVerwalten): ?>
        <div class="row">
            <form method="post" action="<?= $e($url('/account/users')) ?>" class="row">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="invite">
                <select name="hours" class="input" style="width:auto;">
                    <option value="24"><?= $e(translate('account.users.invite_24h')) ?></option>
                    <option value="72" selected><?= $e(translate('account.users.invite_3d')) ?></option>
                    <option value="168"><?= $e(translate('account.users.invite_7d')) ?></option>
                </select>
                <button class="btn btn-small" type="submit"><?= $e(translate('account.users.create_code')) ?></button>
            </form>
        </div>

        <?php if ($link !== ''): ?>
            <div class="field" style="margin-top:14px;">
                <label>
                    <span class="hint"><?= $e(translate('account.users.link')) ?></span><br>
                    <?php /* readonly und nicht nur Text: so laesst sich der Link mit
                            einem Griff markieren und kopieren. */ ?>
                    <input class="input mono" type="text" readonly
                           onclick="this.select();"
                           value="<?= $e($link) ?>">
                </label>
                <p class="hint"><?= $e(translate('account.users.link_hint')) ?></p>
            </div>
        <?php endif ?>
    <?php endif ?>

    <table style="margin-top:16px;">
        <thead>
        <tr>
            <th><?= $e(translate('account.users.name')) ?></th>
            <th><?= $e(translate('account.users.twitch_id')) ?></th>
            <th><?= $e(translate('account.users.role')) ?></th>
            <th><?= $e(translate('account.users.added')) ?></th>
            <th><?= $e(translate('account.users.last_seen')) ?></th>
            <?php if ($darfVerwalten): ?>
                <th></th>
            <?php endif ?>
        </tr>
        </thead>
        <tbody>
        <?php if ($users === []): ?>
            <tr>
                <td colspan="<?= $darfVerwalten ? 6 : 5 ?>" class="hint">
                    <?= $e(translate('account.users.none')) ?>
                </td>
            </tr>
        <?php endif ?>

        <?php foreach ($users as $user): ?>
            <?php $istSuper = ($user['role'] ?? '') === 'superadmin'; ?>
            <tr>
                <td><strong><?= $e($user['display_name']) ?></strong></td>
                <td class="mono"><?= $e($user['twitch_id']) ?></td>
                <td><?= $e($app->auth->roleLabel($user)) ?></td>
                <td class="hint"><?= $e(\TwitchController\Core\Support\Dates::long($user['created_at'] ?? null)) ?></td>
                <td class="hint"><?= $e(\TwitchController\Core\Support\Dates::long($user['last_seen_at'] ?? null)) ?></td>
                <?php if ($darfVerwalten): ?>
                    <td class="actions">
                        <?php /* Der Superadmin bleibt: ohne ihn koennte niemand mehr
                                Rechte vergeben. */ ?>
                        <?php if (!$istSuper): ?>
                            <form method="post" action="<?= $e($url('/account/users')) ?>"
                                  onsubmit="return confirm('<?= $e(translate('account.users.confirm_remove', ['name' => $user['display_name']])) ?>');">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="remove">
                                <input type="hidden" name="twitch_id" value="<?= $e($user['twitch_id']) ?>">
                                <button class="btn btn-danger btn-small" type="submit">
                                    <?= $e(translate('common.remove')) ?>
                                </button>
                            </form>
                        <?php endif ?>
                    </td>
                <?php endif ?>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table>
</div>

<?php if ($darfVerwalten && $invites !== []): ?>
    <div class="card">
        <div class="card-head">
            <h2><?= $e(translate('account.users.invites')) ?></h2>
        </div>

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
                    <td class="hint"><?= $e(\TwitchController\Core\Support\Dates::long($invite['expires_at'])) ?></td>
                    <td class="actions">
                        <form method="post" action="<?= $e($url('/account/users')) ?>">
                            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                            <input type="hidden" name="action" value="revoke_invite">
                            <input type="hidden" name="code" value="<?= $e($invite['code']) ?>">
                            <button class="btn btn-ghost btn-small" type="submit">
                                <?= $e(translate('account.users.revoke')) ?>
                            </button>
                        </form>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    </div>
<?php endif ?>
