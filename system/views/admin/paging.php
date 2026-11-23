<?php
/**
 * Paging below an admin list (3.52, A8): the pages within five of the current one, the first and the last always, a gap
 * between them. Nothing for a single page. The link keeps the list's filter – $query (without page) for $module->url(),
 * or $link for a list that builds its own addresses.
 * Usage: <?= $app->view->render('admin/paging', ['module' => $module, 'query' => ['search' => $search], 'page' => $pageNumber, 'pages' => $pageCount]) ?>
 *
 * @var int $page   the current page (1-based)
 * @var int $pages  the number of pages
 * @var Kaleta\Admin\Module|null $module
 * @var array<string, scalar|null> $query  the filter in the address; '' and null are left out
 * @var string $action  the module's action (optional, '' = the list)
 * @var (\Closure(int): string)|null $link  the address of a page, instead of $module and $query
 */
if ($pages <= 1) {
    return;
}
$query = array_filter($query ?? [], static fn ($v): bool => $v !== null && $v !== '');
$href = $link ?? static fn (int $s): string => $module->url($action ?? '', $query + ($s > 1 ? ['page' => $s] : []));
$shown = array_unique([1, ...range(max(1, $page - 5), min($pages, $page + 5)), $pages]);
?>
<nav class="strankovani" aria-label="<?= e(t('Pages')) ?>">
<?php $previous = 0; foreach ($shown as $s): ?>
<?php if ($s > $previous + 1): ?>	<span class="strankovani-mezera" aria-hidden="true">…</span>
<?php endif ?>
	<?= $s === $page ? '<strong aria-current="page">[' . $s . ']</strong>' : '<a href="' . e($href($s)) . '">' . $s . '</a>' ?>
<?php $previous = $s; endforeach ?>
</nav>
