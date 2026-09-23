<?php
/**
 * Import z WordPressu, krok 2: náhled – co v souboru je, co se nepřevede, a volby importu. Do databáze se zatím nic nezapsalo.
 *
 * @var MiroCMS\Admin\Moduly\Prenos $modul
 * @var MiroCMS\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $stav  stav importu (Core\WpImport::novyStav)
 * @var list<string> $jazyky  jazykové verze webu, první je výchozí
 * @var list<array{idt:int, nazev:string, jazyk:string}> $rubriky
 * @var bool $presmerovaniZapnuto
 */
$p = $stav['prehled'];
$volby = $stav['volby'];
$stavy = ['publish' => 'vydané', 'future' => 'naplánované', 'draft' => 'koncepty', 'pending' => 'čekají na schválení', 'private' => 'soukromé', 'trash' => 'v koši', 'auto-draft' => 'automatické koncepty', 'inherit' => 'revize'];
$poStavech = function (array $pocty) use ($stavy): string {
    $casti = [];
    foreach ($pocty as $s => $pocet) {
        $casti[] = (int) $pocet . ' ' . t($stavy[$s] ?? 'jiné');
    }

    return implode(', ', $casti);
};
$prevede = fn (array $pocty): int => array_sum(array_intersect_key($pocty, ['publish' => 1, 'future' => 1, 'draft' => 1, 'pending' => 1]));
?>
<?= $app->view->render('admin/prenos/kroky', ['krok' => 2]) ?>
<p><?= e(t('Soubor %s – web „%s“ (%s). Zatím se nic neimportovalo; tohle je jen přehled toho, co v souboru je.', $stav['soubor'], $stav['web']['nazev'], $stav['web']['adresa'])) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) array_sum($p['clanky']) ?></strong><span><?= e(t('Příspěvky')) ?><?= $p['clanky'] !== [] ? ': ' . e($poStavech($p['clanky'])) : '' ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) array_sum($p['stranky']) ?></strong><span><?= e(t('Stránky')) ?><?= $p['stranky'] !== [] ? ': ' . e($poStavech($p['stranky'])) : '' ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['rubriky'] ?></strong><span><?= e(t('Rubriky (založí se ty, které mají články)')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['stitky'] ?></strong><span><?= e(t('Štítky')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['komentare'] ?></strong><span><?= e(t('Schválené komentáře')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['prilohy'] ?></strong><span><?= e(t('Soubory v knihovně médií')) ?> · <?= e(t('obrázků v textech: %s', (int) $p['obrazky'])) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $p['autori'] ?></strong><span><?= e(t('Autoři (přenese se jen jméno)')) ?></span></div>
</div>

<div class="hlaska hlaska-varovani">
<p><strong><?= e(t('Co se nepřevede')) ?></strong></p>
<ul>
	<li><?= e(t('Účty a hesla uživatelů – u článku zůstane jméno autora, článek bude patřit vám. E-maily a IP adresy komentujících se nepřenášejí vůbec.')) ?></li>
	<li><?= e(t('Nabídky (menu), widgety, vzhled a nastavení doplňků – navigaci a bloky si na novém webu sestavíte znovu.')) ?></li>
	<li><?= e(t('Soukromé příspěvky, koš, revize a automatické koncepty. Příspěvek chráněný heslem se převede jako koncept.')) ?></li>
<?php if ($p['jine'] !== []): ?>
	<li><?= e(t('Vlastní typy obsahu:')) ?> <?= e(implode(', ', array_map(fn (string $typ, int $pocet): string => $typ . ' (' . $pocet . ')', array_keys($p['jine']), $p['jine']))) ?></li>
<?php endif ?>
<?php if ($p['zkratky'] !== []): ?>
	<li><?= e(t('Zkratky doplňků (formuláře, stavitelé stránek…) – značka zmizí, text uvnitř zůstane:')) ?> <?= e(implode(', ', array_map(fn (string $z, int $pocet): string => '[' . $z . '] ' . $pocet . '×', array_keys($p['zkratky']), $p['zkratky']))) ?></li>
<?php endif ?>
	<li><?= e(t('Obrázky zatím zůstanou na starém webu; po importu je můžete jedním tlačítkem stáhnout k sobě.')) ?></li>
</ul>
</div>

<form class="formular" method="post" action="<?= e($modul->url('spust')) ?>">
<?= $csrf ?>
<input type="hidden" name="soubor" value="<?= e($stav['soubor']) ?>">
<fieldset>
<legend><?= e(t('Volby importu')) ?></legend>
<?php if (count($jazyky) > 1): ?>
<div class="radek"><label for="jazyk"><?= e(t('Jazyková verze')) ?></label><div><select id="jazyk" name="jazyk">
<?php foreach ($jazyky as $i => $kod): ?>
	<option value="<?= $i === 0 ? '' : e($kod) ?>"<?= ($i === 0 ? '' : $kod) === $volby['jazyk'] ? ' selected' : '' ?>><?= e(MiroCMS\Core\Jazyk::DOSTUPNE[$kod][0] ?? $kod) ?><?= $i === 0 ? ' – ' . e(t('výchozí jazyk webu')) : '' ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?= e(t('Do které jazykové verze webu nové rubriky a stránky patří.')) ?></span></div></div>
<?php endif ?>
<div class="radek"><span class="popisek"><?= e(t('Co importovat')) ?></span><div class="volby">
	<label><input type="checkbox" name="koncepty" value="1"<?= $volby['koncepty'] ? ' checked' : '' ?>> <?= e(t('koncepty a příspěvky čekající na schválení (%s)', (int) (($p['clanky']['draft'] ?? 0) + ($p['clanky']['pending'] ?? 0)))) ?></label>
	<label><input type="checkbox" name="stranky" value="1"<?= $volby['stranky'] ? ' checked' : '' ?>> <?= e(t('stránky (%s)', $prevede($p['stranky']))) ?></label>
	<label><input type="checkbox" name="komentare" value="1"<?= $volby['komentare'] ? ' checked' : '' ?>> <?= e(t('schválené komentáře (%s)', (int) $p['komentare'])) ?></label>
	<label><input type="checkbox" name="presmerovani" value="1"<?= $volby['presmerovani'] ? ' checked' : '' ?>> <?= e(t('přesměrování ze starých adres na nové')) ?></label>
</div></div>
<?php if (!$presmerovaniZapnuto): ?>
<p class="napoveda"><?= e(t('Přesměrování se zapíší, ale začnou platit, až zapnete rozšíření Přesměrování.')) ?></p>
<?php endif ?>
<div class="radek"><label for="rubrika"><?= e(t('Příspěvky bez rubriky dát do')) ?></label><div><select id="rubrika" name="rubrika">
	<option value="0"><?= e(t('nové rubriky „Nezařazené“')) ?></option>
<?php foreach ($rubriky as $r): ?>
	<option value="<?= (int) $r['idt'] ?>"<?= (int) $r['idt'] === (int) $volby['rubrika'] ? ' selected' : '' ?>><?= e($r['nazev']) ?><?= $r['jazyk'] !== '' ? ' (' . e($r['jazyk']) . ')' : '' ?></option>
<?php endforeach ?>
</select></div></div>
</fieldset>
<p class="napoveda"><?= e(t('Importované články se nerozesílají: žádné oznámení, IndexNow, Web Push ani newsletter. Před větším importem si v Nastavení → Zálohy a aktualizace vytvořte zálohu databáze.')) ?></p>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Spustit import')) ?>"> <a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět')) ?></a></p>
</form>
