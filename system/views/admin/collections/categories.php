<?php
/**
 * Categories of a collection (3.7): the tree, their pages, language versions and how many items each has.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var list<array<string, mixed>> $tree categories in display order (a top-level category followed by its subcategories)
 * @var array<int, list<string>> $translated category id => languages with texts ('' = the default)
 * @var list<string> $languages the site's other languages
 * @var array<string, string> $counts category id => items in it
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('category', ['id' => $k['idk']])) ?>"><?= e(t('Add category')) ?></a>
	<a class="navigace" href="<?= e($module->url('items', ['id' => $k['idk']])) ?>"><?= e(t('Items')) ?></a>
<?php if ($app->auth()->isAdmin()): ?>
	<a class="navigace" href="<?= e($module->url('builder', ['id' => $k['idk'], 'template' => 'kategorie'])) ?>"><?= e(t('Category page template')) ?></a>
<?php foreach ($languages as $language): ?>
	<a class="navigace" href="<?= e($module->url('builder', ['id' => $k['idk'], 'template' => 'kategorie', 'language' => $language])) ?>"><?= e(t('Category page template (%s)', strtoupper($language))) ?></a>
<?php endforeach ?>
<?php endif ?></p>
<?php if ($tree === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'kolekce', 'heading' => t('No categories yet.'), 'text' => t('Categories sort the items into groups with their own pages – /%s/category and /%s/category/subcategory – with a description, breadcrumbs and a list of the items. Tick the categories on each item.', $k['seo_link'], $k['seo_link']),
    'action' => [$module->url('category', ['id' => $k['idk']]), t('Add category')]]) ?>
<?php else: ?>
<p class="smltxt"><?= e(t('Two levels: a category and its subcategories. A category page lists the items of the category and of its subcategories; an address can never be both a category and an item of this collection.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Category')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Items')) ?></th><th scope="col"><?= e(t('Pořadí')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($tree as $c): $path = $k['seo_link'] . '/' . ($c['parent_slug'] !== '' ? $c['parent_slug'] . '/' : '') . $c['slug']; ?>
<tr<?= $c['visible'] ? '' : ' class="nevydany"' ?>>
	<td<?= $c['parent_id'] !== null ? ' class="podkategorie"' : '' ?>><?= $c['parent_id'] !== null ? '<span aria-hidden="true">↳ </span>' : '' ?><a href="<?= e($module->url('category', ['id' => $k['idk'], 'category' => $c['id']])) ?>"><?= e($c['name']) ?></a>
<?php foreach ($languages as $language): ?>
		<?php if (in_array($language, $translated[$c['id']] ?? [], true)): ?><a class="stitek" href="<?= e($module->url('category', ['id' => $k['idk'], 'category' => $c['id'], 'language' => $language])) ?>" title="<?= e(t('Edit the %s version', strtoupper($language))) ?>"><?= e(strtoupper($language)) ?></a><?php else: ?><a class="stitek stitek-koncept" href="<?= e($module->url('category', ['id' => $k['idk'], 'category' => $c['id'], 'language' => $language])) ?>" title="<?= e(t('Add the %s version', strtoupper($language))) ?>">+ <?= e(strtoupper($language)) ?></a><?php endif ?>
<?php endforeach ?>
	</td>
	<td><code>/<?= e($path) ?></code></td>
	<td class="cislo"><?= (int) ($counts[(string) $c['id']] ?? 0) ?></td>
	<td class="cislo"><?= (int) $c['sort_order'] ?></td>
	<td><span class="stitek stitek-<?= $c['visible'] ? 'vydano' : 'koncept' ?>"><?= e(t($c['visible'] ? 'zveřejněná' : 'skrytá')) ?></span></td>
	<td class="akce"><?php if ($c['visible']): ?><a href="<?= e($app->url($path)) ?>" target="_blank" rel="noopener"><?= e(t('Show')) ?></a> · <?php endif ?>
<?php if ($c['parent_id'] === null): ?>		<a href="<?= e($module->url('category', ['id' => $k['idk'], 'parent' => $c['id']])) ?>"><?= e(t('Add subcategory')) ?></a> · <?php endif ?>
		<a href="<?= e($module->url('item', ['id' => $k['idk'], 'category' => $c['id']])) ?>"><?= e(t('Add item')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete_category')) ?>" data-potvrdit="<?= e(t('Delete the category in every language? Its items stay in the collection; its page disappears.')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
