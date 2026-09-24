<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Stranky $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $stranky
 * @var bool $kos     zobrazen koš
 * @var string $hledat
 * @var int $vKosi    počet stránek v koši
 */
$uvod = $app->settings()->int('titulni_stranka');
$adresa = fn (array $s): string => ($s['jazyk'] !== '' ? $s['jazyk'] . '/' : '') . ((int) $s['ids'] === $uvod ? '' : $s['seo_link']);
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová stránka')) ?></a>
	<form class="vradku" method="post" action="<?= e($modul->url('import')) ?>" enctype="multipart/form-data"><?= $csrf ?>
		<label class="navigace"><?= e(t('Import stránky (JSON)')) ?> <input type="file" name="soubor" accept="application/json,.json" data-odeslat-pri-zmene hidden></label></form></p>
<nav class="zalozky" aria-label="<?= e(t('Stránky')) ?>">
	<a href="<?= e($modul->url()) ?>"<?= $kos ? '' : ' class="aktivni" aria-current="true"' ?>><?= e(t('Všechny')) ?></a>
<?php if ($vKosi > 0 || $kos): ?>
	<a href="<?= e($modul->url('', ['stav' => 'kos'])) ?>"<?= $kos ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Koš')) ?> (<?= $vKosi ?>)</a>
<?php endif ?>
</nav>
<?php if (!$kos && ($stranky !== [] || $hledat !== '')): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="modul" value="stranky">
	<label><?= e(t('Název nebo adresa obsahuje:')) ?> <input class="textpole" type="search" name="hledat" value="<?= e($hledat) ?>" size="20"></label>
	<input class="tl" type="submit" value="<?= e(t('Hledat')) ?>">
</form>
<br>
<?php endif ?>
<?php if ($stranky === [] && $kos): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'stranky', 'nadpis' => t('Koš je prázdný.'), 'text' => t('Smazané stránky tu zůstávají 30 dní, potom se smažou natrvalo.'), 'akce' => [$modul->url(), t('Zpět na stránky')]]) ?>
<?php elseif ($stranky === [] && $hledat !== ''): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'stranky', 'nadpis' => t('Hledání neodpovídá žádná stránka.'), 'text' => t('Zkuste jiné slovo.'), 'akce' => [$modul->url(), t('Zrušit hledání')]]) ?>
<?php elseif ($stranky === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'stranky', 'nadpis' => t('Zatím žádné stránky.'), 'text' => t('Firemní web obvykle tvoří Úvod, O nás, Služby a Kontakt.'), 'akce' => [$modul->url('novy'), t('Založit první stránku')]]) ?>
<?php elseif ($kos): ?>
<p class="smltxt"><?= e(t('Stránky v koši nejsou na webu. Obnovená stránka se vrátí jako skrytá; po 30 dnech se z koše smaže natrvalo.')) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('V koši od')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($stranky as $s): ?>
<tr class="nevydany">
	<td><?= e($s['titulek']) ?></td>
	<td>/<?= e($s['seo_link']) ?></td>
	<td class="cislo"><?= e(datum($s['smazano'], true)) ?></td>
	<td class="akce">
		<form class="vradku" method="post" action="<?= e($modul->url('obnov')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace" type="submit"><?= e(t('Obnovit')) ?></button></form> ·
		<form class="vradku" method="post" action="<?= e($modul->url('smaz_natrvalo')) ?>" data-potvrdit="<?= e(t('Smazat stránku natrvalo? Nejde to vrátit.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat natrvalo')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('V navigaci')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($stranky as $s): ?>
<tr<?= $s['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td><?= !empty($s['uroven']) ? '<span class="odsazeni-stromu" style="padding-inline-start:' . ((int) $s['uroven'] - 1) * 1.2 . 'em">↳ </span>' : '' ?><a href="<?= e($modul->url('edit', ['id' => $s['ids']])) ?>"><?= e($s['titulek']) ?></a><?= (int) $s['ids'] === $uvod ? ' <span class="stitek">' . e(t('úvodní')) . '</span>' : '' ?><?= $s['stavba'] !== null || $s['stavba_koncept'] !== null ? ' <span class="stitek stitek-vydano">' . e(t('stavba')) . '</span>' : '' ?><?= $s['stavba_koncept'] !== null ? ' <span class="stitek stitek-koncept" title="' . e(t('V builderu jsou změny, které ještě nejsou na webu.')) . '">' . e(t('nepublikované změny')) . '</span>' : '' ?><?= $s['noindex'] ? ' <span class="stitek">noindex</span>' : '' ?><?= $s['zverejnit_od'] ? ' <span class="stitek stitek-koncept" title="' . e(t('Zveřejní se sama')) . '">' . e(t('od %s', datum($s['zverejnit_od'], true))) . '</span>' : '' ?></td>
	<td><a href="<?= e($app->url($adresa($s)) . ($s['zobrazit'] ? '' : '?stavba=koncept')) ?>" target="_blank" rel="noopener"<?= $s['zobrazit'] ? '' : ' title="' . e(t('Náhled skryté stránky')) . '"' ?>>/<?= e($adresa($s)) ?></a></td>
	<td><span class="stitek stitek-<?= $s['zobrazit'] ? 'vydano' : 'koncept' ?>"><?= e(t($s['zobrazit'] ? 'zveřejněná' : 'skrytá')) ?></span></td>
	<td><?= e(t($s['v_menu'] ? 'Ano' : 'Ne')) ?></td>
	<td class="akce"><a href="<?= e($modul->url('stavitel', ['id' => $s['ids']])) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($modul->url('edit', ['id' => $s['ids']])) ?>"><?= e(t('Nastavení')) ?></a> ·
		<a href="<?= e($modul->url('novy', ['nadrazena' => $s['ids']])) ?>" title="<?= e(t('Nová stránka pod touto')) ?>"><?= e(t('Podstránka')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($modul->url('duplikuj')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace" type="submit"><?= e(t('Duplikovat')) ?></button></form>
<?php if ((int) $s['ids'] !== $uvod): ?> ·
		<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Přesunout stránku do koše? Z webu zmizí, obnovit ji můžete 30 dní.')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><input type="hidden" name="titulek" value="<?= e($s['titulek']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
