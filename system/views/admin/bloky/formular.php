<?php
/**
 * @var MiroCMS\Admin\Moduly\Bloky $modul
 * @var string $csrf
 * @var array<string, mixed> $blok
 * @var array<string, string> $chyby
 * @var array<string, string> $zony  zóny dostupné ve zvoleném rozvržení
 * @var list<array<string, mixed>> $rubriky
 */
use MiroCMS\Admin\Moduly\Bloky;
use MiroCMS\Admin\Moduly\Reklama;
use MiroCMS\Core\Jazyk;

$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
$data = (string) ($blok['data_sys'] ?? '');
[$dataRubrika, $dataPocet] = $blok['sys_funkce'] === 'cla' ? array_map(intval(...), explode(':', $data . ':5')) : [0, (int) $data ?: 5];
[$podTlacitko, $podAdresa] = $blok['sys_funkce'] === 'pod' ? explode('|', $data . '|') : ['', ''];
$dalsiJazyky = Jazyk::dalsi($app->settings());
$rubrikySelect = function (string $name, int $vybrana, string $prazdna) use ($rubriky): void { ?>
	<select id="<?= e($name) ?>" name="<?= e($name) ?>">
		<option value="0"><?= e($prazdna) ?></option>
<?php foreach ($rubriky as $r): ?>
		<option value="<?= (int) $r['idt'] ?>"<?= $vybrana === (int) $r['idt'] ? ' selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;', $r['uroven']) . e($r['nazev']) ?></option>
<?php endforeach ?>
	</select>
<?php };
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>" data-blok-formular>
<?= $csrf ?>
<input type="hidden" name="idb" value="<?= (int) $blok['idb'] ?>">
<div class="radek">
	<label for="sys_funkce"><?= e(t('Typ bloku')) ?></label>
	<select id="sys_funkce" name="sys_funkce">
		<option value=""><?= e(t('Vlastní obsah (HTML, vložený kód, video…)')) ?></option>
<?php foreach (Bloky::SYSTEMOVE as $zkratka => $nazev): ?>
		<option value="<?= e($zkratka) ?>"<?= $blok['sys_funkce'] === $zkratka ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="radek">
	<label for="nazev"><?= e(t('Nadpis bloku')) ?></label>
	<div><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($blok['nazev']) ?>" maxlength="100" required><?= $chyba('nazev') ?></div>
</div>
<div class="radek" data-pro=" pod">
	<label for="obsah"><?= e(t('Vlastní obsah (HTML)')) ?></label>
	<textarea class="textbox kod" id="obsah" name="obsah" rows="10"><?= e($blok['obsah']) ?></textarea>
</div>
<div class="radek" data-pro="men">
	<label for="obsah-menu"><?= e(t('Odkazy menu')) ?></label>
	<div><textarea class="textbox nizky" id="obsah-menu" name="obsah_menu" rows="6" placeholder="<?= e(t('O nás | /o-nas')) ?>&#10;<?= e(t('Inzerce | /inzerce')) ?>&#10;Facebook | https://facebook.com/…"><?= $blok['sys_funkce'] === 'men' ? e($blok['obsah']) : '' ?></textarea>
	<span class="napoveda"><?= e(t('Každý odkaz na vlastní řádek ve tvaru: text | adresa.')) ?></span></div>
</div>
<div class="radek" data-pro="cla">
	<label for="blok_rubrika"><?= e(t('Rubrika')) ?></label>
	<div><?php $rubrikySelect('blok_rubrika', $dataRubrika, t('– nejnovější ze všech rubrik –')) ?></div>
</div>
<div class="radek" data-pro="cla nej sti aut arc">
	<label for="blok_pocet"><?= e(t('Počet položek')) ?></label>
	<input class="textpole" type="number" id="blok_pocet" name="blok_pocet" value="<?= $dataPocet ?>" min="1" max="50">
</div>
<div class="radek" data-pro="nej">
	<label for="blok_obdobi"><?= e(t('Období')) ?></label>
	<div><select id="blok_obdobi" name="blok_obdobi">
<?php foreach (Bloky::OBDOBI_NEJ as $dni => $popis): ?>
		<option value="<?= $dni ?>"<?= $blok['sys_funkce'] === 'nej' && Bloky::obdobiNej($data) === $dni ? ' selected' : '' ?>><?= e(t($popis)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Počítá se z vlastní statistiky návštěv; bez ní podle celkového počtu přečtení.')) ?></span></div>
</div>
<div class="radek" data-pro="rek">
	<label for="data_sys"><?= e(t('Reklamní pozice')) ?></label>
	<select id="data_sys" name="data_sys">
<?php foreach (Reklama::POZICE as $klic => $nazev): if ($klic === 'pod-clankem') { continue; } ?>
		<option value="<?= e($klic) ?>"<?= $data === $klic ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="radek" data-pro="pod">
	<label for="pod_tlacitko"><?= e(t('Text tlačítka')) ?></label>
	<input class="textpole" type="text" id="pod_tlacitko" name="pod_tlacitko" value="<?= e($podTlacitko) ?>" maxlength="60" placeholder="<?= e(t('Podpořit redakci')) ?>">
</div>
<div class="radek" data-pro="pod">
	<label for="pod_adresa"><?= e(t('Kam tlačítko vede')) ?></label>
	<div><input class="textpole siroke" type="text" id="pod_adresa" name="pod_adresa" value="<?= e($podAdresa) ?>" maxlength="190" placeholder="https://… /podporte-nas">
	<span class="napoveda"><?= e(t('Platební odkaz (Stripe, Donio, Darujme, Ko-fi…) nebo vlastní stránka s číslem účtu a QR kódem.')) ?></span></div>
</div>

<fieldset>
<legend><?= e(t('Umístění a zobrazení')) ?></legend>
<div class="radek">
	<label for="zona"><?= e(t('Umístění')) ?></label>
	<div><select id="zona" name="zona">
<?php foreach ($zony as $klic => $nazev): ?>
		<option value="<?= e($klic) ?>"<?= $blok['zona'] === $klic ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Pořadí uvnitř zóny změníte přetažením v přehledu bloků.')) ?></span></div>
</div>
<div class="radek">
	<label for="typ"><?= e(t('Vzhled bloku')) ?></label>
	<select id="typ" name="typ">
<?php foreach (Bloky::VZHLEDY as $cislo => $nazev): ?>
		<option value="<?= $cislo ?>"<?= (int) $blok['typ'] === $cislo ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="radek">
	<label for="zobrazit_kde"><?= e(t('Na kterých stránkách')) ?></label>
	<select id="zobrazit_kde" name="zobrazit_kde">
<?php foreach (Bloky::KDE as $hodnota => $popis): ?>
		<option value="<?= $hodnota ?>"<?= (int) $blok['zobrazit_kde'] === $hodnota ? ' selected' : '' ?>><?= e(t($popis)) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="radek">
	<label for="jen_rubrika"><?= e(t('Jen v rubrice')) ?></label>
	<div><?php $rubrikySelect('jen_rubrika', (int) ($blok['jen_rubrika'] ?? 0), t('– ve všech –')) ?>
	<span class="napoveda"><?= e(t('Blok se ukáže jen na stránce rubriky a u jejích článků – např. partner sportovní rubriky.')) ?></span></div>
</div>
<?php if ($dalsiJazyky !== []): ?>
<div class="radek">
	<label for="jen_jazyk"><?= e(t('Jazyková verze')) ?></label>
	<select id="jen_jazyk" name="jen_jazyk">
		<option value=""><?= e(t('ve všech jazycích')) ?></option>
<?php foreach (['vy' => Jazyk::vychozi($app->settings())] + array_combine($dalsiJazyky, $dalsiJazyky) as $hodnota => $kod): ?>
		<option value="<?= e($hodnota) ?>"<?= ($blok['jen_jazyk'] ?? '') === $hodnota ? ' selected' : '' ?>><?= e(Jazyk::DOSTUPNE[$kod][0]) ?></option>
<?php endforeach ?>
	</select>
</div>
<?php endif ?>
<div class="radek">
	<label for="zarizeni"><?= e(t('Zařízení')) ?></label>
	<select id="zarizeni" name="zarizeni">
<?php foreach (Bloky::ZARIZENI as $klic => $popis): ?>
		<option value="<?= e($klic) ?>"<?= ($blok['zarizeni'] ?? 'vse') === $klic ? ' selected' : '' ?>><?= e(t($popis)) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Zobrazit blok')) ?></span>
	<div class="volby"><label><input type="checkbox" name="zobrazit" value="1"<?= $blok['zobrazit'] ? ' checked' : '' ?>> <?= e(t('Ano')) ?></label></div>
</div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($blok['idb'] ? 'Uložit' : 'Přidat')) ?>"></p>
</form>
