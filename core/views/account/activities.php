<?php
/**
 * Konto > Aktivitäten: Einstellungen für den Feed.
 *
 * @var callable $e
 * @var callable $url
 * @var array<string, array{label: string, bg: string, text: string}> $badges
 * @var array<string, array{label: string, bg: string, text: string}> $presets
 * @var string $feedUrl
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 * @var string $error
 */
?>
<h1><?= $e(translate('nav.activity')) ?></h1>
<p class="lead">
    <?= $e(translate('account.activity.lead')) ?>
</p>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('account.activity.link_title')) ?></h2>
        <a class="btn btn-small" href="<?= $e($feedUrl) ?>" target="_blank" rel="noreferrer"><?= $e(translate('common.open')) ?></a>
    </div>

    <div class="field">
        <input class="input mono" type="text" readonly value="<?= $e($feedUrl) ?>"
               onclick="this.select()">
    </div>

    <p class="hint">
        <?php // Ohne $e: der Platzhalter ist eigenes Markup. ?>
        <?= translate('account.activity.obs_hint', ['menu' => '<strong>' . $e(translate('account.activity.obs_menu')) . '</strong>']) ?>
    </p>
    <p class="hint">
        <?= translate('account.activity.not_for_viewers', ['source' => '<em>' . $e(translate('account.activity.browser_source')) . '</em>']) ?>
    </p>
</div>

<form method="post" action="<?= $e($url('/account/activities')) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

    <div class="card">
        <div class="card-head">
            <h2><?= $e(translate('account.activity.colors_title')) ?></h2>
            <span class="badge"><?= $e(translate('account.activity.kinds', ['count' => count($badges)])) ?></span>
        </div>

        <p class="hint" style="margin-top:-6px;">
            <?= $e(translate('account.activity.colors_hint')) ?>
        </p>

        <table>
            <thead>
            <tr>
                <th><?= $e(translate('account.activity.event')) ?></th>
                <th><?= $e(translate('account.activity.background')) ?></th>
                <th><?= $e(translate('account.activity.text')) ?></th>
                <th><?= $e(translate('account.activity.preview')) ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($badges as $key => $badge): ?>
                <tr data-badge-row>
                    <td><?= $e($badge['label']) ?></td>
                    <td>
                        <input class="input" type="color" style="width:70px;padding:3px;"
                               name="obs_badge_<?= $e((string) $key) ?>_bg"
                               value="<?= $e($badge['bg']) ?>"
                               data-badge-bg <?= $canManage ? '' : 'disabled' ?>>
                    </td>
                    <td>
                        <input class="input" type="color" style="width:70px;padding:3px;"
                               name="obs_badge_<?= $e((string) $key) ?>_text"
                               value="<?= $e($badge['text']) ?>"
                               data-badge-text <?= $canManage ? '' : 'disabled' ?>>
                    </td>
                    <td>
                        <span class="badge" data-badge-preview
                              style="background:<?= $e($badge['bg']) ?>;color:<?= $e($badge['text']) ?>;">
                            <?= $e($badge['label']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canManage): ?>
        <div class="row">
            <button class="btn" type="submit"><?= $e(translate('common.save')) ?></button>
            <button class="btn btn-ghost btn-small" type="submit" name="action" value="reset"
                    onclick="return confirm('<?= $e(translate('account.activity.confirm_reset')) ?>');">
                <?= $e(translate('account.activity.reset_colors')) ?>
            </button>
        </div>
    <?php else: ?>
        <p class="hint">
            <?= translate('common.missing_permission', ['permission' => '<span class="mono">Account.Activity.Manage</span>']) ?>
        </p>
    <?php endif; ?>
</form>

<script>
// Vorschau sofort mitfärben, damit man nicht speichern muss, um zu sehen
// wie es wirkt.
document.querySelectorAll('[data-badge-row]').forEach(function (row) {
    var bg = row.querySelector('[data-badge-bg]');
    var text = row.querySelector('[data-badge-text]');
    var preview = row.querySelector('[data-badge-preview]');

    function malen() {
        preview.style.background = bg.value;
        preview.style.color = text.value;
    }

    bg.addEventListener('input', malen);
    text.addEventListener('input', malen);
});
</script>
