<?php
/**
 * Detailseite eines Katalog-Plugins. Wird bei uns gerendert, nicht als
 * fremde Seite eingebettet - der Katalogserver liefert nur Daten.
 *
 * @var \TwitchController\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var string $readme     README des Plugins, schon als HTML
 * @var string $readmeErr  Grund, falls sie nicht geholt werden konnte
 * @var list<array{slug: string, name: string, state: string}> $needs
 * @var array<string, mixed> $plugin
 * @var array{installed: bool, enabled: bool, version: ?string}|null $state
 * @var bool $canManage
 * @var bool $canWrite
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var bool $coreOk
 */

use TwitchController\Core\Support\Dates;

$neuer = $state !== null
    && version_compare((string) $state['version'], (string) $plugin['version'], '<');
?>
<h1><?= $e($plugin['name']) ?></h1>
<p class="lead"><?= $e($plugin['summary']) ?></p>

<?= $view->render('account/_plugin_tabs', ['tab' => $tab], null) ?>

<p style="margin:-6px 0 18px;">
    <a class="hint" href="<?= $e($url('/account/plugins/find')) ?>">&larr; <?= $e(translate('market.back')) ?></a>
</p>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <div class="row" style="gap:14px;align-items:flex-start;">
            <?php if ($plugin['icon'] !== ''): ?>
                <img src="<?= $e($plugin['icon']) ?>" alt="" width="56" height="56"
                     style="border-radius:12px;background:var(--panel-2);" loading="lazy">
            <?php endif; ?>
            <div>
                <div style="font-size:1.05rem;font-weight:600;">
                    <?= $e(translate('common.version', ['version' => $plugin['version']])) ?>
                    <?php if ($state === null): ?>
                        <span class="badge"><?= $e(translate('common.not_installed')) ?></span>
                    <?php elseif ($neuer): ?>
                        <span class="badge badge-warn"><?= $e(translate('market.installed_version', ['version' => (string) $state['version']])) ?></span>
                    <?php elseif ($state['enabled']): ?>
                        <span class="badge badge-ok"><?= $e(translate('common.active')) ?></span>
                    <?php else: ?>
                        <span class="badge badge-off"><?= $e(translate('common.installed_off')) ?></span>
                    <?php endif; ?>
                </div>
                <div class="hint">
                    <?php if ($plugin['author'] !== ''): ?>
                        <?= $e(translate('market.by', ['author' => $plugin['author']])) ?>
                    <?php endif; ?>
                    <?php if ($plugin['updated_at'] !== ''): ?>
                        &middot; <?= $e(translate('market.updated', ['date' => Dates::day($plugin['updated_at'])])) ?>
                    <?php endif; ?>
                    <?php if ($plugin['size'] > 0): ?>
                        &middot; <?= $e(translate('market.size_kb', ['size' => (int) round($plugin['size'] / 1024)])) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row">
            <?php if (!$coreOk): ?>
                <span class="badge badge-error"><?= $e(translate('market.needs_newer_core')) ?></span>
            <?php elseif ($canManage && $canWrite && ($state === null || $neuer)): ?>
                <?php
                // Was noch dazukommt. Steht als Rueckfrage am Knopf,
                // damit niemand ungefragt zwei Plugins installiert.
                $mit = [];
                foreach ($needs ?? [] as $braucht) {
                    if ($braucht['state'] === 'will_install') {
                        $mit[] = $braucht['name'];
                    }
                }
                ?>
                <?php if ($mit !== [] && !$neuer): ?>
                    <?php /* Kommt etwas mit, wird vorher gefragt. */ ?>
                    <?= $view->render('_confirm', [
                        'label'    => translate('common.install'),
                        'question' => translate('market.confirm_with_deps', ['plugins' => implode(', ', $mit)]),
                        'confirm'  => translate('market.confirm_with_deps_yes'),
                        'action'   => $url('/account/plugins/find'),
                        'fields'   => [
                            'csrf'   => $csrf,
                            'action' => 'install',
                            'slug'   => $plugin['slug'],
                        ],
                        'danger'   => false,
                        'small'    => false,
                        'right'    => true,
                    ], null) ?>
                <?php else: ?>
                    <form method="post" action="<?= $e($url('/account/plugins/find')) ?>">
                        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="action" value="install">
                        <input type="hidden" name="slug" value="<?= $e($plugin['slug']) ?>">
                        <button class="btn" type="submit">
                            <?= $e($neuer ? translate('common.update') : translate('common.install')) ?>
                        </button>
                    </form>
                <?php endif ?>
            <?php elseif ($state !== null): ?>
                <a class="btn btn-ghost btn-small" href="<?= $e($url('/account/plugins')) ?>"><?= $e(translate('common.manage')) ?></a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$coreOk): ?>
        <div class="note note-warn" style="margin:0 0 14px;">
            <?php // Ohne $e: die Platzhalter sind eigenes Markup. ?>
            <?= translate('market.needs_newer_core_hint', [
                'version' => '<span class="mono">'
                    . $e((string) ($plugin['requires']['core'] ?? '?')) . '</span>',
                'path'    => '<em>' . $e(translate('market.settings_system_path')) . '</em>',
            ]) ?>
        </div>
    <?php endif; ?>

    <?php if (($needs ?? []) !== []): ?>
        <?php
        $fehlt = false;
        foreach ($needs as $braucht) {
            if ($braucht['state'] === 'unknown') {
                $fehlt = true;
            }
        }
        ?>
        <div class="note <?= $fehlt ? 'note-error' : 'note-warn' ?>">
            <strong><?= $e(translate('market.needs_heading')) ?></strong>
            <?php foreach ($needs as $braucht): ?>
                <div>
                    <strong><?= $e($braucht['name']) ?></strong> &middot;
                    <?php if ($braucht['state'] === 'installed'): ?>
                        <?= $e(translate('market.needs_installed')) ?>
                    <?php elseif ($braucht['state'] === 'will_install'): ?>
                        <?= $e(translate('market.needs_will_install')) ?>
                    <?php else: ?>
                        <?= $e(translate('market.needs_unknown')) ?>
                    <?php endif ?>
                </div>
            <?php endforeach ?>
        </div>
    <?php endif ?>

    <?php if (($readmeErr ?? '') !== ''): ?>
        <p class="hint"><?= $e(translate('market.readme.failed', ['reason' => $readmeErr])) ?></p>
    <?php endif; ?>

    <?php if (($readme ?? '') !== ''): ?>
        <?php /* Die README des Plugins - der Langtext. */ ?>
        <div class="prose"><?= $readme ?></div>
    <?php elseif ($plugin['description'] !== ''): ?>
        <?php /* Keine README im Katalog: dann die Kurzbeschreibung. */ ?>
        <div class="prose">
            <?= \TwitchController\Core\Support\Markdown::render((string) $plugin['description']) ?>
        </div>
    <?php else: ?>
        <p class="hint"><?= $e(translate('market.no_description')) ?></p>
    <?php endif; ?>
</div>

<?php if ($plugin['screenshots'] !== []): ?>
    <div class="card">
        <h2><?= $e(translate('market.screenshots')) ?></h2>
        <div class="shots">
            <?php foreach ($plugin['screenshots'] as $shot): ?>
                <a href="<?= $e($shot) ?>" target="_blank" rel="noreferrer noopener">
                    <img src="<?= $e($shot) ?>" alt="" loading="lazy">
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<div class="card">
    <h2><?= $e(translate('market.details')) ?></h2>
    <table>
        <tbody>
        <tr>
            <td><?= $e(translate('market.slug')) ?></td>
            <td class="actions mono"><?= $e($plugin['slug']) ?></td>
        </tr>
        <?php if ($plugin['requires'] !== []): ?>
            <tr>
                <td><?= $e(translate('market.requires')) ?></td>
                <td class="actions hint">
                    <?php foreach ($plugin['requires'] as $name => $constraint): ?>
                        <div>
                            <?= $e($name === 'core' ? translate('common.core') : (string) $name) ?>
                            <span class="mono"><?= $e((string) $constraint) ?></span>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php if ($plugin['optional'] !== []): ?>
            <tr>
                <td><?= $e(translate('market.optional')) ?></td>
                <td class="actions hint">
                    <?php foreach ($plugin['optional'] as $name => $constraint): ?>
                        <div>
                            <?= $e($name === 'core' ? translate('common.core') : (string) $name) ?>
                            <span class="mono"><?= $e((string) $constraint) ?></span>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php if ($plugin['homepage'] !== ''): ?>
            <tr>
                <td><?= $e(translate('market.homepage')) ?></td>
                <td class="actions">
                    <a href="<?= $e($plugin['homepage']) ?>" target="_blank"
                       rel="noreferrer noopener"><?= $e($plugin['homepage']) ?></a>
                </td>
            </tr>
        <?php endif; ?>
        <tr>
            <td><?= $e(translate('market.checksum')) ?></td>
            <td class="actions hint mono" style="word-break:break-all;">
                <?= $e($plugin['sha256'] !== '' ? $plugin['sha256'] : translate('market.checksum_missing')) ?>
            </td>
        </tr>
        </tbody>
    </table>
</div>
