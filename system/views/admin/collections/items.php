<?php
/**
 * Collection items.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var list<array<string, mixed>> $items
 * @var list<string> $languages other languages of the site (a detail template for each one separately)
 * @var list<string> $siteLanguages all languages of the site for the filter (empty = a single language)
 * @var string $language language selected in the filter ('' = all)
 * @var bool $trash the trash is shown
 * @var bool $noticeBoard an official notice board (2.11): its notices are never deleted
 * @var int $inTrash items in the trash
 * @var array<int, array{0: int, 1: int}>|null $downloads  document library (2.11): idp => [downloads in 30 days, total]; null = not a library
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('item', ['id' => $k['idk']])) ?>"><?= e(t('Add item')) ?></a>
	<a class="navigace" href="<?= e($module->url('import', ['id' => $k['idk']])) ?>"><?= e(t('Import from CSV or JSON')) ?></a>
	<a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('All collections')) ?></a>
	<a class="navigace" href="<?= e($module->url('categories', ['id' => $k['idk']])) ?>"><?= e(t('Categories')) ?></a>
<?php if ($app->auth()->isAdmin()): ?>
	<a class="navigace" href="<?= e($module->url('edit', ['id' => $k['idk']])) ?>"><?= e(t('Fields and settings')) ?></a>
<?php if ($k['detail']): ?>
	<a class="navigace" href="<?= e($module->url('builder', ['id' => $k['idk']])) ?>"><?= e(t('Detail template')) ?></a>
<?php foreach ($languages as $language): ?>
	<a class="navigace" href="<?= e($module->url('builder', ['id' => $k['idk'], 'language' => $language])) ?>"><?= e(t('Detail template (%s)', strtoupper($language))) ?></a>
<?php endforeach ?>
<?php endif ?>
<?php endif ?></p>
<?php if ($inTrash > 0 || $trash): ?>
<nav class="zalozky" aria-label="<?= e(t('Items')) ?>">
	<a href="<?= e($module->url('items', ['id' => $k['idk']])) ?>"<?= $trash ? '' : ' class="aktivni" aria-current="true"' ?>><?= e(t('Všechny')) ?></a>
	<a href="<?= e($module->url('items', ['id' => $k['idk'], 'status' => 'kos'])) ?>"<?= $trash ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Trash')) ?> (<?= $inTrash ?>)</a>
</nav>
<?php endif ?>
<?php if ($trash): ?>
<?php if ($items === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('The trash is empty.'), 'text' => t('Deleted items stay here for 30 days, then they are deleted permanently.'), 'action' => [$module->url('items', ['id' => $k['idk']]), t('Back to items')]]) ?>
<?php else: ?>
<p class="smltxt"><?= e(t('Items in the trash are not on the site. A restored item comes back hidden; after 30 days it is permanently deleted from the trash.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($items as $p): ?>
<tr class="nevydany">
	<td><?= e($p['nazev']) ?><?= $p['jazyk'] !== '' ? ' <span class="stitek">' . e(strtoupper($p['jazyk'])) . '</span>' : '' ?></td>
	<td class="cislo"><?= e(format_date((string) $p['smazano'], true)) ?></td>
	<td class="akce">
		<form class="vradku" method="post" action="<?= e($module->url('restore_item')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace" type="submit"><?= e(t('Restore')) ?></button></form> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete_item_permanently')) ?>" data-potvrdit="<?= e(t('Delete the item permanently? This cannot be undone.')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete permanently')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<?php else: ?>
<?php if ($siteLanguages !== []): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt"><input type="hidden" name="module" value="collections"><input type="hidden" name="action" value="items"><input type="hidden" name="id" value="<?= (int) $k['idk'] ?>"><?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true]) ?></form>
<?php endif ?>
<?php if ($items === [] && $language === ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('The collection is empty.'), 'text' => t('Add the first item – then put it on the site with the Collection list element in the builder.'), 'action' => [$module->url('item', ['id' => $k['idk']]), t('Add item')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><input type="checkbox" data-vybrat-vse="hromadne" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Pořadí')) ?></th><?php if ($downloads !== null): ?><th scope="col" title="<?= e(t('Downloads of the stable address /…/latest: the last 30 days / total. Bots are not counted.')) ?>"><?= e(t('Downloads (30 days / total)')) ?></th><?php endif ?><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($items as $p): ?>
<tr<?= $p['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td><input type="checkbox" name="oznacene[]" value="<?= (int) $p['idp'] ?>" form="hromadne" aria-label="<?= e(t('Select %s', $p['nazev'])) ?>"></td>
	<td><a href="<?= e($module->url('item', ['id' => $k['idk'], 'item' => $p['idp']])) ?>"><?= e($p['nazev']) ?></a><?= $p['jazyk'] !== '' ? ' <span class="stitek">' . e(strtoupper($p['jazyk'])) . '</span>' : '' ?><?= $p['valid_until'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Hides itself the day after.')) . '">' . e(t('true until %s', format_date($p['valid_until']))) . '</span>' : '' ?><?= $p['review_by'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Asks for a review on this day.')) . '">' . e(t('review by %s', format_date($p['review_by']))) . '</span>' : '' ?></td>
	<td><?= (int) $p['poradi'] ?></td>
<?php if ($downloads !== null): ?>
	<td class="cislo stazeni"><?= (int) ($downloads[(int) $p['idp']][0] ?? 0) ?> / <?= (int) ($downloads[(int) $p['idp']][1] ?? 0) ?></td>
<?php endif ?>
	<td><span class="stitek stitek-<?= $p['zobrazit'] ? 'vydano' : 'koncept' ?>"><?= e(t($p['zobrazit'] ? 'zveřejněná' : 'skrytá')) ?></span></td>
	<td class="akce"><?php if ($k['detail'] && $p['zobrazit']): ?><a href="<?= e($app->url(($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $k['seo_link'] . '/' . $p['seo_link'])) ?>" target="_blank" rel="noopener"><?= e(t('Show')) ?></a> · <?php endif ?><?php if ($downloads !== null && $k['detail'] && $p['zobrazit']): ?><a href="<?= e($app->url(($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $k['seo_link'] . '/' . $p['seo_link'] . '/latest')) ?>" title="<?= e(t('The stable address of the current file – it stays the same when a new version replaces the file.')) ?>"><?= e(t('Download')) ?></a> · <?php endif ?>
		<form class="vradku" method="post" action="<?= e($module->url('duplicate_item')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace" type="submit"><?= e(t('Duplicate')) ?></button></form><?php if (!empty($noticeBoard)): ?>
		<span class="napoveda" title="<?= e(t('Notices stay in the archive – change the takedown date instead.')) ?>">· <?= e(t('kept in the archive')) ?></span><?php else: ?> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete_item')) ?>" data-potvrdit="<?= e(t('Move the item to the trash? It disappears from the site; you can restore it for 30 days.')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?= $app->view->render('admin/bulk_actions', ['csrf' => $csrf, 'action' => $module->url('bulk_items'), 'siteLanguages' => $siteLanguages, 'hidden' => ['idk' => (string) (int) $k['idk']],
    'actions' => ['zobrazit' => t('Publish'), 'skryt' => t('Hide')] + (empty($noticeBoard) ? ['kos' => t('Move to trash')] : [])]) ?>
<?php endif ?>
<?php endif ?>
