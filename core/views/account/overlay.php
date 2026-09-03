<?php
/**
 * Einstellungen der Overlay-Fläche.
 *
 * @var callable $e
 * @var callable $url
 * @var int $width
 * @var int $height
 * @var bool $debug
 * @var string $sourceUrl
 * @var array<string, array{label: string, position: string, width: string, height: string, z: int}> $slots
 * @var list<string> $positions
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 * @var string $error
 */
?>
<h1><?= $e(translate('overlay.title')) ?></h1>
<p class="lead"><?= $e(translate('overlay.lead')) ?></p>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif ?>

<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('overlay.source_heading')) ?></h2>
    </div>

    <p><?= translate('overlay.source_hint', ['url' => '<span class="mono">' . $e($sourceUrl) . '</span>']) ?></p>

    <div class="note note-warn">
        <strong><?= $e(translate('overlay.login_needed')) ?></strong><br>
        <?= $e(translate('overlay.login_needed_hint')) ?>
    </div>

    <div class="row">
        <a class="btn btn-ghost btn-small" href="<?= $e($sourceUrl) ?>" target="_blank" rel="noopener">
            <?= $e(translate('overlay.open_source')) ?>
        </a>
    </div>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('overlay.canvas_heading')) ?></h2>
    </div>

    <p class="hint"><?= $e(translate('overlay.canvas_hint')) ?></p>

    <form method="post" action="<?= $e($url('/account/overlay')) ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <input type="hidden" name="action" value="canvas">

        <div class="row">
            <label>
                <span class="hint"><?= $e(translate('overlay.width')) ?></span><br>
                <input class="input" type="number" name="width" min="320" max="7680"
                       value="<?= (int) $width ?>" <?= $canManage ? '' : 'disabled' ?>>
            </label>
            <label>
                <span class="hint"><?= $e(translate('overlay.height')) ?></span><br>
                <input class="input" type="number" name="height" min="180" max="4320"
                       value="<?= (int) $height ?>" <?= $canManage ? '' : 'disabled' ?>>
            </label>
        </div>

        <label class="switch-field" style="margin-top:16px;">
            <input type="checkbox" name="debug" value="1"
                   <?= $debug ? 'checked' : '' ?> <?= $canManage ? '' : 'disabled' ?>>
            <span class="switch-track"><span class="switch-knob"></span></span>
            <span>
                <?= $e(translate('overlay.debug')) ?><br>
                <span class="hint"><?= $e(translate('overlay.debug_hint')) ?></span>
            </span>
        </label>

        <?php if ($canManage): ?>
            <div class="row" style="margin-top:14px;">
                <button class="btn btn-small" type="submit"><?= $e(translate('common.save')) ?></button>
            </div>
        <?php endif ?>
    </form>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('overlay.slots_heading')) ?></h2>
    </div>

    <p class="hint"><?= $e(translate('overlay.slots_hint')) ?></p>

    <table>
        <thead>
            <tr>
                <th><?= $e(translate('overlay.slot')) ?></th>
                <th><?= $e(translate('overlay.position')) ?></th>
                <th><?= $e(translate('overlay.size')) ?></th>
                <?php if ($canManage): ?>
                    <th></th>
                <?php endif ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($slots as $id => $slot): ?>
                <tr>
                    <td>
                        <?= $e($slot['label']) ?><br>
                        <span class="hint mono"><?= $e($id) ?></span>
                    </td>
                    <td class="mono"><?= $e($slot['position']) ?></td>
                    <td class="mono">
                        <?php $groesse = trim($slot['width'] . ' × ' . $slot['height'], ' ×'); ?>
                        <?= $groesse === '' ? '<span class="hint">' . $e(translate('overlay.size_auto')) . '</span>' : $e($groesse) ?>
                    </td>
                    <?php if ($canManage): ?>
                        <td>
                            <form method="post" action="<?= $e($url('/account/overlay')) ?>">
                                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                <input type="hidden" name="action" value="test">
                                <input type="hidden" name="slot" value="<?= $e($id) ?>">
                                <button class="btn btn-ghost btn-small" type="submit">
                                    <?= $e(translate('overlay.send_test')) ?>
                                </button>
                            </form>
                        </td>
                    <?php endif ?>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>

    <p class="hint" style="margin-top:12px;"><?= $e(translate('overlay.test_hint')) ?></p>
</div>
