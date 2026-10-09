<?php
/**
 * Comparison of a saved version of a news item with its current wording.
 *
 * @var Kaleta\Admin\Modules\News $module
 * @var array<string, mixed> $newsItem
 * @var array<string, mixed> $versions
 * @var array{html:string, pridano:int, smazano:int} $title
 * @var array{html:string, pridano:int, smazano:int} $home
 * @var array{html:string, pridano:int, smazano:int} $text
 */
$added = $title['pridano'] + $home['pridano'] + $text['pridano'];
$deleted = $title['smazano'] + $home['smazano'] + $text['smazano'];
?>
<p class="navigace-radek">
	<a class="navigace" href="<?= e($module->url('edit', ['id' => (int) $newsItem['idc']])) ?>"><?= e(t('Back to the news item')) ?></a>
	<a class="navigace" href="<?= e($module->url('versions', ['id' => (int) $newsItem['idc'], 'revision' => (int) $versions['idr']])) ?>"><?= e(t('Load this version into the editor')) ?></a>
</p>
<p><?= e(t('Version from %s', format_date($versions['datum'], true))) ?><?= ($versions['kdo_jm'] ?? '') !== '' ? ' · ' . e($versions['kdo_jm']) : '' ?> → <?= e(t('current text')) ?>.
	<ins><?= e(t('added')) ?>: <?= $added ?></ins> · <del><?= e(t('deleted')) ?>: <?= $deleted ?></del></p>
<?php if ($added + $deleted === 0): ?>
<p class="hlaska"><?= e(t('The text has not changed since this version (formatting and image changes are not compared).')) ?></p>
<?php endif ?>
<div class="porovnani">
	<h2><?= e(t('Titulek')) ?></h2>
	<div class="porovnani-titulek"><?= $title['html'] ?></div>
	<h2><?= e(t('Lead paragraph')) ?></h2>
	<?= $home['html'] ?>
	<h2><?= e(t('Text')) ?></h2>
	<?= $text['html'] ?>
</div>
