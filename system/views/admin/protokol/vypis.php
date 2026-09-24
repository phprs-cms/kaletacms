<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\ProtokolZmen $modul
 * @var list<array<string, mixed>> $zaznamy
 * @var array<int, string> $uzivatele
 * @var int $kdo
 * @var string $kde
 * @var string $hledat
 * @var list<string> $moduly
 * @var int $strana
 * @var int $stran
 * @var int $celkem
 */
// názvy modulů z administrace (i těch, které přibudou) a několik míst mimo moduly
$nazvy = array_map(fn (string $class): string => $class::NAZEV, array_combine(array_map(fn (string $class): string => $class::IDENT, Kaleta\Admin\Kernel::MODULY), Kaleta\Admin\Kernel::MODULY))
    + ['asistent' => 'AI asistent', 'mcp' => 'Claude (MCP)', 'prihlaseni' => 'Přihlášení', 'ucet' => 'Můj účet'];
$akce = ['uloz' => 'uložení', 'smaz' => 'smazání', 'smaz_natrvalo' => 'smazání natrvalo', 'obnov' => 'obnovení z koše', 'duplikuj' => 'kopie',
    'vydat' => 'vydání', 'hromadne' => 'hromadná akce', 'nahraj' => 'nahrání', 'login' => 'přihlášení', 'neuspech' => 'neúspěšný pokus',
    'zalohuj' => 'záloha', 'aktualizuj' => 'aktualizace systému', 'slozka' => 'složka', 'automaticky' => 'automatické menu',
    'uloz_variantu' => 'uložení varianty', 'sablona' => 'návrat na šablonu', 'stav' => 'změna stavu', 'import' => 'import', 'stavba_text' => 'návrat k textu'];
?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="modul" value="protokol">
	<label><?= e(t('Uživatel:')) ?> <select name="kdo" data-odeslat-pri-zmene><option value="0"><?= e(t('všichni')) ?></option>
<?php foreach ($uzivatele as $idu => $jmeno): ?>
		<option value="<?= (int) $idu ?>"<?= $kdo === (int) $idu ? ' selected' : '' ?>><?= e($jmeno) ?></option>
<?php endforeach ?>
	</select></label>
	<label><?= e(t('Kde:')) ?> <select name="kde" data-odeslat-pri-zmene><option value=""><?= e(t('všude')) ?></option>
<?php foreach ($moduly as $m): ?>
		<option value="<?= e($m) ?>"<?= $kde === $m ? ' selected' : '' ?>><?= e(isset($nazvy[$m]) ? t($nazvy[$m]) : $m) ?></option>
<?php endforeach ?>
	</select></label>
	<label><?= e(t('Podrobnost obsahuje:')) ?> <input class="textpole" type="search" name="hledat" value="<?= e($hledat) ?>" size="18"></label>
	<input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>"> (<?= e(t('Celkem:')) ?> <?= $celkem ?>)
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
<?php if ($stran > 1): ?>
<p class="strankovani">
<?php for ($s = max(1, $strana - 5); $s <= min($stran, $strana + 5); $s++): ?>
	<?= $s === $strana ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($modul->url('', array_filter(['kdo' => $kdo ?: null, 'kde' => $kde, 'hledat' => $hledat, 'strana' => $s]))) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<p class="smltxt"><?= e(t('Protokol se uchovává půl roku.')) ?></p>
