<?php /** The "Základní" (General) tab. For the variables and the $field function see vypis.php. */ ?>
<fieldset>
<legend><?= e(t('Website')) ?></legend>
<?php
$field('site_name', 'Site name', 'text', '', 'maxlength="150" required');
$field('site_url', 'Site address', 'url', 'For example https://www.example.com, without a trailing slash. Links in e-mails, RSS, the sitemap and notifications are built from it. Change it after moving to another domain.', 'required placeholder="https://"');
$field('site_description', 'Site description', 'radky', 'One or two sentences – a motto, a description for search engines and RSS.');
$field('site_email', 'Site email', 'email', 'System notifications are sent to it.');
?>
<div class="radek">
	<label for="require_2fa"><?= e(t('Two-factor sign-in')) ?></label>
	<div><select id="require_2fa" name="require_2fa">
<?php foreach (['' => 'dobrovolné', 'spravci' => 'required for administrators', 'vsichni' => 'required for all users'] as $k => $n): ?>
		<option value="<?= e($k) ?>"<?= ($values['require_2fa'] ?? '') === $k ? ' selected' : '' ?>><?= e(t($n)) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Anyone who must have it and has not turned it on yet can only reach My account after signing in until they set it up.')) ?></span></div>
</div>
<?php $autoSuspend = explode(',', $values['auto_suspend'] ?? ''); ?>
<div class="radek">
	<span class="popisek"><?= e(t('Suspend automatically')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="auto_suspend[]" value="<?= e(Kaleta\Core\SecurityHygiene::SUSPEND_ACCOUNTS) ?>"<?= in_array(Kaleta\Core\SecurityHygiene::SUSPEND_ACCOUNTS, $autoSuspend, true) ? ' checked' : '' ?>> <?= e(t('block accounts nobody has used for %d days (never the last administrator)', Kaleta\Core\SecurityHygiene::ACCOUNT_DAYS)) ?></label><br>
		<label><input type="checkbox" name="auto_suspend[]" value="<?= e(Kaleta\Core\SecurityHygiene::SUSPEND_CONNECTIONS) ?>"<?= in_array(Kaleta\Core\SecurityHygiene::SUSPEND_CONNECTIONS, $autoSuspend, true) ? ' checked' : '' ?>> <?= e(t('revoke Claude connections nobody has used for %d days', Kaleta\Core\SecurityHygiene::CONNECTION_DAYS)) ?></label>
		<span class="napoveda"><?= e(t('Checked once a day. While this is off, System status and the site audit only report unused accounts and connections. A blocked account shows the reason in Users and an administrator can reactivate it; a revoked connection has to be connected again.')) ?></span>
	</div>
</div>
<?php
?>
<?php if (($additionalLanguages = Kaleta\Core\Language::additional($app->settings())) !== []): ?>
<details class="pokrocile"<?= array_filter($additionalLanguages, fn (string $j): bool => ($values['nazev_webu_' . $j] ?? '') . ($values['popis_webu_' . $j] ?? '') !== '') !== [] ? ' open' : '' ?>>
<summary><?= e(t('Name and description in other language versions')) ?></summary>
<p class="napoveda"><?= e(t('An empty field means the same text as in the default language.')) ?></p>
<?php foreach ($additionalLanguages as $j): ?>
<div class="radek"><label for="nazev_webu_<?= e($j) ?>"><?= e(t('Site name')) ?> (<?= e(strtoupper($j)) ?>)</label><div><input class="textpole siroke" type="text" id="nazev_webu_<?= e($j) ?>" name="nazev_webu_<?= e($j) ?>" value="<?= e($values['nazev_webu_' . $j] ?? '') ?>" maxlength="150" lang="<?= e($j) ?>"></div></div>
<div class="radek"><label for="popis_webu_<?= e($j) ?>"><?= e(t('Site description')) ?> (<?= e(strtoupper($j)) ?>)</label><div><textarea class="textbox radkovy" id="popis_webu_<?= e($j) ?>" name="popis_webu_<?= e($j) ?>" rows="2" cols="60" lang="<?= e($j) ?>"><?= e($values['popis_webu_' . $j] ?? '') ?></textarea></div></div>
<?php endforeach ?>
</details>
<?php endif ?>
<div class="radek">
	<label for="time_zone"><?= e(t('Time zone')) ?></label>
	<div><select id="time_zone" name="time_zone">
<?php foreach (DateTimeZone::listIdentifiers() as $timeZone): ?>
		<option value="<?= e($timeZone) ?>"<?= $values['time_zone'] === $timeZone ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $timeZone)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Scheduled news is published and dates are shown according to it. It is now %s.', format_date(new DateTimeImmutable(), true))) ?></span></div>
</div>
<div class="radek">
	<label for="site_language"><?= e(t('Jazyk webu')) ?></label>
	<div><select id="site_language" name="site_language">
<?php foreach (Kaleta\Core\Language::AVAILABLE as $code => [$languageName]): ?>
		<option value="<?= e($code) ?>"<?= $values['site_language'] === $code ? ' selected' : '' ?>><?= e($languageName) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('The site texts (Search, News, Read more…) are in this language, and the site declares it to search engines.')) ?></span></div>
</div>
<?php if (in_array('de', array_merge([$values['site_language']], explode(',', $values['additional_languages'])), true)): ?>
<div class="radek">
	<label for="german_register"><?= e(t('Form of address in German')) ?></label>
	<div><select id="german_register" name="german_register">
		<option value="formal"<?= $values['german_register'] === 'formal' ? ' selected' : '' ?>><?= e(t('Formal (Sie)')) ?></option>
		<option value="informal"<?= $values['german_register'] === 'informal' ? ' selected' : '' ?>><?= e(t('Informal (du)')) ?></option>
	</select>
	<span class="napoveda"><?= e(t('How the German texts for visitors address them (forms, search, cookie bar). The administration has its own choice in My account.')) ?></span></div>
</div>
<?php endif ?>
<?php if (Kaleta\Core\Extensions::isEnabled($app->settings(), 'jazyky')): ?>
<div class="radek" id="additional_languages">
	<span class="popisek"><?= e(t('Other language versions')) ?></span>
	<div class="volby">
		<div class="volby-jazyky">
<?php foreach (Kaleta\Core\Language::AVAILABLE as $code => [$languageName]): if ($code === $values['site_language']) { continue; } ?>
		<label><input type="checkbox" name="additional_languages[]" value="<?= e($code) ?>"<?= in_array($code, explode(',', $values['additional_languages']), true) ? ' checked' : '' ?>> <?= e($languageName) ?> <small>(/<?= e($code) ?>/)</small></label>
<?php endforeach ?>
		</div>
		<span class="napoveda"><?= e(t('Each version has its own pages, categories and news. A news item\'s language is set by its category. Link translations in the editor.')) ?></span>
<?php $translated = array_map(fn (string $code): string => Kaleta\Core\Language::AVAILABLE[$code][0], array_values(array_filter(array_keys(Kaleta\Core\Language::AVAILABLE), fn (string $code): bool => $code === 'cs' || is_file(KALETA_SYSTEM . '/jazyky/' . $code . '.php')))); ?>
		<span class="napoveda"><?= e(t('Texts for visitors (Search, Read more…) are translated into: %s. Other languages show them in English, with dates in their own format. You write the content of pages and news in the language of the version.', implode(', ', $translated))) ?></span>
	</div>
</div>
<?php $field('slugs_per_language', 'The same address in every language', 'ano', 'A page, a news item or a category may have the same address as one in another language version (/kontakt and /en/kontakt), as on a multilingual WordPress site. System addresses and language codes stay reserved in every language. Switching it off again is possible only while no two language versions share an address.'); ?>
<?php else: ?>
<?php foreach (array_filter(explode(',', $values['additional_languages'])) as $code): ?><input type="hidden" name="additional_languages[]" value="<?= e($code) ?>"><?php endforeach ?>
<?php if ($values['slugs_per_language'] === '1'): ?><input type="hidden" name="slugs_per_language" value="1"><?php endif ?>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Home page and news')) ?></legend>
<div class="radek">
	<label for="home_page"><?= e(t('Site home page')) ?></label>
	<div><select id="home_page" name="home_page">
		<option value="0"><?= e(t('– news list –')) ?></option>
<?php foreach ($pages as $pageId => $pageTitle): ?>
		<option value="<?= (int) $pageId ?>"<?= (int) $values['home_page'] === (int) $pageId ? ' selected' : '' ?>><?= e($pageTitle) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('The page shown at the site address. News is always at %s.', substr($app->url('novinky'), strlen($app->request->basePath())))) ?></span></div>
</div>
<?php $field('news_slug', 'News URL', 'text', 'The first part of the news addresses in every language: blog gives /blog/…. Empty = the default address. Lowercase letters, digits and hyphens; not the address of a page, a collection or the system. The old addresses redirect to the new one.', 'maxlength="40" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="' . e(\Kaleta\Core\Language::defaults($app->settings()) === 'cs' ? 'novinky' : 'news') . '"'); ?>
<?php $field('news_per_page', 'News items per page', 'cislo', '', 'min="1" max="100"'); ?>
</fieldset>
<fieldset>
<legend><?= e(t('Built and looked after by')) ?></legend>
<p class="napoveda"><?= e(t('The agency or freelancer who looks after the site. The sign-in screen and the foot of the admin show whom to ask for help.')) ?></p>
<?php
$field('agency_name', 'Name');
$field('agency_url', 'Website', 'url');
$field('agency_email', 'E-mail for help', 'email');
$field('agency_phone', 'Phone for help');
$field('agency_logo', 'Logo', 'text', 'A path from Media, e.g. media/2026/10/agency.svg – click the file in Media to open it, its address starts with media/.');
?>
</fieldset>
<details class="pokrocile"<?= $values['maintenance'] === '1' ? ' open' : '' ?>>
<summary><?= e(t('Maintenance mode')) ?><?= $values['maintenance'] === '1' ? ' – ' . e(t('ON')) : '' ?></summary>
<?php
$field('maintenance', 'The site is temporarily unavailable', 'ano', 'Visitors see only the notice below. Signed-in users see the site as usual.');
$field('maintenance_text', 'Notice text', 'text', '', 'maxlength="300"');
?>
</details>
<?php /* screen mode (2.11, Front\Screen): a kiosk address for a TV or a tablet in the reception; $screenCollections address => name, $screenUrl '' until the address exists */ ?>
<details class="pokrocile"<?= ($values['screen_mode'] ?? '') === '1' ? ' open' : '' ?>>
<summary><?= e(t('Screen mode')) ?><?= ($values['screen_mode'] ?? '') === '1' ? ' – ' . e(t('ON')) : '' ?></summary>
<p class="napoveda"><?= e(t('A TV or a tablet in the reception, showroom or waiting room opens the address below and rotates slides by itself: the latest news, items of the chosen collections and today\'s opening hours. The address has a secret part, so nobody finds the screen by guessing; it is not indexed and sets no cookies.')) ?></p>
<?php
$field('screen_mode', 'Screen mode on', 'ano', '');
$field('screen_seconds', 'Seconds per slide', 'cislo', '', 'min="' . Kaleta\Front\Screen::MIN_SECONDS . '" max="' . Kaleta\Front\Screen::MAX_SECONDS . '"');
?>
<div class="radek">
	<span class="popisek"><?= e(t('Collections to show')) ?></span>
	<div class="volby">
<?php if ($screenCollections === []): ?>
		<span class="napoveda"><?= e(t('The site has no collections yet.')) ?></span>
<?php else: $chosen = explode(',', $values['screen_collections'] ?? ''); ?>
<?php foreach ($screenCollections as $slug => $collectionName): ?>
		<label><input type="checkbox" name="screen_collections[]" value="<?= e($slug) ?>"<?= in_array($slug, $chosen, true) ? ' checked' : '' ?>> <?= e($collectionName) ?></label><br>
<?php endforeach ?>
<?php endif ?>
		<span class="napoveda"><?= e(t('A collection with a date field (events, courses) shows only the upcoming items.')) ?></span>
	</div>
</div>
<?php
$field('screen_news', 'Show the latest news', 'ano', 'Up to five, with their images.');
$field('screen_hours', 'Show today\'s opening hours', 'ano', 'From Business details, with the exceptions: “Open now, until 17:00”.');
$field('screen_clock', 'Show a clock', 'ano', '');
?>
<?php if ($screenUrl !== ''): ?>
<div class="radek">
	<span class="popisek"><?= e(t('Screen address')) ?></span>
	<div><code id="screen-url"><?= e($screenUrl) ?></code> <button class="navigace" type="button" data-kopirovat="#screen-url"><?= e(t('Copy address')) ?></button>
	<span class="napoveda"><?= e(t('Open it on the screen in full-screen (kiosk) mode. Anyone with the address sees the screen – do not publish it.')) ?></span></div>
</div>
<p><button class="navigace" type="submit" name="novy_token_obrazovka" value="1"><?= e(t('Create a new address (the old one stops working)')) ?></button></p>
<?php else: ?>
<p class="napoveda"><?= e(t('Save the settings with the screen mode on – the address is created then.')) ?></p>
<?php endif ?>
</details>
<details class="pokrocile">
<summary><?= e(t('Sociální sítě')) ?></summary>
<?php foreach (Kaleta\Admin\Modules\Settings::SOCIAL_NETWORKS as $key => $name) { $field($key, $name, 'url', '', 'placeholder="https://"'); } ?>
<p class="napoveda"><?= e(t('Filled-in profiles are shown in the site footer and passed to search engines.')) ?></p>
</details>
<details class="pokrocile">
<summary><?= e(t('More options')) ?></summary>
<?php
$field('footer_text', 'Text v patičce', 'text', 'For example the registered company name and company ID.', 'maxlength="300"');
$field('share_buttons', 'Share links below the news item', 'ano', 'Facebook, X, LinkedIn, WhatsApp, e-mail and copy link – no third-party scripts.');
?>
<?php $socialNetworks = Kaleta\Core\SocialDrafts::chosen($values['social_networks'] ?? ''); ?>
<div class="radek">
	<span class="popisek"><?= e(t('Social post drafts')) ?></span>
	<div class="volby">
<?php foreach (Kaleta\Core\SocialDrafts::NETWORKS as $networkKey => $networkName): ?>
		<label><input type="checkbox" name="social_networks[]" value="<?= e($networkKey) ?>"<?= in_array($networkKey, $socialNetworks, true) ? ' checked' : '' ?>> <?= e($networkName) ?></label>
<?php endforeach ?>
		<span class="napoveda"><?= e(t('When a news item is published, a post draft per network is prepared under the news item – with a tracked link and the image. You copy and post it yourself; the site never posts anywhere.')) ?></span>
	</div>
</div>
<?php
$field('article_outline', 'News table of contents from subheadings', 'ano', 'News items with at least three subheadings get a clickable outline above the text.');
$field('related_news_auto', 'Related news', 'ano', 'Similar news by tags and category is offered below a news item.');
?>
<?php
$field('link_check', 'Look for broken links', 'ano', 'In the background, one news item every five minutes. The result is in News → Broken links.');
$field('page_cache', 'Page cache', 'ano', 'Finished pages are served to visitors from memory – the site is faster and copes with traffic peaks. Leave it on.');
?>
</details>
