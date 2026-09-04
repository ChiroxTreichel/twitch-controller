<?php
/**
 * Reiter "Installierte Plugins".
 *
 * @var \TwitchController\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var list<array<string, mixed>> $rows
 * @var list<string> $missing
 * @var bool $canManage
 * @var bool $canWrite   Ist plugins/ beschreibbar? Ohne das kein Loeschen
 * @var int $updates      Wie viele Plugins liessen sich aktualisieren
 * @var string $catalogError  Warum der Katalog nicht erreichbar war
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var bool $welcome
 * @var bool $compact   Knappe Liste? Vorliebe des angemeldeten Benutzers
 */
?>
<h1><?= $e(translate('nav.plugins')) ?></h1>
<p class="lead"><?= $e(translate('account.plugins.lead')) ?></p>

<?= $view->render('account/_plugin_tabs', ['tab' => $tab], null) ?>

<?php if (($catalogError ?? '') !== ''): ?>
    <?php /*
        Der Katalog war nicht erreichbar. Das ist kein Grund, die
        Verwaltung zu verweigern - nur Updates lassen sich dann nicht
        finden. Deshalb ein Hinweis und keine Fehlerseite.
    */ ?>
    <div class="note note-warn">
        <?= $e(translate('account.plugins.catalog_unreachable', ['reason' => $catalogError])) ?>
    </div>
<?php endif ?>

<?php if ($canManage && ($updates ?? 0) > 0): ?>
    <div class="card">
        <div class="card-head">
            <h2><?= $e(translate('account.plugins.updates_available', ['count' => $updates])) ?></h2>
            <?= $view->render('_confirm', [
                'label'    => translate('account.plugins.update_all'),
                'question' => translate('account.plugins.confirm_update_all', ['count' => $updates]),
                'confirm'  => translate('account.plugins.confirm_update_all_yes'),
                'action'   => $url('/account/plugins'),
                'fields'   => ['csrf' => $csrf, 'action' => 'update_all'],
                'danger'   => false,
                'right'    => true,
            ], null) ?>
        </div>
        <p class="hint"><?= $e(translate('account.plugins.update_all_hint')) ?></p>
    </div>
<?php endif ?>

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

<?php /*
    Knapp oder ausfuehrlich.

    Wer zehn Plugins installiert hat, scrollt in der ausfuehrlichen
    Ansicht an Beschreibungen und Abhaengigkeiten vorbei, um an den
    Schalter zu kommen - und genau dafuer kommt man hierher.

    Die Wahl liegt beim Benutzer und nicht beim Kanal: zwei Leute am
    selben Kanal duerfen das verschieden haben. Siehe
    Auth::setPreference().
*/ ?>
<form class="view-switch" method="post" action="<?= $e($url('/account/plugins')) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <input type="hidden" name="action" value="view_mode">
    <input type="hidden" name="mode" value="<?= ($compact ?? false) ? 'full' : 'compact' ?>">
    <button class="btn btn-ghost btn-small" type="submit">
        <?= $e(($compact ?? false) ? translate('account.plugins.view_full') : translate('account.plugins.view_compact')) ?>
    </button>
</form>

<?php if ($rows === []): ?>
    <div class="card">
        <div class="empty">
            <?= $e(translate('account.plugins.none')) ?><br>
            <a class="btn btn-small" style="margin-top:14px;"
               href="<?= $e($url('/account/plugins/find')) ?>"><?= $e(translate('account.plugins.tab_find')) ?></a>
        </div>
    </div>
<?php else: ?>
    <?php foreach ($rows as $row): ?>
        <?php $manifest = $row['manifest']; ?>

        <?php /*
            Der Zustandspunkt steht in beiden Ansichten gleich da -
            einmal geschrieben, damit "aktiv" nicht in der einen Ansicht
            anders heisst als in der anderen.
        */ ?>
        <?php ob_start(); ?>
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
        <?php $abzeichen = (string) ob_get_clean(); ?>

        <?php if ($compact ?? false): ?>
            <?php /*
                Knapp: Name, Zustand, Knoepfe. Sonst nichts.

                Was hier fehlt - Beschreibung, Fassung, Abhaengigkeiten -
                steht weiter in der ausfuehrlichen Ansicht. Es geht nicht
                weg, es steht nur nicht mehr zwischen einem und dem
                Schalter, den man sucht.
            */ ?>
            <div class="plugin-row">
                <div class="plugin-row-name">
                    <strong><?= $e($manifest->name) ?></strong>
                    <?= $abzeichen ?>
                </div>

                <?php if ($canManage): ?>
                    <?= $view->render('account/_plugin_actions', [
                        'row'      => $row,
                        'manifest' => $manifest,
                        'canWrite' => $canWrite,
                        'csrf'     => $csrf,
                    ], null) ?>
                <?php endif; ?>
            </div>

            <?php /*
                Ein Grund, der das Einschalten verhindert, MUSS auch
                knapp zu sehen sein - sonst steht dort ein Knopf, der
                nichts tut, und man sucht den Grund in der falschen
                Ansicht.
            */ ?>
            <?php if ($row['blockers'] !== []): ?>
                <div class="note note-warn plugin-row-note">
                    <?= $e(implode(' ', $row['blockers'])) ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="card">
                <div class="card-head">
                    <div>
                        <h2>
                            <?= $e($manifest->name) ?>
                            <?= $abzeichen ?>
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
                        <?= $view->render('account/_plugin_actions', [
                            'row'      => $row,
                            'manifest' => $manifest,
                            'canWrite' => $canWrite,
                            'csrf'     => $csrf,
                        ], null) ?>
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
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>
