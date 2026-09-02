<?php
/**
 * @var callable $e
 * @var callable $url
 * @var string|null $error
 * @var list<string> $scopes
 */
?>
<h1>Kanal verbinden</h1>
<p class="lead">
    Jetzt einmal mit dem Twitch-Account anmelden, um dessen Kanal es geht.
    Dieser Account wird gleichzeitig zum Kanalinhaber dieser Installation.
</p>

<?php if ($error !== null): ?>
    <div class="note note-error"><?= $e((string) $error) ?></div>
<?php endif; ?>

<div class="card">
    <h2>Wofür die Berechtigungen sind</h2>
    <?php if ($scopes === []): ?>
        <p class="hint">
            Aktuell werden keine zusätzlichen Berechtigungen gebraucht &mdash; nur die Anmeldung selbst.
            Plugins können später weitere anfordern.
        </p>
    <?php else: ?>
        <ul class="hint" style="margin:0;padding-left:20px;">
            <?php foreach ($scopes as $scope): ?>
                <li class="mono"><?= $e($scope) ?></li>
            <?php endforeach; ?>
        </ul>
        <p class="hint">
            Wenn du später Plugins installierst, die mehr brauchen, wirst du gefragt, den Kanal erneut zu verbinden.
        </p>
    <?php endif; ?>
</div>

<div class="note note-warn">
    <strong>Wichtig:</strong> Melde dich bei Twitch mit dem Kanal-Account an, nicht mit einem Bot- oder Zweitaccount.
    Twitch fragt gleich, welcher Account verwendet werden soll.
</div>

<a class="btn" href="<?= $e($url('/setup/kanal')) ?>">Mit Twitch anmelden</a>
