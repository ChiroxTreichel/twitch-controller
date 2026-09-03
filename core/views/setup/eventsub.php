<?php
/**
 * @var \TwitchController\Core\App $app
 * @var callable $e
 * @var callable $url
 * @var array{created: list<string>, deleted: list<string>, kept: list<string>, failed: array<string, string>}|null $report
 * @var string|null $error
 * @var string $callback
 * @var list<array{type: string, version: string, condition: array<string, string>}> $desired
 * @var string $csrf
 */
?>
<h1><?= $e(translate('setup.events.title')) ?></h1>
<p class="lead">
    <?= $e(translate('setup.events.lead')) ?>
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
        $erklaerung = \TwitchController\Core\Twitch\EventSub::explain((string) $message);
        $schluessel = $erklaerung['ursache'];
        $gruppen[$schluessel]['loesung'] = $erklaerung['loesung'];
        $gruppen[$schluessel]['typen'][] = (string) $type;

        if ($erklaerung['neu_verbinden']) {
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
                <?php $namen = array_map(
                    static fn (string $t): string => \TwitchController\Core\Events\Labels::of($t, $app->hooks),
                    $gruppe['typen']
                ); ?>
                <?= $e(translate('setup.events.affects', ['types' => implode(', ', $namen)])) ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($brauchtNeuVerbinden): ?>
        <a class="btn" href="<?= $e($url('/setup/channel')) ?>">
            <?= $e(translate('common.reconnect_channel')) ?>
        </a>
        <p class="hint" style="margin-top:10px;">
            <?= $e(translate('setup.events.after_reconnect')) ?>
        </p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($report !== null && $report['failed'] === []): ?>
    <div class="note note-ok">
        <?= $e(translate('setup.events.created_kept', ['created' => count($report['created']), 'kept' => count($report['kept'])])) ?>
        <?php if ($report['deleted'] !== []): ?>
            <div class="hint" style="margin-top:6px;">
                <?= $e(translate('setup.events.removed_old', ['count' => count($report['deleted'])])) ?>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-head">
        <h2><?= $e(translate('setup.events.what')) ?></h2>
        <span class="badge"><?= $e(translate('setup.events.count', ['count' => count($desired)])) ?></span>
    </div>
    <table>
        <tbody>
        <?php foreach ($desired as $subscription): ?>
            <tr>
                <td><?= $e(\TwitchController\Core\Events\Labels::of($subscription['type'], $app->hooks)) ?></td>
                <td class="actions hint mono"><?= $e($subscription['type']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <p class="hint">
        <?= $e(translate('setup.events.basics')) ?>
    </p>
</div>

<form method="post" action="<?= $e($url('/setup/events')) ?>" class="row">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button class="btn" type="submit">
        <?= $e(translate('setup.events.create')) ?>
    </button>
</form>

<form method="post" action="<?= $e($url('/setup/finish')) ?>" style="margin-top:12px;">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">
    <button class="btn btn-ghost btn-small" type="submit">
        <?= $e(translate('setup.events.skip')) ?>
    </button>
</form>
