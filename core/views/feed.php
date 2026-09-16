<?php
/**
 * Der Aktivitaeten-Feed (/obs). Eigenstaendige Seite ohne Adminrahmen:
 * gedacht als eigenes Dock in OBS.
 *
 * Aussehen und Bedienung kommen aus dem alten System und sind von dort
 * uebernommen - Karten statt Zeilen, durchsichtiger Grund,
 * Filter-Popover mit Aufklapp-Navigation, Nachladen beim Scrollen.
 *
 * Der durchsichtige Grund ist Absicht und kein Versehen: das Dock
 * liegt auf OBS' eigener Flaeche, und die bringt ihre Farbe mit.
 *
 * @var \TwitchController\Core\App $app
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
?>
<!doctype html>
<html lang="<?= $e($language) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $e(translate('nav.activity_title')) ?></title>
    <style>
        /* -----------------------------------------------------------
         *  Die Farben und Masse des alten Systems, Wert fuer Wert.
         * ----------------------------------------------------------- */
        :root {
            color-scheme: dark;
            --bg: rgba(8, 10, 18, 0.82);
            --panel: rgba(18, 22, 36, 0.88);
            --border: rgba(255, 255, 255, 0.08);
            --text: #f4f7fb;
            --muted: #95a0b8;
            --accent: #b06cff;
            --badge: rgba(176, 108, 255, 0.18);
            --button: rgba(255, 255, 255, 0.08);
            --button-hover: rgba(255, 255, 255, 0.14);
        }

        * { box-sizing: border-box; }

        html, body {
            margin: 0;
            min-height: 100%;
            background: transparent;
            color: var(--text);
            font-family: "Trebuchet MS", "Segoe UI", sans-serif;
            overflow-x: hidden;
            scrollbar-width: none;
            -ms-overflow-style: none;
        }

        html::-webkit-scrollbar,
        body::-webkit-scrollbar { display: none; }

        body {
            width: 100vw;
            min-height: 100vh;
            padding: 18px;
        }

        .feed {
            width: 100%;
            display: grid;
            gap: 12px;
        }

        /* --- Kopf ---------------------------------------------------- */

        .feed-head {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
        }

        .feed-head-left,
        .feed-head-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .control-button {
            appearance: none;
            border: 0;
            border-radius: 999px;
            background: var(--button);
            color: var(--text);
            padding: 10px 14px;
            font-size: 14px;
            line-height: 1;
            cursor: pointer;
            transition: background 0.15s ease;

            /* "Kompakt" ist ein Link und kein Knopf - der Zustand steht
               in der Adresse, damit er ein Lesezeichen ueberlebt. Ohne
               die naechsten beiden Zeilen saehe er als einziger
               unterstrichen aus. */
            text-decoration: none;
            display: inline-block;
        }

        /* Eingeschaltet: dieselbe Faerbung wie die angehakten Zeilen im
           Filter, damit man den Zustand sieht, ohne ihn zu erraten. */
        .control-button.is-on {
            background: rgba(176, 108, 255, 0.35);
        }

        .control-button:hover { background: var(--button-hover); }

        /* --- Der Filter ---------------------------------------------- */

        .filter-menu { position: relative; }

        .filter-menu > summary { list-style: none; }
        .filter-menu > summary::-webkit-details-marker { display: none; }

        .filter-popover {
            position: absolute;
            top: calc(100% + 10px);
            left: 0;
            z-index: 10;
            min-width: 260px;
            max-width: 320px;
            padding: 10px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 16px;
            backdrop-filter: blur(10px);
        }

        .filter-range {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 6px 4px 10px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 8px;
            font-size: 13px;
            color: var(--muted);
        }

        .filter-range label {
            font-size: 12px;
            letter-spacing: 0.04em;
            text-transform: uppercase;
            flex-shrink: 0;
        }

        .control-select {
            flex: 1;
            appearance: none;
            background-color: rgba(20, 23, 36, 0.95);
            color: var(--text);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 6px 28px 6px 10px;
            font: inherit;
            cursor: pointer;
            background-image: url("data:image/svg+xml;charset=UTF-8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6'><path fill='%2395a0b8' d='M0 0l5 6 5-6z'/></svg>");
            background-repeat: no-repeat;
            background-position: right 10px center;
        }

        .control-select option { background-color: #1a1d2c; color: var(--text); }
        .control-select option:checked { background-color: rgba(176, 108, 255, 0.35); }

        .filter-nav {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 4px 4px 8px;
            border-bottom: 1px solid var(--border);
            margin-bottom: 8px;
        }

        .filter-back {
            appearance: none;
            background: transparent;
            border: 0;
            color: var(--text);
            font-size: 22px;
            line-height: 1;
            padding: 4px 8px;
            cursor: pointer;
            border-radius: 8px;
        }

        .filter-back:hover { background: var(--button); }
        .filter-back[hidden] { display: none; }

        .filter-breadcrumb {
            flex: 1;
            font-size: 14px;
            font-weight: 600;
            color: var(--muted);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .filter-items {
            display: grid;
            gap: 4px;
            max-height: 60vh;
            overflow-y: auto;
        }

        .filter-row {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px 12px;
            border-radius: 10px;
            background: transparent;
            color: var(--text);
            font-size: 14px;
            cursor: pointer;
            border: 0;
            width: 100%;
            text-align: left;
            appearance: none;
        }

        .filter-row:hover { background: var(--button); }

        .filter-row input[type="checkbox"] {
            margin: 0;
            width: 16px;
            height: 16px;
            flex-shrink: 0;
        }

        .filter-row .filter-label { flex: 1; }

        .filter-row .filter-count {
            font-size: 12px;
            color: var(--muted);
            background: rgba(255, 255, 255, 0.06);
            padding: 2px 8px;
            border-radius: 999px;
        }

        .filter-row .filter-arrow { font-size: 16px; color: var(--muted); }

        .filter-actions {
            display: flex;
            gap: 6px;
            margin-top: 10px;
            padding-top: 8px;
            border-top: 1px solid var(--border);
        }

        .filter-bulk {
            flex: 1;
            appearance: none;
            background: var(--button);
            border: 1px solid var(--border);
            color: var(--text);
            padding: 6px 10px;
            font-size: 12px;
            border-radius: 8px;
            cursor: pointer;
        }

        .filter-bulk:hover { background: var(--button-hover); }

        /* --- Meldungen ----------------------------------------------- */

        .feed-empty,
        .feed-error {
            padding: 14px 16px;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 16px;
        }

        .feed-error {
            border-color: rgba(255, 96, 96, 0.35);
            color: #ffd4d4;
        }

        /* --- Die Ereigniskarten -------------------------------------- */

        #event-list {
            gap: 10px;
            display: flex;
            flex-direction: column;
        }

        .event-card {
            padding: 14px 16px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 16px;
            backdrop-filter: blur(8px);
            max-width: calc(100vw - 21px);
        }

        .event-card.is-compact .event-bottom { display: none; }

        .event-top {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 10px;
        }

        .event-main {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: center;
        }

        .event-badge {
            display: inline-flex;
            align-items: center;
            padding: 6px 10px;
            border-radius: 999px;
            background: var(--badge);
            color: #ead8ff;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0.02em;
            white-space: nowrap;
            justify-self: start;
        }

        .event-title {
            font-size: 24px;
            line-height: 1.15;
            font-weight: 700;
            overflow-wrap: anywhere;
        }

        .event-time {
            font-size: 22px;
            line-height: 1.1;
            color: var(--muted);
            white-space: nowrap;
            justify-self: end;
        }

        .event-card.is-compact .event-title { font-size: 22px; }

        .event-message {
            font-size: 20px;
            line-height: 1.35;
            color: var(--text);
            white-space: pre-wrap;
            overflow-wrap: anywhere;
        }

        .event-message-empty { min-height: 1px; }

        /* Die Farbe je Ereignisart. Im alten System stand sie als
           style-Attribut an jeder Karte; als Klasse steht sie einmal
           hier und nicht hundertmal im Dokument. */
<?php foreach ($badges as $key => $badge): ?>
        .badge-<?= $e($key) ?> { background: <?= $e($badge['bg']) ?>; color: <?= $e($badge['text']) ?>; }
<?php endforeach; ?>

        /* --- Der Schalter -------------------------------------------- */

        .toggle-wrap {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            color: var(--text);
            font-size: 14px;
        }

        .toggle-switch {
            position: relative;
            width: 52px;
            height: 30px;
            display: inline-block;
            flex-shrink: 0;
            cursor: pointer;
            touch-action: manipulation;
        }

        .toggle-switch input { opacity: 0; width: 0; height: 0; }

        .toggle-slider {
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.12);
            border-radius: 999px;
            cursor: pointer;
            pointer-events: none;
            transition: background 0.15s ease;
        }

        .toggle-slider::before {
            content: '';
            position: absolute;
            width: 22px;
            height: 22px;
            left: 4px;
            top: 4px;
            border-radius: 50%;
            background: #fff;
            transition: transform 0.15s ease;
        }

        .toggle-switch input:checked + .toggle-slider {
            background: rgba(176, 108, 255, 0.55);
        }

        .toggle-switch input:checked + .toggle-slider::before {
            transform: translateX(22px);
        }

        /* --- Nachladen ----------------------------------------------- */

        .feed-loader {
            height: 18px;
            font-size: 13px;
            color: var(--muted);
            text-align: center;
        }

        body.is-paused .feed-loader { opacity: 0.75; }

        /* --- Schmale Docks ------------------------------------------- */

        @media (max-width: 720px) {
            body { padding: 8px; }

            .feed-head, .event-card { border-radius: 10px; }

            .feed-head { align-items: flex-start; gap: 8px; }
            .feed-head-left, .feed-head-right { gap: 8px; }

            .control-button { padding: 7px 10px; font-size: 12px; }

            .toggle-wrap { gap: 8px; font-size: 12px; }
            .toggle-switch { width: 46px; height: 26px; }
            .toggle-slider::before { width: 18px; height: 18px; }
            .toggle-switch input:checked + .toggle-slider::before { transform: translateX(20px); }

            .feed-empty, .feed-error, .event-card { padding: 10px 12px; }

            .event-top { gap: 8px; margin-bottom: 6px; }
            .event-main { gap: 8px; }

            .event-badge { padding: 4px 8px; font-size: 16px; }
            .event-time { font-size: 15px; }

            .event-title {
                font-size: 17px;
                line-height: 1.1;
                min-width: 0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }

            .event-card.is-compact .event-title { font-size: 16px; }

            .event-message {
                font-size: 14px;
                line-height: 1.25;
                min-width: 0;
                white-space: nowrap;
                overflow: hidden;
                text-overflow: ellipsis;
            }
        }
    </style>
</head>
<body class="<?= $compact ? 'is-compact' : '' ?>">

<main class="feed">
    <header class="feed-head">
        <div class="feed-head-left">
            <details class="filter-menu" id="filter-menu">
                <summary class="control-button"><?= $e(translate('feed.ui.filter')) ?></summary>

                <div class="filter-popover">
                    <div class="filter-range">
                        <label for="range"><?= $e(translate('feed.ui.range')) ?></label>
                        <select class="control-select" id="range"
                                onchange="location.href=this.value">
                            <?php foreach ($ranges as $key => $option): ?>
                                <option value="<?= $e($link(['range' => $key, 'page' => null])) ?>"
                                    <?= $range === $key ? 'selected' : '' ?>><?= $e($option['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <?php /*
                        Der Baum wird NICHT hier ausgeschrieben, sondern
                        vom Skript aufgeblaettert - eine Ebene zur Zeit,
                        mit Brotkrume und Zurueck-Knopf. So war es im
                        alten System, und in einem schmalen Dock ist ein
                        voll ausgeklappter Baum unbedienbar.
                    */ ?>
                    <div class="filter-nav">
                        <button type="button" class="filter-back" id="filter-back" hidden
                                aria-label="<?= $e(translate('feed.ui.back')) ?>">&lsaquo;</button>
                        <div class="filter-breadcrumb" id="filter-breadcrumb"><?= $e(translate('feed.ui.filter')) ?></div>
                    </div>

                    <div class="filter-items" id="filter-items"></div>

                    <div class="filter-actions">
                        <button type="button" class="filter-bulk" data-bulk="all"><?= $e(translate('feed.ui.all')) ?></button>
                        <button type="button" class="filter-bulk" data-bulk="none"><?= $e(translate('feed.ui.none')) ?></button>
                    </div>
                </div>
            </details>

            <a class="control-button<?= $compact ? ' is-on' : '' ?>"
               href="<?= $e($link(['compact' => $compact ? null : '1'])) ?>"><?= $e(translate('feed.ui.compact')) ?></a>
        </div>

        <div class="feed-head-right">
            <button id="reload-feed" class="control-button" type="button"><?= $e(translate('feed.ui.reload')) ?></button>

            <?php if ($refresh > 0): ?>
                <div class="toggle-wrap">
                    <span id="pause-label"><?= $e(translate('feed.ui.pause')) ?></span>
                    <label class="toggle-switch">
                        <input type="checkbox" id="pause" checked>
                        <span class="toggle-slider"></span>
                    </label>
                </div>
            <?php endif; ?>
        </div>
    </header>

    <?php if ($events === []): ?>
        <section class="feed-empty" id="feed-empty">
            <?php if ($selected === []): ?>
                <?= $e(translate('feed.empty.nothing_selected')) ?>
            <?php else: ?>
                <?= $e(translate('feed.empty.no_events')) ?>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <section id="event-list">
        <?php foreach ($events as $event): ?>
            <article class="event-card<?= $event['message'] === '' ? ' is-compact' : '' ?>">
                <div class="event-top">
                    <div class="event-badge badge-<?= $e($event['style']) ?>"><?= $e($event['badge']) ?></div>
                    <div class="event-time"><?= $e($event['time']) ?></div>
                </div>
                <div class="event-main">
                    <div class="event-title"><?= $e($event['title']) ?></div>
                </div>
                <div class="event-bottom">
                    <?php if ($event['message'] !== ''): ?>
                        <div class="event-message"><?= $e($event['message']) ?></div>
                    <?php else: ?>
                        <div class="event-message event-message-empty"></div>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </section>

    <div id="feed-loader" class="feed-loader"></div>
</main>

<script>
(function () {
    'use strict';

    var baum = <?= json_encode($tree) ?>;
    var alleBlaetter = <?= json_encode($leaves) ?>;
    var gewaehlt = <?= json_encode($selected) ?>;
    var ziel = <?= json_encode($url('/obs')) ?>;

    var texte = {
        filter:  <?= json_encode(translate('feed.ui.filter')) ?>,
        pause:   <?= json_encode(translate('feed.ui.pause')) ?>,
        weiter:  <?= json_encode(translate('feed.ui.resume')) ?>,
        laedt:   <?= json_encode(translate('feed.ui.loading')) ?>,
        ende:    <?= json_encode(translate('feed.ui.end')) ?>,
        gestoert: <?= json_encode(translate('feed.connection_lost')) ?>
    };

    // =============================================================
    //  Der Filter: eine Ebene zur Zeit
    // =============================================================
    var pfad = [];

    var items = document.getElementById('filter-items');
    var krume = document.getElementById('filter-breadcrumb');
    var zurueck = document.getElementById('filter-back');

    function knotenAn(pfad) {
        var knoten = { children: baum };

        for (var i = 0; i < pfad.length; i++) {
            var gefunden = null;

            (knoten.children || []).forEach(function (k) {
                if (k.key === pfad[i]) { gefunden = k; }
            });

            if (!gefunden) { return null; }
            knoten = gefunden;
        }

        return knoten;
    }

    /** Alle Blaetter unterhalb eines Knotens - er selbst, wenn er eins ist. */
    function blaetterUnter(knoten) {
        if (!knoten) { return []; }

        var kinder = knoten.children || [];
        if (kinder.length === 0) { return knoten.key ? [knoten.key] : []; }

        var raus = [];
        kinder.forEach(function (kind) {
            raus = raus.concat(blaetterUnter(kind));
        });

        return raus;
    }

    function brotkrume() {
        var teile = [texte.filter];
        var knoten = { children: baum };

        for (var i = 0; i < pfad.length; i++) {
            var naechster = null;
            (knoten.children || []).forEach(function (k) {
                if (k.key === pfad[i]) { naechster = k; }
            });

            if (!naechster) { break; }
            teile.push(naechster.label);
            knoten = naechster;
        }

        return teile.join(' › ');
    }

    function zeichne() {
        var knoten = knotenAn(pfad);
        if (!knoten) { pfad = []; knoten = { children: baum }; }

        var kinder = knoten.children || [];

        krume.textContent = brotkrume();
        zurueck.hidden = pfad.length === 0;
        items.textContent = '';

        kinder.forEach(function (kind) {
            var blaetter = blaetterUnter(kind);
            var an = blaetter.filter(function (b) { return gewaehlt.indexOf(b) !== -1; });

            var zeile = document.createElement('div');
            zeile.className = 'filter-row';

            var box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = an.length === blaetter.length && blaetter.length > 0;
            box.indeterminate = an.length > 0 && an.length < blaetter.length;

            box.addEventListener('change', function () {
                blaetter.forEach(function (blatt) {
                    var stelle = gewaehlt.indexOf(blatt);

                    if (box.checked && stelle === -1) { gewaehlt.push(blatt); }
                    if (!box.checked && stelle !== -1) { gewaehlt.splice(stelle, 1); }
                });

                zeichne();
            });

            var beschriftung = document.createElement('span');
            beschriftung.className = 'filter-label';
            beschriftung.textContent = kind.label;

            zeile.appendChild(box);
            zeile.appendChild(beschriftung);

            // Ein Knoten mit Kindern fuehrt eine Ebene tiefer. Die Zahl
            // daneben sagt, wie viele davon an sind - sonst muesste man
            // hineingehen, um es zu sehen.
            if ((kind.children || []).length > 0) {
                var zahl = document.createElement('span');
                zahl.className = 'filter-count';
                zahl.textContent = an.length + '/' + blaetter.length;

                var pfeil = document.createElement('span');
                pfeil.className = 'filter-arrow';
                pfeil.textContent = '›';

                zeile.appendChild(zahl);
                zeile.appendChild(pfeil);

                // Nur die Beschriftung geht tiefer, nicht das Kaestchen:
                // sonst blaetterte jedes Anhaken eine Ebene weiter.
                beschriftung.style.cursor = 'pointer';
                beschriftung.addEventListener('click', function () {
                    pfad.push(kind.key);
                    zeichne();
                });
            }

            items.appendChild(zeile);
        });
    }

    zurueck.addEventListener('click', function () {
        pfad.pop();
        zeichne();
    });

    Array.prototype.forEach.call(document.querySelectorAll('[data-bulk]'), function (knopf) {
        knopf.addEventListener('click', function () {
            gewaehlt = knopf.getAttribute('data-bulk') === 'all' ? alleBlaetter.slice() : [];
            zeichne();
            uebernehmen();
        });
    });

    /**
     * Die Auswahl in die Adresse schreiben und neu laden.
     *
     * Ist alles angehakt, kommt gar kein Parameter mit - dann bleibt
     * der Link auch gueltig, wenn spaeter neue Arten dazukommen.
     */
    function uebernehmen() {
        var params = new URLSearchParams();
        params.set('range', <?= json_encode($range) ?>);
        <?php if ($compact): ?>params.set('compact', '1');<?php endif ?>

        if (gewaehlt.length !== alleBlaetter.length) {
            // Leere Auswahl heisst WIRKLICH leer. Ein fehlender
            // Parameter bedeutet dem Server "kein Filter" - also alles.
            params.set('filter', gewaehlt.length === 0 ? '__keine__' : gewaehlt.join(','));
        }

        location.href = ziel + '?' + params.toString();
    }

    // Beim Zuklappen uebernehmen, nicht bei jedem Haken: sonst laedt
    // die Seite mitten im Auswaehlen neu.
    var menue = document.getElementById('filter-menu');
    var standBeimOeffnen = gewaehlt.join(',');

    menue.addEventListener('toggle', function () {
        if (menue.open) {
            standBeimOeffnen = gewaehlt.join(',');
            return;
        }

        if (gewaehlt.join(',') !== standBeimOeffnen) { uebernehmen(); }
    });

    document.addEventListener('click', function (event) {
        if (menue.open && !menue.contains(event.target)) { menue.open = false; }
    });

    zeichne();

    // =============================================================
    //  Karten bauen
    // =============================================================
    function karte(event) {
        var artikel = document.createElement('article');
        artikel.className = 'event-card' + (event.message ? '' : ' is-compact');

        var oben = document.createElement('div');
        oben.className = 'event-top';

        var abzeichen = document.createElement('div');
        abzeichen.className = 'event-badge badge-' + event.style;
        abzeichen.textContent = event.badge;

        var zeit = document.createElement('div');
        zeit.className = 'event-time';
        zeit.textContent = event.time;

        oben.appendChild(abzeichen);
        oben.appendChild(zeit);

        var mitte = document.createElement('div');
        mitte.className = 'event-main';

        var name = document.createElement('div');
        name.className = 'event-title';
        name.textContent = event.title;
        mitte.appendChild(name);

        var unten = document.createElement('div');
        unten.className = 'event-bottom';

        var text = document.createElement('div');
        text.className = 'event-message' + (event.message ? '' : ' event-message-empty');
        if (event.message) { text.textContent = event.message; }
        unten.appendChild(text);

        artikel.appendChild(oben);
        artikel.appendChild(mitte);
        artikel.appendChild(unten);

        return artikel;
    }

    var liste = document.getElementById('event-list');
    var lader = document.getElementById('feed-loader');

    document.getElementById('reload-feed').addEventListener('click', function () {
        location.reload();
    });

    // =============================================================
    //  Aelteres beim Scrollen
    // =============================================================
    var abstand = <?= (int) $limit ?>;

    // Wie viele Ereignisse schon hinter uns liegen - und zwar vom
    // Anfang der Liste gezaehlt, nicht ab dieser Seite.
    //
    // Die Blaetterleiste ist weg, aber der Parameter "page" gilt noch:
    // wer ein Lesezeichen auf ?page=3 hat, faengt bei 100 an. Zaehlte
    // man hier nur die gezeigten Karten, holte das Nachladen die
    // Ereignisse 50 bis 100 - also die, die man gerade uebersprungen
    // hat.
    var geladen = <?= (int) (($page - 1) * $limit + count($events)) ?>;
    var amEnde = <?= count($events) < $limit ? 'true' : 'false' ?>;
    var laedt = false;

    var suchparameter = 'range=' + encodeURIComponent(<?= json_encode($range) ?>)
        + '&limit=' + abstand
        <?= $allSelected ? '' : "+ '&filter=' + encodeURIComponent(" . json_encode(implode(',', $selected)) . ")" ?>;

    function mehr() {
        if (laedt || amEnde) { return; }

        laedt = true;
        lader.textContent = texte.laedt;

        fetch(<?= json_encode($url('/obs/more')) ?> + '?' + suchparameter + '&offset=' + geladen,
            { credentials: 'same-origin' })
            .then(function (antwort) {
                if (!antwort.ok) { throw new Error('Status ' + antwort.status); }
                return antwort.json();
            })
            .then(function (daten) {
                (daten.events || []).forEach(function (event) {
                    liste.appendChild(karte(event));
                });

                geladen += (daten.events || []).length;
                amEnde = !!daten.done;
                lader.textContent = amEnde ? texte.ende : '';
                laedt = false;

                // Passt alles ins Fenster, loest kein Scrollen aus -
                // dann muss von selbst weitergeladen werden, sonst
                // haengt der Feed bei der ersten Seite fest.
                if (!amEnde && document.body.scrollHeight <= window.innerHeight) { mehr(); }
            })
            .catch(function (fehler) {
                lader.textContent = texte.gestoert + fehler.message;
                laedt = false;
            });
    }

    window.addEventListener('scroll', function () {
        if (window.innerHeight + window.scrollY >= document.body.offsetHeight - 200) { mehr(); }
    });

    if (amEnde) { lader.textContent = ''; } else { mehr(); }

    // =============================================================
    //  Neues von oben
    // =============================================================
    var refresh = <?= (int) $refresh ?>;
    if (!refresh) { return; }

    var neuestes = <?= (int) $latest ?>;
    var uhr = null;

    var quelle = <?= json_encode($url('/obs/updates')) ?> + '?' + suchparameter;

    function holen() {
        fetch(quelle + '&since_id=' + neuestes, { credentials: 'same-origin' })
            .then(function (antwort) {
                if (!antwort.ok) { throw new Error('Status ' + antwort.status); }
                return antwort.json();
            })
            .then(function (daten) {
                if (typeof daten.latest === 'number' && daten.latest > neuestes) {
                    neuestes = daten.latest;
                }

                if (!daten.events || !daten.events.length) { return; }

                var leer = document.getElementById('feed-empty');
                if (leer) { leer.remove(); }

                // Antwort ist neueste zuerst - von hinten einfuegen,
                // damit die Reihenfolge oben stimmt.
                for (var i = daten.events.length - 1; i >= 0; i--) {
                    liste.insertBefore(karte(daten.events[i]), liste.firstChild);
                }

                // Was oben dazukommt, verschiebt das Fenster nach
                // unten: der Abstand fuer das Nachladen muss mitwachsen,
                // sonst kaemen dieselben Karten ein zweites Mal.
                geladen += daten.events.length;
            })
            .catch(function () { /* Beim naechsten Mal wieder. */ });
    }

    function starten() { uhr = setInterval(holen, refresh * 1000); }

    var schalter = document.getElementById('pause');
    var schild = document.getElementById('pause-label');

    if (schalter) {
        schalter.addEventListener('change', function () {
            document.body.classList.toggle('is-paused', !schalter.checked);
            schild.textContent = schalter.checked ? texte.pause : texte.weiter;

            if (schalter.checked) {
                starten();
                holen();
            } else {
                clearInterval(uhr);
            }
        });
    }

    starten();
}());
</script>
</body>
</html>
