<?php
/**
 * Reihenfolge der Menübereiche - und der Punkte darin.
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
 * Die Punkte stehen eingerückt unter ihrem Bereich, aber nur, wo es
 * mindestens zwei gibt: ein einzelner Punkt mit zwei toten Pfeilen
 * wäre nur Lärm.
 *
 * Konto fehlt hier: es steht immer unten, mit fester Reihenfolge -
 * siehe Nav::ACCOUNT.
 *
 * @var callable $e
 * @var callable $url
 * @var list<array{key: string, label: string, items: list<array{key: string, label: string}>}> $navGroups
 * @var bool $canManage
 * @var string $csrf
 */

$sortierbar = count($navGroups) > 1;
foreach ($navGroups as $group) {
    $sortierbar = $sortierbar || count($group['items']) > 1;
}

/**
 * Ein Pfeil als eigenes kleines Formular.
 *
 * @param array<string, string> $felder was außer csrf mitgeht
 */
$pfeil = static function (array $felder, string $richtung, bool $aus) use ($e, $url, $csrf): void {
    ?>
    <form method="post" action="<?= $e($url('/account/settings')) ?>">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <?php foreach ($felder as $name => $wert): ?>
            <input type="hidden" name="<?= $e($name) ?>" value="<?= $e($wert) ?>">
        <?php endforeach ?>
        <input type="hidden" name="direction" value="<?= $e($richtung) ?>">
        <button class="btn btn-ghost btn-small" type="submit"
                title="<?= $e($richtung === 'up' ? translate('settings.nav_order.up') : translate('settings.nav_order.down')) ?>"
            <?= $aus ? 'disabled' : '' ?>><?= $richtung === 'up' ? '&uarr;' : '&darr;' ?></button>
    </form>
    <?php
};
?>
<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('settings.nav_order.title')) ?></h2>
    </div>
    <p class="hint"><?= $e(translate('settings.nav_order.hint', ['account' => translate('nav.account')])) ?></p>

    <?php if (!$sortierbar): ?>
        <div class="empty"><?= $e(translate('settings.nav_order.too_few')) ?></div>
    <?php else: ?>
        <table class="nav-order">
            <tbody>
            <?php foreach ($navGroups as $i => $group): ?>
                <tr>
                    <td><strong><?= $e($group['label']) ?></strong></td>
                    <td class="hint mono"><?= $e($group['key']) ?></td>
                    <td class="actions">
                        <?php if ($canManage): ?>
                            <div class="row">
                                <?php $felder = ['action' => 'nav_order', 'group' => $group['key']]; ?>
                                <?php $pfeil($felder, 'up', $i === 0) ?>
                                <?php $pfeil($felder, 'down', $i === count($navGroups) - 1) ?>
                            </div>
                        <?php endif ?>
                    </td>
                </tr>
                <?php if (count($group['items']) > 1): ?>
                    <?php foreach ($group['items'] as $j => $item): ?>
                        <tr class="nav-order-item">
                            <td><?= $e($item['label']) ?></td>
                            <td class="hint mono"><?= $e($item['key']) ?></td>
                            <td class="actions">
                                <?php if ($canManage): ?>
                                    <div class="row">
                                        <?php $felder = ['action' => 'nav_item_order', 'group' => $group['key'], 'item' => $item['key']]; ?>
                                        <?php $pfeil($felder, 'up', $j === 0) ?>
                                        <?php $pfeil($felder, 'down', $j === count($group['items']) - 1) ?>
                                    </div>
                                <?php endif ?>
                            </td>
                        </tr>
                    <?php endforeach ?>
                <?php endif ?>
            <?php endforeach ?>
            </tbody>
        </table>
    <?php endif ?>
</div>
