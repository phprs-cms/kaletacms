<?php
/**
 * @var Kaleta\Admin\Moduly\Stitky $modul
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $stitky
 * @var array<string, mixed>|null $uprav
 */
?>
<p class="smltxt"><?= e(t('Štítky vznikají samy při psaní novinek. Když štítku doplníte popis, stane se z jeho stránky téma – úvod k oboru nebo projektu se všemi novinkami na jednom místě.')) ?></p>
<?php if ($uprav !== null): ?>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>" id="uprav">
<?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $uprav['ids'] ?>">
<fieldset>
<legend><?= e(t('Úprava štítku')) ?></legend>
<div class="radek"><label for="nazev"><?= e(t('Název')) ?></label><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($uprav['nazev']) ?>" maxlength="80" required></div>
<div class="radek"><label for="popis"><?= e(t('Úvod tématu')) ?></label><div><textarea class="textbox" id="popis" name="popis" rows="5" data-editor="maly"><?= e((string) $uprav['popis']) ?></textarea><span class="napoveda"><?= e(t('Nepovinné. Zobrazí se nad výpisem novinek a jako popis pro vyhledávače.')) ?></span></div></div>
<div class="radek"><label for="obrazek"><?= e(t('Obrázek tématu')) ?></label><input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($uprav['obrazek']) ?>" maxlength="255" data-obrazek></div>
<details class="pokrocile">
<summary><?= e(t('Sloučit s jiným štítkem')) ?></summary>
<div class="radek"><label for="sloucit_do"><?= e(t('Sloučit do')) ?></label><div><select id="sloucit_do" name="sloucit_do">
	<option value="0"><?= e(t('– nesloučit –')) ?></option>
<?php foreach ($stitky as $s): if ((int) $s['ids'] !== (int) $uprav['ids']): ?>
	<option value="<?= (int) $s['ids'] ?>"><?= e($s['nazev']) ?> (<?= (int) $s['pocet'] ?>)</option>
<?php endif; endforeach ?>
</select><span class="napoveda"><?= e(t('Novinky dostanou vybraný štítek, tento zanikne a jeho adresa se přesměruje. Hodí se na překlepy a dvojí psaní.')) ?></span></div></div>
</details>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"> <a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zrušit')) ?></a></p>
</form>
<?php endif ?>
<?php if ($stitky === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'stitky', 'nadpis' => t('Zatím žádné štítky.'), 'text' => t('Přidáte je v editoru novinky v poli Štítky. Tady je pak půjde slučovat a měnit na stránky témat.')]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Štítek')) ?></th><th scope="col"><?= e(t('Novinek')) ?></th><th scope="col"><?= e(t('Téma')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($stitky as $s): ?>
<tr>
	<td><a href="<?= e($app->url('novinky/stitek/' . $s['seo_link'])) ?>" target="_blank" rel="noopener">#<?= e($s['nazev']) ?></a></td>
	<td class="cislo"><?= (int) $s['pocet'] ?></td>
	<td><?= trim((string) $s['popis']) !== '' ? '<span class="stitek stitek-vydano">' . e(t('má úvod')) . '</span>' : '' ?></td>
	<td class="akce"><a href="<?= e($modul->url('', ['uprav' => $s['ids']])) ?>#uprav"><?= e(t('Upravit')) ?></a>
		<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Smazat štítek? Novinky zůstanou, jen ho už nebudou mít.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
