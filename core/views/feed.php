<?php
/**
 * Der Aktivitaeten-Feed (/obs). Eigenstaendige Seite ohne Adminrahmen,
 * damit sie als Browser-Dock in OBS taugt.
 *
 * Der Kopf ist absichtlich eine einzige Zeile: kein Titel, kein
 * Statustext, der Filter sitzt als Knopf mit drin. In einem schmalen
 * Dock ist jede gesparte Zeile eine Zeile mehr Feed.
 *
 * @var \Overlays\Core\App $app
 * @var callable $e
 * @var callable $url
 * @var list<array<string, mixed>> $events
 * @var int $latest
 * @var int $total
 * @var list<array{key: string, label: string, children: list<array<string, mixed>>}> $tree
 * @var list<string> $leaves
 * @var list<string> $selected
 * @var bool $allSelected
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
    $params = array_merge($query, $changes);
    $params = array_filter($params, static fn (mixed $v): bool => $v !== '' && $v !== null);

    return $url('/obs') . ($params === [] ? '' : '?' . http_build_query($params));
};

/** Filterbaum als verschachtelte Liste. */
$zweig = static function (array $knoten, int $tiefe) use (&$zweig, $e, $selected): string {
    $html = '<ul class="tree' . ($tiefe > 0 ? ' is-nested' : '') . '">';

    foreach ($knoten as $node) {
        $hatKinder = $node['children'] !== [];
        $angehakt = !$hatKinder && in_array($node['key'], $selected, true);

        $html .= '<li>';
        $html .= '<label>';
        $html .= '<input type="checkbox" data-key="' . $e($node['key']) . '"';
        $html .= $hatKinder ? ' data-parent="1"' : ' name="filter[]" value="' . $e($node['key']) . '"';
        $html .= $angehakt ? ' checked' : '';
        $html .= '>';
        $html .= '<span>' . $e($node['label']) . '</span>';
        $html .= '</label>';

        if ($hatKinder) {
            $html .= $zweig($node['children'], $tiefe + 1);
        }

        $html .= '</li>';
    }

    return $html . '</ul>';
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
            --ok: #3ecf8e;
            --error: #ef4d4d;
            color-scheme: dark;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: var(--bg);
            color: var(--ink);
            font: 14px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        /* --- Kopf: genau eine Zeile ------------------------------------ */

        header {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            gap: 6px;
            align-items: center;
            padding: 6px 8px;
            background: var(--panel);
            border-bottom: 1px solid var(--line);
        }

        header .grow { flex: 1; }

        select, .btn, summary.btn {
            padding: 4px 9px;
            background: var(--bg);
            border: 1px solid var(--line);
            border-radius: 7px;
            color: var(--ink);
            font: inherit;
            font-size: 0.84rem;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
        }

        .btn:hover, select:hover, summary.btn:hover { border-color: var(--accent); }
        .btn.is-on, summary.btn.is-on { background: rgba(145, 70, 255, 0.18); border-color: var(--accent); }

        /* Verbindungsanzeige als Punkt statt als Textzeile. */
        .dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: var(--ok);
            flex: none;
        }

        .dot.is-off { background: var(--muted); }
        .dot.is-error { background: var(--error); }

        /* --- Filter als Klappfeld im Kopf ------------------------------ */

        details.filter { position: relative; }
        details.filter summary { list-style: none; }
        details.filter summary::-webkit-details-marker { display: none; }

        .filter-panel {
            position: absolute;
            top: calc(100% + 6px);
            left: 0;
            z-index: 30;
            width: max(260px, 80vw);
            max-width: 420px;
            max-height: 70vh;
            overflow: auto;
            padding: 12px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 10px;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.45);
        }

        ul.tree { list-style: none; margin: 0; padding: 0; }
        ul.tree.is-nested { padding-left: 18px; }
        ul.tree li { margin: 1px 0; }

        ul.tree label {
            display: flex;
            gap: 7px;
            align-items: center;
            padding: 2px 0;
            font-size: 0.88rem;
            cursor: pointer;
        }

        /* Elternknoten etwas hervorheben, damit die Ebene erkennbar ist. */
        ul.tree > li > label > span { font-weight: 500; }
        ul.tree.is-nested > li > label > span { font-weight: 400; }

        .panel-actions {
            display: flex;
            gap: 6px;
            align-items: center;
            margin-top: 12px;
            padding-top: 10px;
            border-top: 1px solid var(--line);
        }

        /* --- Liste ----------------------------------------------------- */

        main { padding: 8px; }

        .event {
            display: flex;
            gap: 10px;
            align-items: baseline;
            padding: 7px 10px;
            background: var(--panel);
            border: 1px solid var(--line);
            border-radius: 9px;
            margin-bottom: 5px;
        }

        .event.is-new { animation: einblenden 0.9s ease-out; }

        @keyframes einblenden {
            from { background: var(--panel-2); transform: translateY(-3px); }
            to   { background: var(--panel); transform: none; }
        }

        .event time {
            color: var(--muted);
            font-size: 0.79rem;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.77rem;
            font-weight: 600;
            white-space: nowrap;
            background: var(--panel-2);
            color: var(--muted);
        }

        .who { font-weight: 600; }
        .msg { color: var(--muted); flex: 1; word-break: break-word; }

        /* --- Kompakt fuer schmale Docks -------------------------------- */

        body.is-compact main { padding: 4px; }
        body.is-compact .event {
            padding: 4px 7px;
            margin-bottom: 2px;
            border-radius: 6px;
            gap: 7px;
        }
        body.is-compact .msg { display: none; }
        body.is-compact time { font-size: 0.73rem; }

        .empty { padding: 36px 16px; text-align: center; color: var(--muted); }

        .pager {
            display: flex;
            gap: 8px;
            align-items: center;
            margin-top: 12px;
            color: var(--muted);
            font-size: 0.84rem;
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
    <details class="filter" id="filter">
        <summary class="btn <?= $allSelected ? '' : 'is-on' ?>" title="Filter">
            Filter<?= $allSelected ? '' : ' (' . $e((string) count($selected)) . ')' ?>
        </summary>

        <div class="filter-panel">
            <form method="get" action="<?= $e($url('/obs')) ?>" id="filter-form">
                <input type="hidden" name="zeitraum" value="<?= $e($range) ?>">
                <?php if ($compact): ?>
                    <input type="hidden" name="kompakt" value="1">
                <?php endif; ?>

                <?= $zweig($tree, 0) ?>

                <div class="panel-actions">
                    <button class="btn" type="submit">Anwenden</button>
                    <button class="btn" type="button" data-all>Alles</button>
                    <button class="btn" type="button" data-none>Nichts</button>
                </div>
            </form>
        </div>
    </details>

    <select onchange="location.href=this.value" title="Zeitraum">
        <?php foreach ($ranges as $key => $option): ?>
            <option value="<?= $e($link(['zeitraum' => $key, 'seite' => null])) ?>"
                <?= $range === $key ? 'selected' : '' ?>><?= $e($option['label']) ?></option>
        <?php endforeach; ?>
    </select>

    <a class="btn <?= $compact ? 'is-on' : '' ?>"
       href="<?= $e($link(['kompakt' => $compact ? null : '1'])) ?>" title="Kompakte Ansicht">Kompakt</a>

    <span class="grow"></span>

    <?php if ($refresh > 0): ?>
        <span class="dot" id="dot" title="Verbindung"></span>
        <button class="btn is-on" id="pause" type="button" title="Nachladen anhalten">Pause</button>
    <?php endif; ?>
</header>

<main>
    <div id="liste">
        <?php if ($events === []): ?>
            <div class="empty">
                <?php if ($selected === []): ?>
                    Alles abgewählt &mdash; im Filter etwas anhaken.
                <?php else: ?>
                    Keine Aktivitäten in diesem Zeitraum.<br>
                    Sobald Twitch etwas schickt, erscheint es hier von selbst.
                <?php endif; ?>
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
            <span>Seite <?= $e((string) $page) ?> von <?= $e((string) $pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn" href="<?= $e($link(['seite' => $page + 1])) ?>">Älter</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</main>

<script>
(function () {
    'use strict';

    var ziel = <?= json_encode($url('/obs')) ?>;
    var alleBlaetter = <?= json_encode($leaves) ?>;

    // ---- Filterbaum: Eltern schalten ihre Kinder -------------------

    var form = document.getElementById('filter-form');

    function kinderVon(li) {
        return Array.prototype.slice.call(li.querySelectorAll('input[type=checkbox]'))
            .filter(function (box) { return !box.hasAttribute('data-parent'); });
    }

    function elternAktualisieren() {
        // Von innen nach aussen, damit verschachtelte Eltern stimmen.
        var eltern = Array.prototype.slice.call(form.querySelectorAll('input[data-parent]')).reverse();

        eltern.forEach(function (box) {
            var blaetter = kinderVon(box.closest('li'));
            var an = blaetter.filter(function (b) { return b.checked; }).length;

            box.checked = an === blaetter.length && an > 0;
            box.indeterminate = an > 0 && an < blaetter.length;
        });
    }

    if (form) {
        form.addEventListener('change', function (event) {
            var box = event.target;
            if (box.type !== 'checkbox') { return; }

            if (box.hasAttribute('data-parent')) {
                kinderVon(box.closest('li')).forEach(function (kind) {
                    kind.checked = box.checked;
                });
            }

            elternAktualisieren();
        });

        form.querySelector('[data-all]').addEventListener('click', function () {
            form.querySelectorAll('input[type=checkbox]').forEach(function (box) {
                box.checked = true;
                box.indeterminate = false;
            });
        });

        form.querySelector('[data-none]').addEventListener('click', function () {
            form.querySelectorAll('input[type=checkbox]').forEach(function (box) {
                box.checked = false;
                box.indeterminate = false;
            });
        });

        // Auswahl zu einem kurzen Parameter zusammenfassen. Ist alles
        // angehakt, kommt gar kein Parameter in die Adresse - dann
        // bleibt der Link auch gueltig, wenn spaeter neue Arten
        // dazukommen.
        form.addEventListener('submit', function (event) {
            event.preventDefault();

            var gewaehlt = Array.prototype.slice
                .call(form.querySelectorAll('input[name="filter[]"]:checked'))
                .map(function (box) { return box.value; });

            var params = new URLSearchParams();
            params.set('zeitraum', form.querySelector('[name=zeitraum]').value);
            if (form.querySelector('[name=kompakt]')) { params.set('kompakt', '1'); }

            if (gewaehlt.length !== alleBlaetter.length) {
                params.set('filter', gewaehlt.join(','));
            }

            location.href = ziel + '?' + params.toString();
        });

        elternAktualisieren();
    }

    // Klick daneben schliesst das Klappfeld.
    var klappfeld = document.getElementById('filter');
    document.addEventListener('click', function (event) {
        if (klappfeld.open && !klappfeld.contains(event.target)) {
            klappfeld.open = false;
        }
    });

    // ---- Nachladen -------------------------------------------------

    var refresh = <?= (int) $refresh ?>;
    if (!refresh) { return; }

    var liste = document.getElementById('liste');
    var dot = document.getElementById('dot');
    var pause = document.getElementById('pause');
    var latest = <?= (int) $latest ?>;
    var laeuft = true;
    var timer = null;

    var quelle = <?= json_encode($url('/obs/neu')) ?>
        + '?zeitraum=' + encodeURIComponent(<?= json_encode($range) ?>)
        + <?= $allSelected ? "''" : "'&filter=' + encodeURIComponent(" . json_encode(implode(',', $selected)) . ")" ?>;

    function zustand(klasse, titel) {
        if (!dot) { return; }
        dot.className = 'dot' + (klasse ? ' ' + klasse : '');
        dot.title = titel;
    }

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
                zustand('', 'verbunden');

                if (typeof daten.latest === 'number' && daten.latest > latest) {
                    latest = daten.latest;
                }

                if (!daten.events || !daten.events.length) { return; }

                var leer = liste.querySelector('.empty');
                if (leer) { leer.remove(); }

                // Antwort ist neueste zuerst - von hinten einfuegen,
                // damit die Reihenfolge oben stimmt.
                for (var i = daten.events.length - 1; i >= 0; i--) {
                    liste.insertBefore(zeile(daten.events[i]), liste.firstChild);
                }

                while (liste.children.length > 400) {
                    liste.removeChild(liste.lastChild);
                }
            })
            .catch(function (fehler) {
                zustand('is-error', 'Verbindung gestört: ' + fehler.message);
            });
    }

    function starten() { timer = setInterval(holen, refresh * 1000); }

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
                zustand('is-off', 'angehalten');
            }
        });
    }

    starten();
}());
</script>
</body>
</html>
