<?php
/**
 * The tabs of Customers (3.28): the contacts and the companies; the action buttons of the tab (New contact or New company,
 * and for administrators the CSV export and import) sit on the same row.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $current '' = contacts, 'companies'
 */
?>
<div class="zakaznici-hlava">
<nav class="zalozky" aria-label="<?= e(t('Customers')) ?>">
	<a href="<?= e($module->url()) ?>"<?= $current === '' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Contacts')) ?></a>
	<a href="<?= e($module->url('companies')) ?>"<?= $current === 'companies' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Companies')) ?></a>
</nav>
<p class="zakaznici-akce">
<?php if ($current === 'companies'): ?>
	<a class="tl" href="<?= e($module->url('company_new')) ?>" data-new-company><?= e(t('New company')) ?></a>
<?php else: ?>
	<a class="tl" href="<?= e($module->url('new')) ?>" data-new-contact><?= e(t('New contact')) ?></a>
<?php endif ?>
<?php if ($app->auth()->isAdmin()): ?>
	<a class="navigace" href="<?= e($module->url('export')) ?>" data-contacts-export><?= e(t('Export (CSV)')) ?></a>
	<a class="navigace" href="<?= e($module->url('import')) ?>"><?= e(t('Import (CSV)')) ?></a>
<?php endif ?>
</p>
</div>
