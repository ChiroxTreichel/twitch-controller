<?php
/**
 * Einstellungen des Kerns: nur was mit dem Twitch-Login und dem
 * Event-Empfang zu tun hat. Alles Fachliche gehoert in die Plugins.
 *
 * @var callable $e
 * @var callable $url
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var string $clientId
 * @var bool $hasSecret
 * @var bool $hasWebhook
 * @var string $redirectUri
 * @var string $callbackUrl
 * @var array{id: string, login: string, name: string} $channel
 * @var array{login: ?string, expires_in: int, scopes: list<string>}|null $broadcasterToken
 * @var list<string> $missingScopes
 * @var list<array{type: string, version: string, condition: array<string, string>}> $desired
 */
?>
<h1>Einstellungen</h1>
<p class="lead">Twitch-Anbindung dieser Installation.</p>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Kanal</h2>
        <?php if ($broadcasterToken !== null): ?>
            <span class="badge badge-ok">verbunden</span>
        <?php else: ?>
            <span class="badge badge-error">nicht verbunden</span>
        <?php endif; ?>
    </div>

    <?php if ($channel['login'] !== ''): ?>
        <table>
            <tbody>
            <tr>
                <td>Kanal</td>
                <td class="actions">
                    <strong><?= $e($channel['name'] !== '' ? $channel['name'] : $channel['login']) ?></strong>
                    <span class="hint mono">(<?= $e($channel['id']) ?>)</span>
                </td>
            </tr>
            <?php if ($broadcasterToken !== null): ?>
                <tr>
                    <td>Token gültig noch</td>
                    <td class="actions hint">
                        <?= $e((string) (int) round($broadcasterToken['expires_in'] / 60)) ?> Minuten
                        <span class="hint">(wird automatisch erneuert)</span>
                    </td>
                </tr>
                <tr>
                    <td>Berechtigungen</td>
                    <td class="actions hint mono">
                        <?= $e($broadcasterToken['scopes'] === [] ? '—' : implode(' ', $broadcasterToken['scopes'])) ?>
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    <?php else: ?>
        <div class="empty">Noch kein Kanal verbunden.</div>
    <?php endif; ?>

    <?php if ($missingScopes !== []): ?>
        <div class="note note-warn" style="margin:14px 0 0;">
            <strong>Berechtigungen fehlen.</strong>
            Wahrscheinlich wurde nach dem Verbinden ein Plugin aktiviert, das mehr braucht:
            <span class="mono"><?= $e(implode(' ', $missingScopes)) ?></span>.
            Einmal neu verbinden genügt.
        </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <div class="row" style="margin-top:14px;">
            <a class="btn btn-small" href="<?= $e($url('/konto/einstellungen/kanal')) ?>">
                <?= $broadcasterToken === null ? 'Kanal verbinden' : 'Neu verbinden' ?>
            </a>
            <?php if ($broadcasterToken !== null): ?>
                <form method="post" action="<?= $e($url('/konto/einstellungen')) ?>"
                      onsubmit="return confirm('Verbindung trennen? Events und Plugins, die Twitch-Daten brauchen, funktionieren dann nicht mehr.');">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="disconnect_channel">
                    <button class="btn btn-danger btn-small" type="submit">Trennen</button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2>Event-Abos</h2>
        <span class="badge"><?= $e((string) count($desired)) ?> gebraucht</span>
    </div>
    <p class="hint">
        Twitch schickt Events an <span class="mono"><?= $e($callbackUrl) ?></span>.
        Welche Abos gebraucht werden, ergibt sich aus dem Kern plus den aktiven Plugins &mdash;
        nach jedem Aktivieren oder Deaktivieren einmal abgleichen.
    </p>

    <?php if ($canManage): ?>
        <form method="post" action="<?= $e($url('/konto/einstellungen')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="eventsub">
            <button class="btn btn-small" type="submit">Abos jetzt abgleichen</button>
        </form>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2>Twitch-App</h2>
        <?php if ($hasSecret && $hasWebhook): ?>
            <span class="badge badge-ok">vollständig</span>
        <?php else: ?>
            <span class="badge badge-warn">unvollständig</span>
        <?php endif; ?>
    </div>

    <p class="hint">
        Redirect-URL in der Twitch-Konsole muss sein:
        <span class="mono"><?= $e($redirectUri) ?></span>
    </p>

    <?php if ($canManage): ?>
        <form method="post" action="<?= $e($url('/konto/einstellungen')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="credentials">

            <div class="field">
                <label for="client_id">Client-ID</label>
                <input class="input mono" id="client_id" name="client_id"
                       value="<?= $e($clientId) ?>" autocomplete="off" spellcheck="false">
            </div>

            <div class="field">
                <label for="client_secret">Client-Secret</label>
                <input class="input mono" id="client_secret" name="client_secret" type="password"
                       placeholder="<?= $hasSecret ? 'gesetzt – leer lassen, um es zu behalten' : 'noch nicht gesetzt' ?>"
                       autocomplete="off">
            </div>

            <div class="field">
                <label for="webhook_secret">Webhook-Secret</label>
                <input class="input mono" id="webhook_secret" name="webhook_secret" type="password"
                       placeholder="<?= $hasWebhook ? 'gesetzt – leer lassen, um es zu behalten' : 'noch nicht gesetzt' ?>"
                       autocomplete="off">
                <p class="hint">
                    Nach einer Änderung müssen die Abos neu angelegt werden &mdash; das Secret wird beim
                    Anlegen mitgegeben und lässt sich nachträglich nicht ändern.
                </p>
            </div>

            <button class="btn" type="submit">Speichern</button>
        </form>
    <?php else: ?>
        <p class="hint">Nur der Kanalinhaber darf die Zugangsdaten sehen und ändern.</p>
    <?php endif; ?>
</div>
