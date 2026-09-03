<?php
/**
 * Reiter "Installierte Plugins".
 *
 * @var \Overlays\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var list<array<string, mixed>> $rows
 * @var list<string> $missing
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var bool $welcome
 */
?>
<h1><?= $e(translate('nav.plugins')) ?></h1>
<p class="lead"><?= $e(translate('account.plugins.lead')) ?></p>

<?= $view->render('account/_plugin_tabs', ['tab' => $tab], null) ?>

<?php if ($welcome): ?>
    <div class="note note-ok">
        <strong><?= $e(translate('account.plugins.setup_done')) ?></strong>
        <?= translate('account.plugins.setup_done_hint', ['tab' => '<em>' . $e(translate('account.plugins.tab_find')) . '</em>']) ?>
    </div>
<?php endif; ?>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<?php if ($missing !== []): ?>
    <div class="note note-warn">
        <?= translate('account.plugins.missing_files', ['slugs' => '<span class="mono">' . $e(implode(', ', $missing)) . '</span>']) ?>
    </div>
<?php endif; ?>

<?php if ($rows === []): ?>
    <div class="card">
        <div class="empty">
            <?= $e(translate('account.plugins.none')) ?><br>
            <a class="btn btn-small" style="margin-top:14px;"
               href="<?= $e($url('/konto/plugins/finden')) ?>"><?= $e(translate('account.plugins.tab_find')) ?></a>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($rows as $row): ?>
        <?php $manifest = $row['manifest']; ?>
        <div class="card">
            <div class="card-head">
                <div>
                    <h2>
                        <?= $e($manifest->name) ?>
                        <?php if ($row['enabled']): ?>
                            <span class="badge badge-ok"><?= $e(translate('common.active')) ?></span>
                        <?php elseif ($row['installed']): ?>
                            <span class="badge badge-off"><?= $e(translate('common.installed_off')) ?></span>
                        <?php else: ?>
                            <span class="badge"><?= $e(translate('account.plugins.available')) ?></span>
                        <?php endif; ?>
                        <?php if ($row['catalog'] !== null): ?>
                            <span class="badge badge-warn"><?= $e(translate('account.plugins.version_available', ['version' => $row['catalog']])) ?></span>
                        <?php elseif ($row['updatable']): ?>
                            <span class="badge badge-warn"><?= $e(translate('account.plugins.update_ready')) ?></span>
                        <?php endif; ?>
                    </h2>
                    <div class="hint">
                        <?= $e(translate('common.version', ['version' => $manifest->version])) ?>
                        <?php if ($row['installed'] && $row['version'] !== $manifest->version): ?>
                            <?= $e(translate('account.plugins.installed_version', ['version' => (string) $row['version']])) ?>
                        <?php endif; ?>
                        <?php if ($manifest->author !== ''): ?>
                            &middot; <?= $e($manifest->author) ?>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($canManage): ?>
                    <div class="row">
                        <?php if ($row['enabled'] && $row['settings'] !== null): ?>
                            <a class="btn btn-ghost btn-small"
                               href="<?= $e($url($row['settings']['href'])) ?>">
                                <?= $e($row['settings']['label']) ?>
                            </a>
                        <?php endif; ?>

                        <?php if ($row['catalog'] !== null): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="download_update">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-small" type="submit">
                                    <?= $e(translate('account.plugins.update_to', ['version' => $row['catalog']])) ?>
                                </button>
                            </form>
                        <?php elseif ($row['updatable']): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-small" type="submit"><?= $e(translate('common.update')) ?></button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$row['installed'] || !$row['enabled']): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="enable">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-small" type="submit"
                                    <?= $row['blockers'] !== [] ? 'disabled' : '' ?>>
                                    <?= $e($row['installed'] ? translate('common.enable') : translate('common.install')) ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="disable">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-ghost btn-small" type="submit"><?= $e(translate('common.disable')) ?></button>
                            </form>
                        <?php endif; ?>

                        <?php if ($row['installed'] && !$row['enabled']): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>"
                                  onsubmit="return confirm('<?= $e(translate('account.plugins.confirm_remove')) ?>');">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="uninstall">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-danger btn-small" type="submit"><?= $e(translate('common.remove')) ?></button>
                            </form>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <?php if ($manifest->description !== ''): ?>
                <p style="margin:0 0 10px;"><?= $e($manifest->description) ?></p>
            <?php endif; ?>

            <?php if ($manifest->requiredPlugins() !== [] || $manifest->optionalPlugins() !== []): ?>
                <div class="hint">
                    <?php if ($manifest->requiredPlugins() !== []): ?>
                        <div><?= $e(translate('account.plugins.requires', ['plugins' => implode(', ', array_keys($manifest->requiredPlugins()))])) ?></div>
                    <?php endif; ?>
                    <?php if ($manifest->optionalPlugins() !== []): ?>
                        <div><?= $e(translate('account.plugins.optional', ['plugins' => implode(', ', array_keys($manifest->optionalPlugins()))])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($row['blockers'] !== []): ?>
                <div class="note note-warn" style="margin:12px 0 0;">
                    <?= $e(implode(' ', $row['blockers'])) ?>
                </div>
            <?php endif; ?>

            <?php if ($row['enabled'] && $row['dependents'] !== []): ?>
                <div class="hint" style="margin-top:10px;">
                    <?= $e(translate('account.plugins.needed_by', ['plugins' => implode(', ', $row['dependents'])])) ?>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
