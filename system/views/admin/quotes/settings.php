<?php
/**
 * The settings of quotes (3.38, administrators): numbering and the next number, the default validity, VAT (payer, the
 * default rate, the short list of rates), the largest discount Claude may save, how long the customer's link works after
 * the validity, the retention, the deal value on sending – and who may open Quotes (links to Users and Roles).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $csrf
 * @var array<string, string> $values
 * @var string $next the number the next quote would get
 * @var list<string> $people who may open Quotes besides the administrators
 */
?>
<?= $app->view->render('admin/quotes/tabs', ['module' => $module, 'current' => 'settings', 'admin' => true]) ?>
<form method="post" action="<?= e($module->url('settings_save')) ?>" class="formular" data-quotes-settings>
	<?= $csrf ?>
	<h2><?= e(t('Numbering')) ?></h2>
	<div class="radek"><label for="nabidky-format"><?= e(t('Number format')) ?></label><div><input class="textpole" type="text" id="nabidky-format" name="quote_number_format" maxlength="40" value="<?= e($values['quote_number_format']) ?>">
		<span class="napoveda"><?= e(t('{YYYY} or {YY} is the year, {NNNN} the counter with as many digits as N; a prefix may go in front (N{YYYY}-{NNNN}). A quote gets its number when it is first sent or finalised, so drafts leave no gaps; the counter starts again every year.')) ?></span></div></div>
	<div class="radek"><label for="nabidky-dalsi"><?= e(t('Next number')) ?></label><div><input class="textpole cislo-kratke" type="number" id="nabidky-dalsi" name="quote_next_number" min="1" placeholder="1">
		<span class="napoveda" data-quotes-next><?= e(t('The next quote gets %s. To continue an existing series this year, write its next counter – a series only goes up.', $next)) ?></span></div></div>
	<h2><?= e(t('Validity and VAT')) ?></h2>
	<div class="radek"><label for="nabidky-platnost"><?= e(t('Valid for (days)')) ?></label><div><input class="textpole cislo-kratke" type="number" id="nabidky-platnost" name="quote_valid_days" min="1" max="365" value="<?= (int) $values['quote_valid_days'] ?>"></div></div>
	<div class="radek"><span></span><div><label><input type="checkbox" name="quote_vat_payer" value="1"<?= $values['quote_vat_payer'] === '1' ? ' checked' : '' ?>> <?= e(t('We are a VAT payer')) ?></label>
		<span class="napoveda"><?= e(t('Off: new quotes have no VAT. Each quote can still choose standard VAT, reverse charge or no VAT.')) ?></span></div></div>
	<div class="radek"><label for="nabidky-sazba"><?= e(t('Default VAT rate (%)')) ?></label><div><input class="textpole cislo-kratke" type="text" inputmode="decimal" id="nabidky-sazba" name="quote_vat_rate" value="<?= e($values['quote_vat_rate']) ?>">
		<span class="napoveda"><?= e(t('Prices are without VAT; a price without its own rate takes this one.')) ?></span></div></div>
	<div class="radek"><label for="nabidky-sazby"><?= e(t('Other VAT rates (%)')) ?></label><div><input class="textpole" type="text" id="nabidky-sazby" name="quote_vat_rates" value="<?= e($values['quote_vat_rates']) ?>" placeholder="21, 12, 0">
		<span class="napoveda"><?= e(t('Offered on each line, separated by commas.')) ?></span></div></div>
	<h2><?= e(t('Claude, the link and retention')) ?></h2>
	<div class="radek"><label for="nabidky-sleva"><?= e(t('Largest discount Claude may save (%)')) ?></label><div><input class="textpole cislo-kratke" type="text" inputmode="decimal" id="nabidky-sleva" name="quote_max_discount" value="<?= e($values['quote_max_discount']) ?>">
		<span class="napoveda"><?= e(t('Above it only a person sets the discount here in the admin. Claude never invents a price – a product without an internal price gets a line without a price.')) ?></span></div></div>
	<div class="radek"><label for="nabidky-odkaz"><?= e(t('The customer’s link works after the validity (days)')) ?></label><div><input class="textpole cislo-kratke" type="number" id="nabidky-odkaz" name="quote_link_days" min="1" max="365" value="<?= (int) $values['quote_link_days'] ?>"></div></div>
	<div class="radek"><label for="nabidky-mesice"><?= e(t('Keep quotes (months)')) ?></label><div><input class="textpole cislo-kratke" type="number" id="nabidky-mesice" name="quotes_months" min="0" max="120" value="<?= (int) $values['quotes_months'] ?>">
		<span class="napoveda"><?= e(t('Counted from the last change of status; then the quote goes the way of old enquiries (deleted or without the person). 0 = keep.')) ?></span></div></div>
	<div class="radek"><span></span><div><label><input type="checkbox" name="quote_set_deal_value" value="1"<?= $values['quote_set_deal_value'] === '1' ? ' checked' : '' ?>> <?= e(t('Sending a quote sets the value of its deal to the net total')) ?></label>
		<span class="napoveda"><?= e(t('The default of the checkbox in the send dialog. Won or lost is only offered, never set by itself.')) ?></span></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save')) ?></button></p>
</form>
<?= $app->view->render('admin/quotes/texts', ['app' => $app, 'module' => $module, 'csrf' => $csrf]) ?>
<section data-quotes-people aria-labelledby="nabidky-kdo">
<h2 id="nabidky-kdo"><?= e(t('Who may open Quotes')) ?></h2>
<p class="napoveda"><?= $module->hint(t('Administrators, and whoever you give the section Quotes in Users or Roles; changing prices needs the permission “Quotes: change internal prices” as well.')) ?></p>
<?php if ($people !== []): ?>
<ul>
<?php foreach ($people as $name): ?>
	<li><?= e($name) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
</section>
