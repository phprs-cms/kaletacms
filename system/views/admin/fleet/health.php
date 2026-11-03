<?php
/**
 * The health matrix (3.29, Fleet\Health::matrix): a row per site, a column per check of Fleet\Checks in its order. Each
 * cell is a state chip – a symbol and a text for screen readers, never the colour alone – that opens the screen fixing
 * the check on that site (a plain link the console builds from the site's address). On a wide screen the table scrolls
 * sideways inside its frame with the site column fixed; on a phone each site is a card of chips.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var list<array<string, mixed>> $rows Health::matrix
 * @var array<int, string> $customers id => name
 * @var int $customer 0 = all, -1 = sites without a customer
 * @var string $sort worst | name | customer
 * @var int $siteCount all sites in the console
 */
use Kaleta\Fleet\Checks;
use Kaleta\Fleet\Health;

$states = array_map(fn (string $label): string => t($label), Health::STATE_LABELS);
$group = null;
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'health']) ?>
<?php if ($siteCount === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No site reports to this console yet. Make a pairing key and paste it on the site.')]) ?>
<?php else: ?>
<form class="hromadne" method="get" action="<?= e($app->url('admin.php')) ?>">
	<input type="hidden" name="module" value="fleet"><input type="hidden" name="action" value="health">
<?php if ($customers !== []): ?>
	<label><?= e(t('Customer')) ?> <select name="customer"><option value="0"><?= e(t('all')) ?></option><option value="-1"<?= $customer === -1 ? ' selected' : '' ?>><?= e(t('without a customer')) ?></option>
<?php foreach ($customers as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $customer === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
	<label><?= e(t('Order')) ?> <select name="sort">
<?php foreach (['worst' => t('the worst first'), 'name' => t('by name'), 'customer' => t('by customer')] as $key => $label): ?>
		<option value="<?= e($key) ?>"<?= $sort === $key ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach ?>
	</select></label>
	<button class="navigace" type="submit"><?= e(t('Show')) ?></button>
</form>
<p class="flotila-legenda smltxt"><?php foreach (Health::SYMBOLS as $state => $symbol): ?><span class="flotila-stav flotila-stav-<?= e($state) ?>"><span aria-hidden="true"><?= $symbol ?></span></span> <?= e($states[$state]) ?> &nbsp; <?php endforeach ?></p>
<?php if ($rows === []): ?>
<p><?= e(t('Nothing matches the filter.')) ?></p>
<?php else: ?>
<div class="tab-obal flotila-matice-obal"><table class="vypis flotila-matice">
<caption class="navod-skryte"><?= e(t('Health of the sites: a row per site, a column per check')) ?></caption>
<thead><tr><th scope="col"><?= e(t('Site')) ?></th>
<?php foreach (Checks::CHECKS as $id => [$label]): ?>
	<th scope="col" title="<?= e(t($label)) ?>"><abbr title="<?= e(t($label)) ?>"><?= e(t(Health::SHORT[$id])) ?></abbr></th>
<?php endforeach ?>
</tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<?php if ($sort === 'customer' && $group !== ($r['customer'] ?? '')): $group = $r['customer'] ?? ''; ?>
<tr class="flotila-skupina"><th scope="rowgroup" colspan="<?= count(Checks::CHECKS) + 1 ?>"><?= e($group !== '' ? $group : t('Sites without a customer')) ?></th></tr>
<?php endif ?>
<tr>
	<th scope="row"><a href="<?= e($module->url('detail', ['id' => $r['site_id']])) ?>"><?= e($r['site']) ?></a>
		<?php if ($r['customer'] !== null && $sort !== 'customer'): ?><br><span class="smltxt"><?= e($r['customer']) ?></span><?php endif ?>
		<?php if ($r['update_needed']): ?><br><span class="stitek"><?= e(t('Update the site to see')) ?></span><?php endif ?>
		<span class="flotila-pocty smltxt"><?= e(t('%d need action, %d warnings', $r['bad'], $r['warn'])) ?></span></th>
<?php foreach ($r['checks'] as $id => $c):
    $text = $c['label'] . ': ' . $states[$c['state']] . ' – ' . $c['detail'];
    $inner = '<span aria-hidden="true">' . Health::SYMBOLS[$c['state']] . '</span><span class="flotila-zkratka" aria-hidden="true">' . e(t(Health::SHORT[$id])) . '</span><span class="navod-skryte">' . e($text) . '</span>'; ?>
	<td><?php if ($c['link'] !== ''): ?><a class="flotila-stav flotila-stav-<?= e($c['state']) ?>" href="<?= e($c['link']) ?>" target="_blank" rel="noopener" title="<?= e($text) ?>"><?= $inner ?></a><?php else: ?><span class="flotila-stav flotila-stav-<?= e($c['state']) ?>" title="<?= e($text) ?>"><?= $inner ?></span><?php endif ?></td>
<?php endforeach ?>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="smltxt"><?= e(t('Each cell opens the screen of the site\'s own administration where the check is fixed – sign in there as usual. A site before Kaleta 3.29 sends only part of the checks: update it to see the rest.')) ?></p>
<?php endif ?>
