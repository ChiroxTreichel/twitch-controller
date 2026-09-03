<?php
/**
 * Vorlage eines Plugins. Liegt im Plugin, benutzt aber das Layout des
 * Kerns - erreicht wird sie mit:
 *
 *   $app->view->from($plugin->directory . '/views')->render('seite', [...])
 *
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 * @var string $gruss
 * @var int $zaehler
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 */
?>
<link rel="stylesheet" href="<?= $e($asset('/plugin/beispiel/assets/beispiel.css')) ?>">

<h1><?= $e(translate('example.name')) ?></h1>
<p class="lead"><?= $e(translate('example.lead')) ?></p>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>

<div class="card">
    <h2><?= $e(translate('example.own_setting')) ?></h2>
    <p class="hint">
        <?= translate('example.scope_hint', ['scope' => '<span class="mono">plugin:beispiel</span>']) ?>
    </p>

    <?php if ($canManage): ?>
        <form method="post" action="<?= $e($url('/beispiel')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <div class="field">
                <label for="gruss"><?= $e(translate('example.greeting')) ?></label>
                <input class="input" id="gruss" name="gruss" value="<?= $e($gruss) ?>">
            </div>
            <button class="btn" type="submit"><?= $e(translate('common.save')) ?></button>
        </form>
    <?php else: ?>
        <p><?= $e($gruss) ?></p>
        <p class="hint"><?= translate('common.missing_permission', ['permission' => '<span class="mono">Beispiel.Seite.Manage</span>']) ?></p>
    <?php endif; ?>
</div>

<div class="card">
    <h2><?= $e(translate('example.events_title')) ?></h2>
    <p class="beispiel-hinweis">
        Dieser Absatz ist über eine eigene CSS-Datei des Plugins gestaltet
        (<span class="mono">assets/beispiel.css</span>).
    </p>
    <p>
        Follows gezählt seit Installation: <strong><?= $e((string) $zaehler) ?></strong>
    </p>
    <p class="hint">
        Gezählt wird im Hook <span class="mono">core.event.stored</span> &mdash; also in dem Moment,
        in dem Twitch das Event schickt.
    </p>
</div>
