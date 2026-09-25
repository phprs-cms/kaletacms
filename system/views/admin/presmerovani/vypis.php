<?php
/**
 * @var Kaleta\Admin\Moduly\Presmerovani $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $zaznamy
 * @var list<array<string, mixed>> $nenalezeno  adresy, které v posledních 60 dnech skončily chybou 404
 * @var string $zAdresy  předvyplněná stará adresa
 * @var ?array<string, mixed> $upravit  upravovaný záznam
 * @var int $celkem
 * @var int $strana
 * @var int $stran
 * @var string $hledat
 */
$u = $upravit;
$cesta = fn (string $a): string => preg_match('#^https?://#i', $a) ? $a : '/' . $a;
?>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>" id="upravit">
<?= $csrf ?>
<input type="hidden" name="idp" value="<?= (int) ($u['idp'] ?? 0) ?>">
<div class="radek"><label for="z_adresy"><?= e(t('Stará adresa')) ?></label><div><input class="textpole siroke" type="text" id="z_adresy" name="z_adresy" value="<?= e($u !== null ? '/' . $u['z_adresy'] : ($zAdresy !== '' ? '/' . ltrim($zAdresy, '/') : '')) ?>" maxlength="255" required placeholder="<?= e(t('/stara-stranka.html')) ?>"><span class="napoveda"><?= e(t('Cesta na tomto webu, která už neexistuje.')) ?></span></div></div>
<div class="radek"><label for="na_adresu"><?= e(t('Přesměrovat na')) ?></label><div><input class="textpole siroke" type="text" id="na_adresu" name="na_adresu" value="<?= e($u !== null ? $cesta($u['na_adresu']) : '') ?>" maxlength="255" required placeholder="<?= e(t('/nova-adresa nebo https://…')) ?>"></div></div>
<div class="radek"><label for="typ"><?= e(t('Typ')) ?></label><select id="typ" name="typ">
	<option value="301"><?= e(t('trvalé (301) – stránka se přestěhovala')) ?></option>
	<option value="302"<?= (int) ($u['typ'] ?? 301) === 302 ? ' selected' : '' ?>><?= e(t('dočasné (302) – akce, sezónní nabídka')) ?></option>
</select></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($u !== null ? 'Uložit změny' : 'Přidat přesměrování')) ?>"><?= $u !== null ? ' <a class="navigace" href="' . e($modul->url()) . '">' . e(t('Zrušit')) . '</a>' : '' ?></p>
</form>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt"><input type="hidden" name="modul" value="presmerovani">
	<label><?= e(t('Adresa obsahuje:')) ?> <input class="textpole" type="search" name="hledat" value="<?= e($hledat) ?>" size="24"></label> <input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>"> (<?= e(t('Celkem:')) ?> <?= $celkem ?>)</form>
<p class="smltxt"><?= e(t('Přesměrování se použije jen tehdy, když na staré adrese nic není. Při změně adresy stránky, novinky nebo kategorie vzniká samo.')) ?></p>
<?php if ($zaznamy === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'presmerovani', 'nadpis' => t('Zatím žádné přesměrování.'), 'text' => t('Nic nemusíte dělat – když změníte adresu stránky nebo novinky, přesměrování vznikne samo.')]) ?>
<?php endif ?>
<?php if ($zaznamy !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Stará adresa')) ?></th><th scope="col"><?= e(t('Cíl')) ?></th><th scope="col"><?= e(t('Použito')) ?></th><th scope="col"><?= e(t('Vytvořeno')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($zaznamy as $z): ?>
<tr>
	<td>/<?= e($z['z_adresy']) ?></td>
	<td><?= e($cesta($z['na_adresu'])) ?><?= (int) ($z['typ'] ?? 301) === 302 ? ' <span class="stitek">302</span>' : '' ?></td>
	<td class="cislo"><?= (int) $z['pocet'] ?>×</td>
	<td class="cislo"><?= e(datum($z['vytvoreno'])) ?></td>
	<td class="akce"><a href="<?= e($modul->url('', ['upravit' => (int) $z['idp']])) ?>#upravit"><?= e(t('Upravit')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Smazat přesměrování? Stará adresa pak skončí chybou 404.')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $z['idp'] ?>"><input type="hidden" name="titulek" value="<?= e('/' . $z['z_adresy']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($stran > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $stran; $s++): ?>
	<?= $s === $strana ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($modul->url('', array_filter(['hledat' => $hledat, 'strana' => $s]))) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
<?php if ($nenalezeno !== []): ?>
<h2><?= e(t('Adresy, které návštěvníci nenašli (404)')) ?></h2>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Kolikrát')) ?></th><th scope="col"><?= e(t('Naposledy')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($nenalezeno as $n): ?>
<tr><td>/<?= e($n['cesta']) ?></td><td class="cislo"><?= (int) $n['pocet'] ?>×</td><td class="cislo"><?= e(datum($n['naposledy'])) ?></td>
	<td class="akce"><a href="<?= e($modul->url('', ['z' => $n['cesta']])) ?>"><?= e(t('Přesměrovat')) ?></a></td></tr>
<?php endforeach ?>
</tbody></table></div>
<form method="post" action="<?= e($modul->url('vycisti')) ?>" data-potvrdit="<?= e(t('Vyprázdnit přehled nenalezených adres?')) ?>"><?= $csrf ?><p><button class="navigace nebezpecne" type="submit"><?= e(t('Vyprázdnit přehled')) ?></button></p></form>
<?php endif ?>
