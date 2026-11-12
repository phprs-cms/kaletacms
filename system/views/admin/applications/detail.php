<?php
/**
 * A job application (3.34, Core\Applications::get) in two columns on a wide screen, one on a phone. The main column: who
 * applied and what they sent, the CVs (Download – the file is decrypted only for the download), the replies sent, the
 * team's notes. The side column: the status (rejected asks for a reason), the responsible person, the consent for future
 * openings (withdraw), the day it will be deleted, other applications from the same address, the tasks, the status history
 * and Delete (administrators – HR rejects, the retention deletes).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Applications $module
 * @var string $csrf
 * @var array<string, mixed> $a Core\Applications::get
 * @var array<int, string> $people who may be responsible
 * @var array<int, string> $names people by id
 * @var string|null $deletesOn
 * @var array<string, mixed> $thread Admin\CommentActions::commentThread
 * @var array<string, mixed> $tasks Admin\TaskActions::taskPanel
 * @var list<array{id: int, job_title: string, status: string, received_at: string, deletes_on: ?string}> $others other applications from the same address
 * @var int $othersCount all other applications from the same address (3.40)
 * @var int $me
 * @var bool $admin
 * @var array{subject: string, text: string, template: string} $reply the reply form: a chosen template filled in, or a refused reply again
 * @var list<array<string, mixed>> $replies replies sent with their delivery state (Core\EnquiryReply::history)
 * @var string|null $replyBlocked why the site cannot send a reply (no SMTP, no sender); null = it can
 * @var string $replyTo the signed-in user's e-mail – the Reply-To of a reply
 * @var bool $hasSignature the signed-in user has an e-mail signature
 */
use Kaleta\Core\Applications as Store;

$id = (int) $a['id'];
$label = fn (string $status): string => t(Store::STATUS_LABELS[$status] ?? $status);
$tone = fn (string $status): string => $status === 'hired' ? ' stitek-vydano' : ($status === 'rejected' ? ' stitek-chyba' : ($status === 'new' ? ' stitek-koncept' : ''));
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All applications')) ?></a></p>
<div class="uchazec-detail" data-application-card="<?= $id ?>">
<div class="uchazec-hlavni">
<section class="zakaznik-karta" aria-labelledby="uchazec-kdo">
<h2 id="uchazec-kdo"><?= bdi((string) $a['name'] !== '' ? (string) $a['name'] : ((string) $a['email'] !== '' ? (string) $a['email'] : t('Application #%s', (string) $id))) ?></h2>
<dl class="poptavka">
	<dt><?= e(t('Position')) ?></dt><dd data-application-position><?= (string) $a['job_title'] !== '' ? bdi((string) $a['job_title']) : '<span class="napoveda">' . e(t('No position (a spontaneous application)')) . '</span>' ?><?= $a['late'] ? ' <span class="stitek">' . e(t('after the closing date')) . '</span>' : '' ?></dd>
	<dt><?= e(t('Received')) ?></dt><dd><?= e(format_date((string) $a['received_at'], true)) ?> · <?= bdi((string) $a['form']) ?></dd>
<?php if ((string) $a['email'] !== ''): ?>
	<dt><?= e(t('E-mail')) ?></dt><dd><a href="mailto:<?= e((string) $a['email']) ?>"><?= bdi((string) $a['email']) ?></a></dd>
<?php endif ?>
<?php if ((string) $a['phone'] !== ''): ?>
	<dt><?= e(t('Phone')) ?></dt><dd><a href="tel:<?= e(preg_replace('/[^0-9+]/', '', (string) $a['phone'])) ?>"><?= bdi((string) $a['phone']) ?></a></dd>
<?php endif ?>
<?php foreach ($a['data'] as $field): if (!is_scalar($field[0] ?? null) || !is_scalar($field[1] ?? null)) { continue; } ?>
	<dt><?= e((string) $field[0]) ?></dt><dd><?= (string) $field[1] !== '' ? nl2br(e((string) $field[1])) : '<span class="napoveda">—</span>' ?></dd>
<?php endforeach ?>
<?php if ($a['attachments'] !== []): ?>
	<dt><?= e(t('CV and attachments')) ?></dt><dd data-application-files><ul class="uchazec-soubory"><?php foreach ($a['attachments'] as $i => $file): ?>
		<li><a class="navigace" href="<?= e($module->url('attachment', ['id' => $id, 'index' => $i])) ?>" data-application-file="<?= (int) $i ?>"><?= e(t('Download')) ?>: <?= bdi($file['name']) ?></a> <small><?= e(Kaleta\Core\Files::size($file['size'])) ?></small></li>
	<?php endforeach ?></ul></dd>
<?php endif ?>
<?php if ($a['attachments_locked']): /* 3.34 review M5: never "no attachments" */ ?>
	<dt><?= e(t('CV and attachments')) ?></dt><dd class="hlaska hlaska-chyba" data-application-files-locked><?= e(t('CVs cannot be opened – encryption key')) ?>. <?= e(t('This application\'s list of CVs was sealed with an encryption key this config.php does not have. Put back the vault_key line from the original config.php; until then the CVs are kept and the clean-up does not delete the application.')) ?></dd>
<?php endif ?>
<?php if ($a['source_enquiry_id'] !== null): ?>
	<dt><?= e(t('Came from')) ?></dt><dd><?= e(t('Moved from the enquiry #%s', (string) $a['source_enquiry_id'])) ?></dd>
<?php endif ?>
</dl>
</section>
<?php $statusText = ['sent' => 'E-mail sent', 'queued' => 'Waiting in the mail queue', 'failed' => 'Not delivered']; ?>
<?php if ($replies !== []): ?>
<section data-application-replies aria-labelledby="uchazec-odpovedi">
<h2 id="uchazec-odpovedi"><?= e(t('Replies')) ?></h2>
<ol class="odpovedi vlakno">
<?php foreach ($replies as $r): ?>
	<li class="vlakno-odpoved"><p><strong><?= e(t('Replied by %s on %s', (string) $r['by'] !== '' ? (string) $r['by'] : '—', format_date((string) $r['at'], true))) ?></strong><?= $r['via'] === 'mcp' ? ' · ' . e(t('through Claude')) : '' ?>
		· <span class="stitek stitek-<?= $r['status'] === 'sent' ? 'vydano' : ($r['status'] === 'queued' ? 'koncept' : 'chyba') ?>" data-reply-status="<?= e((string) $r['status']) ?>"><?= e(t($statusText[$r['status']] ?? 'Not delivered')) ?></span></p>
		<details><summary><?= bdi((string) $r['subject']) ?></summary><div class="odpoved-text"><?= nl2br(e((string) $r['text'])) ?></div></details></li>
<?php endforeach ?>
</ol>
</section>
<?php endif ?>
<section class="uchazec-odpoved" id="reply" aria-labelledby="uchazec-odpoved-nadpis">
<h2 id="uchazec-odpoved-nadpis"><?= e(t('Reply')) ?></h2>
<?php if ((string) $a['email'] === ''): ?>
<p class="napoveda" data-reply-none><?= e(t('This application has no e-mail address to reply to – call the applicant, and note it in the internal note.')) ?></p>
<?php elseif ($replyBlocked !== null): ?>
<p class="hlaska hlaska-info" data-reply-blocked><?= e(t($replyBlocked)) ?><?php if ($admin): ?> <a href="<?= e($app->url('admin.php?module=settings&tab=mail')) ?>"><?= e(t('Set up the mail')) ?></a><?php endif ?></p>
<p class="tlacitka"><a class="tl" href="mailto:<?= e((string) $a['email']) ?>"><?= e(t('Reply by email')) ?></a></p>
<?php else: ?>
<form class="uchazec-sablona" method="get" action="<?= e($app->url('admin.php')) ?>" data-reply-template>
	<input type="hidden" name="module" value="<?= e(Store::SECTION) ?>"><input type="hidden" name="action" value="detail"><input type="hidden" name="id" value="<?= $id ?>">
	<label for="uchazec-sablona"><?= e(t('Template')) ?></label>
	<select id="uchazec-sablona" name="template"><option value=""><?= e(t('No template')) ?></option>
<?php foreach (Store::TEMPLATE_LABELS as $key => $templateLabel): ?>
		<option value="<?= e($key) ?>"<?= $reply['template'] === $key ? ' selected' : '' ?>><?= e(t($templateLabel)) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit"><?= e(t('Use the template')) ?></button>
</form>
<form method="post" action="<?= e($module->url('reply')) ?>" data-reply-form data-potvrdit="<?= e(t('Send this reply to %s now? It goes out as a real e-mail and cannot be taken back.', (string) $a['email'])) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="template" value="<?= e($reply['template']) ?>">
	<div class="radek"><span class="popisek"><?= e(t('Recipient')) ?></span><div><strong><?= bdi((string) $a['email']) ?></strong></div></div>
	<div class="radek"><label for="uchazec-predmet"><?= e(t('Subject')) ?></label><div><input class="textpole siroke" type="text" id="uchazec-predmet" name="subject" maxlength="<?= Kaleta\Core\EnquiryReply::MAX_SUBJECT ?>" value="<?= e($reply['subject']) ?>" required></div></div>
	<div class="radek"><label for="uchazec-text"><?= e(t('Reply')) ?></label><div><textarea class="textbox" id="uchazec-text" name="text" rows="9" maxlength="<?= Kaleta\Core\EnquiryReply::MAX_TEXT ?>" required><?= e($reply['text']) ?></textarea>
		<span class="napoveda"><?= $module->hint(match (true) {
		    $replyTo === '' => t('Sent from the site’s address. Your account has no e-mail, so the applicant’s answer goes to the site’s reply address – add yours in My account.'),
		    $hasSignature => t('Sent from the site’s address; when the applicant answers, it comes to you (%s). Your e-mail signature from My account is added below the text.', $replyTo),
		    default => t('Sent from the site’s address; when the applicant answers, it comes to you (%s). You have no e-mail signature yet, so the reply goes without one – see My account → My e-mail signature.', $replyTo),
		}) ?></span></div></div>
	<div class="radek"><label for="uchazec-nastavit"><?= e(t('Then set the status to')) ?></label><div class="uchazec-stav">
		<select id="uchazec-nastavit" name="status"><option value=""><?= e(t('Keep it (a new one becomes reviewed)')) ?></option>
<?php foreach (Store::STATUSES as $status): ?>
			<option value="<?= e($status) ?>"<?= ($reply['template'] === 'rejection' && $status === 'rejected') || ($reply['template'] === 'interview' && $status === 'interview') ? ' selected' : '' ?>><?= e($label($status)) ?></option>
<?php endforeach ?>
		</select>
		<span class="uchazec-duvod"><label for="uchazec-nastavit-duvod"><?= e(t('Reason when rejected')) ?></label>
		<select id="uchazec-nastavit-duvod" name="reject_reason">
<?php foreach (Store::REJECT_REASONS as $key => $reason): ?>
			<option value="<?= e($key) ?>"><?= e(t($reason)) ?></option>
<?php endforeach ?>
		</select></span></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Send reply')) ?></button> <a class="navigace" href="mailto:<?= e((string) $a['email']) ?>"><?= e(t('Reply from your own mail program')) ?></a></p>
</form>
<?php endif ?>
</section>
<?= $app->view->render('admin/comments', $thread + ['app' => $app, 'csrf' => $csrf]) ?>
</div>

<aside class="uchazec-bok" aria-label="<?= e(t('Status and responsibility')) ?>">
<section class="zakaznik-karta">
<p data-application-status="<?= e((string) $a['status']) ?>"><span class="stitek<?= $tone((string) $a['status']) ?>"><?= e($label((string) $a['status'])) ?></span><?= $a['reject_reason'] !== null ? ' · ' . e(t(Store::REJECT_REASONS[$a['reject_reason']] ?? (string) $a['reject_reason'])) : '' ?></p>
<form class="uchazec-stav" method="post" action="<?= e($module->url('status')) ?>" data-application-move>
	<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>">
	<label for="uchazec-stav"><?= e(t('Status')) ?></label>
	<select id="uchazec-stav" name="status">
<?php foreach (Store::STATUSES as $status): ?>
		<option value="<?= e($status) ?>"<?= $status === $a['status'] ? ' selected' : '' ?>><?= e($label($status)) ?></option>
<?php endforeach ?>
	</select>
	<span class="uchazec-duvod">
		<label for="uchazec-duvod"><?= e(t('Reason when rejected')) ?></label>
		<select id="uchazec-duvod" name="reject_reason">
<?php foreach (Store::REJECT_REASONS as $key => $reason): ?>
			<option value="<?= e($key) ?>"<?= $key === $a['reject_reason'] ? ' selected' : '' ?>><?= e(t($reason)) ?></option>
<?php endforeach ?>
		</select>
	</span>
	<button class="tl" type="submit"><?= e(t('Change status')) ?></button>
</form>
<form class="uchazec-stav" method="post" action="<?= e($module->url('assign')) ?>" data-application-assignee>
	<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>">
	<label for="uchazec-odpovedny"><?= e(t('Responsible person')) ?></label>
	<select id="uchazec-odpovedny" name="assignee_id"><option value="0">—</option>
<?php foreach ($people as $userId => $name): ?>
		<option value="<?= (int) $userId ?>"<?= (int) $a['assignee_id'] === (int) $userId ? ' selected' : '' ?>><?= e((int) $userId === $me ? t('Me (%s)', $name) : $name) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit"><?= e(t('Save')) ?></button>
</form>
<dl class="poptavka">
	<dt><?= e(t('Consent for future openings')) ?></dt><dd data-application-pool><?php if ($a['pool_until'] !== null): ?>
		<?= e(t('Given %s – kept until %s', format_date((string) $a['pool_consent_at']), format_date((string) $a['pool_until']))) ?>
		<?php if ((string) $a['pool_consent_text'] !== ''): ?><br><small><?= bdi((string) $a['pool_consent_text']) ?></small><?php endif ?>
		<?php if (Kaleta\Core\TalentPool::renewalSent($a)): /* 3.40 */ ?><br><small data-application-pool-renewal><?= e(t('Renewal e-mail sent %s', format_date((string) $a['pool_renewal_sent_at']))) ?></small><?php endif ?>
		<form class="vradku" method="post" action="<?= e($module->url('consent')) ?>" data-potvrdit="<?= e(t('The applicant withdrew the consent? The application then follows the normal retention.')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><button class="navigace" type="submit"><?= e(t('Consent withdrawn')) ?></button></form>
	<?php else: ?><span class="napoveda"><?= e(t('Not given')) ?></span><?php endif ?></dd>
	<dt><?= e(t('Will be deleted')) ?></dt><dd data-application-deletes><?= $deletesOn !== null ? e(format_date($deletesOn)) : '<span class="napoveda">' . e(t('Not limited')) . '</span>' ?><?= $a['status'] === 'hired' ? '<br><small>' . e(t('Take what belongs in the personnel file before the application is deleted.')) . '</small>' : '' ?></dd>
<?php if ($others !== []): ?>
	<dt><?= e(t('Other applications from this address')) ?></dt><dd data-application-others><ul class="uchazec-soubory">
<?php foreach ($others as $o): ?>
		<li><a href="<?= e($module->url('detail', ['id' => $o['id']])) ?>">#<?= (int) $o['id'] ?></a> · <?= $o['job_title'] !== '' ? bdi($o['job_title']) : e(t('No position')) ?> · <span class="stitek<?= $tone($o['status']) ?>"><?= e($label($o['status'])) ?></span> <small><?= e(format_date($o['received_at'])) ?><?= $o['deletes_on'] !== null ? ' · ' . e(t('deleted on %s', format_date($o['deletes_on']))) : '' ?></small></li>
<?php endforeach ?>
	</ul><?php if ($othersCount > count($others)): ?><small data-application-others-more><a href="<?= e($module->url('', ['status' => '', 'search' => (string) $a['email']])) ?>"><?= e(t('All %d from this address', $othersCount)) ?></a></small><?php endif ?></dd>
<?php endif ?>
</dl>
<?php if ($admin): ?>
<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-application-delete data-potvrdit="<?= e(t('Delete this application for good, with its CVs, notes and tasks? This cannot be undone.')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
<?php else: ?>
<p class="napoveda"><?= e(t('An application is deleted by its retention, or by an administrator. Reject it when it is decided.')) ?></p>
<?php endif ?>
</section>
<?= $app->view->render('admin/tasks_panel', $tasks + ['app' => $app, 'csrf' => $csrf]) ?>
<?php if ($a['history'] !== []): ?>
<section class="obchod-historie-obal" id="history" data-application-history>
<h2><?= e(t('History')) ?></h2>
<ol class="obchod-historie">
<?php foreach (array_reverse($a['history']) as $h): ?>
	<li><span class="obchod-historie-co"><?= e($label((string) ($h['from'] ?? '')) . ' → ' . $label((string) ($h['to'] ?? ''))) ?></span>
		<span class="napoveda"><?= e(format_date((string) ($h['at'] ?? ''), true)) ?><?= isset($h['by']) && ($names[(int) $h['by']] ?? '') !== '' ? ' · ' . e($names[(int) $h['by']]) : '' ?></span></li>
<?php endforeach ?>
</ol>
</section>
<?php endif ?>
</aside>
</div>
