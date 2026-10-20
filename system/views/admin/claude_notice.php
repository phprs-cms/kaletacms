<?php
/**
 * Whether a request written now reaches Claude (3.18, Core\ClaudeConnection) – above every Ask Claude entry point: the
 * box on the dashboard, a new request, the Requests list, "Ask Claude to translate it", a blueprint for Claude.
 *  - off / none: a warning – the request waits until someone connects Claude (a link for whoever may connect it);
 *  - stalled: a warning – the scheduled run that picks requests up does not run;
 *  - manual: Claude reads requests when someone asks it in the Claude app (the last call), a scheduled run would do it
 *    by itself;
 *  - automatic: with $routine the line about the scheduled run, otherwise nothing.
 *
 * @var Kaleta\Core\App $app
 * @var array{state: string, last_call: ?string, pickup: ?string, stalled_since: ?string} $claude
 * @var array{cadence: string, day: int, time: string, next_due: ?string}|null $routine the scheduled run (AskClaude::routine) – only where the line is wanted
 * @var string $class an extra class of the paragraph
 */
use Kaleta\Core\AgentSchedules;

$routine ??= null;
$class = isset($class) && $class !== '' ? ' ' . $class : '';
$auth = $app->auth();
$link = fn (string $module, string $text, string $fragment = ''): string => $auth->hasModule($module)
    ? ' <a href="' . e($app->url('admin.php?module=' . $module) . $fragment) . '">' . e($text) . '</a>' : '';
?>
<?php if ($claude['state'] === 'off'): ?>
<p class="hlaska hlaska-varovani claude-upozorneni<?= $class ?>" role="status"><?= e(t('The Claude connection is switched off (Features) – requests are saved, but nobody reads them until it is on.')) ?><?= $link('extensions', t('Features')) ?></p>
<?php elseif ($claude['state'] === 'none'): ?>
<p class="hlaska hlaska-varovani claude-upozorneni<?= $class ?>" role="status"><?= e(t('Claude is not connected to this site. A request saved now waits until someone connects Claude.')) ?><?= $auth->hasModule('claude_settings') ? $link('claude_settings', t('Connect Claude')) : ' ' . e(t('Ask an administrator to connect Claude.')) ?></p>
<?php elseif ($claude['pickup'] === 'stalled'): ?>
<p class="hlaska hlaska-varovani claude-upozorneni<?= $class ?>" role="status"><?= e(t('The scheduled run that picks up requests is not running (last missed or failed %s). Requests wait until it runs again or someone asks Claude in the Claude app.', $claude['stalled_since'] !== null ? format_date($claude['stalled_since'], true) : '–')) ?><?= $link('schedules', t('Open Scheduled runs')) ?></p>
<?php elseif ($claude['pickup'] === 'manual'): ?>
<p class="hlaska claude-upozorneni<?= $class ?>"><?= e($claude['last_call'] !== null ? t('Claude picks requests up when someone asks it in the Claude app (last call %s).', format_date($claude['last_call'], true)) : t('Claude picks requests up when someone asks it in the Claude app (it has not called the site yet).')) ?><?php if ($auth->hasModule('schedules')): ?> <?= e(t('To have them picked up automatically, set up a scheduled run.')) ?><?= $link('schedules', t('Scheduled runs')) ?><?php endif ?></p>
<?php elseif ($routine !== null): ?>
<p class="smltxt<?= $class ?>"><?= e(t('A scheduled run works through the requests %s.', match ($routine['cadence']) {
    'weekly' => t('every %s at %s', t(AgentSchedules::WEEKDAYS[$routine['day']] ?? 'Monday'), $routine['time']),
    'monthly' => t('on day %d of every month at %s', $routine['day'], $routine['time']),
    default => t('every day at %s', $routine['time']),
})) ?><?php if ($routine['next_due'] !== null): ?> <?= e(t('Next: %s.', format_date($routine['next_due'], true))) ?><?php endif ?></p>
<?php endif ?>
