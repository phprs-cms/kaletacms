<?php
/**
 * Internal files (3.42): the folders the person sees, the files of one or all of them, upload. 3.47 (U66): one head row –
 * the folders on the left (more than five are one select at every width), Upload files (a <details> that looks like a
 * button, no script) and New folder on the right; the shared filter bar (a word, the status, the tag, Clear filter);
 * several files at once, from "All folders" with a folder to choose.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\InternalFiles $module
 * @var string $csrf
 * @var list<array{id: int, name: string, description: string, audience: string, roles: list<int>, departments: list<int>, files: int}> $folders
 * @var array{id: int, name: string, description: string, audience: string, roles: list<int>, departments: list<int>, files: int}|null $current
 * @var array{folder: int, search: string, tag: string, status: string} $query
 * @var array{rows: list<array<string, mixed>>, total: int} $result
 * @var int $page
 * @var int $pages
 * @var bool $admin
 * @var string $limit
 */
use Kaleta\Core\Files;
use Kaleta\Core\InternalFiles;

$filters = array_filter(['search' => $query['search'], 'tag' => $query['tag'], 'status' => $query['status']]);
$here = array_filter(['folder' => $query['folder']]) + $filters;
$never = t('Documents for the team – never public: they are not on the site, in its search, exports or backups of media, and only signed-in people who see the folder can download them.');
$audience = fn (array $f): string => $f['audience'] === 'roles' ? t('roles only') : ($f['audience'] === 'depts' ? t('departments only') : '');
?>
<?php if ($folders === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'interni', 'heading' => $admin ? t('No folders yet.') : t('No folder is shared with you yet.'),
    'text' => ($admin ? t('Create a folder first, then decide who sees it: everyone with Internal files, or only chosen roles.') : t('An administrator shares folders with everyone who has Internal files, or with chosen roles.')) . ' ' . $never,
    'action' => $admin ? [$module->url('folder'), t('New folder')] : null]) ?>
<?php else: ?>
<div class="zakaznici-hlava" data-internal-head>
<?php if (count($folders) > 5): /* 3.47 (U66): many folders are one select at every width */ ?>
<form class="zalozky-vyber slozky-vyber" method="get" action="<?= e($app->url('admin.php')) ?>"><input type="hidden" name="module" value="<?= e(InternalFiles::SECTION) ?>">
<?php foreach ($filters as $name => $value): ?>
	<input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
<?php endforeach ?>
	<label for="slozka-vyber"><?= e(t('Folder')) ?></label>
	<select id="slozka-vyber" name="folder" data-odeslat-pri-zmene>
		<option value="0"><?= e(t('All folders')) ?></option>
<?php foreach ($folders as $f): ?>
		<option value="<?= (int) $f['id'] ?>"<?= $current !== null && $current['id'] === $f['id'] ? ' selected' : '' ?>><?= e($f['name']) ?> (<?= (int) $f['files'] ?>)<?= $audience($f) !== '' ? ' – ' . e($audience($f)) : '' ?></option>
<?php endforeach ?>
	</select>
	<noscript><button class="navigace" type="submit"><?= e(t('Show')) ?></button></noscript>
</form>
<?php else: ?>
<nav class="zalozky" aria-label="<?= e(t('Folders')) ?>">
	<a href="<?= e($module->url('', $filters)) ?>"<?= $current === null ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('All folders')) ?></a>
<?php foreach ($folders as $f): ?>
	<a href="<?= e($module->url('', ['folder' => $f['id']] + $filters)) ?>"<?= $current !== null && $current['id'] === $f['id'] ? ' class="aktivni" aria-current="true"' : '' ?>><?= e($f['name']) ?> (<?= (int) $f['files'] ?>)<?= $audience($f) !== '' ? ' <span class="stitek">' . e($audience($f)) . '</span>' : '' ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<div class="zakaznici-akce interni-akce">
	<details class="interni-nahrat" data-internal-upload<?= $result['total'] === 0 && $filters === [] ? ' open' : '' ?>>
	<summary class="tl"><?= e(t('Upload files')) ?></summary>
	<form class="formular" method="post" action="<?= e($module->url('upload')) ?>" enctype="multipart/form-data"><?= $csrf ?>
<?php if ($current !== null): ?>
	<input type="hidden" name="folder_id" value="<?= (int) $current['id'] ?>">
	<p class="smltxt"><?= e(t('Upload a file to %s', $current['name'])) ?></p>
<?php else: /* 3.47 (U66): from "All folders" the folder is chosen here */ ?>
	<div class="radek"><label for="if-folder"><?= e(t('Folder')) ?></label><div><select id="if-folder" name="folder_id" required>
<?php foreach ($folders as $f): ?>
		<option value="<?= (int) $f['id'] ?>"><?= e($f['name']) ?></option>
<?php endforeach ?>
	</select></div></div>
<?php endif ?>
	<div class="radek"><label for="if-file"><?= e(t('Files')) ?></label><div><input id="if-file" name="files[]" type="file" multiple required accept="<?= e(implode(',', array_map(fn (string $x): string => '.' . $x, array_keys(InternalFiles::TYPES)))) ?>">
	<span class="napoveda"><?= e(t('Documents, spreadsheets, presentations, PDF, images, text and Markdown, archives, audio and video – %s at most.', $limit)) ?></span></div></div>
	<div class="radek"><label for="if-description"><?= e(t('Description')) ?></label><div><textarea class="textpole siroke" id="if-description" name="description" rows="2" maxlength="5000"></textarea></div></div>
	<div class="radek"><label for="if-tags"><?= e(t('Tags')) ?></label><div><input class="textpole siroke" id="if-tags" name="tags" maxlength="500" placeholder="<?= e(t('e.g. contract, 2026, HR')) ?>"><span class="napoveda"><?= e(t('Separated by commas.')) ?></span></div></div>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Upload')) ?></button></p>
	<p class="napoveda"><?= e($never) ?></p>
	</form>
	</details>
<?php if ($admin): ?>
	<a class="navigace" href="<?= e($module->url('folder')) ?>"><?= e(t('New folder')) ?></a>
<?php endif ?>
</div>
</div>
<?php if ($current !== null && ($current['description'] !== '' || $admin)): ?>
<p class="smltxt"><?= e($current['description']) ?><?php if ($admin): ?> <a href="<?= e($module->url('folder', ['id' => $current['id']])) ?>"><?= e(t('Edit the folder')) ?></a><?php endif ?></p>
<?php endif ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr" data-internal-filter>
	<input type="hidden" name="module" value="<?= e(InternalFiles::SECTION) ?>"><?php if ($query['folder'] > 0): ?><input type="hidden" name="folder" value="<?= (int) $query['folder'] ?>"><?php endif ?>
	<label class="zakaznici-hledat"><span><?= e(t('Search')) ?></span> <input class="textpole" type="search" name="search" value="<?= e($query['search']) ?>" placeholder="<?= e(t('Name, description or tag')) ?>"></label>
	<label><span><?= e(t('Status')) ?></span> <select name="status"><option value=""><?= e(t('All')) ?></option><?php foreach (InternalFiles::STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $query['status'] === $k ? ' selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach ?></select></label>
<?php if ($query['tag'] !== ''): ?>
	<input type="hidden" name="tag" value="<?= e($query['tag']) ?>"><span class="stitek"><?= e(t('Tag: %s', $query['tag'])) ?></span>
<?php endif ?>
	<button class="navigace" type="submit"><?= e(t('Filter')) ?></button>
<?php if ($filters !== []): ?>
	<a class="navigace" href="<?= e($module->url('', array_filter(['folder' => $query['folder']]))) ?>"><?= e(t('Clear filter')) ?></a>
<?php endif ?>
</form>
<?php if ($result['rows'] === [] && $filters !== []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'interni', 'heading' => t('No file matches the filter.'), 'text' => t('Try another word, tag or folder.'), 'action' => [$module->url('', array_filter(['folder' => $query['folder']])), t('Clear filter')]]) ?>
<?php elseif ($result['rows'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'interni', 'heading' => t('No files here yet.'), 'text' => t('Upload price sheets, contracts, manuals or templates – only people who see the folder can open them.') . ' ' . $never, 'action' => null]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><?php if ($current === null): ?><th scope="col"><?= e(t('Folder')) ?></th><?php endif ?><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><?= e(t('Changed')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($result['rows'] as $f): ?>
<tr>
	<td><a href="<?= e($module->url('file', ['id' => $f['id']])) ?>"><strong><?= e($f['name']) ?></strong></a><?= $f['status'] === 'draft' ? ' <span class="stitek">' . e(t('draft')) . '</span>' : '' ?><?= $f['version'] > 1 ? ' <span class="smltxt">' . e(t('version %d', $f['version'])) . '</span>' : '' ?>
<?php if ($f['description'] !== ''): ?><br><span class="smltxt"><?= e(mb_strimwidth($f['description'], 0, 160, '…')) ?></span><?php endif ?>
<?php if ($f['tags'] !== []): ?><br><?php foreach ($f['tags'] as $tag): ?><a class="stitek" href="<?= e($module->url('', array_filter(['folder' => $query['folder']]) + ['tag' => $tag])) ?>"><?= e($tag) ?></a> <?php endforeach ?><?php endif ?></td>
<?php if ($current === null): ?><td><a href="<?= e($module->url('', ['folder' => $f['folder_id']])) ?>"><?= e($f['folder_name']) ?></a></td><?php endif ?>
	<td class="cislo"><?= e(Files::size($f['size'])) ?></td>
	<td class="cislo"><?= e(format_date($f['updated_at'], true)) ?><?= $f['updated_by_name'] !== '' ? '<br><span class="smltxt">' . e($f['updated_by_name']) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($module->url('download', ['id' => $f['id']])) ?>"><?= e(t('Download')) ?></a></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $pages; $s++): ?>
	<?= $s === $page ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', $here + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
<?php endif ?>
