<?php
/**
 * Enquiry detail.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Enquiries $module
 * @var list<array<string, mixed>> $testimonials testimonial requests of this enquiry (2.12)
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array{0:string, 1:string, 2?:string}> $data  [label, value, attachment path]
 * @var array<int, string> $users
 * @var array{form: string, kind: string, title: string, builder: string, settings: ?string}|null $origin the form and what carries it, with admin links (3.20, C2)
 * @var array<string, mixed>|null $referencesCollection the References collection the testimonials land in; null = none yet (3.20, C16)
 * @var bool $hasSignature the signed-in user has an e-mail signature a reply carries (3.23.1, N-C5)
 * @var list<array<string, mixed>> $replies replies sent from the enquiry with their delivery state (3.22, C10, Core\EnquiryReply::history)
 * @var string|null $replyBlocked why the site cannot send a reply (no SMTP, no sender); null = it can
 * @var string $replyTo the signed-in user's e-mail – the Reply-To of a reply
 * @var array{subject: string, text: string}|null $replyDraft what a refused reply carried, shown again
 */
use Kaleta\Admin\Modules\Enquiries;

// 3.19.1 (N79): a page becomes a link only with a safe address (an imported enquiry carries what the old site sent)
$pageLink = Kaleta\Front\Forms::isSafePage((string) $p['stranka']);
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All enquiries')) ?></a></p>
<div class="formular">
<dl class="poptavka">
	<dt><?= e(t('Received')) ?></dt><dd><?= e(format_date($p['datum'], true)) ?> · <?= e($p['formular']) ?><?php if ($p['stranka'] !== ''): ?> · <?= $pageLink ? '<a href="' . e($p['stranka']) . '" target="_blank" rel="noopener">' . e($p['stranka']) . '</a>' : e($p['stranka']) ?><?php endif ?></dd>
<?php if ($origin !== null): /* 3.20 (C2): the chain enquiry → form → page continues into the administration – the builder opens with the form selected */ ?>
	<dt><?= e(t('Form')) ?></dt><dd data-origin><?= t(match ($origin['kind']) {
	    'collection' => 'From the form %s in the item template of the collection %s',
	    'popup' => 'From the form %s in the pop-up %s',
	    'part' => 'From the form %s in the site part %s',
	    default => 'From the form %s on the page %s',
	}, '<em>' . e($origin['form'] !== '' ? $origin['form'] : t('Enquiry')) . '</em>', '<em>' . e($origin['title']) . '</em>') ?> – <a href="<?= e($origin['builder']) ?>"><?= e(t('Open in the builder')) ?></a><?php if ($origin['settings'] !== null): ?> · <a href="<?= e($origin['settings']) ?>"><?= e(t(match ($origin['kind']) { 'collection' => 'Collection settings', 'popup' => 'Pop-up settings', default => 'Page settings' })) ?></a><?php endif ?>
	<span class="napoveda"><?= e(t('Recipients, the autoresponder and the fields are in the form’s properties in the builder.')) ?></span></dd>
<?php endif ?>
<?php if (($p['tema'] ?? '') !== ''): ?>
	<dt><?= e(t('Topic')) ?></dt><dd><?= $pageLink ? '<a href="' . e($p['stranka']) . '" target="_blank" rel="noopener">' . e($p['tema']) . '</a>' : e($p['tema']) ?></dd>
<?php endif ?>
<?php if (($p['vstup'] ?? '') !== ''): ?>
	<dt><?= e(t('First page of the visit')) ?></dt><dd><?= e($p['vstup']) ?></dd>
<?php endif ?>
<?php if (($p['odkud'] ?? '') !== ''): ?>
	<dt><?= e(t('Came from')) ?></dt><dd><?= e($p['odkud']) ?></dd>
<?php endif ?>
<?php if (($p['kampan'] ?? '') !== ''): ?>
	<dt><?= e(t('Campaign')) ?></dt><dd><?= e(Kaleta\Front\Forms::campaignText($p['kampan'])) ?></dd>
<?php endif ?>
	<dt><?= e(t('Status')) ?></dt><dd><?= e(t(Enquiries::STATUSES[(int) $p['stav']])) ?></dd>
<?php if (($p['anonymizovano'] ?? null) !== null): ?>
	<dt><?= e(t('Anonymised')) ?></dt><dd><?= e(format_date((string) $p['anonymizovano'], true)) ?> · <?= e(t('the row stays for statistics without the person')) ?></dd>
<?php endif ?>
<?php foreach ($data as $i => $d): [$labelText, $value] = $d; ?>
	<dt><?= e($labelText) ?></dt><dd><?= $value === '' ? '<span class="napoveda">—</span>' : (isset($d[2]) ? '<a href="' . e($module->url('attachment', ['id' => (int) $p['idp'], 'field' => $i])) . '">' . e($value) . '</a>' : nl2br(e($value))) ?></dd>
<?php endforeach ?>
</dl>
<?php
// 3.22 (C10): the reply goes from the site to the enquiry's own address; the history keeps who answered, when and what
$canReply = $p['email'] !== '' && ($p['anonymizovano'] ?? null) === null;
$mailto = 'mailto:' . e($p['email']) . '?subject=' . e(rawurlencode(Kaleta\Core\EnquiryReply::subject($p))) . (($p['navrh_odpovedi'] ?? '') !== '' ? '&amp;body=' . e(rawurlencode((string) $p['navrh_odpovedi'])) : '');
$statusText = ['sent' => 'E-mail sent', 'queued' => 'Waiting in the mail queue', 'failed' => 'Not delivered'];
?>
<h2 id="reply"><?= e(t('Reply')) ?></h2>
<?php if ($replies !== []): ?>
<ol class="odpovedi" data-replies>
<?php foreach ($replies as $reply): ?>
	<li><p><strong><?= e(t('Replied by %s on %s', $reply['by'] !== '' ? $reply['by'] : '—', format_date((string) $reply['at'], true))) ?></strong><?= $reply['via'] === 'mcp' ? ' · ' . e(t('through Claude')) : '' ?>
		· <span class="stitek stitek-<?= $reply['status'] === 'sent' ? 'vydano' : ($reply['status'] === 'queued' ? 'koncept' : 'chyba') ?>" data-reply-status="<?= e($reply['status']) ?>"><?= e(t($statusText[$reply['status']])) ?></span><?php if ($reply['error'] !== '' && $reply['status'] !== 'sent'): ?> <span class="napoveda"><?= e($reply['error']) ?></span><?php endif ?></p>
<?php if ($reply['text'] !== ''): ?>
		<details><summary><?= e($reply['subject']) ?><?= $reply['to'] !== '' ? ' → ' . e($reply['to']) : '' ?></summary><div class="odpoved-text"><?= nl2br(e($reply['text'])) ?></div></details>
<?php endif ?>
	</li>
<?php endforeach ?>
</ol>
<?php endif ?>
<?php if (!$canReply): ?>
<p class="napoveda" data-reply-none><?= e(t('This enquiry has no e-mail address to reply to – reply by phone or another way, and note it in the internal note.')) ?></p>
<?php elseif ($replyBlocked !== null): ?>
<p class="hlaska hlaska-info" data-reply-blocked><?= e(t($replyBlocked)) ?><?php if ($app->auth()->isAdmin()): ?> <a href="<?= e($app->url('admin.php?module=settings&tab=mail')) ?>"><?= e(t('Set up the mail')) ?></a><?php endif ?></p>
<p class="tlacitka"><a class="tl" href="<?= $mailto ?>"><?= e(t(($p['navrh_odpovedi'] ?? '') !== '' ? 'Reply by email with the draft' : 'Reply by email')) ?></a></p>
<?php else: ?>
<form method="post" action="<?= e($module->url('reply')) ?>" data-reply-form data-potvrdit="<?= e(t('Send this reply to %s now? It goes out as a real e-mail and cannot be taken back.', (string) $p['email'])) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $p['idp'] ?>">
	<div class="radek"><span class="popisek"><?= e(t('Recipient')) ?></span><div><strong><?= e($p['email']) ?></strong></div></div>
	<div class="radek"><label for="odpoved-predmet"><?= e(t('Subject')) ?></label><div><input class="textpole siroke" type="text" id="odpoved-predmet" name="subject" maxlength="<?= Kaleta\Core\EnquiryReply::MAX_SUBJECT ?>" value="<?= e($replyDraft['subject'] ?? Kaleta\Core\EnquiryReply::subject($p)) ?>" required></div></div>
	<div class="radek"><label for="odpoved-text"><?= e(t('Reply')) ?></label><div><textarea class="textbox" id="odpoved-text" name="text" rows="8" maxlength="<?= Kaleta\Core\EnquiryReply::MAX_TEXT ?>" required><?= e($replyDraft['text'] ?? ($replies === [] ? (string) ($p['navrh_odpovedi'] ?? '') : '')) ?></textarea>
		<span class="napoveda"<?= $replyTo !== '' && !$hasSignature ? ' data-reply-no-signature' : '' ?>><?= $module->hint(match (true) { // 3.23.1 (N-C5): the signature is promised only to who has one
		    $replyTo === '' => t('Sent from the site’s address. Your account has no e-mail, so the customer’s answer goes to the site’s reply address – add yours in My account.'),
		    $hasSignature => t('Sent from the site’s address; when the customer answers, it comes to you (%s). Your e-mail signature from My account is added below the text.', $replyTo),
		    default => t('Sent from the site’s address; when the customer answers, it comes to you (%s). You have no e-mail signature yet, so the reply goes without one – see My account → My e-mail signature.', $replyTo),
		}) ?></span></div></div>
	<div class="radek"><span></span><div><label><input type="checkbox" name="keep_open" value="1"> <?= e(t('Keep the enquiry open (otherwise it is marked resolved)')) ?></label></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Send reply')) ?></button> <a class="navigace" href="<?= $mailto ?>"><?= e(t('Reply from your own mail program')) ?></a></p>
</form>
<?php endif ?>
<h2><?= e(t('Triage')) ?></h2>
<form method="post" action="<?= e($module->url('triage')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $p['idp'] ?>">
	<div class="radek"><label for="kategorie"><?= e(t('Kind')) ?></label><div><select id="kategorie" name="kategorie"><option value="">—</option>
<?php foreach (Kaleta\Core\Triage::CATEGORIES as $key => $name): ?>
		<option value="<?= e($key) ?>"<?= $p['kategorie'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
	<?php if ($p['triaged_by'] !== ''): ?><span class="napoveda"><?= e(t('Sorted by %s', match ($p['triaged_by']) { 'claude' => 'Claude', 'assistant' => t('the writing assistant'), 'rule' => t('a rule'), default => $p['triaged_by'] })) ?></span><?php endif ?></div></div>
	<div class="radek"><label for="priorita"><?= e(t('Priority')) ?></label><div><select id="priorita" name="priorita"><option value="0">—</option>
<?php foreach ([3 => 'urgent', 2 => 'normal', 1 => 'can wait'] as $value => $name): ?>
		<option value="<?= $value ?>"<?= (int) $p['priorita'] === $value ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></div></div>
	<div class="radek"><label for="navrh-odpovedi"><?= e(t('Drafted reply')) ?></label><div><textarea class="textbox nizky" id="navrh-odpovedi" name="navrh_odpovedi" rows="5"><?= e((string) ($p['navrh_odpovedi'] ?? '')) ?></textarea>
		<span class="napoveda"><?= e(t('Never sent by itself – check it, then send it under Reply.')) ?></span></div></div>
	<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Save triage')) ?></button><?php if ($p['kategorie'] !== 'spam'): ?> <button class="navigace" type="submit" name="kategorie" value="spam"><?= e(t('Mark as spam')) ?></button><?php endif ?></p>
</form>
<form method="post" action="<?= e($module->url('note')) ?>">
	<?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>">
	<div class="radek"><label for="prirazeno"><?= e(t('Handled by')) ?></label><select id="prirazeno" name="prirazeno"><option value="0">—</option>
<?php foreach ($users as $userId => $displayName): ?>
		<option value="<?= (int) $userId ?>"<?= (int) ($p['prirazeno'] ?? 0) === (int) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select></div>
	<div class="radek"><label for="poznamka"><?= e(t('Internal note')) ?></label><div><textarea class="textbox nizky" id="poznamka" name="poznamka" rows="3"><?= e((string) ($p['poznamka'] ?? '')) ?></textarea>
		<span class="napoveda"><?= e(t('Only administration users see it – e.g. what you offered the customer.')) ?></span></div></div>
	<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Save note')) ?></button></p>
</form>
<?php if ($p['email'] !== ''): ?>
<h2><?= e(t('Testimonial')) ?></h2>
<?php foreach ($testimonials as $req): ?>
<p class="smltxt"><?= e(format_date((string) $req['created_at'], true)) ?> · <?= $req['used_at'] !== null
    ? e(t('Answered')) . ($req['item_id'] !== null ? ' – <a href="' . e($app->url('admin.php?module=collections&action=item&id=' . (int) $app->db()->value('SELECT idk FROM {kolekce_polozky} WHERE idp = ?', [(int) $req['item_id']]) . '&item=' . (int) $req['item_id'])) . '">' . e(t('the draft reference')) . '</a>' : '')
    : e(strtotime((string) $req['expires_at']) < time() ? t('Expired') : t('Waiting for the answer')) ?></p>
<?php endforeach ?>
<?php /* 3.23.1 (N-C10): one behaviour for every role – the References collection is created with the first answer (Testimonials::save), so nobody has to create it first */ ?>
<form class="vradku" method="post" action="<?= e($module->url('testimonial')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $p['idp'] ?>">
	<button class="navigace" type="submit" name="poslat" value="1"><?= e(t('Ask for a testimonial by e-mail')) ?></button> <button class="navigace" type="submit" name="poslat" value="0"><?= e(t('Only create the link')) ?></button>
	<span class="napoveda"><?= e($referencesCollection === null
	    ? t('The customer gets a personal link for 30 days; what they write arrives as a hidden draft in References, with the consent they gave – the collection is created with the first answer.')
	    : t('The customer gets a personal link for 30 days; what they write arrives as a hidden draft in %s, with the consent they gave.', (string) $referencesCollection['nazev'])) ?></span></form>
<?php endif ?>
<div class="tlacitka">
	<form class="vradku" method="post" action="<?= e($module->url('status')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><input type="hidden" name="stav" value="<?= (int) $p['stav'] === 2 ? 1 : 2 ?>"><button class="tl<?= (int) $p['stav'] === 2 ? ' tl-vedlejsi' : '' ?>" type="submit"><?= e(t((int) $p['stav'] === 2 ? 'Reopen' : 'Označit jako vyřízenou')) ?></button></form>
<?php if (($p['anonymizovano'] ?? null) === null): ?>
	<form class="vradku" method="post" action="<?= e($module->url('anonymise')) ?>" data-potvrdit="<?= e(t('Blank the name, e-mail, phone, message and attachments of this enquiry? The row stays for statistics.')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace" type="submit"><?= e(t('Anonymise')) ?></button></form>
<?php endif ?>
	<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete this enquiry and its attachments for good? This cannot be undone.')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
</div>
</div>
