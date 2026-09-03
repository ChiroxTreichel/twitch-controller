<?php
/**
 * Rückfrage vor einer Aktion, die sich nicht widerrufen lässt.
 *
 * Statt `confirm()`: ein Kasten, der unter dem Knopf aufklappt. Der
 * Browserdialog ist nicht gestaltbar, schreibt den Domainnamen darüber
 * und sieht auf jedem System anders aus.
 *
 * Kommt ohne JavaScript aus - `<details>` klappt von sich auf und zu.
 * Ein zweiter Klick auf den Knopf schließt den Kasten wieder; mit
 * JavaScript zusätzlich Escape und ein Klick daneben (siehe
 * layout.php).
 *
 * Benutzung:
 *
 *   <?= $view->render('_confirm', [
 *       'label'    => translate('common.remove'),
 *       'question' => translate('…wirklich…?'),
 *       'confirm'  => translate('…ja, entfernen'),
 *       'action'   => $url('/account/plugins'),
 *       'fields'   => ['csrf' => $csrf, 'action' => 'remove', 'slug' => $slug],
 *       'danger'   => true,
 *   ], null) ?>
 *
 * @var callable $e
 * @var string $label     Beschriftung des Knopfes
 * @var string $question  Die Frage im Kasten
 * @var string $confirm   Beschriftung des bestätigenden Knopfes
 * @var string $action    Ziel des Formulars
 * @var array<string, string> $fields  Verborgene Felder
 * @var bool $danger      Roter Knopf (Vorgabe: ja)
 * @var bool $right       Nach links aufklappen (fuer Knoepfe am rechten Rand)
 * @var string $note      Zusätzlicher Hinweis unter der Frage (optional)
 */

$danger = !isset($danger) || $danger;
$small = !isset($small) || $small;

// Sitzt der Knopf am rechten Rand, muss der Kasten nach links
// aufklappen - sonst steht er halb neben der Seite.
$right = !empty($right);
?>
<details class="confirm<?= $right ? ' confirm-right' : '' ?>">
    <summary class="btn <?= $danger ? 'btn-danger' : '' ?><?= $small ? ' btn-small' : '' ?>">
        <?= $e($label) ?>
    </summary>

    <div class="confirm-panel">
        <p class="confirm-question"><?= $e($question) ?></p>

        <?php if (($note ?? '') !== ''): ?>
            <p class="hint"><?= $e($note) ?></p>
        <?php endif ?>

        <div class="row">
            <form method="post" action="<?= $e($action) ?>">
                <?php foreach ($fields as $name => $wert): ?>
                    <input type="hidden" name="<?= $e((string) $name) ?>" value="<?= $e((string) $wert) ?>">
                <?php endforeach ?>
                <button class="btn <?= $danger ? 'btn-danger' : '' ?> btn-small" type="submit">
                    <?= $e($confirm) ?>
                </button>
            </form>

            <?php /*
                Ohne JavaScript schließt ein zweiter Klick auf den Knopf
                den Kasten. Dieser Knopf hier tut dasselbe - er braucht
                aber JavaScript, deshalb ist er kein Ersatz, sondern
                eine Zugabe.
            */ ?>
            <button class="btn btn-ghost btn-small" type="button" data-confirm-cancel>
                <?= $e(translate('common.cancel')) ?>
            </button>
        </div>
    </div>
</details>
