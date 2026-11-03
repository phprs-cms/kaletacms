<?php
/**
 * The workload of the sites (3.29, Fleet\Checks::workload): per site and in total the enquiries this week and last week,
 * those unanswered for over 24 hours, the median hours to the first reply, open and overdue tasks and new customers
 * this month. Counts only – no names, no hours worked, no money. "–" = the site does not send the count (before 3.29).
 * Every column header sorts the table (a plain link, no script).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var list<array<string, mixed>> $rows Checks::workload()['sites'], sorted
 * @var array<string, ?int> $totals
 * @var int $reporting sites that send the counts
 * @var array<int, string> $customers id => name
 * @var int $customer 0 = all, -1 = sites without a customer
 * @var string $sort site | a key of Checks::WORKLOAD
 * @var string $dir asc | desc
 * @var int $siteCount all sites in the console
 */
$columns = ['enquiries_this_week' => t('Enquiries this week'), 'enquiries_last_week' => t('Enquiries last week'), 'unanswered_over_24h' => t('Unanswered over 24 h'),
    'median_first_reply_hours' => t('Median hours to the first reply'), 'tasks_open' => t('Open tasks'), 'tasks_overdue' => t('Overdue tasks'), 'customers_this_month' => t('New customers this month')];
$value = fn (?int $n): string => $n === null ? '–' : format_count($n);
$header = function (string $key, string $label) use ($module, $sort, $dir, $customer): string {
    $active = $sort === $key;
    $next = $active ? ($dir === 'asc' ? 'desc' : 'asc') : ($key === 'site' ? 'asc' : 'desc');
    $mark = $active ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';

    return '<a href="' . e($module->url('workload', array_filter(['customer' => $customer, 'sort' => $key, 'dir' => $next]))) . '"' . ($active ? ' aria-current="true"' : '') . '>' . e($label) . $mark . '</a>';
};
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'workload']) ?>
<?php if ($siteCount === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No site reports to this console yet. Make a pairing key and paste it on the site.')]) ?>
<?php else: ?>
<p class="napoveda"><?= e(t('How much work the sites bring: counts from each site\'s last report – no names, no messages, no hours or money. %d of %d sites send them; "–" means the site does not (update it to Kaleta 3.29).', $reporting, count($rows))) ?></p>
<?php if ($customers !== []): ?>
<form class="hromadne" method="get" action="<?= e($app->url('admin.php')) ?>">
	<input type="hidden" name="module" value="fleet"><input type="hidden" name="action" value="workload"><input type="hidden" name="sort" value="<?= e($sort) ?>"><input type="hidden" name="dir" value="<?= e($dir) ?>">
	<label><?= e(t('Customer')) ?> <select name="customer"><option value="0"><?= e(t('all')) ?></option><option value="-1"<?= $customer === -1 ? ' selected' : '' ?>><?= e(t('without a customer')) ?></option>
<?php foreach ($customers as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $customer === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
	<button class="navigace" type="submit"><?= e(t('Show')) ?></button>
</form>
<?php endif ?>
<?php if ($rows === []): ?>
<p><?= e(t('Nothing matches the filter.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis vypis-karty flotila-prace">
<thead><tr><th scope="col"><?= $header('site', t('Site')) ?></th>
<?php foreach ($columns as $key => $label): ?>
	<th scope="col" class="cislo"><?= $header($key, $label) ?></th>
<?php endforeach ?>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
	<th scope="row"><a href="<?= e($module->url('detail', ['id' => $r['site_id']])) ?>"><?= e((string) $r['site']) ?></a><?php if ($r['customer_id'] !== null && isset($customers[$r['customer_id']])): ?><br><span class="smltxt"><?= e($customers[$r['customer_id']]) ?></span><?php endif ?>
		<?php if (!$r['known']): ?><br><span class="stitek"><?= e(t('Update the site to see')) ?></span><?php endif ?></th>
<?php foreach ($columns as $key => $label): ?>
	<td class="cislo" data-popisek="<?= e($label) ?>"><?= $value($r[$key]) ?></td>
<?php endforeach ?>
</tr>
<?php endforeach ?>
</tbody>
<tfoot><tr><th scope="row"><?= e(t('Total')) ?></th>
<?php foreach ($columns as $key => $label): ?>
	<td class="cislo" data-popisek="<?= e($label) ?>"><?= $value($totals[$key]) ?></td>
<?php endforeach ?>
</tr></tfoot>
</table></div>
<p class="smltxt"><?= e(t('The total of the median is the median of the sites\' medians.')) ?></p>
<?php endif ?>
<?php endif ?>
