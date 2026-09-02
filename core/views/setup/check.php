<?php
/**
 * @var callable $e
 * @var callable $url
 * @var list<array{label: string, ok: bool, required: bool, detail: string}> $checks
 * @var bool $ready
 * @var string|null $error
 */
?>
<h1>Willkommen</h1>
<p class="lead">
    Bevor es losgeht, prüfen wir, ob der Server alles mitbringt.
</p>

<?php if ($error !== null): ?>
    <div class="note note-error"><?= $e($error) ?></div>
<?php endif; ?>

<table>
    <tbody>
    <?php foreach ($checks as $check): ?>
        <tr>
            <td>
                <?= $e($check['label']) ?>
                <div class="hint"><?= $e($check['detail']) ?></div>
            </td>
            <td class="actions">
                <?php if ($check['ok']): ?>
                    <span class="badge badge-ok">in Ordnung</span>
                <?php elseif ($check['required']): ?>
                    <span class="badge badge-error">fehlt</span>
                <?php else: ?>
                    <span class="badge badge-warn">Hinweis</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($ready): ?>
    <form method="post" action="<?= $e($url('/setup/datenbank')) ?>" style="margin-top:22px;">
        <button class="btn" type="submit">Datenbank einrichten und weiter</button>
    </form>
<?php else: ?>
    <div class="note note-warn" style="margin-top:22px;">
        Bitte die rot markierten Punkte in der <span class="mono">.env</span> beheben und die Seite neu laden.
        Als Vorlage dient <span class="mono">.env.example</span>.
    </div>
    <a class="btn btn-ghost" href="<?= $e($url('/setup')) ?>">Erneut prüfen</a>
<?php endif; ?>
