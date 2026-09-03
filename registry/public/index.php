<?php

declare(strict_types=1);

/**
 * Plugin-Katalog.
 *
 * Die eigentliche Schnittstelle ist die statische Datei index.json, die
 * bin/build.php erzeugt - der Client braucht nur die. Dieses Skript ist
 * die Bequemlichkeit obendrauf:
 *
 *   GET /                      Uebersicht im Browser
 *   GET /index.json            der Katalog (liefert Apache direkt aus)
 *   GET /api/plugins           alle, optional ?q=suchbegriff&tag=name
 *   GET /api/plugins/<slug>    ein Plugin
 *   GET /pkg/<datei>.zip       das Paket (liefert Apache direkt aus)
 */

$catalogFile = __DIR__ . '/index.json';

/**
 * @return array{format: int, generated_at: string, plugins: list<array<string, mixed>>}
 */
function loadCatalog(string $file): array
{
    if (!is_file($file)) {
        return ['format' => 1, 'generated_at' => '', 'plugins' => []];
    }

    $decoded = json_decode((string) file_get_contents($file), true);
    if (!is_array($decoded)) {
        return ['format' => 1, 'generated_at' => '', 'plugins' => []];
    }

    $decoded['plugins'] = is_array($decoded['plugins'] ?? null) ? $decoded['plugins'] : [];

    return $decoded;
}

/**
 * @param array<string, mixed>|list<mixed> $data
 */
function sendJson(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=300');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Kleinschreibung, notfalls ohne mbstring - der Katalog soll auch auf
 * einer sparsam gebauten PHP-Installation laufen.
 */
function lower(string $text): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($text) : strtolower($text);
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Dieselbe Suche wie im Client, damit beide Wege gleich antworten.
 *
 * @param list<array<string, mixed>> $plugins
 * @return list<array<string, mixed>>
 */
function searchPlugins(array $plugins, string $query, string $tag): array
{
    if ($tag !== '') {
        $plugins = array_values(array_filter(
            $plugins,
            static fn (array $p): bool => in_array($tag, (array) ($p['tags'] ?? []), true)
        ));
    }

    $query = trim($query);
    if ($query === '') {
        return $plugins;
    }

    $words = array_filter(preg_split('/\s+/', lower($query)) ?: []);

    return array_values(array_filter($plugins, static function (array $plugin) use ($words): bool {
        $haystack = lower(implode(' ', [
            (string) ($plugin['slug'] ?? ''),
            (string) ($plugin['name'] ?? ''),
            (string) ($plugin['summary'] ?? ''),
            (string) ($plugin['author'] ?? ''),
            implode(' ', (array) ($plugin['tags'] ?? [])),
        ]));

        foreach ($words as $word) {
            if (!str_contains($haystack, $word)) {
                return false;
            }
        }

        return true;
    }));
}

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$path = '/' . trim($path, '/');

$catalog = loadCatalog($catalogFile);

// --- Schnittstelle --------------------------------------------------

// Der Katalog.
//
// /index.php ist die Adresse, die der Client abfragt: sie funktioniert
// immer, auch ohne mod_rewrite und ohne dass vorher bin/build.php
// gelaufen ist. /index.json liefert dasselbe - als Datei direkt vom
// Webserver, falls sie erzeugt wurde, sonst hier.
if ($path === '/index.php' || $path === '/index.json') {
    sendJson($catalog);
}

if ($path === '/api/plugins') {
    $plugins = searchPlugins(
        $catalog['plugins'],
        (string) ($_GET['q'] ?? ''),
        (string) ($_GET['tag'] ?? '')
    );

    sendJson([
        'format'       => 1,
        'generated_at' => $catalog['generated_at'],
        'count'        => count($plugins),
        'plugins'      => $plugins,
    ]);
}

if (preg_match('#^/api/plugins/([a-z0-9][a-z0-9\-]{1,38}[a-z0-9])$#', $path, $matches) === 1) {
    foreach ($catalog['plugins'] as $plugin) {
        if (($plugin['slug'] ?? '') === $matches[1]) {
            sendJson($plugin);
        }
    }

    sendJson(['error' => 'not_found', 'message' => 'Dieses Plugin gibt es hier nicht.'], 404);
}

// --- Uebersicht im Browser ------------------------------------------

if ($path !== '/') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Nicht gefunden.\n";
    exit;
}

$tags = [];
foreach ($catalog['plugins'] as $plugin) {
    foreach ((array) ($plugin['tags'] ?? []) as $tag) {
        $tags[(string) $tag] = true;
    }
}
ksort($tags);
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Plugin-Katalog</title>
    <style>
        :root { color-scheme: dark; }
        body {
            margin: 0; background: #0e1014; color: #e9ecf1;
            font: 15px/1.55 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }
        main { width: min(820px, calc(100% - 32px)); margin: 48px auto; }
        h1 { font-size: 1.6rem; margin: 0 0 6px; }
        .lead { color: #98a1b0; margin: 0 0 28px; }
        .card {
            background: #16191f; border: 1px solid #272c36; border-radius: 12px;
            padding: 18px; margin-bottom: 14px;
        }
        .head { display: flex; justify-content: space-between; gap: 14px; align-items: baseline; }
        .name { font-size: 1.05rem; font-weight: 600; }
        .meta { color: #98a1b0; font-size: 0.86rem; }
        .tag {
            display: inline-block; padding: 2px 9px; border-radius: 999px;
            background: #1d2129; color: #98a1b0; font-size: 0.76rem; margin-right: 5px;
        }
        code {
            background: #0e1014; border: 1px solid #272c36; border-radius: 5px;
            padding: 1px 5px; font-size: 0.88em;
        }
        a { color: #b794ff; }
        .empty { padding: 30px; text-align: center; color: #98a1b0; }
        footer { margin-top: 34px; color: #98a1b0; font-size: 0.86rem; }
    </style>
</head>
<body>
<main>
    <h1>Plugin-Katalog</h1>
    <p class="lead">
        <?= count($catalog['plugins']) ?> Plugin<?= count($catalog['plugins']) === 1 ? '' : 's' ?>
        <?php if (($catalog['generated_at'] ?? '') !== ''): ?>
            &middot; Stand <?= h(date('d.m.Y H:i', strtotime((string) $catalog['generated_at']) ?: time())) ?>
        <?php endif; ?>
    </p>

    <?php if ($catalog['plugins'] === []): ?>
        <div class="card">
            <div class="empty">
                Noch keine Pakete.<br>
                ZIPs nach <code>public/pkg/</code> legen und <code>php bin/build.php</code> ausführen.
            </div>
        </div>
    <?php endif; ?>

    <?php foreach ($catalog['plugins'] as $plugin): ?>
        <div class="card">
            <div class="head">
                <span class="name"><?= h((string) ($plugin['name'] ?? '')) ?></span>
                <span class="meta">
                    <?= h((string) ($plugin['version'] ?? '')) ?>
                    <?php if (($plugin['size'] ?? 0) > 0): ?>
                        &middot; <?= (int) round(((int) $plugin['size']) / 1024) ?> KB
                    <?php endif; ?>
                </span>
            </div>

            <?php if (($plugin['summary'] ?? '') !== ''): ?>
                <p style="margin:8px 0 0;"><?= h((string) $plugin['summary']) ?></p>
            <?php endif; ?>

            <p class="meta" style="margin:10px 0 0;">
                <code><?= h((string) ($plugin['slug'] ?? '')) ?></code>
                <?php if (($plugin['author'] ?? '') !== ''): ?>
                    &middot; <?= h((string) $plugin['author']) ?>
                <?php endif; ?>
                &middot; <a href="/api/plugins/<?= h((string) ($plugin['slug'] ?? '')) ?>">JSON</a>
            </p>

            <?php if (($plugin['tags'] ?? []) !== []): ?>
                <p style="margin:10px 0 0;">
                    <?php foreach ((array) $plugin['tags'] as $tag): ?>
                        <span class="tag"><?= h((string) $tag) ?></span>
                    <?php endforeach; ?>
                </p>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <footer>
        Schnittstelle: <a href="/index.json">/index.json</a> &middot;
        <a href="/api/plugins">/api/plugins</a>
    </footer>
</main>
</body>
</html>
