<?php
/**
 * Language version choice in a list filter (pages, categories, collection items). A site with a single language outputs nothing.
 * With $submitOnChange the list is filtered right after the choice (a form without other fields).
 *
 * @var list<string> $siteLanguages
 * @var string $language the selected code ('' = all)
 * @var bool|null $submitOnChange
 */
if ($siteLanguages === []) {
    return;
}
?>
	<label><?= e(t('Language:')) ?>
		<select name="language"<?= !empty($submitOnChange) ? ' data-odeslat-pri-zmene' : '' ?>>
			<option value=""><?= e(t('všechny')) ?></option>
<?php foreach ($siteLanguages as $code): ?>
			<option value="<?= e($code) ?>"<?= $language === $code ? ' selected' : '' ?>><?= e(Kaleta\Core\Language::AVAILABLE[$code][0]) ?></option>
<?php endforeach ?>
		</select>
	</label>
