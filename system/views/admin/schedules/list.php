<?php
/**
 * Scheduled runs (2.17): the schedules with the next due time and the last run, and the "Set up in Claude" panel – the
 * routine prompt to copy and the advice to connect with a drafts-only token.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Schedules $module
 * @var string $csrf
 * @var list<array<string, mixed>> $schedules with last_status, last_finished, last_summary
 * @var string $prompt the routine prompt with the site's MCP address
 * @var bool $claudeOn the Claude connection extension is on
 * @var int $draftTokens how many drafts-only tokens or connections exist
 */
use Kaleta\Core\AgentSchedules;

$tag = fn (?string $status): string => $status === null ? '<span class="smltxt">' . e(t('no run yet')) . '</span>'
    : '<span class="stitek' . match ($status) { 'ok' => ' stitek-vydano', 'running' => ' stitek-koncept', 'failed', 'missed' => ' stitek-chyba', default => '' } . '">' . e(t(AgentSchedules::STATUSES[$status] ?? $status)) . '</span>';
$when = fn (array $s): string => match ((string) $s['cadence']) {
    'weekly' => t('every %s at %s', t(AgentSchedules::WEEKDAYS[(int) $s['day']] ?? ''), (string) $s['time']),
    'monthly' => t('every month on day %d at %s', (int) $s['day'], (string) $s['time']),
    default => t('every day at %s', (string) $s['time']),
};
?>
<p class="hlaska"><?= e(t('The site cannot run Claude by itself. It keeps the schedules below, tells a routine in Claude what is due, records each run and notices runs nobody picked up. The run itself happens in Claude – a scheduled task in the Claude app or a Claude Code routine – connected to this site with drafts-only access, so a run never publishes, deletes or sends anything.')) ?></p>
<?php if (!$claudeOn): ?>
<p class="hlaska chyba"><?= e(t('The Claude connection is switched off (Features) – the schedules are kept, but no routine can pick the runs up until it is on.')) ?></p>
<?php endif ?>
<p><a class="tl" href="<?= e($module->url('edit')) ?>"><?= e(t('New schedule')) ?></a></p>
<?php if ($schedules === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'protokol', 'heading' => t('No schedules yet.'), 'text' => t('Typical ones: a site review every Monday, a report on the first of the month, a triage of new enquiries every morning, the open requests from staff every day.'), 'action' => [$module->url('edit'), t('New schedule')]]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Schedule')) ?></th><th scope="col"><?= e(t('When')) ?></th><th scope="col"><?= e(t('Next due')) ?></th><th scope="col"><?= e(t('Last run')) ?></th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($schedules as $s): ?>
<tr<?= (int) $s['active'] === 1 ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($module->url('edit', ['id' => (int) $s['id']])) ?>"><strong><?= e((string) $s['name']) ?></strong></a><?= (int) $s['active'] === 1 ? '' : ' <span class="stitek">' . e(t('paused')) . '</span>' ?><br><span class="smltxt"><?= e(t(AgentSchedules::TASKS[(string) $s['task']][0] ?? (string) $s['task'])) ?></span></td>
	<td><?= e($when($s)) ?></td>
	<td><?= (int) $s['active'] === 1 && $s['next_due'] !== null ? e(format_date((string) $s['next_due'], true)) : '—' ?></td>
	<td><?= $tag($s['last_status'] !== null ? (string) $s['last_status'] : null) ?><?= $s['last_finished'] !== null ? ' <span class="smltxt">' . e(format_date((string) $s['last_finished'], true)) . '</span>' : '' ?><?= $s['last_summary'] !== null && $s['last_summary'] !== '' ? '<br><span class="smltxt">' . e(mb_strimwidth((string) $s['last_summary'], 0, 140, '…')) . '</span>' : '' ?></td>
	<td class="stred"><a class="navigace" href="<?= e($module->url('history', ['id' => (int) $s['id']])) ?>"><?= e(t('History')) ?></a>
		<form class="vradku" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>"><button class="navigace" type="submit"><?= e((int) $s['active'] === 1 ? t('Pause') : t('Resume')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<h2 id="claude"><?= e(t('Set up in Claude')) ?></h2>
<div class="formular">
<ol>
	<li><?= $module->hint(t('Connect Claude to this site with a drafts-only token or connection: My account → Claude connection, access “drafts only”. A drafts-only connection can read the site and save drafts, and the two tools the routine needs (get_due_agent_runs, report_agent_run) – it cannot publish, delete or send.')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a><?php if ($draftTokens === 0): ?> <span class="stitek"><?= e(t('no drafts-only token yet')) ?></span><?php endif ?></li>
	<li><?= e(t('In Claude, create a scheduled task (the Claude app) or a routine (Claude Code) that runs a little after the earliest time in your schedules – for example every day at 07:15 – with this prompt:')) ?>
		<pre class="kod" id="routine-prompt"><?= e($prompt) ?></pre>
		<p><button class="navigace" type="button" data-kopirovat="#routine-prompt"><?= e(t('Copy')) ?></button></p></li>
	<li><?= e(t('The routine asks the site what is due, does each run as drafts and reports it; the summary and the links to the drafts appear in the history here. You review and publish. A run nobody picks up within 6 hours is marked missed and reported in the events and the alert e-mails.')) ?></li>
</ol>
<p class="smltxt"><?= e(t('Claude never runs on the site: the site holds no Claude sign-in and creates no tokens here – the routine lives in your Claude account and connects with the token you give it.')) ?></p>
</div>
