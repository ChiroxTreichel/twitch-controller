<?php
/**
 * Einstellungen, Reiter «Kanal».
 *
 * Welcher Kanal verbunden ist, welche Twitch-Rechte dabei erteilt
 * wurden - und die Event-Abos, die daran haengen. Beides gehoert
 * zusammen: ohne verbundenen Kanal gibt es keine Abos.
 *
 * @var \TwitchController\Core\Http\View $view
 * @var callable $e
 * @var callable $url
 * @var string $tab
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 * @var string $error
 * @var array{id: string, login: string, name: string} $channel
 * @var array{login: ?string, expires_in: int, scopes: list<string>}|null $broadcasterToken
 * @var list<string> $missingScopes
 * @var string $callbackUrl
 * @var list<array{type: string, version: string, condition: array<string, string>}> $desired
 * @var \TwitchController\Core\App $app
 */

?>
<h1><?= $e(translate('settings.channel.title')) ?></h1>
<p class="lead"><?= $e(translate('settings.channel.lead')) ?></p>

<?= $view->render('account/_settings_tabs', ['tab' => $tab], null) ?>

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
                        <?php $erteilt = \TwitchController\Core\Twitch\Scopes::describe($broadcasterToken['scopes'], $app->hooks); ?>
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
                <?php foreach (\TwitchController\Core\Twitch\Scopes::describe($missingScopes, $app->hooks) as $recht): ?>
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
                    <a class="btn btn-small" href="<?= $e($url('/account/settings/connect')) ?>">
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
            <a class="btn btn-small" href="<?= $e($url('/account/settings/connect')) ?>">
                <?= $e($broadcasterToken === null
                    ? translate('settings.channel.connect')
                    : translate('settings.channel.reconnect')) ?>
            </a>
            <?php if ($broadcasterToken !== null): ?>
                <?= $view->render('_confirm', [
                    'label'    => translate('settings.channel.disconnect'),
                    'question' => translate('settings.channel.confirm_disconnect'),
                    'confirm'  => translate('settings.channel.confirm_disconnect_yes'),
                    'action'   => $url('/account/settings'),
                    'fields'   => ['csrf' => $csrf, 'action' => 'disconnect_channel'],
                ], null) ?>
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
