<?php
/**
 * @var callable $e
 * @var callable $url
 * @var list<array{label: string, ok: bool, required: bool, detail: string}> $checks
 * @var bool $ready
 * @var string|null $error
 */
?>
<h1><?= $e(translate('setup.check.title')) ?></h1>
<p class="lead">
    <?= $e(translate('setup.check.lead')) ?>
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
                    <span class="badge badge-ok"><?= $e(translate('common.ok')) ?></span>
                <?php elseif ($check['required']): ?>
                    <span class="badge badge-error"><?= $e(translate('common.missing')) ?></span>
                <?php else: ?>
                    <span class="badge badge-warn"><?= $e(translate('common.notice')) ?></span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    </tbody>
</table>

<?php if ($ready): ?>
    <form method="post" action="<?= $e($url('/setup/database')) ?>" style="margin-top:22px;">
        <button class="btn" type="submit"><?= $e(translate('setup.check.continue')) ?></button>
    </form>
<?php else: ?>
    <div class="note note-warn" style="margin-top:22px;">
        <?php // Absichtlich ohne $e: die Platzhalter sind eigenes Markup. ?>
        <?= translate('setup.check.fix_env', ['env' => '<span class="mono">.env</span>', 'example' => '<span class="mono">.env.example</span>']) ?>
    </div>
    <a class="btn btn-ghost" href="<?= $e($url('/setup')) ?>"><?= $e(translate('setup.check.recheck')) ?></a>
<?php endif; ?>
