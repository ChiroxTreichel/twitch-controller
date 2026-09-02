<?php
/**
 * Rahmen der Adminoberflaeche.
 *
 * @var \Overlays\Core\App $app
 * @var callable $e
 * @var callable $url
 * @var string $content
 * @var string $title
 * @var string $active
 */

// Defensiv: das Layout wird auch bei Fehlerseiten gerendert und darf
// nicht selbst an einer wegbrechenden Datenbank scheitern.
try {
    $user = $app->auth->user();
    $nav = (new \Overlays\Core\Admin\Nav($app))->build();
    $channel = $app->settings->string('twitch_broadcaster_login');
} catch (\Throwable) {
    $user = null;
    $nav = [];
    $channel = '';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title !== '' ? $title . ' · Overlays' : 'Overlays') ?></title>
    <link rel="stylesheet" href="<?= $e($url('/assets/admin.css')) ?>">
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-dot"></span>
            <div>
                <strong>Overlays</strong>
                <?php if ($channel !== ''): ?>
                    <small><?= $e($channel) ?></small>
                <?php endif; ?>
            </div>
        </div>

        <nav>
            <?php foreach ($nav as $group): ?>
                <div class="nav-group">
                    <div class="nav-label"><?= $e($group['label']) ?></div>
                    <?php foreach ($group['items'] as $item): ?>
                        <a class="nav-item<?= $active === $item['key'] ? ' is-active' : '' ?>"
                           href="<?= $e($url($item['href'])) ?>"><?= $e($item['label']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </nav>

        <?php if ($user !== null): ?>
            <form class="sidebar-foot" method="post" action="<?= $e($url('/logout')) ?>">
                <input type="hidden" name="csrf" value="<?= $e($app->auth->csrfToken()) ?>">
                <div class="who">
                    <strong><?= $e($user['display_name']) ?></strong>
                    <small><?= $e($user['role'] === 'superadmin' ? 'Kanalinhaber' : 'Team') ?></small>
                </div>
                <button class="btn btn-ghost" type="submit">Abmelden</button>
            </form>
        <?php endif; ?>
    </aside>

    <main class="content">
        <?= $content ?>
    </main>
</div>
</body>
</html>
