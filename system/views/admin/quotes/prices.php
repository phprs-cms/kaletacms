<?php
/**
 * Internal prices (3.38, Core\Prices): the grid of one price list – the products of the products collections with their
 * variants (a variant whose code the product no longer has is marked), the own rows of the list, a filter (collection,
 * word, without a price), the CSV export and import with its preview, and the price lists (administrators). Without the
 * permission to change prices the grid is read-only. Prices never leave the admin: no page of the site reads them.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $csrf
 * @var list<array{id: int, name: string, currency: string, is_default: bool, note: string, archived_at: ?string, prices: int}> $lists
 * @var array{id: int, name: string, currency: string, is_default: bool, note: string, archived_at: ?string, prices: int} $list
 * @var array{collection: int, search: string, missing: bool} $filter
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} $grid
 * @var list<array<string, mixed>> $own
 * @var list<array{idk: int, nazev: string, seo_link: string, code: string, variants: string}> $collections
 * @var list<string> $rates
 * @var string|null $defaultRate
 * @var bool $canEdit
 * @var bool $admin
 * @var array<string, mixed>|null $import
 */
use Kaleta\Core\Deals;
use Kaleta\Core\Money;

$query = array_filter(['list' => $list['id'], 'collection' => $filter['collection'], 'search' => $filter['search'], 'missing' => $filter['missing'] ? '1' : '']);
$ro = $canEdit ? '' : ' readonly';
$rateList = 'ceny-sazby';
$field = function (string $name, string $value, string $label, string $extra = '') use ($ro): string {
    return '<input class="textpole" type="text" name="' . $name . '[]" value="' . e($value) . '" aria-label="' . e($label) . '"' . $extra . $ro . '>';
};
?>
<?= $app->view->render('admin/quotes/tabs', ['module' => $module, 'current' => 'prices', 'admin' => $admin]) ?>
<p class="napoveda"><?= e(t('Prices without VAT that nobody outside sees – no page, search, export or blueprint of the site reads them. Quotes take their prices from here.')) ?><?php if ($defaultRate === null && $admin): ?>
	<a href="<?= e($module->url('settings')) ?>"><?= e(t('Set the default VAT rate.')) ?></a><?php endif ?></p>

<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr" data-prices-filter>
	<input type="hidden" name="module" value="<?= e($module::IDENT) ?>"><input type="hidden" name="action" value="prices">
	<label><span><?= e(t('Internal price list')) ?></span> <select name="list">
<?php foreach ($lists as $l): ?>
		<option value="<?= $l['id'] ?>"<?= $l['id'] === $list['id'] ? ' selected' : '' ?>><?= e($l['name'] . ' (' . $l['currency'] . ')') ?><?= $l['archived_at'] !== null ? ' – ' . e(t('archived')) : '' ?></option>
<?php endforeach ?>
	</select></label>
<?php if (count($collections) > 1): ?>
	<label><span><?= e(t('Collection')) ?></span> <select name="collection">
		<option value=""><?= e(t('All products')) ?></option>
<?php foreach ($collections as $c): ?>
		<option value="<?= $c['idk'] ?>"<?= $filter['collection'] === $c['idk'] ? ' selected' : '' ?>><?= e($c['nazev']) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
	<label class="zakaznici-hledat"><span><?= e(t('Search')) ?></span> <input class="textpole" type="search" name="search" value="<?= e($filter['search']) ?>" placeholder="<?= e(t('Product name or code')) ?>"></label>
	<label><input type="checkbox" name="missing" value="1"<?= $filter['missing'] ? ' checked' : '' ?>> <?= e(t('Without a price')) ?></label>
	<button class="tl" type="submit"><?= e(t('Filter')) ?></button>
</form>

<?php if ($import !== null): ?>
<section class="hlaska" data-prices-import-preview aria-labelledby="ceny-import-nahled">
	<h2 id="ceny-import-nahled"><?= e(t('Check the import: %s', $import['name'])) ?></h2>
	<p><?= e(t('New: %1$d, changed: %2$d, the same: %3$d, unknown codes: %4$d, not valid: %5$d.', $import['new'], $import['changed'], $import['same'], $import['unknown'], $import['invalid'])) ?></p>
<?php $problems = array_values(array_filter($import['rows'], fn (array $r): bool => in_array($r['status'], ['unknown', 'invalid'], true))); if ($problems !== []): ?>
	<ul>
<?php foreach (array_slice($problems, 0, 20) as $p): ?>
		<li><?= e(t('Line %d', $p['line'])) ?>: <bdi><?= e($p['code'] . ($p['variant'] !== '' ? ' / ' . $p['variant'] : '')) ?></bdi> – <?= e($p['status'] === 'unknown' ? t('no product or own row has this code') : $p['reason']) ?></li>
<?php endforeach ?>
	</ul>
<?php endif ?>
	<form method="post" action="<?= e($module->url('prices_import_run')) ?>" class="vradku"><?= $csrf ?><input type="hidden" name="list" value="<?= $list['id'] ?>">
		<button class="tl" type="submit"<?= $import['new'] + $import['changed'] === 0 ? ' disabled' : '' ?>><?= e(t('Import %d prices', $import['new'] + $import['changed'])) ?></button></form>
	<form method="post" action="<?= e($module->url('prices_import')) ?>" class="vradku"><?= $csrf ?><input type="hidden" name="list" value="<?= $list['id'] ?>"><input type="hidden" name="discard" value="1">
		<button class="navigace" type="submit"><?= e(t('Discard')) ?></button></form>
</section>
<?php endif ?>

<?php if ($collections === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('No products yet.'),
    'text' => t('Internal prices belong to the items of a Products collection (Collections → New collection → Products) and to own rows such as installation or transport below.')]) ?>
<?php elseif ($grid['rows'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('No product matches the filter.'), 'text' => t('Try another word or clear the filter.'),
    'action' => [$module->url('prices', ['list' => $list['id']]), t('Clear filter')]]) ?>
<?php endif ?>

<form method="post" action="<?= e($module->url('prices_save')) ?>" data-prices-grid>
	<?= $csrf ?>
	<input type="hidden" name="list" value="<?= $list['id'] ?>">
<?php foreach (['collection' => $filter['collection'] ?: '', 'search' => $filter['search'], 'missing' => $filter['missing'] ? '1' : '', 'page' => $grid['page'] > 1 ? (string) $grid['page'] : ''] as $k => $v): if ($v !== '') { ?>
	<input type="hidden" name="<?= $k ?>" value="<?= e((string) $v) ?>">
<?php } endforeach ?>
	<datalist id="<?= $rateList ?>"><?php foreach ($rates as $r): ?><option value="<?= e(Money::short($r)) ?>"></option><?php endforeach ?></datalist>
<?php if ($grid['rows'] !== []): ?>
	<p class="napoveda" data-prices-total><?= e(t('Products: %s', format_count($grid['total']))) ?> · <?= e(t('Prices in %s, without VAT. An empty VAT rate = the default rate%s.', $list['currency'], $defaultRate !== null ? ' (' . Money::short($defaultRate) . ' %)' : '')) ?></p>
	<div class="tab-obal">
	<table class="vypis ceny">
	<thead><tr><th scope="col"><?= e(t('Product')) ?></th><th scope="col"><?= e(t('Code')) ?></th><th scope="col" class="cislo"><?= e(t('Price')) ?></th><th scope="col"><?= e(t('Unit')) ?></th>
		<th scope="col"><?= e(t('VAT %')) ?></th><th scope="col"><?= e(t('Internal note')) ?></th></tr></thead>
	<tbody>
<?php foreach ($grid['rows'] as $item): foreach ($item['lines'] as $line): $p = $line['price']; $label = $line['variant'] === '' ? $item['name'] : $item['name'] . ' – ' . ($line['name'] !== '' ? $line['name'] : $line['variant']); ?>
	<tr data-price-row="<?= $item['idp'] ?>:<?= e($line['variant']) ?>"<?= $line['variant'] !== '' ? ' class="ceny-varianta"' : '' ?>>
		<td><?php if ($line['variant'] === ''): ?><a href="<?= e($app->url('admin.php?' . http_build_query(['module' => 'collections', 'action' => 'item', 'id' => $item['idk'], 'item' => $item['idp']]))) ?>"><bdi><?= e($item['name']) ?></bdi></a><?= $item['language'] !== '' ? ' <span class="stitek">' . e(strtoupper($item['language'])) . '</span>' : '' ?><?= !$item['visible'] ? ' <span class="stitek">' . e(t('hidden')) . '</span>' : '' ?>
			<?php else: ?>↳ <bdi><?= e($line['name'] !== '' ? $line['name'] : $line['variant']) ?></bdi><?= $line['gone'] ? ' <span class="stitek stitek-chyba">' . e(t('variant no longer exists')) . '</span>' : '' ?><?php endif ?>
			<input type="hidden" name="item[]" value="<?= $item['idp'] ?>"><input type="hidden" name="variant[]" value="<?= e($line['variant']) ?>"><input type="hidden" name="code[]" value=""><input type="hidden" name="label[]" value=""></td>
		<td><bdi><?= e($line['code']) ?></bdi></td>
		<td class="cislo"><?= $field('price', $p !== null ? $p['unit_price'] : '', t('Price of %s', $label), ' inputmode="decimal"' . ($p === null ? ' placeholder="—"' : '')) ?></td>
		<td><?= $field('unit', $p['unit'] ?? '', t('Unit of %s', $label), ' maxlength="20"') ?></td>
		<td><?= $field('vat', $p !== null && $p['vat_rate'] !== null ? Money::short($p['vat_rate']) : '', t('VAT rate of %s', $label), ' list="' . $rateList . '" inputmode="decimal"') ?></td>
		<td><?= $field('note', $p['note'] ?? '', t('Internal note of %s', $label), ' maxlength="200"') ?></td>
	</tr>
<?php endforeach; endforeach ?>
	</tbody>
	</table>
	</div>
<?php if ($grid['pages'] > 1): ?>
	<p class="strankovani">
<?php for ($n = 1; $n <= $grid['pages']; $n++): ?>
		<?= $n === $grid['page'] ? '<strong>[' . $n . ']</strong>' : '<a href="' . e($module->url('prices', $query + ['page' => $n])) . '">' . $n . '</a>' ?>
<?php endfor ?>
	</p>
<?php endif ?>
<?php endif ?>

	<h2 id="ceny-vlastni"><?= e(t('Own rows')) ?></h2>
	<p class="napoveda"><?= e(t('Services and items that are not products – installation, transport, training. A quote adds them by their code.')) ?></p>
<?php if ($own !== [] || $canEdit): ?>
	<div class="tab-obal">
	<table class="vypis ceny" data-prices-own>
	<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Code')) ?></th><th scope="col" class="cislo"><?= e(t('Price')) ?></th><th scope="col"><?= e(t('Unit')) ?></th>
		<th scope="col"><?= e(t('VAT %')) ?></th><th scope="col"><?= e(t('Internal note')) ?></th></tr></thead>
	<tbody>
<?php foreach ($own as $o): ?>
	<tr data-price-own="<?= e($o['code']) ?>">
		<td><?= $field('label', $o['label'], t('Name of %s', $o['code']), ' maxlength="200"') ?><input type="hidden" name="item[]" value="0"><input type="hidden" name="variant[]" value=""></td>
		<td><bdi><?= e($o['code']) ?></bdi><input type="hidden" name="code[]" value="<?= e($o['code']) ?>"></td>
		<td class="cislo"><?= $field('price', $o['unit_price'], t('Price of %s', $o['code']), ' inputmode="decimal"') ?></td>
		<td><?= $field('unit', $o['unit'], t('Unit of %s', $o['code']), ' maxlength="20"') ?></td>
		<td><?= $field('vat', $o['vat_rate'] !== null ? Money::short($o['vat_rate']) : '', t('VAT rate of %s', $o['code']), ' list="' . $rateList . '" inputmode="decimal"') ?></td>
		<td><?= $field('note', $o['note'], t('Internal note of %s', $o['code']), ' maxlength="200"') ?></td>
	</tr>
<?php endforeach ?>
<?php if ($canEdit): ?>
	<tr data-price-new>
		<td><input class="textpole" type="text" name="new_label" maxlength="200" placeholder="<?= e(t('New own row')) ?>" aria-label="<?= e(t('Name of the new own row')) ?>"></td>
		<td><input class="textpole" type="text" name="new_code" maxlength="60" aria-label="<?= e(t('Code of the new own row')) ?>"></td>
		<td class="cislo"><input class="textpole" type="text" name="new_price" inputmode="decimal" aria-label="<?= e(t('Price of the new own row')) ?>"></td>
		<td><input class="textpole" type="text" name="new_unit" maxlength="20" aria-label="<?= e(t('Unit of the new own row')) ?>"></td>
		<td><input class="textpole" type="text" name="new_vat" list="<?= $rateList ?>" inputmode="decimal" aria-label="<?= e(t('VAT rate of the new own row')) ?>"></td>
		<td><input class="textpole" type="text" name="new_note" maxlength="200" aria-label="<?= e(t('Internal note of the new own row')) ?>"></td>
	</tr>
<?php endif ?>
	</tbody>
	</table>
	</div>
<?php endif ?>
<?php if ($canEdit): ?>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save prices')) ?></button> <span class="napoveda"><?= e(t('An empty price removes it.')) ?></span></p>
<?php else: ?>
	<p class="napoveda" data-prices-readonly><?= e(t('You can read the prices; changing them needs the permission “Quotes: change internal prices” (Users or Roles).')) ?></p>
<?php endif ?>
</form>

<details class="pokrocile" data-prices-csv>
<summary><?= e(t('CSV export and import')) ?></summary>
<p><a class="navigace" href="<?= e($module->url('prices_export', ['list' => $list['id']])) ?>"><?= e(t('Download the price list as CSV')) ?></a></p>
<?php if ($canEdit): ?>
<form method="post" action="<?= e($module->url('prices_import')) ?>" enctype="multipart/form-data" class="formular">
	<?= $csrf ?><input type="hidden" name="list" value="<?= $list['id'] ?>">
	<div class="radek"><label for="ceny-csv"><?= e(t('CSV file')) ?></label><div><input type="file" id="ceny-csv" name="csv" accept=".csv,text/csv" required>
		<span class="napoveda"><?= e(t('Columns: code, variant, price, unit, vat, note, name (the header names them; only code and price are needed). An empty price removes the price; an unknown code with a name becomes a new own row. You see what will change before anything is written.')) ?></span></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Check the file')) ?></button></p>
</form>
<?php endif ?>
</details>

<?php if ($admin): ?>
<details class="pokrocile" data-price-lists>
<summary><?= e(t('Price lists (%d)', count($lists))) ?></summary>
<p class="napoveda"><?= e(t('Each price list has one currency; a quote uses one list. An archived list is not offered for new quotes – its prices and quotes stay.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Currency')) ?></th><th scope="col" class="cislo"><?= e(t('Prices')) ?></th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($lists as $l): ?>
<tr data-price-list="<?= $l['id'] ?>"><td><bdi><?= e($l['name']) ?></bdi><?= $l['is_default'] ? ' <span class="stitek stitek-vydano">' . e(t('default')) . '</span>' : '' ?><?= $l['archived_at'] !== null ? ' <span class="stitek">' . e(t('archived')) . '</span>' : '' ?></td>
	<td><?= e($l['currency']) ?></td><td class="cislo"><?= format_count($l['prices']) ?></td>
	<td><?php if (!$l['is_default'] && $l['archived_at'] === null): ?><form method="post" action="<?= e($module->url('list_default')) ?>" class="vradku"><?= $csrf ?><input type="hidden" name="id" value="<?= $l['id'] ?>"><button class="navigace" type="submit"><?= e(t('Make default')) ?></button></form><?php endif ?>
	<?php if (!$l['is_default']): ?><form method="post" action="<?= e($module->url('list_archive')) ?>" class="vradku"><?= $csrf ?><input type="hidden" name="id" value="<?= $l['id'] ?>"><input type="hidden" name="archive" value="<?= $l['archived_at'] === null ? '1' : '0' ?>"><button class="navigace" type="submit"><?= e($l['archived_at'] === null ? t('Archive') : t('Restore')) ?></button></form><?php endif ?></td></tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<form method="post" action="<?= e($module->url('list_save')) ?>" class="formular" data-price-list-form>
	<?= $csrf ?><input type="hidden" name="id" value="<?= $list['id'] ?>">
	<h3><?= e(t('Edit the price list %s', $list['name'])) ?></h3>
	<div class="radek"><label for="cenik-nazev"><?= e(t('Name')) ?></label><div><input class="textpole" type="text" id="cenik-nazev" name="name" maxlength="100" required value="<?= e($list['name']) ?>"></div></div>
	<div class="radek"><label for="cenik-mena"><?= e(t('Currency')) ?></label><div><select id="cenik-mena" name="currency">
<?php foreach (Deals::CURRENCIES as $c): ?>
		<option<?= $c === $list['currency'] ? ' selected' : '' ?>><?= $c ?></option>
<?php endforeach ?>
	</select> <span class="napoveda"><?= e(t('The currency of a list with prices or quotes stays.')) ?></span></div></div>
	<div class="radek"><label for="cenik-pozn"><?= e(t('Note')) ?></label><div><input class="textpole siroke" type="text" id="cenik-pozn" name="note" maxlength="300" value="<?= e($list['note']) ?>"></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save')) ?></button></p>
</form>
<form method="post" action="<?= e($module->url('list_save')) ?>" class="formular" data-price-list-new>
	<?= $csrf ?><input type="hidden" name="id" value="0">
	<h3><?= e(t('New price list')) ?></h3>
	<div class="radek"><label for="cenik-novy"><?= e(t('Name')) ?></label><div><input class="textpole" type="text" id="cenik-novy" name="name" maxlength="100" required placeholder="<?= e(t('e.g. Dealers or EUR')) ?>"></div></div>
	<div class="radek"><label for="cenik-nova-mena"><?= e(t('Currency')) ?></label><div><select id="cenik-nova-mena" name="currency">
<?php foreach (Deals::CURRENCIES as $c): ?>
		<option<?= $c === $list['currency'] ? ' selected' : '' ?>><?= $c ?></option>
<?php endforeach ?>
	</select></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Add the price list')) ?></button></p>
</form>
</details>
<?php endif ?>
