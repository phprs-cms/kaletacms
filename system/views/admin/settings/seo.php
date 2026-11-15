<?php /** The "SEO a GEO" (SEO and GEO) tab. */ ?>
<p class="hlaska"><?= e(t('The system does most things itself: URLs, descriptions, the sitemap, structured data and resources for AI search engines. Here you decide only on the essentials.')) ?></p>
<fieldset>
<legend><?= e(t('Site visibility')) ?></legend>
<?php $field('indexing', 'Allow the site in search engines', 'ano', 'Turn off only for a site under construction.'); ?>
<div class="radek">
	<label for="ai_crawlers"><?= e(t('AI search engines and assistants')) ?></label>
	<div><select id="ai_crawlers" name="ai_crawlers">
		<option value="povolit"<?= $values['ai_crawlers'] === 'povolit' ? ' selected' : '' ?>><?= e(t('allow – content may appear in AI answers with a link to the site')) ?></option>
		<option value="zakazat"<?= $values['ai_crawlers'] === 'zakazat' ? ' selected' : '' ?>><?= e(t('disallow – ChatGPT, Claude, Perplexity, Gemini and others')) ?></option>
	</select>
	<span class="napoveda"><?= e(t('Well-behaved bots respect the rule; it is not a technical protection.')) ?></span></div>
</div>
<div class="radek">
	<label for="url_slash"><?= e(t('Trailing slash in URLs')) ?></label>
	<div><select id="url_slash" name="url_slash">
		<option value="bez"<?= $values['url_slash'] === 'bez' ? ' selected' : '' ?>><?= e(t('without – /page (canonical), /page/ redirects to it')) ?></option>
		<option value="s"<?= $values['url_slash'] === 's' ? ' selected' : '' ?>><?= e(t('with – /page/ (canonical), /page redirects to it')) ?></option>
		<option value="html"<?= $values['url_slash'] === 'html' ? ' selected' : '' ?>><?= e(t('.html – /page.html (canonical), /page and /page/ redirect to it')) ?></option>
	</select>
	<span class="napoveda"><?= e(t('The other form redirects with 301 and the canonical URL always uses the chosen one. Does not apply to the home page, files such as sitemap.xml, or the API.')) ?></span></div>
</div>
<?php $field('share_image', 'Sharing image', 'text', 'Shown on social networks for pages without their own image. Ideally 1200×630 px.', 'data-obrazek maxlength="255"'); ?>
<?php $field('share_image_auto', 'Generate share images when a page has none', 'ano', $shareImages
    ? 'A 1200×630 picture with the title in the site colours, the site name and the logo – so social networks show something instead of nothing.'
    : 'This server cannot draw pictures (the PHP GD extension is missing), so nothing is generated.'); ?>
</fieldset>
<details class="pokrocile"<?= $values['verification_google'] . $values['verification_bing'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Site ownership verification (Google Search Console, Bing)')) ?></summary>
<?php
$field('verification_google', 'Google', 'text', 'The content value from the google-site-verification meta tag.', 'maxlength="100"');
$field('verification_bing', 'Bing', 'text', 'The content value from the msvalidate.01 meta tag.', 'maxlength="64"');
?>
<p class="napoveda"><?= e(t('Then submit the sitemap address in Search Console:')) ?> <?= e($siteUrl) ?>sitemap.xml</p>
</details>
<details class="pokrocile">
<summary><?= e(t('Advanced')) ?></summary>
<?php
$field('schema_org', 'schema.org structured data', 'ano');
$field('indexnow', 'Notify search engines about new news (IndexNow)', 'ano', 'Bing, Seznam and Yandex will then index them within minutes.');
$field('llms_txt', 'llms.txt file', 'ano', 'A guide to the site for language models.');
$field('markdown_news', 'Plain-text news (.md)', 'ano', 'Every news item also as plain text without navigation – for language models and AI search engines.');
$field('robots_extra', 'Custom robots.txt rules', 'radky', '', 'spellcheck="false"');
$field('security_contact', 'Security contact', 'text', 'An e-mail address or an https:// page where people report security problems of this site. It is published at /.well-known/security.txt (RFC 9116); empty = no file.', 'spellcheck="false"');
?>
<p class="napoveda"><?= e(t('What the system generates:')) ?> <a href="<?= e($siteUrl) ?>robots.txt" target="_blank" rel="noopener"><?= e(t('robots.txt')) ?></a> · <a href="<?= e($siteUrl) ?>sitemap.xml" target="_blank" rel="noopener"><?= e(t('sitemap.xml')) ?></a> · <a href="<?= e($siteUrl) ?>llms.txt" target="_blank" rel="noopener"><?= e(t('llms.txt')) ?></a> · <a href="<?= e($siteUrl) ?>rss.xml" target="_blank" rel="noopener"><?= e(t('rss.xml')) ?></a> · <a href="<?= e($siteUrl) ?>feed.json" target="_blank" rel="noopener"><?= e(t('feed.json')) ?></a></p>
</details>
