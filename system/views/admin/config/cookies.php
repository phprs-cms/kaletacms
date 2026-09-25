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
$pole('cookies_evidence_mesice', 'Uchovávat záznamy o souhlasech (měsíců)', 'cislo', 'Starší záznamy se mažou automaticky. 0 = nemazat.', 'min="0" max="120"');
?>
</details>
<?php if ($souhlasy !== []): ?>
<p class="napoveda"><?= e(t('Souhlasy za posledních 30 dní:')) ?> <?= implode(' · ', array_map(fn (array $r): string => e($r['kategorie'] === 'nic' ? t('jen nezbytné') : $r['kategorie']) . ' ' . (int) $r['pocet'] . '×', $souhlasy)) ?></p>
<?php endif ?>
