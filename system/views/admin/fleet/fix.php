<?php
/**
 * The fix page of one check (3.50, CPO 11 – Admin\Modules\Fleet::actionFix, read-only on the console): what the check means,
 * how to fix it once, then the sites – the ones to fix first, each with a plain link to the screen of its own administration
 * that fixes it (Fleet\Checks::link, built from the site's known address), the unknown ones, and the ones whose last report
 * already shows the check fixed with a tick. The tick comes from the stored heartbeat; the console never calls into a site.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $check id of Fleet\Checks::CHECKS
 * @var list<array<string, mixed>> $rows Fleet\Checks::fixList
 * @var int $now
 * @var array<int, string> $customers id => name
 * @var array<string, int> $counts state => sites
 */
use Kaleta\Fleet\Checks;
use Kaleta\Fleet\Health;

[$meaning, $howTo] = Checks::HELP[$check];
$states = array_map(fn (string $label): string => t($label), Health::STATE_LABELS);
$since = fn (?int $at): string => $at !== null ? e(Health::age($at, $now)) : '–';
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'fix']) ?>
<div data-fleet-fix="<?= e($check) ?>">
<h2><?= e(t('What it checks')) ?></h2>
<p><?= e(t($meaning)) ?></p>
<h2><?= e(t('How to fix it')) ?></h2>
<p><?= e(t($howTo)) ?></p>
<p class="smltxt"><?= e(t('Each link opens the screen of the site\'s own administration – sign in there as usual. The tick appears when the site\'s next report shows the check fixed.')) ?></p>
</div>
<?php if ($rows === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No site reports to this console yet. Make a pairing key and paste it on the site.')]) ?>
<?php else: ?>
<p data-fleet-fix-counts><?= e(t('%d to fix, %d fixed, %d not known', $counts['bad'] + $counts['warn'], $counts['ok'], $counts['unknown'])) ?></p>
<div class="tab-obal"><table class="vypis vypis-karty flotila-dnes">
<caption class="navod-skryte"><?= e(t('Sites and the state of this check')) ?></caption>
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('State')) ?></th><th scope="col"><?= e(t('Since')) ?></th><th scope="col"><span class="navod-skryte"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr class="flotila-<?= e($r['state']) ?>" data-fleet-fix-site="<?= (int) $r['site_id'] ?>"<?= $r['done'] ? ' data-fleet-fix-done' : '' ?>>
	<td><a href="<?= e($module->url('detail', ['id' => $r['site_id']])) ?>"><?= e($r['site']) ?></a><?php if ($r['customer_id'] !== null && isset($customers[$r['customer_id']])): ?><br><span class="smltxt"><?= e($customers[$r['customer_id']]) ?></span><?php endif ?></td>
	<td data-popisek="<?= e(t('State')) ?>"><span class="flotila-stav flotila-stav-<?= e($r['state']) ?>"><span aria-hidden="true"><?= Health::SYMBOLS[$r['state']] ?></span></span>
		<?= $r['done'] ? '<strong>' . e(t('Fixed')) . '</strong> – ' : e($states[$r['state']]) . ' – ' ?><?= e($r['detail']) ?></td>
	<td data-popisek="<?= e(t('Since')) ?>"><?= $since($r['since']) ?></td>
	<td data-popisek="<?= e(t('Actions')) ?>" class="akce"><?php if (!$r['done'] && $r['link'] !== ''): ?><a class="navigace" href="<?= e($r['link']) ?>" target="_blank" rel="noopener"><?= e(t('Open')) ?><span class="navod-skryte"> – <?= e($r['site']) ?></span></a><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p><a href="<?= e($module->url('health')) ?>"><?= e(t('Back to the health of the sites')) ?></a></p>
