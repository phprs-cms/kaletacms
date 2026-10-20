<?php
/**
 * The monthly fleet reports per customer (3.17, Fleet\Report): view and print, download, make the previous month now,
 * rebuild a month from the history still kept.
 *
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var list<array{month: string, customer: string, created_at: string, rebuilt_at: ?string, sites: int}> $reports
 * @var string $previous the previous month (YYYY-MM)
 */
use Kaleta\Core\ReportPage;
use Kaleta\Fleet\Report;

$months = [];
foreach ($reports as $r) {
    $months[$r['month']][] = $r;
}
?>
<p><a href="<?= e($module->url('')) ?>">← <?= e(t('All sites')) ?></a> · <a href="<?= e($module->url('alerts')) ?>"><?= e(t('Alerts and digest')) ?></a></p>
<p class="napoveda"><?= e(t('On the 1st of each month the console makes a report for each customer from its own history: per site the uptime, outages, versions installed, how often it needed attention, and the headline numbers of the site\'s own monthly report. A summary of what happened – no hours, no prices, no personal data. Kept %d months.', Report::KEEP_MONTHS)) ?></p>
<form class="formular" method="post" action="<?= e($module->url('report_build')) ?>">
<?= $csrf ?>
<?php if ($months === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'statistika', 'heading' => t('No fleet report yet'),
    'text' => t('The reports for %s are made on the 1st from 6 a.m., once the console has history of that month (from Kaleta 3.17 on).', ReportPage::monthName($previous))]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Month')) ?></th><th scope="col"><?= e(t('Customer')) ?></th><th scope="col" class="cislo"><?= e(t('Sites')) ?></th><th scope="col" class="cislo"><?= e(t('Made')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($months as $month => $rows): ?>
<?php foreach ($rows as $i => $r): $params = ['month' => $r['month'], 'customer' => $r['customer']]; ?>
<tr>
	<td><?php if ($i === 0): ?><strong><?= e(ReportPage::monthName($month)) ?></strong><?php endif ?></td>
	<td><?= e(Report::customerName($r['customer'])) ?></td>
	<td class="cislo"><?= (int) $r['sites'] ?></td>
	<td class="cislo"><?= e(format_date($r['created_at'], true)) ?><?php if ($r['rebuilt_at'] !== null): ?><br><small><?= e(t('rebuilt %s', format_date($r['rebuilt_at'], true))) ?></small><?php endif ?></td>
	<td><a href="<?= e($module->url('report_view', $params)) ?>" target="_blank" rel="noopener"><strong><?= e(t('View and print')) ?></strong></a> · <a href="<?= e($module->url('report_download', $params)) ?>"><?= e(t('Download')) ?></a>
	<?php if ($i === 0): ?><br><button class="navigace" type="submit" name="month" value="<?= e($month) ?>" data-potvrdit="<?= e(t('Rebuild the reports for %s from the history still kept? The stored reports are replaced.', ReportPage::monthName($month))) ?>"><?= e(t('Rebuild the month')) ?></button><?php endif ?></td>
</tr>
<?php endforeach ?>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php if (!isset($months[$previous])): ?>
<p><button class="tl" type="submit"><?= e(t('Make the reports for %s now', ReportPage::monthName($previous))) ?></button></p>
<?php endif ?>
</form>
