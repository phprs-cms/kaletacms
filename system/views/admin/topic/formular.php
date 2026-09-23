<?php
/**
 * @var MiroCMS\Admin\Moduly\Rubriky $modul
 * @var string $csrf
 * @var array<string, mixed> $rubrika
 * @var array<string, string> $chyby
 * @var list<array<string, mixed>> $rubriky
 * @var list<int> $zakazane
 */
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<input type="hidden" name="idt" value="<?= (int) $rubrika['idt'] ?>">
<div class="radek">
	<label for="nazev"><?= e(t('Název rubriky')) ?></label>
	<div><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($rubrika['nazev']) ?>" maxlength="100" required><?= $chyba('nazev') ?></div>
</div>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa rubriky')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_link" name="seo_link" value="<?= e($rubrika['seo_link']) ?>" maxlength="110" placeholder="<?= e(t('vytvoří se z názvu')) ?>"></div>
</div>
<div class="radek">
	<label for="id_predka"><?= e(t('Nadřazená rubrika')) ?></label>
	<div><select id="id_predka" name="id_predka">
		<option value="0"><?= e(t('- žádná (hlavní rubrika) -')) ?></option>
<?php foreach ($rubriky as $r): if (in_array((int) $r['idt'], $zakazane, true)) { continue; } ?>
		<option value="<?= (int) $r['idt'] ?>"<?= (int) $rubrika['id_predka'] === (int) $r['idt'] ? ' selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;', $r['uroven']) . e($r['nazev']) ?></option>
<?php endforeach ?>
	</select><?= $chyba('id_predka') ?></div>
</div>
<div class="radek">
	<label for="popis"><?= e(t('Popis')) ?></label>
	<div><textarea class="textbox" id="popis" name="popis" rows="4"><?= e($rubrika['popis']) ?></textarea>
	<span class="napoveda"><?= e(t('Zobrazí se nad výpisem článků rubriky a použije se jako meta description.')) ?></span></div>
</div>
<div class="radek">
	<label for="obrazek"><?= e(t('Obrázek (ikona) rubriky')) ?></label>
	<input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($rubrika['obrazek']) ?>" maxlength="255" placeholder="<?= e(t('nepovinné, adresa obrázku')) ?>">
</div>
<div class="radek">
	<label for="hodnost"><?= e(t('Pořadí')) ?></label>
	<div><input class="textpole" type="number" id="hodnost" name="hodnost" value="<?= (int) $rubrika['hodnost'] ?>" min="0" max="65535">
	<span class="napoveda"><?= e(t('Vyšší číslo = výš v seznamu rubrik.')) ?></span></div>
</div>
<?= $app->view->render('admin/jazyk_pole', ['app' => $app, 'hodnota' => (string) ($rubrika['jazyk'] ?? ''), 'prekladZ' => (int) ($rubrika['preklad_z'] ?? 0), 'originaly' => $app->db()->pairs("SELECT idt, nazev FROM {topic} WHERE jazyk = '' ORDER BY nazev"), 'napoveda' => t('Články v rubrice patří do této jazykové verze webu.')]) ?>
<div class="radek">
	<span class="popisek"><?= e(t('Zobrazit')) ?></span>
	<div class="volby"><label><input type="checkbox" name="zobrazit" value="1"<?= $rubrika['zobrazit'] ? ' checked' : '' ?>> <?= e(t('Ano, zobrazovat v seznamu rubrik')) ?></label></div>
</div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($rubrika['idt'] ? 'Uložit' : 'Přidat')) ?>"></p>
</form>
