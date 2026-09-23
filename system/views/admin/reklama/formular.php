<?php
/**
 * Reklama: co se zobrazí, kde - a volitelně kdy. Pole druhého druhu reklamy se schovají (admin.js, data-pro).
 *
 * @var MiroCMS\Admin\Moduly\Reklama $modul
 * @var string $csrf
 * @var array<string, mixed> $reklama
 * @var array<string, string> $chyby
 */
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
$dt = fn (?string $v): string => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$pozice = [
    'sloupec' => ['Ve sloupci', 'Čtverec nebo obdélník v bočním sloupci, např. 300×250.'],
    'pod-clankem' => ['Pod článkem', 'Pod každým článkem – zobrazuje se sama, blok není potřeba.'],
    'hlavicka' => ['V hlavičce', 'Široký pruh nahoře, např. 970×210.'],
    'paticka' => ['V patičce', 'Široký pruh dole.'],
];
$planovani = ($reklama['jen_rubrika'] ?? null) !== null || ($reklama['zarizeni'] ?? 'vse') !== 'vse' || $reklama['platna_od'] || $reklama['platna_do'] || $reklama['max_zobrazeni'] !== null || (int) $reklama['vaha'] !== 1 || !$reklama['aktivni'];
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>" data-prepinac="typ">
<?= $csrf ?>
<input type="hidden" name="idr" value="<?= (int) $reklama['idr'] ?>">
<div class="radek">
	<label for="nazev"><?= e(t('Název')) ?></label>
	<div><input class="textpole siroke" type="text" id="nazev" name="nazev" value="<?= e($reklama['nazev']) ?>" maxlength="150" required placeholder="<?= e(t('např. Knihkupectví – podzimní akce')) ?>"><?= $chyba('nazev') ?><span class="napoveda"><?= e(t('Jen pro vás, na webu se neukazuje.')) ?></span></div>
</div>

<fieldset>
<legend><?= e(t('Co se má zobrazit')) ?></legend>
<div class="karty-volby karty-volby-text">
	<label class="karta-volba"><input type="radio" name="typ" value="obrazek"<?= $reklama['typ'] !== 'kod' ? ' checked' : '' ?>><strong><?= e(t('Banner')) ?></strong><span><?= e(t('Váš obrázek s odkazem. Počítají se zobrazení i prokliky.')) ?></span></label>
	<label class="karta-volba"><input type="radio" name="typ" value="kod"<?= $reklama['typ'] === 'kod' ? ' checked' : '' ?>><strong><?= e(t('Kód reklamní sítě')) ?></strong><span><?= e(t('Sklik, Google AdSense a podobně – vložíte kód, který vám síť dala.')) ?></span></label>
</div>
<div class="radek" data-pro="obrazek"><label for="obrazek"><?= e(t('Obrázek')) ?></label><div><input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($reklama['obrazek']) ?>" maxlength="255" data-obrazek><?= $chyba('obrazek') ?></div></div>
<div class="radek" data-pro="obrazek"><label for="cil_url"><?= e(t('Kam banner vede')) ?></label><input class="textpole siroke" type="url" id="cil_url" name="cil_url" value="<?= e($reklama['cil_url']) ?>" maxlength="500" placeholder="https://"></div>
<div class="radek" data-pro="kod"><label for="kod"><?= e(t('Kód')) ?></label><div><textarea class="textbox kod" id="kod" name="kod" rows="6" spellcheck="false"><?= e((string) $reklama['kod']) ?></textarea><?= $chyba('kod') ?><span class="napoveda"><?= e(t('Při zapnuté cookie liště se spustí až po souhlasu návštěvníka s marketingem.')) ?></span></div></div>
</fieldset>

<fieldset>
<legend><?= e(t('Kde')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach ($pozice as $klic => [$nazev, $popis]): ?>
	<label class="karta-volba"><input type="radio" name="pozice" value="<?= e($klic) ?>"<?= $reklama['pozice'] === $klic ? ' checked' : '' ?>><strong><?= e(t($nazev)) ?></strong><span><?= e(t($popis)) ?></span></label>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Pozice ve sloupci, hlavičce a patičce umístíte na web blokem „Reklama“ (Bloky a rozvržení → Přidat blok).')) ?></p>
</fieldset>

<details class="pokrocile"<?= $planovani ? ' open' : '' ?>>
<summary><?= e(t('Plánování, cílení a limity')) ?></summary>
<div class="radek"><label for="jen_rubrika"><?= e(t('Jen v rubrice')) ?></label><div><select id="jen_rubrika" name="jen_rubrika">
	<option value="0"><?= e(t('ve všech')) ?></option>
<?php foreach (MiroCMS\Admin\Moduly\Rubriky::strom($modul->app()->db()) as $rub): ?>
	<option value="<?= (int) $rub['idt'] ?>"<?= (int) ($reklama['jen_rubrika'] ?? 0) === (int) $rub['idt'] ? ' selected' : '' ?>><?= e(str_repeat('– ', (int) $rub['uroven']) . $rub['nazev']) ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('Reklama se ukáže jen ve výpisu této rubriky a u jejích článků.')) ?></span></div></div>
<div class="radek"><label for="zarizeni"><?= e(t('Zařízení')) ?></label><select id="zarizeni" name="zarizeni">
	<option value="vse"><?= e(t('všechna')) ?></option>
	<option value="mobil"<?= ($reklama['zarizeni'] ?? '') === 'mobil' ? ' selected' : '' ?>><?= e(t('jen telefony')) ?></option>
	<option value="pocitac"<?= ($reklama['zarizeni'] ?? '') === 'pocitac' ? ' selected' : '' ?>><?= e(t('jen počítače a tablety')) ?></option>
</select></div>
<div class="radek"><label for="platna_od"><?= e(t('Zobrazovat od')) ?></label><input class="textpole" type="datetime-local" id="platna_od" name="platna_od" value="<?= e($dt($reklama['platna_od'])) ?>"></div>
<div class="radek"><label for="platna_do"><?= e(t('Zobrazovat do')) ?></label><input class="textpole" type="datetime-local" id="platna_do" name="platna_do" value="<?= e($dt($reklama['platna_do'])) ?>"></div>
<div class="radek"><label for="max_zobrazeni"><?= e(t('Nejvýše zobrazení')) ?></label><div><input class="textpole" type="number" id="max_zobrazeni" name="max_zobrazeni" value="<?= e((string) $reklama['max_zobrazeni']) ?>" min="0"><span class="napoveda"><?= e(t('Po dosažení se reklama vypne sama.')) ?></span></div></div>
<div class="radek"><label for="vaha"><?= e(t('Váha')) ?></label><div><input class="textpole" type="number" id="vaha" name="vaha" value="<?= (int) $reklama['vaha'] ?>" min="1" max="10"><span class="napoveda"><?= e(t('Když je na pozici víc reklam: váha 2 = zobrazí se dvakrát častěji než váha 1.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('Stav')) ?></span><div class="volby"><label><input type="checkbox" name="aktivni" value="1"<?= $reklama['aktivni'] ? ' checked' : '' ?>> <?= e(t('reklama je zapnutá')) ?></label></div></div>
</details>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"></p>
</form>
