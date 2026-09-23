<?php /** Záložka Soukromí a cookies. */ ?>
<fieldset>
<legend><?= e(t('Cookie lišta')) ?></legend>
<div class="karty-volby karty-volby-text">
<?php foreach ([
    'vestavena' => ['Vestavěná lišta', 'Doporučeno. Zobrazí se jen tehdy, když je co odsouhlasit; měření se spustí až po souhlasu.'],
    'externi' => ['Externí služba', 'Cookiebot, CookieYes, Usercentrics… Vložíte jejich kód.'],
    'zadna' => ['Žádná', 'Měřicí kódy se spouštějí hned. Jen když souhlas řešíte jinak.'],
] as $klic => [$nazev, $popis]): ?>
	<label class="karta-volba">
		<input type="radio" name="cookies_rezim" value="<?= e($klic) ?>"<?= $hodnoty['cookies_rezim'] === $klic ? ' checked' : '' ?>>
		<strong><?= e(t($nazev)) ?></strong>
		<span><?= e(t($popis)) ?></span>
	</label>
<?php endforeach ?>
</div>
<?php
$pole('cookies_text', 'Text lišty', 'radky');
$pole('cookies_zasady_url', 'Odkaz na zásady', 'text', 'Např. /zasady-ochrany-soukromi – stránku vytvoříte v sekci Stránky.', 'maxlength="255"');
?>
</fieldset>
<details class="pokrocile"<?= $hodnoty['cookies_rezim'] === 'externi' || $hodnoty['kod_marketing'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Kódy a evidence')) ?></summary>
<?php
$pole('cookies_externi_kod', 'Kód externí služby', 'kod', 'Skript od poskytovatele (u Cookiebotu řádek s data-cbid). Načte se jako první.', 'spellcheck="false"');
$pole('kod_marketing', 'Marketingové kódy', 'kod', 'Meta Pixel, Sklik retargeting, Google Ads… Spustí se až po souhlasu s marketingem.', 'spellcheck="false"');
$pole('cookies_evidence', 'Evidovat souhlasy', 'ano', 'Čas, náhodný identifikátor a zvolené kategorie – bez IP adresy. Doklad pro případnou kontrolu.');
?>
</details>
<?php if ($souhlasy !== []): ?>
<p class="napoveda"><?= e(t('Souhlasy za posledních 30 dní:')) ?> <?= implode(' · ', array_map(fn (array $r): string => e($r['kategorie'] === 'nic' ? t('jen nezbytné') : $r['kategorie']) . ' ' . (int) $r['pocet'] . '×', $souhlasy)) ?></p>
<?php endif ?>
<fieldset>
<legend><?= e(t('Žádost čtenáře o osobní údaje')) ?></legend>
<p class="napoveda"><?= e(t('Když čtenář požádá o výpis nebo výmaz svých údajů (GDPR), zadejte jeho e-mail. Týká se komentářů, odběru newsletteru a účtu čtenáře.')) ?> <?= e(t('Výpis obsahuje i platby předplatného (datum, částka, měna). Výmaz smaže účet; záznamy o platbách zůstávají kvůli účetnictví, ale bez vazby na čtenáře. Údaje uložené ve Stripe (zákazník, karta, faktury) se odsud nemažou – smažte je ve Stripe a případné běžící předplatné tam zrušte.')) ?></p>
<div class="radek"><label for="gdpr_email"><?= e(t('E-mail čtenáře')) ?></label><input class="textpole siroke" type="email" id="gdpr_email" name="gdpr_email" maxlength="190"></div>
<p><button class="navigace" type="submit" formaction="<?= e($modul->url('osobni_udaje')) ?>" name="gdpr_co" value="export" formnovalidate><?= e(t('Stáhnout jeho údaje')) ?></button>
<button class="navigace nebezpecne" type="submit" formaction="<?= e($modul->url('osobni_udaje')) ?>" name="gdpr_co" value="smazat" data-potvrdit="<?= e(t('Nevratně smazat komentáře, odběr a účet tohoto čtenáře?')) ?>"><?= e(t('Smazat jeho údaje')) ?></button></p>
</fieldset>
