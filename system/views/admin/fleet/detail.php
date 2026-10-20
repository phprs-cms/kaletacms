<?php
/**
 * One site in the fleet console: what needs attention, its last report, its update ring and the console's events about it.
 *
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var array<string, mixed> $site a row of ka_fleet_sites + score, reasons, beat (the decoded report)
 * @var list<array<string, mixed>> $events
 * @var string $latest
 * @var array<int, string> $customers id => name (3.17)
 */

use Kaleta\Core\AgentSchedules;
use Kaleta\Core\Audit;
use Kaleta\Core\ReportPage;
use Kaleta\Core\Scheduler;
use Kaleta\Fleet\Console;

$reasonLabels = require __DIR__ . '/reasons.php';
$beat = $site['beat'];
$siteAdmin = rtrim((string) $site['url'], '/') . '/admin.php';
$moment = fn (mixed $iso): string => is_string($iso) && ($m = date_create_immutable($iso)) !== false ? format_date($m->setTimezone(new DateTimeZone(date_default_timezone_get())), true) : '–';
// a date only when it is a real one – a site's report must never break this page (N316-5)
$day = fn (mixed $part): string => is_array($part) && ($expires = Console::date($part['expires'] ?? null)) !== null ? format_date($expires) . ' (' . t('%d days', (int) Console::daysLeft($part, time())) . ')'
    : (is_array($part) && !empty($part['error']) ? t('could not be checked') : t('unknown'));
// the site sends machine names; the console shows them in its own language (N316-8)
$counts = fn (array $map, array $labels): string => implode(', ', array_map(fn (int|string $key, mixed $n): string => t($labels[$key] ?? (string) $key) . ': ' . (int) $n, array_keys($map), $map));
$taskLabels = array_map(fn (array $task): string => $task[0], AgentSchedules::TASKS);
$requests = is_array($beat['requests'] ?? null) ? $beat['requests'] : null;
$pending = array_filter(is_array($beat['pending_review'] ?? null) ? $beat['pending_review'] : [], fn (mixed $n): bool => is_int($n) && $n > 0);
$security = is_array($beat['security_7_days'] ?? null) ? $beat['security_7_days'] : null;
$watch = is_array($beat['domain_watch'] ?? null) && empty($beat['domain_watch']['local']) ? $beat['domain_watch'] : null;
$runs = is_array($beat['agent_runs'] ?? null) ? $beat['agent_runs'] : null;
$date = fn (mixed $ts): string => is_int($ts) && $ts > 0 && $ts < 4_102_444_800 ? format_date((new DateTimeImmutable())->setTimestamp($ts), true) : '–';
$yes = fn (mixed $v): string => $v ? t('yes') : t('no');
?>
<p><a href="<?= e($module->url('sites', ['show' => 'all'])) ?>">← <?= e(t('All sites')) ?></a> · <a href="<?= e($module->url('', ['site' => (int) $site['id']])) ?>"><?= e(t('Inbox')) ?></a> · <a href="<?= e((string) $site['url']) ?>" target="_blank" rel="noopener"><?= e((string) $site['url']) ?></a> · <a href="<?= e((string) $site['url'] . '/admin.php') ?>" target="_blank" rel="noopener"><?= e(t('Administration of the site')) ?></a></p>
<p class="hlaska<?= $site['reasons'] === [] ? ' hlaska-ok' : '' ?>"><?php if ($site['reasons'] === []): ?><?= e(t('Nothing needs attention.')) ?><?php else: ?><?= e(t('Needs attention:')) ?> <?= e(implode(', ', array_map(fn (string $r): string => $reasonLabels[$r], $site['reasons']))) ?><?php endif ?></p>
<div class="tab-obal"><table class="vypis"><tbody>
	<tr><th scope="row"><?= e(t('Up')) ?></th><td><?= $site['up'] === null ? e(t('not checked yet')) : ((int) $site['up'] === 1 ? e(t('yes')) : '<span class="stitek stitek-chyba">' . e(t('no')) . '</span>') ?><?= $site['up_checked'] !== null ? ' · ' . e(t('checked %s', format_date(new DateTimeImmutable((string) $site['up_checked']), true))) . ((int) $site['up_status'] > 0 ? ' (HTTP ' . (int) $site['up_status'] . ')' : '') : '' ?></td></tr>
	<tr><th scope="row"><?= e(t('Last report')) ?></th><td><?= $site['last_seen'] !== null ? e(format_date(new DateTimeImmutable((string) $site['last_seen']), true)) : e(t('no report yet')) ?></td></tr>
	<tr><th scope="row"><?= e(t('Version')) ?></th><td><?= e((string) $site['version']) ?><?= ($beat['update_available'] ?? null) ? ' · ' . e(t('%s is available', (string) $beat['update_available'])) : '' ?><?= !empty($beat['php']) ? ' · PHP ' . e((string) $beat['php']) : '' ?></td></tr>
	<?php if (!empty($beat['update_problem'])): ?><tr><th scope="row"><?= e(t('Last update')) ?></th><td><span class="stitek stitek-chyba"><?= e(t('failed')) ?></span> <?= e((string) $beat['update_problem']) ?></td></tr><?php endif ?>
	<tr><th scope="row"><?= e(t('Health')) ?></th><td><?= e(t(['ok' => 'Everything is fine.', 'warning' => 'warnings', 'error' => 'errors', '' => '–'][(string) $site['status']] ?? '–')) ?></td></tr>
	<tr><th scope="row"><?= e(t('Last backup')) ?></th><td><?= e($date($beat['last_backup'] ?? null)) ?> · <?= e(t('off-site copy: %s', $yes($beat['offsite_backup'] ?? false))) ?></td></tr>
	<tr><th scope="row"><?= e(t('Cron last called')) ?></th><td><?= e($date($beat['cron_last_run'] ?? null)) ?></td></tr>
	<?php if (($beat['visits_7_days'] ?? null) !== null): ?><tr><th scope="row"><?= e(t('Visits in 7 days')) ?></th><td><?= (int) $beat['visits_7_days'] ?></td></tr><?php endif ?>
	<?php if (($beat['enquiries_7_days'] ?? null) !== null): ?><tr><th scope="row"><?= e(t('Enquiries')) ?></th><td><?= e(t('%d in 7 days, %d waiting for an answer', (int) $beat['enquiries_7_days'], (int) Console::enquiriesWaiting($beat))) ?></td></tr><?php endif ?>
	<?php if (is_array($beat['audit'] ?? null)): ?><tr><th scope="row"><?= e(t('Site audit')) ?></th><td><?= array_sum(array_map('intval', $beat['audit'])) === 0 ? e(t('no findings')) : e($counts($beat['audit'], Audit::KINDS)) ?></td></tr><?php endif ?>
</tbody></table></div>
<?php if ($requests !== null || $pending !== [] || ($beat['bookings_pending'] ?? null) !== null || $security !== null || $watch !== null || $runs !== null): ?>
<h2><?= e(t('Waiting')) ?></h2>
<div class="tab-obal"><table class="vypis"><tbody>
	<?php if ($requests !== null): ?><tr><th scope="row"><?= e(t('Ask Claude')) ?></th><td><?= e(t('%d new, %d in progress, %d done to review', (int) $requests['new'], (int) $requests['in_progress'], (int) $requests['done_to_review'])) ?><?= ($requests['oldest_waiting_hours'] ?? null) !== null ? ' · ' . e(t('the oldest waits %d hours', (int) $requests['oldest_waiting_hours'])) : '' ?>
	<?php if (is_array($requests['items'] ?? null) && $requests['items'] !== []): ?><ul class="smltxt">
	<?php foreach ($requests['items'] as $item): ?><li><a href="<?= e($siteAdmin . '?module=requests&action=detail&id=' . (int) $item['id']) ?>" target="_blank" rel="noopener"><?= e((string) $item['title']) ?></a> · <?= e(t(Kaleta\Core\Requests::STATUSES[$item['status']] ?? (string) $item['status'])) ?> · <?= e($moment($item['created_at'] ?? null)) ?></li><?php endforeach ?>
	</ul><?php elseif (($requests['items'] ?? null) === null): ?><br><span class="smltxt"><?= e(t('The site does not share request titles.')) ?></span><?php endif ?></td></tr><?php endif ?>
	<?php if ($pending !== []): ?><tr><th scope="row"><?= e(t('Waiting for review')) ?></th><td><?= e(implode(', ', array_map(fn (string $kind, int $n): string => t(Kaleta\Core\PendingReview::KINDS[$kind][0] ?? $kind) . ': ' . $n, array_keys($pending), $pending))) ?></td></tr><?php endif ?>
	<?php if (($beat['bookings_pending'] ?? null) !== null): ?><tr><th scope="row"><?= e(t('Bookings to confirm')) ?></th><td><?= (int) $beat['bookings_pending'] ?></td></tr><?php endif ?>
	<?php if ($security !== null): ?><tr><th scope="row"><?= e(t('Security in 7 days')) ?></th><td><?= e(t('%d failed sign-ins, %d accounts locked now', (int) $security['failed_sign_ins'], (int) $security['locked_accounts'])) ?><?php if (($security['firewall'] ?? []) !== []): ?> · <?= e(t('firewall: %s', $counts($security['firewall'], ReportPage::FIREWALL))) ?><?php endif ?><?php if (($security['events'] ?? []) !== []): ?> · <?= e($counts($security['events'], ReportPage::SECURITY)) ?><?php endif ?></td></tr><?php endif ?>
	<?php if ($watch !== null): ?><tr><th scope="row"><?= e(t('Certificate')) ?></th><td><?= e($day($watch['certificate'] ?? null)) ?></td></tr>
	<tr><th scope="row"><?= e(t('Domain')) ?></th><td><?= e($day($watch['domain'] ?? null)) ?><?php if (is_array($watch['mail'] ?? null)): ?> · <?= e(implode(', ', array_map(fn (string $k): string => strtoupper($k) . ' ' . ($watch['mail'][$k] ? t('found') : t('not found')), array_filter(['spf', 'dmarc', 'dkim'], fn (string $k): bool => is_bool($watch['mail'][$k] ?? null))))) ?><?php endif ?></td></tr><?php endif ?>
	<?php if ($runs !== null): ?><tr><th scope="row"><?= e(t('Claude runs in 7 days')) ?></th><td><?= e($counts((array) ($runs['runs_7_days'] ?? []), AgentSchedules::STATUSES)) ?> · <?= e(t('%d sessions', (int) ($runs['sessions_7_days'] ?? 0))) ?><?= ($runs['next_due'] ?? null) !== null ? ' · ' . e(t('next %s (%s)', $moment($runs['next_due']), t($taskLabels[(string) ($runs['next_task'] ?? '')] ?? (string) ($runs['next_task'] ?? '')))) : '' ?></td></tr><?php endif ?>
	<?php if (($beat['updates']['behind_days'] ?? null) !== null || ($beat['updates']['major_available'] ?? null) !== null): ?><tr><th scope="row"><?= e(t('Updates')) ?></th><td><?= ($beat['updates']['behind_days'] ?? null) !== null ? e(t('a newer version has waited %d days', (int) $beat['updates']['behind_days'])) : '' ?><?= ($beat['updates']['major_available'] ?? null) !== null ? ' ' . e(t('Version %s is available (a new major version).', (string) $beat['updates']['major_available'])) : '' ?></td></tr><?php endif ?>
	<?php if (($beat['report_month'] ?? null) !== null): ?><tr><th scope="row"><?= e(t('Last monthly report')) ?></th><td><?= e((string) $beat['report_month']) ?></td></tr><?php endif ?>
</tbody></table></div>
<?php endif ?>
<?php if (($beat['problems'] ?? []) !== []): ?>
<h2><?= e(t('Problems the site reports')) ?></h2>
<div class="tab-obal"><table class="vypis"><tbody>
<?php foreach (array_filter((array) $beat['problems'], 'is_array') as $p): ?>
	<tr><td class="stred"><span class="stitek stitek-<?= ($p['status'] ?? '') === 'error' ? 'chyba' : 'koncept' ?>"><?= ($p['status'] ?? '') === 'error' ? '✕' : '!' ?></span></td><td><strong><?= e((string) ($p['check'] ?? '')) ?></strong><br><span class="smltxt"><?= e((string) ($p['group'] ?? '')) ?></span></td><td><?= e((string) ($p['detail'] ?? '')) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="smltxt"><?= e(t('The site reports its problems in English; fix them in its administration.')) ?></p>
<?php endif ?>
<?php if (($beat['jobs_failing'] ?? []) !== []): ?><p class="hlaska hlaska-chyba"><?= e(t('Background jobs that fail: %s', implode(', ', array_map(fn (string $job): string => t(Scheduler::JOBS[$job][2] ?? $job), array_filter((array) $beat['jobs_failing'], 'is_string'))))) ?></p><?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('customer')) ?>">
<?= $csrf ?>
<input type="hidden" name="id" value="<?= (int) $site['id'] ?>">
<fieldset>
<legend><?= e(t('Customer and note')) ?></legend>
<div class="radek"><label for="fleet-customer"><?= e(t('Customer')) ?></label><div><input class="textpole" type="text" id="fleet-customer" name="customer" list="fleet-customers" maxlength="150" value="<?= e($customers[(int) $site['customer_id']] ?? '') ?>">
<datalist id="fleet-customers"><?php foreach ($customers as $name): ?><option value="<?= e($name) ?>"><?php endforeach ?></datalist>
<span class="napoveda"><?= e(t('Groups the sites of one client: the inbox and the list of sites filter by it. Empty = no customer.')) ?></span></div></div>
<div class="radek"><label for="fleet-note"><?= e(t('Note')) ?></label><div><textarea class="textpole" id="fleet-note" name="note" rows="3" maxlength="1000"><?= e((string) $site['note']) ?></textarea>
<span class="napoveda"><?= e(t('Only for you: it stays on the console and the site never sees it.')) ?></span></div></div>
<p><button class="tl" type="submit"><?= e(t('Save')) ?></button></p>
</fieldset>
</form>
<form class="formular" method="post" action="<?= e($module->url('ring')) ?>">
<?= $csrf ?>
<input type="hidden" name="id" value="<?= (int) $site['id'] ?>">
<fieldset>
<legend><?= e(t('Updates')) ?></legend>
<?php if ((int) $site['manage_updates'] !== 1): ?>
<p><?= e(t('This site decides about its updates itself (Settings → Fleet console on the site).')) ?></p>
<?php else: ?>
<div class="radek"><label for="ring"><?= e(t('Ring')) ?></label><div><select id="ring" name="ring">
	<option value="normal"<?= $site['ring'] !== 'canary' ? ' selected' : '' ?>><?= e(t('normal – after the test sites ran the version for 48 hours without problems')) ?></option>
	<option value="canary"<?= $site['ring'] === 'canary' ? ' selected' : '' ?>><?= e(t('test site – gets a new version first')) ?></option>
</select><span class="napoveda"><?= e(t('Security releases go to every site at once.')) ?></span></div></div>
<p><button class="tl" type="submit"><?= e(t('Save')) ?></button>
<?php if (version_compare($latest, (string) $site['version'], '>')): ?>
<button class="navigace" type="submit" formaction="<?= e($module->url('allow')) ?>"><?= e(t('Allow version %s now', $latest)) ?></button>
<?php endif ?></p>
<?php endif ?>
</fieldset>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('check')) ?>"><?= e(t('Check now')) ?></button>
<button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('remove')) ?>" data-potvrdit="<?= e(t('Remove the site from the console? Its reports will be refused until it is paired again.')) ?>"><?= e(t('Remove from the console')) ?></button></p>
</form>
<?php if ($events !== []): ?>
<h2><?= e(t('Events')) ?></h2>
<div class="tab-obal"><table class="vypis"><tbody>
<?php foreach ($events as $ev): ?>
	<tr><td><?= e(format_date(new DateTimeImmutable((string) $ev['created_at']), true)) ?></td><td><?= e((string) $ev['message']) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="smltxt"><?= e(t('Paired %s.', format_date(new DateTimeImmutable((string) $site['paired_at']), true))) ?> <?= e(t('Score %d (higher = more urgent).', (int) $site['score'])) ?></p>
