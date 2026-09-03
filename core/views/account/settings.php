<?php
/**
 * Einstellungen, Reiter «System».
 *
 * Fassung und Update, Zeitzone, Sprache und die Reihenfolge der
 * Menuebereiche. Alles, was das System als Ganzes betrifft - der
 * Twitch-Zugang steckt in den anderen Reitern.
 *
 * @var \TwitchController\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var string $installPath
 * @var string $language
 * @var list<string> $languages
 * @var list<array{key: string, label: string}> $navGroups
 * @var string $timezone
 * @var array<string, string> $timezones
 * @var array<string, mixed> $update
 * @var bool $updatePossible
 * @var string $updateVersion
 */

use TwitchController\Core\Support\Dates;
?>
<h1><?= $e(translate('nav.settings')) ?></h1>
<p class="lead"><?= $e(translate('settings.lead')) ?></p>

<?= $view->render('account/_settings_tabs', ['tab' => $tab], null) ?>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('settings.system.title')) ?></h2>
        <?php if (!$updatePossible): ?>
            <span class="badge badge-off"><?= $e(translate('settings.system.manual')) ?></span>
        <?php elseif ($update['requested_at'] > 0): ?>
            <span class="badge badge-warn"><?= $e(translate('settings.system.running')) ?></span>
        <?php elseif ($update['available']): ?>
            <span class="badge badge-warn"><?= $e(translate('market.update_available')) ?></span>
        <?php elseif ($update['checked_at'] > 0): ?>
            <span class="badge badge-ok"><?= $e(translate('settings.system.current')) ?></span>
        <?php endif; ?>
    </div>

    <table>
        <tbody>
        <tr>
            <td><?= $e(translate('settings.system.installed_version')) ?></td>
            <td class="actions mono"><?= $e($updateVersion) ?></td>
        </tr>
        <?php if ($update['checked_at'] > 0): ?>
            <tr>
                <td><?= $e(translate('settings.system.last_checked')) ?></td>
                <td class="actions hint">
                    <?= $e(Dates::long(date('c', $update['checked_at']))) ?>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if (!$updatePossible): ?>
        <p class="hint" style="margin-top:12px;">
            <?php // Ohne $e: die Platzhalter sind eigenes Markup. ?>
            <?= translate('settings.system.cannot_update', [
                'git'     => '<span class="mono">git</span>',
                'command' => '<span class="mono">sudo ./install.sh</span>',
            ]) ?>
        </p>
    <?php else: ?>

        <?php if ($update['requested_at'] > 0): ?>
            <div class="note note-warn" style="margin:14px 0 0;">
                <?= $e(translate('settings.system.queued')) ?>
            </div>
        <?php elseif ($update['available']): ?>
            <div class="note note-warn" style="margin:14px 0 0;">
                <strong><?= $e(translate('settings.system.newer')) ?></strong>
                <?php if ($update['subject'] !== ''): ?>
                    <div class="hint" style="margin-top:6px;">
                        <?= $e(translate('settings.system.latest_change', ['subject' => $update['subject']])) ?>
                    </div>
                <?php endif; ?>

                <?php if ($update['needs_shell']): ?>
                    <p style="margin:10px 0 0;">
                        <?= $e(translate('settings.system.needs_shell')) ?>
                    </p>
                    <p class="mono" style="background:var(--bg);padding:10px 12px;border-radius:9px;border:1px solid var(--line);margin:8px 0 0;">
                        cd <?= $e($installPath) ?> &amp;&amp; sudo ./install.sh
                    </p>
                <?php elseif ($canManage): ?>
                    <form method="post" action="<?= $e($url('/account/settings')) ?>"
                          style="margin-top:12px;">
                        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="action" value="update_apply">
                        <button class="btn btn-small" type="submit"><?= $e(translate('settings.system.update_now')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($update['last_result'] !== []): ?>
            <?php $letztes = $update['last_result']; ?>
            <div class="note <?= !empty($letztes['ok']) ? 'note-ok' : 'note-error' ?>" style="margin:14px 0 0;">
                <strong><?= $e(!empty($letztes['ok'])
                    ? translate('settings.system.last_ok')
                    : translate('settings.system.last_failed')) ?></strong>
                <div class="hint" style="margin-top:4px;">
                    <?= $e((string) ($letztes['message'] ?? '')) ?>
                    <?php if (!empty($letztes['at'])): ?>
                        &middot; <?= $e(Dates::long((string) $letztes['at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canManage && $update['requested_at'] === 0): ?>
            <form method="post" action="<?= $e($url('/account/settings')) ?>" style="margin-top:14px;">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="update_check">
                <button class="btn btn-ghost btn-small" type="submit"><?= $e(translate('settings.system.check')) ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form method="post" action="<?= $e($url('/account/settings')) ?>" class="row"
              style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line);">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="timezone">
            <label for="timezone" style="margin:0;"><?= $e(translate('settings.timezone')) ?></label>
            <select class="input" id="timezone" name="timezone" style="width:auto;">
                <?php foreach ($timezones as $zone): ?>
                    <option value="<?= $e($zone) ?>" <?= $timezone === $zone ? 'selected' : '' ?>>
                        <?= $e($zone) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-small" type="submit"><?= $e(translate('common.apply')) ?></button>
        </form>

        <form method="post" action="<?= $e($url('/account/settings')) ?>" class="row"
              style="margin-top:10px;">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="language">
            <label for="language" style="margin:0;"><?= $e(translate('settings.language')) ?></label>
            <select class="input" id="language" name="language" style="width:auto;">
                <?php foreach ($languages as $code): ?>
                    <option value="<?= $e($code) ?>" <?= $language === $code ? 'selected' : '' ?>>
                        <?= $e(\TwitchController\Core\I18n\Translator::label($code)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-small" type="submit"><?= $e(translate('common.apply')) ?></button>
        </form>
        <p class="hint" style="margin-top:8px;">
            <?= translate('settings.language_hint', ['file' => '<span class="mono">lang/&lt;code&gt;.json</span>']) ?>
        </p>

        <p class="hint" style="margin-top:8px;">
            <?= $e(translate('settings.timezone_hint')) ?>
        </p>
    <?php endif; ?>
</div>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<?= $view->render('account/_settings_nav_order', [
    'navGroups' => $navGroups,
    'canManage' => $canManage,
    'csrf'      => $csrf,
], null) ?>
