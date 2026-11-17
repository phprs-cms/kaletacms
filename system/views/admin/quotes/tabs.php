<?php
/**
 * The tabs of Quotes (3.38): the quotes (3.38 B), the internal prices and – for administrators – the settings and (3.45,
 * U62) the price list set-up. The page heading stays "Quotes"; the tab names the screen.
 *
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $current list | prices | settings | setup
 * @var bool $admin
 */
?>
<nav class="zalozky" aria-label="<?= e(t('Quotes')) ?>" data-quotes-tabs>
	<a href="<?= e($module->url()) ?>"<?= $current === 'list' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Quotes')) ?></a>
	<a href="<?= e($module->url('prices')) ?>"<?= $current === 'prices' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Internal prices')) ?></a>
<?php if ($admin): ?>
	<a href="<?= e($module->url('setup')) ?>"<?= $current === 'setup' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Price list set-up')) ?></a>
	<a href="<?= e($module->url('settings')) ?>"<?= $current === 'settings' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Settings')) ?></a>
<?php endif ?>
</nav>
