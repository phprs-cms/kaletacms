<?php
/**
 * One internal file (3.42): its name, description, tags, folder and status, the versions with a download each, a new
 * version, Delete.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\InternalFiles $module
 * @var string $csrf
 * @var array<string, mixed> $file
 * @var list<array{id: int, name: string, description: string, audience: string, roles: list<int>, files: int}> $folders
 * @var bool $mayDelete
 * @var string $limit
 */
use Kaleta\Core\Files;
use Kaleta\Core\InternalFiles;
?>
<p><a href="<?= e($module->url('', ['folder' => $file['folder_id']])) ?>">← <?= e($file['folder_name']) ?></a></p>
<?php if ($file['status'] === 'draft'): ?>
<p class="hlaska hlaska-varovani"><?= e(t('A draft – written through a Claude connection limited to drafts, or marked so by a colleague. Check it and mark it ready.')) ?></p>
<?php endif ?>
<p class="tlacitka"><a class="tl" href="<?= e($module->url('download', ['id' => $file['id']])) ?>"><?= e(t('Download')) ?></a> <span class="smltxt"><?= e(t('Version %d', $file['version'])) ?> · <?= e(Files::size($file['size'])) ?> · <?= e(strtoupper($file['extension'])) ?></span></p>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>"><?= $csrf ?>
<input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
<fieldset>
<legend><?= e(t('File')) ?></legend>
<div class="radek"><label for="if-name"><?= e(t('Name')) ?></label><div><input class="textpole siroke" id="if-name" name="name" required maxlength="200" value="<?= e($file['name']) ?>"></div></div>
<div class="radek"><label for="if-description"><?= e(t('Description')) ?></label><div><textarea class="textpole siroke" id="if-description" name="description" rows="3" maxlength="5000"><?= e($file['description']) ?></textarea></div></div>
<div class="radek"><label for="if-tags"><?= e(t('Tags')) ?></label><div><input class="textpole siroke" id="if-tags" name="tags" maxlength="500" value="<?= e(implode(', ', $file['tags'])) ?>"><span class="napoveda"><?= e(t('Separated by commas.')) ?></span></div></div>
<div class="radek"><label for="if-folder"><?= e(t('Folder')) ?></label><div><select id="if-folder" name="folder_id">
<?php foreach ($folders as $f): ?><option value="<?= (int) $f['id'] ?>"<?= $f['id'] === $file['folder_id'] ? ' selected' : '' ?>><?= e($f['name']) ?></option><?php endforeach ?>
</select></div></div>
<div class="radek"><label for="if-status"><?= e(t('Status')) ?></label><div><select id="if-status" name="status">
<?php foreach (InternalFiles::STATUSES as $k => $label): ?><option value="<?= e($k) ?>"<?= $file['status'] === $k ? ' selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach ?>
</select></div></div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save')) ?>">
<?php if ($mayDelete): ?> <button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the file with all its versions? It cannot be brought back.')) ?>"><?= e(t('Delete')) ?></button><?php endif ?></p>
</form>
<h2><?= e(t('Replace with a new version')) ?></h2>
<form class="formular" method="post" action="<?= e($module->url('replace')) ?>" enctype="multipart/form-data"><?= $csrf ?>
<input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
<div class="radek"><label for="if-new"><?= e(t('New version')) ?></label><div><input id="if-new" name="file" type="file" required accept="<?= e(implode(',', array_map(fn (string $x): string => '.' . $x, array_keys(InternalFiles::TYPES)))) ?>"> <button class="navigace" type="submit"><?= e(t('Upload')) ?></button>
<span class="napoveda"><?= e(t('The older versions stay and can still be downloaded. %s at most.', $limit)) ?></span></div></div>
</form>
<h2><?= e(t('Versions')) ?></h2>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Version')) ?></th><th scope="col"><?= e(t('Uploaded file')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><?= e(t('Uploaded')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($file['versions'] as $v): ?>
<tr>
	<td class="cislo"><?= (int) $v['version'] ?><?= $v['version'] === $file['version'] ? ' <span class="stitek stitek-vydano">' . e(t('current')) . '</span>' : '' ?></td>
	<td><?= e($v['original_name']) ?></td>
	<td class="cislo"><?= e(Files::size($v['size'])) ?></td>
	<td class="cislo"><?= e(format_date($v['uploaded_at'], true)) ?><?= $v['uploaded_by'] !== '' ? '<br><span class="smltxt">' . e($v['uploaded_by']) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($module->url('download', ['id' => $file['id'], 'version' => $v['version']])) ?>"><?= e(t('Download')) ?></a></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="smltxt"><?= e(t('Uploaded by %s, %s.', $file['created_by_name'] !== '' ? $file['created_by_name'] : '–', format_date($file['created_at'], true))) ?></p>
