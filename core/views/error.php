<?php
/**
 * @var callable $e
 * @var callable $url
 * @var string $heading
 * @var string $message
 */
?>
<h1><?= $e($heading) ?></h1>
<p class="lead"><?= $e($message) ?></p>
<a class="btn btn-ghost" href="<?= $e($url('/')) ?>"><?= $e(translate('common.back')) ?></a>
