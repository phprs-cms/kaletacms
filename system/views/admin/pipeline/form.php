<?php
/**
 * The fields of a deal (3.31): new and edit. The customer and the company only for whoever may open Customers; a new deal
 * chooses its open stage, an existing one moves with Move (the history). The value is a number with a currency – no
 * products, no price maths.
 *
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var string $csrf
 * @var array<string, mixed> $d the deal (id 0 = a new one)
 * @var array<string, string> $draft a refused form's fields, or what a new deal starts with
 * @var array<int, string> $contacts id => name
 * @var array<int, string> $companies id => name
 * @var array<int, string> $people who may be responsible
 * @var list<array{key: string, label: string, kind: string, custom: bool}> $stages the open stages (a new deal)
 * @var bool $canContacts
 * @var int $me
 * @var int $enquiry (a new deal, 3.32) the enquiry it comes from – Deals::createFromEnquiry saves it; 0 = none
 */
use Kaleta\Core\Deals;

$new = (int) $d['id'] === 0;
$value = fn (string $key): string => (string) ($draft[$key] ?? $d[$key] ?? '');
$contactId = (int) $value('contact_id');
$companyId = (int) $value('company_id');
$ownerId = (int) $value('owner_id');
$currency = $value('currency');
$prefix = $new ? 'obchod-novy' : 'obchod-' . (int) $d['id'];
?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" data-deal-form>
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $d['id'] ?>"><?php if ($new && (int) ($enquiry ?? 0) > 0): ?><input type="hidden" name="enquiry" value="<?= (int) $enquiry ?>"><?php endif ?>
	<div class="radek"><label for="<?= $prefix ?>-titulek"><?= e(t('Name of the deal')) ?></label><div><input class="textpole siroke" type="text" id="<?= $prefix ?>-titulek" name="title" maxlength="<?= Deals::TITLE_MAX ?>" required value="<?= e($value('title')) ?>" autocomplete="off">
		<span class="napoveda"><?= e(t('What the customer may buy – e.g. Website redesign, Yearly service.')) ?></span></div></div>
<?php if ($canContacts): ?>
	<div class="radek"><label for="<?= $prefix ?>-zakaznik"><?= e(t('Customer')) ?></label><div><select id="<?= $prefix ?>-zakaznik" name="contact_id"><option value="0">—</option>
<?php foreach ($contacts as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $contactId === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></div></div>
	<div class="radek"><label for="<?= $prefix ?>-firma"><?= e(t('Company')) ?></label><div><select id="<?= $prefix ?>-firma" name="company_id"><option value="0">—</option>
<?php foreach ($companies as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $companyId === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></div></div>
<?php endif ?>
	<div class="radek"><label for="<?= $prefix ?>-odpovedny"><?= e(t('Responsible person')) ?></label><div><select id="<?= $prefix ?>-odpovedny" name="owner_id"><option value="0">—</option>
<?php foreach ($people as $userId => $name): ?>
		<option value="<?= (int) $userId ?>"<?= $ownerId === (int) $userId ? ' selected' : '' ?>><?= e((int) $userId === $me ? t('Me (%s)', $name) : $name) ?></option>
<?php endforeach ?>
	</select> <span class="napoveda"><?= e(t('Gets an e-mail when someone else chooses them. It does not change who may open the deal.')) ?></span></div></div>
	<div class="radek"><label for="<?= $prefix ?>-hodnota"><?= e(t('Value')) ?></label><div class="obchod-castka"><input class="textpole" type="text" inputmode="decimal" id="<?= $prefix ?>-hodnota" name="value" maxlength="20" value="<?= e($value('value')) ?>" autocomplete="off">
		<label class="navod-skryte" for="<?= $prefix ?>-mena"><?= e(t('Currency')) ?></label><select id="<?= $prefix ?>-mena" name="currency">
<?php if ($currency === ''): /* 3.32: from an enquiry the currency is offered, not chosen – a value without one takes the site's default */ ?>
			<option value="" selected>—</option>
<?php endif ?>
<?php foreach (Deals::CURRENCIES as $code): ?>
			<option value="<?= e($code) ?>"<?= $currency === $code ? ' selected' : '' ?>><?= e($code) ?></option>
<?php endforeach ?>
		</select>
		<span class="napoveda"><?= e(t('Optional. A number, e.g. 12500 or 12 500,50.')) ?></span></div></div>
	<div class="radek"><label for="<?= $prefix ?>-termin"><?= e(t('Expected close')) ?></label><div><input class="textpole" type="date" id="<?= $prefix ?>-termin" name="expected_close" value="<?= e($value('expected_close')) ?>"></div></div>
<?php if ($new): ?>
	<div class="radek"><label for="<?= $prefix ?>-etapa"><?= e(t('Stage')) ?></label><div><select id="<?= $prefix ?>-etapa" name="stage">
<?php foreach ($stages as $st): ?>
		<option value="<?= e($st['key']) ?>"<?= $value('stage') === $st['key'] ? ' selected' : '' ?>><?= e($st['label']) ?></option>
<?php endforeach ?>
	</select></div></div>
<?php endif ?>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t($new ? 'Create the deal' : 'Save')) ?></button></p>
</form>
