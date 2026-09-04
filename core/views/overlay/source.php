<?php
/**
 * Die Flaeche, die in OBS laeuft.
 *
 * Bewusst karg: durchsichtiger Hintergrund, keine Schriftvorgaben, kein
 * Menue. Was zu sehen ist, bringen die Plugins mit - diese Seite stellt
 * nur die Kaesten und die Leitung.
 *
 * @var callable $e
 * @var int $width
 * @var int $height
 * @var array<string, array{label: string, position: string, width: string, height: string, z: int, vars: array<string, string>}> $slots
 * @var array{css: list<string>, js: list<string>} $assets
 * @var string $stream    Adresse der SSE-Leitung
 * @var bool $debug       Verbindungsanzeige einblenden
 * @var int $startId      Ab dieser Nachricht wird gelesen
 * @var int $build        Aufbaunummer - siehe Bus::invalidate()
 */
?>
<!doctype html>
<html lang="<?= $e($language) ?>">
<head>
    <meta charset="utf-8">
    <title>Overlay</title>
    <style>
        /*
         * Durchsichtig - OBS legt das ueber das Spielbild. Ein
         * Hintergrund hier wuerde alles darunter verdecken.
         */
        html, body {
            margin: 0;
            padding: 0;
            background: transparent;
            overflow: hidden;
        }

        /*
         * Die Buehne hat die eingestellte Groesse und wird auf das
         * Fenster skaliert. So sieht ein Platz bei 1920x1080 gleich aus,
         * egal wie gross die Quelle in OBS gezogen wurde.
         */
        #stage {
            position: relative;
            width: <?= (int) $width ?>px;
            height: <?= (int) $height ?>px;
            transform-origin: top left;
        }

        .ov-slot { position: absolute; pointer-events: none; }

        .ov-slot[data-position="fill"]          { inset: 0; }
        .ov-slot[data-position="center"]        { left: 50%; top: 50%; transform: translate(-50%, -50%); }
        .ov-slot[data-position="top-left"]      { left: 0; top: 0; }
        .ov-slot[data-position="top-center"]    { left: 50%; top: 0; transform: translateX(-50%); }
        .ov-slot[data-position="top-right"]     { right: 0; top: 0; }
        .ov-slot[data-position="middle-left"]   { left: 0; top: 50%; transform: translateY(-50%); }
        .ov-slot[data-position="middle-right"]  { right: 0; top: 50%; transform: translateY(-50%); }
        .ov-slot[data-position="bottom-left"]   { left: 0; bottom: 0; }
        .ov-slot[data-position="bottom-center"] { left: 50%; bottom: 0; transform: translateX(-50%); }
        .ov-slot[data-position="bottom-right"]  { right: 0; bottom: 0; }

        /* Verbindungsanzeige, nur wenn eingeschaltet. */
        #ov-state {
            position: fixed; left: 8px; bottom: 8px; z-index: 9999;
            font: 12px/1.4 monospace; color: #fff;
            background: rgba(0, 0, 0, 0.65); padding: 4px 8px; border-radius: 6px;
        }
        html:not([data-debug="1"]) #ov-state { display: none; }
        html[data-connection="open"]    #ov-state::before { content: "● "; color: #4ade80; }
        html[data-connection="closed"]  #ov-state::before { content: "● "; color: #f87171; }
        html[data-connection="opening"] #ov-state::before { content: "● "; color: #fbbf24; }
    </style>
    <?php foreach ($assets['css'] as $css): ?>
        <link rel="stylesheet" href="<?= $e($css) ?>">
    <?php endforeach ?>
</head>
<body data-overlay-stream="<?= $e($stream) ?>"
      data-overlay-start="<?= (int) $startId ?>"
      data-overlay-build="<?= (int) $build ?>"
      data-overlay-width="<?= (int) $width ?>"
      data-overlay-height="<?= (int) $height ?>">

<div id="stage">
    <?php foreach ($slots as $id => $slot): ?>
        <div class="ov-slot"
             id="ov-slot-<?= $e($id) ?>"
             data-slot="<?= $e($id) ?>"
             data-position="<?= $e($slot['position']) ?>"
             style="z-index: <?= (int) $slot['z'] ?>;<?php
                 echo $slot['width'] !== '' ? ' width: ' . $e($slot['width']) . ';' : '';
                 echo $slot['height'] !== '' ? ' height: ' . $e($slot['height']) . ';' : '';
                 // Eigene CSS-Variablen des Plugins. Name und Wert sind
                 // in Bus::normalizeVars() geprueft.
                 foreach ($slot['vars'] as $name => $wert) {
                     echo ' ' . $e($name) . ': ' . $e($wert) . ';';
                 }
             ?>"></div>
    <?php endforeach ?>
</div>

<div id="ov-state">overlay</div>

<script>
    document.documentElement.dataset.debug = <?= $debug ? '"1"' : '"0"' ?>;
</script>
<script src="<?= $e($asset('/assets/overlay.js')) ?>"></script>
<?php foreach ($assets['js'] as $js): ?>
    <script src="<?= $e($js) ?>"></script>
<?php endforeach ?>
</body>
</html>
