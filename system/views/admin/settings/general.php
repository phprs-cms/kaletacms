<?php
/**
 * The "General" tab: the site, the time zone, the home page and news, maintenance and screen mode, sharing and more options.
 * Languages, Security and Agency were part of it until 3.22 (C12) – Settings::SPLIT_FROM_GENERAL. For the variables and the
 * $field function see list.php.
 */
?>
<fieldset>
<legend><?= e(t('Website')) ?></legend>
<?php
$field('site_name', 'Site name', 'text', '', 'maxlength="150" required');
$field('site_url', 'Site address', 'url', 'For example https://www.example.com, without a trailing slash. Links in e-mails, RSS, the sitemap and notifications are built from it. Change it after moving to another domain.', 'required placeholder="https://"');
$field('site_description', 'Site description', 'radky', 'One or two sentences – a motto, a description for search engines and RSS.');
$field('site_email', 'Site email', 'email', 'System notifications are sent to it.');
?>
<?php if (Kaleta\Core\Language::additional($app->settings()) !== []): ?>
<p class="napoveda"><?= $module->hint(t('The name and the description in the other language versions are in Settings → Languages.')) ?></p>
<?php endif ?>
<div class="radek">
	<label for="time_zone"><?= e(t('Time zone')) ?></label>
	<div><select id="time_zone" name="time_zone">
<?php foreach (DateTimeZone::listIdentifiers() as $timeZone): ?>
		<option value="<?= e($timeZone) ?>"<?= $values['time_zone'] === $timeZone ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $timeZone)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Scheduled news is published and dates are shown according to it. It is now %s.', format_date(new DateTimeImmutable(), true))) ?> <?= e(t('When you change it, you choose whether the dates already saved are converted to the new zone or keep their clock time.')) ?></span>
<?php if (($zoneConversion = Kaleta\Core\TimeZoneConversion::latest($app->db())) !== null && ($zoneConversion['finished_at'] === null || strtotime((string) $zoneConversion['finished_at']) > time() - 86400)): ?>
<?php $zoneTables = $zoneConversion['tables']; $zoneDone = count(array_filter($zoneTables, fn (array $r): bool => $r['finished_at'] !== null)); $zoneValues = array_sum(array_map(fn (array $r): int => (int) $r['values_done'], $zoneTables)); $zoneAmbiguous = array_sum(array_map(fn (array $r): int => (int) $r['ambiguous'], $zoneTables)); ?>
	<p class="napoveda"><?= e($zoneConversion['finished_at'] === null
		? t('Converting saved dates from %s to %s: %d of %d tables done, %d values so far. The background jobs continue it.', (string) $zoneConversion['from_zone'], (string) $zoneConversion['to_zone'], $zoneDone, count($zoneTables), $zoneValues)
		: t('Saved dates were converted from %s to %s: %d values in %d tables.', (string) $zoneConversion['from_zone'], (string) $zoneConversion['to_zone'], $zoneValues, count($zoneTables))) ?>
<?php if ($zoneAmbiguous > 0): ?> <?= e(t('%d of them were in the hour that repeats when daylight saving time ends; the first of the two hours was taken.', $zoneAmbiguous)) ?><?php endif ?>
<?php if (($zoneOutside = array_sum(array_map(fn (array $r): int => (int) $r['out_of_range'], $zoneTables))) > 0): ?> <?= e(t('%d values were left as they were: they would leave the years 1000–9999.', $zoneOutside)) ?><?php endif ?>
<?php if (($zoneFailed = array_column(array_filter($zoneTables, fn (array $r): bool => (string) $r['error'] !== ''), 'table_name')) !== []): ?> <?= e(t('Not converted because of an error (see the change log): %s.', implode(', ', $zoneFailed))) ?><?php endif ?></p>
<?php endif ?>
	</div>
</div>
<?php // 3.32 (U29): the currency a new deal value takes; automatic = from the company country, the time zone or the site language ?>
<div class="radek">
	<label for="default_currency"><?= e(t('Currency')) ?></label>
	<div><select id="default_currency" name="default_currency">
		<option value=""><?= e(t('Automatic (%s)', Kaleta\Core\Deals::derivedCurrency($app->settings()))) ?></option>
<?php foreach (Kaleta\Core\Deals::CURRENCIES as $currencyCode): ?>
		<option value="<?= e($currencyCode) ?>"<?= ($values['default_currency'] ?? '') === $currencyCode ? ' selected' : '' ?>><?= e($currencyCode) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('The currency a new deal value starts with. Automatic takes the currency of the company country, else of the time zone.')) ?></span></div>
</div>
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
	<span class="napoveda"><?= e(t('The page shown at the site address. News has its own address, set in News URL below.')) /* 3.23.1 (N-C6): the address is a setting since 3.17 */ ?></span></div>
</div>
<?php $field('news_slug', 'News URL', 'text', 'The first part of the news addresses in every language: blog gives /blog/…. Empty = the default address. Lowercase letters, digits and hyphens; not the address of a page, a collection or the system. The old addresses redirect to the new one.', 'maxlength="40" pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="' . e(\Kaleta\Core\Language::defaults($app->settings()) === 'cs' ? 'novinky' : 'news') . '"'); ?>
<?php $field('news_title', 'News heading', 'text', 'The heading of the news list in the default language, e.g. Updates to go with the address /updates. Empty = News.', 'maxlength="80"'); ?>
<?php $field('news_per_page', 'News items per page', 'cislo', '', 'min="1" max="100"'); ?>
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
<summary><?= e(t('Sharing and social networks')) ?></summary>
<?php foreach (Kaleta\Admin\Modules\Settings::SOCIAL_NETWORKS as $key => $name) { $field($key, $name, 'url', '', 'placeholder="https://"'); } ?>
<p class="napoveda"><?= e(t('Filled-in profiles are shown in the site footer and passed to search engines.')) ?></p>
<?php
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
</details>
<details class="pokrocile">
<summary><?= e(t('More options')) ?></summary>
<?php
$field('footer_text', 'Text v patičce', 'text', 'For example the registered company name and company ID.', 'maxlength="300"');
$field('article_outline', 'News table of contents from subheadings', 'ano', 'News items with at least three subheadings get a clickable outline above the text.');
$field('related_news_auto', 'Related news', 'ano', 'Similar news by tags and category is offered below a news item.');
$field('link_check', 'Look for broken links', 'ano', 'In the background, one news item every five minutes. The result is in Site care → Broken links.');
$field('page_cache', 'Page cache', 'ano', 'Finished pages are served to visitors from memory – the site is faster and copes with traffic peaks. Leave it on.');
?>
</details>
