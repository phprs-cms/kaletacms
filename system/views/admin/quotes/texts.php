<?php
/**
 * The default texts of new quotes (3.38, Admin\QuoteSending::actionTextsSave, administrators): the text above the lines,
 * the terms below them and the document's footer – for the default language and each additional language of the site
 * (an empty one takes the default language's, Core\QuoteDelivery::defaultText). A quote copies intro and terms when it
 * is created; the footer goes into the document when it is sent.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $csrf
 */
$s = $app->settings();
$labels = ['intro' => 'Text above the lines', 'terms' => 'Terms below the lines', 'footer' => 'Footer of the document'];
?>
<form method="post" action="<?= e($module->url('texts_save')) ?>" class="formular" data-quotes-texts>
	<?= $csrf ?>
	<h2><?= e(t('Default texts')) ?></h2>
	<p class="napoveda"><?= e(t('A new quote starts with these texts in its language; each quote can change them. The customer sees them in the document.')) ?></p>
<?php foreach (Kaleta\Core\QuoteDelivery::textLanguages($s) as $language): ?>
<?php if ($language !== ''): ?>
	<h3><?= e(Kaleta\Core\Language::AVAILABLE[$language][0] ?? $language) ?></h3>
<?php endif ?>
<?php foreach (Kaleta\Core\QuoteDelivery::TEXTS as $key => $setting): $name = $setting . ($language !== '' ? '_' . $language : ''); ?>
	<div class="radek"><label for="nabidky-<?= e($name) ?>"><?= e(t($labels[$key])) ?></label><div><textarea class="textbox nizky" id="nabidky-<?= e($name) ?>" name="<?= e($name) ?>" rows="3" maxlength="<?= Kaleta\Core\Quotes::TEXT_MAX ?>"<?= $language !== '' ? ' placeholder="' . e(t('Empty = the text of the default language')) . '"' : '' ?>><?= e($s->get($name)) ?></textarea></div></div>
<?php endforeach ?>
<?php endforeach ?>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save texts')) ?></button></p>
</form>
