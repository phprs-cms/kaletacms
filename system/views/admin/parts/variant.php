<?php
/**
 * A header or footer variant: name and the pages on which it applies instead of the default version.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\SiteParts $module
 * @var string $csrf
 * @var string $type
 * @var string $language
 * @var string $variant
 * @var string $name
 * @var list<int> $selected
 * @var list<array{ids: int, titulek: string}> $pages
 * @var array{novinky: bool, vypis: bool, kolekce: list<string>, nadrazene: list<int>} $rules  kinds of content (3.6)
 * @var list<array{seo_link: string, nazev: string}> $collections  collections with item pages
 * @var list<array{ids: int, titulek: string}> $parents  pages that have subpages
 * @var bool $news  the News feature is on
 */
?>
<form class="formular" method="post" action="<?= e($module->url('save_variant', ['type' => $type, 'language' => $language])) ?>">
<?= $csrf ?>
<input type="hidden" name="varianta" value="<?= e($variant) ?>">
<p class="napoveda"><?= e(t('A variant applies only to the selected pages; elsewhere the default stays. For example a landing page with a simpler header. If you leave the variant empty in the builder, the page will have no header (footer).')) ?></p>
<div class="radek"><label for="nazev"><?= e(t('Variant name')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($name) ?>" maxlength="100" required placeholder="<?= e(t('e.g. Landing page')) ?>"></div></div>
<div class="radek"><span class="popisek"><?= e(t('Pages')) ?></span><div class="volby">
<?php foreach ($pages as $s): ?>
	<label><input type="checkbox" name="stranky[]" value="<?= (int) $s['ids'] ?>"<?= in_array((int) $s['ids'], $selected, true) ? ' checked' : '' ?>> <?= e($s['titulek']) ?></label><br>
<?php endforeach ?>
</div></div>
<fieldset>
<legend><?= e(t('Also on')) ?></legend>
<p class="napoveda"><?= e(t('Besides the pages above, the variant can take a kind of content. A page ticked above always gets its variant; otherwise the first variant (by name) whose choice fits wins.')) ?></p>
<?php if ($news): ?>
<div class="radek"><span class="popisek"><?= e(t('Novinky')) ?></span><div class="volby">
	<label><input type="checkbox" name="novinky" value="1"<?= $rules['novinky'] ? ' checked' : '' ?>> <?= e(t('news items')) ?></label><br>
	<label><input type="checkbox" name="vypis" value="1"<?= $rules['vypis'] ? ' checked' : '' ?>> <?= e(t('the news list, categories, tags and search')) ?></label>
</div></div>
<?php endif ?>
<?php if ($collections !== []): ?>
<div class="radek"><span class="popisek"><?= e(t('Collection item pages')) ?></span><div class="volby">
<?php foreach ($collections as $k): ?>
	<label><input type="checkbox" name="kolekce[]" value="<?= e($k['seo_link']) ?>"<?= in_array($k['seo_link'], $rules['kolekce'], true) ? ' checked' : '' ?>> <?= e($k['nazev']) ?> (/<?= e($k['seo_link']) ?>/…)</label><br>
<?php endforeach ?>
</div></div>
<?php endif ?>
<?php if ($parents !== []): ?>
<div class="radek"><span class="popisek"><?= e(t('Pages under')) ?></span><div class="volby">
<?php foreach ($parents as $s): ?>
	<label><input type="checkbox" name="nadrazene[]" value="<?= (int) $s['ids'] ?>"<?= in_array((int) $s['ids'], $rules['nadrazene'], true) ? ' checked' : '' ?>> <?= e($s['titulek']) ?></label><br>
<?php endforeach ?>
</div></div>
<?php endif ?>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($variant === '' ? 'Vytvořit a otevřít v builderu' : 'Uložit')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
