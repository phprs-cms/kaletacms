<?php
/**
 * @var Kaleta\Admin\Modules\Pages $module
 * @var string $csrf
 * @var array<string, mixed> $page
 * @var array<string, string> $errors
 * @var bool $home  this is the home page of the site
 * @var ?bool $inMenu  the page is in the built menu (null = the menu is built automatically from v_menu)
 * @var bool $customMenu  the site has a built main menu
 * @var list<array{ids:int, titulek:string, seo_link:string}> $parents  possible parent pages
 * @var list<array{idr:int, datum:string, titulek:string, kdo:?string}> $versions  older versions of the text
 */
$segment = basename((string) $page['seo_link']);
$prefix = '';
foreach ($parents as $r) {
    if ((int) $r['ids'] === (int) ($page['nadrazena'] ?? 0)) {
        $prefix = $r['seo_link'] . '/';
    }
}
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="chyba-pole" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to overview')) ?></a>
<?php if ($page['ids']): ?>
	<a class="navigace" href="<?= e($app->url(($page['jazyk'] ?? '') !== '' ? $page['jazyk'] . '/' . ($home ? '' : $page['seo_link']) : ($home ? '' : $page['seo_link'])) . ($page['zobrazit'] ? '' : '?build=koncept')) ?>" target="_blank" rel="noopener"><?= e(t($page['zobrazit'] ? 'View on site' : 'Preview hidden page')) ?></a>
<?php endif ?></p>
<?php if (($page['stavba_koncept'] ?? null) !== null): ?>
<p class="hlaska hlaska-varovani"><?= e(t(($page['stavba'] ?? null) !== null ? 'The builder has work-in-progress changes that are not on the site yet.' : 'You are building this page in the builder. The site still shows the text below – once you publish in the builder, the build replaces it.')) ?>
	<a href="<?= e($module->url('builder', ['id' => (int) $page['ids']])) ?>"><?= e(t('Open the builder')) ?></a></p>
<?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" data-koncept="stranka-<?= (int) $page['ids'] ?>">
<?= $csrf ?>
<input type="hidden" name="ids" value="<?= (int) $page['ids'] ?>">
<div class="radek pres-celou">
	<label for="titulek"><?= e(t('Název stránky')) ?></label>
	<input class="textpole siroke titulek-pole" type="text" id="titulek" name="titulek" value="<?= e($page['titulek']) ?>" maxlength="200" required><?= $error('titulek') ?>
</div>
<?php if (!$page['ids']): ?>
<div class="radek">
	<label for="sablona"><?= e(t('Start from a template')) ?></label>
	<div><select id="sablona" name="sablona">
		<option value=""><?= e(t('blank page (text)')) ?></option>
<?php foreach (Kaleta\Builder\Library::PAGE_TEMPLATES as $key => [$name]): ?>
		<option value="<?= e($key) ?>"><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('A template builds the page from ready-made sections with sample texts and opens it in the builder.')) ?></span></div>
</div>
<?php endif ?>
<?php if (($page['stavba'] ?? null) !== null): ?>
<div class="radek pres-celou">
	<p class="hlaska"><?= e(t('This page\'s content is built in the builder.')) ?> <a class="tl" href="<?= e($module->url('builder', ['id' => (int) $page['ids']])) ?>"><?= e(t('Open the builder')) ?></a></p>
	<input type="hidden" name="text" value="<?= e($page['text']) ?>"><?= $error('text') ?>
</div>
<?php else: ?>
<div class="radek pres-celou">
	<label for="text"><?= e(t('Content')) ?></label>
	<textarea class="textbox vysoky" id="text" name="text" rows="18" data-editor><?= e($page['text']) ?></textarea><?= $error('text') ?>
<?php if ($page['ids']): ?>
	<span class="napoveda"><?= e(t('Want to build the page from sections, columns and buttons?')) ?> <a href="<?= e($module->url('builder', ['id' => (int) $page['ids']])) ?>"><?= e(t('Open in the builder')) ?></a></span>
<?php endif ?>
</div>
<?php endif ?>
<div class="radek">
	<label for="nadrazena"><?= e(t('Parent page')) ?></label>
	<div><select id="nadrazena" name="nadrazena">
		<option value="0"><?= e(t('— none (top level) —')) ?></option>
<?php foreach ($parents as $r): ?>
		<option value="<?= (int) $r['ids'] ?>"<?= (int) $r['ids'] === (int) ($page['nadrazena'] ?? 0) ? ' selected' : '' ?>><?= e(str_repeat('– ', substr_count($r['seo_link'], '/')) . $r['titulek']) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('A subpage has an address under its parent (/services/kitchens) and appears in its breadcrumbs.')) ?></span></div>
</div>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><span class="napoveda-inline">/<?= e($prefix) ?></span><input class="textpole" type="text" id="seo_link" name="seo_link" value="<?= e($segment) ?>" maxlength="110" placeholder="<?= e(t('generated from the title, e.g. about-us')) ?>"><?= $error('seo_link') ?></div>
</div>
<details class="pokrocile"<?= $page['popis'] !== '' || $page['seo_titulek'] !== '' || $page['obrazek'] !== '' || $page['noindex'] || !empty($page['heslo_hash']) || isset($errors['heslo_stranky']) || array_filter($contentCheck ?? [], fn (array $r): bool => !$r['ok']) !== [] ? ' open' : '' ?>>
<summary><?= e(t('Search engines and sharing')) ?></summary>
<div class="radek">
	<label for="seo_titulek"><?= e(t('Search engine title')) ?></label>
	<input class="textpole siroke" type="text" id="seo_titulek" name="seo_titulek" value="<?= e($page['seo_titulek']) ?>" maxlength="200" placeholder="<?= e(t('empty = page name')) ?>">
</div>
<div class="radek">
	<label for="popis"><?= e(t('Search engine description')) ?></label>
	<div><input class="textpole siroke" type="text" id="popis" name="popis" value="<?= e($page['popis']) ?>" maxlength="300">
	<span class="napoveda"><?= e(t('One or two sentences on what visitors will find on the page (up to 160 characters).')) ?></span></div>
</div>
<div class="radek">
	<label for="obrazek"><?= e(t('Sharing image')) ?></label>
	<div><input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($page['obrazek']) ?>" maxlength="255" placeholder="<?= e(t('empty = default image from Settings')) ?>" data-obrazek>
	<span class="napoveda"><?= e(t('Shown when the link is shared on Facebook, LinkedIn or Teams (ideally 1200 × 630 px).')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Options')) ?></span>
	<div class="volby"><label><input type="checkbox" name="noindex" value="1"<?= $page['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines (noindex)')) ?></label></div>
</div>
<div class="radek">
	<label for="heslo_stranky"><?= e(t('Page password')) ?></label>
	<div><input class="textpole" type="password" id="heslo_stranky" name="heslo_stranky" autocomplete="new-password" minlength="<?= Kaleta\Core\PageLock::MIN_LENGTH ?>" placeholder="<?= e(!empty($page['heslo_hash']) ? t('protected – type a new password to change it') : t('none – the page is public')) ?>">
	<?php if (!empty($page['heslo_hash'])): ?><label><input type="checkbox" name="heslo_zrusit" value="1"> <?= e(t('Remove the password')) ?></label><?php endif ?>
	<?= $error('heslo_stranky') ?>
	<span class="napoveda"><?= e(t('Visitors see the page only after entering the password – e.g. a price list for partners. It is not an account: whoever knows the password reads the page. A protected page is never in search engines, the sitemap or the site search.')) ?></span></div>
</div>
<?php if ($app->auth()->isAdmin()): ?>
<div class="radek">
	<label for="kod_hlavicky"><?= e(t('Code in the head of this page')) ?></label>
	<div><textarea class="textpole siroke kod" id="kod_hlavicky" name="kod_hlavicky" rows="4" spellcheck="false" placeholder="&lt;script&gt;…&lt;/script&gt;"><?= e((string) ($page['kod_hlavicky'] ?? '')) ?></textarea>
	<span class="napoveda"><?= e(t('Only for this page, after the code for the whole site (Settings → Analytics) – e.g. the conversion tag of a landing page. Mind the cookie consent: code that tracks visitors belongs in the marketing code.')) ?></span></div>
</div>
<?php endif ?>
<?php if ($page['ids']): ?>
<?= $app->view->render('admin/content_check', ['results' => $contentCheck]) ?>
<?php endif ?>
</details>
<?= $app->view->render('admin/language_field', ['app' => $app, 'value' => (string) ($page['jazyk'] ?? ''), 'translationOf' => (int) ($page['preklad_z'] ?? 0), 'originals' => $app->db()->pairs("SELECT ids, titulek FROM {stranky} WHERE jazyk = '' AND smazano IS NULL ORDER BY titulek"), 'hint' => '']) ?>
<div class="radek">
	<span class="popisek"><?= e(t('Display')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="zobrazit" value="1"<?= $page['zobrazit'] || !empty($page['show_on_publish']) ? ' checked' : '' ?>> <?= e(t('Publish page')) ?></label><?= $home ? ' <span class="stitek">' . e(t('site home page')) . '</span>' : '' ?><?= $error('zobrazit') ?><br>
<?php if (!empty($page['show_on_publish'])): ?>
		<span class="napoveda"><?= e(t('Hidden until it has content: it goes on the site, and into the navigation, when you publish it in the builder or add text.')) ?></span><br>
<?php elseif (!$page['ids']): ?>
		<span class="napoveda"><?= e(t('A page from a template or the builder stays hidden until you publish it there.')) ?></span><br>
<?php endif ?>
		<span class="napoveda" data-aktivni-kdyz="zobrazit="><label for="zverejnit_od"><?= e(t('Publish the hidden page automatically at:')) ?></label> <input class="textpole" type="datetime-local" id="zverejnit_od" name="zverejnit_od" value="<?= e(($page['zverejnit_od'] ?? null) ? date('Y-m-d\TH:i', strtotime($page['zverejnit_od'])) : '') ?>"></span><br>
		<label><input type="checkbox" name="v_menu" value="1"<?= ($inMenu ?? (bool) $page['v_menu']) ? ' checked' : '' ?>> <?= e(t('Show in the site\'s main navigation')) ?></label>
<?php if ($customMenu): ?>
		<span class="napoveda"><?= e(t('The site has a custom menu – the page is added to its end. Change the order and submenus in Appearance → Menu.')) ?></span>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="valid_until"><?= e(t('True until')) ?></label>
	<div><input class="textpole" type="date" id="valid_until" name="valid_until" value="<?= e((string) ($page['valid_until'] ?? '')) ?>">
	<span class="napoveda"><?= e(t('After this day the page hides itself. Empty = always.')) ?></span></div>
</div>
<div class="radek">
	<label for="review_by"><?= e(t('Review by')) ?></label>
	<div><input class="textpole" type="date" id="review_by" name="review_by" value="<?= e((string) ($page['review_by'] ?? '')) ?>">
	<span class="napoveda"><?= e(t('On this day the site audit and the alert e-mail remind you to check it.')) ?></span></div>
</div>
<div class="radek">
	<label for="poradi"><?= e(t('Order in navigation')) ?></label>
	<div><input class="textpole" type="number" id="poradi" name="poradi" value="<?= (int) $page['poradi'] ?>" min="0" max="65535">
	<span class="napoveda"><?= e(t('Lower number = earlier in the page list and in the automatic menu.')) ?></span></div>
</div>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Uložit')) ?></button><?php if (($page['stavba'] ?? null) === null): ?> <button class="navigace" type="submit" name="po_ulozeni" value="stavitel"><?= e(t('Save and open in the builder')) ?></button><?php endif ?></p>
</form>
<?php if ($versions !== []): ?>
<details class="pokrocile">
<summary><?= e(t('Text history (%s)', count($versions))) ?></summary>
<ul class="revize">
<?php foreach ($versions as $v): ?>
	<li><?= e(format_date($v['datum'], true)) ?><?= $v['kdo'] ? ' · ' . e($v['kdo']) : '' ?> · <?= e($v['titulek']) ?>
		<form class="vradku" method="post" action="<?= e($module->url('restore_version')) ?>" data-potvrdit="<?= e(t('Restore this version of the text? The current version stays in the history.')) ?>"><?= $csrf ?><input type="hidden" name="idr" value="<?= (int) $v['idr'] ?>"><button class="navigace" type="submit"><?= e(t('Restore')) ?></button></form></li>
<?php endforeach ?>
</ul>
</details>
<?php endif ?>
<?php if ($page['ids']): ?>
<div class="navigace-radek akce-dole">
<a class="navigace" href="<?= e($module->url('export', ['id' => (int) $page['ids']])) ?>"><?= e(t('Download as JSON')) ?></a>
<form class="vradku" method="post" action="<?= e($module->url('duplicate')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $page['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($page['titulek']) ?>"><button class="navigace" type="submit"><?= e(t('Duplicate page')) ?></button></form>
<?php if (($page['stavba'] ?? null) !== null): ?>
<form class="vradku" method="post" action="<?= e($module->url('build_text')) ?>" data-potvrdit="<?= e(t('Return the page to plain text? The build stays in versions and you can go back to it.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $page['ids'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Return page to text')) ?></button></form>
<?php endif ?>
</div>
<?php endif ?>
