<?php
/**
 * @var callable $e
 * @var callable $url
 * @var string|null $error
 * @var list<string> $scopes
 */
?>
<h1><?= $e(translate('setup.channel.title')) ?></h1>
<p class="lead">
    <?= $e(translate('setup.channel.lead')) ?>
</p>

<?php if ($error !== null): ?>
    <div class="note note-error"><?= $e((string) $error) ?></div>
<?php endif; ?>

<div class="card">
    <h2><?= $e(translate('setup.channel.scopes_title')) ?></h2>
    <?php if ($scopes === []): ?>
        <p class="hint">
            <?= $e(translate('setup.channel.no_scopes')) ?>
        </p>
    <?php else: ?>
        <ul class="hint" style="margin:0;padding-left:20px;">
            <?php foreach ($scopes as $scope): ?>
                <li class="mono"><?= $e($scope) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="hint">
            <?= $e(translate('setup.channel.more_later')) ?>
        </p>
    <?php endif; ?>
</div>

<div class="note note-warn">
    <strong><?= $e(translate('setup.channel.important')) ?></strong>
    <?= $e(translate('setup.channel.use_channel_account')) ?>
</div>

<a class="btn" href="<?= $e($url('/setup/channel')) ?>"><?= $e(translate('common.sign_in_twitch')) ?></a>
