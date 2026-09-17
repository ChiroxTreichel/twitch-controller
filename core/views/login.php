<?php
/**
 * @var \TwitchController\Core\App $app
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 * @var string $invite
 * @var string $next
 * @var string $error
 */

$channel = $app->settings->string('twitch_broadcaster_name')
    ?: $app->settings->string('twitch_broadcaster_login');
?>
<!doctype html>
<html lang="<?= $e($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e(translate('login.title')) ?></title>
    <link rel="stylesheet" href="<?= $e($asset('/assets/admin.css')) ?>">
</head>
<body>
<div class="centered">
    <div class="panel" style="max-width:420px;text-align:center;">
        <div class="brand" style="justify-content:center;margin-bottom:18px;">
            <span class="brand-dot"></span>
            <strong><?= $e(\TwitchController\Core\App::NAME) ?></strong>
        </div>

        <?php if ($channel !== ''): ?>
            <p class="lead" style="margin-bottom:22px;"><?= $e($channel) ?></p>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="note note-error" style="text-align:left;"><?= $e($error) ?></div>
        <?php endif; ?>

        <?php if ($invite !== ''): ?>
            <div class="note note-ok" style="text-align:left;">
                <?= $e(translate('login.invited')) ?>
            </div>
        <?php endif; ?>

        <?php /*
            Das Ziel wandert mit an den Knopf: von hier geht es zu
            Twitch, und von dort zurueck - ohne diesen Anhang wuesste
            niemand mehr, wo der Weg angefangen hat.
        */ ?>
        <a class="btn" style="width:100%;justify-content:center;"
           href="<?= $e($url(\TwitchController\Core\Auth\ReturnTo::appendTo(
               '/login/start',
               $next,
               ['invite' => $invite]
           ))) ?>">
            <?= $e(translate('common.sign_in_twitch')) ?>
        </a>

        <?php if ($invite === ''): ?>
            <p class="hint" style="margin-top:18px;">
                <?= $e(translate('login.only_invited')) ?>
            </p>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
