<?php
/**
 * Job applications (3.34, Core\Applications::list): tabs by status with their counts, the filter (position, responsible
 * person, kept for future openings, a word), the number, received, name, position, status, responsible person and the day
 * it will be deleted; paging. A card per application on a phone. Administrators are told when nobody else has the section.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Applications $module
 * @var array{rows: list<array<string, mixed>>, total: int, filter: array{status: string, job: string, assignee: string, pool: bool, search: string}} $result
 * @var int $page
 * @var int $pages
 * @var array<string, int> $counts Core\Applications::counts
 * @var list<array{job_item_id: ?int, job_title: string, open: int, all: int}> $jobs
 * @var array<int, string> $people who may be responsible
 * @var array<int, string> $names the responsible people of the rows
 * @var int $me
 * @var bool $nobody nobody but administrators may open applications
 */
use Kaleta\Admin\Modules\Applications;
use Kaleta\Core\Applications as Store;

$filter = $result['filter'];
$query = array_filter(['status' => $filter['status'], 'job' => $filter['job'], 'assignee' => $filter['assignee'], 'pool' => $filter['pool'] ? '1' : '', 'search' => $filter['search']], fn (string $v): bool => $v !== '');
$query['status'] = $filter['status'];
$filtered = $filter['job'] !== '' || $filter['assignee'] !== '' || $filter['pool'] || $filter['search'] !== '';
?>
<?php if ($nobody): ?>
<p class="hlaska hlaska-varovani" data-applications-nobody><?= $module->hint(t('Only administrators can open job applications now. Choose who else may: tick Applications for them in Administration → Users, or give them the ready-made role HR in Administration → Roles.')) ?></p>
<?php endif ?>
<nav class="zalozky" aria-label="<?= e(t('Applications')) ?>" data-applications-tabs>
<?php foreach (Applications::TABS as $status => $label): $n = $status === '' ? $counts['all'] : $counts[$status] ?? 0; ?>
	<a href="<?= e($module->url('', ['status' => $status] + array_diff_key($query, ['status' => 1]))) ?>"<?= $filter['status'] === $status ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($label)) ?> <small><?= format_count($n) ?></small></a>
<?php endforeach ?>
</nav>
<?php if ($counts['waiting'] > 0): ?>
<p class="napoveda" data-applications-waiting><?= e(t('Waiting more than %d days without a reply: %s', Store::WAITING_DAYS, format_count($counts['waiting']))) ?></p>
<?php endif ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr" data-applications-filter>
	<input type="hidden" name="module" value="<?= e(Store::SECTION) ?>"><input type="hidden" name="status" value="<?= e($filter['status']) ?>">
	<label class="zakaznici-hledat"><span><?= e(t('Search')) ?></span> <input class="textpole" type="search" name="search" value="<?= e($filter['search']) ?>" placeholder="<?= e(t('Name, e-mail or text')) ?>"></label>
	<label><span><?= e(t('Position')) ?></span> <select name="job">
		<option value=""><?= e(t('Any')) ?></option>
<?php foreach ($jobs as $j): $value = $j['job_item_id'] !== null ? (string) $j['job_item_id'] : 'none'; ?>
		<option value="<?= e($value) ?>"<?= $filter['job'] === $value ? ' selected' : '' ?>><?= e($j['job_item_id'] !== null ? ($j['job_title'] !== '' ? $j['job_title'] : '#' . $j['job_item_id']) : t('No position')) ?> (<?= format_count($j['open']) ?>/<?= format_count($j['all']) ?>)</option>
<?php endforeach ?>
	</select></label>
	<label><span><?= e(t('Responsible person')) ?></span> <select name="assignee">
		<option value=""><?= e(t('Anyone')) ?></option>
		<option value="<?= $me ?>"<?= $filter['assignee'] === (string) $me ? ' selected' : '' ?>><?= e(t('Mine')) ?></option>
		<option value="none"<?= $filter['assignee'] === 'none' ? ' selected' : '' ?>><?= e(t('Nobody')) ?></option>
<?php foreach ($people as $id => $name): if ((int) $id === $me) { continue; } ?>
		<option value="<?= (int) $id ?>"<?= $filter['assignee'] === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
	<label><input type="checkbox" name="pool" value="1"<?= $filter['pool'] ? ' checked' : '' ?>> <?= e(t('Kept for future openings')) ?></label>
	<button class="navigace" type="submit"><?= e(t('Filter')) ?></button>
<?php if ($filtered): ?>
	<a class="navigace" href="<?= e($module->url('', ['status' => $filter['status']])) ?>"><?= e(t('Clear filter')) ?></a>
<?php endif ?>
</form>
<?php if ($result['rows'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'uzivatele', 'heading' => $filtered ? t('No application matches the filter.') : t('No applications here.'),
    'text' => t('Applications come from the forms on your job pages and from forms whose Purpose is “Job application”. They never become customers or deals.')]) ?>
<?php else: ?>
<p class="napoveda" data-applications-total><?= e(t('Applications: %s', format_count($result['total']))) ?></p>
<div class="tab-obal">
<table class="vypis vypis-karty" data-applications-list>
<thead><tr><th scope="col"><?= e(t('Number')) ?></th><th scope="col"><?= e(t('Received')) ?></th><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Position')) ?></th>
	<th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Responsible person')) ?></th><th scope="col"><?= e(t('Will be deleted')) ?></th></tr></thead>
<tbody>
<?php foreach ($result['rows'] as $r): ?>
<tr data-application="<?= (int) $r['id'] ?>">
	<td><a href="<?= e($module->url('detail', ['id' => $r['id']])) ?>">#<?= (int) $r['id'] ?></a></td>
	<td data-popisek="<?= e(t('Received')) ?>"><?= e(format_date((string) $r['received_at'])) ?></td>
	<td data-popisek="<?= e(t('Name')) ?>"><a href="<?= e($module->url('detail', ['id' => $r['id']])) ?>"><?= bdi((string) $r['name'] !== '' ? (string) $r['name'] : (string) $r['email']) ?></a></td>
	<td data-popisek="<?= e(t('Position')) ?>"><?= (string) $r['job_title'] !== '' ? bdi((string) $r['job_title']) : '<span class="napoveda">' . e(t('No position')) . '</span>' ?><?= $r['late'] ? ' <span class="stitek">' . e(t('after the closing date')) . '</span>' : '' ?></td>
	<td data-popisek="<?= e(t('Status')) ?>"><span class="stitek<?= $r['status'] === 'hired' ? ' stitek-vydano' : ($r['status'] === 'rejected' ? ' stitek-chyba' : ($r['status'] === 'new' ? ' stitek-koncept' : '')) ?>"><?= e(t(Store::STATUS_LABELS[$r['status']] ?? (string) $r['status'])) ?></span></td>
	<td data-popisek="<?= e(t('Responsible person')) ?>"><?= $r['assignee_id'] !== null ? e($names[$r['assignee_id']] ?? '') : '' ?></td>
	<td data-popisek="<?= e(t('Will be deleted')) ?>"><?= $r['deletes_on'] !== null ? e(format_date($r['deletes_on'])) : '' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $pages; $s++): ?>
	<?= $s === $page ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', $query + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
