<?php
/**
 * The set-up links of Quotes (3.38; 3.48 decision 6): the screens Quotes and Internal prices are tabs of the Sales hub
 * (Admin\Hubs) above the page, so this row keeps only what administrators set up – the price list set-up (3.45, U62) and
 * the settings – as a small group of links on the right. Nothing for anyone else: one row of tabs.
 *
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $current list | prices | settings | setup
 * @var bool $admin
 */
?>
<?php if ($admin): ?>
<p class="hlava-odkazy" data-quotes-tabs>
	<a href="<?= e($module->url('setup')) ?>"<?= $current === 'setup' ? ' aria-current="page"' : '' ?>><?= e(t('Price list set-up')) ?></a>
	<a href="<?= e($module->url('settings')) ?>"<?= $current === 'settings' ? ' aria-current="page"' : '' ?>><?= e(t('Settings')) ?></a>
</p>
<?php endif ?>
