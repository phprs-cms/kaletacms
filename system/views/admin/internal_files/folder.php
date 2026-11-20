<?php
/**
 * A folder of internal files (3.42, administrators): its name, description and who sees it.
 *
 * @var Kaleta\Admin\Modules\InternalFiles $module
 * @var string $csrf
 * @var array{id: int, name: string, description: string, audience: string, roles: list<int>, departments: list<int>, files: int}|null $folder
 * @var array<int, string> $roles the custom roles, id => name
 * @var array<int, string> $departments the departments as a tree, id => indented name
 */
use Kaleta\Core\InternalFiles;

$isNew = $folder === null;
$audience = $folder['audience'] ?? 'all';
?>
<p><a href="<?= e($module->url('', $isNew ? [] : ['folder' => $folder['id']])) ?>">← <?= e(t('Internal files')) ?></a></p>
<form class="formular" method="post" action="<?= e($module->url('folder_save')) ?>"><?= $csrf ?>
<input type="hidden" name="id" value="<?= (int) ($folder['id'] ?? 0) ?>">
<fieldset>
<legend><?= e($isNew ? t('New folder') : t('Folder')) ?></legend>
<div class="radek"><label for="if-name"><?= e(t('Name')) ?></label><div><input class="textpole siroke" id="if-name" name="name" required maxlength="120" value="<?= e($folder['name'] ?? '') ?>" placeholder="<?= e(t('e.g. Contracts')) ?>"></div></div>
<div class="radek"><label for="if-description"><?= e(t('Description')) ?></label><div><input class="textpole siroke" id="if-description" name="description" maxlength="500" value="<?= e($folder['description'] ?? '') ?>"></div></div>
</fieldset>
<fieldset class="okruh-slozky"><?php // 3.47 (N166): the roles show only for "chosen roles", the departments only for "chosen departments" (CSS :has) ?>
<legend><?= e(t('Who sees the folder')) ?></legend>
<?php foreach (InternalFiles::AUDIENCES as $k => $label): ?>
<div class="radek"><label><input type="radio" name="audience" value="<?= e($k) ?>"<?= $audience === $k ? ' checked' : '' ?>> <?= e(t($label)) ?></label></div>
<?php endforeach ?>
<?php if ($roles === []): ?>
<p class="smltxt okruh-role"><?= e(t('No roles yet – create them in Administration → Roles to limit a folder to some people.')) ?></p>
<?php else: ?>
<div class="radek okruh-role"><span><?= e(t('Roles')) ?></span><div>
<?php foreach ($roles as $id => $name): ?><label><input type="checkbox" name="roles[]" value="<?= (int) $id ?>"<?= in_array($id, $folder['roles'] ?? [], true) ? ' checked' : '' ?>> <?= e($name) ?></label> <?php endforeach ?>
<span class="napoveda"><?= e(t('Only for a folder limited to roles. Administrators always see every folder; the section Internal files is given in Users or Roles.')) ?></span></div></div>
<?php endif ?>
<?php if ($departments === []): ?>
<p class="smltxt okruh-oddeleni"><?= e(t('No departments yet – create them in Users → Departments to limit a folder to a team.')) ?></p>
<?php else: ?>
<div class="radek okruh-oddeleni"><span><?= e(t('Departments')) ?></span><div>
<?php foreach ($departments as $id => $name): ?><label><input type="checkbox" name="departments[]" value="<?= (int) $id ?>"<?= in_array($id, $folder['departments'] ?? [], true) ? ' checked' : '' ?>> <?= e($name) ?></label> <?php endforeach ?>
<span class="napoveda"><?= e(t('Only for a folder limited to departments – members of a department below a chosen one see it too.')) ?></span></div></div>
<?php endif ?>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save')) ?>">
<?php if (!$isNew && $folder['files'] === 0): ?> <button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('folder_delete')) ?>" data-potvrdit="<?= e(t('Delete the empty folder?')) ?>"><?= e(t('Delete')) ?></button><?php endif ?></p>
</form>
<?php if (!$isNew && $folder['files'] > 0): ?>
<p class="smltxt"><?= e(t('Only an empty folder can be deleted – it holds %d files.', $folder['files'])) ?></p>
<?php endif ?>
