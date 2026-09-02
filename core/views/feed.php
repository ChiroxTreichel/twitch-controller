<?php
/**
 * Der Aktivitaeten-Feed. Eigenstaendige Seite ohne Adminrahmen, damit
 * sie als schmales Fenster oder als Browser-Dock in OBS taugt.
 *
 * @var \Overlays\Core\App $app
 * @var callable $e
 * @var callable $url
 * @var list<array<string, mixed>> $events
 * @var int $latest
 * @var int $total
 * @var array<string, array{label: string, order: int, items: array<string, string>}> $groups
 * @var list<string> $selected
 * @var array<string, array{label: string, interval: ?string}> $ranges
 * @var string $range
 * @var int $limit
 * @var int $page
 * @var int $pages
 * @var int $refresh
 * @var bool $compact
 * @var array<string, array{label: string, bg: string, text: string}> $badges
 * @var array<string, mixed> $query
 */

/** Adresse mit geänderten Parametern, alles andere bleibt stehen. */
$link = static function (array $changes) use ($url, $query): string {
    $params = array_filter(
        array_merge($query, $changes),
        static fn (mixed $v): bool => $v !== '' && $v !== null
    );
    unset($params['format']);

    return $url('/aktivitaeten') . ($params === [] ? '' : '?' . http_build_query($params));
};
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Aktivitäten</title>
    <style>
        :root {
            --bg: #0e1014;
            --panel: #16191f;
            --panel-2: #1d2129;
            --line: #272c36;
            --ink: #e9ecf1;
            --muted: #98a1b0;
            --accent: #9146ff;
            color-scheme: dark;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* --- Kopf --- */

        header {
            position: sticky;
            top: 0;
            z-index: 5;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
            padding: 10px 14px;
            background: var(--panel);
            border-bottom: 1px solid var(--line);
        }

        header .grow { flex: 1; }

        .title { font-weight: 600; }
        .muted { color: var(--muted); font-size: 0.85rem; }

        select, .btn {
            padding: 6px 10px;
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 8px;
            color: var(--ink);
            font: inherit;
            font-size: 0.86rem;
            cursor: pointer;
            text-decoration: none;
        }

        .btn:hover, select:hover { border-color: var(--accent); }
        .btn.is-on { background: rgba(145, 70, 255, 0.18); border-color: var(--accent); }

        /* --- Filter --- */

        details.filters {
            padding: 0 14px 10px;
            background: var(--panel);
            border-bottom: 1px solid var(--line);
        }

        details.filters summary {
            cursor: pointer;
            color: var(--muted);
            font-size: 0.86rem;
            padding: 8px 0;
        }

        .filter-groups {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 12px;
            padding-bottom: 10px;
        }

        .filter-group strong {
            display: block;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--muted);
            margin-bottom: 5px;
        }

        .filter-group label {
            display: flex;
            gap: 7px;
            align-items: center;
            padding: 2px 0;
            font-size: 0.88rem;
        }

        /* --- Liste --- */

        main { padding: 12px 14px 40px; }

        .event {
            display: flex;
            gap: 12px;
            align-items: baseline;
            padding: 9px 12px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 10px;
            margin-bottom: 7px;
        }

        .event.is-new { animation: einblenden 0.9s ease-out; }

        @keyframes einblenden {
            from { background: var(--panel-2); transform: translateY(-3px); }
            to   { background: var(--panel); transform: none; }
        }

        .event time {
            color: var(--muted);
            font-size: 0.8rem;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .badge {
            display: inline-block;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 600;
            white-space: nowrap;
            background: var(--panel-2);
            color: var(--muted);
        }

        .who { font-weight: 600; }
        .msg { color: var(--muted); flex: 1; word-break: break-word; }

        /* --- Kompakte Ansicht fuer schmale Docks --- */

        body.is-compact main { padding: 6px 8px 20px; }
        body.is-compact .event {
            padding: 5px 8px;
            margin-bottom: 3px;
            border-radius: 7px;
            gap: 8px;
        }
        body.is-compact .msg { display: none; }
        body.is-compact time { font-size: 0.74rem; }

        .empty { padding: 40px 20px; text-align: center; color: var(--muted); }

        .pager {
            display: flex;
            gap: 10px;
            align-items: center;
            margin-top: 16px;
        }

        @media (max-width: 620px) {
            .msg { display: none; }
        }
<?php foreach ($badges as $key => $badge): ?>
        .badge-<?= $e($key) ?> { background: <?= $e($badge['bg']) ?>; color: <?= $e($badge['text']) ?>; }
<?php endforeach; ?>
    </style>
</head>
<body class="<?= $compact ? 'is-compact' : '' ?>">

<header>
    <span class="title">Aktivitäten</span>
    <span class="muted" id="status"><?= $e((string) $total) ?> im Zeitraum</span>
    <span class="grow"></span>

    <select onchange="location.href=this.value">
        <?php foreach ($ranges as $key => $option): ?>
            <option value="<?= $e($link(['zeitraum' => $key, 'seite' => null])) ?>"
                <?= $range === $key ? 'selected' : '' ?>><?= $e($option['label']) ?></option>
        <?php endforeach; ?>
    </select>

    <a class="btn <?= $compact ? 'is-on' : '' ?>"
       href="<?= $e($link(['kompakt' => $compact ? null : '1'])) ?>">Kompakt</a>

    <?php if ($refresh > 0): ?>
        <button class="btn is-on" id="pause" type="button">Pause</button>
    <?php endif; ?>
</header>

<details class="filters" <?= $selected !== [] ? 'open' : '' ?>>
    <summary>
        Filter<?= $selected !== [] ? ' (' . $e((string) count($selected)) . ' aktiv)' : '' ?>
    </summary>

    <form method="get" action="<?= $e($url('/aktivitaeten')) ?>">
        <input type="hidden" name="zeitraum" value="<?= $e($range) ?>">
        <?php if ($compact): ?>
            <input type="hidden" name="kompakt" value="1">
        <?php endif; ?>

        <div class="filter-groups">
            <?php foreach ($groups as $group): ?>
                <div class="filter-group">
                    <strong><?= $e($group['label']) ?></strong>
                    <?php foreach ($group['items'] as $key => $label): ?>
                        <label>
                            <input type="checkbox" name="fk[]" value="<?= $e((string) $key) ?>"
                                <?= in_array((string) $key, $selected, true) ? 'checked' : '' ?>>
                            <span><?= $e($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="pager" style="margin:0 0 8px;">
            <button class="btn" type="submit">Anwenden</button>
            <?php if ($selected !== []): ?>
                <a class="btn" href="<?= $e($link(['f' => null, 'seite' => null])) ?>">Alles zeigen</a>
            <?php endif; ?>
            <span class="muted">Nichts angehakt heißt: alles zeigen.</span>
        </div>
    </form>
</details>

<main>
    <div id="liste">
        <?php if ($events === []): ?>
            <div class="empty">
                Keine Aktivitäten in diesem Zeitraum.<br>
                <span class="muted">Sobald Twitch etwas schickt, erscheint es hier von selbst.</span>
            </div>
        <?php endif; ?>

        <?php foreach ($events as $event): ?>
            <div class="event">
                <time><?= $e($event['time']) ?></time>
                <span class="badge badge-<?= $e($event['style']) ?>"><?= $e($event['badge']) ?></span>
                <span class="who"><?= $e($event['title']) ?></span>
                <?php if ($event['message'] !== ''): ?>
                    <span class="msg"><?= $e($event['message']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
        <div class="pager">
            <?php if ($page > 1): ?>
                <a class="btn" href="<?= $e($link(['seite' => $page - 1])) ?>">Neuer</a>
            <?php endif; ?>
            <span class="muted">Seite <?= $e((string) $page) ?> von <?= $e((string) $pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn" href="<?= $e($link(['seite' => $page + 1])) ?>">Älter</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<script>
(function () {
    'use strict';

    // Checkboxen zu einem einzigen Parameter "f" zusammenfassen - das
    // haelt die Adresse kurz und teilbar.
    var form = document.querySelector('form');
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var keys = Array.prototype.slice
                .call(form.querySelectorAll('input[name="fk[]"]:checked'))
                .map(function (box) { return box.value; });

            var params = new URLSearchParams();
            params.set('zeitraum', form.querySelector('[name="zeitraum"]').value);
            if (keys.length) { params.set('f', keys.join(',')); }
            if (form.querySelector('[name="kompakt"]')) { params.set('kompakt', '1'); }

            location.href = <?= json_encode($url('/aktivitaeten')) ?> + '?' + params.toString();
        });
    }

    var refresh = <?= (int) $refresh ?>;
    if (!refresh) { return; }

    var liste = document.getElementById('liste');
    var status = document.getElementById('status');
    var pause = document.getElementById('pause');
    var latest = <?= (int) $latest ?>;
    var laeuft = true;
    var timer = null;

    var quelle = <?= json_encode($url('/aktivitaeten/neu')) ?>
        + '?zeitraum=' + encodeURIComponent(<?= json_encode($range) ?>)
        + (<?= json_encode(implode(',', $selected)) ?> ? '&f=' + encodeURIComponent(<?= json_encode(implode(',', $selected)) ?>) : '');

    function zeile(event) {
        var el = document.createElement('div');
        el.className = 'event is-new';

        var zeit = document.createElement('time');
        zeit.textContent = event.time;

        var badge = document.createElement('span');
        badge.className = 'badge badge-' + event.style;
        badge.textContent = event.badge;

        var wer = document.createElement('span');
        wer.className = 'who';
        wer.textContent = event.title;

        el.appendChild(zeit);
        el.appendChild(badge);
        el.appendChild(wer);

        if (event.message) {
            var msg = document.createElement('span');
            msg.className = 'msg';
            msg.textContent = event.message;
            el.appendChild(msg);
        }

        return el;
    }

    function holen() {
        fetch(quelle + '&since_id=' + latest, { credentials: 'same-origin' })
            .then(function (antwort) {
                if (!antwort.ok) { throw new Error('Status ' + antwort.status); }
                return antwort.json();
            })
            .then(function (daten) {
                if (typeof daten.latest === 'number' && daten.latest > latest) {
                    latest = daten.latest;
                }

                if (!daten.events || !daten.events.length) { return; }

                // Antwort ist neueste zuerst - von hinten einfuegen,
                // damit die Reihenfolge oben stimmt.
                var leer = liste.querySelector('.empty');
                if (leer) { leer.remove(); }

                for (var i = daten.events.length - 1; i >= 0; i--) {
                    liste.insertBefore(zeile(daten.events[i]), liste.firstChild);
                }

                // Nicht unbegrenzt wachsen lassen.
                while (liste.children.length > 400) {
                    liste.removeChild(liste.lastChild);
                }

                if (status) { status.textContent = 'aktualisiert ' + new Date().toLocaleTimeString('de-DE'); }
            })
            .catch(function (fehler) {
                if (status) { status.textContent = 'Verbindung gestört: ' + fehler.message; }
            });
    }

    function starten() {
        timer = setInterval(holen, refresh * 1000);
    }

    if (pause) {
        pause.addEventListener('click', function () {
            laeuft = !laeuft;
            pause.textContent = laeuft ? 'Pause' : 'Weiter';
            pause.classList.toggle('is-on', laeuft);

            if (laeuft) {
                starten();
                holen();
            } else {
                clearInterval(timer);
                if (status) { status.textContent = 'angehalten'; }
            }
        });
    }

    starten();
}());
</script>
</body>
</html>
