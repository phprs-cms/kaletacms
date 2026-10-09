<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Menu $module
 * @var string $csrf
 * @var string $location  hlavni | paticka
 * @var string $language     the language column ('' = default)
 * @var bool $automatic the main menu is still built automatically
 * @var bool $inDraft the items come from the draft look (Core\Look)
 * @var list<array<string, mixed>> $items
 * @var list<array{ids:int, titulek:string, skryta:bool}> $pages
 * @var array<string, string> $languages
 */
$choice = ['location' => $location, 'language' => $language];
?>
<nav class="zalozky" aria-label="<?= e(t('Menu')) ?>">
<?php foreach (Kaleta\Core\Menu::LOCATIONS as $key => $name): ?>
	<a href="<?= e($module->url('', ['location' => $key, 'language' => $language])) ?>"<?= $key === $location ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<?php if (count($languages) > 1): ?>
<p class="smltxt"><?= e(t('Language version:')) ?>
<?php foreach ($languages as $code => $name): ?>
	<a class="navigace<?= $code === $language ? ' aktivni' : '' ?>" href="<?= e($module->url('', ['location' => $location, 'language' => $code])) ?>"<?= $code === $language ? ' aria-current="true"' : '' ?>><?= e($name) ?></a>
<?php endforeach ?></p>
<?php endif ?>
<p class="smltxt"><?= e(t($location === 'hlavni'
    ? ($automatic ? 'The menu is currently built automatically from pages ticked “in navigation”. Once you edit and save it here, this version applies.' : 'Reorder by dragging or with the arrows. The right arrow moves an item into the submenu of the one above.')
    : 'Links in the site footer (privacy policy, contact, careers…). Used by the default footer and by a Navigation element set to the footer menu.')) ?> <?= e(t('An icon shows before the text. The description and group columns (a group inside a submenu with its own items) appear in a mega menu – the Navigation element with “Submenu as a wide panel”.')) ?></p>

<form method="post" action="<?= e($module->url('save', $choice)) ?>" class="menu-formular" data-menu>
<?= $csrf ?>
<input type="hidden" name="polozky" value="">
<script type="application/json" data-menu-data><?= json_encode(['polozky' => $items, 'stranky' => $pages, 'ikony' => ['' => t('no icon')] + array_map(fn (string $n): string => t($n), Kaleta\Builder\Icons::options())], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<ol class="menu-editor" data-menu-seznam></ol>
<p class="napoveda" data-menu-prazdne hidden><?= e(t('The menu is empty – add the first item.')) ?></p>
<fieldset class="menu-pridat">
	<legend><?= e(t('Add item')) ?></legend>
	<label><?= e(t('Page')) ?>
		<select data-menu-stranka>
<?php foreach ($pages as $s): ?>
			<option value="<?= $s['ids'] ?>"><?= e($s['titulek']) ?><?= $s['skryta'] ? ' (' . e(t('hidden')) . ')' : '' ?></option>
<?php endforeach ?>
		</select>
	</label>
	<button class="navigace" type="button" data-menu-pridej="stranka"><?= e(t('Add page')) ?></button>
	<button class="navigace" type="button" data-menu-pridej="odkaz"><?= e(t('Custom link')) ?></button>
<?php if (Kaleta\Core\Extensions::isEnabled($app->settings(), 'novinky')): ?>
	<button class="navigace" type="button" data-menu-pridej="novinky"><?= e(t('Novinky')) ?></button>
<?php endif ?>
	<button class="navigace" type="button" data-menu-pridej="skupina" title="<?= e(t('An item without a link that only opens a submenu')) ?>"><?= e(t('Group')) ?></button>
</fieldset>
<?php /* the draft button first: Enter in a field (implicit submission) saves to the draft, publishing is always a click */ ?>
<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Save to the draft look')) ?></button> <button class="tl" type="submit" name="publikovat" value="1"><?= e(t('Save and publish menu')) ?></button></p>
<p class="napoveda"><?= e(t('Publishing the menu leaves other unpublished look changes (colours, fonts, classes) waiting in the draft.')) ?></p>
</form>
<?php if (!$automatic || $location !== 'hlavni'): ?>
<div class="navigace-radek akce-dole"><form class="vradku" method="post" action="<?= e($module->url('automatic', $choice)) ?>" data-potvrdit="<?= e(t($location === 'hlavni' ? 'Return the menu to being built automatically from pages? Your changes will be discarded.' : 'Empty the footer menu?')) ?>"><?= $csrf ?><button class="navigace nebezpecne" type="submit"><?= e(t($location === 'hlavni' ? 'Back to automatic menu' : 'Empty the menu')) ?></button></form></div>
<?php endif ?>
<script src="<?= e($app->url('image/menu.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
