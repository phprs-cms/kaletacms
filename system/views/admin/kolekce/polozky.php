<?php
/**
 * Položky kolekce.
 *
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Kolekce $modul
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var list<array<string, mixed>> $polozky
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('polozka', ['id' => $k['idk']])) ?>"><?= e(t('Přidat položku')) ?></a>
	<a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Všechny kolekce')) ?></a>
<?php if ($app->auth()->isAdmin()): ?>
	<a class="navigace" href="<?= e($modul->url('edit', ['id' => $k['idk']])) ?>"><?= e(t('Pole a nastavení')) ?></a>
<?php if ($k['detail']): ?>
	<a class="navigace" href="<?= e($modul->url('stavitel', ['id' => $k['idk']])) ?>"><?= e(t('Šablona detailu')) ?></a>
<?php endif ?>
<?php endif ?></p>
<?php if ($polozky === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'kolekce', 'nadpis' => t('Kolekce je zatím prázdná.'), 'text' => t('Přidejte první položku – na web ji pak dostanete prvkem Výpis kolekce ve staviteli.'), 'akce' => [$modul->url('polozka', ['id' => $k['idk']]), t('Přidat položku')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Pořadí')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($polozky as $p): ?>
<tr<?= $p['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($modul->url('polozka', ['id' => $k['idk'], 'polozka' => $p['idp']])) ?>"><?= e($p['nazev']) ?></a><?= $p['jazyk'] !== '' ? ' <span class="stitek">' . e(strtoupper($p['jazyk'])) . '</span>' : '' ?></td>
	<td><?= (int) $p['poradi'] ?></td>
	<td><span class="stitek stitek-<?= $p['zobrazit'] ? 'vydano' : 'koncept' ?>"><?= e(t($p['zobrazit'] ? 'zveřejněná' : 'skrytá')) ?></span></td>
	<td class="akce"><?php if ($k['detail'] && $p['zobrazit']): ?><a href="<?= e($app->url(($p['jazyk'] !== '' ? $p['jazyk'] . '/' : '') . $k['seo_link'] . '/' . $p['seo_link'])) ?>" target="_blank" rel="noopener"><?= e(t('Zobrazit')) ?></a> · <?php endif ?>
		<form class="vradku" method="post" action="<?= e($modul->url('smaz_polozku')) ?>" data-potvrdit="<?= e(t('Opravdu smazat položku?')) ?>"><?= $csrf ?><input type="hidden" name="idk" value="<?= (int) $k['idk'] ?>"><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
