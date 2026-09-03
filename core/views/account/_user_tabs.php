<?php
/**
 * Reiter über den Benutzerseiten. Eingebunden aus users.php,
 * users_permissions.php und users_permissions_edit.php.
 *
 * @var callable $e
 * @var callable $url
 * @var string $tab
 */
?>
<div class="tabs">
    <a class="tab<?= $tab === 'granted' ? ' is-active' : '' ?>"
       href="<?= $e($url('/account/users')) ?>"><?= $e(translate('account.users.tab_granted')) ?></a>
    <a class="tab<?= $tab === 'permissions' ? ' is-active' : '' ?>"
       href="<?= $e($url('/account/users/permissions')) ?>"><?= $e(translate('account.users.tab_permissions')) ?></a>
</div>
