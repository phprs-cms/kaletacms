<?php
/**
 * The quotes of an enquiry, a deal, a customer or a company (3.38 B, Admin\Modules\Quotes::box) in the side column of
 * their record page: each with its number (a draft says so), status, net total and validity, and "New quote" – a POST
 * that makes the draft from the record (the enquiry's basket and customer, the deal's customer, the contact or company)
 * and opens the editor. Only for people who may open Quotes, so the page carries no amount for anyone else.
 *
 * 3.40: on a customer's or company's page also the price list new quotes for them take (Admin\QuoteExtras, customer_list).
 *
 * @var string $csrf
 * @var array{kind: string, id: int, quotes: list<array<string, mixed>>, more: bool, createUrl: string, allUrl: string, priceList?: array{current: ?int, lists: list<array<string, mixed>>, default: string, action: string}|null} $box
 */
use Kaleta\Admin\Modules\Quotes as QuotesModule;
use Kaleta\Core\Money;
use Kaleta\Core\Quotes;
?>
<section class="zakaznik-box" id="quotes" data-quotes-box="<?= e($box['kind']) ?>">
<h2><?= e(t('Quotes')) ?> <small><?= format_count(count($box['quotes'])) ?><?= $box['more'] ? '+' : '' ?></small></h2>
<?php if ($box['quotes'] === []): ?>
<p class="napoveda"><?= e(t('No quotes yet.')) ?></p>
<?php else: ?>
<ul class="obchody-box-seznam">
<?php foreach ($box['quotes'] as $q): ?>
	<li data-quote="<?= (int) $q['id'] ?>"><a href="<?= e($q['url']) ?>"><?= e($q['label']) ?></a><?= $q['title'] !== '' ? ' ' . bdi($q['title']) : '' ?>
		<span class="<?= e(QuotesModule::statusClass($q['status'])) ?>"><?= e(t(Quotes::STATUS_LABELS[$q['status']] ?? $q['status'])) ?></span>
		<span class="napoveda"><?= e(Money::format(Money::fromDecimal($q['total_net']), $q['currency'])) ?></span><?= $q['valid_until'] !== null && in_array($q['status'], ['draft', 'sent'], true) ? ' <span class="napoveda">' . e(t('valid until %s', format_date($q['valid_until']))) . '</span>' : '' ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<form class="tlacitka" method="post" action="<?= e($box['createUrl']) ?>" data-quote-new-here><?= $csrf ?><input type="hidden" name="<?= e($box['kind']) ?>" value="<?= (int) $box['id'] ?>">
	<button class="navigace" type="submit"><?= e(t('New quote')) ?></button><?php if ($box['quotes'] !== []): ?> <a class="navigace" href="<?= e($box['allUrl']) ?>"><?= e(t('All their quotes')) ?></a><?php endif ?></form>
<?php if (($box['priceList'] ?? null) !== null): $pl = $box['priceList']; ?>
<form class="nabidky-cenik" method="post" action="<?= e($pl['action']) ?>" data-quote-customer-list><?= $csrf ?><input type="hidden" name="kind" value="<?= e($box['kind']) ?>"><input type="hidden" name="id" value="<?= (int) $box['id'] ?>">
	<label for="nabidky-cenik-<?= e($box['kind']) ?>"><?= e(t('Price list for new quotes')) ?></label>
	<span class="nabidky-cenik-volba"><select id="nabidky-cenik-<?= e($box['kind']) ?>" name="price_list_id">
		<option value="0"><?= e($box['kind'] === 'contact' ? t('As the company, or the default (%s)', $pl['default']) : t('The default (%s)', $pl['default'])) ?></option>
<?php foreach ($pl['lists'] as $l): ?>
		<option value="<?= (int) $l['id'] ?>"<?= $pl['current'] === $l['id'] ? ' selected' : '' ?>><?= e($l['name'] . ' (' . $l['currency'] . ')') ?></option>
<?php endforeach ?>
	</select> <button class="navigace" type="submit"><?= e(t('Save')) ?></button></span>
</form>
<?php endif ?>
</section>
