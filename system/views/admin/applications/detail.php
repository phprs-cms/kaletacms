<?php
/**
 * A job application (3.34, Core\Applications::get): the position, who applied and what they sent, the CVs (Download – the
 * file is decrypted only for the download), the status (rejected asks for a reason), the responsible person, the consent
 * for future openings (withdraw), the day it will be deleted, the replies sent, the status history, the team's notes and
 * tasks (application:N) and Delete. Reply templates come with part B.
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
 * @var int $me
 */
use Kaleta\Core\Applications as Store;

$id = (int) $a['id'];
$label = fn (string $status): string => t(Store::STATUS_LABELS[$status] ?? $status);
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All applications')) ?></a></p>
<section class="zakaznik-karta" data-application-card="<?= $id ?>">
<dl class="poptavka">
	<dt><?= e(t('Position')) ?></dt><dd data-application-position><?= (string) $a['job_title'] !== '' ? bdi((string) $a['job_title']) : '<span class="napoveda">' . e(t('No position (a spontaneous application)')) . '</span>' ?><?= $a['late'] ? ' <span class="stitek">' . e(t('after the closing date')) . '</span>' : '' ?></dd>
	<dt><?= e(t('Status')) ?></dt><dd data-application-status="<?= e((string) $a['status']) ?>"><span class="stitek<?= $a['status'] === 'hired' ? ' stitek-vydano' : ($a['status'] === 'rejected' ? ' stitek-chyba' : '') ?>"><?= e($label((string) $a['status'])) ?></span><?= $a['reject_reason'] !== null ? ' · ' . e(t(Store::REJECT_REASONS[$a['reject_reason']] ?? (string) $a['reject_reason'])) : '' ?></dd>
	<dt><?= e(t('Received')) ?></dt><dd><?= e(format_date((string) $a['received_at'], true)) ?> · <?= bdi((string) $a['form']) ?></dd>
<?php if ((string) $a['email'] !== ''): ?>
	<dt><?= e(t('E-mail')) ?></dt><dd><a href="mailto:<?= e((string) $a['email']) ?>"><?= bdi((string) $a['email']) ?></a></dd>
<?php endif ?>
<?php foreach ($a['data'] as $field): if (!is_scalar($field[0] ?? null) || !is_scalar($field[1] ?? null)) { continue; } ?>
	<dt><?= e((string) $field[0]) ?></dt><dd><?= nl2br(e((string) $field[1])) ?></dd>
<?php endforeach ?>
<?php if ($a['attachments'] !== []): ?>
	<dt><?= e(t('CV and attachments')) ?></dt><dd data-application-files><?php foreach ($a['attachments'] as $i => $file): ?>
		<a class="navigace" href="<?= e($module->url('attachment', ['id' => $id, 'index' => $i])) ?>" data-application-file="<?= (int) $i ?>"><?= e(t('Download')) ?>: <?= bdi($file['name']) ?></a> <small><?= e(Kaleta\Core\Files::size($file['size'])) ?></small>
	<?php endforeach ?></dd>
<?php endif ?>
	<dt><?= e(t('Consent for future openings')) ?></dt><dd data-application-pool><?php if ($a['pool_until'] !== null): ?>
		<?= e(t('Given %s – kept until %s', format_date((string) $a['pool_consent_at']), format_date((string) $a['pool_until']))) ?>
		<form class="vradku" method="post" action="<?= e($module->url('consent')) ?>" data-potvrdit="<?= e(t('The applicant withdrew the consent? The application then follows the normal retention.')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><button class="navigace" type="submit"><?= e(t('Consent withdrawn')) ?></button></form>
	<?php else: ?><span class="napoveda"><?= e(t('Not given')) ?></span><?php endif ?></dd>
	<dt><?= e(t('Will be deleted')) ?></dt><dd data-application-deletes><?= $deletesOn !== null ? e(format_date($deletesOn)) : '<span class="napoveda">' . e(t('Not limited')) . '</span>' ?><?= $a['status'] === 'hired' ? '<br><small>' . e(t('Take what belongs in the personnel file before the application is deleted.')) . '</small>' : '' ?></dd>
<?php if ($a['source_enquiry_id'] !== null): ?>
	<dt><?= e(t('Came from')) ?></dt><dd><?= e(t('Moved from the enquiry #%s', (string) $a['source_enquiry_id'])) ?></dd>
<?php endif ?>
</dl>
<form class="zakaznik-odpovedny" method="post" action="<?= e($module->url('assign')) ?>" data-application-assignee>
	<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>">
	<label for="uchazec-odpovedny"><?= e(t('Responsible person')) ?></label>
	<select id="uchazec-odpovedny" name="assignee_id"><option value="0">—</option>
<?php foreach ($people as $userId => $name): ?>
		<option value="<?= (int) $userId ?>"<?= (int) $a['assignee_id'] === (int) $userId ? ' selected' : '' ?>><?= e((int) $userId === $me ? t('Me (%s)', $name) : $name) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit"><?= e(t('Save')) ?></button>
</form>
<form class="vradku" method="post" action="<?= e($module->url('status')) ?>" data-application-move>
	<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>">
	<label for="uchazec-stav"><?= e(t('Status')) ?></label>
	<select id="uchazec-stav" name="status">
<?php foreach (Store::STATUSES as $status): ?>
		<option value="<?= e($status) ?>"<?= $status === $a['status'] ? ' selected' : '' ?>><?= e($label($status)) ?></option>
<?php endforeach ?>
	</select>
	<label for="uchazec-duvod"><?= e(t('Reason when rejected')) ?></label>
	<select id="uchazec-duvod" name="reject_reason">
<?php foreach (Store::REJECT_REASONS as $key => $reason): ?>
		<option value="<?= e($key) ?>"<?= $key === $a['reject_reason'] ? ' selected' : '' ?>><?= e(t($reason)) ?></option>
<?php endforeach ?>
	</select>
	<button class="tl" type="submit"><?= e(t('Change status')) ?></button>
</form>
<div class="tlacitka zakaznik-tlacitka">
	<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-application-delete data-potvrdit="<?= e(t('Delete this application for good, with its CVs, notes and tasks? This cannot be undone.')) ?>">
		<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
</div>
</section>
<?php if ($a['replies'] !== []): ?>
<section data-application-replies>
<h2><?= e(t('Replies')) ?></h2>
<?php foreach ($a['replies'] as $reply): ?>
<article><p class="napoveda"><?= e(format_date((string) $reply['at'], true)) ?> · <?= e((string) $reply['by']) ?> · <?= bdi((string) $reply['subject']) ?></p><p><?= nl2br(e((string) $reply['text'])) ?></p></article>
<?php endforeach ?>
</section>
<?php endif ?>
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
<?= $app->view->render('admin/comments', $thread + ['app' => $app, 'csrf' => $csrf]) ?>
<?= $app->view->render('admin/tasks_panel', $tasks + ['app' => $app, 'csrf' => $csrf]) ?>
