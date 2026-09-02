<?php
/**
 * @var callable $e
 * @var callable $url
 * @var array{created: list<string>, deleted: list<string>, kept: list<string>, failed: array<string, string>}|null $report
 * @var string|null $error
 * @var string $callback
 * @var list<array{type: string, version: string, condition: array<string, string>}> $desired
 * @var string $csrf
 */
?>
<h1>Events einrichten</h1>
<p class="lead">
    Zum Schluss wird bei Twitch bestellt, worüber wir benachrichtigt werden wollen.
</p>

<?php if ($error !== null): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<?php if ($report !== null && $report['failed'] !== []): ?>
    <?php
    // Gleiche Ursachen zusammenfassen: bei fehlenden Berechtigungen sind
    // sonst schnell fuenf Zeilen mit derselben Aussage untereinander.
    $gruppen = [];
    $brauchtNeuVerbinden = false;
    foreach ($report['failed'] as $type => $message) {
        $erklaerung = \Overlays\Core\Twitch\EventSub::explain((string) $message);
        $schluessel = $erklaerung['ursache'];
        $gruppen[$schluessel]['loesung'] = $erklaerung['loesung'];
        $gruppen[$schluessel]['typen'][] = (string) $type;

        if (str_contains($erklaerung['loesung'], 'neu verbinden')) {
            $brauchtNeuVerbinden = true;
        }
    }
    ?>

    <?php foreach ($gruppen as $ursache => $gruppe): ?>
        <div class="note note-error">
            <strong><?= $e((string) $ursache) ?></strong>
            <?php if ($gruppe['loesung'] !== ''): ?>
                <div style="margin-top:6px;"><?= $e($gruppe['loesung']) ?></div>
            <?php endif; ?>
            <div class="hint" style="margin-top:8px;">
                Betrifft:
                <?php $namen = array_map(
                    static fn (string $t): string => \Overlays\Core\Events\Labels::of($t, $app->hooks),
                    $gruppe['typen']
                ); ?>
                <?= $e(implode(', ', $namen)) ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($brauchtNeuVerbinden): ?>
        <a class="btn" href="<?= $e($url('/setup/kanal')) ?>">Kanal neu verbinden</a>
        <p class="hint" style="margin-top:10px;">
            Danach landest du wieder hier und klickst noch einmal auf &bdquo;Abos anlegen&ldquo;.
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($report !== null && $report['failed'] === []): ?>
    <div class="note note-ok">
        <?= $e((string) count($report['created'])) ?> angelegt,
        <?= $e((string) count($report['kept'])) ?> bestanden schon.
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2>Was abonniert wird</h2>
        <span class="badge"><?= $e((string) count($desired)) ?> Abos</span>
    </div>
    <table>
        <tbody>
        <?php foreach ($desired as $subscription): ?>
            <tr>
                <td><?= $e(\Overlays\Core\Events\Labels::of($subscription['type'], $app->hooks)) ?></td>
                <td class="actions hint mono"><?= $e($subscription['type']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint">
        Das ist die Grundausstattung für die Aktivitätenliste. Plugins melden später weitere Abos an
        (Ziele, Hype-Train, Kanalpunkte) &mdash; die werden dann automatisch nachbestellt.
    </p>
</div>

<form method="post" action="<?= $e($url('/setup/events')) ?>" class="row">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button class="btn" type="submit">Abos anlegen und fertigstellen</button>
</form>

<form method="post" action="<?= $e($url('/setup/fertig')) ?>" style="margin-top:12px;">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button class="btn btn-ghost btn-small" type="submit">Überspringen, später einrichten</button>
</form>
