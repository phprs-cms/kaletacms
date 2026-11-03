<?php
/**
 * The tabs of the Pipeline (3.31): the board, the list (both keep the filter) and, for administrators, the stages; "New deal"
 * on the same row.
 *
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var string $current board | list | stages
 * @var array<string, int|string> $query the filter of the board or the list (without view)
 * @var bool $admin
 */
$query = array_diff_key($query ?? [], ['view' => 1, 'status' => 1, 'page' => 1]);
?>
<div class="zakaznici-hlava">
<nav class="zalozky" aria-label="<?= e(t('Pipeline')) ?>">
	<a href="<?= e($module->url('', $query)) ?>"<?= $current === 'board' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Board')) ?></a>
	<a href="<?= e($module->url('', ['view' => 'list'] + $query)) ?>"<?= $current === 'list' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('List')) ?></a>
<?php if ($admin): ?>
	<a href="<?= e($module->url('stages')) ?>"<?= $current === 'stages' ? ' class="aktivni" aria-current="true"' : '' ?> data-pipeline-stages><?= e(t('Stages')) ?></a>
<?php endif ?>
</nav>
<p class="zakaznici-akce">
	<a class="tl" href="<?= e($module->url('new', array_intersect_key($query, ['contact' => 1, 'company' => 1]))) ?>" data-new-deal><?= e(t('New deal')) ?></a>
</p>
</div>
