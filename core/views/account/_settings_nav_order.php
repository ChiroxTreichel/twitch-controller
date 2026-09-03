<?php
/**
 * Reihenfolge der Menübereiche.
 *
 * Auf und ab statt Ziehen: das geht ohne JavaScript, ist mit der
 * Tastatur bedienbar und auf einem Telefon zuverlässiger als eine
 * Ziehfläche.
 *
 * Aufgeführt werden nur Bereiche, die es gerade gibt. Ein Plugin, das
 * einen neuen mitbringt, erscheint hier von allein; wird es entfernt,
 * verschwindet er - die gespeicherte Reihenfolge behält seinen Platz
 * aber, damit eine Neuinstallation dort wieder landet.
 *
 * @var callable $e
 * @var callable $url
 * @var list<array{key: string, label: string}> $navGroups
 * @var bool $canManage
 * @var string $csrf
 */
?>
<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('settings.nav_order.title')) ?></h2>
    </div>
    <p class="hint"><?= $e(translate('settings.nav_order.hint')) ?></p>

    <?php if (count($navGroups) < 2): ?>
        <div class="empty"><?= $e(translate('settings.nav_order.too_few')) ?></div>
    <?php else: ?>
        <table>
            <tbody>
            <?php foreach ($navGroups as $i => $group): ?>
                <tr>
                    <td><strong><?= $e($group['label']) ?></strong></td>
                    <td class="hint mono"><?= $e($group['key']) ?></td>
                    <td class="actions">
                        <?php if ($canManage): ?>
                            <div class="row">
                                <form method="post" action="<?= $e($url('/account/settings')) ?>">
                                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="action" value="nav_order">
                                    <input type="hidden" name="group" value="<?= $e($group['key']) ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button class="btn btn-ghost btn-small" type="submit"
                                            title="<?= $e(translate('settings.nav_order.up')) ?>"
                                        <?= $i === 0 ? 'disabled' : '' ?>>&uarr;</button>
                                </form>
                                <form method="post" action="<?= $e($url('/account/settings')) ?>">
                                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                                    <input type="hidden" name="action" value="nav_order">
                                    <input type="hidden" name="group" value="<?= $e($group['key']) ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button class="btn btn-ghost btn-small" type="submit"
                                            title="<?= $e(translate('settings.nav_order.down')) ?>"
                                        <?= $i === count($navGroups) - 1 ? 'disabled' : '' ?>>&darr;</button>
                                </form>
                            </div>
                        <?php endif ?>
                    </td>
                </tr>
            <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</div>
