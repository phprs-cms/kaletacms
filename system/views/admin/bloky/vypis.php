<?php
/**
 * Plátno stránky: zóny podle zvoleného rozvržení a v nich bloky, které lze přetahovat.
 *
 * @var MiroCMS\Admin\Moduly\Bloky $modul
 * @var string $csrf
 * @var string $rozvrzeni
 * @var array<string, list<array<string, mixed>>> $bloky  zóna => bloky v pořadí shora
 */
use MiroCMS\Admin\Moduly\Bloky;

$zona = function (string $klic) use ($bloky, $modul, $csrf): void {
    if (!isset($bloky[$klic])) {
        return;
    } ?>
	<section class="zona zona-<?= e($klic) ?>" data-zona="<?= e($klic) ?>" aria-label="<?= e(t(Bloky::ZONY[$klic])) ?>">
		<h3><?= e(t(Bloky::ZONY[$klic])) ?></h3>
		<div class="zona-bloky">
<?php foreach ($bloky[$klic] as $b): ?>
			<article class="blok-karta<?= $b['sys_funkce'] !== '' ? ' blok-karta-sys' : '' ?><?= $b['zobrazit'] ? '' : ' blok-skryty' ?>" draggable="true" data-idb="<?= (int) $b['idb'] ?>">
				<strong><?= e($b['nazev']) ?></strong>
				<span><?= $b['sys_funkce'] !== '' ? e(t('systémový')) . ' · ' . e(explode(' – ', t(Bloky::SYSTEMOVE[$b['sys_funkce']] ?? $b['sys_funkce']))[0]) : e(t('vlastní HTML')) ?><?= $b['zobrazit'] ? ((int) $b['zobrazit_kde'] !== 0 ? ' · ' . e(t(Bloky::KDE[(int) $b['zobrazit_kde']] ?? '')) : '') : ' · <b>' . e(t('skrytý')) . '</b>' ?></span>
				<span class="blok-karta-akce">
					<button type="button" class="navigace" data-posun="-1" title="<?= e(t('Posunout výš')) ?>" aria-label="<?= e(t('Posunout blok %s výš', $b['nazev'])) ?>">↑</button>
					<button type="button" class="navigace" data-posun="1" title="<?= e(t('Posunout níž')) ?>" aria-label="<?= e(t('Posunout blok %s níž', $b['nazev'])) ?>">↓</button>
					<a href="<?= e($modul->url('edit', ['id' => $b['idb']])) ?>"><?= e(t('Upravit')) ?></a>
					<form method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Opravdu smazat blok?')) ?>"><?= $csrf ?><input type="hidden" name="idb" value="<?= (int) $b['idb'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
				</span>
			</article>
<?php endforeach ?>
		</div>
		<a class="navigace zona-pridat" href="<?= e($modul->url('novy', ['zona' => $klic])) ?>"><?= e(t('+ přidat blok')) ?></a>
	</section>
<?php };
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('vizualne')) ?>"><?= e(t('Otevřít vizuální editor')) ?></a></p>
<form class="rozvrzeni-volba" method="post" action="<?= e($modul->url('rozvrzeni')) ?>">
	<?= $csrf ?>
	<span class="popisek"><?= e(t('Rozvržení stránky:')) ?></span>
<?php foreach (Bloky::ROZVRZENI as $klic => [$nazev, $popis]): ?>
	<button type="submit" name="rozvrzeni" value="<?= e($klic) ?>" class="rozvrzeni-tl<?= $klic === $rozvrzeni ? ' aktivni' : '' ?>" aria-pressed="<?= $klic === $rozvrzeni ? 'true' : 'false' ?>" title="<?= e(t($popis)) ?>">
		<i class="rozvrzeni-ikona rozvrzeni-ikona-<?= e($klic) ?>" aria-hidden="true"><b></b><b></b><b></b></i><?= e(t($nazev)) ?>
	</button>
<?php endforeach ?>
</form>

<div class="platno platno-<?= e($rozvrzeni) ?>" data-platno data-url="<?= e($modul->url('poradi')) ?>">
	<?php $zona('hlavicka') ?>
	<div class="platno-stred">
		<?php $zona('leva') ?>
		<div class="platno-obsah">
			<?php $zona('nad') ?>
			<div class="platno-misto-obsahu"><?= e(t('Obsah stránky')) ?><br><small><?= e(t('výpis článků, článek, rubrika, vyhledávání')) ?></small></div>
			<?php $zona('pod') ?>
		</div>
		<?php $zona('prava') ?>
	</div>
	<?php $zona('paticka') ?>
</div>
<p class="smltxt"><?= e(t('Bloky přetáhněte myší na nové místo nebo do jiné zóny, případně použijte šipky. Pořadí se ukládá samo. Fialově jsou systémové bloky, šedě bloky s vlastním HTML.')) ?> <span data-stav-poradi role="status"></span></p>
