<?php
/** Záložka Rozšíření. */
use Kaleta\Core\Rozsireni;
?>
<p class="hlaska"><?= e(t('Rozšíření jsou volitelné části Kalety. Všechna jsou součástí systému a udržuje je tým Kaleta – nic se nestahuje ani neinstaluje. Vypnuté rozšíření zmizí z menu i z webu, jeho data zůstanou a po zapnutí se vrátí.')) ?></p>
<div class="rozsireni-seznam">
<?php foreach (Rozsireni::SEZNAM as $klic => [$nazev, $popis]): ?>
	<label class="rozsireni-karta">
		<input type="checkbox" name="rozsireni[]" value="<?= e($klic) ?>"<?= in_array($klic, $zapnutaRozsireni, true) ? ' checked' : '' ?>>
		<span><strong><?= e(t($nazev)) ?></strong><br><?= e(t($popis)) ?></span>
	</label>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Vždy zapnuté jádro: Stránky, Novinky, Kategorie, Média, Vzhled, Uživatelé, Nastavení.')) ?></p>
<details class="pokrocile"<?= in_array('asistent', $zapnutaRozsireni, true) ? ' open' : '' ?>>
<summary><?= e(t('AI asistent – poskytovatel, klíč a model')) ?></summary>
<input type="hidden" name="ai_poskytovatel_puvodni" value="<?= e($hodnoty['ai_poskytovatel']) ?>">
<div class="radek">
	<label for="ai_poskytovatel"><?= e(t('Poskytovatel')) ?></label>
	<div><select id="ai_poskytovatel" name="ai_poskytovatel">
<?php foreach (Kaleta\Core\Asistent::POSKYTOVATELE as $klic => [$nazev, , $konzole]): ?>
		<option value="<?= e($klic) ?>"<?= $hodnoty['ai_poskytovatel'] === $klic ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Klíč si vytvoříte u poskytovatele:')) ?>
<?php foreach (Kaleta\Core\Asistent::POSKYTOVATELE as [$nazev, , $konzole]): ?>
		<a href="<?= e($konzole) ?>" target="_blank" rel="noopener"><?= e(t($nazev)) ?></a>
<?php endforeach ?>
		· <?= e(t('Platíte jen za skutečné použití, jeden návrh stojí řádově haléře. Klíč se ukládá jen na vašem webu.')) ?></span></div>
</div>
<div class="radek">
	<label for="ai_klic"><?= e(t('Klíč API')) ?></label>
	<div><input class="textpole siroke" type="password" id="ai_klic" name="ai_klic" value="" autocomplete="off" placeholder="<?= $hodnoty['ai_klic'] !== '' ? e(t('uložen klíč končící %s – nový vložte jen při změně', $hodnoty['ai_klic'])) : '' ?>">
<?php if ($hodnoty['ai_klic'] !== ''): ?>
	<label><input type="checkbox" name="ai_klic_smazat" value="1"> <?= e(t('Odebrat uložený klíč')) ?></label>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="ai_model"><?= e(t('Model')) ?></label>
	<div><input class="textpole" id="ai_model" name="ai_model" value="<?= e($hodnoty['ai_model']) ?>" list="ai_modely" maxlength="80" spellcheck="false">
	<datalist id="ai_modely">
<?php foreach (Kaleta\Core\Asistent::MODELY as $klic => $nazev): ?>
		<option value="<?= e($klic) ?>"><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</datalist>
	<span class="napoveda"><?= e(t('U Claude vyberte z nabídky (doporučený je Sonnet). U ostatních poskytovatelů napište přesný název modelu z jejich dokumentace – nabídka modelů se tam často mění.')) ?></span></div>
</div>
<p class="napoveda"><?= e(t('Asistent jen navrhuje – o každé změně rozhoduje člověk. Při použití se text odešle zvolenému poskytovateli; bez kliknutí na tlačítko asistenta se nikam nic neposílá.')) ?></p>
</details>
