<?php
/**
 * The customers of the fleet console (3.17, Fleet\Customers): how many sites each has and how much waits on them,
 * renaming a customer for all its sites, and the new version for one customer's sites.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $csrf
 * @var list<array{id: int, name: string, sites: int}> $customers
 * @var array<int, int> $waiting customer id => things waiting (0 = sites without a customer)
 * @var int $without sites without a customer
 * @var string $latest the newest version the console knows
 * @var array<int, string> $reports customer id => the newest month of its fleet report (Fleet\Report)
 */
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'customers']) ?>
<p class="hlaska"><?= e(t('A customer groups the sites of one client: the inbox and the list of sites filter by it, the new version and the shared kit can go to one customer\'s sites. Give sites a customer on the list of sites (tick them) or on a site\'s page. The customer and your notes stay on the console – the sites never see them.')) ?></p>
<?php if ($customers === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No customers yet.'), 'text' => t('Tick sites on the list of sites and give them a customer.'), 'action' => [$module->url('sites', ['show' => 'all']), t('All sites')]]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis vypis-karty">
<thead><tr><th scope="col"><?= e(t('Customer')) ?></th><th scope="col"><?= e(t('Sites')) ?></th><th scope="col"><?= e(t('Waiting')) ?></th><th scope="col"><?= e(t('Fleet report')) ?></th><th scope="col"><?= e(t('Rename')) ?></th><th scope="col"><?= e(t('Updates')) ?></th></tr></thead>
<tbody>
<?php foreach ($customers as $c): ?>
<tr>
	<td><a href="<?= e($module->url('sites', ['customer' => $c['id']])) ?>"><strong><?= e($c['name']) ?></strong></a></td>
	<td data-popisek="<?= e(t('Sites')) ?>" class="stred"><?= $c['sites'] ?></td>
	<td data-popisek="<?= e(t('Waiting')) ?>" class="stred"><?= ($n = $waiting[$c['id']] ?? 0) > 0 ? '<a href="' . e($module->url('', ['customer' => $c['id']])) . '">' . $n . '</a>' : '0' ?></td>
	<td data-popisek="<?= e(t('Fleet report')) ?>"><?php if (isset($reports[$c['id']])): ?><a href="<?= e($module->url('report_view', ['month' => $reports[$c['id']], 'customer' => $c['id']])) ?>" target="_blank" rel="noopener"><?= e(Kaleta\Core\ReportPage::monthName($reports[$c['id']])) ?></a><?php else: ?>–<?php endif ?></td>
	<td data-popisek="<?= e(t('Rename')) ?>"><form class="hromadne" method="post" action="<?= e($module->url('customer_rename')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= $c['id'] ?>">
		<input class="textpole" type="text" name="name" value="<?= e($c['name']) ?>" maxlength="150" size="20" required aria-label="<?= e(t('New name of %s', $c['name'])) ?>"> <button class="navigace" type="submit"><?= e(t('Rename')) ?></button></form></td>
	<td data-popisek="<?= e(t('Updates')) ?>"><form method="post" action="<?= e($module->url('allow')) ?>"><?= $csrf ?><input type="hidden" name="customer" value="<?= $c['id'] ?>">
		<button class="navigace" type="submit" data-potvrdit="<?= e(t('The sites of %s that let the console decide install version %s within an hour. Continue?', $c['name'], $latest)) ?>"><?= e(t('Allow version %s now', $latest)) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php if ($without > 0): ?><p class="smltxt"><?= e(t('%d sites have no customer.', $without)) ?> <a href="<?= e($module->url('sites', ['show' => 'all'])) ?>"><?= e(t('All sites')) ?></a></p><?php endif ?>
<p class="smltxt"><?= e(t('Renaming to the name of another customer merges the two.')) ?></p>
<?php endif ?>
