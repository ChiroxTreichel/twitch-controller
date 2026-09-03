<?php
/**
 * Reiter "Plugins finden" - der Katalog.
 *
 * @var \Overlays\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var list<array<string, mixed>> $plugins
 * @var list<string> $tags
 * @var string $query
 * @var string $tag
 * @var string $registry
 * @var int $fetchedAt
 * @var bool $canManage
 * @var bool $canWrite
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var array<string, array{installed: bool, enabled: bool, version: ?string}> $states
 */
?>
<h1><?= $e(translate('account.plugins.tab_find')) ?></h1>
<p class="lead"><?= $e(translate('market.lead')) ?></p>

<?= $view->render('account/_plugin_tabs', ['tab' => $tab], null) ?>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error">
        <strong><?= $e(translate('market.unreachable')) ?></strong>
        <div class="hint" style="margin-top:6px;"><?= $e($error) ?></div>
        <div class="hint" style="margin-top:6px;">
            <?= translate('market.source', ['url' => '<span class="mono">' . $e($registry) . '</span>']) ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!$canWrite): ?>
    <div class="note note-warn">
        <?= translate('market.not_writable', ['directory' => '<span class="mono">plugins/</span>', 'command' => '<span class="mono">sudo ./install.sh</span>']) ?>
    </div>
<?php endif; ?>

<div class="card">
    <form method="get" action="<?= $e($url('/konto/plugins/finden')) ?>" class="row">
        <input class="input grow" type="search" name="q" placeholder="<?= $e(translate('market.search_placeholder')) ?>"
               value="<?= $e($query) ?>">
        <?php if ($tag !== ''): ?>
            <input type="hidden" name="tag" value="<?= $e($tag) ?>">
        <?php endif; ?>
        <button class="btn btn-small" type="submit"><?= $e(translate('common.search')) ?></button>
        <?php if ($query !== '' || $tag !== ''): ?>
            <a class="btn btn-ghost btn-small" href="<?= $e($url('/konto/plugins/finden')) ?>"><?= $e(translate('market.show_all')) ?></a>
        <?php endif; ?>
    </form>

    <?php if ($tags !== []): ?>
        <div class="row" style="margin-top:12px;">
            <?php foreach ($tags as $one): ?>
                <a class="badge<?= $tag === $one ? ' badge-ok' : '' ?>"
                   style="text-decoration:none;"
                   href="<?= $e($url('/konto/plugins/finden?' . http_build_query(
                       $tag === $one ? ['q' => $query] : ['q' => $query, 'tag' => $one]
                   ))) ?>"><?= $e($one) ?></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row" style="margin-top:12px;">
        <span class="hint grow">
            <?php if ($fetchedAt > 0): ?>
                <?= $e(translate('market.catalog_from', ['date' => \Overlays\Core\Support\Dates::long(date('c', $fetchedAt))])) ?>
            <?php else: ?>
                <?= $e(translate('market.catalog_not_loaded')) ?>
            <?php endif; ?>
        </span>
        <?php if ($canManage): ?>
            <form method="post" action="<?= $e($url('/konto/plugins/finden')) ?>">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="refresh">
                <button class="btn btn-ghost btn-small" type="submit"><?= $e(translate('market.reload')) ?></button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php if ($plugins === [] && $error === ''): ?>
    <div class="card">
        <div class="empty">
            <?php if ($query !== '' || $tag !== ''): ?>
                <?= $e(translate('market.nothing_found')) ?><br>
                <span class="hint"><?= $e(translate('market.try_other')) ?></span>
            <?php else: ?>
                <?= $e(translate('market.empty')) ?>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php foreach ($plugins as $plugin): ?>
    <?php
    $state = $states[$plugin['slug']] ?? null;
    $detailUrl = $url('/konto/plugins/finden/' . rawurlencode((string) $plugin['slug']));
    $neuer = $state !== null && version_compare((string) $state['version'], (string) $plugin['version'], '<');
    ?>
    <div class="card">
        <div class="card-head">
            <div class="row" style="gap:14px;align-items:flex-start;">
                <?php if ($plugin['icon'] !== ''): ?>
                    <img src="<?= $e($plugin['icon']) ?>" alt="" width="44" height="44"
                         style="border-radius:10px;background:var(--panel-2);" loading="lazy">
                <?php endif; ?>
                <div>
                    <h2 style="margin:0;">
                        <a href="<?= $e($detailUrl) ?>" style="text-decoration:none;"><?= $e($plugin['name']) ?></a>
                        <?php if ($state === null): ?>
                            <span class="badge"><?= $e(translate('common.not_installed')) ?></span>
                        <?php elseif ($neuer): ?>
                            <span class="badge badge-warn"><?= $e(translate('market.update_available')) ?></span>
                        <?php elseif ($state['enabled']): ?>
                            <span class="badge badge-ok"><?= $e(translate('common.active')) ?></span>
                        <?php else: ?>
                            <span class="badge badge-off"><?= $e(translate('common.installed_off')) ?></span>
                        <?php endif; ?>
                    </h2>
                    <div class="hint">
                        <?= $e(translate('common.version', ['version' => $plugin['version']])) ?>
                        <?php if ($plugin['author'] !== ''): ?>
                            &middot; <?= $e($plugin['author']) ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="row">
                <a class="btn btn-ghost btn-small" href="<?= $e($detailUrl) ?>"><?= $e(translate('common.view')) ?></a>
                <?php if ($canManage && $canWrite && ($state === null || $neuer)): ?>
                    <form method="post" action="<?= $e($url('/konto/plugins/finden')) ?>">
                        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="action" value="install">
                        <input type="hidden" name="slug" value="<?= $e($plugin['slug']) ?>">
                        <button class="btn btn-small" type="submit">
                            <?= $e($neuer ? translate('common.update') : translate('common.install')) ?>
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($plugin['summary'] !== ''): ?>
            <p style="margin:0;"><?= $e($plugin['summary']) ?></p>
        <?php endif; ?>

        <?php if ($plugin['tags'] !== []): ?>
            <div class="row" style="margin-top:10px;">
                <?php foreach ($plugin['tags'] as $one): ?>
                    <span class="badge"><?= $e($one) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
<?php endforeach; ?>
