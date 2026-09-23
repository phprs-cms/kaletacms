<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Clanky $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $clanky
 * @var int $celkem
 * @var int $strana
 * @var int $stran
 * @var list<array<string, mixed>> $rubriky
 * @var array{tema:int, jazyk:string, hledat:string, moje:string, stav:string} $filtr
 * @var list<string> $jazykyWebu  jazykové verze webu (prázdné = web má jen jeden jazyk)
 * @var bool $smiVydavat
 * @var int $vKosi  počet článků v koši (v rozsahu přihlášeného)
 */
$kos = $filtr['stav'] === 'kos';
$strankaUrl = fn (int $s): string => $modul->url('', array_filter($filtr) + ['strana' => $s]);
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nový článek')) ?></a> <a class="navigace" href="<?= e($modul->url('kalendar')) ?>"><?= e(t('Redakční kalendář')) ?></a>
	<a class="navigace" href="<?= e($modul->url('odkazy')) ?>"><?= e(t('Nefunkční odkazy')) ?></a>
<?php if ($modul->app()->auth()->smiVydavat()): ?>
	<a class="navigace" href="<?= e($modul->url('titulni')) ?>"><?= e(t('Titulní strana')) ?></a>
<?php endif ?></p>

<nav class="zalozky" aria-label="<?= e(t('Stav článků')) ?>">
<?php foreach (['' => 'Všechny', 'vydane' => 'Vydané', 'plan' => 'Naplánované', 'koncepty' => 'Koncepty', 'korektura' => 'Ke korektuře', 'schvaleno' => 'Schválené'] as $klic => $nazev): ?>
	<a href="<?= e($modul->url('', array_filter(['stav' => $klic]))) ?>"<?= $filtr['stav'] === $klic ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($nazev)) ?></a>
<?php endforeach ?>
<?php if ($vKosi > 0 || $kos): ?>
	<a href="<?= e($modul->url('', ['stav' => 'kos'])) ?>"<?= $kos ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('Koš')) ?> (<?= $vKosi ?>)</a>
<?php endif ?>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="modul" value="clanky">
	<input type="hidden" name="stav" value="<?= e($filtr['stav']) ?>">
	<label><?= e(t('Rubrika:')) ?>
		<select name="tema">
			<option value="0"><?= e(t('všechny')) ?></option>
<?php foreach ($rubriky as $r): ?>
			<option value="<?= (int) $r['idt'] ?>"<?= $filtr['tema'] === (int) $r['idt'] ? ' selected' : '' ?>><?= str_repeat('&nbsp;&nbsp;', $r['uroven']) . e($r['nazev']) ?></option>
<?php endforeach ?>
		</select>
	</label>
<?php if ($jazykyWebu !== []): ?>
	<label><?= e(t('Jazyk:')) ?>
		<select name="jazyk">
			<option value=""><?= e(t('všechny')) ?></option>
<?php foreach ($jazykyWebu as $kod): ?>
			<option value="<?= e($kod) ?>"<?= $filtr['jazyk'] === $kod ? ' selected' : '' ?>><?= e(\MiroCMS\Core\Jazyk::DOSTUPNE[$kod][0]) ?></option>
<?php endforeach ?>
		</select>
	</label>
<?php endif ?>
	<label><?= e(t('Titulek obsahuje:')) ?> <input class="textpole" type="search" name="hledat" value="<?= e($filtr['hledat']) ?>" size="20"></label>
	<label><input type="checkbox" name="moje" value="1"<?= $filtr['moje'] === '1' ? ' checked' : '' ?>> <?= e(t('Zobrazit pouze mé články')) ?></label>
	<input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>">
	(<?= e(t('Celkový počet článků:')) ?> <?= $celkem ?>)
</form>
<br>

<?php if ($clanky === [] && $kos): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'clanek', 'nadpis' => t('Koš je prázdný.'), 'text' => t('Smazané články tu zůstávají 30 dní, potom se smažou natrvalo.'), 'akce' => [$modul->url(), t('Zpět na články')]]) ?>
<?php elseif ($clanky === []): ?>
<?php if (array_filter($filtr) !== []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'clanek', 'nadpis' => t('Filtru neodpovídá žádný článek.'), 'text' => t('Zkuste jiné slovo, rubriku nebo stav.'), 'akce' => [$modul->url(), t('Zrušit filtr')]]) ?>
<?php else: ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'clanek', 'nadpis' => t('Zatím tu není žádný článek.'), 'text' => t('Článek napíšete v editoru; než ho vydáte, zůstává konceptem, který vidí jen redakce.'), 'akce' => [$modul->url('novy'), t('Napsat první článek')]]) ?>
<?php endif ?>
<?php elseif ($kos): ?>
<p class="smltxt"><?= e(t('Články v koši nejsou na webu ani ve výpisech. Obnovený článek se vrátí jako koncept; po 30 dnech se článek z koše smaže natrvalo.')) ?></p>
<form method="post" id="obnov-jeden" action="<?= e($modul->url('obnov')) ?>"><?= $csrf ?></form>
<form method="post" action="<?= e($modul->url('obnov')) ?>">
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis">
<thead>
<tr><th scope="col"><?= e(t('Titulek')) ?></th><th scope="col"><?= e(t('Rubrika')) ?></th><th scope="col"><?= e(t('Smazal')) ?></th><th scope="col"><?= e(t('V koši od')) ?></th><th scope="col"><?= e(t('Akce')) ?></th><th scope="col" class="stred"><?= e(t('Označit')) ?></th></tr>
</thead>
<tbody>
<?php foreach ($clanky as $c): ?>
<tr class="nevydany">
	<td><?= e($c['titulek']) ?><?= $c['smazano_vydany'] ? ' <span class="stitek">' . e(t('byl vydaný')) . '</span>' : '' ?></td>
	<td><?= e($c['tema_jm']) ?></td>
	<td><?= e((string) $c['smazal_jm']) ?></td>
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
	<button class="navigace nebezpecne" type="submit" formaction="<?= e($modul->url('smaz_natrvalo')) ?>" data-potvrdit="<?= e(t('Smazat označené články natrvalo? Nejde to vrátit.')) ?>"><?= e(t('Smazat natrvalo')) ?></button>
</p>
</form>
<?php else: ?>
<form method="post" id="vydat" action="<?= e($modul->url('vydat')) ?>"><?= $csrf ?></form>
<form method="post" action="<?= e($modul->url('hromadne')) ?>">
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis">
<thead>
<tr><th scope="col"><?= e(t('Titulek')) ?></th><th scope="col"><?= e(t('Rubrika')) ?></th><th scope="col"><?= e(t('Autor')) ?></th><th scope="col"><?= e(t('Datum vydání')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Čteno')) ?></th><th scope="col"><?= e(t('Akce')) ?></th><th scope="col"><?= e(t('Označit')) ?></th></tr>
</thead>
<tbody>
<?php foreach ($clanky as $c): ?>
<tr<?= $c['visible'] ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($modul->url('edit', ['id' => $c['idc']])) ?>"><?= e($c['titulek']) ?></a><?= $c['priority'] > 0 ? ' <span class="stitek">' . e(t('připnuto')) . '</span>' : '' ?></td>
	<td><?= e($c['tema_jm']) ?></td>
	<td><?= e($c['autor_jm'] ?: $c['autor_login']) ?></td>
	<td class="cislo"><?= e(datum($c['datum'], true)) ?></td>
	<td><span class="stitek stitek-<?= !$c['visible'] ? 'koncept' : (strtotime($c['datum']) > time() ? 'plan' : 'vydano') ?>"><?= e(t(!$c['visible'] ? (['korektura' => 'ke korektuře', 'schvaleno' => 'schváleno'][$c['stav_redakce']] ?? 'koncept') : (strtotime($c['datum']) > time() ? 'naplánováno' : 'vydáno'))) ?></span></td>
	<td class="cislo"><?= (int) $c['visit'] ?>x</td>
	<td class="akce"><a href="<?= e($modul->url('edit', ['id' => $c['idc']])) ?>"><?= e(t('Upravit')) ?></a><?php if (!$c['visible'] && $smiVydavat): ?> · <button class="navigace" type="submit" form="vydat" name="idc" value="<?= (int) $c['idc'] ?>"><?= e(t('Vydat')) ?></button><?php endif ?> · <a href="<?= e($app->url('clanek/' . $c['seo_link'] . '?nahled=1')) ?>" target="_blank" rel="noopener"><?= e(t('Náhled')) ?></a></td>
	<td class="stred"><input type="checkbox" name="smaz[]" value="<?= (int) $c['idc'] ?>" aria-label="<?= e(t('Označit')) ?>: <?= e($c['titulek']) ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-hromadne">
	<?= e(t('S označenými:')) ?>
	<select name="provest" aria-label="<?= e(t('Hromadná akce')) ?>" data-hromadne>
		<option value=""><?= e(t('– vyberte akci –')) ?></option>
		<option value="rubrika"><?= e(t('přesunout do rubriky…')) ?></option>
		<option value="stitek"><?= e(t('přidat štítek…')) ?></option>
<?php if ($ctenari): ?>
		<option value="zamknout"><?= e(t('zamknout pro přihlášené čtenáře')) ?></option>
		<option value="odemknout"><?= e(t('odemknout pro všechny')) ?></option>
<?php endif ?>
	</select>
	<select name="do_rubriky" aria-label="<?= e(t('Rubrika')) ?>" data-pro-akci="rubrika" hidden>
<?php foreach ($rubriky as $r): ?>
		<option value="<?= (int) $r['idt'] ?>"><?= e(str_repeat('– ', (int) $r['uroven']) . $r['nazev']) ?></option>
<?php endforeach ?>
	</select>
	<input class="textpole" type="text" name="stitek" maxlength="80" placeholder="<?= e(t('štítek')) ?>" aria-label="<?= e(t('Štítek')) ?>" data-pro-akci="stitek" hidden>
	<input class="tl" type="submit" value="<?= e(t('Provést')) ?>">
	<button class="navigace nebezpecne" type="submit" formaction="<?= e($modul->url('smaz')) ?>"><?= e(t('Smazat označené')) ?></button>
</p>
</form>

<?php if ($stran > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $stran; $s++): ?>
	<?= $s === $strana ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($strankaUrl($s)) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
