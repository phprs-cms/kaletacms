<?php
/**
 * "Ask Claude" on the dashboard (3.1, Core\AskClaude): one box whose text becomes a request for Claude (Requests), with
 * attachments; example requests that fill the box (admin.js); whether a scheduled run picks requests up; the person's
 * latest requests. "Copy for the Claude app" copies the text with the site's address and opens Claude in a new tab.
 *
 * @var Kaleta\Core\App $app
 * @var array{connected: bool, examples: array<string, string>, recent: list<array{id: int, title: string, status: string, updated_at: string}>, routine: ?array{cadence: string, day: int, time: string, next_due: ?string}, prompt: string, admin: bool} $ask
 */
use Kaleta\Core\AgentSchedules;
use Kaleta\Core\Requests;

$requestsUrl = fn (string $action = '', array $params = []): string => $app->url('admin.php?module=requests' . ($action !== '' ? '&action=' . $action : '') . ($params !== [] ? '&' . http_build_query($params) : ''));
$routine = $ask['routine'];
$when = $routine === null ? '' : match ($routine['cadence']) {
    'weekly' => t('every %s at %s', t(AgentSchedules::WEEKDAYS[$routine['day']] ?? 'Monday'), $routine['time']),
    'monthly' => t('on day %d of every month at %s', $routine['day'], $routine['time']),
    default => t('every day at %s', $routine['time']),
};
?>
<section class="ask-claude" aria-labelledby="ask-claude-nadpis">
	<h2 id="ask-claude-nadpis"><?= e(t('Ask Claude')) ?></h2>
	<p class="smltxt"><?= e(t('Write what you need done on the site, as you would to a colleague. Claude does it as drafts and answers in Requests; a person reviews and publishes.')) ?></p>
	<form method="post" action="<?= e($requestsUrl('save')) ?>" enctype="multipart/form-data">
		<?= $app->session->csrfField() ?>
		<input type="hidden" name="quick" value="1">
		<input type="hidden" name="from" value="dashboard">
		<label class="navod-skryte" for="ask-claude-text"><?= e(t('What should Claude do?')) ?></label>
		<textarea class="textbox" id="ask-claude-text" name="text" rows="3" required maxlength="<?= Requests::MAX_TEXT ?>" placeholder="<?= e(t('e.g. Add the new opening hours to the contact page')) ?>"></textarea>
		<div class="ask-claude-tlacitka">
			<input class="tl" type="submit" value="<?= e($ask['connected'] ? t('Send to Claude') : t('Save the request')) ?>">
			<label class="ask-claude-soubory"><span><?= e(t('Attach files')) ?></span> <input type="file" name="prilohy[]" multiple></label>
<?php if ($ask['connected']): ?>
			<a class="navigace" href="https://claude.ai/new" target="_blank" rel="noopener" data-ask-claude-copy data-prompt="<?= e($ask['prompt']) ?>" data-copied="<?= e(t('Copied – paste it into Claude')) ?>"><?= e(t('Copy for the Claude app')) ?></a>
<?php endif ?>
		</div>
	</form>
<?php if (!$ask['connected']): ?>
	<p class="hlaska ask-claude-nepripojeno"><?= e($ask['admin'] ? t('Claude is not connected to this site yet, so requests wait until it is.') : t('Claude is not connected to this site yet – requests wait until an administrator connects it.')) ?><?php if ($ask['admin']): ?> <a href="#pripojit-claude"><?= e(t('Connect Claude')) ?></a><?php endif ?></p>
<?php endif ?>
<?php if ($ask['examples'] !== []): ?>
	<p class="smltxt ask-claude-zkuste"><?= e($ask['connected'] ? t('For example – click one, change it and send:') : t('For example – click one, change it and save:')) ?></p>
	<ul class="ask-claude-priklady">
<?php foreach ($ask['examples'] as $key => $text): ?>
		<li><button type="button" class="ask-claude-priklad" data-ask-claude-example="<?= e($key) ?>"><?= e(t($text)) ?></button></li>
<?php endforeach ?>
	</ul>
<?php endif ?>
	<p class="smltxt ask-claude-kdy">
<?php if ($routine !== null): ?>
		<?= e(t('A scheduled run works through the requests %s.', $when)) ?><?php if ($routine['next_due'] !== null): ?> <?= e(t('Next: %s.', format_date($routine['next_due'], true))) ?><?php endif ?>
<?php elseif ($ask['admin']): ?>
		<?= e(t('No scheduled run works through the requests yet – Claude reads them when someone asks it to.')) ?> <a href="<?= e($app->url('admin.php?module=schedules')) ?>"><?= e(t('Schedule a run')) ?></a>
<?php else: ?>
		<?= e(t('Claude reads the requests the next time it works on the site.')) ?>
<?php endif ?>
	</p>
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
