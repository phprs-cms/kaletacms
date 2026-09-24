<?php
/**
 * Import a export: krok 1 importu z WordPressu (soubor) a export celého webu.
 *
 * @var Kaleta\Admin\Moduly\Prenos $modul
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array{soubor:string, velikost:int, cas:int, stav:array<string,mixed>|null}> $soubory  exporty z WordPressu ve storage/import/
 * @var int $limitNahrani  kolik bajtů server dovolí nahrát formulářem
 * @var bool $chybiXml  na serveru chybí rozšíření pro čtení XML
 * @var list<array{soubor:string, velikost:int, cas:int}> $exporty
 * @var bool $umiZip
 */
$faze = [
    'analyza' => 'čte se', 'nahled' => 'připraven k importu', 'import' => 'import běží', 'hotovo' => 'obsah převeden',
    'obrazky' => 'stahují se obrázky', 'obrazky-hotovo' => 'převeden včetně obrázků',
];
?>
<h3><?= e(t('Import z WordPressu')) ?></h3>
<?= $app->view->render('admin/prenos/kroky', ['krok' => 1]) ?>
<p><?= e(t('Ve WordPressu otevřete Nástroje → Export, zvolte „Veškerý obsah“ a stáhněte soubor .xml. Ten pak nahrajte sem. Převedou se stránky, příspěvky (jako novinky), kategorie a štítky a vzniknou přesměrování ze starých adres; na webu se nic nezmění, dokud import v dalším kroku nepotvrdíte.')) ?></p>
<?php if ($chybiXml): ?>
<p class="hlaska hlaska-chyba"><?= e(t('Na serveru chybí rozšíření PHP xmlreader nebo dom – bez nich nejde export z WordPressu přečíst.')) ?></p>
<?php else: ?>
<form class="formular" method="post" enctype="multipart/form-data" action="<?= e($modul->url('nahraj')) ?>">
<?= $csrf ?>
<div class="radek"><label for="soubor"><?= e(t('Export z WordPressu')) ?></label><div><input type="file" id="soubor" name="soubor" accept=".xml,text/xml,application/xml" required>
	<span class="napoveda"><?= e(t('Server dovolí nahrát nejvýš %s. Větší soubor zkopírujte přes FTP do složky storage/import/ – objeví se v seznamu níže.', Kaleta\Core\Soubory::velikost($limitNahrani))) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Nahrát a zobrazit náhled')) ?>"></p>
</form>
<?php endif ?>

<?php if ($soubory !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Soubor')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Nahráno')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($soubory as $s): $stav = $s['stav']; ?>
<tr>
	<td><?= e($s['soubor']) ?></td>
	<td class="cislo"><?= e(Kaleta\Core\Soubory::velikost($s['velikost'])) ?></td>
	<td class="cislo"><?= e(datum(date('Y-m-d H:i:s', $s['cas']), true)) ?></td>
	<td><?= $stav === null ? '–' : e(t($faze[$stav['faze']] ?? '–')) . ($stav['faze'] === 'import' ? ' (' . (int) $stav['pozice'] . ' / ' . (int) $stav['celkem'] . ')' : '') ?></td>
	<td class="akce">
<?php if ($stav !== null): ?>
		<a href="<?= e($modul->url($stav['faze'] === 'nahled' ? 'nahled' : 'prubeh', ['soubor' => $s['soubor']])) ?>"><?= e(t(in_array($stav['faze'], ['hotovo', 'obrazky-hotovo'], true) ? 'Výsledek' : 'Pokračovat')) ?></a>
<?php endif ?>
<?php if (!$chybiXml): ?>
		<form class="vradku" method="post" action="<?= e($modul->url('vyber')) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace" type="submit"><?= e(t($stav === null ? 'Zobrazit náhled' : 'Načíst znovu')) ?></button></form>
<?php endif ?>
		<form class="vradku" method="post" action="<?= e($modul->url('smaz_soubor')) ?>" data-potvrdit="<?= e(t('Smazat soubor %s? Už převedený obsah na webu zůstane.', $s['soubor'])) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="smltxt"><?= e(t('Stejný soubor můžete importovat opakovaně – co už bylo převedeno, se přeskočí. Po dokončení importu soubor smažte, obsahuje e-maily autorů a komentujících ze starého webu.')) ?></p>
<?php endif ?>

<h3><?= e(t('Export celého webu')) ?></h3>
<p><?= e(t('Jedním archivem dostanete všechen obsah v otevřeném formátu: stránky, novinky, kategorie, štítky, přesměrování a nahrané soubory. Hesla, klíče ani účty v něm nejsou.')) ?></p>
<?php if (!$umiZip): ?>
<p class="hlaska"><?= e(t('Na serveru chybí rozšíření PHP zip, export proto obsahuje jen data (JSON). Složku media/ si stáhněte přes FTP.')) ?></p>
<?php endif ?>
<form method="post" action="<?= e($modul->url('export')) ?>"><?= $csrf ?><p><button class="tl" type="submit"><?= e(t('Vytvořit export')) ?></button></p></form>
<?php if ($exporty !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Soubor')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Vytvořeno')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($exporty as $x): ?>
<tr>
	<td><?= e($x['soubor']) ?></td>
	<td class="cislo"><?= e(Kaleta\Core\Soubory::velikost($x['velikost'])) ?></td>
	<td class="cislo"><?= e(datum(date('Y-m-d H:i:s', $x['cas']), true)) ?></td>
	<td class="akce"><a href="<?= e($modul->url('stahni', ['soubor' => $x['soubor']])) ?>"><?= e(t('Stáhnout')) ?></a>
		<form class="vradku" method="post" action="<?= e($modul->url('smaz_export')) ?>" data-potvrdit="<?= e(t('Smazat export %s?', $x['soubor'])) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($x['soubor']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="smltxt"><?= e(t('Uchovávají se poslední tři exporty. Pro obnovu tohoto webu slouží záloha databáze v Nastavení → Zálohy a aktualizace.')) ?></p>
<?php endif ?>
