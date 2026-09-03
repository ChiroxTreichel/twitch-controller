<?php
/**
 * @var callable $e
 * @var callable $url
 */
?>
<h1><?= $e(translate('setup.locked.title')) ?></h1>
<p class="lead">
    <?= $e(translate('setup.locked.lead')) ?>
</p>
<a class="btn" href="<?= $e($url('/login')) ?>"><?= $e(translate('common.sign_in_twitch')) ?></a>
