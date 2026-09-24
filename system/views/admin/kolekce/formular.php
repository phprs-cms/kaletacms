<?php
/**
 * Definice kolekce: název, adresa, stránky položek a pole.
 *
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Kolekce $modul
 * @var string $csrf
 * @var array<string, mixed> $k
 */
use MiroCMS\Stavitel\Kolekce;

$pole = array_merge($k['pole'], array_fill(0, 3, ['klic' => '', 'popisek' => '', 'typ' => 'text']));
?>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Název kolekce')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($k['nazev']) ?>" maxlength="100" required placeholder="<?= e(t('např. Reference, Tým, Produkty')) ?>"></div></div>
<div class="radek"><label for="seo_link"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="seo_link" name="seo_link" value="<?= e($k['seo_link']) ?>" maxlength="110"><span class="napoveda"><?= e(t('Z názvu, když ji nevyplníte. Stránky položek pak budou na /adresa/nazev-polozky.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Stránky položek')) ?></span><div class="volby"><label><input type="checkbox" name="detail" value="1"<?= $k['detail'] ? ' checked' : '' ?>> <?= e(t('každá položka má vlastní stránku (detail)')) ?></label>
<span class="napoveda"><?= e(t('Vzhled detailu navrhnete ve staviteli (Šablona detailu). Bez detailu jsou položky jen karty ve výpisu.')) ?></span></div></div>
<fieldset>
<legend><?= e(t('Pole položek')) ?></legend>
<p class="napoveda"><?= e(t('Každá položka má vždy název. Přidejte pole, která potřebujete – ve staviteli je vložíte značkou {{klíč}}. Prázdný popisek pole odebere; klíč už uloženého pole se nemění.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Popisek')) ?></th><th scope="col"><?= e(t('Typ')) ?></th><th scope="col"><?= e(t('Značka')) ?></th></tr></thead>
<tbody>
<?php foreach ($pole as $i => $p): ?>
<tr>
	<td><input class="textpole" name="pole[<?= $i ?>][popisek]" value="<?= e($p['popisek']) ?>" maxlength="80" aria-label="<?= e(t('Popisek')) ?>"><input type="hidden" name="pole[<?= $i ?>][klic]" value="<?= e($p['klic']) ?>"></td>
	<td><select name="pole[<?= $i ?>][typ]" aria-label="<?= e(t('Typ')) ?>">
<?php foreach (Kolekce::TYPY_POLI as $typ => $nazev): ?>
		<option value="<?= e($typ) ?>"<?= $p['typ'] === $typ ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select></td>
	<td><?= $p['klic'] !== '' ? '<code>{{' . e($p['klic']) . '}}</code>' : '<span class="napoveda">' . e(t('vznikne z popisku')) . '</span>' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit kolekci')) ?>"> <a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět')) ?></a></p>
</form>
<?php if ($k['idk'] > 0): ?>
<form class="formular" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Smazat kolekci i se všemi položkami? Výpisy na webu zmizí.')) ?>">
<?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>">
<button class="navigace nebezpecne" type="submit"><?= e(t('Smazat kolekci')) ?></button>
</form>
<?php endif ?>
