<?php
/**
 * The expiry calendar of the fleet console (3.30, Fleet\Calendar): a month of certificate and domain dates (a chip per
 * date – a symbol and text, never the colour alone), the list of the dates from today on, the recovery keys of the
 * off-site copies, the reminders (days before) and the administrator's own calendar link (.ics, shown once, revocable).
 * On a phone the month is a list of the days that have a date.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $csrf
 * @var array{month: string, previous: string, next: string, weeks: list<list<array<string, mixed>>>} $month
 * @var list<array<string, mixed>> $events Calendar::dates, narrowed by customer
 * @var list<array<string, mixed>> $keys Calendar::keys
 * @var array<int, string> $customers
 * @var int $customer
 * @var array<string, list<int>> $reminders kind => days before (Calendar::remindersByKind, 3.31.1)
 * @var int $now
 * @var int $siteCount
 * @var string $feedLink the calendar link made just now ('' = none to show)
 * @var bool $hasFeed
 * @var bool $alertsOn
 * @var list<string> $recipients
 */
use Kaleta\Fleet\Calendar;
use Kaleta\Fleet\Health;

$states = array_map(fn (string $label): string => t($label), Health::STATE_LABELS);
$kindShort = ['certificate' => t('TLS'), 'domain' => t('Domain')];
$kindLabel = ['certificate' => t('Certificate'), 'domain' => t('Domain registration')];
$weekdays = [t('Mon'), t('Tue'), t('Wed'), t('Thu'), t('Fri'), t('Sat'), t('Sun')];
$upcoming = array_values(array_filter($events, fn (array $e): bool => $e['days'] >= -30));
$counts = ['bad' => 0, 'warn' => 0];
foreach ($upcoming as $e) {
    if (isset($counts[$e['state']])) {
        $counts[$e['state']]++;
    }
}
$filter = $customer !== 0 ? ['customer' => $customer] : [];
$monthName = Kaleta\Core\ReportPage::monthName($month['month']);
$left = fn (int $days): string => $days < 0 ? t('ended %d days ago', -$days) : ($days === 0 ? t('today') : t('in %d days', $days));
$keyText = fn (array $k): string => match ($k['state']) {
    'bad' => t('Off-site copies wait – the recovery key is not saved'),
    'warn' => $k['key'] === 'none' ? t('No recovery key yet') : t('The recovery key is not saved'),
    'ok' => $k['since'] !== null ? t('Saved on %s (%d days ago)', format_date($k['since']), (int) $k['days']) : t('Saved'),
    default => t('Update the site to see'),
};
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'calendar']) ?>
<?php if ($feedLink !== ''): ?>
<div class="hlaska hlaska-ok"><p><?= e(t('Your calendar link – add it to your calendar app as a subscription. It is shown only now; anyone with it sees the dates, so keep it to yourself. A new link or Revoke stops this one.')) ?></p>
<p><input class="textpole flotila-odkaz" type="text" readonly value="<?= e($feedLink) ?>" aria-label="<?= e(t('Your calendar link')) ?>"></p></div>
<?php endif ?>
<?php if ($siteCount === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No site reports to this console yet. Make a pairing key and paste it on the site.')]) ?>
<?php else: ?>
<ul class="flotila-souhrn" aria-label="<?= e(t('Summary of the dates')) ?>">
	<li class="flotila-souhrn-bad"><strong><?= $counts['bad'] ?></strong> <?= e(t('need action')) ?></li>
	<li class="flotila-souhrn-warn"><strong><?= $counts['warn'] ?></strong> <?= e(t('warnings')) ?></li>
	<li><strong><?= count($upcoming) ?></strong> <?= e(t('dates known')) ?></li>
</ul>
<nav class="flotila-mesice" aria-label="<?= e(t('Month')) ?>">
	<a class="navigace" href="<?= e($module->url('calendar', ['month' => $month['previous']] + $filter)) ?>" rel="prev">← <span class="navod-skryte"><?= e(t('Previous month')) ?></span></a>
	<h2><?= e($monthName) ?></h2>
	<a class="navigace" href="<?= e($module->url('calendar', ['month' => $month['next']] + $filter)) ?>" rel="next"><span class="navod-skryte"><?= e(t('Next month')) ?></span> →</a>
<?php if ($customers !== []): ?>
	<form class="hromadne" method="get" action="<?= e($app->url('admin.php')) ?>">
		<input type="hidden" name="module" value="fleet"><input type="hidden" name="action" value="calendar"><input type="hidden" name="month" value="<?= e($month['month']) ?>">
		<label><?= e(t('Customer')) ?> <select name="customer"><option value="0"><?= e(t('all')) ?></option><option value="-1"<?= $customer === -1 ? ' selected' : '' ?>><?= e(t('without a customer')) ?></option>
<?php foreach ($customers as $id => $name): ?>
			<option value="<?= (int) $id ?>"<?= $customer === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
		</select></label>
		<button class="navigace" type="submit"><?= e(t('Show')) ?></button>
	</form>
<?php endif ?>
</nav>
<div class="tab-obal"><table class="flotila-kalendar">
<caption class="navod-skryte"><?= e(t('Certificate and domain dates in %s', $monthName)) ?></caption>
<thead><tr><?php foreach ($weekdays as $name): ?><th scope="col"><?= e($name) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach ($month['weeks'] as $week): ?>
<tr>
<?php foreach ($week as $day): ?>
	<td class="<?= $day['in_month'] ? '' : 'flotila-mimo' ?><?= $day['today'] ? ' flotila-dnes-den' : '' ?><?= $day['events'] !== [] ? ' flotila-ma' : '' ?>"<?= $day['today'] ? ' aria-current="date"' : '' ?>>
		<span class="flotila-den"><span class="navod-skryte"><?= e(format_date($day['date'])) ?></span><span aria-hidden="true"><?= $day['day'] ?></span></span>
<?php if ($day['events'] !== []): ?>
		<ul>
<?php foreach ($day['events'] as $ev): ?>
			<li><a class="flotila-stav flotila-stav-<?= e($ev['state']) ?>" href="<?= e($module->url('detail', ['id' => $ev['site_id']])) ?>" title="<?= e(Calendar::line($ev)) ?>"><span aria-hidden="true"><?= Health::SYMBOLS[$ev['state']] ?></span> <span aria-hidden="true"><?= e($kindShort[$ev['kind']]) ?></span> <span class="flotila-kal-web"><?= e($ev['site']) ?></span><span class="navod-skryte"> – <?= e($kindLabel[$ev['kind']]) ?>, <?= e($states[$ev['state']]) ?></span></a></li>
<?php endforeach ?>
		</ul>
<?php endif ?>
	</td>
<?php endforeach ?>
</tr>
<?php endforeach ?>
</tbody></table></div>
<h2><?= e(t('Upcoming dates')) ?></h2>
<?php if ($upcoming === []): ?>
<p><?= e(t('No site has reported a certificate or domain date yet. The sites check them once a day (Kaleta 3.16 and later; not on a local address).')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis vypis-karty flotila-terminy">
<thead><tr><th scope="col"><?= e(t('Date')) ?></th><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('What')) ?></th><th scope="col"><?= e(t('Severity')) ?></th><th scope="col"><span class="navod-skryte"><?= e(t('Open')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($upcoming as $ev): ?>
<tr class="flotila-<?= e($ev['state']) ?>">
	<td><strong><?= e(format_date($ev['date'])) ?></strong><br><span class="smltxt"><?= e($left($ev['days'])) ?></span></td>
	<td data-popisek="<?= e(t('Site')) ?>"><a href="<?= e($module->url('detail', ['id' => $ev['site_id']])) ?>"><?= e($ev['site']) ?></a><?php if ($ev['customer_id'] !== null && isset($customers[$ev['customer_id']])): ?><br><span class="smltxt"><?= e($customers[$ev['customer_id']]) ?></span><?php endif ?></td>
	<td data-popisek="<?= e(t('What')) ?>"><?= e($kindLabel[$ev['kind']]) ?></td>
	<td data-popisek="<?= e(t('Severity')) ?>"><span class="flotila-stav flotila-stav-<?= e($ev['state']) ?>"><span aria-hidden="true"><?= Health::SYMBOLS[$ev['state']] ?></span> <?= e($states[$ev['state']]) ?></span></td>
	<td data-popisek="<?= e(t('Open')) ?>" class="akce"><?php if ($ev['link'] !== ''): ?><a class="navigace" href="<?= e($ev['link']) ?>" target="_blank" rel="noopener"><?= e(t('Open')) ?><span class="navod-skryte"> – <?= e($kindLabel[$ev['kind']]) ?>, <?= e($ev['site']) ?></span></a><?php else: ?>–<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<h2><?= e(t('Recovery keys')) ?></h2>
<?php if ($keys === []): ?>
<p><?= e(t('No site makes encrypted off-site copies yet.')) ?></p>
<?php else: ?>
<p class="napoveda"><?= e(t('Encrypted off-site copies wait until an administrator of the site confirms the recovery key is saved. The key has no end date – this says only whether and when it was saved.')) ?></p>
<div class="tab-obal"><table class="vypis vypis-karty flotila-klice">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('Recovery key')) ?></th><th scope="col"><span class="navod-skryte"><?= e(t('Open')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($keys as $k): ?>
<tr>
	<td><a href="<?= e($module->url('detail', ['id' => $k['site_id']])) ?>"><?= e($k['site']) ?></a></td>
	<td data-popisek="<?= e(t('Recovery key')) ?>"><span class="flotila-stav flotila-stav-<?= e($k['state']) ?>"><span aria-hidden="true"><?= Health::SYMBOLS[$k['state']] ?></span><span class="navod-skryte"><?= e($states[$k['state']]) ?></span></span> <?= e($keyText($k)) ?></td>
	<td data-popisek="<?= e(t('Open')) ?>" class="akce"><?php if ($k['link'] !== '' && $k['state'] !== 'ok'): ?><a class="navigace" href="<?= e($k['link']) ?>" target="_blank" rel="noopener"><?= e(t('Open')) ?><span class="navod-skryte"> – <?= e(t('Recovery key')) ?>, <?= e($k['site']) ?></span></a><?php else: ?>–<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('calendar_save')) ?>">
<?= $csrf ?>
<fieldset>
<legend><?= e(t('Reminders')) ?></legend>
<div class="radek"><label for="fleet-reminders-certificate"><?= e(t('Certificates – days before')) ?></label><div><input class="textpole" type="text" id="fleet-reminders-certificate" name="<?= e(Calendar::REMINDER_SETTINGS['certificate']) ?>" value="<?= e(implode(', ', $reminders['certificate'])) ?>" inputmode="numeric" maxlength="40" size="12">
<span class="napoveda"><?= e(t('For example 14, 7: each certificate date is reminded once 14 days and once 7 days before. Certificates usually renew themselves about 30 days before they end. Empty = no reminders.')) ?></span></div></div>
<div class="radek"><label for="fleet-reminders"><?= e(t('Domain registrations – days before')) ?></label><div><input class="textpole" type="text" id="fleet-reminders" name="<?= e(Calendar::REMINDER_SETTINGS['domain']) ?>" value="<?= e(implode(', ', $reminders['domain'])) ?>" inputmode="numeric" maxlength="40" size="12">
<span class="napoveda"><?= e(t('For example 30, 7: each domain registration date is reminded once 30 days and once 7 days before. Empty = no reminders.')) ?></span></div></div>
<p class="smltxt"><?= $alertsOn && $recipients !== [] ? e(t('Every administrator gets a notification; an e-mail goes to %s (the recipients of Alerts and digest).', implode(', ', $recipients)))
    : e(t('Every administrator gets a notification. E-mail: the alert e-mails are off or have no address (Alerts and digest).')) ?></p>
<p><button class="tl" type="submit"><?= e(t('Save')) ?></button></p>
</fieldset>
</form>
<form class="formular" method="post" action="<?= e($module->url('calendar_feed')) ?>">
<?= $csrf ?>
<fieldset>
<legend><?= e(t('Calendar link')) ?></legend>
<p><?= e(t('Your own link to these dates for a calendar app (iCalendar). Each administrator has their own; it can be revoked at any time.')) ?></p>
<p><button class="navigace" type="submit" name="feed" value="new"><?= e($hasFeed ? t('Make a new link') : t('Make a calendar link')) ?></button>
<?php if ($hasFeed): ?>
<button class="navigace nebezpecne" type="submit" name="feed" value="revoke" data-potvrdit="<?= e(t('Revoke your calendar link? Calendar apps that use it get nothing from now on.')) ?>"><?= e(t('Revoke')) ?></button>
<?php endif ?></p>
</fieldset>
</form>
