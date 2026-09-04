<?php
/**
 * Die Knopfreihe eines Plugins.
 *
 * Steht als eigene Datei da, weil sie in zwei Ansichten vorkommt - der
 * ausfuehrlichen und der knappen. Zweimal geschrieben liefen die beiden
 * mit der Zeit auseinander: ein neuer Knopf hier ergaenzt, dort
 * vergessen, und wer knapp eingestellt hat, kaeme an ihn nicht heran.
 *
 * @var \TwitchController\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var array<string, mixed> $row
 * @var \TwitchController\Core\Plugin\Manifest $manifest
 * @var bool $canWrite   Ist plugins/ beschreibbar? Ohne das kein Loeschen
 * @var string $csrf
 */
?>
<div class="row">
    <?php if ($row['enabled'] && $row['settings'] !== null): ?>
        <a class="btn btn-ghost btn-small"
           href="<?= $e($url($row['settings']['href'])) ?>">
            <?= $e($row['settings']['label']) ?>
        </a>
    <?php endif; ?>

    <?php if ($row['catalog'] !== null): ?>
        <form method="post" action="<?= $e($url('/account/plugins')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="download_update">
            <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
            <button class="btn btn-small" type="submit">
                <?= $e(translate('account.plugins.update_to', ['version' => $row['catalog']])) ?>
            </button>
        </form>
    <?php elseif ($row['updatable']): ?>
        <form method="post" action="<?= $e($url('/account/plugins')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
            <button class="btn btn-small" type="submit"><?= $e(translate('common.update')) ?></button>
        </form>
    <?php endif; ?>

    <?php if (!$row['installed'] || !$row['enabled']): ?>
        <form method="post" action="<?= $e($url('/account/plugins')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="enable">
            <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
            <button class="btn btn-small" type="submit"
                <?= $row['blockers'] !== [] ? 'disabled' : '' ?>>
                <?= $e($row['installed'] ? translate('common.enable') : translate('common.install')) ?>
            </button>
        </form>
    <?php else: ?>
        <form method="post" action="<?= $e($url('/account/plugins')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="disable">
            <input type="hidden" name="slug" value="<?= $e($manifest->slug) ?>">
            <button class="btn btn-ghost btn-small" type="submit"><?= $e(translate('common.disable')) ?></button>
        </form>
    <?php endif; ?>

    <?php /*
        EIN Knopf: Daten abraeumen und Dateien loeschen. Vorher waren es
        zwei - erst "Entfernen" fuer die Daten, dann "Dateien loeschen" -
        und das war zweimal Klicken fuer eine Absicht.

        Auch bei einem aktiven Plugin: uninstall() schaltet es zuerst
        aus. Sonst waeren es wieder zwei Klicks.
    */ ?>
    <?php if ($canWrite): ?>
        <?= $view->render('_confirm', [
            'label'    => translate('common.remove'),
            'question' => translate('account.plugins.confirm_remove', ['name' => $manifest->name]),
            'confirm'  => translate('account.plugins.confirm_remove_yes'),
            'action'   => $url('/account/plugins'),
            'fields'   => [
                'csrf'   => $csrf,
                'action' => 'remove',
                'slug'   => $manifest->slug,
            ],
        ], null) ?>
    <?php endif; ?>
</div>
