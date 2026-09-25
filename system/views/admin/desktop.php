<?php
/**
 * Úvodní obrazovka administrace.
 * Přehled webu: první kroky, upozornění, počty, návštěvnost, nové poptávky a naposledy upravený obsah.
 *
 * @var Kaleta\Core\App $app
 * @var array<string, class-string<Kaleta\Admin\Modul>> $moduly
 * @var array<string, array{0: int, 1: string}> $pocty  popisek => [počet, adresa]
 * @var list<array{0: string, 1: string}> $upozorneni  [text, adresa]
 * @var list<array<string, mixed>> $poptavky
 * @var list<array{druh: string, titulek: string, kdy: string, url: string, stav: string}> $upravene
 */
?>
<div class="prehled-hlavicka">
	<h1><?= e(t('Přehled')) ?></h1>
	<p class="navigace-radek">
<?php if (isset($moduly['stranky'])): ?>
		<a class="tl" href="<?= e($app->url('admin.php?modul=stranky&akce=novy')) ?>"><?= e(t('Nová stránka')) ?></a>
<?php endif ?>
<?php if (isset($moduly['novinky'])): ?>
		<a class="navigace" href="<?= e($app->url('admin.php?modul=novinky&akce=novy')) ?>"><?= e(t('Napsat novinku')) ?></a>
<?php endif ?>
		<a class="navigace" href="<?= e($app->url('')) ?>" target="_blank" rel="noopener"><?= e(t('Zobrazit web')) ?></a>
	</p>
</div>
<?php foreach ($upozorneni as [$text, $adresa]): ?>
<p class="hlaska hlaska-varovani"><?= e($text) ?> <a href="<?= e($adresa) ?>"><?= e(t('Vyřešit')) ?></a></p>
<?php endforeach ?>
<?php if (!empty($pruvodce)): $hotovych = count(array_filter($pruvodce, fn (array $k): bool => $k['hotovo'])); ?>
<section class="pruvodce" aria-label="<?= e(t('První kroky')) ?>">
	<div class="pruvodce-hlava">
		<h2><?= e(t('První kroky')) ?> <small><?= $hotovych ?> / <?= count($pruvodce) ?></small></h2>
		<form method="post" action="<?= e($app->url('admin.php?akce=pruvodce_skryt')) ?>"><?= $app->session->csrfField() ?><button class="navigace" type="submit"><?= e(t('Skrýt')) ?></button></form>
	</div>
	<ol class="pruvodce-kroky">
<?php foreach ($pruvodce as $k): ?>
		<li class="<?= $k['hotovo'] ? 'hotovo' : '' ?>"><a href="<?= e($k['url']) ?>"><strong><?= e(t($k['nazev'])) ?></strong><span><?= e(t($k['popis'])) ?></span></a></li>
<?php endforeach ?>
	</ol>
</section>
<?php endif ?>
<div class="dlazdice">
<?php foreach ($pocty as $popis => [$pocet, $adresa]): ?>
	<a class="dlazdice-polozka" href="<?= e($app->url($adresa)) ?>"><strong><?= pocet($pocet) ?></strong><span><?= e(t($popis)) ?></span></a>
<?php endforeach ?>
</div>
<?php if (count($navstevnost) >= 2):
    // sloupcový graf v čistém SVG: jeden sloupec na den, výška podle návštěv
    $dny = [];
    for ($i = 13; $i >= 0; $i--) { $dny[date('Y-m-d', strtotime("-{$i} day"))] = 0; }
    foreach ($navstevnost as $n) { $dny[$n['den']] = (int) $n['navstevy']; }
    $max = max(1, ...array_values($dny));
?>
<section class="prehled-graf" aria-label="<?= e(t('Návštěvnost za 14 dní')) ?>">
	<h2><?= e(t('Návštěvnost za 14 dní')) ?> <small><?= e(t('%s návštěv', pocet(array_sum($dny)))) ?></small></h2>
	<svg viewBox="0 0 280 70" preserveAspectRatio="none" role="img" aria-label="<?= e(t('Návštěvnost za 14 dní')) ?>">
<?php $x = 0; foreach ($dny as $den => $pocet): $v = max(1, (int) round($pocet / $max * 62)); ?>
		<rect x="<?= $x * 20 + 2 ?>" y="<?= 66 - $v ?>" width="16" height="<?= $v ?>" rx="2"><title><?= e(datum($den)) ?>: <?= $pocet ?></title></rect>
<?php $x++; endforeach ?>
	</svg>
	<p class="smltxt"><a href="<?= e($app->url('admin.php?modul=stat')) ?>"><?= e(t('Celá statistika')) ?></a></p>
</section>
<?php endif ?>
<?php if ($poptavky !== []): ?>
<h2><?= e(t('Poslední poptávky')) ?></h2>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Formulář')) ?></th><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Přijato')) ?></th></tr></thead>
<tbody>
<?php foreach ($poptavky as $p): ?>
<tr<?= (int) $p['stav'] === 0 ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($app->url('admin.php?modul=poptavky&akce=detail&id=' . (int) $p['idp'])) ?>"><?= e($p['formular'] !== '' ? $p['formular'] : t('Poptávka')) ?></a><?= (int) $p['stav'] === 0 ? ' <span class="stitek stitek-koncept">' . e(t('nová')) . '</span>' : '' ?></td>
	<td><?= e($p['email']) ?></td>
	<td class="cislo"><?= e(datum($p['datum'], true)) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<?php if ($upravene !== []): ?>
<h2><?= e(t('Naposledy upravené')) ?></h2>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Druh')) ?></th><th scope="col"><?= e(t('Upraveno')) ?></th></tr></thead>
<tbody>
<?php foreach ($upravene as $u): ?>
<tr>
	<td><a href="<?= e($u['url']) ?>"><?= e($u['titulek']) ?></a><?= $u['stav'] !== '' ? ' <span class="stitek stitek-koncept">' . e($u['stav']) . '</span>' : '' ?></td>
	<td><?= e($u['druh']) ?></td>
	<td class="cislo"><?= e(datum($u['kdy'], true)) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
