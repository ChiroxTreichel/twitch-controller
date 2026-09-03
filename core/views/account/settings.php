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

use Overlays\Core\Support\Dates;
?>
<h1><?= $e(translate('nav.settings')) ?></h1>
<p class="lead"><?= $e(translate('settings.lead')) ?></p>

<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('settings.system.title')) ?></h2>
        <?php if (!$updatePossible): ?>
            <span class="badge badge-off"><?= $e(translate('settings.system.manual')) ?></span>
        <?php elseif ($update['requested_at'] > 0): ?>
            <span class="badge badge-warn"><?= $e(translate('settings.system.running')) ?></span>
        <?php elseif ($update['available']): ?>
            <span class="badge badge-warn"><?= $e(translate('market.update_available')) ?></span>
        <?php elseif ($update['checked_at'] > 0): ?>
            <span class="badge badge-ok"><?= $e(translate('settings.system.current')) ?></span>
        <?php endif; ?>
    </div>

    <table>
        <tbody>
        <tr>
            <td><?= $e(translate('settings.system.installed_version')) ?></td>
            <td class="actions mono"><?= $e($updateVersion) ?></td>
        </tr>
        <?php if ($update['checked_at'] > 0): ?>
            <tr>
                <td><?= $e(translate('settings.system.last_checked')) ?></td>
                <td class="actions hint">
                    <?= $e(Dates::long(date('c', $update['checked_at']))) ?>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>

    <?php if (!$updatePossible): ?>
        <p class="hint" style="margin-top:12px;">
            <?php // Ohne $e: die Platzhalter sind eigenes Markup. ?>
            <?= translate('settings.system.cannot_update', [
                'git'     => '<span class="mono">git</span>',
                'command' => '<span class="mono">sudo ./install.sh</span>',
            ]) ?>
        </p>
    <?php else: ?>

        <?php if ($update['requested_at'] > 0): ?>
            <div class="note note-warn" style="margin:14px 0 0;">
                <?= $e(translate('settings.system.queued')) ?>
            </div>
        <?php elseif ($update['available']): ?>
            <div class="note note-warn" style="margin:14px 0 0;">
                <strong><?= $e(translate('settings.system.newer')) ?></strong>
                <?php if ($update['subject'] !== ''): ?>
                    <div class="hint" style="margin-top:6px;">
                        <?= $e(translate('settings.system.latest_change', ['subject' => $update['subject']])) ?>
                    </div>
                <?php endif; ?>

                <?php if ($update['needs_shell']): ?>
                    <p style="margin:10px 0 0;">
                        <?= $e(translate('settings.system.needs_shell')) ?>
                    </p>
                    <p class="mono" style="background:var(--bg);padding:10px 12px;border-radius:9px;border:1px solid var(--line);margin:8px 0 0;">
                        cd <?= $e($installPath) ?> &amp;&amp; sudo ./install.sh
                    </p>
                <?php elseif ($canManage): ?>
                    <form method="post" action="<?= $e($url('/account/settings')) ?>"
                          style="margin-top:12px;">
                        <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                        <input type="hidden" name="action" value="update_apply">
                        <button class="btn btn-small" type="submit"><?= $e(translate('settings.system.update_now')) ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($update['last_result'] !== []): ?>
            <?php $letztes = $update['last_result']; ?>
            <div class="note <?= !empty($letztes['ok']) ? 'note-ok' : 'note-error' ?>" style="margin:14px 0 0;">
                <strong><?= $e(!empty($letztes['ok'])
                    ? translate('settings.system.last_ok')
                    : translate('settings.system.last_failed')) ?></strong>
                <div class="hint" style="margin-top:4px;">
                    <?= $e((string) ($letztes['message'] ?? '')) ?>
                    <?php if (!empty($letztes['at'])): ?>
                        &middot; <?= $e(Dates::long((string) $letztes['at'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($canManage && $update['requested_at'] === 0): ?>
            <form method="post" action="<?= $e($url('/account/settings')) ?>" style="margin-top:14px;">
                <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                <input type="hidden" name="action" value="update_check">
                <button class="btn btn-ghost btn-small" type="submit"><?= $e(translate('settings.system.check')) ?></button>
            </form>
        <?php endif; ?>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <form method="post" action="<?= $e($url('/account/settings')) ?>" class="row"
              style="margin-top:16px;padding-top:14px;border-top:1px solid var(--line);">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="timezone">
            <label for="timezone" style="margin:0;"><?= $e(translate('settings.timezone')) ?></label>
            <select class="input" id="timezone" name="timezone" style="width:auto;">
                <?php foreach ($timezones as $zone): ?>
                    <option value="<?= $e($zone) ?>" <?= $timezone === $zone ? 'selected' : '' ?>>
                        <?= $e($zone) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button class="btn btn-ghost btn-small" type="submit">Übernehmen</button>
        </form>

        <form method="post" action="<?= $e($url('/account/settings')) ?>" class="row"
              style="margin-top:10px;">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="language">
            <label for="language" style="margin:0;"><?= $e(translate('settings.language')) ?></label>
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
            <?= translate('settings.language_hint', ['file' => '<span class="mono">lang/&lt;code&gt;.json</span>']) ?>
        </p>

        <p class="hint" style="margin-top:8px;">
            <?= $e(translate('settings.timezone_hint')) ?>
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
        <h2><?= $e(translate('settings.channel.title')) ?></h2>
        <?php if ($broadcasterToken !== null): ?>
            <span class="badge badge-ok"><?= $e(translate('settings.channel.connected')) ?></span>
        <?php else: ?>
            <span class="badge badge-error"><?= $e(translate('settings.channel.not_connected')) ?></span>
        <?php endif; ?>
    </div>

    <?php if ($channel['login'] !== ''): ?>
        <table>
            <tbody>
            <tr>
                <td><?= $e(translate('settings.channel.title')) ?></td>
                <td class="actions">
                    <strong><?= $e($channel['name'] !== '' ? $channel['name'] : $channel['login']) ?></strong>
                    <span class="hint mono">(<?= $e($channel['id']) ?>)</span>
                </td>
            </tr>
            <?php if ($broadcasterToken !== null): ?>
                <tr>
                    <td><?= $e(translate('settings.channel.granted')) ?></td>
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
        <div class="empty"><?= $e(translate('settings.channel.none')) ?></div>
    <?php endif; ?>

    <?php if ($missingScopes !== []): ?>
        <div class="note note-warn" style="margin:14px 0 0;">
            <strong><?= $e(translate('settings.channel.missing')) ?></strong>
            <p style="margin:8px 0 0;">
                <?= $e(translate('settings.channel.missing_hint')) ?>
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
                <?= $e(translate('settings.channel.missing_why')) ?>
            </p>
            <?php if ($canManage): ?>
                <p style="margin:12px 0 0;">
                    <a class="btn btn-small" href="<?= $e($url('/account/settings/channel')) ?>">
                        <?= $e(translate('common.reconnect_channel')) ?>
                    </a>
                </p>
                <p class="hint" style="margin:8px 0 0;">
                    <?= $e(translate('settings.channel.then_sync')) ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($canManage): ?>
        <div class="row" style="margin-top:14px;">
            <a class="btn btn-small" href="<?= $e($url('/account/settings/channel')) ?>">
                <?= $e($broadcasterToken === null
                    ? translate('settings.channel.connect')
                    : translate('settings.channel.reconnect')) ?>
            </a>
            <?php if ($broadcasterToken !== null): ?>
                <form method="post" action="<?= $e($url('/account/settings')) ?>"
                      onsubmit="return confirm('<?= $e(translate('settings.channel.confirm_disconnect')) ?>');">
                    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
                    <input type="hidden" name="action" value="disconnect_channel">
                    <button class="btn btn-danger btn-small" type="submit"><?= $e(translate('settings.channel.disconnect')) ?></button>
                </form>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('settings.events.title')) ?></h2>
        <span class="badge"><?= $e(translate('settings.events.needed', ['count' => count($desired)])) ?></span>
    </div>
    <p class="hint">
        <?= translate('settings.events.hint', ['url' => '<span class="mono">' . $e($callbackUrl) . '</span>']) ?>
    </p>

    <?php if ($canManage): ?>
        <form method="post" action="<?= $e($url('/account/settings')) ?>">
            <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
            <input type="hidden" name="action" value="eventsub">
            <button class="btn btn-small" type="submit"><?= $e(translate('settings.events.sync')) ?></button>
        </form>
    <?php endif; ?>
</div>

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
