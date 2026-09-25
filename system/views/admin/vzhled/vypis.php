<?php
/**
 * Vzhled webu: předvolby a design systém (barvy, písma, velikosti, šířka, zaoblení) s živým náhledem skutečné úvodní stránky.
 * Náhled obstarává image/admin.js (data-vzhled): po každé změně si vyžádá CSS tokenů (akce nahled) a vloží ho do iframe.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Vzhled $modul
 * @var string $csrf
 * @var array<string, array{nazev:string, popis:string}> $layouty
 * @var array<string, mixed> $ds
 * @var list<array{popis:string, pomer:float, ok:bool}> $kontrasty
 * @var array<string, array{nazev:string, popis:string, ds:array<string, mixed>}> $predvolby
 * @var array<string, string> $hodnoty
 */
use Kaleta\Front\Identita;
use Kaleta\Stavitel\DesignSystem;

$px = fn (float $rem): string => (string) round($rem * 16);
$kontrastyHtml = function (array $kontrasty): string {
    $html = '';
    foreach ($kontrasty as $k) {
        $html .= '<li class="' . ($k['ok'] ? 'ok' : 'spatne') . '"><span>' . e(t($k['popis'])) . '</span><strong>' . e(t('%s : 1', cislo($k['pomer']))) . '</strong></li>';
    }

    return $html;
};
?>
<div class="vzhled">
<form class="formular vzhled-formular" method="post" action="<?= e($modul->url('uloz')) ?>" data-vzhled data-nahled-url="<?= e($modul->url('nahled')) ?>">
<?= $csrf ?>

<fieldset>
<legend><?= e(t('Předvolby')) ?></legend>
<p class="napoveda"><?= e(t('Celý vzhled jedním klikem – barvy, písma i velikosti. Pak ho můžete doladit níže.')) ?></p>
<div class="vzhled-predvolby">
<?php foreach ($predvolby as $klic => $p): ?>
	<button type="button" class="vzhled-predvolba" data-predvolba="<?= e((string) json_encode($p['ds'], JSON_UNESCAPED_SLASHES)) ?>">
		<span class="vzhled-vzorky"><?php foreach (['primarni', 'sekundarni', 'text', 'plocha'] as $b): ?><i style="background:<?= e($p['ds']['barvy'][$b]) ?>"></i><?php endforeach ?></span>
		<strong style="font-family:<?= e(Identita::PISMA_TITULKU[$p['ds']['pismo_titulky']][2]) ?>"><?= e(t($p['nazev'])) ?></strong>
		<small><?= e(t($p['popis'])) ?></small>
	</button>
<?php endforeach ?>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Barvy')) ?></legend>
<div class="vzhled-barvy">
<?php foreach (DesignSystem::BARVY as $klic => $nazev): ?>
	<label class="vzhled-barva">
		<input type="color" name="ds[barvy][<?= e($klic) ?>]" value="<?= e($ds['barvy'][$klic]) ?>">
		<span><?= e(t($nazev)) ?><small data-hex><?= e($ds['barvy'][$klic]) ?></small></span>
	</label>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Odstíny (tlumený text, linky, jemná hlavní barva) a barva textu na tlačítkách se dopočítají samy.')) ?></p>
<h3 class="vzhled-podnadpis"><?= e(t('Čitelnost')) ?></h3>
<ul class="vzhled-kontrasty" data-kontrasty><?= $kontrastyHtml($kontrasty) ?></ul>
<p class="napoveda"><?= e(t('Text by měl mít kontrast aspoň 4,5 : 1 (WCAG AA). Červeně označené dvojice budou pro část návštěvníků špatně čitelné.')) ?></p>
</fieldset>

<fieldset>
<legend><?= e(t('Tmavý režim')) ?></legend>
<div class="volby">
	<label><input type="radio" name="tmavy_rezim" value="vypnuto" data-prepni="tmave:0"<?= $hodnoty['tmavy_rezim'] !== 'auto' ? ' checked' : '' ?>> <?= e(t('vypnutý – web je vždy světlý')) ?></label><br>
	<label><input type="radio" name="tmavy_rezim" value="auto" data-prepni="tmave:1"<?= $hodnoty['tmavy_rezim'] === 'auto' ? ' checked' : '' ?>> <?= e(t('podle zařízení návštěvníka')) ?></label>
</div>
<div class="vzhled-barvy" data-sekce="tmave"<?= $hodnoty['tmavy_rezim'] !== 'auto' ? ' hidden' : '' ?>>
<?php foreach (['text' => 'Text', 'pozadi' => 'Pozadí', 'plocha' => 'Plocha'] as $klic => $nazev): ?>
	<label class="vzhled-barva">
		<input type="color" name="ds[barvy_tmave][<?= e($klic) ?>]" value="<?= e($ds['barvy_tmave'][$klic]) ?>">
		<span><?= e(t($nazev)) ?><small data-hex><?= e($ds['barvy_tmave'][$klic]) ?></small></span>
	</label>
<?php endforeach ?>
	<p class="napoveda"><?= e(t('Zkontrolujte logo: tmavé logo na průhledném pozadí by na tmavém webu zaniklo.')) ?></p>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Písmo')) ?></legend>
<div class="radek">
	<label for="ds-pismo-titulky"><?= e(t('Titulky')) ?></label>
	<select id="ds-pismo-titulky" name="ds[pismo_titulky]">
<?php foreach (Identita::PISMA_TITULKU as $klic => [$nazev, $popis]): if ($klic === 'vychozi') { continue; } ?>
		<option value="<?= e($klic) ?>"<?= $ds['pismo_titulky'] === $klic ? ' selected' : '' ?>><?= e(t($nazev) . ' – ' . t($popis)) ?></option>
<?php endforeach ?>
<?php foreach ($ds['vlastni_pisma'] as $i => $vp): ?>
		<option value="vlastni-<?= $i + 1 ?>"<?= $ds['pismo_titulky'] === 'vlastni-' . ($i + 1) ? ' selected' : '' ?>><?= e($vp['nazev'] . ' – ' . t('vlastní písmo')) ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="radek">
	<label for="ds-pismo-text"><?= e(t('Text')) ?></label>
	<div><select id="ds-pismo-text" name="ds[pismo_text]">
<?php foreach (Identita::PISMA_TEXTU as $klic => [$nazev, $popis]): if ($klic === 'vychozi') { continue; } ?>
		<option value="<?= e($klic) ?>"<?= $ds['pismo_text'] === $klic ? ' selected' : '' ?>><?= e(t($nazev) . ' – ' . t($popis)) ?></option>
<?php endforeach ?>
<?php foreach ($ds['vlastni_pisma'] as $i => $vp): ?>
		<option value="vlastni-<?= $i + 1 ?>"<?= $ds['pismo_text'] === 'vlastni-' . ($i + 1) ? ' selected' : '' ?>><?= e($vp['nazev'] . ' – ' . t('vlastní písmo')) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Systémová písma se nic nestahují. Vlastní písmo leží na vašem serveru – také nepotřebuje souhlas návštěvníka.')) ?></span></div>
</div>
<details class="pokrocile"<?= $ds['vlastni_pisma'] !== [] ? ' open' : '' ?>>
<summary><?= e(t('Vlastní písma značky (WOFF2)')) ?></summary>
<p class="napoveda"><?= e(t('Nahrajte soubory písma (.woff2) do Médií a vložte sem jejich adresu. Stačí jeden variabilní soubor, nebo běžný a tučný řez. Po uložení písmo vyberete výše.')) ?></p>
<?php for ($i = 0; $i < 3; $i++): $vp = $ds['vlastni_pisma'][$i] ?? ['nazev' => '', 'soubor' => '', 'tucny' => '']; ?>
<div class="radek">
	<span class="popisek"><?= e(t('Písmo %d', $i + 1)) ?></span>
	<div class="pole-vedle">
		<input class="textpole" type="text" name="ds[vlastni_pisma][<?= $i ?>][nazev]" value="<?= e($vp['nazev']) ?>" maxlength="40" placeholder="<?= e(t('název, např. Bricolage Grotesque')) ?>" aria-label="<?= e(t('Název písma %d', $i + 1)) ?>">
		<input class="textpole" type="text" name="ds[vlastni_pisma][<?= $i ?>][soubor]" value="<?= e($vp['soubor']) ?>" placeholder="media/…/pismo.woff2" aria-label="<?= e(t('Soubor písma %d', $i + 1)) ?>">
		<input class="textpole" type="text" name="ds[vlastni_pisma][<?= $i ?>][tucny]" value="<?= e($vp['tucny']) ?>" placeholder="<?= e(t('tučný řez (nepovinné)')) ?>" aria-label="<?= e(t('Tučný řez písma %d', $i + 1)) ?>">
	</div>
</div>
<?php endfor ?>
</details>
</fieldset>

<fieldset>
<legend><?= e(t('Velikosti')) ?></legend>
<p class="napoveda"><?= e(t('Písmo i mezery rostou plynule s šířkou okna – od telefonu po velký monitor. Nadpisy jsou násobky základního písma.')) ?></p>
<div class="vzhled-mrizka">
	<label><span><?= e(t('Základní písmo na telefonu')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[zaklad_min]" value="<?= e($px($ds['zaklad_min'])) ?>" min="13" max="24" step="1"> px</span></label>
	<label><span><?= e(t('Základní písmo na monitoru')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[zaklad_max]" value="<?= e($px($ds['zaklad_max'])) ?>" min="13" max="25" step="1"> px</span></label>
<?php foreach (['pomer_min' => 'Nadpisy na telefonu', 'pomer_max' => 'Nadpisy na monitoru'] as $klic => $popisek): ?>
	<label><span><?= e(t($popisek)) ?></span><select name="ds[<?= $klic ?>]">
<?php foreach (DesignSystem::POMERY as $hodnota => $nazev): ?>
		<option value="<?= e($hodnota) ?>"<?= abs((float) $hodnota - $ds[$klic]) < 0.001 ? ' selected' : '' ?>><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select></label>
<?php endforeach ?>
	<label><span><?= e(t('Šířka obsahu')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[sirka]" value="<?= e($px($ds['sirka'])) ?>" min="640" max="1920" step="16"> px</span></label>
	<label><span><?= e(t('Šířka textu (novinky a textové stránky)')) ?></span><span class="vzhled-jednotka"><input type="number" name="ds[sirka_textu]" value="<?= e($px($ds['sirka_textu'])) ?>" min="448" max="960" step="16"> px</span></label>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Typografické styly')) ?></legend>
<p class="napoveda"><?= e(t('Pojmenované styly textu, které v builderu vyberete u prvku (Styl → Typografie). Změna tady se projeví všude, kde styl je.')) ?></p>
<div class="tab-obal"><table class="vypis vzhled-typografie">
<thead><tr><th scope="col"><?= e(t('Styl')) ?></th><th scope="col"><?= e(t('Velikost (krok škály)')) ?></th><th scope="col"><?= e(t('Tloušťka')) ?></th></tr></thead>
<tbody>
<?php foreach (DesignSystem::TYPOGRAFIE as $klic => [$nazev, $krok, $tloustka, $radkovani, $titulky]): $vlastni = $ds['typografie'][$klic] ?? []; ?>
<tr>
	<th scope="row"><span style="font: var(--ka-typ-<?= e($klic) ?>, inherit)<?= $klic === 'nadtitulek' ? ';text-transform:uppercase;letter-spacing:.08em' : '' ?>"><?= e(t($nazev)) ?></span></th>
	<td><select name="ds[typografie][<?= e($klic) ?>][krok]" aria-label="<?= e(t('Velikost: %s', t($nazev))) ?>">
<?php foreach (DesignSystem::KROKY as $k): ?>
		<option value="<?= e($k) ?>"<?= ($vlastni['krok'] ?? $krok) === $k ? ' selected' : '' ?>><?= e($k === '0' ? t('0 – základní písmo') : $k) ?></option>
<?php endforeach ?>
	</select></td>
	<td><select name="ds[typografie][<?= e($klic) ?>][tloustka]" aria-label="<?= e(t('Tloušťka: %s', t($nazev))) ?>">
<?php foreach (DesignSystem::TLOUSTKY as $w => $nazevTloustky): ?>
		<option value="<?= $w ?>"<?= (int) ($vlastni['tloustka'] ?? $tloustka) === $w ? ' selected' : '' ?>><?= e(t($nazevTloustky)) ?></option>
<?php endforeach ?>
	</select></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
</fieldset>

<fieldset>
<legend><?= e(t('Zaoblení rohů')) ?></legend>
<div class="vzhled-zaobleni">
<?php foreach (['0' => 'ostré', 's' => 'jemné', 'm' => 'střední', 'l' => 'velké', 'plne' => 'kulaté'] as $klic => $nazev): ?>
	<label><input type="radio" name="ds[zaobleni]" value="<?= e($klic) ?>"<?= $ds['zaobleni'] === $klic ? ' checked' : '' ?>><i style="border-radius:<?= e($klic === 'plne' ? '999px' : DesignSystem::ZAOBLENI[$klic]) ?>"></i><?= e(t($nazev)) ?></label>
<?php endforeach ?>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Logo a ikona')) ?></legend>
<div class="radek"><label for="logo_webu"><?= e(t('Logo')) ?></label><div><input class="textpole siroke" type="text" id="logo_webu" name="logo_webu" value="<?= e($hodnoty['logo_webu']) ?>" maxlength="255" placeholder="<?= e(t('bez loga se v záhlaví zobrazí název webu')) ?>" data-obrazek><span class="napoveda"><?= e(t('Nejlépe PNG s průhledným pozadím, výška aspoň 120 px.')) ?></span></div></div>
<div class="radek"><label for="favicon"><?= e(t('Ikona webu')) ?></label><div><input class="textpole siroke" type="text" id="favicon" name="favicon" value="<?= e($hodnoty['favicon']) ?>" maxlength="255" data-obrazek><span class="napoveda"><?= e(t('Malý čtvercový obrázek na kartě prohlížeče a v záložkách. Stačí 256×256 px.')) ?></span></div></div>
</fieldset>

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

<p class="tlacitka vzhled-ulozit"><input class="tl" type="submit" value="<?= e(t('Uložit vzhled')) ?>"> <span class="napoveda" data-neulozeno hidden><?= e(t('Náhled ukazuje neuložené změny.')) ?></span></p>
</form>

<details class="pokrocile">
<summary><?= e(t('Design tokeny (Figma, Tokens Studio)')) ?></summary>
<p class="napoveda"><?= e(t('Barvy, písma, velikosti a typografické styly ve formátu W3C Design Tokens (DTCG). Export Kalety se dá načíst zpět celý, z jiného nástroje se převezmou barvy.')) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url('tokeny')) ?>"><?= e(t('Stáhnout tokeny (.tokens.json)')) ?></a></p>
<form class="navigace-radek" method="post" action="<?= e($modul->url('tokeny_import')) ?>" enctype="multipart/form-data" data-potvrdit="<?= e(t('Načíst tokeny? Přepíší nastavení vzhledu výše.')) ?>">
	<?= $csrf ?>
	<input type="file" name="tokeny" accept=".json,application/json" required aria-label="<?= e(t('Soubor s tokeny')) ?>">
	<button class="navigace" type="submit"><?= e(t('Načíst tokeny')) ?></button>
</form>
</details>

<aside class="vzhled-nahled">
	<div class="vzhled-nahled-lista">
		<span><?= e(t('Náhled úvodní stránky')) ?></span>
		<span class="vzhled-zarizeni" role="group" aria-label="<?= e(t('Zařízení')) ?>">
			<button type="button" data-zarizeni="pocitac" aria-pressed="true"><?= e(t('Počítač')) ?></button>
			<button type="button" data-zarizeni="mobil" aria-pressed="false"><?= e(t('Telefon')) ?></button>
		</span>
	</div>
	<div class="vzhled-ramec" data-ramec><iframe src="<?= e($app->url('')) ?>" title="<?= e(t('Náhled úvodní stránky')) ?>" data-nahled></iframe></div>
</aside>
</div>
