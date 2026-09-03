<?php
/**
 * Die Rechte eines Benutzers.
 *
 * Aufbau wie im alten System: ein Kasten je Bereich, darin die
 * Funktionen als Zwischentitel und deren Rechte nebeneinander. Bei
 * knapp hundert Kästchen ist diese Gliederung der Unterschied
 * zwischen benutzbar und unbenutzbar.
 *
 * @var \TwitchController\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var array<string, mixed> $target
 * @var array<string, array{label: string, features: array<string, array{label: string, permissions: array<string, array{label: string, description: string}>}}>> $tree
 * @var array<string, array{label: string, description: string, keys: list<string>}> $presets
 * @var array{have: int, total: int, all: bool} $count
 * @var bool $isSuper
 * @var string $csrf
 * @var string $notice
 * @var string $error
 */

$darfVerwalten = permission('Account.Users.Manage');
$aenderbar = $darfVerwalten && !$isSuper;
$hat = array_map('strval', (array) ($target['permissions'] ?? []));
$id = (string) $target['twitch_id'];
$liste = $url('/account/users/permissions');
?>
<h1><?= $e(translate('account.users.permissions_for', ['name' => $target['display_name']])) ?></h1>
<p class="lead">
    <?= translate('account.users.twitch_id_is', ['id' => '<span class="mono">' . $e($id) . '</span>']) ?>
    <?php if ($isSuper): ?>
        <?= $e(translate('account.users.superadmin_note')) ?>
    <?php endif ?>
</p>

<?= $view->render('account/_user_tabs', ['tab' => $tab], null) ?>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif ?>

<div class="row">
    <a class="btn btn-ghost btn-small" href="<?= $e($liste) ?>">
        &larr; <?= $e(translate('account.users.back_to_list')) ?>
    </a>
    <span class="hint right">
        <?php if ($count['all']): ?>
            <?= $e(translate('account.users.all_permissions')) ?>
        <?php else: ?>
            <?= (int) $count['have'] ?> / <?= (int) $count['total'] ?>
        <?php endif ?>
    </span>
</div>

<?php if ($aenderbar && $presets !== []): ?>
    <div class="card">
        <div class="card-head">
            <h2><?= $e(translate('account.users.presets')) ?></h2>
        </div>
        <p class="hint"><?= $e(translate('account.users.presets_hint')) ?></p>

        <div class="row">
            <?php foreach ($presets as $key => $preset): ?>
                <form method="post" action="<?= $e($url('/account/users')) ?>">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="preset">
                    <input type="hidden" name="twitch_id" value="<?= $e($id) ?>">
                    <input type="hidden" name="preset" value="<?= $e((string) $key) ?>">
                    <button class="btn btn-ghost btn-small" type="submit"
                            title="<?= $e($preset['description']) ?>">
                        <?= $e(translate('account.users.set_role', ['role' => $preset['label']])) ?>
                    </button>
                </form>
            <?php endforeach ?>
        </div>
    </div>
<?php endif ?>

<form method="post" action="<?= $e($url('/account/users')) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="permissions">
    <input type="hidden" name="twitch_id" value="<?= $e($id) ?>">

    <?php foreach ($tree as $bereich): ?>
        <div class="card">
            <div class="card-head">
                <h2><?= $e($bereich['label']) ?></h2>
            </div>

            <?php foreach ($bereich['features'] as $funktion): ?>
                <div class="perm-feature">
                    <h3><?= $e($funktion['label']) ?></h3>

                    <div class="perm-grid">
                        <?php foreach ($funktion['permissions'] as $key => $recht): ?>
                            <label class="perm" title="<?= $e((string) $key) ?>">
                                <input type="checkbox" name="permissions[]"
                                       value="<?= $e((string) $key) ?>"
                                    <?= in_array((string) $key, $hat, true) ? 'checked' : '' ?>
                                    <?= $aenderbar ? '' : 'disabled' ?>>
                                <span>
                                    <strong><?= $e($recht['label']) ?></strong>
                                    <?php if ($recht['description'] !== ''): ?>
                                        <span class="hint"><?= $e($recht['description']) ?></span>
                                    <?php endif ?>
                                </span>
                            </label>
                        <?php endforeach ?>
                    </div>
                </div>
            <?php endforeach ?>
        </div>
    <?php endforeach ?>

    <?php if ($aenderbar): ?>
        <div class="row">
            <button class="btn" type="submit"><?= $e(translate('common.save')) ?></button>
            <a class="btn btn-ghost" href="<?= $e($liste) ?>"><?= $e(translate('common.cancel')) ?></a>
        </div>
    <?php endif ?>
</form>
