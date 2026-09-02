<?php
/**
 * Rahmen der Ersteinrichtung - eigenes Layout, weil es hier noch keine
 * Navigation und keinen angemeldeten Benutzer gibt.
 *
 * @var callable $e
 * @var callable $url
 * @var string $content
 * @var string $title
 * @var string $step
 */

use Overlays\Core\Setup\SetupController;

$order = [
    SetupController::STEP_CHECK       => 'System',
    SetupController::STEP_CREDENTIALS => 'Twitch-App',
    SetupController::STEP_CHANNEL     => 'Kanal',
    SetupController::STEP_EVENTSUB    => 'Events',
];

$keys = array_keys($order);
$currentIndex = array_search($step ?? '', $keys, true);
$currentIndex = $currentIndex === false ? 0 : $currentIndex;
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title !== '' ? $title : 'Einrichtung') ?></title>
    <link rel="stylesheet" href="<?= $e($url('/assets/admin.css')) ?>">
</head>
<body>
<div class="centered">
    <div class="panel">
        <div class="steps" aria-hidden="true">
            <?php foreach ($keys as $index => $key): ?>
                <div class="step <?= $index < $currentIndex ? 'is-done' : ($index === $currentIndex ? 'is-current' : '') ?>"></div>
            <?php endforeach; ?>
        </div>
        <p class="hint" style="margin-top:-14px;margin-bottom:22px;">
            Schritt <?= $e((string) ($currentIndex + 1)) ?> von <?= $e((string) count($keys)) ?>
            &middot; <?= $e($order[$keys[$currentIndex]]) ?>
        </p>

        <?= $content ?>
    </div>
</div>
</body>
</html>
