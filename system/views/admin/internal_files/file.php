<?php
/**
 * One internal file (3.42): its name, description, tags, folder and status, the versions with a download each, a new
 * version, Delete. 3.47 (U66) on the record template: one bar with the way back, Preview (a raster image or a text
 * document), Download and More (Delete – out of the Save form); the form and the new version in the main column, the facts
 * and the versions at the side.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\InternalFiles $module
 * @var string $csrf
 * @var array<string, mixed> $file
 * @var list<array{id: int, name: string, description: string, audience: string, roles: list<int>, departments: list<int>, files: int}> $folders
 * @var bool $mayDelete
 * @var string $limit
 */
use Kaleta\Core\Files;
use Kaleta\Core\InternalFiles;

$preview = $module::previewKind($file);
?>
<div class="zaznam-lista" data-record-bar>
<a class="navigace" href="<?= e($module->url('', ['folder' => $file['folder_id']])) ?>">← <?= e($file['folder_name']) ?></a>
<div class="zaznam-akce">
<?php if ($preview !== null): ?>
	<a class="navigace" href="<?= e($module->url('preview', ['id' => $file['id']])) ?>" target="_blank" rel="noopener" data-internal-preview><?= e(t('Preview')) ?></a>
<?php endif ?>
	<a class="tl" href="<?= e($module->url('download', ['id' => $file['id']])) ?>" data-internal-download><?= e(t('Download')) ?></a>
<?php if ($mayDelete): ?>
	<details class="zaznam-vice" data-zavrit-mimo data-record-more><summary><?= e(t('More')) ?></summary><div class="zaznam-vice-menu">
		<form method="post" action="<?= e($module->url('delete')) ?>" data-internal-delete data-potvrdit="<?= e(t('Delete the file with all its versions? It cannot be brought back.')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $file['id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
	</div></details>
<?php endif ?>
</div>
</div>
<div class="zaznam-obal"><div class="zaznam">
<div class="zaznam-hlavni">
<?php if ($file['status'] === 'draft'): ?>
<p class="hlaska hlaska-varovani"><?= e(t('A draft – written through a Claude connection limited to drafts, or marked so by a colleague. Check it and mark it ready.')) ?></p>
<?php endif ?>
<?php if ($preview === 'image'): /* 3.47 (U66): loaded only when opened (a change-log line each) */ ?>
<details class="interni-nahled"><summary><?= e(t('Preview')) ?></summary><img src="<?= e($module->url('preview', ['id' => $file['id']])) ?>" alt="<?= e($file['name']) ?>" loading="lazy"></details>
<?php endif ?>
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
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save')) ?></button></p>
</form>
<h2><?= e(t('Replace with a new version')) ?></h2>
<form class="formular" method="post" action="<?= e($module->url('replace')) ?>" enctype="multipart/form-data"><?= $csrf ?>
<input type="hidden" name="id" value="<?= (int) $file['id'] ?>">
<div class="radek"><label for="if-new"><?= e(t('New version')) ?></label><div><input id="if-new" name="file" type="file" required accept="<?= e(implode(',', array_map(fn (string $x): string => '.' . $x, array_keys(InternalFiles::TYPES)))) ?>"> <button class="navigace" type="submit"><?= e(t('Upload')) ?></button>
<span class="napoveda"><?= e(t('The older versions stay and can still be downloaded. %s at most.', $limit)) ?></span></div></div>
</form>
</div>
<div class="zaznam-bok">
<section class="zaznam-panel" aria-labelledby="if-fakta" data-internal-facts>
<h2 id="if-fakta"><?= e(t('Details')) ?></h2>
<dl class="poptavka">
	<dt><?= e(t('Version')) ?></dt><dd><?= (int) $file['version'] ?> · <?= e(Files::size($file['size'])) ?> · <?= e(strtoupper($file['extension'])) ?></dd>
	<dt><?= e(t('Uploaded')) ?></dt><dd><?= e(t('Uploaded by %s, %s.', $file['created_by_name'] !== '' ? $file['created_by_name'] : '–', format_date($file['created_at'], true))) ?></dd>
</dl>
</section>
<section class="zaznam-panel" aria-labelledby="if-verze" data-internal-versions>
<h2 id="if-verze"><?= e(t('Versions')) ?></h2>
<ol class="interni-verze">
<?php foreach ($file['versions'] as $v): ?>
	<li><strong><?= e(t('Version %d', (int) $v['version'])) ?></strong><?= $v['version'] === $file['version'] ? ' <span class="stitek stitek-vydano">' . e(t('current')) . '</span>' : '' ?>
		<br><span class="interni-verze-nazev"><?= e($v['original_name']) ?></span>
		<br><span class="smltxt"><?= e(Files::size($v['size'])) ?> · <?= e(format_date($v['uploaded_at'], true)) ?><?= $v['uploaded_by'] !== '' ? ' · ' . e($v['uploaded_by']) : '' ?></span>
		<br><a href="<?= e($module->url('download', ['id' => $file['id'], 'version' => $v['version']])) ?>"><?= e(t('Download')) ?></a></li>
<?php endforeach ?>
</ol>
</section>
</div>
</div></div>
