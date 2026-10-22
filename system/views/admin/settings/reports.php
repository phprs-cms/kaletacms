<?php
/**
 * The "Reports" tab (3.16): the archive of the monthly reports (Core\ReportArchive) – view and print, download, send by
 * e-mail, rebuild – and the monthly e-mail (2.9, moved here from Mail).
 *
 * @var array{months?: list<array{month: string, created_at: string, rebuilt_at: ?string, schema_version: int}>, first?: string, previous?: string} $reports
 */
$months = $reports['months'] ?? [];
$monthName = static fn (string $month): string => Kaleta\Core\ReportPage::monthName($month);
?>
<p class="napoveda"><?= e(t('Once a month ends, the site makes a report of what happened to it: requests handled, changes by people and by Claude, updates, backups, security, the site audit, traffic, enquiries, bookings and the newsletter. It is a summary for your client – counts, titles and page addresses only, never personal data, hours or prices. Print it or save it as PDF, download it, or send it by e-mail.')) ?></p>
<h2><?= e(t('Archived reports')) ?></h2>
<?php if ($months === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'statistika', 'heading' => t('No report is stored yet'),
    'text' => t('The report for %s is made by the background jobs within an hour. Reports exist only from Kaleta 3.16 on – what earlier months were built from is kept 30 to 180 days, so they cannot be made any more.', $monthName((string) ($reports['previous'] ?? '')))]) ?>
<?php else: ?>
<p class="napoveda"><?= e(t('Reports are kept from %s on, for %d months. Earlier months were not stored: what they would be built from is kept only 30 to 180 days.', $monthName((string) ($reports['first'] ?? '')), Kaleta\Core\ReportArchive::KEEP_MONTHS)) ?></p>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Month')) ?></th><th scope="col" class="cislo"><?= e(t('Made')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($months as $m): ?>
<tr>
	<td><strong><?= e($monthName($m['month'])) ?></strong></td>
	<td class="cislo"><?= e(format_date($m['created_at'], true)) ?><?php if ($m['rebuilt_at'] !== null): ?><br><small><?= e(t('rebuilt %s', format_date($m['rebuilt_at'], true))) ?></small><?php endif ?></td>
	<td><a href="<?= e($module->url('report_view', ['month' => $m['month']])) ?>" target="_blank" rel="noopener"><strong><?= e(t('View and print')) ?></strong></a> ·
		<a href="<?= e($module->url('report_download', ['month' => $m['month']])) ?>"><?= e(t('Download')) ?></a><br>
		<button class="navigace" type="submit" name="month" value="<?= e($m['month']) ?>" formaction="<?= e($module->url('report_send')) ?>"><?= e(t('Send by e-mail')) ?></button>
		<button class="navigace" type="submit" name="month" value="<?= e($m['month']) ?>" formaction="<?= e($module->url('report_rebuild')) ?>" data-potvrdit="<?= e(t('Rebuild the report for %s from the data still available? The stored report is replaced. Kaleta keeps its sources only 30 to 180 days (the firewall log a month, the change log and the events half a year), so older details may be gone and the rebuilt report can show less.', $monthName($m['month']))) ?>"><?= e(t('Rebuild from the data still available')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<fieldset>
<legend><?= e(t('Monthly report by e-mail')) ?></legend>
<p class="napoveda"><?= e(t('In the first days of each month the site can e-mail a short version of the report about the previous month to you or your client. With the agency details from the General tab it carries the agency’s branding.')) ?></p>
<?php
$field('report_monthly', 'Send a monthly report by e-mail', 'ano', '');
$field('report_recipients', 'Recipients', 'radky', 'One address per line or separated by commas, at most 10. Empty = the site e-mail (Settings → General).', 'maxlength="2000"');
?>
<p><a class="navigace" href="<?= e($module->url('report_preview')) ?>" target="_blank" rel="noopener"><?= e(t('Preview last month')) ?></a> <button class="navigace" type="submit" formaction="<?= e($module->url('report_send')) ?>"><?= e(t('Send last month’s report now')) ?></button> <span class="smltxt"><?= e(t('Save the settings first – sending uses the saved recipients.')) ?></span></p>
</fieldset>
