<?php
/**
 * @var callable $e
 * @var callable $url
 * @var list<array<string, mixed>> $events
 * @var list<string> $types
 * @var array{event_type: string, actor: string} $filters
 * @var int $page
 * @var int $pages
 * @var int $total
 */

/** Verstaendlicher Name eines Event-Typs, siehe core/Events/Labels.php. */
$label = static fn (string $eventType): string
    => \Overlays\Core\Events\Labels::of($eventType, $app->hooks);

$amount = static function (array $event): string {
    $value = $event['amount'] ?? null;
    if ($value === null || $value === '') {
        return '';
    }

    $number = rtrim(rtrim(number_format((float) $value, 2, ',', '.'), '0'), ',');

    return match ((string) ($event['currency'] ?? '')) {
        'BITS'           => $number . ' Bits',
        'VIEWERS'        => $number . ' Zuschauer',
        'GIFT_SUBS'      => $number . ' Subs',
        'MONTHS'         => $number . ' Monate',
        'CHANNEL_POINTS' => $number . ' Punkte',
        'EUR'            => $number . ' €',
        ''               => $number,
        default          => $number . ' ' . (string) $event['currency'],
    };
};
?>
<h1>Aktivitäten</h1>
<p class="lead">Alles, was im Kanal passiert ist &mdash; <?= $e((string) $total) ?> Einträge.</p>

<div class="card">
    <form method="get" action="<?= $e($url('/konto/aktivitaeten')) ?>" class="row">
        <select class="input" name="typ" style="width:auto;">
            <option value="">Alle Arten</option>
            <?php foreach ($types as $type): ?>
                <option value="<?= $e($type) ?>" <?= $filters['event_type'] === $type ? 'selected' : '' ?>>
                    <?= $e($label($type)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <input class="input" name="wer" style="width:auto;" placeholder="Name enthält …"
               value="<?= $e($filters['actor']) ?>">
        <button class="btn btn-small" type="submit">Filtern</button>
        <?php if ($filters['event_type'] !== '' || $filters['actor'] !== ''): ?>
            <a class="btn btn-ghost btn-small" href="<?= $e($url('/konto/aktivitaeten')) ?>">Zurücksetzen</a>
        <?php endif; ?>
    </form>
</div>

<div class="card">
    <?php if ($events === []): ?>
        <div class="empty">
            Noch keine Aktivitäten. Sobald Twitch das erste Event schickt, steht es hier.
        </div>
    <?php else: ?>
        <table>
            <thead>
            <tr>
                <th>Wann</th>
                <th>Was</th>
                <th>Wer</th>
                <th>Menge</th>
                <th>Nachricht</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($events as $event): ?>
                <tr>
                    <td class="hint" style="white-space:nowrap;">
                        <?= $e(substr((string) $event['occurred_at'], 0, 16)) ?>
                    </td>
                    <td><span class="badge"><?= $e($label((string) $event['event_type'])) ?></span></td>
                    <td><?= $e($event['actor_name'] ?? 'Anonym') ?></td>
                    <td class="hint"><?= $e($amount($event)) ?></td>
                    <td class="hint"><?= $e($event['message'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<?php if ($pages > 1): ?>
    <div class="row">
        <?php
        $query = static function (int $target) use ($filters): string {
            return '?' . http_build_query(array_filter([
                'seite' => $target,
                'typ'   => $filters['event_type'],
                'wer'   => $filters['actor'],
            ], static fn (mixed $v): bool => $v !== '' && $v !== null));
        };
        ?>
        <?php if ($page > 1): ?>
            <a class="btn btn-ghost btn-small"
               href="<?= $e($url('/konto/aktivitaeten') . $query($page - 1)) ?>">Neuer</a>
        <?php endif; ?>
        <span class="hint">Seite <?= $e((string) $page) ?> von <?= $e((string) $pages) ?></span>
        <?php if ($page < $pages): ?>
            <a class="btn btn-ghost btn-small"
               href="<?= $e($url('/konto/aktivitaeten') . $query($page + 1)) ?>">Älter</a>
        <?php endif; ?>
    </div>
<?php endif; ?>
