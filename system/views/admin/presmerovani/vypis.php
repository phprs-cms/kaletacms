<?php
/**
 * @var MiroCMS\Admin\Moduly\Presmerovani $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $zaznamy
 * @var list<array<string, mixed>> $nenalezeno  adresy, které v posledních 60 dnech skončily chybou 404
 * @var string $zAdresy  předvyplněná stará adresa
 */
?>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<div class="radek"><label for="z_adresy"><?= e(t('Stará adresa')) ?></label><div><input class="textpole siroke" type="text" id="z_adresy" name="z_adresy" value="<?= e($zAdresy !== '' ? '/' . ltrim($zAdresy, '/') : '') ?>" maxlength="255" required placeholder="<?= e(t('/stara-stranka.html')) ?>"><span class="napoveda"><?= e(t('Cesta na tomto webu, která už neexistuje.')) ?></span></div></div>
<div class="radek"><label for="na_adresu"><?= e(t('Přesměrovat na')) ?></label><div><input class="textpole siroke" type="text" id="na_adresu" name="na_adresu" maxlength="255" required placeholder="<?= e(t('/clanek/nova-adresa nebo https://…')) ?>"></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Přidat přesměrování')) ?>"></p>
</form>
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
	<td><?= e(preg_match('#^https?://#i', $z['na_adresu']) ? $z['na_adresu'] : '/' . $z['na_adresu']) ?></td>
	<td class="cislo"><?= (int) $z['pocet'] ?>×</td>
	<td class="cislo"><?= e(datum($z['vytvoreno'])) ?></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $z['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<?php if ($nenalezeno !== []): ?>
<h3><?= e(t('Adresy, které návštěvníci nenašli (404)')) ?></h3>
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
