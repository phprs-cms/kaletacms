<?php
/**
 * The sites of the fleet console: all paired sites, the ones that need attention first (Fleet\Console::overview); since
 * 3.17 with their customer, how much waits on each (Fleet\Inbox) and giving the ticked sites a customer.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $csrf
 * @var list<array<string, mixed>> $sites
 * @var int $total
 * @var int $needing
 * @var string $show attention | all
 * @var string $query
 * @var array<int, string> $customers id => name
 * @var int $customer the customer shown (0 = all)
 * @var array<int, int> $waiting site id => things waiting
 * @var string $latest the newest version the console knows
 * @var string $pairingKey a pairing key made just now (shown once)
 */
use Kaleta\Fleet\Console;

$reasonLabels = require __DIR__ . '/reasons.php';
$severity = fn (string $r): string => Console::REASONS[$r] >= Console::REASONS['errors'] ? 'chyba' : (Console::REASONS[$r] >= Console::REASONS['warnings'] ? 'koncept' : '');
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'sites', 'pairingKey' => $pairingKey]) ?>
<p class="hlaska<?= $total > 0 && $needing === 0 ? ' hlaska-ok' : '' ?>"><?= e($total === 0 ? t('No site reports to this console yet. Make a pairing key and paste it on the site.')
    : ($needing === 0 ? t('All %d sites are fine.', $total) : t('%d of %d sites need attention.', $needing, $total))) ?> <?= e(t('The newest version: %s.', $latest)) ?></p>
<form class="formular" method="post" action="<?= e($module->url('pairing_key')) ?>">
<?= $csrf ?>
<p><button class="tl" type="submit"><?= e(t('Add a site')) ?></button>
<?php if ($total > 0): ?>
<button class="navigace" type="submit" formaction="<?= e($module->url('check')) ?>"><?= e(t('Check all now')) ?></button>
<button class="navigace" type="submit" formaction="<?= e($module->url('allow')) ?>" data-potvrdit="<?= e(t('Every site that lets the console decide installs version %s within an hour, without waiting for the test sites. Continue?', $latest)) ?>"><?= e(t('Allow the new version everywhere now')) ?></button>
<?php endif ?></p>
</form>
<?php if ($total > 0): ?>
<nav class="zalozky" aria-label="<?= e(t('Which sites')) ?>">
	<a href="<?= e($module->url('sites')) ?>"<?= $show === 'attention' && $customer === 0 ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t('Need attention')) ?> (<?= $needing ?>)</a>
	<a href="<?= e($module->url('sites', ['show' => 'all'])) ?>"<?= $show === 'all' && $customer === 0 ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t('All sites')) ?> (<?= $total ?>)</a>
</nav>
<form class="hromadne" method="get" action="<?= e($app->url('admin.php')) ?>"><input type="hidden" name="module" value="fleet"><input type="hidden" name="action" value="sites"><?php if ($show === 'all'): ?><input type="hidden" name="show" value="all"><?php endif ?>
	<label><?= e(t('Name or address contains:')) ?> <input class="textpole" type="search" name="q" value="<?= e($query) ?>" size="24"></label>
<?php if ($customers !== []): ?>
	<label><?= e(t('Customer')) ?> <select name="customer"><option value="0"><?= e(t('all')) ?></option>
<?php foreach ($customers as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $customer === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
	<button class="navigace" type="submit"><?= e(t('Filtrovat')) ?></button></form>
<?php if ($customer > 0): ?>
<form class="formular" method="post" action="<?= e($module->url('allow')) ?>"><?= $csrf ?><input type="hidden" name="customer" value="<?= $customer ?>">
<p><button class="navigace" type="submit" data-potvrdit="<?= e(t('The sites of %s that let the console decide install version %s within an hour. Continue?', $customers[$customer], $latest)) ?>"><?= e(t('Allow the new version on the sites of %s now', $customers[$customer])) ?></button></p></form>
<?php endif ?>
<?php if ($sites === []): ?>
<p><?= e(t('Nothing needs attention.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis vypis-karty">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('Customer')) ?></th><th scope="col"><?= e(t('What needs attention')) ?></th><th scope="col"><?= e(t('Waiting')) ?></th><th scope="col"><?= e(t('Version')) ?></th><th scope="col"><?= e(t('Last report')) ?></th><th scope="col"><?= e(t('Enquiries waiting')) ?></th><th scope="col" class="stred"><?= e(t('Select')) ?></th></tr></thead>
<tbody>
<?php foreach ($sites as $s): ?>
<tr>
	<td><a href="<?= e($module->url('detail', ['id' => (int) $s['id']])) ?>"><strong><?= e((string) $s['name'] !== '' ? (string) $s['name'] : (string) $s['url']) ?></strong></a><br><span class="smltxt"><?= e((string) $s['url']) ?><?= $s['ring'] === 'canary' ? ' · ' . e(t('test site')) : '' ?></span></td>
	<td data-popisek="<?= e(t('Customer')) ?>"><?php if (isset($customers[(int) $s['customer_id']])): ?><a href="<?= e($module->url('sites', ['customer' => (int) $s['customer_id']])) ?>"><?= e($customers[(int) $s['customer_id']]) ?></a><?php endif ?></td>
	<td data-popisek="<?= e(t('What needs attention')) ?>"><?php if ($s['reasons'] === []): ?><span class="stitek stitek-vydano"><?= e(t('ok')) ?></span><?php endif ?><?php foreach ($s['reasons'] as $r): ?><span class="stitek<?= $severity($r) !== '' ? ' stitek-' . $severity($r) : '' ?>"><?= e($reasonLabels[$r]) ?></span> <?php endforeach ?></td>
	<td data-popisek="<?= e(t('Waiting')) ?>" class="stred"><?= ($n = $waiting[(int) $s['id']] ?? 0) > 0 ? '<a href="' . e($module->url('', ['site' => (int) $s['id']])) . '">' . $n . '</a>' : '0' ?></td>
	<td data-popisek="<?= e(t('Version')) ?>"><?= e((string) $s['version'] !== '' ? (string) $s['version'] : '–') ?><?php if ((string) $s['update_allowed'] !== ''): ?><br><span class="smltxt"><?= e(t('allowed %s', (string) $s['update_allowed'])) ?></span><?php endif ?></td>
	<td data-popisek="<?= e(t('Last report')) ?>"><?= $s['last_seen'] !== null ? e(format_date(new DateTimeImmutable((string) $s['last_seen']), true)) : '–' ?></td>
	<td data-popisek="<?= e(t('Enquiries waiting')) ?>" class="stred"><?= ($waitingEnquiries = Console::enquiriesWaiting($s['beat'])) !== null ? $waitingEnquiries : '–' ?></td>
	<td data-popisek="<?= e(t('Select')) ?>" class="stred"><input type="checkbox" name="sites[]" value="<?= (int) $s['id'] ?>" form="fleet-customer" aria-label="<?= e(t('Select %s', (string) $s['name'] !== '' ? (string) $s['name'] : (string) $s['url'])) ?>"></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<form id="fleet-customer" class="hromadne" method="post" action="<?= e($module->url('customer_bulk')) ?>">
	<?= $csrf ?>
	<label><?= e(t('Give the selected sites the customer')) ?> <input class="textpole" type="text" name="customer" list="fleet-customers" maxlength="150" size="24"></label>
	<datalist id="fleet-customers"><?php foreach ($customers as $name): ?><option value="<?= e($name) ?>"><?php endforeach ?></datalist>
	<button class="navigace" type="submit"><?= e(t('Apply')) ?></button>
	<span class="napoveda"><?= e(t('An empty name takes the customer away.')) ?></span>
</form>
<?php endif ?>
<?php endif ?>
<p class="smltxt"><?= e(t('Sites report every hour; the console checks every 5 minutes that each home page answers. Problems reach you by the alert e-mail (System status → Alerts).')) ?> <?= e(t('Which problems, to whom and how often: Alerts and digest.')) ?></p>
