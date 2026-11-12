<?php
/**
 * Enquiry detail – 3.37: the record template (image/admin.css .zaznam-*): one bar with the way back, Mark as resolved, a jump
 * to the reply and More (anonymise, move to Applications, delete); the main column with what the form sent, the conversation,
 * the internal note and the reply; the side column with one form for who handles it, the kind, the priority, the summary and
 * the drafted reply (one Save – Enquiries::actionTriage), the customer, the deal, the tasks and the facts of the visit.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Enquiries $module
 * @var list<array<string, mixed>> $testimonials testimonial requests of this enquiry (2.12)
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array{0:string, 1:string, 2?:string}> $data  [label, value, attachment path]
 * @var array<int, string> $users
 * @var array{form: string, kind: string, title: string, builder: string, settings: ?string}|null $origin the form and what carries it, with admin links (3.20, C2)
 * @var bool $hasSignature the signed-in user has an e-mail signature a reply carries (3.23.1, N-C5)
 * @var list<array<string, mixed>> $replies replies sent from the enquiry with their delivery state (3.22, C10, Core\EnquiryReply::history)
 * @var string|null $replyBlocked why the site cannot send a reply (no SMTP, no sender); null = it can
 * @var string $replyTo the signed-in user's e-mail – the Reply-To of a reply
 * @var array{subject: string, text: string}|null $replyDraft what a refused reply carried, shown again
 * @var list<array{id: int, at: string, by: string, by_id: int, text: string, via: string, kind: string, mentions: list<int>, edited: ?string, deleted: bool}> $notes internal notes, oldest first (3.25, Core\Comments; 3.26 with the deleted ones)
 * @var array<string, mixed> $tasks the enquiry's tasks and the follow-up buttons (3.27, Admin\TaskActions::taskPanel)
 * @var array<string, mixed>|null $contactBox the customer of the enquiry (3.28, Admin\Modules\Contacts::box); null without Customers
 * @var bool $toApplication may move the enquiry to Applications (3.34: the person has both sections)
 * @var array<string, mixed>|null $dealBox the deal of the enquiry and "Create deal" (3.31, Admin\Modules\Pipeline::enquiryBox); null without the Pipeline
 * @var array<string, mixed> $thread the notes as a thread – names of the people mentioned, who may be mentioned, a refused text (3.26, Admin\CommentActions::commentThread)
 */
use Kaleta\Admin\Modules\Enquiries;

// 3.19.1 (N79): a page becomes a link only with a safe address (an imported enquiry carries what the old site sent)
$pageLink = Kaleta\Front\Forms::isSafePage((string) $p['stranka']);
?>
<div class="zaznam-lista" data-record-bar>
<a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All enquiries')) ?></a>
<div class="zaznam-akce">
	<span class="stitek<?= (int) $p['stav'] === 2 ? ' stitek-vydano' : '' ?>" data-record-state><?= e(t(Enquiries::STATUSES[(int) $p['stav']])) ?></span>
<?php if ($p['email'] !== '' && ($p['anonymizovano'] ?? null) === null && (int) $p['stav'] !== 2): /* the reply starts below the conversation – one tap there, also on a phone */ ?>
	<a class="navigace" href="#reply" data-record-reply><?= e(t('Reply')) ?></a>
<?php endif ?>
	<form method="post" action="<?= e($module->url('status')) ?>" data-record-status><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><input type="hidden" name="stav" value="<?= (int) $p['stav'] === 2 ? 1 : 2 ?>"><button class="<?= (int) $p['stav'] === 2 ? 'navigace' : 'tl' ?>" type="submit"><?= e(t((int) $p['stav'] === 2 ? 'Reopen' : 'Označit jako vyřízenou')) ?></button></form>
	<details class="zaznam-vice" data-zavrit-mimo data-record-more><summary><?= e(t('More')) ?></summary><div class="zaznam-vice-menu">
<?php if (($p['anonymizovano'] ?? null) === null): ?>
		<form method="post" action="<?= e($module->url('anonymise')) ?>" data-potvrdit="<?= e(t('Blank the name, e-mail, phone, message and attachments of this enquiry? The row stays for statistics.')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace" type="submit"><?= e(t('Anonymise')) ?></button></form>
<?php endif ?>
<?php if ($toApplication): /* 3.34: a job application that came through a general form */ ?>
		<form method="post" action="<?= e($module->url('to_application')) ?>" data-to-application data-potvrdit="<?= e(t('Move this enquiry to Applications? It leaves Enquiries with its notes, tasks and attachments; a deal made from it loses the link.')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace" type="submit"><?= e(t('Move to Applications')) ?></button></form>
<?php endif ?>
		<form method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete this enquiry and its attachments for good? This cannot be undone.')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</div></details>
</div>
</div>
<?php if ($tasks['replied']): /* 3.27: right after a reply – "Follow up if they do not answer" first */
    $taskHidden = implode('', array_map(fn (string $name, int|string $value): string => '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">', array_keys($tasks['fields']), $tasks['fields'])); ?>
<?= $app->view->render('admin/task_follow_up', ['csrf' => $csrf, 'action' => $tasks['action'], 'hidden' => $taskHidden, 'replied' => true]) ?>
<?php endif ?>
<div class="zaznam-obal"><div class="zaznam">
<div class="zaznam-hlavni">
<section class="zaznam-panel" aria-labelledby="enquiry-sent">
<h2 id="enquiry-sent"><?= e(t('What they sent')) ?></h2>
<?php if ($data === []): ?>
<p class="napoveda"><?= e(t('The form sent no fields.')) ?></p>
<?php else: ?>
<dl class="poptavka" data-enquiry-fields>
<?php foreach ($data as $i => $d): [$labelText, $value] = $d; ?>
	<dt><?= e($labelText) ?></dt><dd><?= $value === '' ? '<span class="napoveda">—</span>' : (isset($d[2]) ? '<a href="' . e($module->url('attachment', ['id' => (int) $p['idp'], 'field' => $i])) . '">' . e($value) . '</a>' : nl2br(e($value))) ?></dd>
<?php endforeach ?>
</dl>
<?php endif ?>
</section>
<?php
// 3.22 (C10): the reply goes from the site to the enquiry's own address; the history keeps who answered, when and what
$canReply = $p['email'] !== '' && ($p['anonymizovano'] ?? null) === null;
$mailto = 'mailto:' . e($p['email']) . '?subject=' . e(rawurlencode(Kaleta\Core\EnquiryReply::subject($p))) . (($p['navrh_odpovedi'] ?? '') !== '' ? '&amp;body=' . e(rawurlencode((string) $p['navrh_odpovedi'])) : '');
$statusText = ['sent' => 'E-mail sent', 'queued' => 'Waiting in the mail queue', 'failed' => 'Not delivered'];
?>
<?php /* 3.25: one conversation – the customer's message, the replies and the internal notes in the order of time (Core\EnquiryThread) */
// 3.26: "@" in a note offers the colleagues who may open Enquiries (not oneself)
$mentionsJson = (string) json_encode(array_values(array_filter($thread['mentionable'], fn (array $person): bool => $person['id'] !== $thread['me'])), JSON_UNESCAPED_UNICODE); ?>
<h2 id="thread"><?= e(t('Conversation')) ?></h2>
<ol class="odpovedi vlakno" data-thread data-replies>
<?php if (trim((string) ($p['poznamka'] ?? '')) !== ''): /* the summary – the one note an enquiry had before 3.25 – stays first */ ?>
	<li class="vlakno-poznamka" data-thread-item="summary"><p><strong><?= e(t('Summary')) ?></strong> · <span class="napoveda"><?= e(t('Internal – the customer does not see this. Edit it in Handling.')) ?></span></p>
		<div class="odpoved-text"><?= nl2br(e((string) $p['poznamka'])) ?></div></li>
<?php endif ?>
<?php foreach (Kaleta\Core\EnquiryThread::entries($p, $replies, $notes) as $item): $entry = $item['entry']; ?>
<?php if ($item['type'] === 'customer'): ?>
	<li class="vlakno-zakaznik" data-thread-item="customer"><p><strong><?= e(t('%s wrote on %s', $entry['from'] !== '' ? $entry['from'] : t('The customer'), format_date($item['at'], true))) ?></strong></p>
		<div class="odpoved-text"><?= $entry['text'] !== '' ? nl2br(e($entry['text'])) : '<span class="napoveda">' . e(t('The form has no message text – the fields are above.')) . '</span>' ?></div></li>
<?php elseif ($item['type'] === 'note' && !empty($entry['deleted'])): /* 3.26: a deleted note stays as a quiet line */ ?>
	<li class="vlakno-poznamka vlakno-smazany" data-thread-item="note" data-comment="<?= (int) $entry['id'] ?>" data-comment-deleted><p class="napoveda"><?= e(t('Note deleted · %s, %s', $entry['by'] !== '' ? $entry['by'] : '—', format_date($item['at'], true))) ?></p></li>
<?php elseif ($item['type'] === 'note'): ?>
	<li class="vlakno-poznamka" data-thread-item="note" data-comment="<?= (int) $entry['id'] ?>"><p><strong><?= e(t('Note by %s on %s', $entry['by'] !== '' ? $entry['by'] : '—', format_date($item['at'], true))) ?></strong><?= $entry['via'] === 'mcp' ? ' · ' . e(t('through Claude')) : '' ?><?= ($entry['edited'] ?? null) !== null ? ' · <span class="napoveda">' . e(t('edited')) . '</span>' : '' ?> · <span class="napoveda"><?= e(t('Internal – the customer does not see this')) ?></span></p>
		<div class="odpoved-text"><?= $entry['text'] !== '' ? Kaleta\Core\Comments::html($entry['text'], $thread['people']) : '<span class="napoveda">' . e(t('The text was removed when the enquiry was anonymised.')) . '</span>' ?></div>
<?php if (($p['anonymizovano'] ?? null) === null): /* 3.26: the author edits or deletes their note, an administrator deletes any */ ?>
		<?= $app->view->render('admin/comment_actions', ['c' => $entry, 'action' => $module->url('comment'), 'fields' => $thread['fields'], 'csrf' => $csrf, 'me' => $thread['me'], 'admin' => $thread['admin'], 'draft' => $thread['draft'], 'mentionsJson' => $mentionsJson]) ?>
<?php endif ?>
	</li>
<?php else: $reply = $entry; ?>
	<li class="vlakno-odpoved" data-thread-item="reply"><p><strong><?= e(t('Replied by %s on %s', $reply['by'] !== '' ? $reply['by'] : '—', format_date((string) $reply['at'], true))) ?></strong><?= $reply['via'] === 'mcp' ? ' · ' . e(t('through Claude')) : '' ?>
		· <span class="stitek stitek-<?= $reply['status'] === 'sent' ? 'vydano' : ($reply['status'] === 'queued' ? 'koncept' : 'chyba') ?>" data-reply-status="<?= e($reply['status']) ?>"><?= e(t($statusText[$reply['status']])) ?></span><?php if ($reply['error'] !== '' && $reply['status'] !== 'sent'): ?> <span class="napoveda"><?= e($reply['error']) ?></span><?php endif ?></p>
<?php if ($reply['text'] !== ''): ?>
		<details><summary><?= e($reply['subject']) ?><?= $reply['to'] !== '' ? ' → ' . e($reply['to']) : '' ?></summary><div class="odpoved-text"><?= nl2br(e($reply['text'])) ?></div></details>
<?php endif ?>
	</li>
<?php endif ?>
<?php endforeach ?>
</ol>
<form method="post" action="<?= e($module->url('note')) ?>" class="vlakno-poznamka-form" data-note-form>
	<?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>">
	<div class="radek"><label for="nova-poznamka"><?= e(t('Internal note')) ?></label><div><textarea class="textbox nizky" id="nova-poznamka" name="add_note" rows="3" maxlength="<?= Kaleta\Core\Comments::MAX_TEXT ?>" required data-mentions="<?= e($mentionsJson) ?>"><?= e($thread['draft'] !== null && $thread['draft']['comment'] === 0 ? $thread['draft']['text'] : '') ?></textarea>
		<span class="napoveda"><?= e(t('Only administration users see it – it is added to the conversation with your name and the time. Type @ to mention a colleague – they are notified.')) ?></span></div></div>
	<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Add internal note')) ?></button></p>
</form>
<h2 id="reply"><?= e(t('Reply')) ?></h2>
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
	<?php /* 3.32 (R4): the follow-up is offered with the reply – with no inbound mail, the task is the only memory of a reply left unanswered */ ?>
	<div class="radek"><label for="odpoved-navazat"><?= e(t('Follow up if they do not answer')) ?></label><div><select id="odpoved-navazat" name="follow_up" data-reply-follow-up><option value=""><?= e(t('No follow-up')) ?></option>
<?php foreach (Kaleta\Admin\TaskPanel::FOLLOW_UP as $key => $days): ?>
		<option value="<?= e($key) ?>"><?= e(t(Kaleta\Admin\TaskPanel::DAY_LABELS[$key])) ?></option>
<?php endforeach ?>
	</select> <span class="napoveda"><?= e(t('Adds a task for you in My work about this enquiry.')) ?></span></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Send reply')) ?></button> <a class="navigace" href="<?= $mailto ?>"><?= e(t('Reply from your own mail program')) ?></a></p>
</form>
<?php endif ?>
<?php
// 3.23.1 (owner): asking for a testimonial is a secondary step after a job done – only for a customer with an e-mail, not for spam,
// a job application or a supplier, and only once the enquiry is resolved or a request already exists (its status lines stay)
$testimonialFits = $p['email'] !== '' && !in_array((string) $p['kategorie'], Kaleta\Core\Testimonials::NOT_FOR_KINDS, true) && ((int) $p['stav'] === 2 || $testimonials !== []);
if ($testimonialFits): ?>
<details class="reference-zadost" data-testimonial<?= $testimonials !== [] ? ' open' : '' ?>>
<summary><?= e(t('Ask the customer for a testimonial')) ?></summary>
<?php foreach ($testimonials as $req): ?>
<p class="smltxt"><?= e(format_date((string) $req['created_at'], true)) ?> · <?= $req['used_at'] !== null
    ? e(t('Answered')) . ($req['item_id'] !== null ? ' – <a href="' . e($app->url('admin.php?module=collections&action=item&id=' . (int) $app->db()->value('SELECT idk FROM {kolekce_polozky} WHERE idp = ?', [(int) $req['item_id']]) . '&item=' . (int) $req['item_id'])) . '">' . e(t('the draft reference')) . '</a>' : '')
    : e(strtotime((string) $req['expires_at']) < time() ? t('Expired') : t('Waiting for the answer')) ?></p>
<?php endforeach ?>
<p><?= e(t('Was the customer happy? Send them a link where they can write a few words about you for your website. Nothing is published until you publish it.')) ?></p>
<?php /* 3.23.1 (N-C10): one behaviour for every role – the References collection is created with the first answer (Testimonials::save) */ ?>
<form class="vradku" method="post" action="<?= e($module->url('testimonial')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $p['idp'] ?>">
	<button class="navigace" type="submit" name="poslat" value="1"><?= e(t('Ask for a testimonial by e-mail')) ?></button> <button class="navigace" type="submit" name="poslat" value="0"><?= e(t('Only create the link')) ?></button></form>
</details>
<?php endif ?>
</div>

<div class="zaznam-bok">
<section class="zaznam-panel" id="handling" aria-labelledby="enquiry-handling" data-enquiry-handling>
<h2 id="enquiry-handling"><?= e(t('Handling')) ?></h2>
<?php /* 3.37 (U26): one form, one Save – who handles it and the summary (Enquiries::saveHandling) with the kind, the priority and the drafted reply (a person's triage only when it changed) */ ?>
<form class="zaznam-formular" method="post" action="<?= e($module->url('triage')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $p['idp'] ?>">
	<div class="zaznam-pole"><label for="prirazeno"><?= e(t('Responsible person')) ?></label><select id="prirazeno" name="prirazeno"><option value="0">—</option>
<?php foreach ($users as $userId => $displayName): ?>
		<option value="<?= (int) $userId ?>"<?= (int) ($p['prirazeno'] ?? 0) === (int) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select></div>
	<div class="zaznam-dvojice">
	<div class="zaznam-pole"><label for="kategorie"><?= e(t('Kind')) ?></label><select id="kategorie" name="kategorie"><option value="">—</option>
<?php foreach (Kaleta\Core\Triage::CATEGORIES as $key => $name): ?>
		<option value="<?= e($key) ?>"<?= $p['kategorie'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></div>
	<div class="zaznam-pole"><label for="priorita"><?= e(t('Priority')) ?></label><select id="priorita" name="priorita"><option value="0">—</option>
<?php foreach ([3 => 'urgent', 2 => 'normal', 1 => 'can wait'] as $value => $name): ?>
		<option value="<?= $value ?>"<?= (int) $p['priorita'] === $value ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></div>
	</div>
	<?php if ($p['triaged_by'] !== ''): ?><span class="napoveda"><?= e(t('Sorted by %s', match ($p['triaged_by']) { 'claude' => 'Claude', 'assistant' => t('the writing assistant'), 'rule' => t('a rule'), default => $p['triaged_by'] })) ?></span><?php endif ?>
	<div class="zaznam-pole"><label for="poznamka"><?= e(t('Summary')) ?></label><textarea class="textbox nizky" id="poznamka" name="poznamka" rows="3"><?= e((string) ($p['poznamka'] ?? '')) ?></textarea>
		<span class="napoveda"><?= e(t('One short internal summary shown first in the conversation – it can be rewritten. Add notes in the conversation; they are kept as written.')) ?></span></div>
	<div class="zaznam-pole"><label for="navrh-odpovedi"><?= e(t('Drafted reply')) ?></label><textarea class="textbox nizky" id="navrh-odpovedi" name="navrh_odpovedi" rows="4"><?= e((string) ($p['navrh_odpovedi'] ?? '')) ?></textarea>
		<span class="napoveda"><?= e(t('Never sent by itself – check it, then send it under Reply.')) ?></span></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save')) ?></button><?php if ($p['kategorie'] !== 'spam'): ?> <button class="navigace" type="submit" name="kategorie" value="spam"><?= e(t('Mark as spam')) ?></button><?php endif ?></p>
</form>
</section>
<?php if ($contactBox !== null): /* 3.28: the customer this enquiry belongs to */ ?>
<?= $app->view->render('admin/contact_box', ['csrf' => $csrf, 'box' => $contactBox]) ?>
<?php endif ?>
<?php if ($dealBox !== null): /* 3.31: the deal this enquiry became, or Create deal */ ?>
<?= $app->view->render('admin/deal_box', ['csrf' => $csrf, 'box' => $dealBox]) ?>
<?php endif ?>
<?= $app->view->render('admin/tasks_panel', $tasks + ['app' => $app, 'csrf' => $csrf]) /* 3.27 */ ?>
<section class="zaznam-panel" aria-labelledby="enquiry-facts" data-enquiry-facts>
<h2 id="enquiry-facts"><?= e(t('Details')) ?></h2>
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
</dl>
</section>
</div>
</div></div>
