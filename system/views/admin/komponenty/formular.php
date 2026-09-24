<?php
/**
 * Název a vlastnosti komponenty.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Komponenty $modul
 * @var string $csrf
 * @var array<string, mixed> $k
 */
use Kaleta\Stavitel\Komponenty;

$vlastnosti = array_merge($k['vlastnosti'], array_fill(0, 3, ['klic' => '', 'popisek' => '', 'typ' => 'text', 'vychozi' => '']));
?>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<input type="hidden" name="idm" value="<?= (int) $k['idm'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Název komponenty')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" value="<?= e($k['nazev']) ?>" maxlength="100" required placeholder="<?= e(t('např. Karta služby')) ?>"></div></div>
<fieldset>
<legend><?= e(t('Vlastnosti')) ?></legend>
<p class="napoveda"><?= e(t('Co se u každého použití komponenty může lišit – nadpis, text, obrázek, odkaz. Ve staviteli je do komponenty vložíte značkou {{klíč}}, u použití pak vyplníte hodnotu (prázdná = výchozí).')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Popisek')) ?></th><th scope="col"><?= e(t('Typ')) ?></th><th scope="col"><?= e(t('Výchozí hodnota')) ?></th><th scope="col"><?= e(t('Značka')) ?></th></tr></thead>
<tbody>
<?php foreach ($vlastnosti as $i => $v): ?>
<tr>
	<td><input class="textpole" name="vlastnosti[<?= $i ?>][popisek]" value="<?= e($v['popisek']) ?>" maxlength="80" aria-label="<?= e(t('Popisek')) ?>"><input type="hidden" name="vlastnosti[<?= $i ?>][klic]" value="<?= e($v['klic']) ?>"></td>
	<td><select name="vlastnosti[<?= $i ?>][typ]" aria-label="<?= e(t('Typ')) ?>">
<?php foreach (Komponenty::TYPY as $typ => $nazev): ?>
		<option value="<?= e($typ) ?>"<?= $v['typ'] === $typ ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select></td>
	<td><input class="textpole" name="vlastnosti[<?= $i ?>][vychozi]" value="<?= e($v['vychozi']) ?>" maxlength="500" aria-label="<?= e(t('Výchozí hodnota')) ?>"></td>
	<td><?= $v['klic'] !== '' ? '<code>{{' . e($v['klic']) . '}}</code>' : '<span class="napoveda">' . e(t('vznikne z popisku')) . '</span>' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit komponentu')) ?>"> <a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět')) ?></a></p>
</form>
