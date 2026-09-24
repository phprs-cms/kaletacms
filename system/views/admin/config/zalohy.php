<?php
/** Záložka Zálohy a aktualizace. */
?>
<fieldset>
<legend><?= e(t('Aktualizace systému')) ?></legend>
<p><?= e(t('Nainstalovaná verze:')) ?> <strong><?= e($aktualizace['aktualni']) ?></strong></p>
<?php if (!$aktualizace['nastaveno']): ?>
<p class="hlaska"><?= e(t('Zdroj aktualizací zatím není nastaven. Novou verzi nahrajete přes FTP (přepište všechny soubory kromě config.php, media/ a storage/); databáze se upraví sama.')) ?></p>
<?php elseif ($aktualizace['chyba'] !== null): ?>
<p class="hlaska hlaska-chyba"><?= e($aktualizace['chyba']) ?></p>
<?php elseif ($aktualizace['nova'] !== null): ?>
<div class="hlaska hlaska-ok">
	<p><strong><?= e(t(!empty($aktualizace['nova']['bezpecnostni']) ? 'Bezpečnostní aktualizace: verze %s' : 'K dispozici je verze %s', (string) $aktualizace['nova']['verze'])) ?></strong><?= !empty($aktualizace['nova']['vydano']) ? ' (' . e(datum((string) $aktualizace['nova']['vydano'])) . ')' : '' ?></p>
<?php if ($aktualizace['nova']['zmeny'] !== []): ?>
	<ul><?php foreach ($aktualizace['nova']['zmeny'] as $zmena): ?><li><?= e($zmena) ?></li><?php endforeach ?></ul>
<?php endif ?>
	<p><button class="tl" type="submit" formaction="<?= e($modul->url('aktualizuj')) ?>" data-potvrdit="<?= e(t('Aktualizovat systém? Nejprve se vytvoří záloha databáze. Web bude několik vteřin nedostupný.')) ?>"><?= e(t('Aktualizovat na %s', $aktualizace['nova']['verze'])) ?></button></p>
</div>
<p class="napoveda"><?= e(t('Před aktualizací se zazálohuje databáze. Balíček se přijme jen s platným podpisem vydavatele. Nepřepisuje se config.php, nahraná média ani vlastní šablony webu.')) ?></p>
<?php else: ?>
<p><?= e(t('Máte aktuální verzi.')) ?><?= $aktualizace['overeno'] ? ' <small>' . e(t('Ověřeno %s.', date('j. n. Y H:i', $aktualizace['overeno']))) . '</small>' : '' ?></p>
<?php endif ?>
<?php if ($aktualizace['nastaveno']): ?>
<p><button class="navigace" type="submit" formaction="<?= e($modul->url('zkontroluj')) ?>"><?= e(t('Zkontrolovat teď')) ?></button></p>
<?php endif ?>
<?php $pole('aktualizace_auto', 'Bezpečnostní aktualizace instalovat automaticky', 'ano', 'Doporučeno. Týká se jen vydání označených jako bezpečnostní; běžné verze instalujete sami. Systém se po novinkách dívá dvakrát denně, před instalací zálohuje databázi a o výsledku pošle e-mail na adresu redakce.'); ?>
<?php $pole('aktualizace_url', 'Vlastní zdroj aktualizací', 'url', 'Nechte prázdné. Jinou adresu souboru aktualizace.json vyplňte jen tehdy, když si verze spravujete sami.', 'placeholder="https://"'); ?>
</fieldset>

<fieldset>
<legend><?= e(t('Zálohy databáze')) ?></legend>
<details class="pokrocile"<?= $hodnoty['zaloha_vzdalena'] !== 'vypnuto' ? ' open' : '' ?>>
<summary><?= e(t('Kopie záloh mimo server')) ?><?= $hodnoty['zaloha_vzdalena'] !== 'vypnuto' ? ' – ' . e(t('zapnuté')) : '' ?></summary>
<p class="napoveda"><?= e(t('Záloha na stejném serveru jako web nepomůže, když o hosting přijdete. Každá nová záloha databáze se proto může sama nahrát jinam. Média se tímto způsobem nekopírují – stahujte si je občas jako ZIP.')) ?></p>
<div class="radek"><label for="zaloha_vzdalena"><?= e(t('Kam kopírovat')) ?></label><select id="zaloha_vzdalena" name="zaloha_vzdalena">
	<option value="vypnuto"><?= e(t('nikam')) ?></option>
	<option value="ftp"<?= $hodnoty['zaloha_vzdalena'] === 'ftp' ? ' selected' : '' ?>><?= e(t('na FTP server (jiný hosting, domácí NAS)')) ?></option>
	<option value="s3"<?= $hodnoty['zaloha_vzdalena'] === 's3' ? ' selected' : '' ?>><?= e(t('do úložiště S3 (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2)')) ?></option>
</select></div>
<?php
$pole('zaloha_host', 'Server', 'text', 'FTP: ftp.example.cz. S3: adresa úložiště, např. s3.eu-central-1.amazonaws.com nebo s3.eu-central-003.backblazeb2.com.', 'maxlength="150" autocomplete="off"');
$pole('zaloha_uzivatel', 'Jméno / přístupový klíč', 'text', '', 'maxlength="190" autocomplete="off"');
?>
<div class="radek"><label for="zaloha_heslo"><?= e(t('Heslo / tajný klíč')) ?></label><div><input class="textpole siroke" type="password" id="zaloha_heslo" name="zaloha_heslo" value="" autocomplete="new-password" placeholder="<?= $hodnoty['zaloha_heslo'] !== '' ? e(t('uloženo – nové vložte jen při změně')) : '' ?>">
<?php if ($hodnoty['zaloha_heslo'] !== ''): ?>
	<label><input type="checkbox" name="zaloha_heslo_smazat" value="1"> <?= e(t('Odebrat uložené heslo')) ?></label>
<?php endif ?>
</div></div>
<?php
$pole('zaloha_slozka', 'Složka / bucket', 'text', 'FTP: složka pro zálohy (vytvoří se). S3: název bucketu, případně bucket/složka.', 'maxlength="150"');
$pole('zaloha_region', 'Region (jen S3)', 'text', 'Například eu-central-1. U Cloudflare R2 zadejte auto.', 'maxlength="40" style="width:180px"');
?>
<?php if ($vzdalenaStav !== ''): [$kdy, $jak] = explode('|', $vzdalenaStav, 2) + [1 => '']; ?>
<p class="hlaska<?= $jak === 'ok' ? ' hlaska-ok' : ' hlaska-chyba' ?>"><?= e($jak === 'ok' ? t('Poslední kopie byla nahrána %s.', $kdy) : t('Poslední pokus %s selhal: %s', $kdy, $jak)) ?></p>
<?php endif ?>
<p class="napoveda"><?= e(t('Nastavení uložte a pak klepněte na „Vytvořit zálohu teď“ – kopie se nahraje hned a uvidíte, jestli spojení funguje.')) ?></p>
</details>
<?php $pole('zalohy_auto', 'Automatická záloha jednou týdně', 'ano', 'Vytvoří se při přihlášení administrátora, když je poslední záloha starší než týden. Uchovává se posledních 10 záloh.'); ?>
<p><button class="tl" type="submit" formaction="<?= e($modul->url('zalohuj')) ?>"><?= e(t('Vytvořit zálohu teď')) ?></button></p>
<?php if ($zalohy !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Soubor')) ?></th><th scope="col"><?= e(t('Vytvořena')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($zalohy as $z): ?>
<tr>
	<td><?= e($z['soubor']) ?></td>
	<td class="cislo"><?= e(date('j. n. Y H:i', $z['cas'])) ?></td>
	<td class="cislo"><?= pocet($z['velikost'] / 1024) ?> kB</td>
	<td class="akce"><a href="<?= e($modul->url('stahni_zalohu', ['soubor' => $z['soubor']])) ?>"><?= e(t('Stáhnout')) ?></a> · <button class="navigace" type="submit" formaction="<?= e($modul->url('obnov_zalohu')) ?>" name="soubor" value="<?= e($z['soubor']) ?>" data-potvrdit="<?= e(t('Obnovit databázi z této zálohy? Všechno, co na webu přibylo po jejím vytvoření (články, komentáře, nastavení), se ztratí. Současný stav se předtím uloží do nové zálohy.')) ?>"><?= e(t('Obnovit')) ?></button> · <button class="navigace nebezpecne" type="submit" formaction="<?= e($modul->url('smaz_zalohu')) ?>" name="soubor" value="<?= e($z['soubor']) ?>" data-potvrdit="<?= e(t('Smazat zálohu?')) ?>"><?= e(t('Smazat')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<p><button class="navigace" type="submit" formaction="<?= e($modul->url('zaloha_medii')) ?>"><?= e(t('Stáhnout zálohu médií (ZIP)')) ?></button></p>
<p class="napoveda"><?= e(t('Záloha databáze obsahuje stránky, novinky, nastavení a uživatele; nahrané obrázky jsou v záloze médií. Zálohy leží ve složce storage/zalohy/, která není z webu přístupná – stahujte si je i mimo server.')) ?></p>
</fieldset>
