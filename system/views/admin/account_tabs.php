<?php
/**
 * The tabs of My account (3.14): the details, and the own e-mail signature when the site has a people collection.
 *
 * @var Kaleta\Core\App $app
 * @var string $current '' = the details, 'signature'
 */
?>
<nav class="zalozky" aria-label="<?= e(t('My account')) ?>">
	<a href="<?= e($app->url('admin.php?action=account')) ?>"<?= $current === '' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('My details')) ?></a>
	<a href="<?= e($app->url('admin.php?action=account&tab=signature')) ?>"<?= $current === 'signature' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('My e-mail signature')) ?></a>
</nav>
