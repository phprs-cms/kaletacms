<?php
/**
 * Poptávky z formulářů webu.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Poptavky $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $poptavky
 * @var int $celkem
 * @var string $filtr
 * @var int $strana
 * @var int $naStranu
 * @var int $mesice
 * @var string $hledat
 * @var array<int, string> $uzivatele
 */
use Kaleta\Admin\Moduly\Poptavky;

$stran = (int) ceil($celkem / $naStranu);
$nahled = function (string $data): string {
    $polozky = json_decode($data, true) ?: [];
    $text = implode(' · ', array_filter(array_map(fn (array $d): string => (string) $d[1], $polozky), fn (string $v): bool => $v !== '' && mb_strlen($v) > 1));

    return mb_strimwidth($text, 0, 140, '…');
};
?>
<nav class="zalozky" aria-label="<?= e(t('Stav poptávek')) ?>">
<?php foreach (['' => 'Všechny', 'otevrene' => 'K vyřízení', 'moje' => 'Moje', 'vyrizene' => 'Vyřízené'] as $klic => $nazev): ?>
	<a href="<?= e($modul->url('', array_filter(['stav' => $klic]))) ?>"<?= $filtr === $klic ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($nazev)) ?></a>
<?php endforeach ?>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="modul" value="poptavky"><input type="hidden" name="stav" value="<?= e($filtr) ?>">
	<label><?= e(t('Hledat (jméno, e-mail, text):')) ?> <input class="textpole" type="search" name="hledat" value="<?= e($hledat) ?>" size="24"></label>
	<input class="tl" type="submit" value="<?= e(t('Hledat')) ?>">
</form>
<br>
<?php if ($poptavky === [] && ($hledat !== '' || $filtr !== '')): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'poptavky', 'nadpis' => t('Filtru neodpovídá žádná poptávka.'), 'text' => t('Zkuste jiné slovo nebo stav.'), 'akce' => [$modul->url(), t('Zrušit filtr')]]) ?>
<?php elseif ($poptavky === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'poptavky', 'nadpis' => t('Zatím žádné poptávky.'), 'text' => t('Přidejte na web prvek Formulář v builderu stránek (nebo hotovou sekci Poptávkový formulář) – odeslané zprávy se objeví tady a přijdou i e-mailem.'), 'akce' => [$app->url('admin.php?modul=stranky'), t('Otevřít stránky')]]) ?>
<?php else: ?>
<form method="post" action="<?= e($modul->url('hromadne')) ?>">
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Datum')) ?></th><th scope="col"><?= e(t('Formulář')) ?></th><th scope="col"><?= e(t('Obsah')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col" class="stred"><?= e(t('Označit')) ?></th></tr></thead>
<tbody>
<?php foreach ($poptavky as $p): ?>
<tr<?= (int) $p['stav'] === 2 ? ' class="nevydany"' : '' ?>>
	<td><a href="<?= e($modul->url('detail', ['id' => $p['idp']])) ?>"><?= (int) $p['stav'] === 0 ? '<strong>' . e(datum($p['datum'], true)) . '</strong>' : e(datum($p['datum'], true)) ?></a></td>
	<td><?= e($p['formular']) ?><?= $p['email'] !== '' ? '<br><small>' . e($p['email']) . '</small>' : '' ?></td>
	<td><a href="<?= e($modul->url('detail', ['id' => $p['idp']])) ?>"><?= e($nahled((string) $p['data'])) ?></a></td>
	<td><span class="stitek<?= (int) $p['stav'] === 0 ? ' stitek-koncept' : ((int) $p['stav'] === 2 ? ' stitek-vydano' : '') ?>"><?= e(t(Poptavky::STAVY[(int) $p['stav']])) ?></span><?= $p['prirazeno'] && isset($uzivatele[(int) $p['prirazeno']]) ? '<br><small>' . e($uzivatele[(int) $p['prirazeno']]) . '</small>' : '' ?></td>
	<td class="stred"><input type="checkbox" name="oznacene[]" value="<?= (int) $p['idp'] ?>" aria-label="<?= e(t('Označit')) ?>: #<?= (int) $p['idp'] ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-hromadne"><?= e(t('S označenými:')) ?>
	<button class="tl" type="submit" name="provest" value="vyridit"><?= e(t('Označit jako vyřízené')) ?></button>
	<button class="navigace nebezpecne" type="submit" name="provest" value="smazat" data-potvrdit="<?= e(t('Smazat označené poptávky i s přílohami? Nejde to vrátit.')) ?>"><?= e(t('Smazat')) ?></button></p>
</form>
<?php if ($stran > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $stran; $s++): ?>
	<?= $s === $strana ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($modul->url('', array_filter(['stav' => $filtr]) + ['strana' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url('csv')) ?>"><?= e(t('Stáhnout všechny poptávky (CSV)')) ?></a></p>
<?php endif ?>
<?php if ($app->auth()->isAdmin()): ?>
<form class="formular" method="post" action="<?= e($modul->url('nastaveni')) ?>" data-potvrdit="<?= e(t('Poptávky starší než zadaný počet měsíců se hned natrvalo smažou – i s přílohami. Opravdu uložit?')) ?>">
<?= $csrf ?>
<div class="radek"><label for="mesice"><?= e(t('Mazat poptávky starší než')) ?></label><div><input class="textpole" type="number" id="mesice" name="mesice" value="<?= $mesice ?>" min="0" max="120" size="4"> <?= e(t('měsíců')) ?> <input class="tl" type="submit" value="<?= e(t('Uložit')) ?>">
<span class="napoveda"><?= e(t('Poptávky obsahují osobní údaje – nemají ležet déle, než je potřeba. 0 = nemazat.')) ?></span></div></div>
</form>
<?php endif ?>
