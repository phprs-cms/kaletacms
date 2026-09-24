<?php
/**
 * @var MiroCMS\Admin\Moduly\Stranky $modul
 * @var string $csrf
 * @var array<string, mixed> $stranka
 * @var array<string, string> $chyby
 * @var bool $uvod  je to úvodní stránka webu
 * @var ?bool $vMenu  je stránka v sestaveném menu (null = menu se skládá automaticky podle v_menu)
 * @var bool $vlastniMenu  web má sestavené hlavní menu
 * @var list<array{ids:int, titulek:string, seo_link:string}> $rodice  možné nadřazené stránky
 * @var list<array{idr:int, datum:string, titulek:string, kdo:?string}> $revize  starší verze textu
 */
$segment = basename((string) $stranka['seo_link']);
$predpona = '';
foreach ($rodice as $r) {
    if ((int) $r['ids'] === (int) ($stranka['nadrazena'] ?? 0)) {
        $predpona = $r['seo_link'] . '/';
    }
}
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled')) ?></a>
<?php if ($stranka['ids']): ?>
	<a class="navigace" href="<?= e($app->url(($stranka['jazyk'] ?? '') !== '' ? $stranka['jazyk'] . '/' . ($uvod ? '' : $stranka['seo_link']) : ($uvod ? '' : $stranka['seo_link'])) . ($stranka['zobrazit'] ? '' : '?stavba=koncept')) ?>" target="_blank" rel="noopener"><?= e(t($stranka['zobrazit'] ? 'Zobrazit na webu' : 'Náhled skryté stránky')) ?></a>
<?php endif ?></p>
<?php if (($stranka['stavba_koncept'] ?? null) !== null): ?>
<p class="hlaska hlaska-varovani"><?= e(t(($stranka['stavba'] ?? null) !== null ? 'Ve staviteli jsou rozpracované změny, které ještě nejsou na webu.' : 'Stránku skládáte ve staviteli. Na webu je zatím text níže – po publikování ve staviteli ho nahradí stavba.')) ?>
	<a href="<?= e($modul->url('stavitel', ['id' => (int) $stranka['ids']])) ?>"><?= e(t('Otevřít stavitel')) ?></a></p>
<?php endif ?>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>" data-koncept="stranka-<?= (int) $stranka['ids'] ?>">
<?= $csrf ?>
<input type="hidden" name="ids" value="<?= (int) $stranka['ids'] ?>">
<div class="radek pres-celou">
	<label for="titulek"><?= e(t('Název stránky')) ?></label>
	<input class="textpole siroke titulek-pole" type="text" id="titulek" name="titulek" value="<?= e($stranka['titulek']) ?>" maxlength="200" required><?= $chyba('titulek') ?>
</div>
<?php if (!$stranka['ids']): ?>
<div class="radek">
	<label for="sablona"><?= e(t('Začít podle šablony')) ?></label>
	<div><select id="sablona" name="sablona">
		<option value=""><?= e(t('prázdná stránka (text)')) ?></option>
<?php foreach (MiroCMS\Stavitel\Knihovna::SABLONY_STRANEK as $klic => [$nazev]): ?>
		<option value="<?= e($klic) ?>"><?= e(t($nazev)) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Šablona poskládá stránku z hotových sekcí s ukázkovými texty a otevře ji ve staviteli.')) ?></span></div>
</div>
<?php endif ?>
<?php if (($stranka['stavba'] ?? null) !== null): ?>
<div class="radek pres-celou">
	<p class="hlaska"><?= e(t('Obsah této stránky se skládá ve staviteli.')) ?> <a class="tl" href="<?= e($modul->url('stavitel', ['id' => (int) $stranka['ids']])) ?>"><?= e(t('Otevřít stavitel')) ?></a></p>
	<input type="hidden" name="text" value="<?= e($stranka['text']) ?>">
</div>
<?php else: ?>
<div class="radek pres-celou">
	<label for="text"><?= e(t('Obsah')) ?></label>
	<textarea class="textbox vysoky" id="text" name="text" rows="18" data-editor><?= e($stranka['text']) ?></textarea>
<?php if ($stranka['ids']): ?>
	<span class="napoveda"><?= e(t('Chcete stránku poskládat ze sekcí, sloupců a tlačítek?')) ?> <a href="<?= e($modul->url('stavitel', ['id' => (int) $stranka['ids']])) ?>"><?= e(t('Otevřít ve staviteli')) ?></a></span>
<?php endif ?>
</div>
<?php endif ?>
<div class="radek">
	<label for="nadrazena"><?= e(t('Nadřazená stránka')) ?></label>
	<div><select id="nadrazena" name="nadrazena">
		<option value="0"><?= e(t('— žádná (hlavní úroveň) —')) ?></option>
<?php foreach ($rodice as $r): ?>
		<option value="<?= (int) $r['ids'] ?>"<?= (int) $r['ids'] === (int) ($stranka['nadrazena'] ?? 0) ? ' selected' : '' ?>><?= e(str_repeat('– ', substr_count($r['seo_link'], '/')) . $r['titulek']) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Podstránka má adresu pod nadřazenou (/sluzby/kuchyne) a ukáže se v jejích drobečcích.')) ?></span></div>
</div>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><span class="napoveda-inline">/<?= e($predpona) ?></span><input class="textpole" type="text" id="seo_link" name="seo_link" value="<?= e($segment) ?>" maxlength="110" placeholder="<?= e(t('vytvoří se z názvu, např. o-nas')) ?>"><?= $chyba('seo_link') ?></div>
</div>
<details class="pokrocile"<?= $stranka['popis'] !== '' || $stranka['seo_titulek'] !== '' || $stranka['obrazek'] !== '' || $stranka['noindex'] ? ' open' : '' ?>>
<summary><?= e(t('Vyhledávače a sdílení')) ?></summary>
<div class="radek">
	<label for="seo_titulek"><?= e(t('Titulek pro vyhledávače')) ?></label>
	<input class="textpole siroke" type="text" id="seo_titulek" name="seo_titulek" value="<?= e($stranka['seo_titulek']) ?>" maxlength="200" placeholder="<?= e(t('prázdné = název stránky')) ?>">
</div>
<div class="radek">
	<label for="popis"><?= e(t('Popis pro vyhledávače')) ?></label>
	<div><input class="textpole siroke" type="text" id="popis" name="popis" value="<?= e($stranka['popis']) ?>" maxlength="300">
	<span class="napoveda"><?= e(t('Jedna až dvě věty, co na stránce návštěvník najde (do 160 znaků).')) ?></span></div>
</div>
<div class="radek">
	<label for="obrazek"><?= e(t('Obrázek pro sdílení')) ?></label>
	<div><input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($stranka['obrazek']) ?>" maxlength="255" placeholder="<?= e(t('prázdné = výchozí obrázek z Nastavení')) ?>" data-obrazek>
	<span class="napoveda"><?= e(t('Ukáže se při sdílení odkazu na Facebooku, LinkedInu nebo v Teams (ideálně 1200 × 630 px).')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Možnosti')) ?></span>
	<div class="volby"><label><input type="checkbox" name="noindex" value="1"<?= $stranka['noindex'] ? ' checked' : '' ?>> <?= e(t('Skrýt před vyhledávači (noindex)')) ?></label></div>
</div>
</details>
<?= $app->view->render('admin/jazyk_pole', ['app' => $app, 'hodnota' => (string) ($stranka['jazyk'] ?? ''), 'prekladZ' => (int) ($stranka['preklad_z'] ?? 0), 'originaly' => $app->db()->pairs("SELECT ids, titulek FROM {stranky} WHERE jazyk = '' AND smazano IS NULL ORDER BY titulek"), 'napoveda' => '']) ?>
<div class="radek">
	<span class="popisek"><?= e(t('Zobrazení')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="zobrazit" value="1"<?= $stranka['zobrazit'] ? ' checked' : '' ?>> <?= e(t('Zveřejnit stránku')) ?></label><?= $uvod ? ' <span class="stitek">' . e(t('úvodní stránka webu')) . '</span>' : '' ?><?= $chyba('zobrazit') ?><br>
		<span class="napoveda"><label for="zverejnit_od"><?= e(t('Skrytou stránku zveřejnit automaticky:')) ?></label> <input class="textpole" type="datetime-local" id="zverejnit_od" name="zverejnit_od" value="<?= e(($stranka['zverejnit_od'] ?? null) ? date('Y-m-d\TH:i', strtotime($stranka['zverejnit_od'])) : '') ?>"></span><br>
		<label><input type="checkbox" name="v_menu" value="1"<?= ($vMenu ?? (bool) $stranka['v_menu']) ? ' checked' : '' ?>> <?= e(t('Zobrazit v hlavní navigaci webu')) ?></label>
<?php if ($vlastniMenu): ?>
		<span class="napoveda"><?= e(t('Web má sestavené menu – stránka se přidá na jeho konec. Pořadí a podmenu upravíte ve Vzhled → Menu.')) ?></span>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="poradi"><?= e(t('Pořadí v navigaci')) ?></label>
	<div><input class="textpole" type="number" id="poradi" name="poradi" value="<?= (int) $stranka['poradi'] ?>" min="0" max="65535">
	<span class="napoveda"><?= e(t('Menší číslo = dřív v seznamu stránek a v automatickém menu.')) ?></span></div>
</div>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Uložit')) ?></button><?php if (($stranka['stavba'] ?? null) === null): ?> <button class="navigace" type="submit" name="po_ulozeni" value="stavitel"><?= e(t('Uložit a otevřít ve staviteli')) ?></button><?php endif ?></p>
</form>
<?php if ($revize !== []): ?>
<details class="pokrocile">
<summary><?= e(t('Historie textu (%s)', count($revize))) ?></summary>
<ul class="revize">
<?php foreach ($revize as $v): ?>
	<li><?= e(datum($v['datum'], true)) ?><?= $v['kdo'] ? ' · ' . e($v['kdo']) : '' ?> · <?= e($v['titulek']) ?>
		<form class="vradku" method="post" action="<?= e($modul->url('obnov_verzi')) ?>" data-potvrdit="<?= e(t('Obnovit tuto verzi textu? Současná podoba zůstane v historii.')) ?>"><?= $csrf ?><input type="hidden" name="idr" value="<?= (int) $v['idr'] ?>"><button class="navigace" type="submit"><?= e(t('Obnovit')) ?></button></form></li>
<?php endforeach ?>
</ul>
</details>
<?php endif ?>
<?php if ($stranka['ids']): ?>
<a class="navigace" href="<?= e($modul->url('export', ['id' => (int) $stranka['ids']])) ?>"><?= e(t('Stáhnout jako JSON')) ?></a>
<form class="vradku" method="post" action="<?= e($modul->url('duplikuj')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $stranka['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($stranka['titulek']) ?>"><button class="navigace" type="submit"><?= e(t('Duplikovat stránku')) ?></button></form>
<?php endif ?>
<?php if (($stranka['stavba'] ?? null) !== null): ?>
<form class="vradku" method="post" action="<?= e($modul->url('stavba_text')) ?>" data-potvrdit="<?= e(t('Vrátit stránku k obyčejnému textu? Stavba zůstane ve verzích a můžete se k ní vrátit.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $stranka['ids'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Vrátit stránku k textu')) ?></button></form>
<?php endif ?>
