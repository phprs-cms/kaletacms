<?php /** Záložka SEO a GEO. */ ?>
<p class="hlaska"><?= e(t('Většinu věcí dělá systém sám: adresy, popisy, sitemapu, strukturovaná data i podklady pro AI vyhledávače. Tady rozhodujete jen o tom hlavním.')) ?></p>
<fieldset>
<legend><?= e(t('Viditelnost webu')) ?></legend>
<?php $pole('indexovani', 'Web smí být ve vyhledávačích', 'ano', 'Vypněte jen u webu ve výstavbě.'); ?>
<div class="radek">
	<label for="ai_crawlery"><?= e(t('AI vyhledávače a asistenti')) ?></label>
	<div><select id="ai_crawlery" name="ai_crawlery">
		<option value="povolit"<?= $hodnoty['ai_crawlery'] === 'povolit' ? ' selected' : '' ?>><?= e(t('povolit – obsah se může objevit v odpovědích AI s odkazem na web')) ?></option>
		<option value="zakazat"<?= $hodnoty['ai_crawlery'] === 'zakazat' ? ' selected' : '' ?>><?= e(t('zakázat – ChatGPT, Claude, Perplexity, Gemini a další')) ?></option>
	</select>
	<span class="napoveda"><?= e(t('Slušní roboti pravidlo respektují; nejde o technickou ochranu.')) ?></span></div>
</div>
<?php $pole('og_obrazek', 'Obrázek pro sdílení', 'text', 'Ukáže se na sociálních sítích u stránek bez vlastního obrázku. Ideálně 1200×630 px.', 'data-obrazek maxlength="255"'); ?>
</fieldset>
<details class="pokrocile"<?= $hodnoty['overeni_google'] . $hodnoty['overeni_bing'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Ověření vlastnictví webu (Google Search Console, Bing)')) ?></summary>
<?php
$pole('overeni_google', 'Google', 'text', 'Hodnota content z meta tagu google-site-verification.', 'maxlength="100"');
$pole('overeni_bing', 'Bing', 'text', 'Hodnota content z meta tagu msvalidate.01.', 'maxlength="64"');
?>
<p class="napoveda"><?= e(t('Do Search Console pak vložte adresu sitemapy:')) ?> <?= e($adresaWebu) ?>sitemap.xml</p>
</details>
<details class="pokrocile">
<summary><?= e(t('Pro pokročilé')) ?></summary>
<?php
$pole('schema_org', 'Strukturovaná data schema.org', 'ano');
$pole('indexnow', 'Oznamovat nové články vyhledávačům (IndexNow)', 'ano', 'Bing, Seznam a Yandex je pak zaindexují během minut.');
$pole('llms_txt', 'Soubor llms.txt', 'ano', 'Průvodce webem pro jazykové modely.');
$pole('markdown_clanky', 'Čistá verze článků (.md)', 'ano', 'Každý článek i jako prostý text bez navigace a reklam.');
$pole('robots_extra', 'Vlastní pravidla robots.txt', 'radky', '', 'spellcheck="false"');
?>
<p class="napoveda"><?= e(t('Co systém generuje:')) ?> <a href="<?= e($adresaWebu) ?>robots.txt" target="_blank" rel="noopener"><?= e(t('robots.txt')) ?></a> · <a href="<?= e($adresaWebu) ?>sitemap.xml" target="_blank" rel="noopener"><?= e(t('sitemap.xml')) ?></a> · <a href="<?= e($adresaWebu) ?>sitemap-news.xml" target="_blank" rel="noopener"><?= e(t('sitemap-news.xml')) ?></a> · <a href="<?= e($adresaWebu) ?>llms.txt" target="_blank" rel="noopener"><?= e(t('llms.txt')) ?></a> · <a href="<?= e($adresaWebu) ?>rss.xml" target="_blank" rel="noopener"><?= e(t('rss.xml')) ?></a> · <a href="<?= e($adresaWebu) ?>feed.json" target="_blank" rel="noopener"><?= e(t('feed.json')) ?></a></p>
</details>
