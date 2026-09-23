<?php /** Záložka Měření. */ ?>
<fieldset>
<legend><?= e(t('Návštěvnost')) ?></legend>
<?php
$pole('statistika', 'Vestavěná statistika', 'ano', 'Návštěvy, nejčtenější články a zdroje návštěv v sekci Statistika. Bez cookies a bez souhlasu.');
$pole('ga4_id', 'Google Analytics', 'text', 'Stačí ID měření ve tvaru G-XXXXXXXXXX. Spouští se až po souhlasu návštěvníka (záložka Soukromí a cookies).', 'placeholder="G-" maxlength="24"');
?>
</fieldset>
<details class="pokrocile"<?= $hodnoty['matomo_url'] . $hodnoty['plausible_domena'] . $hodnoty['kod_hlava'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Další nástroje (Matomo, Plausible, vlastní kód)')) ?></summary>
<?php
$pole('matomo_url', 'Matomo – adresa', 'url', 'Adresa vaší instalace, např. https://statistiky.example.cz/', 'placeholder="https://"');
$pole('matomo_id', 'Matomo – ID webu', 'cislo', '', 'min="0"');
$pole('plausible_domena', 'Plausible – doména', 'text', 'Nepoužívá cookies, načítá se bez souhlasu.', 'placeholder="example.cz" maxlength="100"');
$pole('kod_hlava', 'Vlastní kód do hlavičky', 'kod', 'Vloží se na každou stránku bez ohledu na souhlas – jen pro kódy, které neukládají cookies.', 'spellcheck="false"');
?>
</details>
