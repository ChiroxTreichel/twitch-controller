<?php
/**
 * Notausgang.
 *
 * Der Grund fuer diese Seite: Update pruefen und einspielen lag nur auf
 * /account/settings. Reisst dort eine einzige Zeile die Seite, ist
 * genau der Knopf unerreichbar, der den Fehler behebt - und es bleibt
 * nur die Kommandozeile. Deshalb liegt beides zusaetzlich hier, auf
 * einer Seite ohne Navigation, ohne Plugins und mit so wenig Inhalt wie
 * moeglich.
 *
 * @var callable $e
 * @var callable $url
 * @var string $notice
 * @var string $error
 * @var string $csrf
 * @var string $version
 * @var string $language
 * @var array<string, string> $languages
 * @var array{ok: bool, message: string, behind: int}|null $check
 */
?>
<h1><?= $e(translate('rescue.title')) ?></h1>
<p class="lead"><?= $e(translate('rescue.lead')) ?></p>

<?php if ($notice !== ''): ?>
    <div class="alert alert-ok"><?= $e($notice) ?></div>
<?php endif ?>
<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?= $e($error) ?></div>
<?php endif ?>

<div class="card">
    <h2><?= $e(translate('rescue.update_heading')) ?></h2>
    <p class="muted"><?= $e(translate('rescue.update_hint', ['version' => $version])) ?></p>

    <form method="post" action="<?= $e($url('/rescue')) ?>" class="row gap">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <button class="btn btn-ghost" type="submit" name="action" value="update_check">
            <?= $e(translate('rescue.update_check')) ?>
        </button>
        <button class="btn" type="submit" name="action" value="update_apply">
            <?= $e(translate('rescue.update_apply')) ?>
        </button>
    </form>
</div>

<div class="card">
    <h2><?= $e(translate('rescue.language_heading')) ?></h2>
    <p class="muted"><?= $e(translate('rescue.language_hint')) ?></p>

    <form method="post" action="<?= $e($url('/rescue')) ?>" class="row gap">
        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
        <?php foreach ($languages as $code => $label): ?>
            <button class="btn <?= $code === $language ? '' : 'btn-ghost' ?>"
                    type="submit" name="language" value="<?= $e($code) ?>">
                <?= $e($label) ?>
            </button>
        <?php endforeach ?>
        <input type="hidden" name="action" value="language">
    </form>
</div>

<a class="btn btn-ghost" href="<?= $e($url('/account/settings')) ?>">
    <?= $e(translate('rescue.back_to_settings')) ?>
</a>
