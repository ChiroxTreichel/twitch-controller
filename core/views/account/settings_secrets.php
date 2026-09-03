<?php
/**
 * Einstellungen, Reiter «Secrets».
 *
 * Die Zugangsdaten der Twitch-App: Client-ID, Client-Secret und
 * das Webhook-Secret. Eigener Reiter, weil man hier selten etwas
 * aendert - und wenn, dann bewusst.
 *
 * @var \TwitchController\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var string $clientId
 * @var bool $hasSecret
 * @var bool $hasWebhook
 * @var string $redirectUri
 */

?>
<h1><?= $e(translate('settings.secrets.title')) ?></h1>
<p class="lead"><?= $e(translate('settings.secrets.lead')) ?></p>

<?= $view->render('account/_settings_tabs', ['tab' => $tab], null) ?>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('setup.step.app')) ?></h2>
        <?php if ($hasSecret && $hasWebhook): ?>
            <span class="badge badge-ok"><?= $e(translate('settings.app.complete')) ?></span>
        <?php else: ?>
            <span class="badge badge-warn"><?= $e(translate('settings.app.incomplete')) ?></span>
        <?php endif; ?>
    </div>

    <p class="hint">
        <?= translate('settings.app.redirect', ['url' => '<span class="mono">' . $e($redirectUri) . '</span>']) ?>
    </p>

    <?php if ($canManage): ?>
        <form method="post" action="<?= $e($url('/account/settings')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="credentials">

            <div class="field">
                <label for="client_id"><?= $e(translate('setup.credentials.client_id')) ?></label>
                <input class="input mono" id="client_id" name="client_id"
                       value="<?= $e($clientId) ?>" autocomplete="off" spellcheck="false">
            </div>

            <div class="field">
                <label for="client_secret"><?= $e(translate('setup.credentials.client_secret')) ?></label>
                <input class="input mono" id="client_secret" name="client_secret" type="password"
                       placeholder="<?= $e($hasSecret
                           ? translate('settings.app.secret_set')
                           : translate('settings.app.secret_unset')) ?>"
                       autocomplete="off">
            </div>

            <div class="field">
                <label for="webhook_secret"><?= $e(translate('setup.credentials.webhook_secret')) ?></label>
                <input class="input mono" id="webhook_secret" name="webhook_secret" type="password"
                       placeholder="<?= $e($hasWebhook
                           ? translate('settings.app.secret_set')
                           : translate('settings.app.secret_unset')) ?>"
                       autocomplete="off">
                <p class="hint">
                    <?= $e(translate('settings.app.webhook_hint')) ?>
                </p>
            </div>

            <button class="btn" type="submit"><?= $e(translate('common.save')) ?></button>
        </form>
    <?php else: ?>
        <p class="hint"><?= $e(translate('settings.app.owner_only')) ?></p>
    <?php endif; ?>
</div>
