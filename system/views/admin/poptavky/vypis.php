<?php
/**
 * Poptávky z formulářů webu.
 *
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Poptavky $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $poptavky
 * @var int $celkem
 * @var string $filtr
 * @var int $strana
 * @var int $naStranu
 * @var int $mesice
 */
use MiroCMS\Admin\Moduly\Poptavky;

$stran = (int) ceil($celkem / $naStranu);
$nahled = function (string $data): string {
    $polozky = json_decode($data, true) ?: [];
    $text = implode(' · ', array_filter(array_map(fn (array $d): string => (string) $d[1], $polozky), fn (string $v): bool => $v !== '' && mb_strlen($v) > 1));

    return mb_strimwidth($text, 0, 140, '…');
};
?>
<nav class="zalozky" aria-label="<?= e(t('Stav poptávek')) ?>">
<?php foreach (['' => 'Všechny', 'otevrene' => 'K vyřízení', 'vyrizene' => 'Vyřízené'] as $klic => $nazev): ?>
	<a href="<?= e($modul->url('', array_filter(['stav' => $klic]))) ?>"<?= $filtr === $klic ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($nazev)) ?></a>
<?php endforeach ?>
</nav>
<?php if ($poptavky === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'poptavky', 'nadpis' => t('Zatím žádné poptávky.'), 'text' => t('Přidejte na web prvek Formulář ve staviteli stránek (nebo hotovou sekci Poptávkový formulář) – odeslané zprávy se objeví tady a přijdou i e-mailem.'), 'akce' => [$app->url('admin.php?modul=stranky'), t('Otevřít stránky')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Datum')) ?></th><th scope="col"><?= e(t('Formulář')) ?></th><th scope="col"><?= e(t('Obsah')) ?></th><th scope="col"><?= e(t('Stav')) ?></th></tr></thead>
<tbody>
<?php foreach ($poptavky as $p): ?>
<tr<?= (int) $p['stav'] === 2 ? ' class="nevydany"' : '' ?>>
	<td><a href="<?= e($modul->url('detail', ['id' => $p['idp']])) ?>"><?= (int) $p['stav'] === 0 ? '<strong>' . e(datum($p['datum'], true)) . '</strong>' : e(datum($p['datum'], true)) ?></a></td>
	<td><?= e($p['formular']) ?><?= $p['email'] !== '' ? '<br><small>' . e($p['email']) . '</small>' : '' ?></td>
	<td><a href="<?= e($modul->url('detail', ['id' => $p['idp']])) ?>"><?= e($nahled((string) $p['data'])) ?></a></td>
	<td><span class="stitek<?= (int) $p['stav'] === 0 ? ' stitek-koncept' : ((int) $p['stav'] === 2 ? ' stitek-vydano' : '') ?>"><?= e(t(Poptavky::STAVY[(int) $p['stav']])) ?></span></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
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
<form class="formular" method="post" action="<?= e($modul->url('nastaveni')) ?>">
<?= $csrf ?>
<div class="radek"><label for="mesice"><?= e(t('Mazat poptávky starší než')) ?></label><div><input class="textpole" type="number" id="mesice" name="mesice" value="<?= $mesice ?>" min="0" max="120" size="4"> <?= e(t('měsíců')) ?> <input class="tl" type="submit" value="<?= e(t('Uložit')) ?>">
<span class="napoveda"><?= e(t('Poptávky obsahují osobní údaje – nemají ležet déle, než je potřeba. 0 = nemazat.')) ?></span></div></div>
</form>
<?php endif ?>
