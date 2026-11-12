<?php
/**
 * The statistics of job applications (3.40, design B10): the last twelve months – received, invited to an interview,
 * offers, hired and rejected per month (each status once per application and month) – and the same per position. Counts
 * only, from ka_application_counts (Core\ApplicationCounts): they stay after the retention deleted the applications, and
 * nothing here names a person.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Applications $module
 * @var array{months: array<string, array<string, int>>, total: array<string, int>}|null $months
 * @var list<array<string, int|string>> $positions
 * @var bool $admin
 */
use Kaleta\Core\ApplicationCounts;

$heads = ['received' => t('Received'), 'interview' => t('Interview'), 'offer' => t('Offer'), 'hired' => t('Hired'), 'rejected' => t('Rejected')];
$monthName = fn (string $month): string => Kaleta\Core\ReportPage::monthName($month);
?>
<?= $app->view->render('admin/applications/tabs', ['module' => $module, 'current' => 'stats', 'admin' => $admin]) ?>
<p class="napoveda"><?= e(t('Counts only – they stay when the retention deletes the applications. A status counts once per application and month.')) ?></p>
<?php if ($months === null || $months['total']['received'] + $months['total']['interview'] + $months['total']['offer'] + $months['total']['hired'] + $months['total']['rejected'] === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'uzivatele', 'heading' => t('Nothing to count yet.'),
    'text' => t('The numbers appear here as applications arrive and move through their statuses.')]) ?>
<?php else: ?>
<h2 id="uchazeci-mesice-nadpis"><?= e(t('The last %d months', ApplicationCounts::MONTHS)) ?></h2>
<div class="tab-obal">
<table class="vypis vypis-karty" data-applications-stats aria-labelledby="uchazeci-mesice-nadpis">
<thead><tr><th scope="col"><?= e(t('Month')) ?></th><?php foreach ($heads as $head): ?><th scope="col" class="cislo"><?= e($head) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach (array_reverse($months['months'], true) as $month => $row): ?>
<tr data-stats-month="<?= e($month) ?>"><th scope="row"><?= e($monthName($month)) ?></th>
<?php foreach ($heads as $key => $head): ?>
	<td class="cislo" data-popisek="<?= e($head) ?>"><?= format_count($row[$key]) ?></td>
<?php endforeach ?>
</tr>
<?php endforeach ?>
</tbody>
<tfoot><tr><th scope="row"><?= e(t('Total')) ?></th>
<?php foreach ($heads as $key => $head): ?>
	<td class="cislo" data-popisek="<?= e($head) ?>"><strong><?= format_count($months['total'][$key]) ?></strong></td>
<?php endforeach ?>
</tr></tfoot>
</table>
</div>
<?php if ($positions !== []): ?>
<h2 id="uchazeci-pozice-nadpis"><?= e(t('By position')) ?></h2>
<div class="tab-obal">
<table class="vypis vypis-karty" data-applications-stats-positions aria-labelledby="uchazeci-pozice-nadpis">
<thead><tr><th scope="col"><?= e(t('Position')) ?></th><?php foreach ($heads as $head): ?><th scope="col" class="cislo"><?= e($head) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach ($positions as $p): ?>
<tr><th scope="row"><?= (int) $p['job_item_id'] === 0 ? '<span class="napoveda">' . e(t('No position')) . '</span>' : bdi((string) $p['job_title'] !== '' ? (string) $p['job_title'] : '#' . (int) $p['job_item_id']) ?></th>
<?php foreach ($heads as $key => $head): ?>
	<td class="cislo" data-popisek="<?= e($head) ?>"><?= format_count((int) $p[$key]) ?></td>
<?php endforeach ?>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<?php endif ?>
