<?php
/**
 * @var callable $e
 * @var callable $url
 * @var string $heading
 * @var string $message
 * @var bool $rescue Notausgang anbieten (nur fuer Angemeldete)
 */
?>
<h1><?= $e($heading) ?></h1>
<p class="lead"><?= $e($message) ?></p>
<div class="row">
    <a class="btn btn-ghost" href="<?= $e($url('/')) ?>"><?= $e(translate('common.back')) ?></a>
    <?php if (!empty($rescue)): ?>
        <a class="btn" href="<?= $e($url('/rescue')) ?>"><?= $e(translate('rescue.title')) ?></a>
    <?php endif ?>
</div>
