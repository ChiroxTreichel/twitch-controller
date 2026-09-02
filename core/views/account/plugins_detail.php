<?php
/**
 * Detailseite eines Katalog-Plugins. Wird bei uns gerendert, nicht als
 * fremde Seite eingebettet - der Katalogserver liefert nur Daten.
 *
 * @var \Overlays\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var array<string, mixed> $plugin
 * @var array{installed: bool, enabled: bool, version: ?string}|null $state
 * @var bool $canManage
 * @var bool $canWrite
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var bool $coreOk
 */

$neuer = $state !== null
    && version_compare((string) $state['version'], (string) $plugin['version'], '<');
?>
<h1><?= $e($plugin['name']) ?></h1>
<p class="lead"><?= $e($plugin['summary']) ?></p>

<?= $view->render('account/_plugin_tabs', ['tab' => $tab], null) ?>

<p style="margin:-6px 0 18px;">
    <a class="hint" href="<?= $e($url('/konto/plugins/finden')) ?>">&larr; Zurück zum Katalog</a>
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
                    Version <?= $e($plugin['version']) ?>
                    <?php if ($state === null): ?>
                        <span class="badge">nicht installiert</span>
                    <?php elseif ($neuer): ?>
                        <span class="badge badge-warn">installiert: <?= $e((string) $state['version']) ?></span>
                    <?php elseif ($state['enabled']): ?>
                        <span class="badge badge-ok">aktiv</span>
                    <?php else: ?>
                        <span class="badge badge-off">installiert, aus</span>
                    <?php endif; ?>
                </div>
                <div class="hint">
                    <?php if ($plugin['author'] !== ''): ?>
                        von <?= $e($plugin['author']) ?>
                    <?php endif; ?>
                    <?php if ($plugin['updated_at'] !== ''): ?>
                        &middot; aktualisiert <?= $e(date('d.m.Y', strtotime($plugin['updated_at']) ?: time())) ?>
                    <?php endif; ?>
                    <?php if ($plugin['size'] > 0): ?>
                        &middot; <?= $e((string) (int) round($plugin['size'] / 1024)) ?> KB
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="row">
            <?php if (!$coreOk): ?>
                <span class="badge badge-error">braucht neueren Kern</span>
            <?php elseif ($canManage && $canWrite && ($state === null || $neuer)): ?>
                <form method="post" action="<?= $e($url('/konto/plugins/finden')) ?>">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="install">
                    <input type="hidden" name="slug" value="<?= $e($plugin['slug']) ?>">
                    <button class="btn" type="submit">
                        <?= $neuer ? 'Aktualisieren' : 'Installieren' ?>
                    </button>
                </form>
            <?php elseif ($state !== null): ?>
                <a class="btn btn-ghost btn-small" href="<?= $e($url('/konto/plugins')) ?>">Verwalten</a>
            <?php endif; ?>
        </div>
    </div>

    <?php if (!$coreOk): ?>
        <div class="note note-warn" style="margin:0 0 14px;">
            Dieses Plugin verlangt eine neuere Kernversion
            (<span class="mono"><?= $e((string) ($plugin['requires']['core'] ?? '?')) ?></span>).
            Erst das System aktualisieren: <em>Konto &rarr; Einstellungen &rarr; System</em>.
        </div>
    <?php endif; ?>

    <?php if ($plugin['description'] !== ''): ?>
        <div class="prose">
            <?= \Overlays\Core\Support\Markdown::render((string) $plugin['description']) ?>
        </div>
    <?php else: ?>
        <p class="hint">Keine ausführliche Beschreibung hinterlegt.</p>
    <?php endif; ?>
</div>

<?php if ($plugin['screenshots'] !== []): ?>
    <div class="card">
        <h2>Bilder</h2>
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
    <h2>Angaben</h2>
    <table>
        <tbody>
        <tr>
            <td>Kennung</td>
            <td class="actions mono"><?= $e($plugin['slug']) ?></td>
        </tr>
        <?php if ($plugin['requires'] !== []): ?>
            <tr>
                <td>Setzt voraus</td>
                <td class="actions hint">
                    <?php foreach ($plugin['requires'] as $name => $constraint): ?>
                        <div>
                            <?= $e($name === 'core' ? 'Kern' : (string) $name) ?>
                            <span class="mono"><?= $e((string) $constraint) ?></span>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php if ($plugin['optional'] !== []): ?>
            <tr>
                <td>Kann mehr mit</td>
                <td class="actions hint">
                    <?php foreach ($plugin['optional'] as $name => $constraint): ?>
                        <div>
                            <?= $e($name === 'core' ? 'Kern' : (string) $name) ?>
                            <span class="mono"><?= $e((string) $constraint) ?></span>
                        </div>
                    <?php endforeach; ?>
                </td>
            </tr>
        <?php endif; ?>
        <?php if ($plugin['homepage'] !== ''): ?>
            <tr>
                <td>Mehr dazu</td>
                <td class="actions">
                    <a href="<?= $e($plugin['homepage']) ?>" target="_blank"
                       rel="noreferrer noopener"><?= $e($plugin['homepage']) ?></a>
                </td>
            </tr>
        <?php endif; ?>
        <tr>
            <td>Prüfsumme</td>
            <td class="actions hint mono" style="word-break:break-all;">
                <?= $e($plugin['sha256'] !== '' ? $plugin['sha256'] : 'fehlt – wird nicht installiert') ?>
            </td>
        </tr>
        </tbody>
    </table>
</div>
