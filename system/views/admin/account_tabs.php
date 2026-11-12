<?php
/**
 * The tabs of My account (3.14): the details, the own e-mail signature and (3.37) the colleagues – for every signed-in user.
 *
 * @var Kaleta\Core\App $app
 * @var string $current '' = the details, 'signature', 'colleagues'
 */
$tabs = ['' => t('My details'), 'signature' => t('My e-mail signature'), 'colleagues' => t('Colleagues')];
?>
<nav class="zalozky" aria-label="<?= e(t('My account')) ?>">
<?php foreach ($tabs as $tab => $label): ?>
	<a href="<?= e($app->url('admin.php?action=account' . ($tab !== '' ? '&tab=' . $tab : ''))) ?>"<?= $current === $tab ? ' class="aktivni" aria-current="true"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
</nav>
