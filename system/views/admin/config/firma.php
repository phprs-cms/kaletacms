<?php
/** Záložka Firma: údaje pro web (prvek Údaje firmy) a pro vyhledávače (schema.org Organization / LocalBusiness). */
use MiroCMS\Front\Firma;

?>
<p class="hlaska"><?= e(t('Údaje vyplníte jednou a web je použije všude: v patičce a na kontaktu (prvek Údaje firmy ve staviteli) i pro Google, Mapy a AI asistenty – ti tak správně odpoví na otázku, kdy máte otevřeno nebo kde vás najít.')) ?></p>
<fieldset>
<legend><?= e(t('Firma')) ?></legend>
<?php
$pole('firma_nazev', 'Obchodní firma', 'text', 'Přesný název podle rejstříku, např. „Truhlářství Novák s.r.o.“. Na webu se jinak používá název webu.', 'maxlength="200"');
?>
<div class="radek">
	<label for="firma_typ"><?= e(t('Druh podniku')) ?></label>
	<div><select id="firma_typ" name="firma_typ">
<?php foreach (Firma::TYPY as $typ => $popis): ?>
		<option value="<?= e($typ) ?>"<?= $hodnoty['firma_typ'] === $typ ? ' selected' : '' ?>><?= e(t($popis)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Podle druhu vyhledávače ukazují otevírací dobu, mapu a hodnocení. Máte-li provozovnu pro zákazníky, nevolte „firma bez provozovny“.')) ?></span></div>
</div>
<?php
$pole('firma_ico', 'IČO', 'text', '', 'maxlength="10" inputmode="numeric"');
$pole('firma_dic', 'DIČ', 'text', 'Jen plátce DPH, např. CZ12345678.', 'maxlength="14"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Adresa a kontakt')) ?></legend>
<?php
$pole('firma_ulice', 'Ulice a číslo', 'text', '', 'maxlength="200" autocomplete="street-address"');
$pole('firma_psc', 'PSČ', 'text', '', 'maxlength="10" autocomplete="postal-code"');
$pole('firma_mesto', 'Město', 'text', '', 'maxlength="120" autocomplete="address-level2"');
$pole('firma_zeme', 'Země (kód)', 'text', 'Dvoupísmenný kód: CZ, SK, DE…', 'maxlength="2" size="3"');
$pole('firma_telefon', 'Telefon', 'text', 'S předvolbou, např. +420 123 456 789. E-mail je v záložce Základní.', 'maxlength="30" autocomplete="tel"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Otevírací doba a mapa')) ?></legend>
<?php
$pole('firma_hodiny', 'Otevírací doba', 'radky', 'Každý řádek jeden den nebo rozsah dnů: „Po–Pá 8:00–17:00“, „So 9–12“, „Ne zavřeno“, polední pauza „Út 8–12, 13–17“. Prázdné = bez otevírací doby.', 'rows="5" spellcheck="false"');
$pole('firma_mapa', 'Odkaz na mapu', 'url', 'Adresa místa na Mapy.cz nebo Google Maps – prvek Údaje firmy z ní udělá odkaz „Zobrazit na mapě“.');
$pole('firma_gps', 'Souřadnice (nepovinné)', 'text', 'Zeměpisná šířka a délka, např. 50.0875, 14.4213 – přesnější místo pro mapy a vyhledávače.', 'maxlength="40"');
?>
</fieldset>
