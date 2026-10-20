<?php
/**
 * "Ask Claude" on the dashboard (3.1, Core\AskClaude): one box whose text becomes a request for Claude (Requests), with
 * attachments; example requests that fill the box (admin.js); whether a scheduled run picks requests up; the person's
 * latest requests. "Copy for the Claude app" copies the text with the site's address and opens Claude in a new tab.
 *
 * 3.18: whether a request written now reaches Claude (Core\ClaudeConnection, views/admin/claude_notice.php) – without a
 * connection the box saves for later and never says "Send".
 *
 * @var Kaleta\Core\App $app
 * @var array{connected: bool, status: array<string, mixed>, examples: array<string, string>, recent: list<array{id: int, title: string, status: string, updated_at: string}>, routine: ?array{cadence: string, day: int, time: string, next_due: ?string}, prompt: string, admin: bool} $ask
 */
use Kaleta\Core\Requests;

$requestsUrl = fn (string $action = '', array $params = []): string => $app->url('admin.php?module=requests' . ($action !== '' ? '&action=' . $action : '') . ($params !== [] ? '&' . http_build_query($params) : ''));
?>
<section class="ask-claude" aria-labelledby="ask-claude-nadpis">
	<h2 id="ask-claude-nadpis"><?= e(t('Ask Claude')) ?></h2>
	<p class="smltxt"><?= e(t('Write what you need done on the site, as you would to a colleague. Claude does it as drafts and answers in Requests; a person reviews and publishes.')) ?></p>
<?= $app->view->render('admin/claude_notice', ['app' => $app, 'claude' => $ask['status'], 'routine' => $ask['routine'], 'class' => 'ask-claude-kdy']) ?>
	<form method="post" action="<?= e($requestsUrl('save')) ?>" enctype="multipart/form-data">
		<?= $app->session->csrfField() ?>
		<input type="hidden" name="quick" value="1">
		<input type="hidden" name="from" value="dashboard">
		<label class="navod-skryte" for="ask-claude-text"><?= e(t('What should Claude do?')) ?></label>
		<textarea class="textbox" id="ask-claude-text" name="text" rows="3" required maxlength="<?= Requests::MAX_TEXT ?>" placeholder="<?= e(t('e.g. Add the new opening hours to the contact page')) ?>"></textarea>
		<div class="ask-claude-tlacitka">
			<button class="<?= $ask['connected'] ? 'tl' : 'navigace' ?>" type="submit"><?= e($ask['connected'] ? t('Send to Claude') : t('Save for later')) ?></button>
			<label class="ask-claude-soubory"><span><?= e(t('Attach files')) ?></span> <input type="file" name="prilohy[]" multiple></label>
<?php if ($ask['connected']): ?>
			<a class="navigace" href="https://claude.ai/new" target="_blank" rel="noopener" data-ask-claude-copy data-prompt="<?= e($ask['prompt']) ?>" data-copied="<?= e(t('Copied – paste it into Claude')) ?>"><?= e(t('Copy for the Claude app')) ?></a>
<?php endif ?>
		</div>
	</form>
<?php if ($ask['examples'] !== []): ?>
	<p class="smltxt ask-claude-zkuste"><?= e($ask['connected'] ? t('For example – click one, change it and send:') : t('For example – click one, change it and save:')) ?></p>
	<ul class="ask-claude-priklady">
<?php foreach ($ask['examples'] as $key => $text): ?>
		<li><button type="button" class="ask-claude-priklad" data-ask-claude-example="<?= e($key) ?>"><?= e(t($text)) ?></button></li>
<?php endforeach ?>
	</ul>
<?php endif ?>
<?php if ($ask['recent'] !== []): ?>
	<h3><?= e(t('Your requests')) ?></h3>
	<ul class="ask-claude-moje">
<?php foreach ($ask['recent'] as $r): ?>
		<li><a href="<?= e($requestsUrl('detail', ['id' => $r['id']])) ?>"><?= e($r['title']) ?></a>
			<span class="stitek<?= match ($r['status']) { 'done' => ' stitek-vydano', 'new' => ' stitek-koncept', 'declined' => ' stitek-chyba', default => '' } ?>"><?= e(t(Requests::STATUSES[$r['status']] ?? $r['status'])) ?></span>
			<span class="smltxt"><?= e(format_date($r['updated_at'], true)) ?></span></li>
<?php endforeach ?>
	</ul>
	<p class="smltxt"><a href="<?= e($requestsUrl()) ?>"><?= e(t('All requests')) ?></a></p>
<?php endif ?>
</section>
