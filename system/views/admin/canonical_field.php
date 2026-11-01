<?php
/**
 * The optional canonical address under "Search engines and sharing" (3.22, C13, Core\CanonicalUrl): pages, news items
 * and collection items share the field.
 *
 * @var string $value the stored address ('' = the page itself)
 * @var string $error the field's error (HTML from the form's $error helper, '' = none)
 * @var string $hint what the field is for, translated
 */
?>
<div class="radek">
	<label for="canonical_url"><?= e(t('Canonical address')) ?></label>
	<div><input class="textpole siroke" type="text" id="canonical_url" name="canonical_url" value="<?= e($value) ?>" maxlength="500" inputmode="url" spellcheck="false" placeholder="<?= e(t('empty = this page; https://… or /path')) ?>"><?= $error ?>
	<span class="napoveda"><?= e($hint) ?></span></div>
</div>
