<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Statistika $modul
 * @var int $dni
 * @var array<string, array{navstevy:int, zobrazeni:int}> $graf
 * @var bool $zapnuto
 * @var list<array<string, mixed>> $clanky
 * @var list<array<string, mixed>> $zdroje
 */
$max = max(1, ...array_column($graf, 'zobrazeni'));
$navstev = array_sum(array_column($graf, 'navstevy'));
$zobrazeni = array_sum(array_column($graf, 'zobrazeni'));
?>
<?php if (!$zapnuto): ?>
<p class="hlaska hlaska-chyba"><?= e(t('Měření je vypnuté. Zapnete ho v Nastavení → Měření.')) ?></p>
<?php endif ?>
<nav class="zalozky" aria-label="<?= e(t('Období')) ?>">
<?php foreach ([7, 30, 90] as $d): ?>
	<a href="<?= e($modul->url('', ['dni' => $d])) ?>"<?= $dni === $d ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t('%s dní', $d)) ?></a>
<?php endforeach ?>
</nav>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= pocet($navstev) ?></strong><span><?= e(t('Návštěvy')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= pocet($zobrazeni) ?></strong><span><?= e(t('Zobrazené stránky')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= $navstev > 0 ? pocet($zobrazeni / $navstev, 1) : '0' ?></strong><span><?= e(t('Stránek na návštěvu')) ?></span></div>
</div>
<h3><?= e(t('Zobrazení a návštěvy po dnech')) ?></h3>
<div class="graf" role="img" aria-label="<?= e(t('Sloupcový graf zobrazení stránek po dnech')) ?>">
<?php foreach ($graf as $den => $h): ?>
	<div class="graf-sloupec" title="<?= e(t('%s: %s zobrazení, %s návštěv', datum($den), $h['zobrazeni'], $h['navstevy'])) ?>"><i style="height:<?= round($h['zobrazeni'] / $max * 100, 1) ?>%"><b style="height:<?= $h['zobrazeni'] > 0 ? round($h['navstevy'] / $h['zobrazeni'] * 100, 1) : 0 ?>%"></b></i></div>
<?php endforeach ?>
</div>
<p class="smltxt"><?= e(datum(array_key_first($graf))) ?> – <?= e(datum(array_key_last($graf))) ?> · <?= e(t('světlá část sloupce jsou zobrazení stránek, tmavá návštěvy. Měření nepoužívá cookies a neukládá IP adresy; roboty nepočítá.')) ?></p>

<div class="stat-tabulky">
<div>
<h3><?= e(t('Nejčtenější novinky')) ?></h3>
<?php if ($clanky === []): ?><p><?= e(t('Zatím žádná data.')) ?></p><?php else: ?>
<div class="tab-obal"><table class="vypis"><tbody>
<?php foreach ($clanky as $c): ?>
<tr><td><a href="<?= e($app->url('admin.php?modul=novinky&akce=edit&id=' . (int) $c['idc'])) ?>"><?= e($c['titulek']) ?></a></td><td class="cislo"><?= pocet((int) $c['pocet']) ?>×</td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</div>
<div>
<h3><?= e(t('Odkud návštěvníci přicházejí')) ?></h3>
<?php if ($zdroje === []): ?><p><?= e(t('Zatím žádná data.')) ?></p><?php else: ?>
<div class="tab-obal"><table class="vypis"><tbody>
<?php foreach ($zdroje as $z): ?>
<tr><td><?= e($z['zdroj']) ?></td><td class="cislo"><?= pocet((int) $z['pocet']) ?>×</td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</div>
</div>
