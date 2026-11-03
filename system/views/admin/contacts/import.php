<?php
/**
 * Import customers from CSV (3.28, administrators): the file, then the preview with what saving would do – new, updated,
 * skipped and why – and Import or Cancel. A row is matched by its e-mail address only; two different addresses are never
 * merged. The file is kept for a day at most.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $csrf
 * @var string $token the uploaded file ('' = the upload form)
 * @var string $file its name
 * @var array{rows: list<array<string, mixed>>, new: int, update: int, skip: int}|null $plan
 */
use Kaleta\Admin\Modules\Contacts;

$actions = ['new' => ['Will be added', 'stitek-vydano'], 'update' => ['Will be updated', 'stitek-koncept'], 'skip' => ['Will be skipped', '']];
$fieldNames = ['name' => 'name', 'phone' => 'phone', 'company' => 'company'];
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All customers')) ?></a></p>
<?php if ($plan === null): ?>
<p class="napoveda"><?= e(t('A CSV file (from Excel, Google Sheets or another CRM) whose first row names the columns: e-mail, name, phone, company. A customer is found by the e-mail address – an existing one gets what is new, a row without a valid address is skipped. Nothing is saved before you see the preview.')) ?></p>
<form class="formular" method="post" action="<?= e($module->url('import_upload')) ?>" enctype="multipart/form-data" data-import-form>
	<?= $csrf ?>
	<div class="radek"><label for="import-soubor"><?= e(t('CSV file')) ?></label><div><input type="file" id="import-soubor" name="file" accept=".csv,text/csv,text/plain"></div></div>
	<div class="radek"><label for="import-text"><?= e(t('or paste the rows')) ?></label><div><textarea class="textbox nizky" id="import-text" name="text" rows="5" placeholder="email;name;phone;company"></textarea></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Show the preview')) ?></button></p>
</form>
<?php else: ?>
<p data-import-file><?= e(t('File: %s', $file)) ?></p>
<ul class="import-souhrn" data-import-counts>
	<li data-import-count="new"><strong><?= format_count($plan['new']) ?></strong> <?= e(t('new customers')) ?></li>
	<li data-import-count="update"><strong><?= format_count($plan['update']) ?></strong> <?= e(t('updated')) ?></li>
	<li data-import-count="skip"><strong><?= format_count($plan['skip']) ?></strong> <?= e(t('skipped')) ?></li>
</ul>
<div class="tab-obal">
<table class="vypis vypis-karty" data-import-rows>
<thead><tr><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Full name')) ?></th><th scope="col"><?= e(t('Phone')) ?></th><th scope="col"><?= e(t('Company')) ?></th><th scope="col"><?= e(t('What happens')) ?></th></tr></thead>
<tbody>
<?php foreach (array_slice($plan['rows'], 0, Contacts::IMPORT_SAMPLE) as $row): [$label, $class] = $actions[$row['action']]; ?>
<tr data-import-row="<?= e($row['action']) ?>">
	<td><?= $row['email'] !== '' ? bdi($row['email']) : '<span class="napoveda">—</span>' ?></td>
	<td data-popisek="<?= e(t('Full name')) ?>"><?= bdi($row['name']) ?></td>
	<td data-popisek="<?= e(t('Phone')) ?>"><?= bdi($row['phone']) ?></td>
	<td data-popisek="<?= e(t('Company')) ?>"><?= bdi($row['company']) ?></td>
	<td data-popisek="<?= e(t('What happens')) ?>"><span class="stitek <?= $class ?>"><?= e(t($label)) ?></span>
		<?= $row['action'] === 'update' ? e(implode(', ', array_map(fn (string $f): string => t($fieldNames[$f] ?? $f), $row['changes']))) : '' ?>
		<?= $row['reason'] !== '' ? '<span class="napoveda">' . e(t($row['reason'])) . '</span>' : '' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if (count($plan['rows']) > Contacts::IMPORT_SAMPLE): ?>
<p class="napoveda"><?= e(t('The first %d of %s rows.', Contacts::IMPORT_SAMPLE, format_count(count($plan['rows'])))) ?></p>
<?php endif ?>
<form class="tlacitka" method="post" action="<?= e($module->url('import_run')) ?>" data-import-run>
	<?= $csrf ?><input type="hidden" name="file" value="<?= e($token) ?>">
	<button class="tl" type="submit" name="op" value="run"<?= $plan['new'] + $plan['update'] === 0 ? ' disabled' : '' ?>><?= e(t('Import')) ?></button>
	<button class="navigace" type="submit" name="op" value="cancel"><?= e(t('Cancel')) ?></button>
</form>
<p class="napoveda"><?= e(t('New customers get the source “import”. The change log keeps only the counts.')) ?></p>
<?php endif ?>
