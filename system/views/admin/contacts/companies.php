<?php
/**
 * Customers – the companies (3.28, Core\Contacts::companies): search by name, domain, registration or VAT number, the
 * responsible person; how many contacts each has. A card per company on a phone.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} $result
 * @var string $search
 * @var string $ownerKey '' | mine | nobody
 * @var array<int, string> $owners
 */
$filtered = $search !== '' || $ownerKey !== '';
?>
<?= $app->view->render('admin/contacts/tabs', ['app' => $app, 'module' => $module, 'current' => 'companies']) ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr">
	<input type="hidden" name="module" value="contacts"><input type="hidden" name="action" value="companies">
	<label class="zakaznici-hledat"><span><?= e(t('Search')) ?></span> <input class="textpole" type="search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Name, domain, registration or VAT number')) ?>"></label>
	<label><span><?= e(t('Responsible person')) ?></span> <select name="owner">
		<option value=""><?= e(t('Anyone')) ?></option>
		<option value="mine"<?= $ownerKey === 'mine' ? ' selected' : '' ?>><?= e(t('Mine')) ?></option>
		<option value="nobody"<?= $ownerKey === 'nobody' ? ' selected' : '' ?>><?= e(t('Nobody')) ?></option>
	</select></label>
	<p class="zakaznici-filtr-tlacitka"><button class="tl" type="submit"><?= e(t('Filtrovat')) ?></button><?php if ($filtered): ?> <a class="navigace" href="<?= e($module->url('companies')) ?>"><?= e(t('Clear filter')) ?></a><?php endif ?></p>
</form>
<?php if ($result['rows'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'zakaznici', 'heading' => $filtered ? t('No company matches the filter.') : t('No companies yet.'),
    'text' => $filtered ? t('Try another word or clear the filter.') : t('A company groups the contacts who work there. Kaleta suggests one from a contact’s e-mail domain – never for free-mail addresses such as Gmail.'),
    'action' => $filtered ? [$module->url('companies'), t('Clear filter')] : [$module->url('company_new'), t('New company')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis vypis-karty" data-companies-list>
<thead><tr><th scope="col"><?= e(t('Company')) ?></th><th scope="col"><?= e(t('Domain')) ?></th><th scope="col"><?= e(t('Registration number')) ?></th><th scope="col"><?= e(t('Responsible person')) ?></th><th scope="col"><?= e(t('Contacts')) ?></th></tr></thead>
<tbody>
<?php foreach ($result['rows'] as $co): ?>
<tr data-company="<?= (int) $co['id'] ?>">
	<td><a href="<?= e($module->url('company', ['id' => $co['id']])) ?>"><?= bdi($co['name']) ?></a></td>
	<td data-popisek="<?= e(t('Domain')) ?>"><?= e($co['domain']) ?></td>
	<td data-popisek="<?= e(t('Registration number')) ?>"><?= e($co['registration_no']) ?></td>
	<td data-popisek="<?= e(t('Responsible person')) ?>"><?= $co['owner_id'] !== null ? e($owners[$co['owner_id']] ?? '') : '' ?></td>
	<td class="cislo" data-popisek="<?= e(t('Contacts')) ?>"><?= $co['contacts'] > 0 ? '<a href="' . e($module->url('', ['company' => $co['id']])) . '">' . (int) $co['contacts'] . '</a>' : '' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($result['pages'] > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $result['pages']; $s++): ?>
	<?= $s === $result['page'] ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('companies', array_filter(['search' => $search, 'owner' => $ownerKey]) + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
