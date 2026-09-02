<?php
/**
 * @var callable $e
 * @var callable $url
 */
?>
<h1>Einrichtung gesperrt</h1>
<p class="lead">
    Diese Installation hat schon einen Kanalinhaber. Nur er kann die Einrichtung fortsetzen.
</p>
<a class="btn" href="<?= $e($url('/login')) ?>">Mit Twitch anmelden</a>
