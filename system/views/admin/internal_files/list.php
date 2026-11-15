<?php
/**
 * Internal files (3.42): the folders the person sees as tabs, the files of one or all of them, upload into the chosen one.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\InternalFiles $module
 * @var string $csrf
 * @var list<array{id: int, name: string, description: string, audience: string, roles: list<int>, files: int}> $folders
 * @var array{id: int, name: string, description: string, audience: string, roles: list<int>, files: int}|null $current
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
?>
<p class="hlaska"><?= e(t('Documents for the team – never public: they are not on the site, in its search, exports or backups of media, and only signed-in people who see the folder can download them.')) ?></p>
<?php if ($folders === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'interni', 'heading' => $admin ? t('No folders yet.') : t('No folder is shared with you yet.'),
    'text' => $admin ? t('Create a folder first, then decide who sees it: everyone with Internal files, or only chosen roles.') : t('An administrator shares folders with everyone who has Internal files, or with chosen roles.'),
    'action' => $admin ? [$module->url('folder'), t('New folder')] : null]) ?>
<?php else: ?>
<nav class="zalozky" aria-label="<?= e(t('Folders')) ?>">
	<a href="<?= e($module->url('', $filters)) ?>"<?= $current === null ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('All folders')) ?></a>
<?php foreach ($folders as $f): ?>
	<a href="<?= e($module->url('', ['folder' => $f['id']] + $filters)) ?>"<?= $current !== null && $current['id'] === $f['id'] ? ' class="aktivni" aria-current="true"' : '' ?>><?= e($f['name']) ?> (<?= (int) $f['files'] ?>)<?= $f['audience'] === 'roles' ? ' <span class="stitek">' . e(t('roles only')) . '</span>' : '' ?></a>
<?php endforeach ?>
</nav>
<?php if ($current !== null && ($current['description'] !== '' || $admin)): ?>
<p class="smltxt"><?= e($current['description']) ?><?php if ($admin): ?> <a href="<?= e($module->url('folder', ['id' => $current['id']])) ?>"><?= e(t('Edit the folder')) ?></a><?php endif ?></p>
<?php endif ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="module" value="<?= e(InternalFiles::SECTION) ?>"><?php if ($query['folder'] > 0): ?><input type="hidden" name="folder" value="<?= (int) $query['folder'] ?>"><?php endif ?>
	<label><?= e(t('Search (name, description, tags):')) ?> <input class="textpole" type="search" name="search" value="<?= e($query['search']) ?>" size="24"></label>
	<label><?= e(t('Status')) ?> <select name="status"><option value=""><?= e(t('All')) ?></option><?php foreach (InternalFiles::STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $query['status'] === $k ? ' selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach ?></select></label>
	<?php if ($query['tag'] !== ''): ?><input type="hidden" name="tag" value="<?= e($query['tag']) ?>"><?php endif ?>
	<input class="tl" type="submit" value="<?= e(t('Filter')) ?>">
</form>
<?php if ($query['tag'] !== ''): ?>
<p class="smltxt"><?= e(t('Tag: %s', $query['tag'])) ?> · <a href="<?= e($module->url('', array_diff_key($here, ['tag' => '']))) ?>"><?= e(t('Clear filter')) ?></a></p>
<?php endif ?>
<?php if ($current !== null): ?>
<details<?= $result['total'] === 0 && $filters === [] ? ' open' : '' ?>>
<summary><?= e(t('Upload a file to %s', $current['name'])) ?></summary>
<form class="formular" method="post" action="<?= e($module->url('upload')) ?>" enctype="multipart/form-data"><?= $csrf ?>
<input type="hidden" name="folder_id" value="<?= (int) $current['id'] ?>">
<div class="radek"><label for="if-file"><?= e(t('File')) ?></label><div><input id="if-file" name="file" type="file" required accept="<?= e(implode(',', array_map(fn (string $x): string => '.' . $x, array_keys(InternalFiles::TYPES)))) ?>">
<span class="napoveda"><?= e(t('Documents, spreadsheets, presentations, PDF, images, text and Markdown, archives, audio and video – %s at most.', $limit)) ?></span></div></div>
<div class="radek"><label for="if-description"><?= e(t('Description')) ?></label><div><textarea class="textpole siroke" id="if-description" name="description" rows="2" maxlength="5000"></textarea></div></div>
<div class="radek"><label for="if-tags"><?= e(t('Tags')) ?></label><div><input class="textpole siroke" id="if-tags" name="tags" maxlength="500" placeholder="<?= e(t('e.g. contract, 2026, HR')) ?>"><span class="napoveda"><?= e(t('Separated by commas.')) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Upload')) ?>"></p>
</form>
</details>
<?php elseif ($admin || count($folders) > 0): ?>
<p class="smltxt"><?= e(t('Choose a folder to upload a file into it.')) ?></p>
<?php endif ?>
<?php if ($admin): ?>
<p><a class="navigace" href="<?= e($module->url('folder')) ?>"><?= e(t('New folder')) ?></a></p>
<?php endif ?>
<?php if ($result['rows'] === [] && $filters !== []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'interni', 'heading' => t('No file matches the filter.'), 'text' => t('Try another word, tag or folder.'), 'action' => [$module->url('', array_filter(['folder' => $query['folder']])), t('Clear filter')]]) ?>
<?php elseif ($result['rows'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'interni', 'heading' => t('No files here yet.'), 'text' => t('Upload price sheets, contracts, manuals or templates – only people who see the folder can open them.'), 'action' => null]) ?>
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
