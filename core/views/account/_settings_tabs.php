<?php
/**
 * Reiter über den Einstellungsseiten.
 *
 * Drei Bereiche mit ganz verschiedener Lebensdauer: am System stellt
 * man selten etwas um, den Kanal verbindet man einmal, und die
 * Zugangsdaten der Twitch-App fasst man im Idealfall nie wieder an.
 * Auf einer Seite untereinander war das eine lange Rolle, in der das
 * Wichtige unten stand.
 *
 * @var callable $e
 * @var callable $url
 * @var string $tab
 */
?>
<div class="tabs">
    <a class="tab<?= $tab === 'system' ? ' is-active' : '' ?>"
       href="<?= $e($url('/account/settings')) ?>"><?= $e(translate('settings.tab.system')) ?></a>
    <a class="tab<?= $tab === 'channel' ? ' is-active' : '' ?>"
       href="<?= $e($url('/account/settings/channel')) ?>"><?= $e(translate('settings.tab.channel')) ?></a>
    <a class="tab<?= $tab === 'secrets' ? ' is-active' : '' ?>"
       href="<?= $e($url('/account/settings/secrets')) ?>"><?= $e(translate('settings.tab.secrets')) ?></a>
</div>
