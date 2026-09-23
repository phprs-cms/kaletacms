<?php
/**
 * Editor článku: vlevo text, vpravo nastavení (na úzké obrazovce pod sebou).
 *
 * @var MiroCMS\Admin\Moduly\Clanky $modul
 * @var string $csrf
 * @var array<string, mixed> $clanek
 * @var array<string, string> $chyby
 * @var list<array<string, mixed>> $rubriky
 * @var array<int, string> $autori
 * @var array<int, string> $sablony
 * @var array<int, string> $sablonySoubory soubor šablony (standard, dlouhe-cteni…) kvůli popisu u karty
 * @var bool $smiVydavat
 * @var bool $ctenari  je zapnuté rozšíření Čtenáři a zamčený obsah
 * @var bool $asistent  AI asistent je zapnutý a má klíč
 * @var list<string> $jazykyPrekladu  jazyky, do kterých jde článek přeložit (jen u uloženého článku ve výchozím jazyce)
 * @var array<string, int> $preklady  existující překlady: jazyk => číslo článku
 * @var array{cas:string, data:string}|null $konceptServer  rozepsaný stav uložený na serveru (z jiného zařízení)
 * @var array<int, string> $vsichniAutori
 * @var list<int> $spoluautori
 * @var bool $jazykyWebu  web má další jazykové verze
 * @var string $original  adresa článku, jehož je tento překladem
 * @var array<int, string> $serialy
 * @var string $stitky  štítky oddělené čárkou
 * @var list<string> $vsechnyStitky
 * @var list<array<string, mixed>> $revize
 */
$dt = fn (?string $v): string => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$chyba = fn (string $pole): string => isset($chyby[$pole]) ? '<span class="chyba-pole" role="alert">' . e(t($chyby[$pole])) . '</span>' : '';
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled článků')) ?></a></p>

<?php if (!empty($konceptServer)): ?>
<script type="application/json" id="koncept-server"><?= json_encode(['cas' => strtotime($konceptServer['cas']) * 1000, 'pole' => json_decode($konceptServer['data'], true)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif ?>
<form class="formular formular-clanek" method="post" action="<?= e($modul->url('uloz')) ?>" data-koncept="clanek-<?= (int) $clanek['idc'] ?>" data-koncept-url="<?= e($modul->url('koncept')) ?>"<?= $asistent ? ' data-asistent="' . e($modul->url('asistent')) . '"' : '' ?>>
<?= $csrf ?>
<input type="hidden" name="idc" value="<?= (int) $clanek['idc'] ?>">

<div class="clanek-hlavni">
<?php
// Šablona článku určuje celou podobu textu na webu, proto je první volbou formuláře - ne poslední položkou dalších nastavení.
$popisySablon = [
    'standard' => 'Běžný článek: titulek, perex, text a obrázek.',
    'dlouhe-cteni' => 'Větší písmo, užší sloupec a výrazný úvodní obrázek.',
    'fotoreportaz' => 'Fotky přes celou šířku textu, text je doprovod.',
    'rozhovor' => 'Tučný odstavec je otázka, běžný odpověď.',
];
?>
<?php if (count($sablony) > 1): ?>
	<fieldset class="radek pres-celou sablona-clanku">
		<legend><?= e(t('Šablona článku')) ?></legend>
		<div class="karty-volby karty-volby-male">
<?php foreach ($sablony as $ids => $nazev): ?>
			<label class="karta-volba"><input type="radio" name="sablona" value="<?= (int) $ids ?>"<?= (int) $clanek['sablona'] === (int) $ids || ((int) $clanek['sablona'] === 0 && $ids === array_key_first($sablony)) ? ' checked' : '' ?>><strong><?= e(t($nazev)) ?></strong><?= e(t($popisySablon[$sablonySoubory[$ids] ?? ''] ?? '')) ?></label>
<?php endforeach ?>
		</div>
	</fieldset>
<?php else: ?>
	<input type="hidden" name="sablona" value="<?= (int) (array_key_first($sablony) ?? 0) ?>">
<?php endif ?>
	<div class="radek pres-celou">
		<label for="titulek"><?= e(t('Titulek')) ?></label>
		<input class="textpole siroke titulek-pole" type="text" id="titulek" name="titulek" value="<?= e($clanek['titulek']) ?>" maxlength="255" required placeholder="<?= e(t('Titulek článku')) ?>"><?= $chyba('titulek') ?>
	</div>
	<div class="radek pres-celou">
		<label for="uvod"><?= e(t('Perex (úvod)')) ?></label>
		<textarea class="textbox" id="uvod" name="uvod" rows="7" data-editor="maly"><?= e($clanek['uvod']) ?></textarea>
		<span class="napoveda"><?= e(t('Zobrazuje se ve výpisech i na začátku článku – v textu ho neopakujte.')) ?></span>
	</div>
	<div class="radek pres-celou">
		<label for="text"><?= e(t('Text článku')) ?></label>
		<textarea class="textbox vysoky" id="text" name="text" rows="20" data-editor><?= e($clanek['text']) ?></textarea>
		<span class="napoveda"><?= e(t('Video nebo příspěvek ze sítí vložíte tak, že jeho adresu dáte na samostatný řádek (YouTube, Vimeo, Spotify, X, Instagram, Facebook, TikTok, Mastodon). Čtenáři se načte až po kliknutí.')) ?></span>
	</div>
</div>

<aside class="clanek-nastaveni">
<fieldset>
<legend><?= e(t('Vydání')) ?></legend>
<div class="radek">
	<label for="stav"><?= e(t('Stav')) ?></label>
	<div><select id="stav" name="stav">
		<option value="koncept"<?= !$clanek['visible'] && $clanek['stav_redakce'] === '' ? ' selected' : '' ?>><?= e(t('Koncept – rozepsaný')) ?></option>
		<option value="korektura"<?= !$clanek['visible'] && $clanek['stav_redakce'] === 'korektura' ? ' selected' : '' ?>><?= e(t('Ke korektuře – hotovo, prosím o kontrolu')) ?></option>
<?php if ($smiVydavat): ?>
		<option value="schvaleno"<?= !$clanek['visible'] && $clanek['stav_redakce'] === 'schvaleno' ? ' selected' : '' ?>><?= e(t('Schváleno – čeká na vydání')) ?></option>
		<option value="vydany"<?= $clanek['visible'] ? ' selected' : '' ?>><?= e(t('Vydaný')) ?></option>
<?php endif ?>
	</select>
<?php if (!$smiVydavat): ?>
	<span class="napoveda"><?= e(t('Až bude článek hotový, přepněte ho na „Ke korektuře“ – vydá ho redaktor.')) ?></span>
<?php endif ?>
	</div>
</div>
<?php if ($viceLidi || (string) $clanek['poznamka'] !== ''): ?>
<div class="radek">
	<label for="poznamka"><?= e(t('Poznámka pro redakci')) ?></label>
	<div><textarea class="textbox radkovy" id="poznamka" name="poznamka" rows="2" placeholder="<?= e(t('Na webu se neukazuje.')) ?>"><?= e((string) $clanek['poznamka']) ?></textarea></div>
</div>
<?php endif ?>
<div class="radek">
	<label for="datum"><?= e(t('Datum vydání')) ?></label>
	<div><input class="textpole" type="datetime-local" id="datum" name="datum" value="<?= e($dt($clanek['datum'])) ?>" required>
	<span class="napoveda"><?= e(t('Budoucí datum = článek se vydá sám v daný čas.')) ?></span></div>
</div>
<div class="radek"<?= !$smiVydavat && !$clanek['visible'] ? ' hidden' : '' ?>>
	<span class="popisek"><?= e(t('Hlavní stránka')) ?></span>
	<div class="volby">
<?php if ($smiVydavat): // o titulní straně rozhoduje ten, kdo smí vydávat ?>
		<label><input type="checkbox" name="zobr_na_indexu" value="1"<?= $clanek['zobr_na_indexu'] ? ' checked' : '' ?>> <?= e(t('Zobrazit na hlavní stránce')) ?></label><br>
		<label><input type="checkbox" name="pripnout" value="1"<?= $clanek['priority'] > 0 ? ' checked' : '' ?>> <?= e(t('Připnout nahoru (otvírák)')) ?></label>
<?php endif ?>
<?php if ($clanek['visible']): ?>
		<br><label><input type="checkbox" name="oznacit_aktualizaci" value="1"> <?= e(t('Označit jako aktualizovaný (čtenář uvidí „Aktualizováno“ s dnešním datem)')) ?></label>
<?php endif ?>
	</div>
</div>
<p class="tlacitka ulozit-lista">
	<button class="tl" type="submit" name="po_ulozeni" value="vypis"><?= e(t('Uložit')) ?></button>
	<button class="tl" type="submit" name="po_ulozeni" value="zustat"><?= e(t('Uložit a pokračovat')) ?></button>
<?php if ($clanek['idc']): ?>
	<a class="navigace" href="<?= e($modul->app()->url('clanek/' . $clanek['seo_link'] . '?nahled=1')) ?>" target="_blank" rel="noopener"><?= e(t('Náhled')) ?></a>
<?php endif ?>
</p>
</fieldset>

<fieldset>
<legend><?= e(t('Zařazení')) ?></legend>
<div class="radek">
	<label for="tema"><?= e(t('Rubrika')) ?></label>
	<div><select id="tema" name="tema" required>
		<option value=""><?= e(t('– vyberte –')) ?></option>
<?php foreach ($rubriky as $r): ?>
		<option value="<?= (int) $r['idt'] ?>"<?= (int) $clanek['tema'] === (int) $r['idt'] ? ' selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;', $r['uroven']) . e($r['nazev']) ?></option>
<?php endforeach ?>
	</select><?= $chyba('tema') ?></div>
</div>
<?php if (count($autori) < 2): ?>
<input type="hidden" name="autor" value="<?= (int) (array_key_first($autori) ?? $clanek['autor']) ?>">
<?php endif ?>
<div class="radek"<?= count($autori) < 2 ? ' hidden' : '' ?>>
	<label for="autor"><?= e(t('Autor')) ?></label>
	<div><select id="autor" name="autor"<?= count($autori) < 2 ? ' disabled' : '' ?>>
<?php foreach ($autori as $idu => $jmeno): ?>
		<option value="<?= (int) $idu ?>"<?= (int) $clanek['autor'] === (int) $idu ? ' selected' : '' ?>><?= e($jmeno) ?></option>
<?php endforeach ?>
	</select><?= $chyba('autor') ?></div>
</div>
<details class="pokrocile"<?= $spoluautori !== [] || $clanek['externi_autor'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Spoluautoři a externí autor')) ?></summary>
<div class="radek"><span class="popisek"><?= e(t('Spoluautoři')) ?></span><div class="volby">
<?php foreach ($vsichniAutori as $idu => $jmenoAutora): ?>
	<label><input type="checkbox" name="spoluautori[]" value="<?= (int) $idu ?>"<?= in_array((int) $idu, $spoluautori, true) ? ' checked' : '' ?>> <?= e($jmenoAutora) ?></label><br>
<?php endforeach ?>
</div></div>
<div class="radek"><label for="externi_autor"><?= e(t('Externí autor')) ?></label><div><input class="textpole siroke" type="text" id="externi_autor" name="externi_autor" value="<?= e($clanek['externi_autor']) ?>" maxlength="120" placeholder="<?= e(t('např. ČTK nebo jméno hosta')) ?>"><span class="napoveda"><?= e(t('Host nebo agentura bez účtu v administraci. Na webu se uvede jako autor.')) ?></span></div></div>
</details>
<div class="radek">
	<label for="stitky"><?= e(t('Štítky')) ?></label>
	<div><input class="textpole siroke" type="text" id="stitky" name="stitky" value="<?= e($stitky) ?>" maxlength="600" list="stitky-seznam" autocomplete="off" data-stitky>
	<datalist id="stitky-seznam"><?php foreach ($vsechnyStitky as $s): ?><option value="<?= e($s) ?>"><?php endforeach ?></datalist>
	<span class="napoveda"><?= e(t('Oddělené čárkou, např. doprava, územní plán. Čtenář si podle štítku zobrazí související články.')) ?></span></div>
</div>
<div class="radek pres-celou">
	<label><input type="checkbox" name="komercni" value="1"<?= $clanek['komercni'] ? ' checked' : '' ?>> <?= e(t('Komerční sdělení')) ?></label>
	<div><input class="textpole siroke" type="text" id="komercni_partner" name="komercni_partner" value="<?= e($clanek['komercni_partner']) ?>" maxlength="120" placeholder="<?= e(t('partner (nepovinné)')) ?>" aria-label="<?= e(t('Partner')) ?>">
	<span class="napoveda"><?= e(t('Placený nebo partnerský obsah. Na webu se nad titulkem i ve výpisech označí „Komerční sdělení“, s partnerem „Komerční sdělení · partner: …“.')) ?></span></div>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Hlavní obrázek')) ?></legend>
<div class="radek pres-celou">
	<input class="textpole siroke" type="text" id="obrazek" name="obrazek" value="<?= e($clanek['obrazek']) ?>" maxlength="255" placeholder="<?= e(t('vyberte z médií, nebo vložte adresu')) ?>" aria-label="<?= e(t('Hlavní obrázek')) ?>" data-obrazek>
	<span class="napoveda"><?= e(t('Použije se ve výpisech a při sdílení na sociálních sítích.')) ?><?php if ($clanek['idc']): ?> <a href="<?= e($modul->app()->url('admin.php?modul=intergal&clanek=' . (int) $clanek['idc'])) ?>" target="_blank" rel="noopener"><?= e(t('Média použitá v článku')) ?></a><?php endif ?></span>
</div>
<div class="radek pres-celou">
	<label for="obrazek_popis"><?= e(t('Popisek fotky')) ?></label>
	<input class="textpole siroke" type="text" id="obrazek_popis" name="obrazek_popis" value="<?= e($clanek['obrazek_popis']) ?>" maxlength="300">
</div>
<div class="radek pres-celou">
	<label for="obrazek_autor"><?= e(t('Autor fotky')) ?></label>
	<div><input class="textpole siroke" type="text" id="obrazek_autor" name="obrazek_autor" value="<?= e($clanek['obrazek_autor']) ?>" maxlength="120" placeholder="<?= e(t('např. Jana Nováková / ČTK')) ?>">
	<span class="napoveda"><?= e(t('Prázdné pole = popisek a autor z knihovny Médií. Na webu se ukážou pod fotkou.')) ?></span></div>
</div>
</fieldset>

<details class="pokrocile"<?= $clanek['medium_url'] !== '' || (int) $clanek['zive'] > 0 || $clanek['recenze_hodnoceni'] !== null ? ' open' : '' ?>>
<summary><?= e(t('Podcast, video, živě, recenze')) ?></summary>
<div class="radek pres-celou">
	<label for="medium_url"><?= e(t('Zvuk nebo video')) ?></label>
	<input class="textpole siroke" type="text" id="medium_url" name="medium_url" value="<?= e($clanek['medium_url']) ?>" maxlength="255" placeholder="<?= e(t('adresa MP3/MP4, YouTube, Vimeo nebo Spotify')) ?>">
	<span class="napoveda"><?= e(t('Nad textem článku se zobrazí přehrávač. Epizody se zvukovým souborem najdou podcastové aplikace na adrese')) ?> <code>/podcast.xml</code>.</span>
</div>
<div class="radek pres-celou">
	<label for="zive"><?= e(t('Živá reportáž')) ?></label>
	<select id="zive" name="zive">
		<option value="0"><?= e(t('ne – běžný článek')) ?></option>
		<option value="1"<?= (int) $clanek['zive'] === 1 ? ' selected' : '' ?>><?= e(t('běží – zápisy se čtenářům načítají samy')) ?></option>
		<option value="2"<?= (int) $clanek['zive'] === 2 ? ' selected' : '' ?>><?= e(t('skončila – zápisy zůstávají')) ?></option>
	</select>
<?php if ($clanek['idc'] && (int) $clanek['zive'] > 0): ?>
	<a class="tl" href="<?= e($modul->url('zive', ['id' => (int) $clanek['idc']])) ?>"><?= e(t('Psát zápisy reportáže')) ?></a>
<?php else: ?>
	<span class="napoveda"><?= e(t('Po uložení se tu objeví tlačítko pro psaní průběžných zápisů.')) ?></span>
<?php endif ?>
</div>
<div class="radek pres-celou">
	<label for="recenze_hodnoceni"><?= e(t('Recenze – hodnocení v %')) ?></label>
	<input class="textpole" type="number" id="recenze_hodnoceni" name="recenze_hodnoceni" value="<?= e($clanek['recenze_hodnoceni'] === null ? '' : (string) $clanek['recenze_hodnoceni']) ?>" min="0" max="100" placeholder="–">
	<input class="textpole siroke" type="text" name="recenze_predmet" value="<?= e($clanek['recenze_predmet']) ?>" maxlength="160" placeholder="<?= e(t('co hodnotíte – název filmu, knihy, výrobku…')) ?>" aria-label="<?= e(t('Co hodnotíte')) ?>">
	<span class="napoveda"><?= e(t('Vyplněné hodnocení se zobrazí pod textem a předá vyhledávačům.')) ?></span>
</div>
</details>

<?php if ($jazykyWebu): ?>
<details class="pokrocile"<?= $original !== '' || $preklady !== [] ? ' open' : '' ?>>
<summary><?= e(t('Překlad článku')) ?></summary>
<?php if ($jazykyPrekladu !== []): ?>
<div class="radek pres-celou">
	<span class="popisek"><?= e(t('Jazykové verze článku')) ?></span>
	<div class="volby">
<?php foreach ($jazykyPrekladu as $kodJazyka): $nazevJazyka = MiroCMS\Core\Jazyk::DOSTUPNE[$kodJazyka][0]; ?>
<?php if (isset($preklady[$kodJazyka])): ?>
		<a class="navigace" href="<?= e($modul->url('edit', ['id' => $preklady[$kodJazyka]])) ?>"><?= e($nazevJazyka) ?>: <?= e(t('otevřít překlad')) ?></a>
<?php elseif ($asistent): ?>
		<button class="navigace" type="submit" name="prelozit_do" value="<?= e($kodJazyka) ?>" formaction="<?= e($modul->url('preloz')) ?>" formnovalidate data-potvrdit="<?= e(t('Přeložit uloženou verzi článku asistentem? Vznikne koncept, který před vydáním přečtete. Překlad může trvat i minutu.')) ?>"><?= e(t('Přeložit asistentem')) ?>: <?= e($nazevJazyka) ?></button>
<?php else: ?>
		<span class="napoveda vradku"><?= e($nazevJazyka) ?>: <?= e(t('zatím bez překladu')) ?></span>
<?php endif ?>
<?php endforeach ?>
		<span class="napoveda"><?= e(t('Překládá se naposledy uložená verze. Překlad se založí jako koncept v rubrice daného jazyka a propojí se s tímto článkem.')) ?></span>
	</div>
</div>
<?php endif ?>
<div class="radek pres-celou">
	<label for="preklad_z"><?= e(t('Originál ve výchozím jazyce')) ?></label>
	<input class="textpole siroke" type="text" id="preklad_z" name="preklad_z" value="<?= e($original) ?>" maxlength="255" placeholder="<?= e(t('adresa nebo číslo původního článku')) ?>">
	<span class="napoveda"><?= e(t('Vyplňte jen u článku v jiné jazykové verzi (jazyk určuje rubrika). Čtenář pak mezi jazyky přepne přímo na překlad a vyhledávače dostanou značky hreflang.')) ?></span>
</div>
</details>
<?php endif ?>

<fieldset class="kontrola" data-kontrola>
<legend><?= e(t('Kontrola přístupnosti')) ?></legend>
<div data-kontrola-vysledek aria-live="polite"><p class="napoveda"><?= e(t('Kontrola běží při psaní (potřebuje JavaScript).')) ?></p></div>
</fieldset>

<details class="pokrocile"<?= (int) $clanek['skupina_cl'] > 0 || $clanek['datum_pl'] || $clanek['zdroj'] !== '' || $clanek['t_slova'] !== '' || (int) $clanek['typ_clanku'] === 2 ? ' open' : '' ?>>
<summary><?= e(t('Další nastavení')) ?></summary>
<div class="radek">
	<label for="skupina_cl"><?= e(t('Seriál')) ?></label>
	<div><select id="skupina_cl" name="skupina_cl">
		<option value="0"><?= e(t('– článek není součástí seriálu –')) ?></option>
<?php foreach ($serialy as $ids => $nazev): ?>
		<option value="<?= (int) $ids ?>"<?= (int) $clanek['skupina_cl'] === (int) $ids ? ' selected' : '' ?>><?= e($nazev) ?></option>
<?php endforeach ?>
	</select>
	<input class="textpole siroke" type="text" name="serial_novy" maxlength="150" placeholder="<?= e(t('…nebo název nového seriálu')) ?>" aria-label="<?= e(t('Název nového seriálu')) ?>" style="margin-top:6px">
	<span class="napoveda"><?= e(t('U článku se zobrazí odkazy na ostatní díly.')) ?></span></div>
</div>
<div class="radek">
	<label for="seo_link"><?= e(t('Adresa článku')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_link" name="seo_link" value="<?= e($clanek['seo_link']) ?>" maxlength="150" placeholder="<?= e(t('vytvoří se z titulku')) ?>">
	<span class="napoveda"><?= e(t('Část adresy za /clanek/. Po vydání ji raději neměňte.')) ?></span></div>
</div>
<div class="radek">
	<label for="t_slova"><?= e(t('Klíčová slova')) ?></label>
	<div><input class="textpole siroke" type="text" id="t_slova" name="t_slova" value="<?= e($clanek['t_slova']) ?>" maxlength="500">
	<span class="napoveda"><?= e(t('Oddělená čárkou; pomáhají vyhledávání na webu.')) ?></span></div>
</div>
<div class="radek">
	<label for="zdroj"><?= e(t('Zdroj')) ?></label>
	<div><input class="textpole siroke" type="text" id="zdroj" name="zdroj" value="<?= e($clanek['zdroj']) ?>" maxlength="255">
	<span class="napoveda"><?= e(t('U převzatých textů: odkud pocházejí.')) ?></span></div>
</div>
<div class="radek">
	<label for="shrnuti"><?= e(t('Ve zkratce')) ?></label>
	<div><textarea class="textbox nizky" id="shrnuti" name="shrnuti" rows="4"><?= e((string) $clanek['shrnuti']) ?></textarea>
	<span class="napoveda"><?= e(t('Tři až pět hlavních sdělení, každé na vlastní řádek. Zobrazí se nad článkem; pomáhá čtenářům i AI vyhledávačům.')) ?></span></div>
</div>
<div class="radek">
	<label for="faq"><?= e(t('Otázky a odpovědi')) ?></label>
	<div><textarea class="textbox nizky" id="faq" name="faq" rows="5"><?= e((string) $clanek['faq']) ?></textarea>
	<span class="napoveda"><?= e(t('Otázka na jednom řádku, odpověď pod ní, mezi dvojicemi prázdný řádek. Zobrazí se pod článkem a ve strukturovaných datech (FAQ).')) ?></span></div>
</div>
<div class="radek">
	<label for="seo_titulek"><?= e(t('Titulek pro vyhledávače')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_titulek" name="seo_titulek" value="<?= e($clanek['seo_titulek']) ?>" maxlength="255" placeholder="<?= e(t('prázdné = titulek článku')) ?>"></div>
</div>
<div class="radek">
	<label for="seo_popis"><?= e(t('Popis pro vyhledávače')) ?></label>
	<div><input class="textpole siroke" type="text" id="seo_popis" name="seo_popis" value="<?= e($clanek['seo_popis']) ?>" maxlength="320" placeholder="<?= e(t('prázdné = začátek perexu')) ?>"></div>
</div>
<?php if ($ctenari): ?>
<div class="radek">
	<label for="pristup"><?= e(t('Kdo smí číst')) ?></label>
	<div><select id="pristup" name="pristup">
<?php foreach (\MiroCMS\Front\Ctenari::PRISTUP as $hodnota => $popis): ?>
		<option value="<?= $hodnota ?>"<?= (int) $clanek['pristup'] === $hodnota ? ' selected' : '' ?>><?= e(t($popis)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Ostatní uvidí perex, začátek textu a výzvu k přihlášení.')) ?></span></div>
</div>
<?php endif ?>
<div class="radek">
	<label for="datum_pl"><?= e(t('Stáhnout z hlavní stránky')) ?></label>
	<div><input class="textpole" type="datetime-local" id="datum_pl" name="datum_pl" value="<?= e($dt($clanek['datum_pl'])) ?>">
	<span class="napoveda"><?= e(t('Nepovinné. V rubrice a ve vyhledávání článek zůstane.')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Možnosti')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="povolit_kom" value="1"<?= $clanek['povolit_kom'] ? ' checked' : '' ?>> <?= e(t('Povolit komentáře')) ?></label><br>
		<label><input type="checkbox" name="noindex" value="1"<?= $clanek['noindex'] ? ' checked' : '' ?>> <?= e(t('Skrýt před vyhledávači (noindex)')) ?></label><br>
		<label><input type="checkbox" name="kratky" value="1"<?= (int) $clanek['typ_clanku'] === 2 ? ' checked' : '' ?>> <?= e(t('Krátká zpráva – jen perex, bez vlastní stránky')) ?></label>
	</div>
</div>
</details>
<?php if ($revize !== []): ?>
<details class="pokrocile">
<summary><?= e(t('Historie verzí (%s)', count($revize))) ?></summary>
<ul class="revize">
<?php foreach ($revize as $rv): ?>
	<li><a href="<?= e($modul->url('revize', ['id' => $clanek['idc'], 'idr' => $rv['idr']])) ?>" title="<?= e($rv['titulek']) ?>"><?= e(datum($rv['datum'], true)) ?></a> <span class="napoveda vradku"><?= e($rv['kdo_jm'] ?? '') ?></span> · <a href="<?= e($modul->url('porovnej', ['id' => $clanek['idc'], 'idr' => $rv['idr']])) ?>"><?= e(t('co se změnilo')) ?></a></li>
<?php endforeach ?>
</ul>
<p class="napoveda"><?= e(t('Kliknutím načtete starší verzi do editoru. Uchovává se posledních 20 verzí.')) ?></p>
</details>
<?php endif ?>
</aside>
</form>
<script src="<?= e($modul->app()->url('image/pomocnik.js')) ?>?v=<?= e(MIROCMS_VERSION) ?>" defer></script>
