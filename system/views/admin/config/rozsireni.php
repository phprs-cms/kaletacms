<?php
/** Záložka Rozšíření. */
use MiroCMS\Core\Rozsireni;
?>
<p class="hlaska"><?= e(t('Rozšíření jsou volitelné části MiroCMS. Všechna jsou součástí systému a udržuje je tým MiroCMS – nic se nestahuje ani neinstaluje. Vypnuté rozšíření zmizí z menu i z webu, jeho data zůstanou a po zapnutí se vrátí.')) ?></p>
<div class="rozsireni-seznam">
<?php foreach (Rozsireni::SEZNAM as $klic => [$nazev, $popis]): ?>
	<label class="rozsireni-karta">
		<input type="checkbox" name="rozsireni[]" value="<?= e($klic) ?>"<?= in_array($klic, $zapnutaRozsireni, true) ? ' checked' : '' ?>>
		<span><strong><?= e(t($nazev)) ?></strong><br><?= e(t($popis)) ?></span>
	</label>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Vždy zapnuté jádro: Články, Média, Rubriky, Stránky, Bloky a rozvržení, Uživatelé, Nastavení.')) ?></p>
<details class="pokrocile"<?= in_array('asistent', $zapnutaRozsireni, true) ? ' open' : '' ?>>
<summary><?= e(t('AI asistent – klíč a model')) ?></summary>
<div class="radek">
	<label for="ai_klic"><?= e(t('Klíč Claude API')) ?></label>
	<div><input class="textpole siroke" type="password" id="ai_klic" name="ai_klic" value="" autocomplete="off" placeholder="<?= $hodnoty['ai_klic'] !== '' ? e(t('uložen klíč končící %s – nový vložte jen při změně', $hodnoty['ai_klic'])) : 'sk-ant-…' ?>">
	<span class="napoveda"><?= e(t('Klíč si vytvoříte na')) ?> <a href="https://console.anthropic.com/" target="_blank" rel="noopener"><?= e(t('console.anthropic.com')) ?></a> <?= e(t('→ API Keys. Platíte jen za skutečné použití, jeden návrh stojí řádově haléře. Klíč se ukládá jen na vašem webu.')) ?></span>
<?php if ($hodnoty['ai_klic'] !== ''): ?>
	<label><input type="checkbox" name="ai_klic_smazat" value="1"> <?= e(t('Odebrat uložený klíč')) ?></label>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="ai_model"><?= e(t('Model')) ?></label>
	<select id="ai_model" name="ai_model">
<?php foreach (MiroCMS\Core\Asistent::MODELY as $klic => $nazev): ?>
		<option value="<?= e($klic) ?>"<?= $hodnoty['ai_model'] === $klic ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select>
</div>
<p class="napoveda"><?= e(t('Asistent jen navrhuje – o každé změně rozhoduje redaktor. Při použití se text rozepsaného článku odešle službě Anthropic (Claude); bez kliknutí na tlačítko asistenta se nikam nic neposílá.')) ?></p>
</details>
