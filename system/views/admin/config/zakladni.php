<?php /** Záložka Základní. Proměnné a funkce $pole viz vypis.php. */ ?>
<fieldset>
<legend><?= e(t('Web')) ?></legend>
<?php
$pole('nazev_webu', 'Název webu', 'text', '', 'maxlength="150" required');
$pole('adresa_webu', 'Adresa webu', 'url', 'Například https://www.mujmagazin.cz, bez lomítka na konci. Skládají se z ní odkazy v e-mailech, RSS, mapě webu a oznámeních. Po přestěhování na jinou doménu ji změňte.', 'required placeholder="https://"');
$pole('popis_webu', 'Popis webu', 'radky', 'Jedna až dvě věty – motto, popis pro vyhledávače a RSS.');
$pole('email_webu', 'E-mail redakce', 'email', 'Chodí na něj upozornění systému.');
?>
<?php if (($jazykyDalsi = MiroCMS\Core\Jazyk::dalsi($app->settings())) !== []): ?>
<details class="pokrocile"<?= array_filter($jazykyDalsi, fn (string $j): bool => ($hodnoty['nazev_webu_' . $j] ?? '') . ($hodnoty['popis_webu_' . $j] ?? '') !== '') !== [] ? ' open' : '' ?>>
<summary><?= e(t('Název a popis v dalších jazykových verzích')) ?></summary>
<p class="napoveda"><?= e(t('Prázdné pole znamená stejný text jako ve výchozím jazyce.')) ?></p>
<?php foreach ($jazykyDalsi as $j): ?>
<div class="radek"><label for="nazev_webu_<?= e($j) ?>"><?= e(t('Název webu')) ?> (<?= e(strtoupper($j)) ?>)</label><div><input class="textpole siroke" type="text" id="nazev_webu_<?= e($j) ?>" name="nazev_webu_<?= e($j) ?>" value="<?= e($hodnoty['nazev_webu_' . $j] ?? '') ?>" maxlength="150" lang="<?= e($j) ?>"></div></div>
<div class="radek"><label for="popis_webu_<?= e($j) ?>"><?= e(t('Popis webu')) ?> (<?= e(strtoupper($j)) ?>)</label><div><textarea class="textbox radkovy" id="popis_webu_<?= e($j) ?>" name="popis_webu_<?= e($j) ?>" rows="2" cols="60" lang="<?= e($j) ?>"><?= e($hodnoty['popis_webu_' . $j] ?? '') ?></textarea></div></div>
<?php endforeach ?>
</details>
<?php endif ?>
<div class="radek">
	<label for="casove_pasmo"><?= e(t('Časové pásmo')) ?></label>
	<div><select id="casove_pasmo" name="casove_pasmo">
<?php foreach (DateTimeZone::listIdentifiers() as $pasmo): ?>
		<option value="<?= e($pasmo) ?>"<?= $hodnoty['casove_pasmo'] === $pasmo ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $pasmo)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Podle něj se vydávají naplánované články a zobrazují data. Teď je %s.', date('j. n. Y H:i'))) ?></span></div>
</div>
<div class="radek">
	<label for="jazyk_webu"><?= e(t('Jazyk webu')) ?></label>
	<div><select id="jazyk_webu" name="jazyk_webu">
<?php foreach (MiroCMS\Core\Jazyk::DOSTUPNE as $kod => [$nazevJazyka]): ?>
		<option value="<?= e($kod) ?>"<?= $hodnoty['jazyk_webu'] === $kod ? ' selected' : '' ?>><?= e($nazevJazyka) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('V tomto jazyce jsou texty šablony (Hledat, Celý článek, Komentáře…) a web se tak hlásí vyhledávačům.')) ?></span></div>
</div>
<?php if (MiroCMS\Core\Rozsireni::je($app->settings(), 'jazyky')): ?>
<div class="radek">
	<span class="popisek"><?= e(t('Další jazykové verze')) ?></span>
	<div class="volby">
<?php foreach (MiroCMS\Core\Jazyk::DOSTUPNE as $kod => [$nazevJazyka]): if ($kod === $hodnoty['jazyk_webu']) { continue; } ?>
		<label><input type="checkbox" name="jazyky_dalsi[]" value="<?= e($kod) ?>"<?= in_array($kod, explode(',', $hodnoty['jazyky_dalsi']), true) ? ' checked' : '' ?>> <?= e($nazevJazyka) ?> <small>(/<?= e($kod) ?>/)</small></label><br>
<?php endforeach ?>
		<span class="napoveda"><?= e(t('Každá verze má své rubriky, články a stránky. Jazyk se volí u rubriky – článek ho převezme. Překlad článku propojíte v jeho editoru.')) ?></span>
	</div>
</div>
<?php else: ?>
<?php foreach (array_filter(explode(',', $hodnoty['jazyky_dalsi'])) as $kod): ?><input type="hidden" name="jazyky_dalsi[]" value="<?= e($kod) ?>"><?php endforeach ?>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Články a čtenáři')) ?></legend>
<?php
$pole('pocet_clanku', 'Článků na stránku', 'cislo', '', 'min="1" max="100"');
$pole('povolit_komentare', 'Komentáře pod články', 'ano');
?>
<div class="radek">
	<label for="komentare_rezim"><?= e(t('Nový komentář')) ?></label>
	<select id="komentare_rezim" name="komentare_rezim">
		<option value="hned"<?= $hodnoty['komentare_rezim'] === 'hned' ? ' selected' : '' ?>><?= e(t('zveřejnit hned (podezřelé počkají na schválení)')) ?></option>
		<option value="schvalovat"<?= $hodnoty['komentare_rezim'] === 'schvalovat' ? ' selected' : '' ?>><?= e(t('zveřejnit až po schválení redakcí')) ?></option>
	</select>
</div>
</fieldset>
<details class="pokrocile"<?= $hodnoty['udrzba'] === '1' ? ' open' : '' ?>>
<summary><?= e(t('Režim údržby')) ?><?= $hodnoty['udrzba'] === '1' ? ' – ' . e(t('ZAPNUTÝ')) : '' ?></summary>
<?php
$pole('udrzba', 'Web je dočasně mimo provoz', 'ano', 'Návštěvníci uvidí jen oznámení níže. Přihlášená redakce vidí web normálně.');
$pole('udrzba_text', 'Text oznámení', 'text', '', 'maxlength="300"');
?>
</details>
<details class="pokrocile">
<summary><?= e(t('Sociální sítě')) ?></summary>
<?php foreach (MiroCMS\Admin\Moduly\Konfigurace::SITE as $klic => $nazev) { $pole($klic, $nazev, 'url', '', 'placeholder="https://"'); } ?>
<p class="napoveda"><?= e(t('Vyplněné profily se zobrazí v patičce webu a předají se vyhledávačům.')) ?></p>
</details>
<details class="pokrocile">
<summary><?= e(t('Další možnosti')) ?></summary>
<?php
$pole('text_paticky', 'Text v patičce', 'text', 'Například vydavatel, ISSN nebo kontakt.', 'maxlength="300"');
$pole('klicova_slova', 'Klíčová slova webu', 'text');
$pole('pocet_novinek', 'Novinek v bloku', 'cislo', '', 'min="0" max="50"');
$pole('povolit_hodnoceni', 'Hodnocení článků hvězdičkami', 'ano');
$pole('sdileni', 'Odkazy pro sdílení pod článkem', 'ano', 'Facebook, X, LinkedIn, WhatsApp, e-mail a kopírování odkazu – bez cizích skriptů.');
$pole('doba_cteni', 'Doba čtení a ukazatel průběhu', 'ano', 'U článků delších než dvě minuty čtení.');
$pole('osnova_clanku', 'Obsah článku z mezititulků', 'ano', 'U článků s aspoň třemi mezititulky se nad textem zobrazí klikací osnova.');
$pole('souvisejici_auto', 'Související články automaticky', 'ano', 'Když článek není dílem seriálu, vyberou se podobné podle štítků a rubriky.');
?>
<div class="radek">
	<label for="upozorneni_komentare"><?= e(t('E-mail redakci o komentářích')) ?></label>
	<select id="upozorneni_komentare" name="upozorneni_komentare">
		<option value="schvaleni"<?= $hodnoty['upozorneni_komentare'] === 'schvaleni' ? ' selected' : '' ?>><?= e(t('když komentář čeká na schválení')) ?></option>
		<option value="vse"<?= $hodnoty['upozorneni_komentare'] === 'vse' ? ' selected' : '' ?>><?= e(t('při každém novém komentáři')) ?></option>
		<option value="nic"<?= $hodnoty['upozorneni_komentare'] === 'nic' ? ' selected' : '' ?>><?= e(t('neposílat')) ?></option>
	</select>
</div>
<?php
$pole('hlidat_platnost', 'Stahovat články z hlavní stránky', 'ano', 'Článek po svém „datu stažení“ zmizí z hlavní stránky; v rubrice zůstane.');
$pole('webhook_url', 'Webhook po vydání článku', 'url', 'Adresa ze služby Make, Zapier, IFTTT nebo n8n. Po vydání článku na ni systém pošle titulek, perex, adresu a obrázek – služba je pak sama sdílí na Facebook, X, Mastodon, do Slacku apod.', 'placeholder="https://"');
$pole('kontrola_odkazu', 'Hledat nefunkční odkazy', 'ano', 'Na pozadí, jeden článek za pět minut. Výsledek je v Článcích → Nefunkční odkazy.');
$pole('cache_stranek', 'Cache stránek', 'ano', 'Hotové stránky se čtenářům podávají z paměti – web je rychlejší a vydrží nápor. Nechte zapnuté.');
?>
</details>
<fieldset>
<legend><?= e(t('Ukázkový obsah')) ?></legend>
<?php if ($demoNahrano): ?>
<p class="napoveda"><?= e(t('Na webu jsou rubriky, články a obrázky smyšleného magazínu. Až je nebudete potřebovat, smažte je najednou – vaše vlastní články a rubriky zůstanou.')) ?></p>
<p><button class="navigace nebezpecne" type="submit" formaction="<?= e($modul->url('demo_smaz')) ?>" formnovalidate data-potvrdit="<?= e(t('Smazat ukázkový obsah? Zmizí ukázkové články včetně úprav, které jste v nich udělali, prázdné ukázkové rubriky a nepoužité ukázkové obrázky.')) ?>"><?= e(t('Smazat ukázkový obsah')) ?></button></p>
<?php else: ?>
<p class="napoveda"><?= e(t('Pět rubrik, deset článků a obrázky smyšleného magazínu v jazyce webu, ať hned vidíte, jak web vypadá. Později je tady jedním kliknutím smažete.')) ?></p>
<p><button class="navigace" type="submit" formaction="<?= e($modul->url('demo_nahraj')) ?>" formnovalidate><?= e(t('Nahrát ukázkový obsah')) ?></button></p>
<?php endif ?>
</fieldset>
