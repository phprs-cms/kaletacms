<?php
/**
 * The rollout of the fleet console (3.30, Fleet\Rollout): where the newest release has reached in each ring – updated,
 * may update now, waiting (until when), held back by the pilot sites, failed, decides itself – each site with its ring
 * and window, the delay of each ring and the maintenance window of each customer. 3.31.1: when sites are held back, a
 * note says how the rollout goes on (Today links here after 3 days).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $csrf
 * @var array{version: string, security: bool, first_seen: int, sites: list<array<string, mixed>>, rings: array<string, array<string, int>>, next: array<string, ?int>} $overview
 * @var array{pilot: int, normal: int, careful: int} $delays
 * @var list<array{id: int, name: string, sites: int}> $customers
 * @var array<int, string> $windows customer id => window
 * @var array<int, string> $names customer id => name
 * @var string $timeZone
 * @var int $now
 */
use Kaleta\Fleet\Rollout;

$ringLabel = fn (string $ring): string => t(Rollout::RING_LABELS[$ring]);
$stateLabel = fn (string $state): string => t(Rollout::STATE_LABELS[$state]);
$chip = ['updated' => 'ok', 'allowed' => 'ok', 'waiting' => 'unknown', 'blocked' => 'warn', 'failed' => 'bad', 'self' => 'unknown'];
$moment = fn (?int $ts): string => $ts !== null ? format_date((new DateTimeImmutable())->setTimestamp($ts), true) : '–';
$hours = fn (int $h): string => $h % 24 === 0 && $h > 0 ? t('%d days', intdiv($h, 24)) : t('%d h', $h);
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'rollout']) ?>
<p class="napoveda"><?= e(t('Each site asks the console every hour whether it may install the newest version. The console answers after the delay of the site\'s ring and only inside its maintenance window. Sites that decide about their updates themselves are only shown.')) ?></p>
<p class="hlaska<?= $overview['security'] ? ' hlaska-chyba' : '' ?>"><?= e(t('Newest version: %s', $overview['version'])) ?><?= $overview['first_seen'] > 0 ? ' · ' . e(t('the console has known it since %s', $moment($overview['first_seen']))) : '' ?>
<?php if ($overview['security']): ?><br><strong><?= e(t('A security release: every site may install it at once, whatever its ring and window.')) ?></strong><?php endif ?></p>
<?php if ($overview['sites'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No site reports to this console yet. Make a pairing key and paste it on the site.')]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis vypis-karty flotila-okruhy">
<caption class="navod-skryte"><?= e(t('Where version %s has reached, by ring', $overview['version'])) ?></caption>
<thead><tr><th scope="col"><?= e(t('Ring')) ?></th><th scope="col" class="cislo"><?= e(t('Delay')) ?></th>
<?php foreach (Rollout::STATES as $state): ?><th scope="col" class="cislo"><?= e($stateLabel($state)) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach (Rollout::RINGS as $ring): ?>
<tr>
	<th scope="row"><?= e($ringLabel($ring)) ?></th>
	<td class="cislo" data-popisek="<?= e(t('Delay')) ?>"><?= e($hours($delays[$ring])) ?></td>
<?php foreach (Rollout::STATES as $state): $n = $overview['rings'][$ring][$state]; ?>
	<td class="cislo" data-popisek="<?= e($stateLabel($state)) ?>"><?= $n ?><?php if ($state === 'waiting' && $overview['next'][$ring] !== null): ?><br><span class="smltxt"><?= e(t('from %s', $moment($overview['next'][$ring]))) ?></span><?php endif ?></td>
<?php endforeach ?>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php if (array_sum(array_column($overview['rings'], 'blocked')) > 0): /* 3.31.1: how a held rollout goes on (Today links here) */ ?>
<p class="napoveda" data-rollout-held><?= e(t('Held back: a pilot site does not run the new version without errors, outages or a failed update yet. Fix the pilot site and the rollout goes on by itself, or allow the version by hand on a site\'s page.')) ?></p>
<?php endif ?>
<h2><?= e(t('Sites')) ?></h2>
<div class="tab-obal"><table class="vypis vypis-karty flotila-rollout">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('Ring')) ?></th><th scope="col"><?= e(t('Version')) ?></th><th scope="col"><?= e(t('State')) ?></th><th scope="col"><?= e(t('Maintenance window')) ?></th></tr></thead>
<tbody>
<?php foreach ($overview['sites'] as $r): ?>
<tr>
	<th scope="row"><a href="<?= e($module->url('detail', ['id' => $r['site_id']])) ?>"><?= e($r['site']) ?></a><?php if ($r['customer_id'] !== null && isset($names[$r['customer_id']])): ?><br><span class="smltxt"><?= e($names[$r['customer_id']]) ?></span><?php endif ?></th>
	<td data-popisek="<?= e(t('Ring')) ?>"><?= e($ringLabel($r['ring'])) ?></td>
	<td data-popisek="<?= e(t('Version')) ?>"><?= e($r['version'] !== '' ? $r['version'] : '–') ?></td>
	<td data-popisek="<?= e(t('State')) ?>"><span class="flotila-stav flotila-stav-<?= e($chip[$r['state']]) ?>"><?= e($stateLabel($r['state'])) ?></span><?php if ($r['from'] !== null): ?><br><span class="smltxt"><?= e(t('from %s', $moment($r['from']))) ?></span><?php endif ?></td>
	<td data-popisek="<?= e(t('Maintenance window')) ?>"><?= e(Rollout::describe($r['window'])) ?><?= $r['own_window'] ? ' <span class="smltxt">(' . e(t('the site\'s own')) . ')</span>' : '' ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('rollout_save')) ?>">
<?= $csrf ?>
<fieldset>
<legend><?= e(t('Delay of each ring')) ?></legend>
<p class="napoveda"><?= e(t('Hours after the console first sees a new version. Normal and careful sites also wait until every pilot site runs it without errors, outages or a failed update. Change a site\'s ring on its page.')) ?></p>
<?php foreach (Rollout::SETTINGS as $ring => $key): ?>
<div class="radek"><label for="<?= e($key) ?>"><?= e($ringLabel($ring)) ?></label><div><input class="textpole" type="number" id="<?= e($key) ?>" name="<?= e($key) ?>" min="0" max="<?= Rollout::MAX_HOURS ?>" step="1" value="<?= (int) $delays[$ring] ?>"> <?= e(t('hours')) ?></div></div>
<?php endforeach ?>
<p><button class="tl" type="submit"><?= e(t('Save')) ?></button></p>
</fieldset>
</form>
<h2><?= e(t('Maintenance windows of the customers')) ?></h2>
<p class="napoveda"><?= e(t('When the sites of a customer may install a new version, in the console\'s time zone (%s). A range past midnight belongs to the day it starts (Monday 22:00–05:00 ends on Tuesday morning); no day ticked = any time. A site can have its own window on its page.', $timeZone)) ?></p>
<?php if ($customers === []): ?>
<p><?= e(t('No customers yet.')) ?> <a href="<?= e($module->url('customers')) ?>"><?= e(t('Customers')) ?></a></p>
<?php else: ?>
<?php foreach ($customers as $c): $w = Rollout::parseWindow($windows[$c['id']] ?? ''); ?>
<form class="formular flotila-okno" method="post" action="<?= e($module->url('window')) ?>">
<?= $csrf ?>
<input type="hidden" name="customer" value="<?= (int) $c['id'] ?>">
<fieldset>
<legend><?= e($c['name']) ?> · <?= e(Rollout::describe($windows[$c['id']] ?? '')) ?></legend>
<?= $app->view->render('admin/fleet/window', ['window' => $w, 'prefix' => 'customer-' . (int) $c['id']]) ?>
<p><button class="navigace" type="submit"><?= e(t('Save')) ?><span class="navod-skryte"> – <?= e($c['name']) ?></span></button></p>
</fieldset>
</form>
<?php endforeach ?>
<?php endif ?>
<p class="smltxt"><?= e(t('The rule for security releases: they go to every site at once, whatever its ring and window, and a site with automatic security updates installs one itself. A version allowed by hand on a site\'s page also goes at once.')) ?></p>
