<?php
/**
 * Rahmen der Ersteinrichtung - eigenes Layout, weil es hier noch keine
 * Navigation und keinen angemeldeten Benutzer gibt.
 *
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 * @var string $content
 * @var string $title
 * @var string $step
 */

use Overlays\Core\Setup\SetupController;

$order = [
    SetupController::STEP_CHECK       => translate('setup.step.system'),
    SetupController::STEP_CREDENTIALS => translate('setup.step.app'),
    SetupController::STEP_CHANNEL     => translate('setup.step.channel'),
    SetupController::STEP_EVENTSUB    => translate('setup.step.events'),
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
    <title><?= $e($title !== '' ? $title : translate('setup.title')) ?></title>
    <link rel="stylesheet" href="<?= $e($asset('/assets/admin.css')) ?>">
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
            <?= $e(translate('setup.step_of', ['step' => $currentIndex + 1, 'total' => count($keys), 'name' => $order[$keys[$currentIndex]]])) ?>
        </p>

        <?= $content ?>
    </div>
</div>
</body>
</html>
