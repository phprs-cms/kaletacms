<?php
/**
 * Redakční kalendář - měsíční mřížka článků podle data vydání.
 *
 * @var MiroCMS\Admin\Moduly\Clanky $modul
 * @var DateTimeImmutable $od  první den měsíce
 * @var array<int, list<array<string, mixed>>> $dny  den v měsíci => články
 */
$mesice = explode(' ', t('leden únor březen duben květen červen červenec srpen září říjen listopad prosinec')); // jeden klíč slovníku, dvanáct slov
$posun = ((int) $od->format('N')) - 1;
$dni = (int) $od->format('t');
?>
<p class="navigace-radek">
	<a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled článků')) ?></a>
	<a class="navigace" href="<?= e($modul->url('kalendar', ['mesic' => $od->modify('-1 month')->format('Y-m')])) ?>"><?= e(t('← předchozí')) ?></a>
	<strong style="align-self:center"><?= e(($mesice[(int) $od->format('n') - 1] ?? $od->format('n.')) . ' ' . $od->format('Y')) ?></strong>
	<a class="navigace" href="<?= e($modul->url('kalendar', ['mesic' => $od->modify('+1 month')->format('Y-m')])) ?>"><?= e(t('další →')) ?></a>
</p>
<div class="kalendar">
<?php foreach (explode(' ', t('Po Út St Čt Pá So Ne')) as $d): ?>
	<div class="kalendar-hlava"><?= e($d) ?></div>
<?php endforeach ?>
<?php for ($i = 0; $i < $posun; $i++): ?><div class="kalendar-den kalendar-prazdny"></div><?php endfor ?>
<?php for ($den = 1; $den <= $dni; $den++): $dnes = $od->format('Y-m-') . sprintf('%02d', $den) === date('Y-m-d'); ?>
	<div class="kalendar-den<?= $dnes ? ' kalendar-dnes' : '' ?>">
		<span class="kalendar-cislo"><?= $den ?></span>
<?php foreach ($dny[$den] ?? [] as $c): $stav = !$c['visible'] ? (['korektura' => 'korektura', 'schvaleno' => 'schvaleno'][$c['stav_redakce'] ?? ''] ?? 'koncept') : (strtotime($c['datum']) > time() ? 'plan' : 'vydano'); ?>
		<a class="kalendar-clanek stitek-<?= $stav ?>" href="<?= e($modul->url('edit', ['id' => $c['idc']])) ?>" title="<?= e(date('H:i', strtotime($c['datum'])) . ' · ' . t(['koncept' => 'koncept', 'korektura' => 'ke korektuře', 'schvaleno' => 'schváleno – čeká na vydání', 'plan' => 'naplánováno', 'vydano' => 'vydáno'][$stav])) ?>"><?= e(date('H:i', strtotime($c['datum']))) ?> <?= e($c['titulek']) ?></a>
<?php endforeach ?>
	</div>
<?php endfor ?>
</div>
<p class="smltxt"><?= e(t('Zeleně vydané, modře naplánované, oranžově koncepty, fialově články ke korektuře a schválené (schválené plnou čarou). Datum článku změníte v jeho úpravě.')) ?></p>
