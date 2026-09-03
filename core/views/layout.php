<?php
/**
 * Rahmen der Adminoberflaeche.
 *
 * @var \TwitchController\Core\App $app
 * @var callable $e
 * @var callable $url
 * @var callable $asset
 * @var string $content
 * @var string $title
 * @var string $active
 */

// Defensiv: das Layout wird auch bei Fehlerseiten gerendert und darf
// nicht selbst an einer wegbrechenden Datenbank scheitern.
//
// Drei getrennte Absicherungen, nicht eine gemeinsame: sonst wuerde ein
// Fehler beim Kanalnamen auch die Navigation verschwinden lassen.
try {
    $user = $app->auth->user();
} catch (\Throwable) {
    $user = null;
}

try {
    $nav = (new \TwitchController\Core\Admin\Nav($app))->build();
} catch (\Throwable) {
    $nav = [];
}

try {
    $channel = $app->settings->string('twitch_broadcaster_login');
} catch (\Throwable) {
    $channel = '';
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e($title !== '' ? $title . ' · ' . \TwitchController\Core\App::NAME : \TwitchController\Core\App::NAME) ?></title>
    <link rel="stylesheet" href="<?= $e($asset('/assets/admin.css')) ?>">
    <?php $pluginAssets = $view->adminAssets(); ?>
    <?php foreach ($pluginAssets['css'] as $css): ?>
        <link rel="stylesheet" href="<?= $e($css) ?>">
    <?php endforeach ?>
</head>
<body>
<div class="shell">
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-dot"></span>
            <div>
                <strong><?= $e(\TwitchController\Core\App::NAME) ?></strong>
                <?php if ($channel !== ''): ?>
                    <small><?= $e($channel) ?></small>
                <?php endif; ?>
            </div>
        </div>

        <nav>
            <?php foreach ($nav as $key => $group): ?>
                <?php
                // Die Gruppe mit der aktuellen Seite ist immer offen -
                // man will sehen, wo man steht. Alle anderen behalten
                // den Zustand, den der Benutzer gesetzt hat.
                $enthaeltAktive = false;
                foreach ($group['items'] as $item) {
                    if ($active === $item['key']) {
                        $enthaeltAktive = true;
                    }
                }
                ?>
                <details class="nav-group" data-group="<?= $e((string) $key) ?>"
                    <?= $enthaeltAktive ? 'open data-current' : 'open' ?>>
                    <summary class="nav-label"><?= $e($group['label']) ?></summary>
                    <div class="nav-links">
                        <?php foreach ($group['items'] as $item): ?>
                            <a class="nav-item<?= $active === $item['key'] ? ' is-active' : '' ?>"
                               href="<?= $e($url($item['href'])) ?>"><?= $e($item['label']) ?></a>
                        <?php endforeach; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </nav>

        <?php if ($user !== null): ?>
            <form class="sidebar-foot" method="post" action="<?= $e($url('/logout')) ?>">
                <input type="hidden" name="csrf" value="<?= $e($app->auth->csrfToken()) ?>">
                <div class="who">
                    <strong><?= $e($user['display_name']) ?></strong>
                    <small><?= $e($user['role'] === 'superadmin' ? translate('nav.owner') : translate('nav.team')) ?></small>
                </div>
                <button class="btn btn-ghost" type="submit"><?= $e(translate('nav.sign_out')) ?></button>
            </form>
        <?php endif; ?>
    </aside>

    <main class="content">
        <?= $content ?>
    </main>
</div>

<script>
// Zugeklappte Gruppen merken. Die Gruppe der aktuellen Seite bleibt
// offen, damit man nach dem Navigieren nicht ins Leere schaut.
(function () {
    'use strict';

    var schluessel = 'ov_nav_zu';
    var zu = [];

    try {
        zu = JSON.parse(localStorage.getItem(schluessel) || '[]');
    } catch (e) {
        zu = [];
    }

    var gruppen = document.querySelectorAll('.nav-group');

    gruppen.forEach(function (gruppe) {
        if (!gruppe.hasAttribute('data-current') && zu.indexOf(gruppe.dataset.group) !== -1) {
            gruppe.open = false;
        }

        gruppe.addEventListener('toggle', function () {
            var name = gruppe.dataset.group;
            var index = zu.indexOf(name);

            if (gruppe.open && index !== -1) {
                zu.splice(index, 1);
            } else if (!gruppe.open && index === -1) {
                zu.push(name);
            }

            try {
                localStorage.setItem(schluessel, JSON.stringify(zu));
            } catch (e) {
                // Kein Speicher verfuegbar - dann eben nur fuer diese Sitzung.
            }
        });
    });
}());
</script>
<?php foreach ($pluginAssets['js'] as $js): ?>
    <script src="<?= $e($js) ?>"></script>
<?php endforeach ?>
</body>
</html>
