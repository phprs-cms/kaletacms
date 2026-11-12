<?php
/**
 * The editor of a draft quote (3.38 B, design A5.3) in the record template (image/admin.css .zaznam-*): the bar with the way
 * back, the status, one primary action (Send – the send dialog of the next part, action send), Finalise without sending
 * and More (Preview, Delete). One form: in the main column the lines (each with quantity, unit, unit price – "manual
 * price" where it differs from the price list, "no price" in red – line discount, VAT rate and remove), "Add a line"
 * (a product of the price list by code with its internal price, an own row, a free line), the totals and the texts; in
 * the side column the customer (a contact or company fills the block, which stays editable), validity, VAT mode,
 * whole-quote discount, language, price list, responsible person and the deal with "set its value". The server computes
 * every total (image/admin.js asks action totals on change – no arithmetic in JavaScript); Save shows them too.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $csrf
 * @var array<string, mixed> $q Core\Quotes::get
 * @var array<string, string> $draft what a refused save typed
 * @var list<array<string, mixed>> $lines the quote's lines, or the posted ones after a refused save
 * @var array{id: int, name: string, currency: string} $list
 * @var list<array{id: int, name: string, currency: string, archived_at: ?string}> $lists
 * @var list<string> $rates
 * @var string|null $defaultRate
 * @var list<array{value: string, label: string}> $products
 * @var list<array<string, mixed>> $own
 * @var array<int, string> $people
 * @var array<int, string> $contacts
 * @var array<int, string> $companies
 * @var array<int, string> $deals
 * @var bool $canContacts
 * @var bool $canDeals
 * @var array<string, string> $languages column value => name ([] = one language)
 * @var string $maxDiscount
 * @var bool $dealValue
 * @var int $me
 * @var bool $canDelete
 */
use Kaleta\Core\Money;
use Kaleta\Core\Quotes;

$id = (int) $q['id'];
$v = fn (string $key, string $stored): string => $draft[$key] ?? $stored;
$money = fn (?string $decimal): string => $decimal === null ? '' : Money::format(Money::fromDecimal($decimal), $q['currency']);
$vatModes = ['standard' => 'Standard VAT', 'reverse_charge' => 'Reverse charge', 'none' => 'Without VAT'];
$lineRates = $rates;
foreach ($lines as $line) {
    if ((string) $line['vat_rate'] !== '' && !in_array((string) $line['vat_rate'], $lineRates, true)) {
        $lineRates[] = (string) $line['vat_rate'];
    }
}
?>
<form method="post" action="<?= e($module->url('save')) ?>" id="quote-form" class="nabidka-editor" data-quote-editor data-quote-totals="<?= e($module->url('totals')) ?>">
<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="updated_at" value="<?= e($q['updated_at']) ?>">
<button type="submit" hidden tabindex="-1"><?= e(t('Save')) ?></button><?php /* Enter in a field saves – never sends */ ?>
<div class="zaznam-lista" data-record-bar>
<a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All quotes')) ?></a>
<div class="zaznam-akce">
	<span class="<?= e($module::statusClass('draft')) ?>" data-quote-status="draft"><?= e(t('Draft')) ?></span>
	<button class="tl" type="submit" name="next" value="send" data-quote-send><?= e(t('Save and send')) ?></button>
	<button class="navigace" type="submit" name="next" value="finalise" data-quote-finalise data-potvrdit="<?= e(t('Finalise the quote without sending it? It gets its number and is frozen – for a quote handed over on paper.')) ?>"><?= e(t('Finalise without sending')) ?></button>
	<details class="zaznam-vice" data-zavrit-mimo data-record-more><summary><?= e(t('More')) ?></summary><div class="zaznam-vice-menu">
		<button class="navigace" type="submit" name="next" value="preview" data-quote-preview><?= e(t('Save and preview')) ?></button>
<?php if ($canDelete): ?>
		<button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('delete')) ?>" formnovalidate data-quote-delete data-potvrdit="<?= e(t('Delete this draft for good? This cannot be undone.')) ?>"><?= e(t('Delete')) ?></button>
<?php endif ?>
	</div></details>
</div>
</div>
<div class="zaznam-obal"><div class="zaznam">
<div class="zaznam-hlavni">
<section class="zaznam-panel" aria-labelledby="nabidka-nazev">
<h2 id="nabidka-nazev"><?= e(t('Quote')) ?></h2>
<div class="zaznam-formular">
	<div class="zaznam-pole"><label for="nabidka-titulek"><?= e(t('Title')) ?></label><input class="textpole" type="text" id="nabidka-titulek" name="title" maxlength="<?= Quotes::TITLE_MAX ?>" value="<?= e($v('title', $q['title'])) ?>"></div>
</div>
</section>

<section id="lines" aria-labelledby="nabidka-radky" data-quote-lines>
<h2 id="nabidka-radky"><?= e(t('Lines')) ?></h2>
<?php if ($lines === []): ?>
<p class="napoveda" data-quote-no-lines><?= e(t('No lines yet – add a product of the price list, an own row or a free line below.')) ?></p>
<?php else: ?>
<div class="tab-obal">
<table class="vypis vypis-karty nabidka-radky">
<thead><tr><th scope="col"><?= e(t('Item')) ?></th><th scope="col"><?= e(t('Quantity')) ?></th><th scope="col"><?= e(t('Unit')) ?></th><th scope="col"><?= e(t('Unit price')) ?></th>
	<th scope="col"><?= e(t('Discount %')) ?></th><th scope="col"><?= e(t('VAT %')) ?></th><th scope="col" class="cislo"><?= e(t('Total')) ?></th><th scope="col"><?= e(t('Remove')) ?></th></tr></thead>
<tbody>
<?php foreach ($lines as $i => $line):
    $stored = array_key_exists('line_net', $line);
    $price = $line['unit_price'] === null ? '' : (string) $line['unit_price'];
    $unpriced = trim($price) === '';
    $manual = $stored && $line['manual'];
    $n = $i + 1; ?>
<tr data-quote-line="<?= (int) $line['id'] ?>"<?= $unpriced ? ' data-quote-unpriced' : '' ?>>
	<td><input type="hidden" name="line_id[]" value="<?= (int) $line['id'] ?>"><input type="hidden" name="line_item[]" value="<?= e((string) ($line['item_id'] ?? '')) ?>"><input type="hidden" name="line_variant[]" value="<?= e((string) $line['variant_code']) ?>"><input type="hidden" name="line_code[]" value="<?= e((string) $line['code']) ?>">
		<input class="textpole" type="text" name="line_name[]" value="<?= e((string) $line['name']) ?>" maxlength="<?= Quotes::NAME_MAX ?>" aria-label="<?= e(t('Line %d: name', $n)) ?>" required>
		<input class="textpole nabidka-popis" type="text" name="line_description[]" value="<?= e((string) $line['description']) ?>" maxlength="<?= Quotes::DESCRIPTION_MAX ?>" aria-label="<?= e(t('Line %d: description', $n)) ?>" placeholder="<?= e(t('Description (optional)')) ?>">
		<?= (string) $line['code'] !== '' ? '<small class="napoveda">' . e((string) $line['code']) . '</small>' : '' ?></td>
	<td data-popisek="<?= e(t('Quantity')) ?>"><input class="textpole cislo-kratke" type="text" inputmode="decimal" name="line_quantity[]" value="<?= e(Money::short((string) $line['quantity'] !== '' ? (string) $line['quantity'] : '1')) ?>" aria-label="<?= e(t('Line %d: quantity', $n)) ?>"></td>
	<td data-popisek="<?= e(t('Unit')) ?>"><input class="textpole cislo-kratke" type="text" name="line_unit[]" value="<?= e((string) $line['unit']) ?>" maxlength="20" aria-label="<?= e(t('Line %d: unit', $n)) ?>"></td>
	<td data-popisek="<?= e(t('Unit price')) ?>"><input class="textpole cislo-kratke" type="text" inputmode="decimal" name="line_price[]" value="<?= e($price) ?>" aria-label="<?= e(t('Line %d: unit price without VAT', $n)) ?>">
		<?= $unpriced ? '<span class="stitek stitek-chyba" data-quote-flag="unpriced">' . e(t('no price')) . '</span>' : ($manual ? '<span class="stitek stitek-koncept" data-quote-flag="manual" title="' . e(t('Price list: %s', $money($line['list_price']) !== '' ? $money($line['list_price']) : '—')) . '">' . e(t('manual price')) . '</span>' : '') ?></td>
	<td data-popisek="<?= e(t('Discount %')) ?>"><input class="textpole cislo-kratke" type="text" inputmode="decimal" name="line_discount[]" value="<?= e(Money::short((string) ($line['discount_percent'] !== '' ? $line['discount_percent'] : '0'))) ?>" aria-label="<?= e(t('Line %d: discount in %%', $n)) ?>"></td>
	<td data-popisek="<?= e(t('VAT %')) ?>"><select name="line_vat[]" aria-label="<?= e(t('Line %d: VAT rate', $n)) ?>">
<?php foreach ($lineRates as $rate): ?>
		<option value="<?= e($rate) ?>"<?= (string) $line['vat_rate'] === $rate ? ' selected' : '' ?>><?= e(Money::short($rate)) ?></option>
<?php endforeach ?>
<?php if ($lineRates === []): ?>
		<option value="0.00">0</option>
<?php endif ?>
	</select></td>
	<td class="cislo" data-popisek="<?= e(t('Total')) ?>"><output data-quote-line-net><?= $stored && !$unpriced ? e($money((string) $line['line_net'])) : '' ?></output></td>
	<td data-popisek="<?= e(t('Remove')) ?>"><label class="nabidka-odebrat"><input type="checkbox" name="line_remove[]" value="<?= $i ?>"<?= !empty($line['remove']) ? ' checked' : '' ?>> <span class="navod-skryte"><?= e(t('Remove line %d', $n)) ?></span></label></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

<fieldset class="nabidka-pridat" data-quote-add>
<legend><?= e(t('Add a line')) ?></legend>
<div class="zaznam-dvojice">
	<div class="zaznam-pole"><label for="nabidka-produkt"><?= e(t('Product of the price list')) ?></label><input class="textpole" type="text" id="nabidka-produkt" name="add_product" list="nabidka-produkty" autocomplete="off" placeholder="<?= e(t('Code or name')) ?>">
		<datalist id="nabidka-produkty">
<?php foreach ($products as $p): ?>
			<option value="<?= e($p['value']) ?>"><?= e($p['label']) ?></option>
<?php endforeach ?>
		</datalist></div>
	<div class="zaznam-pole"><label for="nabidka-produkt-mnozstvi"><?= e(t('Quantity')) ?></label><input class="textpole cislo-kratke" type="text" inputmode="decimal" id="nabidka-produkt-mnozstvi" name="add_quantity" value="1"></div>
</div>
<?php if ($own !== []): ?>
<div class="zaznam-dvojice">
	<div class="zaznam-pole"><label for="nabidka-vlastni"><?= e(t('Own row (installation, transport…)')) ?></label><select id="nabidka-vlastni" name="add_own"><option value="">—</option>
<?php foreach ($own as $o): ?>
		<option value="<?= e($o['code']) ?>"><?= e(($o['label'] !== '' ? $o['label'] : $o['code']) . ' · ' . Money::format($o['minor'], $list['currency'])) ?></option>
<?php endforeach ?>
	</select></div>
	<div class="zaznam-pole"><label for="nabidka-vlastni-mnozstvi"><?= e(t('Quantity')) ?></label><input class="textpole cislo-kratke" type="text" inputmode="decimal" id="nabidka-vlastni-mnozstvi" name="add_own_quantity" value="1"></div>
</div>
<?php endif ?>
<div class="zaznam-dvojice">
	<div class="zaznam-pole"><label for="nabidka-volny"><?= e(t('Free line')) ?></label><input class="textpole" type="text" id="nabidka-volny" name="add_free_name" maxlength="<?= Quotes::NAME_MAX ?>" placeholder="<?= e(t('Name')) ?>"></div>
	<div class="zaznam-pole"><label for="nabidka-volny-cena"><?= e(t('Unit price')) ?></label><input class="textpole cislo-kratke" type="text" inputmode="decimal" id="nabidka-volny-cena" name="add_free_price"></div>
	<div class="zaznam-pole"><label for="nabidka-volny-mnozstvi"><?= e(t('Quantity')) ?></label><input class="textpole cislo-kratke" type="text" inputmode="decimal" id="nabidka-volny-mnozstvi" name="add_free_quantity" value="1"></div>
</div>
<p class="tlacitka"><button class="navigace" type="submit" name="next" value="add" data-quote-add-line><?= e(t('Add to the quote')) ?></button></p>
<p class="napoveda"><?= e(t('Prices come from the price list %s (%s) without VAT; a different price is kept as a manual price.', $list['name'], $list['currency'])) ?></p>
</fieldset>

<section class="zaznam-panel nabidka-soucty" aria-labelledby="nabidka-soucty" data-quote-sum aria-live="polite">
<h2 id="nabidka-soucty"><?= e(t('Totals')) ?></h2>
<dl class="poptavka">
	<dt><?= e(t('Total without VAT')) ?></dt><dd data-quote-sum-net><?= e($money($q['totals']['net'])) ?></dd>
<?php if ($q['vat_mode'] === 'standard'): foreach ($q['totals']['rates'] as $r): ?>
	<dt><?= e(t('VAT %s %%', Money::short($r['rate']))) ?></dt><dd><?= e($money($r['vat'])) ?></dd>
<?php endforeach; endif ?>
	<dt><?= e(t('VAT')) ?></dt><dd data-quote-sum-vat><?= e($money($q['totals']['vat'])) ?></dd>
	<dt><?= e(t('Total with VAT')) ?></dt><dd data-quote-sum-gross><strong><?= e($money($q['totals']['gross'])) ?></strong></dd>
</dl>
<p class="napoveda" data-quote-sum-note><?= $q['totals']['unpriced'] > 0 ? e(t('%d lines have no price yet – the quote cannot be sent until you fill them.', $q['totals']['unpriced'])) : e(t('Totals as saved; they change as you type and are computed again when you save.')) ?></p>
</section>

<section class="zaznam-panel" aria-labelledby="nabidka-texty">
<h2 id="nabidka-texty"><?= e(t('Texts for the customer')) ?></h2>
<div class="zaznam-formular">
	<div class="zaznam-pole"><label for="nabidka-uvod"><?= e(t('Introduction')) ?></label><textarea class="textbox nizky" id="nabidka-uvod" name="intro" rows="3" maxlength="<?= Quotes::TEXT_MAX ?>"><?= e($v('intro', $q['intro'])) ?></textarea></div>
	<div class="zaznam-pole"><label for="nabidka-podminky"><?= e(t('Terms')) ?></label><textarea class="textbox nizky" id="nabidka-podminky" name="terms" rows="3" maxlength="<?= Quotes::TEXT_MAX ?>"><?= e($v('terms', $q['terms'])) ?></textarea></div>
</div>
</section>
<p class="tlacitka"><button class="tl" type="submit" data-quote-save><?= e(t('Save')) ?></button></p>
</div>

<div class="zaznam-bok">
<section class="zaznam-panel" aria-labelledby="nabidka-zakaznik" data-quote-customer>
<h2 id="nabidka-zakaznik"><?= e(t('Customer')) ?></h2>
<div class="zaznam-formular">
<?php if ($canContacts): ?>
	<div class="zaznam-pole"><label for="nabidka-kontakt"><?= e(t('Contact')) ?></label><select id="nabidka-kontakt" name="contact_id"><option value="0">—</option>
<?php foreach ($contacts as $cid => $name): ?>
		<option value="<?= (int) $cid ?>"<?= (int) $v('contact_id', (string) ($q['contact_id'] ?? 0)) === (int) $cid ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></div>
	<div class="zaznam-pole"><label for="nabidka-firma"><?= e(t('Company')) ?></label><select id="nabidka-firma" name="company_id"><option value="0">—</option>
<?php foreach ($companies as $cid => $name): ?>
		<option value="<?= (int) $cid ?>"<?= (int) $v('company_id', (string) ($q['company_id'] ?? 0)) === (int) $cid ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Choosing a contact or company fills the fields below when you save; they stay editable.')) ?></span></div>
<?php endif ?>
<?php foreach (['name' => 'Full name', 'company' => 'Company name', 'reg_no' => 'Registration number', 'vat_id' => 'VAT number', 'email' => 'Email'] as $key => $label): ?>
	<div class="zaznam-pole"><label for="nabidka-c-<?= $key ?>"><?= e(t($label)) ?></label><input class="textpole" type="<?= $key === 'email' ? 'email' : 'text' ?>" id="nabidka-c-<?= $key ?>" name="customer_<?= $key ?>" maxlength="<?= Quotes::CUSTOMER[$key] ?>" value="<?= e($v('customer_' . $key, (string) $q['customer'][$key])) ?>"></div>
<?php endforeach ?>
	<div class="zaznam-pole"><label for="nabidka-c-address"><?= e(t('Address')) ?></label><textarea class="textbox nizky" id="nabidka-c-address" name="customer_address" rows="3" maxlength="<?= Quotes::CUSTOMER['address'] ?>"><?= e($v('customer_address', (string) $q['customer']['address'])) ?></textarea></div>
</div>
</section>

<section class="zaznam-panel" aria-labelledby="nabidka-podminky-nadpis" data-quote-conditions>
<h2 id="nabidka-podminky-nadpis"><?= e(t('Details')) ?></h2>
<div class="zaznam-formular">
	<div class="zaznam-dvojice">
	<div class="zaznam-pole"><label for="nabidka-platnost"><?= e(t('Valid until')) ?></label><input class="textpole" type="date" id="nabidka-platnost" name="valid_until" value="<?= e($v('valid_until', (string) $q['valid_until'])) ?>"></div>
	<div class="zaznam-pole"><label for="nabidka-sleva"><?= e(t('Discount on the whole quote (%)')) ?></label><input class="textpole" type="text" inputmode="decimal" id="nabidka-sleva" name="discount_percent" value="<?= e($v('discount_percent', Money::short($q['discount_percent']))) ?>"></div>
	</div>
	<span class="napoveda"><?= e(t('Claude may save a discount up to %s %%; a higher one only a person sets here.', $maxDiscount)) ?></span>
	<div class="zaznam-pole"><label for="nabidka-dph"><?= e(t('VAT')) ?></label><select id="nabidka-dph" name="vat_mode">
<?php foreach ($vatModes as $key => $label): ?>
		<option value="<?= e($key) ?>"<?= $v('vat_mode', $q['vat_mode']) === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select><?php if ($defaultRate === null): ?><span class="napoveda"><?= e(t('The default VAT rate is not set – Quotes → Settings.')) ?></span><?php endif ?></div>
<?php if ($languages !== []): ?>
	<div class="zaznam-pole"><label for="nabidka-jazyk"><?= e(t('Language of the quote')) ?></label><select id="nabidka-jazyk" name="language">
<?php foreach ($languages as $code => $name): ?>
		<option value="<?= e($code) ?>"<?= $v('language', $q['language']) === $code ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></div>
<?php endif ?>
	<div class="zaznam-pole"><label for="nabidka-cenik"><?= e(t('Internal price list')) ?></label><select id="nabidka-cenik" name="price_list_id"<?= $q['lines'] !== [] ? ' disabled' : '' ?>>
<?php foreach ($lists as $l): ?>
		<option value="<?= (int) $l['id'] ?>"<?= (int) $l['id'] === (int) $list['id'] ? ' selected' : '' ?>><?= e($l['name'] . ' (' . $l['currency'] . ')') ?></option>
<?php endforeach ?>
	</select><?php if ($q['lines'] !== []): ?><span class="napoveda"><?= e(t('One quote, one price list and currency – it is fixed once the quote has lines.')) ?></span><?php endif ?></div>
	<div class="zaznam-pole"><label for="nabidka-odpovedny"><?= e(t('Responsible person')) ?></label><select id="nabidka-odpovedny" name="owner_id"><option value="0">—</option>
<?php foreach ($people as $userId => $name): ?>
		<option value="<?= (int) $userId ?>"<?= (int) $v('owner_id', (string) ($q['owner_id'] ?? 0)) === (int) $userId ? ' selected' : '' ?>><?= e((int) $userId === $me ? t('Me (%s)', $name) : $name) ?></option>
<?php endforeach ?>
	</select></div>
<?php if ($canDeals): ?>
	<div class="zaznam-pole"><label for="nabidka-obchod"><?= e(t('Deal')) ?></label><select id="nabidka-obchod" name="deal_id"><option value="0">—</option>
<?php foreach ($deals as $did => $title): ?>
		<option value="<?= (int) $did ?>"<?= (int) $v('deal_id', (string) ($q['deal_id'] ?? 0)) === (int) $did ? ' selected' : '' ?>><?= e($title) ?></option>
<?php endforeach ?>
	</select></div>
	<label><input type="checkbox" name="set_deal_value" value="1"<?= $dealValue ? ' checked' : '' ?> data-quote-deal-value> <?= e(t('When sent, set the value of the deal to the net total')) ?></label>
<?php endif ?>
</div>
</section>
</div>
</div></div>
</form>
