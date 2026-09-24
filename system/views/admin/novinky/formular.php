<?php
/**
 * Editor novinky: vlevo text, vpravo nastavení (na úzké obrazovce pod sebou).
 *
 * @var Kaleta\Admin\Moduly\Novinky $modul
 * @var string $csrf
 * @var array<string, mixed> $novinka
 * @var array<string, string> $chyby
 * @var list<array<string, mixed>> $kategorie
 * @var array<int, string> $autori
 * @var bool $smiVydavat
 * @var bool $asistent  AI asistent je zapnutý a má klíč
 * @var list<string> $jazykyPrekladu  jazyky, do kterých jde novinku přeložit (jen u uložené novinky ve výchozím jazyce)
 * @var array<string, int> $preklady  existující překlady: jazyk => číslo novinky
 * @var array{cas:string, data:string}|null $konceptServer  rozepsaný stav uložený na serveru (z jiného zařízení)
 * @var bool $jazykyWebu  web má další jazykové verze
 * @var string $original  adresa novinky, jejímž je tato překladem
 * @var string $stitky  štítky oddělené čárkou
 * @var list<string> $vsechnyStitky
 * @var list<array<string, mixed>> $revize
 */
$dt = fn (?string $v): string => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled novinek')) ?></a></p>

<?php if (!empty($konceptServer)): ?>
<script type="application/json" id="koncept-server"><?= json_encode(['cas' => strtotime($konceptServer['cas']) * 1000, 'pole' => json_decode($konceptServer['data'], true)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif ?>
<form class="formular formular-clanek" method="post" action="<?= e($modul->url('uloz')) ?>" data-koncept="novinka-<?= (int) $novinka['idc'] ?>" data-koncept-url="<?= e($modul->url('koncept')) ?>"<?= $asistent ? ' data-asistent="' . e($modul->url('asistent')) . '"' : '' ?>>
<?= $csrf ?>
<input type="hidden" name="idc" value="<?= (int) $novinka['idc'] ?>">

<div class="clanek-hlavni">
	<div class="radek pres-celou">
		<label for="titulek"><?= e(t('Titulek')) ?></label>
		<input class="textpole siroke titulek-pole" type="text" id="titulek" name="titulek" value="<?= e($novinka['titulek']) ?>" maxlength="255" required placeholder="<?= e(t('Titulek novinky')) ?>"><?= $chyba('titulek') ?>
	</div>
	<div class="radek pres-celou">
		<label for="uvod"><?= e(t('Perex (úvod)')) ?></label>
		<textarea class="textbox" id="uvod" name="uvod" rows="5" data-editor="maly"><?= e($novinka['uvod']) ?></textarea>
		<span class="napoveda"><?= e(t('Zobrazuje se ve výpisech i na začátku novinky – v textu ho neopakujte.')) ?></span>
	</div>
	<div class="radek pres-celou">
		<label for="text"><?= e(t('Text')) ?></label>
		<textarea class="textbox vysoky" id="text" name="text" rows="20" data-editor><?= e($novinka['text']) ?></textarea>
		<span class="napoveda"><?= e(t('Video vložíte tak, že jeho adresu (YouTube, Vimeo) dáte na samostatný řádek. Návštěvníkovi se načte až po kliknutí.')) ?></span>
	</div>
</div>

<aside class="clanek-nastaveni">
<fieldset>
<legend><?= e(t('Vydání')) ?></legend>
<div class="radek">
	<label for="stav"><?= e(t('Stav')) ?></label>
	<div><select id="stav" name="stav">
		<option value="koncept"<?= !$novinka['visible'] ? ' selected' : '' ?>><?= e(t('Koncept')) ?></option>
<?php if ($smiVydavat): ?>
		<option value="vydany"<?= $novinka['visible'] ? ' selected' : '' ?>><?= e(t('Vydaná')) ?></option>
<?php endif ?>
	</select>
<?php if (!$smiVydavat): ?>
	<span class="napoveda"><?= e(t('Novinku vydá editor nebo správce webu.')) ?></span>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="datum"><?= e(t('Datum vydání')) ?></label>
	<div><input class="textpole" type="datetime-local" id="datum" name="datum" value="<?= e($dt($novinka['datum'])) ?>" required>
	<span class="napoveda"><?= e(t('Budoucí datum = novinka se vydá sama v daný čas.')) ?></span></div>
</div>
<?php if ($novinka['visible']): ?>
<div class="radek"><span class="popisek"></span><div class="volby"><label><input type="checkbox" name="oznacit_aktualizaci" value="1"> <?= e(t('Označit jako aktualizovanou (s dnešním datem)')) ?></label></div></div>
<?php endif ?>
<?php $jenCteni = $novinka['visible'] && !$smiVydavat; /* vydanou novinku upravuje jen editor – autor ji vidí, ale neuloží */ ?>
<?php if ($jenCteni): ?>
<p class="napoveda"><?= e(t('Novinka je vydaná – změny v ní uloží jen editor nebo správce. Požádejte je o úpravu.')) ?></p>
<?php endif ?>
<p class="tlacitka ulozit-lista">
	<button class="tl" type="submit" name="po_ulozeni" value="vypis"<?= $jenCteni ? ' disabled' : '' ?>><?= e(t('Uložit')) ?></button>
	<button class="tl" type="submit" name="po_ulozeni" value="zustat"<?= $jenCteni ? ' disabled' : '' ?>><?= e(t('Uložit a pokračovat')) ?></button>
<?php if ($novinka['idc']): ?>
	<a class="navigace" href="<?= e($modul->app()->url('novinky/' . $novinka['seo_link'] . '?nahled=1')) ?>" target="_blank" rel="noopener"><?= e(t('Náhled')) ?></a>
<?php endif ?>
</p>
</fieldset>

<fieldset>
<legend><?= e(t('Zařazení')) ?></legend>
<?php if (count($kategorie) < 2): ?>
<input type="hidden" name="tema" value="<?= (int) ($kategorie[0]['idt'] ?? $novinka['tema']) ?>">
<?php else: ?>
<div class="radek">
	<label for="tema"><?= e(t('Kategorie')) ?></label>
	<div><select id="tema" name="tema" required>
<?php foreach ($kategorie as $k): ?>
		<option value="<?= (int) $k['idt'] ?>"<?= (int) $novinka['tema'] === (int) $k['idt'] ? ' selected' : '' ?>><?= e($k['nazev']) ?></option>
<?php endforeach ?>
	</select><?= $chyba('tema') ?></div>
</div>
<?php endif ?>
<?php if (count($autori) < 2): ?>
<input type="hidden" name="autor" value="<?= (int) (array_key_first($autori) ?? $novinka['autor']) ?>">
<?php else: ?>
<div class="radek">
	<label for="autor"><?= e(t('Autor')) ?></label>
	<div><select id="autor" name="autor">
<?php foreach ($autori as $idu => $jmeno): ?>
		<option value="<?= (int) $idu ?>"<?= (int) $novinka['autor'] === (int) $idu ? ' selected' : '' ?>><?= e($jmeno) ?></option>
<?php endforeach ?>
	</select><?= $chyba('autor') ?></div>
</div>
<?php endif ?>
<div class="radek">
	<label for="stitky"><?= e(t('Štítky')) ?></label>
	<div><input class="textpole siroke" type="text" id="stitky" name="stitky" value="<?= e($stitky) ?>" maxlength="600" list="stitky-seznam" autocomplete="off" data-stitky>
	<datalist id="stitky-seznam"><?php foreach ($vsechnyStitky as $s): ?><option value="<?= e($s) ?>"><?php endforeach ?></datalist>
	<span class="napoveda"><?= e(t('Oddělené čárkou. Návštěvník si podle štítku zobrazí související novinky.')) ?></span></div>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Hlavní obrázek')) ?></legend>
<div class="radek pres-celou">
	<input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($novinka['obrazek']) ?>" maxlength="255" placeholder="<?= e(t('vyberte z médií, nebo vložte adresu')) ?>" aria-label="<?= e(t('Hlavní obrázek')) ?>" data-obrazek>
	<span class="napoveda"><?= e(t('Použije se ve výpisech a při sdílení na sociálních sítích.')) ?></span>
</div>
<div class="radek pres-celou">
	<label for="obrazek_popis"><?= e(t('Popisek obrázku')) ?></label>
	<input class="textpole siroke" type="text" id="obrazek_popis" name="obrazek_popis" value="<?= e($novinka['obrazek_popis']) ?>" maxlength="300">
</div>
<div class="radek pres-celou">
	<label for="obrazek_autor"><?= e(t('Autor obrázku')) ?></label>
	<div><input class="textpole siroke" type="text" id="obrazek_autor" name="obrazek_autor" value="<?= e($novinka['obrazek_autor']) ?>" maxlength="120">
	<span class="napoveda"><?= e(t('Prázdné pole = popisek a autor z knihovny Médií.')) ?></span></div>
</div>
</fieldset>

<?php if ($jazykyWebu): ?>
<details class="pokrocile"<?= $original !== '' || $preklady !== [] ? ' open' : '' ?>>
<summary><?= e(t('Překlad')) ?></summary>
<?php if ($jazykyPrekladu !== []): ?>
<div class="radek pres-celou">
	<span class="popisek"><?= e(t('Jazykové verze')) ?></span>
	<div class="volby">
<?php foreach ($jazykyPrekladu as $kodJazyka): $nazevJazyka = Kaleta\Core\Jazyk::DOSTUPNE[$kodJazyka][0]; ?>
<?php if (isset($preklady[$kodJazyka])): ?>
		<a class="navigace" href="<?= e($modul->url('edit', ['id' => $preklady[$kodJazyka]])) ?>"><?= e($nazevJazyka) ?>: <?= e(t('otevřít překlad')) ?></a>
<?php elseif ($asistent): ?>
		<button class="navigace" type="submit" name="prelozit_do" value="<?= e($kodJazyka) ?>" formaction="<?= e($modul->url('preloz')) ?>" formnovalidate data-potvrdit="<?= e(t('Přeložit uloženou verzi asistentem? Vznikne koncept, který před vydáním přečtete. Překlad může trvat i minutu.')) ?>"><?= e(t('Přeložit asistentem')) ?>: <?= e($nazevJazyka) ?></button>
<?php else: ?>
		<span class="napoveda vradku"><?= e($nazevJazyka) ?>: <?= e(t('zatím bez překladu')) ?></span>
<?php endif ?>
<?php endforeach ?>
	</div>
</div>
<?php endif ?>
<div class="radek pres-celou">
	<label for="preklad_z"><?= e(t('Originál ve výchozím jazyce')) ?></label>
	<input class="textpole siroke" type="text" id="preklad_z" name="preklad_z" value="<?= e($original) ?>" maxlength="255" placeholder="<?= e(t('adresa nebo číslo původní novinky')) ?>">
	<span class="napoveda"><?= e(t('Vyplňte jen u novinky v jiné jazykové verzi (jazyk určuje kategorie).')) ?></span>
</div>
</details>
<?php endif ?>

<fieldset class="kontrola" data-kontrola>
<legend><?= e(t('Kontrola přístupnosti')) ?></legend>
<div data-kontrola-vysledek aria-live="polite"><p class="napoveda"><?= e(t('Kontrola běží při psaní (potřebuje JavaScript).')) ?></p></div>
</fieldset>

<details class="pokrocile"<?= $novinka['seo_titulek'] !== '' || $novinka['seo_popis'] !== '' || (string) $novinka['faq'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('SEO a další nastavení')) ?></summary>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_link" name="seo_link" value="<?= e($novinka['seo_link']) ?>" maxlength="150" placeholder="<?= e(t('vytvoří se z titulku')) ?>">
	<span class="napoveda"><?= e(t('Část adresy za /novinky/. Když ji po vydání změníte, stará adresa se sama přesměruje.')) ?></span></div>
</div>
<div class="radek">
	<label for="seo_titulek"><?= e(t('Titulek pro vyhledávače')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_titulek" name="seo_titulek" value="<?= e($novinka['seo_titulek']) ?>" maxlength="255" placeholder="<?= e(t('prázdné = titulek novinky')) ?>"></div>
</div>
<div class="radek">
	<label for="seo_popis"><?= e(t('Popis pro vyhledávače')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_popis" name="seo_popis" value="<?= e($novinka['seo_popis']) ?>" maxlength="320" placeholder="<?= e(t('prázdné = začátek perexu')) ?>"></div>
</div>
<div class="radek">
	<label for="t_slova"><?= e(t('Klíčová slova')) ?></label>
	<div><input class="textpole siroke" type="text" id="t_slova" name="t_slova" value="<?= e($novinka['t_slova']) ?>" maxlength="500">
	<span class="napoveda"><?= e(t('Oddělená čárkou; pomáhají vyhledávání na webu.')) ?></span></div>
</div>
<div class="radek">
	<label for="faq"><?= e(t('Otázky a odpovědi')) ?></label>
	<div><textarea class="textbox nizky" id="faq" name="faq" rows="5"><?= e((string) $novinka['faq']) ?></textarea>
	<span class="napoveda"><?= e(t('Otázka na jednom řádku, odpověď pod ní, mezi dvojicemi prázdný řádek. Zobrazí se pod textem a ve strukturovaných datech (FAQ).')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Možnosti')) ?></span>
	<div class="volby"><label><input type="checkbox" name="noindex" value="1"<?= $novinka['noindex'] ? ' checked' : '' ?>> <?= e(t('Skrýt před vyhledávači (noindex)')) ?></label></div>
</div>
</details>
<?php if ($revize !== []): ?>
<details class="pokrocile">
<summary><?= e(t('Historie verzí (%s)', count($revize))) ?></summary>
<ul class="revize">
<?php foreach ($revize as $rv): ?>
	<li><a href="<?= e($modul->url('revize', ['id' => $novinka['idc'], 'idr' => $rv['idr']])) ?>" title="<?= e($rv['titulek']) ?>"><?= e(datum($rv['datum'], true)) ?></a> <span class="napoveda vradku"><?= e($rv['kdo_jm'] ?? '') ?></span> · <a href="<?= e($modul->url('porovnej', ['id' => $novinka['idc'], 'idr' => $rv['idr']])) ?>"><?= e(t('co se změnilo')) ?></a></li>
<?php endforeach ?>
</ul>
<p class="napoveda"><?= e(t('Kliknutím načtete starší verzi do editoru. Uchovává se posledních 20 verzí.')) ?></p>
</details>
<?php endif ?>
</aside>
</form>
<script src="<?= e($modul->app()->url('image/pomocnik.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
