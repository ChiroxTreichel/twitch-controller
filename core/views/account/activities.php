<?php
/**
 * Konto > Aktivitäten: Einstellungen für den Feed.
 *
 * @var callable $e
 * @var callable $url
 * @var array<string, array{label: string, bg: string, text: string}> $badges
 * @var array<string, array{label: string, bg: string, text: string}> $presets
 * @var string $feedUrl
 * @var bool $canManage
 * @var string $csrf
 * @var string $notice
 * @var string $error
 */
?>
<h1>Aktivitäten</h1>
<p class="lead">
    Der Feed zeigt, was im Kanal passiert. Hier stellst du seine Farben ein.
</p>

<?php if ($notice !== ''): ?>
    <div class="note note-ok"><?= $e($notice) ?></div>
<?php endif; ?>
<?php if ($error !== ''): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Link zum Feed</h2>
        <a class="btn btn-small" href="<?= $e($feedUrl) ?>" target="_blank" rel="noreferrer">Öffnen</a>
    </div>

    <div class="field">
        <input class="input mono" type="text" readonly value="<?= $e($feedUrl) ?>"
               onclick="this.select()">
    </div>

    <p class="hint">
        Am besten direkt in OBS einbinden: <strong>Ansicht &rarr; Docks &rarr; Eigenes Browser-Dock</strong>,
        dort diese Adresse eintragen. Ein solches Dock teilt die Anmeldung mit deinem Browser,
        du bist also gleich eingeloggt.
    </p>
    <p class="hint">
        Als <em>Browserquelle</em> im Stream ist der Feed nicht gedacht &mdash; er ist für dich,
        nicht für die Zuschauer.
    </p>
</div>

<form method="post" action="<?= $e($url('/konto/aktivitaeten')) ?>">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

    <div class="card">
        <div class="card-head">
            <h2>Farben der Badges</h2>
            <span class="badge"><?= $e((string) count($badges)) ?> Arten</span>
        </div>

        <p class="hint" style="margin-top:-6px;">
            Jede Art von Ereignis hat ihre eigene Farbe. Neue Arten kommen dazu, sobald du ein
            Plugin aktivierst, das eigene mitbringt.
        </p>

        <table>
            <thead>
            <tr>
                <th>Ereignis</th>
                <th>Hintergrund</th>
                <th>Schrift</th>
                <th>Vorschau</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($badges as $key => $badge): ?>
                <tr data-badge-row>
                    <td><?= $e($badge['label']) ?></td>
                    <td>
                        <input class="input" type="color" style="width:70px;padding:3px;"
                               name="obs_badge_<?= $e((string) $key) ?>_bg"
                               value="<?= $e($badge['bg']) ?>"
                               data-badge-bg <?= $canManage ? '' : 'disabled' ?>>
                    </td>
                    <td>
                        <input class="input" type="color" style="width:70px;padding:3px;"
                               name="obs_badge_<?= $e((string) $key) ?>_text"
                               value="<?= $e($badge['text']) ?>"
                               data-badge-text <?= $canManage ? '' : 'disabled' ?>>
                    </td>
                    <td>
                        <span class="badge" data-badge-preview
                              style="background:<?= $e($badge['bg']) ?>;color:<?= $e($badge['text']) ?>;">
                            <?= $e($badge['label']) ?>
                        </span>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($canManage): ?>
        <div class="row">
            <button class="btn" type="submit">Speichern</button>
            <button class="btn btn-ghost btn-small" type="submit" name="action" value="reset"
                    onclick="return confirm('Alle Farben auf die Vorgaben zurücksetzen?');">
                Farben zurücksetzen
            </button>
        </div>
    <?php else: ?>
        <p class="hint">Zum Ändern fehlt dir <span class="mono">Konto.Aktivitaeten.Manage</span>.</p>
    <?php endif; ?>
</form>

<script>
// Vorschau sofort mitfärben, damit man nicht speichern muss, um zu sehen
// wie es wirkt.
document.querySelectorAll('[data-badge-row]').forEach(function (row) {
    var bg = row.querySelector('[data-badge-bg]');
    var text = row.querySelector('[data-badge-text]');
    var preview = row.querySelector('[data-badge-preview]');

    function malen() {
        preview.style.background = bg.value;
        preview.style.color = text.value;
    }

    bg.addEventListener('input', malen);
    text.addEventListener('input', malen);
});
</script>
