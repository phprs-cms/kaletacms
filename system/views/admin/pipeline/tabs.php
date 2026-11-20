<?php
/**
 * The head of the Pipeline (3.31; 3.48 decision 6): the board and the list (both keep the filter) as a switch of the view,
 * "New deal", and for administrators the stages as a small link on the right – the tabs above are the Sales hub's
 * (Pipeline · Quotes · Internal prices, Admin\Hubs), so the page has one row of tabs.
 *
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var string $current board | list | stages
 * @var array<string, int|string> $query the filter of the board or the list (without view)
 * @var bool $admin
 */
$query = array_diff_key($query ?? [], ['view' => 1, 'status' => 1, 'page' => 1]);
?>
<div class="zakaznici-hlava">
<p class="zakaznici-akce" role="group" aria-label="<?= e(t('View')) ?>">
	<a class="navigace" href="<?= e($module->url('', $query)) ?>"<?= $current === 'board' ? ' aria-current="page"' : '' ?>><?= e(t('Board')) ?></a>
	<a class="navigace" href="<?= e($module->url('', ['view' => 'list'] + $query)) ?>"<?= $current === 'list' ? ' aria-current="page"' : '' ?>><?= e(t('List')) ?></a>
	<a class="tl" href="<?= e($module->url('new', array_intersect_key($query, ['contact' => 1, 'company' => 1]))) ?>" data-new-deal><?= e(t('New deal')) ?></a>
</p>
<?php if ($admin): ?>
<p class="hlava-odkazy">
	<a href="<?= e($module->url('stages')) ?>"<?= $current === 'stages' ? ' aria-current="page"' : '' ?> data-pipeline-stages><?= e(t('Stages')) ?></a>
</p>
<?php endif ?>
</div>
