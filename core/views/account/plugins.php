<?php
/**
 * @var callable $e
 * @var callable $url
 * @var list<array{manifest: \Overlays\Core\Plugin\Manifest, installed: bool, enabled: bool, version: ?string, updatable: bool, blockers: list<string>, dependents: list<string>}> $rows
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

<?php if ($welcome): ?>
    <div class="note note-ok">
        <strong>Einrichtung abgeschlossen.</strong>
        Der Kern läuft. Alles Weitere kommt als Plugin dazu &mdash; hier ist die Stelle dafür.
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
            Noch keine Plugins vorhanden.<br>
            <span class="hint">
                Plugins liegen je in einem Ordner unter <span class="mono">plugins/</span>
                mit <span class="mono">plugin.json</span> und <span class="mono">plugin.php</span>.
            </span>
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
                            <span class="badge badge-off">installiert</span>
                        <?php else: ?>
                            <span class="badge">verfügbar</span>
                        <?php endif; ?>
                        <?php if ($row['updatable']): ?>
                            <span class="badge badge-warn">Update</span>
                        <?php endif; ?>
                    </h2>
                    <div class="hint">
                        Version <?= $e($manifest->version) ?>
                        <?php if ($row['installed'] && $row['version'] !== $manifest->version): ?>
                            (installiert: <?= $e((string) $row['version']) ?>)
                        <?php endif; ?>
                        <?php if ($manifest->author !== ''): ?>
                            &middot; <?= $e($manifest->author) ?>
                        <?php endif; ?>
                        &middot; <span class="mono"><?= $e($manifest->slug) ?></span>
                    </div>
                </div>

                <?php if ($canManage): ?>
                    <div class="row">
                        <?php if ($row['updatable']): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-small" type="submit">Aktualisieren</button>
                            </form>
                        <?php endif; ?>

                        <?php if (!$row['installed']): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="enable">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-small" type="submit"
                                    <?= $row['blockers'] !== [] ? 'disabled' : '' ?>>Installieren</button>
                            </form>
                        <?php elseif ($row['enabled']): ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="disable">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-ghost btn-small" type="submit">Deaktivieren</button>
                            </form>
                        <?php else: ?>
                            <form method="post" action="<?= $e($url('/konto/plugins')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="enable">
                                <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
                                <button class="btn btn-small" type="submit"
                                    <?= $row['blockers'] !== [] ? 'disabled' : '' ?>>Aktivieren</button>
                            </form>
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
                        <div>Braucht: <span class="mono"><?= $e(implode(', ', array_keys($manifest->requiredPlugins()))) ?></span></div>
                    <?php endif; ?>
                    <?php if ($manifest->optionalPlugins() !== []): ?>
                        <div>Kann mehr mit: <span class="mono"><?= $e(implode(', ', array_keys($manifest->optionalPlugins()))) ?></span></div>
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
                    Wird gebraucht von: <span class="mono"><?= $e(implode(', ', $row['dependents'])) ?></span>
                    &mdash; deshalb erst dort deaktivieren.
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
