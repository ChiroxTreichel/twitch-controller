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
 * @var string $installPath
 * @var string $language
 * @var list<string> $languages
 * @var list<array{type: string, version: string, condition: array<string, string>}> $desired
 */
?>
<h1>Einstellungen</h1>
<p class="lead">Twitch-Anbindung und Stand dieser Installation.</p>

<div class="card">
    <div class="card-head">
        <h2>System</h2>
        <?php if (!$updatePossible): ?>
            <span class="badge badge-off">Updates von Hand</span>
        <?php elseif ($update['requested_at'] > 0): ?>
            <span class="badge badge-warn">Update läuft</span>
        <?php elseif ($update['available']): ?>
            <span class="badge badge-warn">Update verfügbar</span>
        <?php elseif ($update['checked_at'] > 0): ?>
            <span class="badge badge-ok">aktuell</span>
        <?php endif; ?>
    </div>

    <table>
        <tbody>
        <tr>
            <td>Installierte Version</td>
            <td class="actions mono"><?= $e($updateVersion) ?></td>
        </tr>
        <?php if ($update['checked_at'] > 0): ?>
            <tr>
                <td>Zuletzt nachgesehen</td>
                <td class="actions hint">
                    <?= $e(date('d.m.Y H:i', $update['checked_at'])) ?>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if (!$updatePossible): ?>
        <p class="hint" style="margin-top:12px;">
            Diese Installation kann sich nicht selbst aktualisieren &mdash; entweder ist sie keine
            Git-Kopie, oder im Container fehlt <span class="mono">git</span>. Einmal
            <span class="mono">sudo ./install.sh</span> auf dem Server behebt beides.
        </p>
    <?php else: ?>

        <?php if ($update['requested_at'] > 0): ?>
            <div class="note note-warn" style="margin:14px 0 0;">
                Das Update ist beauftragt und läuft im Hintergrund.
                Seite in einer Minute neu laden.
            </div>
        <?php elseif ($update['available']): ?>
            <div class="note note-warn" style="margin:14px 0 0;">
                <strong>Es gibt eine neuere Version.</strong>
                <?php if ($update['subject'] !== ''): ?>
                    <div class="hint" style="margin-top:6px;">
                        Neueste Änderung: <?= $e($update['subject']) ?>
                    </div>
                <?php endif; ?>

                <?php if ($update['needs_shell']): ?>
                    <p style="margin:10px 0 0;">
                        Dieses Update ändert auch Dinge am Server selbst. Es lässt sich deshalb
                        nicht von hier aus einspielen &mdash; dafür braucht es einen Befehl auf
                        dem Server:
                    </p>
                    <p class="mono" style="background:var(--bg);padding:10px 12px;border-radius:9px;border:1px solid var(--line);margin:8px 0 0;">
                        cd <?= $e($installPath) ?> &amp;&amp; sudo ./install.sh
                    </p>
                <?php elseif ($canManage): ?>
                    <form method="post" action="<?= $e($url('/konto/einstellungen')) ?>"
                          style="margin-top:12px;">
                        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="action" value="update_apply">
                        <button class="btn btn-small" type="submit">Jetzt aktualisieren</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($update['last_result'] !== []): ?>
            <?php $letztes = $update['last_result']; ?>
            <div class="note <?= !empty($letztes['ok']) ? 'note-ok' : 'note-error' ?>" style="margin:14px 0 0;">
                <strong><?= !empty($letztes['ok']) ? 'Letztes Update erfolgreich.' : 'Letztes Update fehlgeschlagen.' ?></strong>
                <div class="hint" style="margin-top:4px;">
                    <?= $e((string) ($letztes['message'] ?? '')) ?>
                    <?php if (!empty($letztes['at'])): ?>
                        &middot; <?= $e(date('d.m.Y H:i', strtotime((string) $letztes['at']))) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canManage && $update['requested_at'] === 0): ?>
            <form method="post" action="<?= $e($url('/konto/einstellungen')) ?>" style="margin-top:14px;">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="update_check">
                <button class="btn btn-ghost btn-small" type="submit">Nach Updates sehen</button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form method="post" action="<?= $e($url('/konto/einstellungen')) ?>" class="row"
              style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line);">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="timezone">
            <label for="timezone" style="margin:0;">Zeitzone</label>
            <select class="input" id="timezone" name="timezone" style="width:auto;">
                <?php foreach ($timezones as $zone): ?>
                    <option value="<?= $e($zone) ?>" <?= $timezone === $zone ? 'selected' : '' ?>>
                        <?= $e($zone) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-small" type="submit">Übernehmen</button>
        </form>

        <form method="post" action="<?= $e($url('/konto/einstellungen')) ?>" class="row"
              style="margin-top:10px;">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="language">
            <label for="language" style="margin:0;">Sprache</label>
            <select class="input" id="language" name="language" style="width:auto;">
                <?php foreach ($languages as $code): ?>
                    <option value="<?= $e($code) ?>" <?= $language === $code ? 'selected' : '' ?>>
                        <?= $e(\Overlays\Core\I18n\Translator::label($code)) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-small" type="submit">Übernehmen</button>
        </form>
        <p class="hint" style="margin-top:8px;">
            Weitere Sprachen kommen dazu, indem eine Datei
            <span class="mono">lang/&lt;code&gt;.json</span> angelegt wird.
        </p>

        <p class="hint" style="margin-top:8px;">
            Bestimmt, welche Uhrzeiten überall angezeigt werden. Der Server rechnet intern in
            UTC &mdash; ohne die richtige Zone stehen im Feed Zeiten, die daneben liegen.
        </p>
    <?php endif; ?>
</div>

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
                    <td>Erteilte Rechte</td>
                    <td class="actions hint">
                        <?php $erteilt = \Overlays\Core\Twitch\Scopes::describe($broadcasterToken['scopes'], $app->hooks); ?>
                        <?php if ($erteilt === []): ?>
                            &mdash;
                        <?php else: ?>
                            <?php foreach ($erteilt as $recht): ?>
                                <div title="<?= $e($recht['scope']) ?>"><?= $e($recht['label']) ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
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
            <strong>Es fehlen Rechte auf deinem Kanal.</strong>
            <p style="margin:8px 0 0;">
                Twitch erlaubt uns diese Dinge noch nicht &mdash; solange bleiben die zugehörigen
                Meldungen im Stream aus:
            </p>
            <ul style="margin:8px 0 0;padding-left:20px;">
                <?php foreach (\Overlays\Core\Twitch\Scopes::describe($missingScopes, $app->hooks) as $recht): ?>
                    <li>
                        <strong><?= $e($recht['label']) ?></strong>
                        <?php if ($recht['reason'] !== ''): ?>
                            &ndash; <?= $e($recht['reason']) ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p style="margin:10px 0 0;">
                Das passiert, wenn ein Update oder ein neues Plugin mehr braucht als beim
                letzten Verbinden erlaubt wurde. Einmal neu verbinden reicht &mdash; Twitch
                fragt dann nach den fehlenden Rechten.
            </p>
            <?php if ($canManage): ?>
                <p style="margin:12px 0 0;">
                    <a class="btn btn-small" href="<?= $e($url('/konto/einstellungen/kanal')) ?>">
                        Kanal neu verbinden
                    </a>
                </p>
                <p class="hint" style="margin:8px 0 0;">
                    Danach unten auf &bdquo;Abos jetzt abgleichen&ldquo; klicken.
                </p>
            <?php endif; ?>
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
