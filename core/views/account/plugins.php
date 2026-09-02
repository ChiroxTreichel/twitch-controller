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
<h1>Plugins</h1>
<p class="lead">Funktionserweiterungen: Overlay, Alerts, Ziele, Spenden und was noch dazukommt.</p>

<?= $view->render('account/_plugin_tabs', ['tab' => $tab], null) ?>

<?php if ($welcome): ?>
    <div class="note note-ok">
        <strong>Einrichtung abgeschlossen.</strong>
        Der Kern läuft. Alles Weitere kommt als Plugin dazu &mdash; unter
        <em>Plugins finden</em> ist der Katalog.
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
        Diese Plugins sind registriert, ihre Dateien fehlen aber:
        <span class="mono"><?= $e(implode(', ', $missing)) ?></span>.
        Entweder die Dateien zurücklegen oder das Plugin entfernen.
    </div>
<?php endif; ?>

<?php if ($rows === []): ?>
    <div class="card">
        <div class="empty">
            Noch keine Plugins installiert.<br>
            <a class="btn btn-small" style="margin-top:14px;"
               href="<?= $e($url('/konto/plugins/finden')) ?>">Plugins finden</a>
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
                            <span class="badge badge-ok">aktiv</span>
                        <?php elseif ($row['installed']): ?>
                            <span class="badge badge-off">installiert, aus</span>
                        <?php else: ?>
                            <span class="badge">liegt bereit</span>
                        <?php endif; ?>
                        <?php if ($row['catalog'] !== null): ?>
                            <span class="badge badge-warn">Version <?= $e($row['catalog']) ?> verfügbar</span>
                        <?php elseif ($row['updatable']): ?>
                            <span class="badge badge-warn">Update bereit</span>
                        <?php endif; ?>
                    </h2>
                    <div class="hint">
                        Version <?= $e($manifest->version) ?>
                        <?php if ($row['installed'] && $row['version'] !== $manifest->version): ?>
                            (eingerichtet: <?= $e((string) $row['version']) ?>)
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
                                <button class="btn btn-small" type="submit">Auf
                                    <?= $e($row['catalog']) ?> aktualisieren</button>
                            </form>
                        <?php elseif ($row['updatable']): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-small" type="submit">Aktualisieren</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$row['installed'] || !$row['enabled']): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="enable">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-small" type="submit"
                                    <?= $row['blockers'] !== [] ? 'disabled' : '' ?>>
                                    <?= $row['installed'] ? 'Einschalten' : 'Installieren' ?>
                                </button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="disable">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-ghost btn-small" type="submit">Ausschalten</button>
                            </form>
                        <?php endif; ?>

                        <?php if ($row['installed'] && !$row['enabled']): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>"
                                  onsubmit="return confirm('Plugin entfernen? Seine Daten werden gelöscht.');">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="uninstall">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-danger btn-small" type="submit">Entfernen</button>
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
                        <div>Braucht: <?= $e(implode(', ', array_keys($manifest->requiredPlugins()))) ?></div>
                    <?php endif; ?>
                    <?php if ($manifest->optionalPlugins() !== []): ?>
                        <div>Kann mehr mit: <?= $e(implode(', ', array_keys($manifest->optionalPlugins()))) ?></div>
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
                    Wird gebraucht von: <?= $e(implode(', ', $row['dependents'])) ?>
                    &mdash; deshalb erst dort ausschalten.
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
