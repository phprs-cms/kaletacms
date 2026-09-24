<?php
/**
 * Identita webu: šablona, logo, barva a písma s živou ukázkou.
 *
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Vzhled $modul
 * @var string $csrf
 * @var array<string, array{nazev:string, popis:string}> $layouty
 * @var array<string, string> $hodnoty
 */
use MiroCMS\Front\Identita;

$barvy = ['#1f4fe0' => 'modrá', '#326891' => 'ocelová modrá', '#b3261e' => 'červená', '#c2410c' => 'oranžová', '#0f766e' => 'smaragdová',
    '#15803d' => 'zelená', '#7c3aed' => 'fialová', '#be185d' => 'purpurová', '#ff2d6f' => 'růžová', '#111111' => 'černá'];
$akcent = $hodnoty['brand_akcent'] !== '' ? $hodnoty['brand_akcent'] : '#1f4fe0';
?>
<form class="formular identita" method="post" action="<?= e($modul->url('uloz')) ?>" data-identita>
<?= $csrf ?>
<?php if (count($layouty) > 1): ?>
<fieldset>
<legend><?= e(t('Šablona')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach ($layouty as $slozka => $l): ?>
	<label class="karta-volba">
		<input type="radio" name="layout" value="<?= e($slozka) ?>"<?= $hodnoty['layout'] === $slozka ? ' checked' : '' ?>>
		<strong><?= e($l['nazev']) ?></strong>
		<span><?= e($l['popis']) ?></span>
	</label>
<?php endforeach ?>
</div>
</fieldset>
<?php else: ?>
<input type="hidden" name="layout" value="<?= e((string) array_key_first($layouty)) ?>">
<?php endif ?>

<fieldset>
<legend><?= e(t('Logo')) ?></legend>
<div class="radek"><label for="logo_webu"><?= e(t('Logo')) ?></label><div><input class="textpole siroke" type="text" id="logo_webu" name="logo_webu" value="<?= e($hodnoty['logo_webu']) ?>" maxlength="255" placeholder="<?= e(t('bez loga se v záhlaví zobrazí název webu')) ?>" data-obrazek><span class="napoveda"><?= e(t('Nejlépe PNG s průhledným pozadím, výška aspoň 120 px.')) ?></span></div></div>
<div class="radek"><label for="favicon"><?= e(t('Ikona webu')) ?></label><div><input class="textpole siroke" type="text" id="favicon" name="favicon" value="<?= e($hodnoty['favicon']) ?>" maxlength="255" data-obrazek><span class="napoveda"><?= e(t('Malý čtvercový obrázek na kartě prohlížeče a v záložkách. Stačí 256×256 px.')) ?></span></div></div>
</fieldset>

<fieldset>
<legend><?= e(t('Barva')) ?></legend>
<div class="radek">
	<span class="popisek"><?= e(t('Hlavní barva')) ?></span>
	<div>
		<label class="identita-prepinac"><input type="radio" name="akcent_vlastni" value="0"<?= $hodnoty['brand_akcent'] === '' ? ' checked' : '' ?>> <?= e(t('barva šablony')) ?></label>
		<label class="identita-prepinac"><input type="radio" name="akcent_vlastni" value="1"<?= $hodnoty['brand_akcent'] !== '' ? ' checked' : '' ?>> <?= e(t('vlastní:')) ?></label>
		<input type="color" name="brand_akcent" value="<?= e($akcent) ?>" aria-label="<?= e(t('Vlastní hlavní barva')) ?>">
		<div class="identita-barvy">
<?php foreach ($barvy as $hex => $nazev): ?>
			<button type="button" data-barva="<?= e($hex) ?>" style="background:<?= e($hex) ?>" title="<?= e(t($nazev)) ?>" aria-label="<?= e(t($nazev)) ?>"></button>
<?php endforeach ?>
		</div>
		<span class="napoveda"><?= e(t('Použije se na odkazy, tlačítka a zvýraznění.')) ?> <strong data-kontrast hidden><?= e(t('Pozor: tahle barva je na bílém pozadí špatně čitelná – zvolte tmavší.')) ?></strong></span>
	</div>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Tmavý režim')) ?></legend>
<div class="radek">
	<span class="popisek"><?= e(t('Tmavý vzhled webu')) ?></span>
	<div class="volby">
		<label><input type="radio" name="tmavy_rezim" value="vypnuto"<?= $hodnoty['tmavy_rezim'] !== 'auto' ? ' checked' : '' ?>> <?= e(t('vypnutý – web je vždy světlý')) ?></label><br>
		<label><input type="radio" name="tmavy_rezim" value="auto"<?= $hodnoty['tmavy_rezim'] === 'auto' ? ' checked' : '' ?>> <?= e(t('podle zařízení návštěvníka')) ?></label>
		<span class="napoveda"><?= e(t('Návštěvník s tmavým režimem v telefonu nebo počítači uvidí tmavou verzi šablony. Zkontrolujte logo: tmavé logo na průhledném pozadí by na tmavém webu zaniklo.')) ?></span>
	</div>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Písmo')) ?></legend>
<div class="radek">
	<label for="brand_pismo_titulky"><?= e(t('Titulky')) ?></label>
	<select id="brand_pismo_titulky" name="brand_pismo_titulky">
<?php foreach (Identita::PISMA_TITULKU as $klic => [$nazev, $popis, $css]): ?>
		<option value="<?= e($klic) ?>" data-css="<?= e($css) ?>"<?= $hodnoty['brand_pismo_titulky'] === $klic ? ' selected' : '' ?>><?= e(t($nazev) . ($popis !== '' ? ' – ' . t($popis) : '')) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="radek">
	<label for="brand_pismo_text"><?= e(t('Text')) ?></label>
	<div><select id="brand_pismo_text" name="brand_pismo_text">
<?php foreach (Identita::PISMA_TEXTU as $klic => [$nazev, $popis, $css]): ?>
		<option value="<?= e($klic) ?>" data-css="<?= e($css) ?>"<?= $hodnoty['brand_pismo_text'] === $klic ? ' selected' : '' ?>><?= e(t($nazev) . ($popis !== '' ? ' – ' . t($popis) : '')) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Písma jsou systémová: nic se nestahuje z cizích serverů, web je rychlý a nepotřebuje kvůli nim souhlas návštěvníka.')) ?></span></div>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Ukázka')) ?></legend>
<div class="identita-ukazka" data-ukazka>
	<span class="identita-ukazka-rubrika"><?= e(t('Novinky')) ?></span>
	<h3><?= e(t('%s: nadpis vypadá takto', $hodnoty['nazev_webu'])) ?></h3>
	<p><?= e(t('Takhle bude vypadat běžný text. Obsahuje i')) ?> <a href="#" data-neklikat><?= e(t('odkaz v hlavní barvě')) ?></a> <?= e(t('a dost slov na to, abyste posoudili čitelnost zvoleného písma.')) ?></p>
	<span class="identita-ukazka-tlacitko"><?= e(t('Tlačítko')) ?></span>
</div>
<p class="napoveda"><?= e(t('Ukázka je orientační – skutečný výsledek uvidíte po uložení na webu.')) ?></p>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"> <a class="navigace" href="<?= e($app->url('')) ?>" target="_blank" rel="noopener"><?= e(t('Zobrazit web')) ?></a></p>
</form>
