<?php
/**
 * Customers – the contacts (3.28, Core\Contacts::list): search by name, e-mail, phone or company; the responsible person
 * (mine, nobody, a person), the company, the last activity and the order; what each customer has – enquiries, bookings,
 * the newsletter. A card per customer on a phone.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} $result
 * @var string $search
 * @var string $ownerKey '' | mine | nobody | a user id
 * @var string $activity a key of Contacts::ACTIVITY or ''
 * @var string $sort
 * @var int $company
 * @var string $companyName
 * @var list<array<string, mixed>> $companies for the company filter (the first 200 by name)
 * @var array<int, string> $people who may be responsible (the filter)
 * @var array<int, string> $owners names of the responsible people in the rows
 * @var array<string, int|string> $query the current filter (paging keeps it)
 */
use Kaleta\Admin\Modules\Contacts;

$filtered = $search !== '' || $ownerKey !== '' || $activity !== '' || $company > 0;
?>
<?= $app->view->render('admin/contacts/tabs', ['app' => $app, 'module' => $module, 'current' => '']) ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr" data-contacts-filter>
	<input type="hidden" name="module" value="contacts">
	<label class="zakaznici-hledat"><span><?= e(t('Search')) ?></span> <input class="textpole" type="search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Name, e-mail, phone or company')) ?>"></label>
	<label><span><?= e(t('Responsible person')) ?></span> <select name="owner">
		<option value=""><?= e(t('Anyone')) ?></option>
		<option value="mine"<?= $ownerKey === 'mine' ? ' selected' : '' ?>><?= e(t('Mine')) ?></option>
		<option value="nobody"<?= $ownerKey === 'nobody' ? ' selected' : '' ?>><?= e(t('Nobody')) ?></option>
<?php foreach ($people as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $ownerKey === (string) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
<?php if ($companies !== [] || $company > 0): ?>
	<label><span><?= e(t('Company')) ?></span> <select name="company">
		<option value=""><?= e(t('Any')) ?></option>
<?php if ($company > 0 && !in_array($company, array_column($companies, 'id'), true)): ?>
		<option value="<?= $company ?>" selected><?= e($companyName) ?></option>
<?php endif ?>
<?php foreach ($companies as $co): ?>
		<option value="<?= (int) $co['id'] ?>"<?= $company === $co['id'] ? ' selected' : '' ?>><?= e($co['name']) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
	<label><span><?= e(t('Last activity')) ?></span> <select name="activity">
		<option value=""><?= e(t('Any time')) ?></option>
<?php foreach (Contacts::ACTIVITY as $key => [, , $label]): ?>
		<option value="<?= e($key) ?>"<?= $activity === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select></label>
	<label><span><?= e(t('Order')) ?></span> <select name="sort">
<?php foreach (Contacts::SORT_LABELS as $key => $label): ?>
		<option value="<?= e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select></label>
	<p class="zakaznici-filtr-tlacitka"><button class="tl" type="submit"><?= e(t('Filtrovat')) ?></button><?php if ($filtered): ?> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Clear filter')) ?></a><?php endif ?></p>
</form>
<?php if ($result['rows'] === [] && $filtered): ?>
<?= $app->view->render('admin/empty', ['icon' => 'zakaznici', 'heading' => t('No customer matches the filter.'), 'text' => t('Try another word or clear the filter.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($result['rows'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'zakaznici', 'heading' => t('No customers yet.'),
    'text' => t('Everyone who sends an enquiry, books an appointment or subscribes to the newsletter becomes a customer here – one record per e-mail address. You can also add one by hand.'),
    'action' => [$module->url('new'), t('New customer')]]) ?>
<?php else: ?>
<p class="napoveda" data-contacts-total><?= e(t('Customers: %s', format_count($result['total']))) ?></p>
<div class="tab-obal">
<table class="vypis vypis-karty" data-contacts-list>
<thead><tr><th scope="col"><?= e(t('Full name')) ?></th><th scope="col"><?= e(t('Company')) ?></th><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Responsible person')) ?></th>
	<th scope="col"><?= e(t('Last activity')) ?></th><th scope="col"><?= e(t('Enquiries')) ?></th><th scope="col"><?= e(t('Bookings')) ?></th><th scope="col"><?= e(t('Newsletter')) ?></th></tr></thead>
<tbody>
<?php foreach ($result['rows'] as $c): ?>
<tr data-contact="<?= (int) $c['id'] ?>">
	<td><a href="<?= e($module->url('detail', ['id' => $c['id']])) ?>"><?= bdi($c['name'] !== '' ? $c['name'] : ($c['email'] !== '' ? $c['email'] : '#' . $c['id'])) ?></a></td>
	<td data-popisek="<?= e(t('Company')) ?>"><?= $c['company_id'] !== null ? '<a href="' . e($module->url('company', ['id' => $c['company_id']])) . '">' . bdi((string) $c['company_name']) . '</a>' : '' ?></td>
	<td data-popisek="<?= e(t('E-mail')) ?>"><?= $c['email'] !== '' ? bdi($c['email']) : '' ?></td>
	<td data-popisek="<?= e(t('Responsible person')) ?>"><?= $c['owner_id'] !== null ? e($owners[$c['owner_id']] ?? '') : '' ?></td>
	<td data-popisek="<?= e(t('Last activity')) ?>"><?= $c['last_activity_at'] !== null ? e(format_date($c['last_activity_at'])) : '' ?></td>
	<td class="cislo" data-popisek="<?= e(t('Enquiries')) ?>"><?= $c['enquiries'] > 0 ? (int) $c['enquiries'] : '' ?></td>
	<td class="cislo" data-popisek="<?= e(t('Bookings')) ?>"><?= $c['bookings'] > 0 ? (int) $c['bookings'] : '' ?></td>
	<td data-popisek="<?= e(t('Newsletter')) ?>"><?= $c['subscribed'] ? '<span class="stitek stitek-vydano">' . e(t('subscribed')) . '</span>' : '' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($result['pages'] > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $result['pages']; $s++): ?>
	<?= $s === $result['page'] ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', $query + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
