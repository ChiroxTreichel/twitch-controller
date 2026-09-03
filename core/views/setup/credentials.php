<?php
/**
 * @var callable $e
 * @var callable $url
 * @var string|null $error
 * @var string $redirectUri
 * @var string $callbackUrl
 * @var string $clientId
 * @var string $suggestedKey
 */
?>
<h1><?= $e(translate('setup.credentials.title')) ?></h1>
<p class="lead">
    <?= $e(translate('setup.credentials.lead')) ?>
</p>

<?php if ($error !== null): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <h2><?= $e(translate('setup.credentials.step1')) ?></h2>
    <p class="hint">
        <?php // Ohne $e: die Platzhalter sind eigenes Markup. ?>
        <?= translate('setup.credentials.register', ['site' => '<a href="https://dev.twitch.tv/console/apps/create" target="_blank" rel="noreferrer noopener">dev.twitch.tv</a>', 'field' => '<strong>OAuth Redirect URL</strong>']) ?>
    </p>
    <p class="mono" style="background:var(--bg);padding:10px 12px;border-radius:9px;border:1px solid var(--line);">
        <?= $e($redirectUri) ?>
    </p>
    <p class="hint">
        <?= translate('setup.credentials.category', ['button' => '<strong>New Secret</strong>']) ?>
    </p>
</div>

<form method="post" action="<?= $e($url('/setup/twitch')) ?>">
    <div class="field">
        <label for="client_id"><?= $e(translate('setup.credentials.client_id')) ?></label>
        <input class="input mono" id="client_id" name="client_id" required
               value="<?= $e($clientId) ?>" autocomplete="off" spellcheck="false">
    </div>

    <div class="field">
        <label for="client_secret"><?= $e(translate('setup.credentials.client_secret')) ?></label>
        <input class="input mono" id="client_secret" name="client_secret" type="password" required
               autocomplete="off" spellcheck="false">
        <p class="hint"><?= $e(translate('setup.credentials.secret_hint')) ?></p>
    </div>

    <div class="field">
        <label for="webhook_secret"><?= $e(translate('setup.credentials.webhook_secret')) ?></label>
        <input class="input mono" id="webhook_secret" name="webhook_secret" required
               value="<?= $e($suggestedKey) ?>" autocomplete="off" spellcheck="false">
        <p class="hint">
            <?= translate('setup.credentials.webhook_hint', ['url' => '<span class="mono">' . $e($callbackUrl) . '</span>']) ?>
        </p>
    </div>

    <button class="btn" type="submit"><?= $e(translate('setup.credentials.continue')) ?></button>
</form>
