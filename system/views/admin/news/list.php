<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\News $module
 * @var string $csrf
 * @var list<array<string, mixed>> $news
 * @var int $total
 * @var int $pageNumber
 * @var int $pageCount
 * @var list<array<string, mixed>> $category
 * @var array{category:int, language:string, search:string, status:string} $filter
 * @var list<string> $siteLanguages  language versions of the site (empty = the site has only one language)
 * @var int $inTrash  number of news items in the trash (within the signed-in user's scope)
 * @var int $toPublish  authors' drafts waiting to be published (seen by editors and administrators)
 */
$trash = $filter['status'] === 'kos';
$pageUrl = fn (int $s): string => $module->url('', array_filter($filter) + ['page' => $s]);
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New news item')) ?></a>
<?php if ($app->auth()->hasModule('categories')): ?>
	<a class="navigace" href="<?= e($app->url('admin.php?module=categories')) ?>"><?= e(t('Categories')) ?></a>
<?php endif ?>
<?php if ($app->auth()->hasModule('tags')): ?>
	<a class="navigace" href="<?= e($app->url('admin.php?module=tags')) ?>"><?= e(t('Tags')) ?></a>
<?php endif ?>
	<a class="navigace" href="<?= e($module->url('links')) ?>"><?= e(t('Broken links')) ?></a></p>

<nav class="zalozky" aria-label="<?= e(t('News status')) ?>">
<?php foreach (['' => 'Všechny', 'vydane' => 'Vydané', 'plan' => 'Scheduled', 'koncepty' => 'Drafts'] as $key => $name): ?>
	<a href="<?= e($module->url('', array_filter(['status' => $key]))) ?>"<?= $filter['status'] === $key ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
<?php if ($toPublish > 0 || $filter['status'] === 'ke_vydani'): ?>
	<a href="<?= e($module->url('', ['status' => 'ke_vydani'])) ?>"<?= $filter['status'] === 'ke_vydani' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Awaiting publication')) ?> (<?= $toPublish ?>)</a>
<?php endif ?>
<?php if ($inTrash > 0 || $trash): ?>
	<a href="<?= e($module->url('', ['status' => 'kos'])) ?>"<?= $trash ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Trash')) ?> (<?= $inTrash ?>)</a>
<?php endif ?>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="module" value="news">
	<input type="hidden" name="status" value="<?= e($filter['status']) ?>">
<?php if (count($category) > 1): ?>
	<label><?= e(t('Category:')) ?>
		<select name="category">
			<option value="0"><?= e(t('všechny')) ?></option>
<?php foreach ($category as $k): ?>
			<option value="<?= (int) $k['idt'] ?>"<?= $filter['category'] === (int) $k['idt'] ? ' selected' : '' ?>><?= e($k['nazev']) ?></option>
<?php endforeach ?>
		</select>
	</label>
<?php endif ?>
<?php if ($siteLanguages !== []): ?>
	<label><?= e(t('Language:')) ?>
		<select name="language">
			<option value=""><?= e(t('všechny')) ?></option>
<?php foreach ($siteLanguages as $code): ?>
			<option value="<?= e($code) ?>"<?= $filter['language'] === $code ? ' selected' : '' ?>><?= e(\Kaleta\Core\Language::AVAILABLE[$code][0]) ?></option>
<?php endforeach ?>
		</select>
	</label>
<?php endif ?>
	<label><?= e(t('Headline contains:')) ?> <input class="textpole" type="search" name="search" value="<?= e($filter['search']) ?>" size="20"></label>
	<input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>">
	(<?= e(t('Total:')) ?> <?= $total ?>)
</form>
<br>

<?php if ($news === [] && $trash): ?>
<?= $app->view->render('admin/empty', ['icon' => 'clanek', 'heading' => t('The trash is empty.'), 'text' => t('Deleted news items stay here for 30 days, then they are deleted permanently.'), 'action' => [$module->url(), t('Back to news')]]) ?>
<?php elseif ($news === []): ?>
<?php if (array_filter($filter) !== []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'clanek', 'heading' => t('No news item matches the filter.'), 'text' => t('Try another word, category or status.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php else: ?>
<?= $app->view->render('admin/empty', ['icon' => 'clanek', 'heading' => t('There are no news items yet.'), 'text' => t('Until you publish a news item, it stays a draft that nobody sees on the site.'), 'action' => [$module->url('new'), t('Write the first news item')]]) ?>
<?php endif ?>
<?php elseif ($trash): ?>
<p class="smltxt"><?= e(t('News items in the trash are not on the site. A restored news item comes back as a draft; after 30 days it is permanently deleted from the trash.')) ?></p>
<form method="post" id="obnov-jeden" action="<?= e($module->url('restore')) ?>"><?= $csrf ?></form>
<form method="post" action="<?= e($module->url('restore')) ?>">
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis">
<thead>
<tr><th scope="col"><?= e(t('Titulek')) ?></th><th scope="col"><?= e(t('Categories')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><?= e(t('Actions')) ?></th><th scope="col" class="stred"><?= e(t('Select')) ?></th></tr>
</thead>
<tbody>
<?php foreach ($news as $c): ?>
<tr class="nevydany">
	<td><?= e($c['titulek']) ?></td>
	<td><?= e($c['tema_jm']) ?></td>
	<td class="cislo"><?= e(format_date($c['smazano'], true)) ?></td>
	<td class="akce"><button class="navigace" type="submit" form="obnov-jeden" name="smaz[]" value="<?= (int) $c['idc'] ?>"><?= e(t('Restore')) ?></button></td>
	<td class="stred"><input type="checkbox" name="smaz[]" value="<?= (int) $c['idc'] ?>" aria-label="<?= e(t('Select')) ?>: <?= e($c['titulek']) ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-hromadne">
	<?= e(t('With selected:')) ?>
	<input class="tl" type="submit" value="<?= e(t('Restore')) ?>">
<?php if ($app->auth()->canPublish()): ?>
	<button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('delete_permanently')) ?>" data-potvrdit="<?= e(t('Delete the selected news items permanently? This cannot be undone.')) ?>"><?= e(t('Delete permanently')) ?></button>
<?php endif ?>
</p>
</form>
<?php else: ?>
<form method="post" action="<?= e($module->url('delete')) ?>">
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis">
<thead>
<tr><th scope="col"><?= e(t('Titulek')) ?></th><th scope="col"><?= e(t('Categories')) ?></th><th scope="col"><?= e(t('Author')) ?></th><th scope="col"><?= e(t('Publish date')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th><th scope="col"><?= e(t('Select')) ?></th></tr>
</thead>
<tbody>
<?php foreach ($news as $c): ?>
<tr<?= $c['visible'] ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($module->url('edit', ['id' => $c['idc']])) ?>"><?= e($c['titulek']) ?></a><?= $c['valid_until'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Hides itself the day after.')) . '">' . e(t('true until %s', format_date($c['valid_until']))) . '</span>' : '' ?><?= $c['review_by'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Asks for a review on this day.')) . '">' . e(t('review by %s', format_date($c['review_by']))) . '</span>' : '' ?></td>
	<td><?= e($c['tema_jm']) ?></td>
	<td><?= e($c['autor_jm'] ?: $c['autor_login']) ?></td>
	<td class="cislo"><?= e(format_date($c['datum'], true)) ?></td>
<?php if (!$c['visible'] && (int) $c['autor_uroven'] === 0 && $app->auth()->canPublish()): // an author's draft: waiting for an editor to publish it ?>
	<td><span class="stitek stitek-ceka" title="<?= e(t('News authors cannot publish – review this news item and publish it.')) ?>"><?= e(t('awaiting publication')) ?></span></td>
<?php else: ?>
	<td><span class="stitek stitek-<?= !$c['visible'] ? 'koncept' : (strtotime($c['datum']) > time() ? 'plan' : 'vydano') ?>"><?= e(t(!$c['visible'] ? 'koncept' : (strtotime($c['datum']) > time() ? 'naplánováno' : 'vydáno'))) ?></span></td>
<?php endif ?>
	<td class="akce"><a href="<?= e($module->url('edit', ['id' => $c['idc']])) ?>"><?= e(t('Edit')) ?></a> · <a href="<?= e($app->url('novinky/' . $c['seo_link'] . '?preview=1')) ?>" target="_blank" rel="noopener"><?= e(t('Preview')) ?></a> ·
<?php if ((int) $c['social_open'] > 0): // social post drafts not posted yet (2.13) ?>
		<a href="<?= e($module->url('edit', ['id' => $c['idc']])) ?>#social-posts" title="<?= e(t('Social post drafts waiting to be posted')) ?>"><?= e(t('Social posts')) ?> (<?= (int) $c['social_open'] ?>)</a> ·
<?php endif ?>
		<button class="navigace" type="submit" formaction="<?= e($module->url('duplicate')) ?>" name="idc" value="<?= (int) $c['idc'] ?>" formnovalidate><?= e(t('Duplicate')) ?></button></td>
	<td class="stred"><input type="checkbox" name="smaz[]" value="<?= (int) $c['idc'] ?>" aria-label="<?= e(t('Select')) ?>: <?= e($c['titulek']) ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-hromadne hromadne">
	<label><?= e(t('With selected:')) ?>
	<select name="provest">
<?php if ($app->auth()->canPublish()): ?>
		<option value="vydat"><?= e(t('Publish')) ?></option>
<?php endif ?>
		<option value="koncept"><?= e(t('Back to draft')) ?></option>
		<option value="kategorie"><?= e(t('Category…')) ?></option>
		<option value="kos"><?= e(t('Move to trash')) ?></option>
	</select></label>
	<select name="kategorie" aria-label="<?= e(t('Category')) ?>">
<?php foreach ($category as $t): ?>
		<option value="<?= (int) $t['idt'] ?>"><?= e($t['nazev']) ?><?= $t['jazyk'] !== '' ? ' (' . e(strtoupper($t['jazyk'])) . ')' : '' ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit" formaction="<?= e($module->url('bulk')) ?>" data-potvrdit="<?= e(t('Apply the action to the selected items?')) ?>"><?= e(t('Apply')) ?></button>
	<button class="navigace nebezpecne" type="submit"><?= e(t('Delete selected')) ?></button>
</p>
</form>

<?php if ($pageCount > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($pageUrl($s)) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
