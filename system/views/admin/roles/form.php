<?php
/**
 * @var Kaleta\Admin\Modules\Roles $module
 * @var string $csrf
 * @var array<string, mixed> $role
 * @var array<string, string> $errors
 * @var array<string, string> $section  ident => name
 * @var list<string> $selected
 * @var list<array<string, mixed>> $members
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to roles')) ?></a></p>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="idr" value="<?= (int) $role['idr'] ?>">
<div class="radek">
	<label for="nazev"><?= e(t('Role name')) ?></label>
	<div><input class="textpole" type="text" id="nazev" name="nazev" value="<?= e($role['nazev']) ?>" maxlength="60" required placeholder="<?= e(t('e.g. Salesperson')) ?>"><?= $error('nazev') ?></div>
</div>
<div class="radek">
	<label for="popis"><?= e(t('Description')) ?></label>
	<input class="textpole siroke" type="text" id="popis" name="popis" value="<?= e($role['popis']) ?>" maxlength="200">
</div>
<fieldset>
<legend><?= e(t('News')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach (Kaleta\Admin\Modules\Roles::LEVELS as $value => [$name, $description]): ?>
	<label class="karta-volba"><input type="radio" name="uroven" value="<?= $value ?>"<?= (int) $role['uroven'] === $value ? ' checked' : '' ?>><strong><?= e(t($name)) ?></strong><span><?= e(t($description)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
<fieldset>
<legend><?= e(t('Access to areas')) ?></legend>
<div class="volby">
<?php foreach ($section as $ident => $name): ?>
	<label><input type="checkbox" name="moduly[]" value="<?= e($ident) ?>"<?= in_array($ident, $selected, true) ? ' checked' : '' ?>> <?= e(t($name)) ?></label><br>
<?php endforeach ?>
	<?= $error('moduly') ?>
	<span class="napoveda"><?= e(t('Appearance lets the role edit the menu, the site parts (header, footer), the pop-ups and the components; with “Writes own content” only as drafts. Site appearance (colours and fonts), website settings, users, backups and the Claude settings stay with the administrator.')) ?></span>
</div>
</fieldset>
<?php if ($members !== []): ?>
<p class="smltxt"><?= e(t('Saving also changes the permissions of these users:')) ?> <?= e(implode(', ', array_map(fn (array $u): string => $u['jmeno'] !== '' ? $u['jmeno'] : $u['user'], $members))) ?></p>
<?php endif ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"></p>
</form>
