<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Novinky $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $novinky
 * @var int $celkem
 * @var int $strana
 * @var int $stran
 * @var list<array<string, mixed>> $kategorie
 * @var array{tema:int, jazyk:string, hledat:string, stav:string} $filtr
 * @var list<string> $jazykyWebu  jazykové verze webu (prázdné = web má jen jeden jazyk)
 * @var int $vKosi  počet novinek v koši (v rozsahu přihlášeného)
 * @var int $keVydani  koncepty autorů, které čekají na vydání (vidí editor a správce)
 */
$kos = $filtr['stav'] === 'kos';
$strankaUrl = fn (int $s): string => $modul->url('', array_filter($filtr) + ['strana' => $s]);
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová novinka')) ?></a>
<?php if ($app->auth()->maModul('kategorie')): ?>
	<a class="navigace" href="<?= e($app->url('admin.php?modul=kategorie')) ?>"><?= e(t('Kategorie')) ?></a>
<?php endif ?>
<?php if ($app->auth()->maModul('stitky')): ?>
	<a class="navigace" href="<?= e($app->url('admin.php?modul=stitky')) ?>"><?= e(t('Štítky')) ?></a>
<?php endif ?>
	<a class="navigace" href="<?= e($modul->url('odkazy')) ?>"><?= e(t('Nefunkční odkazy')) ?></a></p>

<nav class="zalozky" aria-label="<?= e(t('Stav novinek')) ?>">
<?php foreach (['' => 'Všechny', 'vydane' => 'Vydané', 'plan' => 'Naplánované', 'koncepty' => 'Koncepty'] as $klic => $nazev): ?>
	<a href="<?= e($modul->url('', array_filter(['stav' => $klic]))) ?>"<?= $filtr['stav'] === $klic ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($nazev)) ?></a>
<?php endforeach ?>
<?php if ($keVydani > 0 || $filtr['stav'] === 'ke_vydani'): ?>
	<a href="<?= e($modul->url('', ['stav' => 'ke_vydani'])) ?>"<?= $filtr['stav'] === 'ke_vydani' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Čekají na vydání')) ?> (<?= $keVydani ?>)</a>
<?php endif ?>
<?php if ($vKosi > 0 || $kos): ?>
	<a href="<?= e($modul->url('', ['stav' => 'kos'])) ?>"<?= $kos ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Koš')) ?> (<?= $vKosi ?>)</a>
<?php endif ?>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="modul" value="novinky">
	<input type="hidden" name="stav" value="<?= e($filtr['stav']) ?>">
<?php if (count($kategorie) > 1): ?>
	<label><?= e(t('Kategorie:')) ?>
		<select name="tema">
			<option value="0"><?= e(t('všechny')) ?></option>
<?php foreach ($kategorie as $k): ?>
			<option value="<?= (int) $k['idt'] ?>"<?= $filtr['tema'] === (int) $k['idt'] ? ' selected' : '' ?>><?= e($k['nazev']) ?></option>
<?php endforeach ?>
		</select>
	</label>
<?php endif ?>
<?php if ($jazykyWebu !== []): ?>
	<label><?= e(t('Jazyk:')) ?>
		<select name="jazyk">
			<option value=""><?= e(t('všechny')) ?></option>
<?php foreach ($jazykyWebu as $kod): ?>
			<option value="<?= e($kod) ?>"<?= $filtr['jazyk'] === $kod ? ' selected' : '' ?>><?= e(\Kaleta\Core\Jazyk::DOSTUPNE[$kod][0]) ?></option>
<?php endforeach ?>
		</select>
	</label>
<?php endif ?>
	<label><?= e(t('Titulek obsahuje:')) ?> <input class="textpole" type="search" name="hledat" value="<?= e($filtr['hledat']) ?>" size="20"></label>
	<input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>">
	(<?= e(t('Celkem:')) ?> <?= $celkem ?>)
</form>
<br>

<?php if ($novinky === [] && $kos): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'clanek', 'nadpis' => t('Koš je prázdný.'), 'text' => t('Smazané novinky tu zůstávají 30 dní, potom se smažou natrvalo.'), 'akce' => [$modul->url(), t('Zpět na novinky')]]) ?>
<?php elseif ($novinky === []): ?>
<?php if (array_filter($filtr) !== []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'clanek', 'nadpis' => t('Filtru neodpovídá žádná novinka.'), 'text' => t('Zkuste jiné slovo, kategorii nebo stav.'), 'akce' => [$modul->url(), t('Zrušit filtr')]]) ?>
<?php else: ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'clanek', 'nadpis' => t('Zatím tu není žádná novinka.'), 'text' => t('Než novinku vydáte, zůstává konceptem, který na webu nikdo nevidí.'), 'akce' => [$modul->url('novy'), t('Napsat první novinku')]]) ?>
<?php endif ?>
<?php elseif ($kos): ?>
<p class="smltxt"><?= e(t('Novinky v koši nejsou na webu. Obnovená novinka se vrátí jako koncept; po 30 dnech se z koše smaže natrvalo.')) ?></p>
<form method="post" id="obnov-jeden" action="<?= e($modul->url('obnov')) ?>"><?= $csrf ?></form>
<form method="post" action="<?= e($modul->url('obnov')) ?>">
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis">
<thead>
<tr><th scope="col"><?= e(t('Titulek')) ?></th><th scope="col"><?= e(t('Kategorie')) ?></th><th scope="col"><?= e(t('V koši od')) ?></th><th scope="col"><?= e(t('Akce')) ?></th><th scope="col" class="stred"><?= e(t('Označit')) ?></th></tr>
</thead>
<tbody>
<?php foreach ($novinky as $c): ?>
<tr class="nevydany">
	<td><?= e($c['titulek']) ?></td>
	<td><?= e($c['tema_jm']) ?></td>
	<td class="cislo"><?= e(datum($c['smazano'], true)) ?></td>
	<td class="akce"><button class="navigace" type="submit" form="obnov-jeden" name="smaz[]" value="<?= (int) $c['idc'] ?>"><?= e(t('Obnovit')) ?></button></td>
	<td class="stred"><input type="checkbox" name="smaz[]" value="<?= (int) $c['idc'] ?>" aria-label="<?= e(t('Označit')) ?>: <?= e($c['titulek']) ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-hromadne">
	<?= e(t('S označenými:')) ?>
	<input class="tl" type="submit" value="<?= e(t('Obnovit')) ?>">
<?php if ($app->auth()->smiVydavat()): ?>
	<button class="navigace nebezpecne" type="submit" formaction="<?= e($modul->url('smaz_natrvalo')) ?>" data-potvrdit="<?= e(t('Smazat označené novinky natrvalo? Nejde to vrátit.')) ?>"><?= e(t('Smazat natrvalo')) ?></button>
<?php endif ?>
</p>
</form>
<?php else: ?>
<form method="post" action="<?= e($modul->url('smaz')) ?>">
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis">
<thead>
<tr><th scope="col"><?= e(t('Titulek')) ?></th><th scope="col"><?= e(t('Kategorie')) ?></th><th scope="col"><?= e(t('Autor')) ?></th><th scope="col"><?= e(t('Datum vydání')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Akce')) ?></th><th scope="col"><?= e(t('Označit')) ?></th></tr>
</thead>
<tbody>
<?php foreach ($novinky as $c): ?>
<tr<?= $c['visible'] ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($modul->url('edit', ['id' => $c['idc']])) ?>"><?= e($c['titulek']) ?></a></td>
	<td><?= e($c['tema_jm']) ?></td>
	<td><?= e($c['autor_jm'] ?: $c['autor_login']) ?></td>
	<td class="cislo"><?= e(datum($c['datum'], true)) ?></td>
<?php if (!$c['visible'] && (int) $c['autor_uroven'] === 0 && $app->auth()->smiVydavat()): // koncept autora: čeká, až ho editor vydá ?>
	<td><span class="stitek stitek-ceka" title="<?= e(t('Autor novinek sám nevydává – novinku zkontrolujte a vydejte.')) ?>"><?= e(t('čeká na vydání')) ?></span></td>
<?php else: ?>
	<td><span class="stitek stitek-<?= !$c['visible'] ? 'koncept' : (strtotime($c['datum']) > time() ? 'plan' : 'vydano') ?>"><?= e(t(!$c['visible'] ? 'koncept' : (strtotime($c['datum']) > time() ? 'naplánováno' : 'vydáno'))) ?></span></td>
<?php endif ?>
	<td class="akce"><a href="<?= e($modul->url('edit', ['id' => $c['idc']])) ?>"><?= e(t('Upravit')) ?></a> · <a href="<?= e($app->url('novinky/' . $c['seo_link'] . '?nahled=1')) ?>" target="_blank" rel="noopener"><?= e(t('Náhled')) ?></a> ·
		<button class="navigace" type="submit" formaction="<?= e($modul->url('duplikuj')) ?>" name="idc" value="<?= (int) $c['idc'] ?>" formnovalidate><?= e(t('Duplikovat')) ?></button></td>
	<td class="stred"><input type="checkbox" name="smaz[]" value="<?= (int) $c['idc'] ?>" aria-label="<?= e(t('Označit')) ?>: <?= e($c['titulek']) ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-hromadne"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat označené')) ?></button></p>
</form>

<?php if ($stran > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $stran; $s++): ?>
	<?= $s === $strana ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($strankaUrl($s)) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
