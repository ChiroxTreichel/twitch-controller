<?php
/**
 * Rahmen ohne Navigation und ohne Datenbankzugriff.
 *
 * Wird fuer Fehlerseiten gebraucht: wenn die Datenbank weg ist, darf die
 * Meldung darueber nicht selbst an der Datenbank scheitern.
 *
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 * @var string $content
 * @var string $title
 */
?>
<!doctype html>
<html lang="<?= $e($language ?? 'de') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title !== '' ? $title : \TwitchController\Core\App::NAME) ?></title>
    <link rel="stylesheet" href="<?= $e($asset('/assets/admin.css')) ?>">
</head>
<body>
<div class="centered">
    <div class="panel"><?= $content ?></div>
</div>
</body>
</html>
