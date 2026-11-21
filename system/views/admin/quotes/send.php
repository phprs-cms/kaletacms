<?php
/**
 * The send dialog of a quote (3.38, Admin\QuoteSending): to the quote's customer e-mail only (read only – changing it means
 * changing the customer block), the subject with {number} and the message in the quote's language, the sender's
 * signature, the deal value box. Without SMTP: finalise and hand over the link. For a sent quote: the link and its state.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $csrf
 * @var array<string, mixed> $quote as Core\Quotes::get returns it
 * @var array{to: string, subject: string, message: string, refusal: ?string, smtp: ?string, deal: bool, set_deal_value: bool, language: string} $draft
 * @var string|null $link the customer's working link made in this session, or null (3.38 review L1: only its hash is stored)
 * @var bool $signature does the sender have an e-mail signature
 * @var string $replyTo the sender's e-mail
 * @var bool $pdf 3.40: can this quote be drawn as a PDF (Front\QuotePdf)
 * @var bool $attachPdf 3.40: the box "Attach the quote as a PDF" ticked by default (quote_attach_pdf)
 * @var string $mailTail 3.44 (U61): what the e-mail adds below the message, for the preview ('' once sent)
 * @var array<string, mixed> $attachments 3.49 A: the Attachments block (Admin\AttachmentBox::data)
 */
$detail = $app->url(Kaleta\Core\Quotes::adminPath((int) $quote['id']));
$sent = $quote['status'] !== 'draft';
$mailto = $link !== null && $quote['customer']['email'] !== '' ? 'mailto:' . rawurlencode((string) $quote['customer']['email']) . '?subject=' . rawurlencode(str_replace('{number}', (string) $quote['number'], $draft['subject']))
    . '&body=' . rawurlencode($draft['message'] . "\n\n" . $link) : null;
?>
<p><a href="<?= e($detail) ?>"><?= e(t('Back to the quote')) ?></a> · <a href="<?= e($module->url('preview', ['id' => $quote['id']])) ?>" target="_blank" rel="noopener" data-quote-preview><?= e(t('Preview the document')) ?></a></p>
<h2><?= e($module::heading($quote)) ?></h2>
<?php if (!$sent): /* 3.44 (U61): what leaves – the customer, the total and the validity */
    $money = fn (string $decimal): string => Kaleta\Core\Money::format(Kaleta\Core\Money::fromDecimal($decimal), (string) $quote['currency']);
    $who = trim(implode(' · ', array_filter([trim((string) $quote['customer']['company']), trim((string) $quote['customer']['name'])], fn (string $x): bool => $x !== ''))); ?>
<dl class="poptavka nabidka-shrnuti" data-quote-summary>
	<dt><?= e(t('Customer')) ?></dt><dd><?= $who !== '' ? bdi($who) : '<span class="stitek stitek-chyba">' . e(t('missing')) . '</span>' ?></dd>
	<dt><?= e(t('Total')) ?></dt><dd><?= $quote['vat_mode'] === 'standard' ? e(t('%1$s with VAT (%2$s without VAT)', $money((string) $quote['totals']['gross']), $money((string) $quote['totals']['net']))) : e($money((string) $quote['totals']['net'])) ?></dd>
	<dt><?= e(t('Valid until')) ?></dt><dd><?= $quote['valid_until'] !== null ? e(format_date((string) $quote['valid_until'])) : e(t('not set')) ?></dd>
</dl>
<?php endif ?>
<?php if ($sent): ?>
<section data-quote-sent aria-labelledby="nabidka-odkaz">
	<p><?= e(t('Status: %s', t(Kaleta\Core\Quotes::STATUS_LABELS[$quote['status']] ?? $quote['status']))) ?><?= $quote['sent_at'] !== null ? ' · ' . e(t('sent on %s', format_date((string) $quote['sent_at'], true))) : '' ?></p>
	<h3 id="nabidka-odkaz"><?= e(t('The customer’s link')) ?></h3>
<?php if ($link !== null): ?>
	<p class="mcp-adresa"><code id="nabidka-odkaz-adresa" data-quote-link><?= e($link) ?></code> <button class="navigace" type="button" data-kopirovat="#nabidka-odkaz-adresa"><?= e(t('Copy link')) ?></button></p>
	<p class="napoveda"><?= e(t('Whoever has the link sees this one quote. It works until %s.', format_date((string) $quote['token_expires_at']))) ?></p>
	<?php if ($mailto !== null): ?><p class="tlacitka"><a class="navigace" href="<?= e($mailto) ?>"><?= e(t('Send the link from your own mail program')) ?></a></p><?php endif ?>
	<form method="post" action="<?= e($module->url('link_withdraw')) ?>" data-potvrdit="<?= e(t('Withdraw the link? The customer will see “page not found”.')) ?>">
		<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
		<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Withdraw link')) ?></button></p>
	</form>
<?php elseif (Kaleta\Core\QuoteDelivery::hasWorkingLink($quote)): ?>
	<p class="napoveda" data-quote-link-hidden><?= e(t('The link is stored only as a fingerprint, so it cannot be shown again. New link makes one for you to copy – the link the customer has stops working.')) ?></p>
	<p class="napoveda"><?= e(t('Whoever has the link sees this one quote. It works until %s.', format_date((string) $quote['token_expires_at']))) ?></p>
	<form method="post" action="<?= e($module->url('link')) ?>">
		<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
		<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('New link')) ?></button></p>
	</form>
	<form method="post" action="<?= e($module->url('link_withdraw')) ?>" data-potvrdit="<?= e(t('Withdraw the link? The customer will see “page not found”.')) ?>">
		<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
		<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Withdraw link')) ?></button></p>
	</form>
<?php elseif ($quote['anonymised_at'] === null): ?>
	<p class="napoveda" data-quote-no-link><?= e(t('The quote has no working link – it was withdrawn or has ended.')) ?></p>
	<form method="post" action="<?= e($module->url('link')) ?>">
		<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
		<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('New link')) ?></button></p>
	</form>
<?php endif ?>
</section>
<?php elseif ($draft['refusal'] !== null): ?>
<p class="hlaska hlaska-info" data-quote-refusal><?= e($draft['refusal']) ?> <a href="<?= e($detail) ?>"><?= e(t('Back to the quote')) ?></a></p>
<?php elseif ($draft['smtp'] !== null): ?>
<p class="hlaska hlaska-info" data-quote-no-smtp><?= e($draft['smtp']) ?><?php if ($app->auth()->isAdmin()): ?> <a href="<?= e($app->url('admin.php?module=settings&tab=mail')) ?>"><?= e(t('Set up the mail')) ?></a><?php endif ?></p>
<form method="post" action="<?= e($module->url('send')) ?>" data-potvrdit="<?= e(t('Finalise the quote? It gets its number and cannot be edited any more.')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $quote['id'] ?>"><input type="hidden" name="mode" value="finalise">
	<p class="napoveda"><?= e(t('Finalising gives the quote its number and a link for the customer; send the link from your own mail program or print the quote.')) ?></p>
	<?= $app->view->render('admin/quotes/follow_up_box') ?>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Finalise and show the link')) ?></button></p>
</form>
<?php else: ?>
<?php /* 3.49 A: without the script "Add" reloads the dialog through this form (a GET outside the sending form) */ ?>
<form id="nabidka-nacist" method="get" action="admin.php">
	<input type="hidden" name="module" value="<?= e(Kaleta\Core\Quotes::SECTION) ?>"><input type="hidden" name="action" value="send"><input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
	<input type="hidden" name="attach" value="<?= e(implode(',', array_column($attachments['chosen'], 'id'))) ?>">
</form>
<form method="post" action="<?= e($module->url('send')) ?>" class="formular" enctype="multipart/form-data" data-quote-send data-potvrdit="<?= e(t('Send this quote to %s now? It goes out as a real e-mail and cannot be taken back.', $draft['to'])) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $quote['id'] ?>">
<?php $claudeRefusal = Kaleta\Core\QuoteDelivery::claudeRefusal($app->db(), $quote); ?>
<?php if (Kaleta\Core\Quotes::emailByClaude($quote) && $claudeRefusal !== null): /* 3.44 (N146): a connection wrote this address */ ?>
	<p class="hlaska hlaska-varovani" data-quote-email-by-claude><?= e(t('Claude wrote this e-mail address into the quote and it is not the address of the customer record or enquiry. Check it before you send.')) ?></p>
<?php elseif ($claudeRefusal === Kaleta\Core\QuoteDelivery::CLAUDE_WROTE_ADDRESS): /* 3.44 review L2: through the customer record or an imported enquiry */ ?>
	<p class="hlaska hlaska-varovani" data-quote-email-by-claude><?= e(t('A Claude connection wrote this e-mail address into the customer record or brought in the enquiry. Check it before you send.')) ?></p>
<?php endif ?>
	<div class="radek"><span class="popisek"><?= e(t('Recipient')) ?></span><div><strong data-quote-recipient><?= e($draft['to']) ?></strong>
		<span class="napoveda"><?= e(t('The quote goes only to the customer’s e-mail – to send it elsewhere, change the customer in the quote.')) ?></span></div></div>
	<div class="radek"><label for="nabidka-predmet"><?= e(t('Subject')) ?></label><div><input class="textpole siroke" type="text" id="nabidka-predmet" name="subject" maxlength="<?= Kaleta\Core\QuoteDelivery::MAX_SUBJECT ?>" value="<?= e($draft['subject']) ?>" required>
		<span class="napoveda"><?= e(t('{number} becomes the quote’s number, which it gets when it is sent.')) ?></span></div></div>
	<div class="radek"><label for="nabidka-zprava"><?= e(t('Message')) ?></label><div><textarea class="textbox" id="nabidka-zprava" name="message" rows="7" maxlength="<?= Kaleta\Core\QuoteDelivery::MAX_MESSAGE ?>" required><?= e($draft['message']) ?></textarea>
		<span class="napoveda"><?= e(t('Below the message the e-mail adds the number, the validity, the total and the link to the quote.')) ?>
		<?= e($signature ? ($replyTo !== '' ? t('Your e-mail signature is added; when the customer answers, it comes to you (%s).', $replyTo) : t('Your e-mail signature is added.')) : t('You have no e-mail signature yet, so the e-mail goes without one.')) ?></span></div></div>
	<div class="radek"><span></span><div><details class="nabidka-nahled-mailu" data-quote-mail-preview data-number="<?= e(t('(the number it gets when sent)')) ?>"><summary><?= e(t('Preview of the e-mail')) ?></summary>
		<p><strong><?= e(t('Subject')) ?>:</strong> <span data-quote-mail-subject><?= e(str_replace('{number}', t('(the number it gets when sent)'), $draft['subject'])) ?></span></p>
		<div class="nabidka-nahled-text"><span data-quote-mail-message><?= e($draft['message']) ?></span>

<?= e($mailTail) ?></div>
		<p data-quote-mail-files<?= $attachments['chosen'] === [] ? ' hidden' : '' ?>><strong><?= e(t('Attachments')) ?>:</strong> <span><?= e(implode(', ', array_column($attachments['chosen'], 'name'))) ?></span></p>
		<p class="napoveda"><?= e(t('The customer gets the quote’s number and the link when you send it.')) ?></p></details></div></div>
<?php if ($draft['deal']): ?>
	<div class="radek"><span></span><div><label><input type="checkbox" name="set_deal_value" value="1"<?= $draft['set_deal_value'] ? ' checked' : '' ?> data-quote-deal-value> <?= e(t('Set the deal value to the net total')) ?></label></div></div>
<?php endif ?>
<?php if ($pdf): ?>
	<div class="radek"><span></span><div><label><input type="checkbox" name="attach_pdf" value="1"<?= $attachPdf ? ' checked' : '' ?> data-quote-attach-pdf> <?= e(t('Attach the quote as a PDF')) ?></label></div></div>
<?php else: ?>
	<div class="radek"><span></span><div><span class="napoveda" data-quote-no-pdf><?= e(t('This quote’s language cannot be drawn in a PDF, so the e-mail carries the link only.')) ?></span></div></div>
<?php endif ?>
	<?= $app->view->render('admin/attachments', ['box' => $attachments, 'prefix' => 'nabidka', 'reloadForm' => 'nabidka-nacist',
	    'confirm' => str_replace('%1$s', $draft['to'], t('Send this quote to %1$s now, with attachments (%2$d)? It goes out as a real e-mail and cannot be taken back.'))]) ?>
	<?= $app->view->render('admin/quotes/follow_up_box') ?>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Send the quote')) ?></button></p>
</form>
<?php endif ?>
