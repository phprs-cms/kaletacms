<?php
/**
 * Živá reportáž: psaní průběžných zápisů.
 *
 * @var MiroCMS\Admin\Moduly\Clanky $modul
 * @var string $csrf
 * @var array<string, mixed> $clanek
 * @var list<array<string, mixed>> $zapisy
 */
$bezi = (int) $clanek['zive'] === 1;
?>
<p class="navigace-radek">
	<a class="navigace" href="<?= e($modul->url('edit', ['id' => (int) $clanek['idc']])) ?>"><?= e(t('Zpět do článku')) ?></a>
	<a class="navigace" href="<?= e($modul->app()->url('clanek/' . $clanek['seo_link']) . ($clanek['visible'] ? '' : '?nahled=1')) ?>" target="_blank" rel="noopener"><?= e(t('Zobrazit na webu')) ?></a>
</p>
<h3 class="stred"><?= e($clanek['titulek']) ?> <span class="stitek stitek-<?= $bezi ? 'vydano' : 'koncept' ?>"><?= e(t($bezi ? 'běží' : 'neběží')) ?></span></h3>
<?php if (!$clanek['visible']): ?>
<p class="hlaska"><?= e(t('Článek zatím není vydaný – zápisy uvidí čtenáři až po vydání.')) ?></p>
<?php endif ?>
<form class="formular" method="post" action="<?= e($modul->url('zive')) ?>">
<?= $csrf ?>
<input type="hidden" name="idc" value="<?= (int) $clanek['idc'] ?>">
<div class="radek pres-celou">
	<label for="text"><?= e(t('Nový zápis')) ?></label>
	<textarea class="textbox" id="text" name="text" rows="5" data-editor="maly"></textarea>
</div>
<p class="tlacitka">
	<input class="tl" type="submit" value="<?= e(t('Zveřejnit zápis')) ?>">
	<label><input type="checkbox" name="dulezite" value="1"> <?= e(t('Důležitý – zvýraznit')) ?></label>
</p>
</form>
<form method="post" action="<?= e($modul->url('zive')) ?>"<?= $bezi ? ' data-potvrdit="' . e(t('Ukončit živou reportáž? Zápisy na webu zůstanou, jen se přestanou načítat nové.')) . '"' : '' ?>>
<?= $csrf ?><input type="hidden" name="idc" value="<?= (int) $clanek['idc'] ?>"><input type="hidden" name="stav" value="<?= $bezi ? 'ukoncit' : 'spustit' ?>">
<p><button class="navigace" type="submit"><?= e(t($bezi ? 'Ukončit reportáž' : 'Spustit reportáž')) ?></button> <span class="smltxt"><?= e(t('Čtenářům se nové zápisy načítají samy každých 30 vteřin.')) ?></span></p>
</form>
<?php if ($zapisy !== []): ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Čas')) ?></th><th scope="col"><?= e(t('Zápis')) ?></th><th scope="col"><?= e(t('Autor')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($zapisy as $z): ?>
<tr>
	<td class="cislo"><?= e(datum($z['cas'], true)) ?></td>
	<td><?= $z['dulezite'] ? '<strong>' : '' ?><?= e(mb_strimwidth(trim(strip_tags($z['text'])), 0, 160, '…')) ?><?= $z['dulezite'] ? '</strong>' : '' ?></td>
	<td><?= e((string) $z['autor_jm']) ?></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($modul->url('zive')) ?>" data-potvrdit="<?= e(t('Smazat zápis?')) ?>"><?= $csrf ?><input type="hidden" name="idc" value="<?= (int) $clanek['idc'] ?>"><input type="hidden" name="smazat" value="<?= (int) $z['idz'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
