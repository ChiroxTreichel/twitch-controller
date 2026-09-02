<?php
/**
 * Reiter über den Plugin-Seiten. Eingebunden aus plugins.php,
 * plugins_find.php und plugins_detail.php.
 *
 * @var callable $e
 * @var callable $url
 * @var string $tab
 */
?>
<div class="tabs">
    <a class="tab<?= $tab === 'installiert' ? ' is-active' : '' ?>"
       href="<?= $e($url('/konto/plugins')) ?>">Installierte Plugins</a>
    <a class="tab<?= $tab === 'finden' ? ' is-active' : '' ?>"
       href="<?= $e($url('/konto/plugins/finden')) ?>">Plugins finden</a>
</div>
