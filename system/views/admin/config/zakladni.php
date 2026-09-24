<?php /** Záložka Základní. Proměnné a funkce $pole viz vypis.php. */ ?>
<fieldset>
<legend><?= e(t('Web')) ?></legend>
<?php
$pole('nazev_webu', 'Název webu', 'text', '', 'maxlength="150" required');
$pole('adresa_webu', 'Adresa webu', 'url', 'Například https://www.firma.cz, bez lomítka na konci. Skládají se z ní odkazy v e-mailech, RSS, mapě webu a oznámeních. Po přestěhování na jinou doménu ji změňte.', 'required placeholder="https://"');
$pole('popis_webu', 'Popis webu', 'radky', 'Jedna až dvě věty – motto, popis pro vyhledávače a RSS.');
$pole('email_webu', 'E-mail webu', 'email', 'Chodí na něj upozornění systému.');
?>
<div class="radek">
	<label for="vynutit_2fa"><?= e(t('Dvoufázové přihlášení')) ?></label>
	<div><select id="vynutit_2fa" name="vynutit_2fa">
<?php foreach (['' => 'dobrovolné', 'spravci' => 'povinné pro správce', 'vsichni' => 'povinné pro všechny uživatele'] as $k => $n): ?>
		<option value="<?= e($k) ?>"<?= ($hodnoty['vynutit_2fa'] ?? '') === $k ? ' selected' : '' ?>><?= e(t($n)) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Kdo ho povinně má a ještě si ho nezapnul, se po přihlášení dostane jen do Můj účet, dokud ho nenastaví.')) ?></span></div>
</div>
<?php
?>
<?php if (($jazykyDalsi = Kaleta\Core\Jazyk::dalsi($app->settings())) !== []): ?>
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
	<span class="napoveda"><?= e(t('Podle něj se vydávají naplánované novinky a zobrazují data. Teď je %s.', date('j. n. Y H:i'))) ?></span></div>
</div>
<div class="radek">
	<label for="jazyk_webu"><?= e(t('Jazyk webu')) ?></label>
	<div><select id="jazyk_webu" name="jazyk_webu">
<?php foreach (Kaleta\Core\Jazyk::DOSTUPNE as $kod => [$nazevJazyka]): ?>
		<option value="<?= e($kod) ?>"<?= $hodnoty['jazyk_webu'] === $kod ? ' selected' : '' ?>><?= e($nazevJazyka) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('V tomto jazyce jsou texty šablony (Hledat, Novinky, Číst dál…) a web se tak hlásí vyhledávačům.')) ?></span></div>
</div>
<?php if (Kaleta\Core\Rozsireni::je($app->settings(), 'jazyky')): ?>
<div class="radek" id="jazyky_dalsi">
	<span class="popisek"><?= e(t('Další jazykové verze')) ?></span>
	<div class="volby">
<?php foreach (Kaleta\Core\Jazyk::DOSTUPNE as $kod => [$nazevJazyka]): if ($kod === $hodnoty['jazyk_webu']) { continue; } ?>
		<label><input type="checkbox" name="jazyky_dalsi[]" value="<?= e($kod) ?>"<?= in_array($kod, explode(',', $hodnoty['jazyky_dalsi']), true) ? ' checked' : '' ?>> <?= e($nazevJazyka) ?> <small>(/<?= e($kod) ?>/)</small></label><br>
<?php endforeach ?>
		<span class="napoveda"><?= e(t('Každá verze má své stránky, kategorie a novinky. Jazyk novinky určuje její kategorie. Překlad propojíte v editoru.')) ?></span>
	</div>
</div>
<?php else: ?>
<?php foreach (array_filter(explode(',', $hodnoty['jazyky_dalsi'])) as $kod): ?><input type="hidden" name="jazyky_dalsi[]" value="<?= e($kod) ?>"><?php endforeach ?>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Úvodní stránka a novinky')) ?></legend>
<div class="radek">
	<label for="titulni_stranka"><?= e(t('Úvodní stránka webu')) ?></label>
	<div><select id="titulni_stranka" name="titulni_stranka">
		<option value="0"><?= e(t('– výpis novinek –')) ?></option>
<?php foreach ($stranky as $idStranky => $titulekStranky): ?>
		<option value="<?= (int) $idStranky ?>"<?= (int) $hodnoty['titulni_stranka'] === (int) $idStranky ? ' selected' : '' ?>><?= e($titulekStranky) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Stránka, která se ukáže na adrese webu. Novinky jsou vždy na /novinky.')) ?></span></div>
</div>
<?php $pole('pocet_clanku', 'Novinek na stránku', 'cislo', '', 'min="1" max="100"'); ?>
</fieldset>
<details class="pokrocile"<?= $hodnoty['udrzba'] === '1' ? ' open' : '' ?>>
<summary><?= e(t('Režim údržby')) ?><?= $hodnoty['udrzba'] === '1' ? ' – ' . e(t('ZAPNUTÝ')) : '' ?></summary>
<?php
$pole('udrzba', 'Web je dočasně mimo provoz', 'ano', 'Návštěvníci uvidí jen oznámení níže. Přihlášení uživatelé vidí web normálně.');
$pole('udrzba_text', 'Text oznámení', 'text', '', 'maxlength="300"');
?>
</details>
<details class="pokrocile">
<summary><?= e(t('Sociální sítě')) ?></summary>
<?php foreach (Kaleta\Admin\Moduly\Konfigurace::SITE as $klic => $nazev) { $pole($klic, $nazev, 'url', '', 'placeholder="https://"'); } ?>
<p class="napoveda"><?= e(t('Vyplněné profily se zobrazí v patičce webu a předají se vyhledávačům.')) ?></p>
</details>
<details class="pokrocile">
<summary><?= e(t('Další možnosti')) ?></summary>
<?php
$pole('text_paticky', 'Text v patičce', 'text', 'Například obchodní firma a IČO.', 'maxlength="300"');
$pole('sdileni', 'Odkazy pro sdílení pod novinkou', 'ano', 'Facebook, X, LinkedIn, WhatsApp, e-mail a kopírování odkazu – bez cizích skriptů.');
$pole('osnova_clanku', 'Obsah novinky z mezititulků', 'ano', 'U novinek s aspoň třemi mezititulky se nad textem zobrazí klikací osnova.');
$pole('souvisejici_auto', 'Související novinky', 'ano', 'Pod novinkou se nabídnou podobné podle štítků a kategorie.');
?>
<?php
$pole('webhook_poptavky', 'Webhook nové poptávky', 'url', 'Kam poslat každou novou poptávku z formuláře (CRM, Make, Zapier, n8n, Slack). Dostane název formuláře, vyplněná pole a e-mail odesílatele.', 'placeholder="https://"');
$pole('webhook_url', 'Webhook po vydání novinky', 'url', 'Adresa ze služby Make, Zapier, IFTTT nebo n8n. Po vydání novinky na ni systém pošle titulek, perex, adresu a obrázek – služba je pak sama sdílí na Facebook, X, Mastodon, do Slacku apod.', 'placeholder="https://"');
$pole('kontrola_odkazu', 'Hledat nefunkční odkazy', 'ano', 'Na pozadí, jedna novinka za pět minut. Výsledek je v Novinky → Nefunkční odkazy.');
$pole('cache_stranek', 'Cache stránek', 'ano', 'Hotové stránky se návštěvníkům podávají z paměti – web je rychlejší a vydrží nápor. Nechte zapnuté.');
?>
</details>
