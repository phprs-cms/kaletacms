<?php
/**
 * Media: folders and filters on the left, upload and the image grid on the right.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Media $module
 * @var string $csrf
 * @var list<array<string, mixed>> $images
 * @var int $pageNumber
 * @var int $pageCount
 * @var int $total
 * @var string $limit
 * @var array{section: ?int, article: int, unused: bool, search: string, sort: string} $filter
 * @var list<array<string, mixed>> $folders
 * @var string|null $newsItem  title of the news item used as the filter
 */
$activeFolder = null;
foreach ($folders as $s) {
    if ((int) $s['ids'] === $filter['section']) {
        $activeFolder = $s;
    }
}
$params = array_filter(['section' => $filter['section'], 'article' => $filter['article'] ?: null, 'unused' => $filter['unused'] ? 1 : null,
    'search' => $filter['search'] !== '' ? $filter['search'] : null, 'sort' => $filter['sort'] !== 'nove' ? $filter['sort'] : null], fn ($v): bool => $v !== null);
$isAll = $filter['section'] === null && $filter['article'] === 0 && !$filter['unused'];
?>
<div class="media">
<nav class="media-slozky" aria-label="<?= e(t('Folders')) ?>">
	<a href="<?= e($module->url()) ?>"<?= $isAll ? ' class="aktivni"' : '' ?>><?= e(t('All media')) ?></a>
	<a href="<?= e($module->url('', ['section' => 0])) ?>"<?= $filter['section'] === 0 ? ' class="aktivni"' : '' ?>><?= e(t('Nezařazené')) ?></a>
	<a href="<?= e($module->url('', ['unused' => 1])) ?>"<?= $filter['unused'] ? ' class="aktivni"' : '' ?>><?= e(t('Unused')) ?></a>
	<a href="<?= e($module->url('cleanup')) ?>"><?= e(t('Clean-up')) ?></a>
	<strong><?= e(t('Folders')) ?></strong>
<?php foreach ($folders as $s): ?>
	<a href="<?= e($module->url('', ['section' => $s['ids']])) ?>"<?= $activeFolder === $s ? ' class="aktivni"' : '' ?>><?= e($s['nazev']) ?> <small>(<?= (int) $s['pocet'] ?>)</small></a>
<?php endforeach ?>
	<form method="post" action="<?= e($module->url('folder')) ?>">
		<?= $csrf ?>
		<input class="textpole" type="text" name="nazev" placeholder="<?= e(t('new folder')) ?>" maxlength="100" required aria-label="<?= e(t('New folder name')) ?>">
		<button class="navigace" type="submit"><?= e(t('Přidat')) ?></button>
	</form>
</nav>

<div class="media-obsah">
<?php if ($newsItem !== null): ?>
<p class="hlaska"><?= e(t('Images used in the news item “%s”.', $newsItem)) ?> <a href="<?= e($module->url()) ?>"><?= e(t('Show all media')) ?></a></p>
<?php endif ?>
<?php if ($activeFolder !== null): ?>
<div class="media-slozka-uprava">
	<form method="post" action="<?= e($module->url('folder')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $activeFolder['ids'] ?>"><input class="textpole" type="text" name="nazev" value="<?= e($activeFolder['nazev']) ?>" maxlength="100" required aria-label="<?= e(t('Folder name')) ?>"> <button class="navigace" type="submit"><?= e(t('Rename')) ?></button></form>
<?php if ($app->auth()->isAdmin()): ?>
	<form method="post" action="<?= e($module->url('folder_delete')) ?>" data-potvrdit="<?= e(t('Delete the folder? Its images will be kept and become uncategorized.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $activeFolder['ids'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete folder')) ?></button></form>
<?php endif ?>
</div>
<?php endif ?>

<form class="nahravani" method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>" data-nahravani>
	<?= $csrf ?>
	<input type="hidden" name="sekce" value="<?= (int) ($activeFolder['ids'] ?? 0) ?>">
	<label for="soubory"><strong><?= e(t('Upload images and attachments')) ?><?= $activeFolder !== null ? ' – ' . e($activeFolder['nazev']) : '' ?></strong> <?= e(t('– select files, or drag them here')) ?></label>
	<input type="file" id="soubory" name="soubory[]" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml,.svg,<?= e('.' . implode(',.', Kaleta\Core\Files::FILE_EXTENSIONS)) ?>" multiple required>
	<input class="tl" type="submit" value="<?= e(t('Upload')) ?>">
	<span class="napoveda"><?= e(t('JPG, PNG, WebP and GIF images as well as downloadable attachments (PDF, documents, spreadsheets, ZIP, audio, video), up to %s per file. Large photos are scaled down to %s px and location data is removed.', $limit, Kaleta\Core\Images::MAX_SIDE)) ?></span>
</form>

<form class="navigace-radek media-hledani" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="module" value="media">
<?php foreach (array_diff_key($params, ['search' => 1, 'sort' => 1]) as $k => $v): ?>
	<input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>">
<?php endforeach ?>
	<input class="textpole" type="search" name="search" value="<?= e($filter['search']) ?>" placeholder="<?= e(t('Search name, description or file')) ?>" aria-label="<?= e(t('Search media')) ?>">
	<select name="sort" aria-label="<?= e(t('Řazení')) ?>" data-odeslat-pri-zmene>
<?php foreach (Kaleta\Admin\Modules\Media::SORT_ORDERS as $key => [$sortName]): ?>
		<option value="<?= e($key) ?>"<?= $filter['sort'] === $key ? ' selected' : '' ?>><?= e(t($sortName)) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit"><?= e(t('Filtrovat')) ?></button>
</form>
<?php if ($images === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'media', 'heading' => t('No images.'), 'text' => t('Upload your first photos with the form above – or drag them straight into the text in the editor.')]) ?>
<?php else: ?>
<form method="post" action="<?= e($module->url('bulk')) ?>">
<?= $csrf ?>
<div class="galerie-mrizka">
<?php foreach ($images as $o): ?>
	<figure class="galerie-polozka">
<?php if ($o['nahl_poloha'] === ''): ?>
		<a class="galerie-soubor" href="<?= e($app->url($o['obr_poloha'])) ?>" target="_blank" rel="noopener"><span><?= e(strtoupper(pathinfo($o['obr_poloha'], PATHINFO_EXTENSION))) ?></span></a>
<?php else: ?>
		<a href="<?= e($app->url($o['obr_poloha'])) ?>" target="_blank" rel="noopener"><img src="<?= e($app->url($o['nahl_poloha'])) ?>" alt="<?= e($o['nazev']) ?>" loading="lazy" width="<?= (int) $o['nahl_width'] ?>" height="<?= (int) $o['nahl_height'] ?>"></a>
<?php endif ?>
		<figcaption>
			<strong title="<?= e($o['nazev']) ?>"><?= e($o['nazev'] !== '' ? $o['nazev'] : t('untitled')) ?></strong>
			<span><?= $o['nahl_poloha'] === '' ? '' : (int) $o['obr_width'] . '&times;' . (int) $o['obr_height'] . ' &middot; ' ?><?= e(Kaleta\Core\Files::size((int) $o['obr_vel'])) ?> &middot; <span<?= $o['kde'] !== [] ? ' title="' . e(t('Used in: %s', implode(', ', $o['kde']))) . '"' : '' ?>><?= e((int) $o['pouzito'] > 0 ? t('used %s×', (int) $o['pouzito']) : t('unused')) ?></span></span>
<?php if ($o['nahl_poloha'] !== ''): ?>
			<input class="galerie-popis" type="text" value="<?= e((string) $o['nazev']) ?>" maxlength="150" placeholder="<?= e(t('Popis pro nevidomé (alt)')) ?>" aria-label="<?= e(t('Description of image %s', $o['nazev'])) ?>" data-popis-media="<?= (int) $o['ido'] ?>" data-adresa="<?= e($module->url('save_caption')) ?>" form="">
<?php endif ?>
			<span><label><input type="checkbox" name="oznacene[]" value="<?= (int) $o['ido'] ?>"> <?= e(t('označit')) ?></label> &middot; <a href="<?= e($module->url('list', $params + ['edit' => $o['ido'], 'page' => $pageNumber])) ?>#uprav"><?= e(t('description')) ?></a></span>
		</figcaption>
	</figure>
<?php endforeach ?>
</div>
<p class="media-hromadne">
	<?= e(t('With selected:')) ?>
	<select name="do_sekce" aria-label="<?= e(t('Target folder')) ?>">
		<option value="0"><?= e(t('– uncategorized –')) ?></option>
<?php foreach ($folders as $s): ?>
		<option value="<?= (int) $s['ids'] ?>"><?= e($s['nazev']) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit" name="provest" value="presun"><?= e(t('Move to folder')) ?></button>
	<button class="navigace nebezpecne" type="submit" name="provest" value="smaz" data-potvrdit="<?= e(t('Really delete the selected files? Files the site still uses are skipped.')) ?>"><?= e(t('Smazat')) ?></button>
</p>
</form>

<?php foreach ($images as $o): if ((int) $o['ido'] !== $app->request->getInt('edit')) { continue; } ?>
<form class="formular" id="uprav" method="post" action="<?= e($module->url('save')) ?>">
	<?= $csrf ?>
	<input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>">
	<div class="radek"><label for="nazev"><?= e(t('Name (alternative text)')) ?></label><div><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($o['nazev']) ?>" maxlength="150"><span class="napoveda"><?= e(t('Describe what is in the image - screen readers and search engines read it.')) ?></span></div></div>
	<div class="radek"><label for="popis"><?= e(t('Caption below the image')) ?></label><input class="textpole siroke" type="text" id="popis" name="popis" value="<?= e($o['popis']) ?>" maxlength="500"></div>
	<div class="radek"><label for="autor"><?= e(t('Image credit')) ?></label><div><input class="textpole siroke" type="text" id="autor" name="autor" value="<?= e($o['autor'] ?? '') ?>" maxlength="120"><span class="napoveda"><?= e(t('Shown under a news item\'s main photo unless it has its own photo credit.')) ?></span></div></div>
<?php if ($o['nahl_poloha'] !== '' && !str_ends_with($o['obr_poloha'], '.svg')): [$ox, $oy] = array_map('intval', explode(' ', str_replace('%', '', $o['ohnisko'] ?: '50% 50%'))) + [1 => 50]; ?>
	<div class="radek"><span class="popisek"><?= e(t('Crop focal point')) ?></span><div>
		<div class="ohnisko" data-ohnisko><img src="<?= e($app->url($o['nahl_poloha'])) ?>" alt=""><span class="ohnisko-bod" style="left:<?= $ox ?>%;top:<?= $oy ?>%"></span></div>
		<label><?= e(t('Horizontal')) ?> <input class="textpole" type="number" name="ohnisko_x" min="0" max="100" value="<?= $ox ?>" size="3"> %</label>
		<label><?= e(t('Vertical')) ?> <input class="textpole" type="number" name="ohnisko_y" min="0" max="100" value="<?= $oy ?>" size="3"> %</label>
		<span class="napoveda"><?= e(t('Click in the preview on what must stay visible when the photo is cropped to another shape (card, section background).')) ?></span></div></div>
<?php endif ?>
	<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"></p>
</form>
<?php if (preg_match('/\.(jpg|png|webp)$/', $o['obr_poloha'])): ?>
<form class="formular" method="post" action="<?= e($module->url('replace')) ?>" enctype="multipart/form-data">
	<?= $csrf ?>
	<input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>">
	<div class="radek"><label for="soubor-nahrada"><?= e(t('Replace file')) ?></label><div><input type="file" id="soubor-nahrada" name="soubor" accept="image/jpeg,image/png,image/webp" required>
		<span class="napoveda"><?= e(t('The new photo appears everywhere the old one is used – the file address does not change.')) ?></span></div></div>
	<p class="tlacitka"><input class="navigace" type="submit" value="<?= e(t('Replace')) ?>"></p>
</form>
<?php endif ?>
<?php endforeach ?>

<?php if ($pageCount > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', $params + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
</div>
</div>
