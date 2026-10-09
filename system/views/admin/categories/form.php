<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Categories $module
 * @var string $csrf
 * @var array<string, mixed> $category
 * @var array<string, string> $errors
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to overview')) ?></a></p>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="idt" value="<?= (int) $category['idt'] ?>">
<div class="radek">
	<label for="nazev"><?= e(t('Category name')) ?></label>
	<div><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($category['nazev']) ?>" maxlength="100" required><?= $error('nazev') ?></div>
</div>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_link" name="seo_link" value="<?= e($category['seo_link']) ?>" maxlength="110" placeholder="<?= e(t('generated from the name')) ?>">
	<span class="napoveda"><?= e(t('The part of the address after %s.', substr($app->url('novinky/kategorie/'), strlen($app->request->basePath())))) ?></span></div>
</div>
<div class="radek">
	<label for="popis"><?= e(t('Description')) ?></label>
	<div><textarea class="textbox" id="popis" name="popis" rows="4"><?= e($category['popis']) ?></textarea><?= $error('popis') ?>
	<span class="napoveda"><?= e(t('Shown above the category\'s news list and used as the description for search engines.')) ?></span></div>
</div>
<div class="radek">
	<label for="hodnost"><?= e(t('Pořadí')) ?></label>
	<div><input class="textpole" type="number" id="hodnost" name="hodnost" value="<?= (int) $category['hodnost'] ?>" min="0" max="65535">
	<span class="napoveda"><?= e(t('Higher number = higher in the list.')) ?></span></div>
</div>
<?= $app->view->render('admin/language_field', ['app' => $app, 'value' => (string) ($category['jazyk'] ?? ''), 'translationOf' => (int) ($category['preklad_z'] ?? 0), 'originals' => $app->db()->pairs("SELECT idt, nazev FROM {kategorie} WHERE jazyk = '' ORDER BY nazev"), 'hint' => t('News in this category belongs to this language version of the site.')]) ?>
<?= $error('jazyk') ?>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($category['idt'] ? 'Uložit' : 'Přidat')) ?>"></p>
</form>
