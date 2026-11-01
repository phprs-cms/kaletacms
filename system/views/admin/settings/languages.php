<?php
/**
 * The "Languages" tab (3.23, C12 – part of General until then): the site language, the other language versions, their
 * names and descriptions and the same address in every language. For the variables and the $field function see list.php.
 */
?>
<fieldset>
<legend><?= e(t('Languages')) ?></legend>
<div class="radek">
	<label for="site_language"><?= e(t('Jazyk webu')) ?></label>
	<div><select id="site_language" name="site_language">
<?php foreach (Kaleta\Core\Language::AVAILABLE as $code => [$languageName]): ?>
		<option value="<?= e($code) ?>"<?= $values['site_language'] === $code ? ' selected' : '' ?>><?= e($languageName) ?><?= Kaleta\Core\Language::hasVisitorTexts($code) ? '' : ' – ' . e(t('visitor texts in English')) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('The site texts (Search, News, Read more…) are in this language, and the site declares it to search engines.')) ?></span>
<?php // 3.12 (UX310-12): a language without visitor texts is said plainly, for the default language and the further versions alike
if (($english = Kaleta\Admin\Modules\Settings::languagesInEnglish($app->settings())) !== []): ?>
	<p class="hlaska hlaska-varovani"><?= e(t('Visitors of %s see the site texts (Search, Read more, the cookie bar…) in English – Kaleta has no translation of them yet. Your pages and news are in the language you write them in.', implode(', ', $english))) ?></p>
<?php endif ?>
	</div>
</div>
<?php if (in_array('de', array_map(Kaleta\Core\Language::base(...), array_merge([$values['site_language']], explode(',', $values['additional_languages']))), true)): ?>
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
		<label><input type="checkbox" name="additional_languages[]" value="<?= e($code) ?>"<?= in_array($code, explode(',', $values['additional_languages']), true) ? ' checked' : '' ?>> <?= e($languageName) ?> <small>(/<?= e($code) ?>/<?= Kaleta\Core\Language::hasVisitorTexts($code) ? '' : ' · ' . e(t('visitor texts in English')) ?>)</small></label>
<?php endforeach ?>
		</div>
<?php // 3.11 (UX310-7): a language visitors cannot switch to yet, and why
$waiting = array_diff(Kaleta\Core\Language::additional($app->settings()), Kaleta\Core\Language::published($app->settings(), $app->db()));
if ($waiting !== []): ?>
		<span class="napoveda"><?= e(t('Not in the language switcher yet: %s. A language appears there once the home page has a published translation.', implode(', ', array_map(fn (string $c): string => Kaleta\Core\Language::AVAILABLE[$c][0], $waiting)))) ?> <a href="<?= e($app->url('admin.php?module=pages&action=translations')) ?>"><?= e(t('Translations')) ?></a></span>
<?php endif ?>
		<span class="napoveda"><?= e(t('Each version has its own pages, categories and news. A news item\'s language is set by its category. Link translations in the editor.')) ?></span>
<?php $translated = array_map(fn (string $code): string => Kaleta\Core\Language::AVAILABLE[$code][0], array_values(array_filter(array_keys(Kaleta\Core\Language::AVAILABLE), Kaleta\Core\Language::hasVisitorTexts(...)))); ?>
		<span class="napoveda"><?= e(t('Texts for visitors (Search, Read more…) are translated into: %s. Other languages show them in English, with dates in their own format. You write the content of pages and news in the language of the version.', implode(', ', $translated))) ?></span>
	</div>
</div>
<?php else: ?>
<?php foreach (array_filter(explode(',', $values['additional_languages'])) as $code): ?><input type="hidden" name="additional_languages[]" value="<?= e($code) ?>"><?php endforeach ?>
<?php endif ?>
<?php // 3.19: the same address once in each language version is on for every site (migration 0106); the switch is offered only
// where the update could not switch it on – with the reason – and it only switches on (running the same checks again);
// a site that is on but where the migration recorded a failure (a key missing, N319-1) gets it ticked, to run it again
$slugFailure = Kaleta\Core\Slug::failure($app->settings());
if ($values['slugs_per_language'] !== '1' || $slugFailure !== null):
    if ($slugFailure !== null): ?>
<p class="hlaska hlaska-varovani"><?= e(Kaleta\Core\Slug::failureText($slugFailure, true, $values['slugs_per_language'] === '1')) ?></p>
<?php endif ?>
<?php $field('slugs_per_language', 'The same address in every language', 'ano', 'A page, a news item, a category or a tag may have the same address as one in another language version (/kontakt and /en/kontakt), as on a multilingual WordPress site. System addresses and language codes stay reserved in every language. Since Kaleta 3.19 this is how every site works – it can no longer be switched off.'); ?>
<?php endif ?>
</fieldset>
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
