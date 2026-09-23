<?php
/**
 * Titulní strana: ruční pořadí článků nahoře na hlavní stránce.
 *
 * @var MiroCMS\Admin\Moduly\Clanky $modul
 * @var MiroCMS\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $pripnute
 * @var list<array<string, mixed>> $dalsi
 */
$polozka = function (array $c, bool $pripnuty) use ($app): string {
    $obr = $c['obrazek'] === '' ? '' : (preg_match('#^(https?:)?/#i', $c['obrazek']) ? $c['obrazek'] : $app->url($c['obrazek']));

    return '<li draggable="true" data-id="' . (int) $c['idc'] . '">'
        . ($obr !== '' ? '<img src="' . e($obr) . '" alt="" loading="lazy">' : '<span class="titulni-bez-obrazku"></span>')
        . '<span class="titulni-text"><strong>' . e($c['titulek']) . '</strong><small>' . e($c['tema_jm']) . ' · ' . e(datum($c['datum'], true)) . '</small></span>'
        . '<span class="titulni-akce"><button type="button" data-nahoru aria-label="' . e(t('Posunout výš')) . '">↑</button><button type="button" data-dolu aria-label="' . e(t('Posunout níž')) . '">↓</button>'
        . '<button type="button" class="navigace" data-prepnout data-pripnout="' . e(t('Připnout')) . '" data-odepnout="' . e(t('Odepnout')) . '">' . e(t($pripnuty ? 'Odepnout' : 'Připnout')) . '</button></span></li>';
};
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled článků')) ?></a> <a class="navigace" href="<?= e($app->url('')) ?>" target="_blank" rel="noopener"><?= e(t('Zobrazit web')) ?></a></p>
<p class="smltxt"><?= e(t('Připnuté články jsou na hlavní stránce nahoře v pořadí, které tady určíte – přetažením nebo šipkami. Pod nimi následují ostatní články od nejnovějšího. První připnutý článek je otvírák.')) ?></p>
<form method="post" action="<?= e($modul->url('titulni')) ?>" data-titulni>
<?= $csrf ?>
<input type="hidden" name="poradi" value="<?= e(implode(',', array_column($pripnute, 'idc'))) ?>">
<div class="titulni-sloupce">
	<section>
		<h3><?= e(t('Nahoře na titulní straně')) ?></h3>
		<ol class="titulni-seznam" data-seznam="pripnute" data-prazdne="<?= e(t('Sem přetáhněte článek, nebo u něj klepněte na Připnout.')) ?>"><?php foreach ($pripnute as $c) { echo $polozka($c, true); } ?></ol>
	</section>
	<section>
		<h3><?= e(t('Nejnovější články')) ?></h3>
		<ol class="titulni-seznam" data-seznam="dalsi" data-prazdne="<?= e(t('Žádné další články.')) ?>"><?php foreach ($dalsi as $c) { echo $polozka($c, false); } ?></ol>
	</section>
</div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit titulní stranu')) ?>"></p>
</form>
