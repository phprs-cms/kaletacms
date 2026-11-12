<?php
/**
 * A quote (3.38 B, design A5.6) in the record template (image/admin.css .zaznam-*): the bar with the way back, the status
 * and one primary action – a draft: Edit (and Send, the dialog of the next part); sent: Mark accepted, Mark declined with
 * a reason; accepted or declined: the deal offered won or lost (never moved by itself); expired: Revise – and More (back
 * to sent, Revise, Mark expired, Delete). The main column holds the document as the customer gets it (once sent the
 * frozen snapshot, so a later price change never alters it) and the history; the side column the facts, the customer,
 * the enquiry, the deal and the other versions of the series. On a phone one column.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $csrf
 * @var array<string, mixed> $q Core\Quotes::get
 * @var array<string, mixed>|null $deal Core\Deals::get – for whoever may open the Pipeline
 * @var array{key: string, label: string, kind: string}|null $dealStage
 * @var array{key: string, label: string, kind: string}|null $won the first won stage
 * @var array{key: string, label: string, kind: string}|null $lost the first lost stage
 * @var array<string, mixed>|null $enquiry for whoever may open Enquiries
 * @var array<int, string> $names
 * @var string $listName
 * @var bool $canContacts
 * @var bool $canDelete
 * @var list<array{id: int, label: string, status: string, url: string}> $revisions
 * @var string|null $refusal why a draft cannot be finalised yet
 */
use Kaleta\Core\Contacts;
use Kaleta\Core\Deals;
use Kaleta\Core\Money;
use Kaleta\Core\Quotes;

$id = (int) $q['id'];
$status = $q['status'];
$doc = $status !== 'draft' && is_array($q['snapshot']) ? $q['snapshot'] : null; // what the customer got
$lines = $doc['lines'] ?? $q['lines'];
$totals = $doc['totals'] ?? $q['totals'];
$customer = $doc['customer'] ?? $q['customer'];
$currency = (string) ($doc['currency'] ?? $q['currency']);
$vatMode = (string) ($doc['vat_mode'] ?? $q['vat_mode']);
$money = fn (?string $decimal): string => $decimal === null ? '' : Money::format(Money::fromDecimal($decimal), $currency);
$who = fn (?int $user): string => $user !== null && ($names[$user] ?? '') !== '' ? ' · ' . e($names[$user]) : '';
$vatModes = ['standard' => 'Standard VAT', 'reverse_charge' => 'Reverse charge', 'none' => 'Without VAT'];
$actions = ['created' => 'Created', 'sent' => 'Sent', 'finalised' => 'Finalised without sending', 'status' => 'Status changed', 'revision' => 'Made as a revision of %s', 'replaced' => 'Replaced by %s',
    'link' => 'Customer’s link made', 'link_withdrawn' => 'Customer’s link withdrawn'];
// the customer's link and its actions are part C's (Core\QuoteDelivery, Admin\QuoteSending: actions preview, link, link_withdraw)
$link = $status !== 'draft' && class_exists(\Kaleta\Core\QuoteDelivery::class) ? \Kaleta\Core\QuoteDelivery::linkUrl($app, $q) : null;
$dealOpen = $deal !== null && $deal['closed_at'] === null;
$hidden = $csrf . '<input type="hidden" name="id" value="' . $id . '">';
?>
<div class="zaznam-lista" data-record-bar>
<a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All quotes')) ?></a>
<div class="zaznam-akce">
	<span class="<?= e($module::statusClass($status)) ?>" data-quote-status="<?= e($status) ?>"><?= e(t(Quotes::STATUS_LABELS[$status] ?? $status)) ?></span>
<?php if ($status === 'draft'): ?>
	<a class="tl" href="<?= e($module->url('edit', ['id' => $id])) ?>" data-quote-edit><?= e(t('Edit')) ?></a>
	<a class="navigace" href="<?= e($module->url('send', ['id' => $id])) ?>" data-quote-send><?= e(t('Send')) ?></a>
<?php elseif ($status === 'sent'): ?>
	<form method="post" action="<?= e($module->url('status')) ?>" data-quote-accept><?= $hidden ?><input type="hidden" name="status" value="accepted"><button class="tl" type="submit"><?= e(t('Mark accepted')) ?></button></form>
	<form method="post" action="<?= e($module->url('status')) ?>" data-quote-decline><?= $hidden ?><input type="hidden" name="status" value="declined">
		<label class="navod-skryte" for="nabidka-duvod"><?= e(t('Why it was declined')) ?></label>
		<select id="nabidka-duvod" name="reason" required><option value=""><?= e(t('Why it was declined…')) ?></option>
<?php foreach (Deals::LOST_REASONS as $key => $label): ?>
			<option value="<?= e($key) ?>"><?= e(t($label)) ?></option>
<?php endforeach ?>
		</select>
		<button class="navigace" type="submit"><?= e(t('Mark declined')) ?></button></form>
<?php elseif ($status === 'accepted' && $dealOpen && $won !== null): ?>
	<form method="post" action="<?= e($app->url('admin.php?module=pipeline&action=move')) ?>" data-quote-deal-won><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $deal['id'] ?>"><input type="hidden" name="stage" value="<?= e($won['key']) ?>"><input type="hidden" name="return" value="detail">
		<button class="tl" type="submit"><?= e(t('Move the deal to won')) ?></button></form>
<?php elseif ($status === 'declined' && $dealOpen && $lost !== null): ?>
	<form method="post" action="<?= e($app->url('admin.php?module=pipeline&action=move')) ?>" data-quote-deal-lost><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $deal['id'] ?>"><input type="hidden" name="stage" value="<?= e($lost['key']) ?>"><input type="hidden" name="return" value="detail">
		<input type="hidden" name="lost_reason" value="<?= e((string) $q['decline_reason']) ?>"><button class="tl" type="submit"><?= e(t('Mark the deal lost')) ?></button></form>
<?php elseif ($status === 'expired'): ?>
	<form method="post" action="<?= e($module->url('revise')) ?>" data-quote-revise><?= $hidden ?><button class="tl" type="submit"><?= e(t('Revise')) ?></button></form>
<?php endif ?>
	<details class="zaznam-vice" data-zavrit-mimo data-record-more><summary><?= e(t('More')) ?></summary><div class="zaznam-vice-menu">
		<a class="navigace" href="<?= e($module->url('preview', ['id' => $id])) ?>" data-quote-preview><?= e(t('Preview')) ?></a>
<?php if (in_array($status, ['sent', 'accepted'], true)): ?>
		<form method="post" action="<?= e($module->url('link')) ?>" data-quote-link-new><?= $hidden ?><input type="hidden" name="back" value="detail"><button class="navigace" type="submit"><?= e(t('New link')) ?></button></form>
<?php endif ?>
<?php if ($link !== null): ?>
		<form method="post" action="<?= e($module->url('link_withdraw')) ?>" data-quote-link-withdraw data-potvrdit="<?= e(t('Withdraw the link? The customer will see “page not found”.')) ?>"><?= $hidden ?><input type="hidden" name="back" value="detail"><button class="navigace" type="submit"><?= e(t('Withdraw link')) ?></button></form>
<?php endif ?>
<?php if (in_array($status, ['accepted', 'declined'], true)): ?>
		<form method="post" action="<?= e($module->url('status')) ?>" data-quote-reopen><?= $hidden ?><input type="hidden" name="status" value="sent"><button class="navigace" type="submit"><?= e(t('Set back to sent')) ?></button></form>
<?php endif ?>
<?php if (in_array($status, ['sent', 'accepted', 'declined'], true)): ?>
		<form method="post" action="<?= e($module->url('revise')) ?>" data-quote-revise><?= $hidden ?><button class="navigace" type="submit"><?= e(t('Revise')) ?></button></form>
<?php endif ?>
<?php if ($status === 'sent'): ?>
		<form method="post" action="<?= e($module->url('status')) ?>" data-quote-expire data-potvrdit="<?= e(t('Mark the quote expired? It cannot be accepted any more; revise it to send a new one.')) ?>"><?= $hidden ?><input type="hidden" name="status" value="expired"><button class="navigace" type="submit"><?= e(t('Mark expired')) ?></button></form>
<?php endif ?>
<?php if ($canDelete): ?>
		<form method="post" action="<?= e($module->url('delete')) ?>" data-quote-delete data-potvrdit="<?= e(t($q['number'] !== null ? 'Delete this numbered quote for good? Its number stays used. This cannot be undone.' : 'Delete this draft for good? This cannot be undone.')) ?>"><?= $hidden ?><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
<?php endif ?>
	</div></details>
</div>
</div>
<div class="zaznam-obal"><div class="zaznam">
<div class="zaznam-hlavni">
<section class="zaznam-panel nabidka-dokument" aria-labelledby="nabidka-dokument" data-quote-document>
<h2 id="nabidka-dokument"><?= e($q['number'] !== null ? t('Quote %s', $q['number']) : t('Draft quote')) ?><?= ($doc['title'] ?? $q['title']) !== '' ? ' – ' . bdi((string) ($doc['title'] ?? $q['title'])) : '' ?></h2>
<?php if ($status === 'draft'): ?>
<p class="napoveda" data-quote-preview-note><?= e($refusal !== null ? t('Before it can go out: %s', $refusal) : t('This is how the lines and totals stand now; the number and the frozen copy come when it is sent or finalised.')) ?></p>
<?php endif ?>
<?php $block = array_filter([$customer['company'], $customer['name'], $customer['address'], $customer['reg_no'] !== '' ? t('Reg. no. %s', $customer['reg_no']) : '', $customer['vat_id'] !== '' ? t('VAT no. %s', $customer['vat_id']) : '', $customer['email']], fn (string $x): bool => trim($x) !== ''); ?>
<?php if ($block !== []): ?>
<p class="nabidka-zakaznik" data-quote-customer-block><?= implode('<br>', array_map(fn (string $x): string => nl2br(bdi($x)), $block)) ?></p>
<?php endif ?>
<?php if (trim((string) ($doc['intro'] ?? $q['intro'])) !== ''): ?>
<p><?= nl2br(e((string) ($doc['intro'] ?? $q['intro']))) ?></p>
<?php endif ?>
<?php if ($lines === []): ?>
<p class="napoveda"><?= e(t('No lines yet.')) ?></p>
<?php else: ?>
<div class="tab-obal">
<table class="vypis vypis-karty" data-quote-document-lines>
<thead><tr><th scope="col"><?= e(t('Item')) ?></th><th scope="col" class="cislo"><?= e(t('Quantity')) ?></th><th scope="col" class="cislo"><?= e(t('Unit price')) ?></th>
	<th scope="col" class="cislo"><?= e(t('Discount %')) ?></th><?php if ($vatMode === 'standard'): ?><th scope="col" class="cislo"><?= e(t('VAT %')) ?></th><?php endif ?><th scope="col" class="cislo"><?= e(t('Total')) ?></th></tr></thead>
<tbody>
<?php foreach ($lines as $l): ?>
<tr>
	<td><?= bdi((string) $l['name']) ?><?= (string) $l['code'] !== '' ? ' <small class="napoveda">' . e((string) $l['code']) . '</small>' : '' ?><?= (string) $l['description'] !== '' ? '<br><small>' . bdi((string) $l['description']) . '</small>' : '' ?></td>
	<td class="cislo" data-popisek="<?= e(t('Quantity')) ?>"><?= e(Money::short((string) $l['quantity']) . ((string) $l['unit'] !== '' ? ' ' . $l['unit'] : '')) ?></td>
	<td class="cislo" data-popisek="<?= e(t('Unit price')) ?>"><?= $l['unit_price'] !== null ? e($money((string) $l['unit_price'])) : '<span class="stitek stitek-chyba">' . e(t('no price')) . '</span>' ?><?= !empty($l['manual']) ? ' <span class="stitek stitek-koncept">' . e(t('manual price')) . '</span>' : '' ?></td>
	<td class="cislo" data-popisek="<?= e(t('Discount %')) ?>"><?= Money::fromDecimal($l['discount_percent']) > 0 ? e(Money::short((string) $l['discount_percent'])) : '' ?></td>
<?php if ($vatMode === 'standard'): ?>
	<td class="cislo" data-popisek="<?= e(t('VAT %')) ?>"><?= e(Money::short((string) $l['vat_rate'])) ?></td>
<?php endif ?>
	<td class="cislo" data-popisek="<?= e(t('Total')) ?>"><?= $l['unit_price'] !== null ? e($money((string) $l['line_net'])) : '' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<dl class="poptavka nabidka-soucty" data-quote-document-totals>
<?php if (Money::fromDecimal($doc['discount_percent'] ?? $q['discount_percent']) > 0): ?>
	<dt><?= e(t('Discount on the whole quote')) ?></dt><dd><?= e(Money::short((string) ($doc['discount_percent'] ?? $q['discount_percent']))) ?> %</dd>
<?php endif ?>
	<dt><?= e(t('Total without VAT')) ?></dt><dd data-quote-net><?= e($money($totals['net'])) ?></dd>
<?php if ($vatMode === 'standard'): foreach ($totals['rates'] as $r): ?>
	<dt><?= e(t('VAT %s %%', Money::short($r['rate']))) ?></dt><dd><?= e($money($r['vat'])) ?></dd>
<?php endforeach; elseif ($vatMode === 'reverse_charge'): ?>
	<dt><?= e(t('VAT')) ?></dt><dd><?= e(t('reverse charge')) ?></dd>
<?php endif ?>
	<dt><?= e(t('Total with VAT')) ?></dt><dd data-quote-gross><strong><?= e($money($totals['gross'])) ?></strong></dd>
</dl>
<?php endif ?>
<?php if (trim((string) ($doc['terms'] ?? $q['terms'])) !== ''): ?>
<p class="napoveda"><?= nl2br(e((string) ($doc['terms'] ?? $q['terms']))) ?></p>
<?php endif ?>
</section>

<section id="history" aria-labelledby="nabidka-historie" data-quote-history>
<h2 id="nabidka-historie"><?= e(t('History')) ?></h2>
<ol class="obchod-historie">
<?php foreach (array_reverse($q['history']) as $h): $action = (string) ($h['action'] ?? ''); ?>
	<li data-history-action="<?= e($action) ?>"><span class="obchod-historie-co"><?= match ($action) {
	    'status' => e(t(Quotes::STATUS_LABELS[$h['from'] ?? ''] ?? (string) ($h['from'] ?? '')) . ' → ' . t(Quotes::STATUS_LABELS[$h['to'] ?? ''] ?? (string) ($h['to'] ?? '')))
	        . (($h['to'] ?? '') === 'declined' && isset(Deals::LOST_REASONS[(string) ($h['note'] ?? '')]) ? ' · ' . e(t(Deals::LOST_REASONS[(string) $h['note']])) : ''),
	    'revision', 'replaced' => e(t($actions[$action], (string) ($h['note'] ?? ''))),
	    default => e(t($actions[$action] ?? $action)),
	} ?></span>
		<span class="napoveda"><?= e(format_date((string) ($h['at'] ?? ''), true)) ?><?= $who(isset($h['by']) ? (int) $h['by'] : null) ?></span></li>
<?php endforeach ?>
</ol>
</section>
</div>

<div class="zaznam-bok">
<section class="zaznam-panel" aria-labelledby="nabidka-udaje" data-quote-facts>
<h2 id="nabidka-udaje"><?= e(t('Details')) ?></h2>
<dl class="poptavka">
	<dt><?= e(t('Number')) ?></dt><dd><?= $q['number'] !== null ? '<strong>' . e($q['number']) . '</strong>' : '<span class="napoveda">' . e(t('given when sent or finalised')) . '</span>' ?></dd>
	<dt><?= e(t('Valid until')) ?></dt><dd><?= $q['valid_until'] !== null ? e(format_date($q['valid_until'])) : '<span class="napoveda">—</span>' ?></dd>
<?php if ($q['sent_at'] !== null): ?>
	<dt><?= e(t('Sent')) ?></dt><dd><?= e(format_date($q['sent_at'], true)) ?><?= $who($q['sent_by']) ?></dd>
<?php endif ?>
<?php if ($q['decided_at'] !== null): ?>
	<dt><?= e(t($status === 'declined' ? 'Declined' : 'Accepted')) ?></dt><dd><?= e(format_date($q['decided_at'], true)) ?><?= $who($q['decided_by']) ?><?= $q['decline_reason'] !== null ? ' · ' . e(t(Deals::LOST_REASONS[$q['decline_reason']] ?? $q['decline_reason'])) : '' ?></dd>
<?php endif ?>
	<dt><?= e(t('VAT')) ?></dt><dd><?= e(t($vatModes[$vatMode] ?? $vatMode)) ?></dd>
	<dt><?= e(t('Internal price list')) ?></dt><dd><?= e($listName !== '' ? $listName . ' (' . $q['currency'] . ')' : $q['currency']) ?></dd>
	<dt><?= e(t('Responsible person')) ?></dt><dd><?= $q['owner_id'] !== null ? e($names[$q['owner_id']] ?? '') : '<span class="napoveda">—</span>' ?></dd>
	<dt><?= e(t('Created')) ?></dt><dd><?= e(format_date($q['created_at'], true)) ?><?= $who($q['created_by']) ?></dd>
</dl>
</section>

<?php if ($link !== null): ?>
<section class="zaznam-panel" aria-labelledby="nabidka-odkaz" data-quote-customer-link>
<h2 id="nabidka-odkaz"><?= e(t('The customer’s link')) ?></h2>
<p><code id="nabidka-odkaz-adresa"><?= e($link) ?></code></p>
<p class="tlacitka"><button class="navigace" type="button" data-kopirovat="#nabidka-odkaz-adresa"><?= e(t('Copy link')) ?></button></p>
<?php if ($q['token_expires_at'] !== null): ?><p class="napoveda"><?= e(t('Whoever has the link sees this one quote. It works until %s.', format_date($q['token_expires_at']))) ?></p><?php endif ?>
</section>
<?php endif ?>
<section class="zaznam-panel" aria-labelledby="nabidka-vazby" data-quote-links>
<h2 id="nabidka-vazby"><?= e(t('Customer and deal')) ?></h2>
<dl class="poptavka">
<?php if ($q['contact_id'] !== null): ?>
	<dt><?= e(t('Contact')) ?></dt><dd><?= $canContacts ? '<a href="' . e($app->url(Contacts::adminPath($q['contact_id']))) . '">' . bdi($q['customer']['name'] !== '' ? $q['customer']['name'] : '#' . $q['contact_id']) . '</a>' : bdi($q['customer']['name']) ?></dd>
<?php endif ?>
<?php if ($q['company_id'] !== null): ?>
	<dt><?= e(t('Company')) ?></dt><dd><?= $canContacts ? '<a href="' . e($app->url(Contacts::companyAdminPath($q['company_id']))) . '">' . bdi($q['customer']['company'] !== '' ? $q['customer']['company'] : '#' . $q['company_id']) . '</a>' : bdi($q['customer']['company']) ?></dd>
<?php endif ?>
<?php if ($q['enquiry_id'] !== null): ?>
	<dt><?= e(t('Came from')) ?></dt><dd data-quote-enquiry><?= $enquiry !== null ? '<a href="' . e($app->url('admin.php?module=enquiries&action=detail&id=' . (int) $enquiry['idp'])) . '">' . bdi(Kaleta\Core\RecordTitle::enquiry($enquiry)) . '</a> · ' . e(t('Enquiry #%s', (string) $enquiry['idp'])) : e(t('Enquiry #%s', (string) $q['enquiry_id'])) ?></dd>
<?php endif ?>
<?php if ($deal !== null): ?>
	<dt><?= e(t('Deal')) ?></dt><dd data-quote-deal><a href="<?= e($app->url(Deals::adminPath($deal['id']))) ?>"><?= bdi($deal['title']) ?></a>
		<span class="<?= ($dealStage['kind'] ?? 'open') === 'won' ? 'stitek stitek-vydano' : (($dealStage['kind'] ?? 'open') === 'lost' ? 'stitek stitek-chyba' : 'stitek') ?>"><?= e($dealStage['label'] ?? $deal['stage']) ?></span>
		<?= $deal['value'] !== null ? '<br><span class="napoveda">' . e(t('Value: %s', Deals::formatValue($deal['value'], $deal['currency']))) . '</span>' : '' ?></dd>
<?php elseif ($q['deal_id'] !== null): ?>
	<dt><?= e(t('Deal')) ?></dt><dd><?= e(t('Deal #%s', (string) $q['deal_id'])) ?></dd>
<?php endif ?>
</dl>
<?php if ($q['contact_id'] === null && $q['company_id'] === null && $q['enquiry_id'] === null && $q['deal_id'] === null): ?>
<p class="napoveda"><?= e(t('Not linked to a customer, an enquiry or a deal.')) ?></p>
<?php endif ?>
</section>
<?php if ($revisions !== []): ?>
<section class="zaznam-panel" aria-labelledby="nabidka-verze" data-quote-revisions>
<h2 id="nabidka-verze"><?= e(t('Other versions')) ?></h2>
<ul class="obchody-box-seznam">
<?php foreach ($revisions as $r): ?>
	<li><a href="<?= e($r['url']) ?>"><?= e($r['label']) ?></a> <span class="<?= e($module::statusClass($r['status'])) ?>"><?= e(t(Quotes::STATUS_LABELS[$r['status']] ?? $r['status'])) ?></span></li>
<?php endforeach ?>
</ul>
</section>
<?php endif ?>
</div>
</div></div>
