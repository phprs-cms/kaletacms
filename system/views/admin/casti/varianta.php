<?php
/**
 * Varianta záhlaví nebo patičky: název a stránky, na kterých platí místo výchozí podoby.
 *
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Casti $modul
 * @var string $csrf
 * @var string $typ
 * @var string $jazyk
 * @var string $varianta
 * @var string $nazev
 * @var list<int> $vybrane
 * @var list<array{ids: int, titulek: string}> $stranky
 */
?>
<form class="formular" method="post" action="<?= e($modul->url('uloz_variantu', ['typ' => $typ, 'jazyk' => $jazyk])) ?>">
<?= $csrf ?>
<input type="hidden" name="varianta" value="<?= e($varianta) ?>">
<p class="napoveda"><?= e(t('Varianta platí jen na vybraných stránkách, jinde zůstává výchozí podoba. Třeba landing page s jednodušším záhlavím. Když ve staviteli necháte variantu prázdnou, stránka záhlaví (patičku) mít nebude.')) ?></p>
<div class="radek"><label for="nazev"><?= e(t('Název varianty')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($nazev) ?>" maxlength="100" required placeholder="<?= e(t('např. Landing page')) ?>"></div></div>
<div class="radek"><span class="popisek"><?= e(t('Stránky')) ?></span><div class="volby">
<?php foreach ($stranky as $s): ?>
	<label><input type="checkbox" name="stranky[]" value="<?= (int) $s['ids'] ?>"<?= in_array((int) $s['ids'], $vybrane, true) ? ' checked' : '' ?>> <?= e($s['titulek']) ?></label><br>
<?php endforeach ?>
</div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($varianta === '' ? 'Vytvořit a otevřít ve staviteli' : 'Uložit')) ?>"> <a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět')) ?></a></p>
</form>
