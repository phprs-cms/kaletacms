<?php
/**
 * Ready-made templates of a site part (Builder\PartTemplates): structure only, the look comes from the design system.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\SiteParts $module
 * @var string $csrf
 * @var string $type
 * @var string $language
 * @var string $variant
 * @var list<array{klic: string, nazev: string, popis: string}> $templates
 */
$params = ['type' => $type, 'language' => $language] + ($variant !== '' ? ['variant' => $variant] : []);
?>
<p class="napoveda"><?= e(t('A template is a clean skeleton – colours, fonts and spacing come from your design system. It goes into the draft of the part: adjust it in the builder and publish it; until then visitors see the published version.')) ?></p>
<div class="karty-volby karty-volby-text">
<?php foreach ($templates as $template): ?>
	<form class="karta-volba" method="post" action="<?= e($module->url('apply_template', $params)) ?>">
		<?= $csrf ?><input type="hidden" name="sablona" value="<?= e($template['klic']) ?>">
		<strong><?= e(t($template['nazev'])) ?></strong>
		<span><?= e(t($template['popis'])) ?></span>
		<button class="navigace" type="submit"><?= e(t('Use this template')) ?></button>
	</form>
<?php endforeach ?>
</div>
<p class="navigace-radek akce-dole"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('All site parts')) ?></a></p>
