<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pages $module
 * @var string $csrf
 * @var list<array<string, mixed>> $pages
 * @var bool $trash     the trash is shown
 * @var string $search
 * @var int $inTrash    number of pages in the trash
 * @var array<int, int> $comments  unresolved comments from shared previews per page id (2.15)
 */
$home = $app->settings()->int('home_page');
$url = fn (array $s): string => ($s['jazyk'] !== '' ? $s['jazyk'] . '/' : '') . ((int) $s['ids'] === $home ? '' : $s['seo_link']);
?>
<div class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New page')) ?></a>
	<form class="vradku" method="post" action="<?= e($module->url('import')) ?>" enctype="multipart/form-data"><?= $csrf ?>
		<label class="navigace"><?= e(t('Import page (JSON)')) ?> <input type="file" name="soubor" accept="application/json,.json" data-odeslat-pri-zmene></label></form>
<?php if ($siteLanguages !== []): ?>
	<a class="navigace" href="<?= e($module->url('translations')) ?>"><?= e(t('Translations')) ?></a>
<?php endif ?></div>
<?php if ($inTrash > 0 || $trash): // tabs only with the trash – "Všechny" (All) on its own makes no sense ?>
<nav class="zalozky" aria-label="<?= e(t('Pages')) ?>">
	<a href="<?= e($module->url()) ?>"<?= $trash ? '' : ' class="aktivni" aria-current="true"' ?>><?= e(t('Všechny')) ?></a>
	<a href="<?= e($module->url('', ['status' => 'kos'])) ?>"<?= $trash ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Trash')) ?> (<?= $inTrash ?>)</a>
</nav>
<?php endif ?>
<?php if (!$trash && ($pages !== [] || $search !== '' || $language !== '')): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="module" value="pages">
<?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language]) ?>
	<label><?= e(t('Name or address contains:')) ?> <input class="textpole" type="search" name="search" value="<?= e($search) ?>" size="20"></label>
	<input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>">
</form>
<br>
<?php endif ?>
<?php if ($pages === [] && $trash): ?>
<?= $app->view->render('admin/empty', ['icon' => 'stranky', 'heading' => t('The trash is empty.'), 'text' => t('Deleted pages stay here for 30 days, then they are deleted permanently.'), 'action' => [$module->url(), t('Back to pages')]]) ?>
<?php elseif ($pages === [] && $search !== ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'stranky', 'heading' => t('No page matches the search.'), 'text' => t('Try another word.'), 'action' => [$module->url(), t('Clear search')]]) ?>
<?php elseif ($pages === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'stranky', 'heading' => t('No pages yet.'), 'text' => t('A business website usually consists of Home, About us, Services and Contact.'), 'action' => [$module->url('new'), t('Create the first page')]]) ?>
<?php elseif ($trash): ?>
<p class="smltxt"><?= e(t('Pages in the trash are not on the site. A restored page comes back hidden; after 30 days it is permanently deleted from the trash.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($pages as $s): ?>
<tr class="nevydany">
	<td><?= e($s['titulek']) ?></td>
	<td>/<?= e($s['seo_link']) ?></td>
	<td class="cislo"><?= e(format_date($s['smazano'], true)) ?></td>
	<td class="akce">
		<form class="vradku" method="post" action="<?= e($module->url('restore')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace" type="submit"><?= e(t('Restore')) ?></button></form> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete_permanently')) ?>" data-potvrdit="<?= e(t('Delete the page permanently? This cannot be undone.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete permanently')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><input type="checkbox" data-vybrat-vse="hromadne" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('In navigation')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($pages as $s): ?>
<tr<?= $s['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td><input type="checkbox" name="oznacene[]" value="<?= (int) $s['ids'] ?>" form="hromadne" aria-label="<?= e(t('Select %s', $s['titulek'])) ?>"></td>
	<td><?= !empty($s['uroven']) ? '<span class="odsazeni-stromu" style="padding-inline-start:' . ((int) $s['uroven'] - 1) * 1.2 . 'em">↳ </span>' : '' ?><a href="<?= e($module->url('edit', ['id' => $s['ids']])) ?>"><?= e($s['titulek']) ?></a><?= (int) $s['ids'] === $home ? ' <span class="stitek">' . e(t('home')) . '</span>' : '' ?><?= $s['stavba'] !== null || $s['stavba_koncept'] !== null ? ' <span class="stitek stitek-vydano">' . e(t('builder')) . '</span>' : '' ?><?= $s['stavba_koncept'] !== null ? ' <span class="stitek stitek-koncept" title="' . e(t('The builder has changes that are not on the site yet.')) . '">' . e(t('unpublished changes')) . '</span>' : '' ?><?= $s['noindex'] ? ' <span class="stitek">noindex</span>' : '' ?><?= $s['zverejnit_od'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Publishes automatically')) . '">' . e(t('from %s', format_date($s['zverejnit_od'], true))) . '</span>' : '' ?><?= $s['valid_until'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Hides itself the day after.')) . '">' . e(t('true until %s', format_date($s['valid_until']))) . '</span>' : '' ?><?= $s['review_by'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Asks for a review on this day.')) . '">' . e(t('review by %s', format_date($s['review_by']))) . '</span>' : '' ?><?= !empty($comments[(int) $s['ids']]) ? ' <a class="stitek stitek-koncept" href="' . e($module->url('builder', ['id' => $s['ids']])) . '" title="' . e(t('Comments from people with a preview link, waiting in the builder.')) . '">' . e(t('%d comments', $comments[(int) $s['ids']])) . '</a>' : '' ?></td>
	<td><a href="<?= e($app->url($url($s)) . ($s['zobrazit'] ? '' : '?build=koncept')) ?>" target="_blank" rel="noopener"<?= $s['zobrazit'] ? '' : ' title="' . e(t('Preview hidden page')) . '"' ?>>/<?= e($url($s)) ?></a></td>
	<td><span class="stitek stitek-<?= $s['zobrazit'] ? 'vydano' : 'koncept' ?>"<?= !empty($s['show_on_publish']) ? ' title="' . e(t('Hidden until it has content: it goes on the site, and into the navigation, when you publish it in the builder or add text.')) . '"' : '' ?>><?= e(t($s['zobrazit'] ? 'zveřejněná' : (!empty($s['show_on_publish']) ? 'hidden until published' : 'skrytá'))) ?></span></td>
	<td><?= e(t($s['v_menu'] ? 'Yes' : 'No')) ?></td>
	<td class="akce"><a href="<?= e($module->url('builder', ['id' => $s['ids']])) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($module->url('edit', ['id' => $s['ids']])) ?>"><?= e(t('Nastavení')) ?></a> ·
		<a href="<?= e($module->url('new', ['parent' => $s['ids']])) ?>" title="<?= e(t('New page under this one')) ?>"><?= e(t('Subpage')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('duplicate')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace" type="submit"><?= e(t('Duplicate')) ?></button></form>
<?php if ((int) $s['ids'] !== $home): ?> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Move the page to the trash? It disappears from the site; you can restore it for 30 days.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?= $app->view->render('admin/bulk_actions', ['csrf' => $csrf, 'action' => $module->url('bulk'), 'siteLanguages' => $siteLanguages, 'actions' => [
    'zobrazit' => t('Publish'), 'skryt' => t('Hide'), 'kos' => t('Move to trash')]]) ?>
<?php endif ?>
