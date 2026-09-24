<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\ProtokolZmen $modul
 * @var list<array<string, mixed>> $zaznamy
 * @var array<int, string> $uzivatele
 * @var int $kdo
 */
$nazvy = ['stranky' => 'Stránky', 'novinky' => 'Novinky', 'kategorie' => 'Kategorie', 'stitky' => 'Štítky', 'intergal' => 'Média', 'vzhled' => 'Vzhled', 'asistent' => 'AI asistent', 'mcp' => 'Claude (MCP)', 'users' => 'Uživatelé', 'presmerovani' => 'Přesměrování', 'config' => 'Nastavení', 'prihlaseni' => 'Přihlášení', 'ucet' => 'Můj účet'];
$akce = ['uloz' => 'uložení', 'smaz' => 'smazání', 'vydat' => 'vydání', 'hromadne' => 'hromadná akce', 'nahraj' => 'nahrání', 'login' => 'přihlášení', 'neuspech' => 'neúspěšný pokus',
    'rozvrzeni' => 'změna rozvržení', 'zalohuj' => 'záloha', 'aktualizuj' => 'aktualizace systému', 'slozka' => 'složka', 'ads_txt' => 'ads.txt'];
?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="modul" value="protokol">
	<label><?= e(t('Uživatel:')) ?> <select name="kdo" data-odeslat-pri-zmene><option value="0"><?= e(t('všichni')) ?></option>
<?php foreach ($uzivatele as $idu => $jmeno): ?>
		<option value="<?= (int) $idu ?>"<?= $kdo === (int) $idu ? ' selected' : '' ?>><?= e($jmeno) ?></option>
<?php endforeach ?>
	</select></label>
</form>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Kdy')) ?></th><th scope="col"><?= e(t('Kdo')) ?></th><th scope="col"><?= e(t('Kde')) ?></th><th scope="col"><?= e(t('Co')) ?></th><th scope="col"><?= e(t('Podrobnost')) ?></th></tr></thead>
<tbody>
<?php foreach ($zaznamy as $z): ?>
<tr<?= $z['akce'] === 'neuspech' ? ' class="nevydany"' : '' ?>>
	<td class="cislo"><?= e(datum($z['cas'], true)) ?></td>
	<td><?= e($z['jmeno'] !== '' ? $z['jmeno'] : '–') ?></td>
	<td><?= e(isset($nazvy[$z['modul']]) ? t($nazvy[$z['modul']]) : $z['modul']) ?></td>
	<td><?= e(t($akce[$z['akce']] ?? $z['akce'])) ?></td>
	<td><?= e($z['popis']) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="smltxt"><?= e(t('Posledních 300 záznamů. Protokol se uchovává půl roku.')) ?></p>
