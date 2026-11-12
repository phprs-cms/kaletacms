<?php
/**
 * The box "Internal prices" on an item's form in Collections (3.38): one row per price list and per product or variant.
 * Rendered only with the feature and the section Quotes (Admin\Modules\Quotes::itemBox – its own query, so a person
 * without the section gets no price in the HTML); editable only with the permission to change prices.
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array{idp: int, idk: int, name: string, code: string, variants: list<array{name: string, code: string}>} $item
 * @var list<array{id: int, name: string, currency: string}> $lists
 * @var array<int, array<string, array<string, mixed>>> $prices list => variant code => row
 * @var bool $canEdit
 * @var list<string> $rates
 */
use Kaleta\Core\Money;

$rows = [['', '']];
foreach ($item['variants'] as $v) {
    if ($v['code'] !== '') {
        $rows[] = [$v['code'], $v['name']];
    }
}
$ro = $canEdit ? '' : ' readonly';
?>
<details class="pokrocile" id="internal-prices" open data-internal-prices>
<summary><?= e(t('Internal prices')) ?></summary>
<p class="napoveda"><?= e(t('Without VAT, never shown on the site. Quotes take their prices from here.')) ?></p>
<?php if ($lists === []): ?>
<p class="napoveda"><?= e(t('No price list yet – open Quotes → Internal prices.')) ?></p>
<?php else: ?>
<form method="post" action="<?= e($app->url('admin.php?module=quotes&action=item_prices_save')) ?>">
	<?= $csrf ?><input type="hidden" name="idk" value="<?= $item['idk'] ?>"><input type="hidden" name="idp" value="<?= $item['idp'] ?>">
	<datalist id="polozka-sazby"><?php foreach ($rates as $r): ?><option value="<?= e(Money::short($r)) ?>"></option><?php endforeach ?></datalist>
	<div class="tab-obal">
	<table class="vypis ceny">
	<thead><tr><th scope="col"><?= e(t('Internal price list')) ?></th><th scope="col"><?= e(t('Variant')) ?></th><th scope="col" class="cislo"><?= e(t('Price')) ?></th><th scope="col"><?= e(t('Unit')) ?></th><th scope="col"><?= e(t('VAT %')) ?></th><th scope="col"><?= e(t('Internal note')) ?></th></tr></thead>
	<tbody>
<?php foreach ($lists as $l): foreach ($rows as [$code, $name]): $p = $prices[$l['id']][$code] ?? null; $label = $l['name'] . ($code !== '' ? ' – ' . $name : ''); ?>
	<tr data-item-price="<?= $l['id'] ?>:<?= e($code) ?>">
		<td><?= e($l['name'] . ' (' . $l['currency'] . ')') ?><input type="hidden" name="list[]" value="<?= $l['id'] ?>"><input type="hidden" name="item[]" value="<?= $item['idp'] ?>"><input type="hidden" name="variant[]" value="<?= e($code) ?>"></td>
		<td><?= $code !== '' ? '<bdi>' . e($name) . '</bdi> <small>' . e($code) . '</small>' : e(t('the product')) ?></td>
		<td class="cislo"><input class="textpole" type="text" name="price[]" inputmode="decimal" value="<?= e($p['unit_price'] ?? '') ?>" aria-label="<?= e(t('Price of %s', $label)) ?>"<?= $ro ?>></td>
		<td><input class="textpole" type="text" name="unit[]" maxlength="20" value="<?= e($p['unit'] ?? '') ?>" aria-label="<?= e(t('Unit of %s', $label)) ?>"<?= $ro ?>></td>
		<td><input class="textpole" type="text" name="vat[]" list="polozka-sazby" inputmode="decimal" value="<?= e($p !== null && $p['vat_rate'] !== null ? Money::short($p['vat_rate']) : '') ?>" aria-label="<?= e(t('VAT rate of %s', $label)) ?>"<?= $ro ?>></td>
		<td><input class="textpole" type="text" name="note[]" maxlength="200" value="<?= e($p['note'] ?? '') ?>" aria-label="<?= e(t('Internal note of %s', $label)) ?>"<?= $ro ?>></td>
	</tr>
<?php endforeach; endforeach ?>
	</tbody>
	</table>
	</div>
<?php if ($canEdit): ?>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save prices')) ?></button> <span class="napoveda"><?= e(t('An empty price removes it.')) ?></span></p>
<?php endif ?>
</form>
<?php endif ?>
</details>
