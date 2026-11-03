<?php
/**
 * Today (3.29, the console opens on it): what needs attention across the sites, ranked by Fleet\Checks::today – red
 * before amber, the check's weight, the oldest first. Each row opens the screen that fixes it on the site: a plain link
 * built by the console from the site's known address (no token, no sign-in on the owner's behalf).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $csrf
 * @var list<array<string, mixed>> $items Checks::today, filtered
 * @var int $total items without a filter
 * @var array{sites: int, bad: int, warn: int, ok_sites: int, not_reporting: int, to_update: int} $summary
 * @var int $customer 0 = all, -1 = sites without a customer
 * @var string $severity '' | bad | warn
 * @var array<int, string> $customers id => name
 * @var int $now
 * @var string $pairingKey
 */
use Kaleta\Fleet\Health;

$filtered = $customer !== 0 || $severity !== '';
$severityLabel = ['bad' => t('Needs action'), 'warn' => t('Warning')];
$severityClass = ['bad' => ' stitek-chyba', 'warn' => ' stitek-koncept'];
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => '', 'pairingKey' => $pairingKey]) ?>
<?php if ($summary['sites'] === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No site reports to this console yet. Make a pairing key and paste it on the site.'),
    'text' => t('When the sites report, what needs your attention today – a site down, a failing backup, an expiring certificate, an update – comes together here.')]) ?>
<form class="stred" method="post" action="<?= e($module->url('pairing_key')) ?>"><?= $csrf ?><button class="tl" type="submit"><?= e(t('Add a site')) ?></button></form>
<?php else: ?>
<ul class="flotila-souhrn" aria-label="<?= e(t('Summary of the sites')) ?>">
	<li class="flotila-souhrn-bad"><strong><?= $summary['bad'] ?></strong> <?= e(t('need action')) ?></li>
	<li class="flotila-souhrn-warn"><strong><?= $summary['warn'] ?></strong> <?= e(t('warnings')) ?></li>
	<li><strong><?= $summary['ok_sites'] ?></strong> <?= e(t('of %d sites OK', $summary['sites'])) ?></li>
	<li><a href="<?= e($module->url('health')) ?>"><strong><?= $summary['not_reporting'] ?></strong> <?= e(t('not reporting')) ?></a></li>
	<li><a href="<?= e($module->url('health')) ?>"><strong><?= $summary['to_update'] ?></strong> <?= e(t('to update to see every check')) ?></a></li>
</ul>
<?php if ($total === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('Everything is green on the %d sites.', $summary['sites']),
    'text' => t('Nothing needs your attention today. The health matrix shows every check of every site.'), 'action' => [$module->url('health'), t('Open the health matrix')]]) ?>
<?php else: ?>
<form class="hromadne" method="get" action="<?= e($app->url('admin.php')) ?>">
	<input type="hidden" name="module" value="fleet">
<?php if ($customers !== []): ?>
	<label><?= e(t('Customer')) ?> <select name="customer"><option value="0"><?= e(t('all')) ?></option><option value="-1"<?= $customer === -1 ? ' selected' : '' ?>><?= e(t('without a customer')) ?></option>
<?php foreach ($customers as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $customer === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
	<label><?= e(t('Severity')) ?> <select name="severity"><option value=""><?= e(t('all')) ?></option>
		<option value="bad"<?= $severity === 'bad' ? ' selected' : '' ?>><?= e(t('Needs action')) ?></option>
		<option value="warn"<?= $severity === 'warn' ? ' selected' : '' ?>><?= e(t('Warning')) ?></option>
	</select></label>
	<button class="navigace" type="submit"><?= e(t('Filtrovat')) ?></button>
<?php if ($filtered): ?>
	<a href="<?= e($module->url('')) ?>"><?= e(t('Clear filter')) ?></a>
<?php endif ?>
</form>
<?php if ($items === []): ?>
<p><?= e(t('Nothing matches the filter.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis vypis-karty flotila-dnes">
<thead><tr><th scope="col"><?= e(t('What needs attention')) ?></th><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('Severity')) ?></th><th scope="col"><?= e(t('Since')) ?></th><th scope="col"><span class="navod-skryte"><?= e(t('Open')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($items as $i): ?>
<tr class="flotila-<?= e($i['state']) ?>">
	<td><strong><?= e($i['label']) ?></strong><br><span class="smltxt"><?= e($i['detail']) ?></span></td>
	<td data-popisek="<?= e(t('Site')) ?>"><a href="<?= e($module->url('detail', ['id' => $i['site_id']])) ?>"><?= e($i['site']) ?></a><?php if ($i['customer_id'] !== null && isset($customers[$i['customer_id']])): ?><br><span class="smltxt"><?= e($customers[$i['customer_id']]) ?></span><?php endif ?></td>
	<td data-popisek="<?= e(t('Severity')) ?>"><span class="stitek<?= $severityClass[$i['state']] ?>"><span aria-hidden="true"><?= Health::SYMBOLS[$i['state']] ?></span> <?= e($severityLabel[$i['state']]) ?></span></td>
	<td data-popisek="<?= e(t('Since')) ?>"><?= $i['since'] !== null ? '<span title="' . e(format_date((new DateTimeImmutable())->setTimestamp($i['since']), true)) . '">' . e(Health::age($i['since'], $now)) . '</span>' : '–' ?></td>
	<td data-popisek="<?= e(t('Open')) ?>" class="akce"><?php if ($i['link'] !== ''): ?><a class="navigace" href="<?= e($i['link']) ?>" target="_blank" rel="noopener"><?= e(t('Open')) ?><span class="navod-skryte"> – <?= e($i['label']) ?>, <?= e($i['site']) ?></span></a><?php else: ?>–<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php endif ?>
<p class="smltxt"><?= e(t('Built from each site\'s last report (every hour) and the console\'s own uptime checks. Open leads to the screen of the site\'s own administration that fixes it – sign in there as usual; the console has no access to the sites.')) ?></p>
<?php endif ?>
