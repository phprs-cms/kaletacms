<?php
/**
 * Porovnání uložené verze novinky se současným zněním.
 *
 * @var Kaleta\Admin\Moduly\Novinky $modul
 * @var array<string, mixed> $novinka
 * @var array<string, mixed> $revize
 * @var array{html:string, pridano:int, smazano:int} $titulek
 * @var array{html:string, pridano:int, smazano:int} $uvod
 * @var array{html:string, pridano:int, smazano:int} $text
 */
$pridano = $titulek['pridano'] + $uvod['pridano'] + $text['pridano'];
$smazano = $titulek['smazano'] + $uvod['smazano'] + $text['smazano'];
?>
<p class="navigace-radek">
	<a class="navigace" href="<?= e($modul->url('edit', ['id' => (int) $novinka['idc']])) ?>"><?= e(t('Zpět do novinky')) ?></a>
	<a class="navigace" href="<?= e($modul->url('revize', ['id' => (int) $novinka['idc'], 'idr' => (int) $revize['idr']])) ?>"><?= e(t('Načíst tuto verzi do editoru')) ?></a>
</p>
<p><?= e(t('Verze z %s', datum($revize['datum'], true))) ?><?= ($revize['kdo_jm'] ?? '') !== '' ? ' · ' . e($revize['kdo_jm']) : '' ?> → <?= e(t('současné znění')) ?>.
	<ins><?= e(t('přidáno')) ?>: <?= $pridano ?></ins> · <del><?= e(t('smazáno')) ?>: <?= $smazano ?></del></p>
<?php if ($pridano + $smazano === 0): ?>
<p class="hlaska"><?= e(t('Text se od této verze nezměnil (změny formátování a obrázků se neporovnávají).')) ?></p>
<?php endif ?>
<div class="porovnani">
	<h3><?= e(t('Titulek')) ?></h3>
	<div class="porovnani-titulek"><?= $titulek['html'] ?></div>
	<h3><?= e(t('Perex (úvod)')) ?></h3>
	<?= $uvod['html'] ?>
	<h3><?= e(t('Text')) ?></h3>
	<?= $text['html'] ?>
</div>
