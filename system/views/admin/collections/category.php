<?php
/**
 * A category of a collection (3.7): the texts of one language and the settings every language shares.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array{id: int, idk: int, parent_id: ?int, image: string, sort_order: int, visible: bool, texts: array<string, array<string, string>>} $category
 * @var string $language the language of the texts being edited ('' = the default)
 * @var array{name: string, slug: string, description: string, seo_title: string, seo_description: string} $texts
 * @var list<array<string, mixed>> $parents top-level categories it can go under
 * @var bool $hasChildren it has subcategories (so it stays a top-level category)
 */
use Kaleta\Core\Language;

$languages = Language::additional($app->settings());
$parentPath = '';
foreach ($parents as $parent) {
    if ($parent['id'] === $category['parent_id']) {
        $parentPath = $parent['slug'] . '/';
    }
}
?>
<?php if ($languages !== []): ?>
<nav class="zalozky" aria-label="<?= e(t('Language')) ?>">
<?php foreach (['', ...$languages] as $code): ?>
	<a href="<?= e($module->url('category', ['id' => $k['idk']] + ($category['id'] > 0 ? ['category' => $category['id']] : []) + ($code !== '' ? ['language' => $code] : []))) ?>"<?= $code === $language ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(Language::AVAILABLE[$code === '' ? Language::defaults($app->settings()) : $code][0]) ?><?= $category['id'] > 0 && !isset($category['texts'][$code]) ? ' +' : '' ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('save_category')) ?>">
<?= $csrf ?>
<input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>">
<input type="hidden" name="id" value="<?= (int) $category['id'] ?>">
<input type="hidden" name="jazyk" value="<?= e($language) ?>">
<div class="radek"><label for="name"><?= e(t('Název')) ?></label><div><input class="textpole siroke" id="name" name="name" value="<?= e($texts['name']) ?>" maxlength="200" required
	placeholder="<?= e($language !== '' ? (string) ($category['texts']['']['name'] ?? '') : '') ?>"></div></div>
<div class="radek"><label for="slug"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="slug" name="slug" value="<?= e($texts['slug']) ?>" maxlength="160">
	<span class="napoveda">/<?= $language !== '' ? e($language) . '/' : '' ?><?= e($k['seo_link']) ?>/<?= e($parentPath) ?>… <?= e(t('From the name if left empty. It cannot be the address of an item of this collection.')) ?></span></div></div>
<div class="radek pres-celou"><label for="description"><?= e(t('Description')) ?></label><div><textarea class="textbox" id="description" name="description" rows="8" data-editor><?= e($texts['description']) ?></textarea>
	<span class="napoveda"><?= e(t('Shown on the category page ({{popis}} in the category template) – an introduction for visitors and search engines.')) ?></span></div></div>
<details class="pokrocile"<?= $texts['seo_title'] !== '' || $texts['seo_description'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Search engines and sharing')) ?></summary>
<div class="radek"><label for="seo_title"><?= e(t('Search engine title')) ?></label><div><input class="textpole siroke" id="seo_title" name="seo_title" value="<?= e($texts['seo_title']) ?>" maxlength="200" placeholder="<?= e(t('empty = the category name')) ?>"></div></div>
<div class="radek"><label for="seo_description"><?= e(t('Search engine description')) ?></label><div><input class="textpole siroke" id="seo_description" name="seo_description" value="<?= e($texts['seo_description']) ?>" maxlength="300">
	<span class="napoveda"><?= e(t('One or two sentences for search results (up to 160 characters). Empty = the beginning of the description.')) ?></span></div></div>
</details>
<fieldset>
<legend><?= e($languages !== [] ? t('Shared by every language') : t('Settings')) ?></legend>
<div class="radek"><label for="parent_id"><?= e(t('Parent category')) ?></label><div><select id="parent_id" name="parent_id"<?= $hasChildren ? ' disabled' : '' ?>>
	<option value="0"><?= e(t('– none (a top-level category) –')) ?></option>
<?php foreach ($parents as $parent): ?>
	<option value="<?= (int) $parent['id'] ?>"<?= $parent['id'] === $category['parent_id'] ? ' selected' : '' ?>><?= e($parent['name']) ?></option>
<?php endforeach ?>
</select><?php if ($hasChildren): ?><input type="hidden" name="parent_id" value="0"> <span class="napoveda"><?= e(t('It has subcategories, so it stays a top-level category.')) ?></span><?php endif ?></div></div>
<div class="radek"><label for="image"><?= e(t('Image')) ?></label><div><input class="textpole siroke" id="image" name="image" value="<?= e($category['image']) ?>" maxlength="255" data-obrazek>
	<span class="napoveda"><?= e(t('Shown on the category page and its card ({{obrazek}}) and when the page is shared.')) ?></span></div></div>
<div class="radek"><label for="sort_order"><?= e(t('Pořadí')) ?></label><div><input class="textpole" type="number" id="sort_order" name="sort_order" value="<?= (int) $category['sort_order'] ?>" min="-9999" max="9999"><span class="napoveda"><?= e(t('Smaller number = earlier in the list.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Display')) ?></span><div class="volby"><label><input type="checkbox" name="visible" value="1"<?= $category['visible'] ? ' checked' : '' ?>> <?= e(t('published on the site')) ?></label>
	<span class="napoveda"><?= e(t('A hidden category has no page and is in no list; its items stay where they are.')) ?></span></div></div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save category')) ?>"> <a class="navigace" href="<?= e($module->url('categories', ['id' => $k['idk']])) ?>"><?= e(t('Back')) ?></a></p>
</form>
<?php if ($category['id'] > 0 && $language !== '' && isset($category['texts'][$language]) && count($category['texts']) > 1): ?>
<div class="navigace-radek akce-dole"><form class="vradku" method="post" action="<?= e($module->url('delete_category')) ?>" data-potvrdit="<?= e(t('Remove the %s version of the category? The other languages keep it.', strtoupper($language))) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="id" value="<?= (int) $category['id'] ?>"><input type="hidden" name="jazyk" value="<?= e($language) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Remove this language version')) ?></button></form></div>
<?php endif ?>
