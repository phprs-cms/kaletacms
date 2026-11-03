<?php
/**
 * The inventory of the fleet console (3.30, Fleet\Inventory): a row per site with its version, update channel, PHP,
 * database, web server, features, add-ons, languages and last report – each column a filter (what the sites report), a
 * free text search and the CSV export of what is shown. A site before 3.30 shows what it sends and "Update the site to see".
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var list<array<string, mixed>> $rows Inventory::row, filtered
 * @var int $total all sites
 * @var array<string, array<string, int>> $options Inventory::options
 * @var array<string, mixed> $filters
 * @var string $query
 * @var array<int, string> $customers id => name
 * @var int $unknown sites that do not send the 3.30 parts
 */
use Kaleta\Core\Extensions;
use Kaleta\Fleet\Inventory;

$featureLabel = fn (string $key): string => isset(Extensions::CATALOG[$key]) ? t(Extensions::CATALOG[$key][0]) : $key;
$dbLabel = fn (string $value): string => (string) preg_replace_callback('/^(mysql|mariadb|other)/', fn (array $m): string => t(Inventory::DB_LABELS[$m[1]]), $value);
$serverLabel = fn (string $value): string => t(Inventory::SERVER_LABELS[$value] ?? $value);
$channelLabel = fn (string $value): string => match ($value) { 'latest' => t('Latest'), 'stable' => t('Stable'), 'custom' => t('custom update source'), default => $value };
$labels = ['version' => t('Version'), 'channel' => t('Channel'), 'php' => 'PHP', 'db' => t('Database'), 'server' => t('Web server'), 'feature' => t('Feature'), 'addon' => t('Add-on'),
    'language' => t('Language')];
$show = ['version' => fn (string $v): string => $v, 'channel' => $channelLabel, 'php' => fn (string $v): string => $v, 'db' => $dbLabel, 'server' => $serverLabel, 'feature' => $featureLabel,
    'addon' => fn (string $v): string => $v, 'language' => fn (string $v): string => strtoupper($v)];
$active = array_filter($filters, fn (mixed $v): bool => $v !== '' && $v !== 0) !== [] || $query !== '';
$params = array_filter($filters + ['q' => $query], fn (mixed $v): bool => $v !== '' && $v !== 0);
$none = '<span class="smltxt" title="' . e(t('Update the site to see')) . '">–</span>';
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'inventory']) ?>
<?php if ($total === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No site reports to this console yet. Make a pairing key and paste it on the site.')]) ?>
<?php else: ?>
<p class="napoveda"><?= e(t('What each site reports about itself in its hourly report: the version and update channel, PHP, the database, the web server, the features and add-ons switched on and its languages. Filter by any column – for example the sites that still run PHP 8.3, or the ones with Bookings on.')) ?></p>
<form class="hromadne flotila-filtry" method="get" action="<?= e($app->url('admin.php')) ?>">
	<input type="hidden" name="module" value="fleet"><input type="hidden" name="action" value="inventory">
	<label><?= e(t('Search')) ?> <input class="textpole" type="search" name="q" value="<?= e($query) ?>" size="16"></label>
<?php if ($customers !== []): ?>
	<label><?= e(t('Customer')) ?> <select name="customer"><option value="0"><?= e(t('all')) ?></option><option value="-1"<?= $filters['customer'] === -1 ? ' selected' : '' ?>><?= e(t('without a customer')) ?></option>
<?php foreach ($customers as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $filters['customer'] === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
<?php foreach ($labels as $filter => $label): if (($options[$filter] ?? []) === [] && ($filters[$filter] ?? '') === '') { continue; } ?>
	<label><?= e($label) ?> <select name="<?= e($filter) ?>"><option value=""><?= e(t('all')) ?></option>
<?php foreach ($options[$filter] ?? [] as $value => $count): ?>
		<option value="<?= e((string) $value) ?>"<?= (string) $filters[$filter] === (string) $value ? ' selected' : '' ?>><?= e($show[$filter]((string) $value)) ?> (<?= (int) $count ?>)</option>
<?php endforeach ?>
	</select></label>
<?php endforeach ?>
	<button class="navigace" type="submit"><?= e(t('Show')) ?></button>
<?php if ($active): ?>
	<a href="<?= e($module->url('inventory')) ?>"><?= e(t('Clear filter')) ?></a>
<?php endif ?>
	<a class="navigace" href="<?= e($module->url('inventory_csv', $params)) ?>" download><?= e(t('Export to CSV')) ?></a>
</form>
<p class="smltxt"><?= e(t('%d of %d sites', count($rows), $total)) ?><?php if ($unknown > 0): ?> · <?= e(t('%d sites before Kaleta 3.30 report only the version, channel and PHP – update them to see the rest.', $unknown)) ?><?php endif ?></p>
<?php if ($rows === []): ?>
<p><?= e(t('Nothing matches the filter.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis vypis-karty flotila-inventar">
<caption class="navod-skryte"><?= e(t('Inventory of the sites')) ?></caption>
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('Version')) ?></th><th scope="col"><?= e(t('Channel')) ?></th><th scope="col">PHP</th><th scope="col"><?= e(t('Database')) ?></th>
	<th scope="col"><?= e(t('Web server')) ?></th><th scope="col"><?= e(t('Features')) ?></th><th scope="col"><?= e(t('Add-ons')) ?></th><th scope="col"><?= e(t('Languages')) ?></th><th scope="col"><?= e(t('Last report')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
	<th scope="row"><a href="<?= e($module->url('detail', ['id' => $r['site_id']])) ?>"><?= e($r['site']) ?></a><?php if ($r['customer'] !== null): ?><br><span class="smltxt"><?= e($r['customer']) ?></span><?php endif ?>
		<?php if (!$r['known']): ?><br><span class="stitek"><?= e(t('Update the site to see')) ?></span><?php endif ?></th>
	<td data-popisek="<?= e(t('Version')) ?>"><?= $r['version'] !== '' ? e($r['version']) : $none ?></td>
	<td data-popisek="<?= e(t('Channel')) ?>"><?= $r['channel'] !== null ? e($channelLabel($r['channel'])) : $none ?></td>
	<td data-popisek="PHP"><?= $r['php'] !== null ? e($r['php']) : $none ?></td>
	<td data-popisek="<?= e(t('Database')) ?>"><?= $r['db_family'] !== null ? e(t(Inventory::DB_LABELS[$r['db_family']]) . ($r['db_version'] !== null ? ' ' . $r['db_version'] : '')) : $none ?></td>
	<td data-popisek="<?= e(t('Web server')) ?>"><?= $r['server'] !== null ? e($serverLabel($r['server'])) : $none ?></td>
	<td data-popisek="<?= e(t('Features')) ?>"><?= $r['features'] !== null ? ($r['features'] === [] ? e(t('none')) : e(implode(', ', array_map($featureLabel, $r['features'])))) : $none ?></td>
	<td data-popisek="<?= e(t('Add-ons')) ?>"><?= $r['addons'] !== null ? ($r['addons'] === [] ? e(t('none')) : e(implode(', ', $r['addons']))) : $none ?></td>
	<td data-popisek="<?= e(t('Languages')) ?>"><?= $r['languages'] !== null ? e(implode(', ', array_map('strtoupper', $r['languages']))) : $none ?></td>
	<td data-popisek="<?= e(t('Last report')) ?>"><?= $r['last_seen'] !== null ? e(format_date(new DateTimeImmutable($r['last_seen']), true)) : e(t('no report yet')) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="smltxt"><?= e(t('The web server is only its kind (Apache, nginx, LiteSpeed…), as the site sees it – never an address or a version. The console asks nobody else about the sites.')) ?></p>
<?php endif ?>
