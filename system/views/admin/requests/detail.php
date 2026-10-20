<?php
/**
 * A request to Claude (2.15): the request itself, its attachments, the conversation – Claude's notes with links to the
 * drafts it made and the person's replies – and the status.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Requests $module
 * @var string $csrf
 * @var array<string, mixed> $r the request with 'author' and decoded 'attachments'
 * @var array{type: string, id: ?int, title: string, url: string}|null $about
 * @var list<array{id: int, name: string, url: string, size: string, image: bool}> $attachments
 * @var list<array{id: int, sender: string, sender_name: string, text: string, links: list<array{label: string, url: string}>, created_at: string}> $messages
 * @var bool $mine the signed-in user wrote it
 * @var array{state: string, last_call: ?string, pickup: ?string, stalled_since: ?string} $claude whether requests reach Claude now (3.18)
 */
use Kaleta\Core\Requests;

$tag = fn (string $status): string => '<span class="stitek' . match ($status) { 'done' => ' stitek-vydano', 'new' => ' stitek-koncept', 'declined' => ' stitek-chyba', default => '' } . '">' . e(t(Requests::STATUSES[$status] ?? $status)) . '</span>';
$moves = Requests::TRANSITIONS[$r['status']] ?? [];
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All requests')) ?></a></p>
<?php if ($r['status'] === 'new' && Kaleta\Core\ClaudeConnection::blocked($claude)): // 3.18: a new request nothing picks up says so ?>
<?= $app->view->render('admin/claude_notice', ['app' => $app, 'claude' => $claude]) ?>
<?php endif ?>
<div class="formular">
<dl class="poptavka">
	<dt><?= e(t('Request')) ?></dt><dd><strong><?= e((string) $r['title']) ?></strong></dd>
	<dt><?= e(t('Author')) ?></dt><dd><?= e((string) $r['author']) ?> · <span class="smltxt"><?= e(format_date((string) $r['created_at'], true)) ?></span></dd>
	<dt><?= e(t('Status')) ?></dt><dd><?= $tag((string) $r['status']) ?><?= $r['done_at'] !== null ? ' <span class="smltxt">' . e(format_date((string) $r['done_at'], true)) . '</span>' : '' ?></dd>
<?php if ($about !== null): ?>
	<dt><?= e(t('It is about')) ?></dt><dd><?= $about['url'] !== '' ? '<a href="' . e($about['url']) . '" target="_blank" rel="noopener">' . e($about['title']) . '</a>' : e($about['title']) ?><?= $about['type'] !== 'url' ? ' <span class="smltxt">(' . e(t(match ($about['type']) { 'page' => 'page', 'news' => 'news item', default => 'collection item' })) . ')</span>' : '' ?></dd>
<?php endif ?>
	<dt><?= e(t('Text')) ?></dt><dd><?= nl2br(e((string) $r['text'])) ?></dd>
<?php if ($attachments !== []): ?>
	<dt><?= e(t('Attachments')) ?></dt><dd><?php foreach ($attachments as $a): ?><a href="<?= e($a['url']) ?>" target="_blank" rel="noopener"><?= e($a['name']) ?></a> <span class="smltxt">(<?= e($a['size']) ?>)</span><br><?php endforeach ?>
	<span class="napoveda"><?= e(t('Saved in Media – Claude places them on the site from there.')) ?></span></dd>
<?php endif ?>
</dl>
<h2><?= e(t('Conversation')) ?></h2>
<?php if ($messages === []): ?>
<p class="smltxt"><?= e(t('No notes yet. Claude writes here what it did and where the drafts are; you can reply below.')) ?></p>
<?php else: ?>
<?php foreach ($messages as $m): ?>
<p><strong><?= e($m['sender'] === 'claude' ? ($m['sender_name'] !== '' ? t('Claude (%s)', $m['sender_name']) : 'Claude') : ($m['sender_name'] !== '' ? $m['sender_name'] : t('Colleague'))) ?></strong> · <span class="smltxt"><?= e(format_date($m['created_at'], true)) ?></span><?= $m['text'] !== '' ? '<br>' . nl2br(e($m['text'])) : '' ?>
<?php if ($m['links'] !== []): ?><br><span class="smltxt"><?= e(t('Drafts:')) ?></span> <?php foreach ($m['links'] as $i => $l): ?><?= $i > 0 ? ' · ' : '' ?><?= $l['url'] !== '' ? '<a href="' . e($l['url']) . '" target="_blank" rel="noopener">' . e($l['label'] !== '' ? $l['label'] : $l['url']) . '</a>' : e($l['label']) ?><?php endforeach ?><?php endif ?></p>
<?php endforeach ?>
<?php endif ?>
<form method="post" action="<?= e($module->url('reply')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
	<div class="radek"><label for="req-reply"><?= e(t('Reply')) ?></label><div><textarea class="textbox nizky" id="req-reply" name="text" rows="4" maxlength="<?= Requests::MAX_MESSAGE ?>" required></textarea>
		<span class="napoveda"><?= e(t('Claude reads the reply with the request the next time it works on the site.')) ?></span></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Add the reply')) ?></button></p>
</form>
<?php if ($moves !== []): ?>
<form class="vradku" method="post" action="<?= e($module->url('status')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
	<label for="req-status"><?= e(t('Status')) ?></label> <select id="req-status" name="status" required>
		<option value="" selected disabled><?= e(t('— choose —')) ?></option>
<?php foreach ($moves as $key): ?>
		<option value="<?= e($key) ?>"><?= e(t(Requests::STATUSES[$key])) ?></option>
<?php endforeach ?>
	</select> <button class="navigace" type="submit"><?= e(t('Change the status')) ?></button>
	<span class="napoveda"><?= e(t('Done = the drafts are reviewed and published, or nothing more is needed. Declined = it will not be done.')) ?></span>
</form>
<?php endif ?>
</div>
