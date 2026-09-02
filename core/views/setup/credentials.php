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
<h1>Twitch-App verbinden</h1>
<p class="lead">
    Jede Installation braucht ihre eigene Twitch-Anwendung. Die ist in zwei Minuten angelegt.
</p>

<?php if ($error !== null): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <h2>1. Anwendung bei Twitch anlegen</h2>
    <p class="hint">
        Auf <a href="https://dev.twitch.tv/console/apps/create" target="_blank" rel="noreferrer noopener">dev.twitch.tv</a>
        eine Anwendung registrieren. Als <strong>OAuth Redirect URL</strong> genau diese Adresse eintragen:
    </p>
    <p class="mono" style="background:var(--bg);padding:10px 12px;border-radius:9px;border:1px solid var(--line);">
        <?= $e($redirectUri) ?>
    </p>
    <p class="hint">
        Kategorie und Client-Typ sind frei wählbar &mdash; &bdquo;Website Integration&ldquo; und
        &bdquo;Confidential&ldquo; passen. Danach <strong>New Secret</strong> klicken und beide Werte hier einsetzen.
    </p>
</div>

<form method="post" action="<?= $e($url('/setup/twitch')) ?>">
    <div class="field">
        <label for="client_id">Client-ID</label>
        <input class="input mono" id="client_id" name="client_id" required
               value="<?= $e($clientId) ?>" autocomplete="off" spellcheck="false">
    </div>

    <div class="field">
        <label for="client_secret">Client-Secret</label>
        <input class="input mono" id="client_secret" name="client_secret" type="password" required
               autocomplete="off" spellcheck="false">
        <p class="hint">Wird verschlüsselt gespeichert und nie wieder angezeigt.</p>
    </div>

    <div class="field">
        <label for="webhook_secret">Webhook-Secret</label>
        <input class="input mono" id="webhook_secret" name="webhook_secret" required
               value="<?= $e($suggestedKey) ?>" autocomplete="off" spellcheck="false">
        <p class="hint">
            Damit signiert Twitch die Events, die an <span class="mono"><?= $e($callbackUrl) ?></span> gehen.
            Der Vorschlag ist frisch erzeugt und kann so bleiben &mdash; er muss nirgends sonst eingetragen werden.
        </p>
    </div>

    <button class="btn" type="submit">Prüfen und weiter</button>
</form>
