<?php
/**
 * Which collections feed the internal price list (3.42, administrators; on Quotes → Settings and Internal prices): any
 * collection, several at once – each with the field that holds the product code (none = the item's address is its code)
 * and, when it has a suitable field, the variants ("name | code | price" per line). A collection made from the products
 * preset is always in the price list. Only the mapping is stored (Prices::saveMapping) – the prices stay in the price tables.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $csrf
 * @var string $back settings | prices
 * @var array<int, array{collection: int, code: string, variants: string}> $mapping
 * @var list<array{idk: int, name: string, preset: bool, items: int, code: list<array{key: string, label: string}>, variants: list<array{key: string, label: string}>}> $mappable
 */
?>
<form method="post" action="<?= e($module->url('collections_save')) ?>" id="price-collections" data-price-collections>
	<?= $csrf ?><input type="hidden" name="back" value="<?= e($back) ?>">
	<p class="napoveda"><?= e(t('Every item of a chosen collection gets a row in the price list with an empty price to fill in; enquiries sent from its detail page, or naming it in a field, lead to a prepared quote. The site never shows these prices.')) ?></p>
<?php if ($mappable === []): ?>
	<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('No collections yet.'), 'text' => t('Create a collection of your products in Collections first.')]) ?>
<?php else: ?>
	<div class="tab-obal">
	<table class="vypis vypis-karty">
	<thead><tr><th scope="col"><?= e(t('In the price list')) ?></th><th scope="col"><?= e(t('Collection')) ?></th><th scope="col"><?= e(t('Product code')) ?></th><th scope="col"><?= e(t('Variants')) ?></th></tr></thead>
	<tbody>
<?php foreach ($mappable as $c): $m = $mapping[$c['idk']] ?? null; $id = 'cenik-kolekce-' . $c['idk']; ?>
	<tr data-price-collection="<?= $c['idk'] ?>">
		<td data-popisek="<?= e(t('In the price list')) ?>"><?php if ($c['preset']): ?><input type="checkbox" checked disabled id="<?= $id ?>" aria-describedby="<?= $id ?>-vzdy"> <span class="napoveda" id="<?= $id ?>-vzdy"><?= e(t('always (Products)')) ?></span>
			<?php else: ?><input type="checkbox" name="collection[]" value="<?= $c['idk'] ?>" id="<?= $id ?>"<?= $m !== null ? ' checked' : '' ?>><?php endif ?></td>
		<td data-popisek="<?= e(t('Collection')) ?>"><label for="<?= $id ?>"><bdi><?= e($c['name']) ?></bdi></label> <span class="napoveda"><?= e(t('Items: %s', format_count($c['items']))) ?></span></td>
		<td data-popisek="<?= e(t('Product code')) ?>"><?php if ($c['preset']): ?><?= e(t('the Code field')) ?><?php else: ?>
			<select name="code_<?= $c['idk'] ?>" aria-label="<?= e(t('Product code of %s', $c['name'])) ?>">
				<option value=""><?= e(t('the item’s address')) ?></option>
<?php foreach ($c['code'] as $f): ?>
				<option value="<?= e($f['key']) ?>"<?= ($m['code'] ?? '') === $f['key'] ? ' selected' : '' ?>><?= e($f['label']) ?></option>
<?php endforeach ?>
			</select><?php endif ?></td>
		<td data-popisek="<?= e(t('Variants')) ?>"><?php if ($c['preset']): ?><?= e(t('the Variants field')) ?><?php elseif ($c['variants'] === []): ?><span class="napoveda"><?= e(t('no suitable field')) ?></span><?php else: ?>
			<select name="variants_<?= $c['idk'] ?>" aria-label="<?= e(t('Variants of %s', $c['name'])) ?>">
				<option value=""><?= e(t('without variants')) ?></option>
<?php foreach ($c['variants'] as $f): ?>
				<option value="<?= e($f['key']) ?>"<?= ($m['variants'] ?? '') === $f['key'] ? ' selected' : '' ?>><?= e($f['label']) ?></option>
<?php endforeach ?>
			</select><?php endif ?></td>
	</tr>
<?php endforeach ?>
	</tbody>
	</table>
	</div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save the collections')) ?></button> <span class="napoveda"><?= e(t('Variants come from a field with one variant per line, written as in a Products collection; only variants with a code get their own price.')) ?></span></p>
<?php endif ?>
</form>
