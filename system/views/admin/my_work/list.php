<?php
/**
 * My work (3.24, Core\MyWork): one table per list the person may open, only lists with something in them; every row links
 * to where the work is done. Nothing at all = the empty state.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\MyWork $module
 * @var array<string, list<array<string, mixed>>> $lists  list => rows (Core\MyWork::lists)
 * @var array<string, class-string<Kaleta\Admin\Module>> $modules
 */
$headings = [
    'assigned' => 'Enquiries you handle',
    'unassigned' => 'New enquiries nobody handles yet',
    'pages' => 'Pages you are responsible for',
    'items' => 'Collection items you are responsible for',
    'requests' => 'Your requests Claude answered',
];
$reason = fn (string $code, array $row): string => match ($code) {
    'overdue' => t('Review overdue since %s', format_date((string) $row['review_by'])),
    'review' => t('Review by %s', format_date((string) $row['review_by'])),
    'draft' => t('Unpublished changes'),
    'comments' => t('Unresolved comments: %d', (int) $row['comments']),
    'hidden' => t('Hidden – not on the site yet'),
    default => '',
};
$filled = array_filter($lists);
?>
<?php if ($filled === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'moje', 'heading' => t('Nothing waits for you right now.'),
    'text' => t('Enquiries assigned to you, the pages and collection items you are responsible for when a review is due or changes wait unpublished, and your requests Claude answered appear here.'),
    'action' => [$app->url('admin.php'), t('Go to the dashboard')]]) ?>
<?php else: ?>
<p class="napoveda"><?= e(t('Every row opens the place where the work is done. You are responsible for a page or an item when its settings name you as the responsible person.')) ?></p>
<?php endif ?>
<?php foreach ($filled as $list => $rows): ?>
<section class="moje-prace" data-list="<?= e($list) ?>" aria-labelledby="moje-<?= e($list) ?>">
<h2 id="moje-<?= e($list) ?>"><?= e(t($headings[$list])) ?> <small><?= format_count(count($rows)) ?></small></h2>
<div class="tab-obal">
<table class="vypis">
<?php if ($list === 'assigned' || $list === 'unassigned'): ?>
<thead><tr><th scope="col"><?= e(t('Form')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Received')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
	<td><a href="<?= e($app->url($r['url'])) ?>"><?= e($r['title'] !== '' ? $r['title'] : t('Enquiry')) ?> #<?= (int) $r['id'] ?></a><?= $r['about'] !== '' ? '<br><span class="smltxt">' . e($r['about']) . '</span>' : '' ?></td>
	<td><?= $r['status'] === 'new' ? '<span class="stitek stitek-koncept">' . e(t('new')) . '</span>' : '' ?></td>
	<td class="cislo"><?= e(format_date($r['date'], true)) ?></td>
</tr>
<?php endforeach ?>
</tbody>
<?php elseif ($list === 'requests'): ?>
<thead><tr><th scope="col"><?= e(t('Request')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Updated')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
	<td><a href="<?= e($app->url($r['url'])) ?>"><?= e($r['title']) ?></a></td>
	<td><span class="stitek"><?= e(t(Kaleta\Core\Requests::STATUSES[$r['status']] ?? $r['status'])) ?></span></td>
	<td class="cislo"><?= e(format_date($r['date'], true)) ?></td>
</tr>
<?php endforeach ?>
</tbody>
<?php else: ?>
<thead><tr><th scope="col"><?= e(t($list === 'pages' ? 'Page' : 'Item')) ?></th><th scope="col"><?= e(t('What waits')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
	<td><a href="<?= e($app->url($r['url'])) ?>"><?= e($r['title']) ?></a><?= isset($r['collection']) ? '<br><span class="smltxt">' . e($r['collection']) . '</span>' : '' ?></td>
	<td><?php foreach ($r['reasons'] as $code): ?><span class="stitek<?= $code === 'overdue' ? ' stitek-koncept' : '' ?>"><?= e($reason($code, $r)) ?></span> <?php endforeach ?></td>
</tr>
<?php endforeach ?>
</tbody>
<?php endif ?>
</table>
</div>
</section>
<?php endforeach ?>
