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
 * @var array{main: string, background: string} $sourceUrls
 * @var array<string, array{label: string, position: string, width: string, height: string, layer: string, z: int}> $slots
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

    <?php /*
        Zwei Flaechen, zwei Quellen: Main liegt in OBS ueber dem Avatar,
        Background darunter. Beide Adressen zum Herauskopieren.
    */ ?>
    <table>
        <tbody>
            <?php foreach ($sourceUrls as $ebene => $adresse): ?>
                <tr>
                    <td><strong><?= $e(translate('overlay.layer_' . $ebene)) ?></strong><br>
                        <span class="hint"><?= $e(translate('overlay.layer_' . $ebene . '_hint')) ?></span></td>
                    <td><span class="mono" style="word-break:break-all;"><?= $e($adresse) ?></span></td>
                    <td>
                        <a class="btn btn-ghost btn-small" href="<?= $e($adresse) ?>" target="_blank" rel="noopener">
                            <?= $e(translate('overlay.open_source')) ?>
                        </a>
                    </td>
                </tr>
            <?php endforeach ?>
        </tbody>
    </table>

    <div class="note note-warn">
        <strong><?= $e(translate('overlay.login_needed')) ?></strong><br>
        <?= $e(translate('overlay.login_needed_hint')) ?>
    </div>

    <?php /*
        Eine Quelle je Platz ist der eigentliche Grund fuer "?view=":
        die Ziele sollen unten links liegen und in jeder Szene
        sichtbar sein, die Alerts oben und nur in einer. Mit einer
        Flaeche, die alles bringt, geht das nicht.
    */ ?>
    <p class="hint"><?= translate('overlay.view_hint', [
        'param' => '<span class="mono">?view=' . $e(array_key_first($slots) ?? 'alerts') . '</span>',
    ]) ?></p>

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

    <?php
    /*
     * Je Flaeche eine Tabelle, von vorne nach hinten. Die erste Zeile
     * liegt oben - wie die oberste Quelle in einer OBS-Szene, und das
     * ist die Reihenfolge, die hier jeder schon im Kopf hat. Die Pfeile
     * tauschen nur innerhalb einer Flaeche (Bus::move).
     */
    ?>

    <?php foreach (\TwitchController\Core\Overlay\Bus::LAYERS as $ebene): ?>
        <?php
        $gruppe = array_filter($slots, static fn (array $slot): bool => $slot['layer'] === $ebene);
        $letzte = count($gruppe) - 1;
        $stelle = 0;
        ?>

        <h3 style="margin-top:18px;"><?= $e(translate('overlay.layer_' . $ebene)) ?></h3>

        <?php if ($gruppe === []): ?>
            <p class="hint"><?= $e(translate('overlay.layer_empty')) ?></p>
            <?php continue ?>
        <?php endif ?>

        <table>
            <thead>
                <tr>
                    <th><?= $e(translate('overlay.slot')) ?></th>
                    <th><?= $e(translate('overlay.layer')) ?></th>
                    <?php if ($canManage): ?>
                        <th><?= $e(translate('overlay.order')) ?></th>
                    <?php endif ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($gruppe as $id => $slot): ?>
                    <tr>
                        <td>
                            <?= $e($slot['label']) ?><br>
                            <span class="hint mono"><?= $e($id) ?></span><br>
                            <?php /*
                                Die Adresse fuer eine eigene Quelle - zum
                                Herauskopieren. Sie steht hier und nicht
                                nur in einem Hinweis oben: wer sie tippen
                                muss, vertippt sich beim Namen des Platzes.
                            */ ?>
                            <span class="hint mono" style="word-break:break-all;"><?=
                                $e($sourceUrls[$ebene] . '?view=' . $id)
                            ?></span>
                        </td>
                        <td>
                            <?php if ($canManage): ?>
                                <?php /*
                                    Ohne Knopf: die Auswahl schickt sich
                                    selbst ab. Ohne JavaScript bleibt sie
                                    stehen - dafuer der Knopf in noscript.
                                */ ?>
                                <form method="post" action="<?= $e($url('/account/overlay')) ?>">
                                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="action" value="layer">
                                    <input type="hidden" name="slot" value="<?= $e($id) ?>">
                                    <select class="input" name="layer" onchange="this.form.submit()">
                                        <?php foreach (\TwitchController\Core\Overlay\Bus::LAYERS as $wahl): ?>
                                            <option value="<?= $e($wahl) ?>" <?= $wahl === $ebene ? 'selected' : '' ?>>
                                                <?= $e(translate('overlay.layer_' . $wahl)) ?>
                                            </option>
                                        <?php endforeach ?>
                                    </select>
                                    <noscript><button class="btn btn-ghost btn-small" type="submit"><?= $e(translate('common.save')) ?></button></noscript>
                                </form>
                            <?php else: ?>
                                <?= $e(translate('overlay.layer_' . $ebene)) ?>
                            <?php endif ?>
                        </td>
                        <?php if ($canManage): ?>
                            <td>
                                <div class="row" style="gap:6px;">
                                    <?php /*
                                        Am Rand steht der Pfeil gar nicht
                                        erst: ein Knopf, der nichts tut,
                                        laesst einen zweimal druecken und
                                        dann nachsehen, ob man sich geirrt
                                        hat.
                                    */ ?>
                                    <?php if ($stelle > 0): ?>
                                        <form method="post" action="<?= $e($url('/account/overlay')) ?>">
                                            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="slot" value="<?= $e($id) ?>">
                                            <input type="hidden" name="dir" value="up">
                                            <button class="btn btn-ghost btn-small" type="submit"
                                                    title="<?= $e(translate('overlay.move_up')) ?>">▲</button>
                                        </form>
                                    <?php endif ?>

                                    <?php if ($stelle < $letzte): ?>
                                        <form method="post" action="<?= $e($url('/account/overlay')) ?>">
                                            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                            <input type="hidden" name="action" value="move">
                                            <input type="hidden" name="slot" value="<?= $e($id) ?>">
                                            <input type="hidden" name="dir" value="down">
                                            <button class="btn btn-ghost btn-small" type="submit"
                                                    title="<?= $e(translate('overlay.move_down')) ?>">▼</button>
                                        </form>
                                    <?php endif ?>
                                </div>
                            </td>
                        <?php endif ?>
                    </tr>
                    <?php $stelle++ ?>
                <?php endforeach ?>
            </tbody>
        </table>
    <?php endforeach ?>

    <p class="hint" style="margin-top:12px;"><?= $e(translate('overlay.order_hint')) ?></p>
</div>
