<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Stranky $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $stranky
 */
$uvod = $app->settings()->int('titulni_stranka');
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová stránka')) ?></a></p>
<?php if ($stranky === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'stranky', 'nadpis' => t('Zatím žádné stránky.'), 'text' => t('Firemní web obvykle tvoří Úvod, O nás, Služby a Kontakt.'), 'akce' => [$modul->url('novy'), t('Založit první stránku')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('V navigaci')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($stranky as $s): ?>
<tr<?= $s['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($modul->url('edit', ['id' => $s['ids']])) ?>"><?= e($s['titulek']) ?></a><?= (int) $s['ids'] === $uvod ? ' <span class="stitek">' . e(t('úvodní')) . '</span>' : '' ?><?= $s['stavba'] !== null ? ' <span class="stitek stitek-vydano">' . e(t('stavba')) . '</span>' : '' ?><?= $s['stavba_koncept'] !== null ? ' <span class="stitek stitek-koncept">' . e(t('neuložené změny')) . '</span>' : '' ?></td>
	<td><?php $cesta = (int) $s['ids'] === $uvod ? '' : $s['seo_link']; ?><a href="<?= e($app->url($cesta)) ?>" target="_blank" rel="noopener">/<?= e($cesta) ?></a></td>
	<td><span class="stitek stitek-<?= $s['zobrazit'] ? 'vydano' : 'koncept' ?>"><?= e(t($s['zobrazit'] ? 'zveřejněná' : 'skrytá')) ?></span></td>
	<td><?= e(t($s['v_menu'] ? 'Ano' : 'Ne')) ?></td>
	<td class="akce"><a href="<?= e($modul->url('stavitel', ['id' => $s['ids']])) ?>"><?= e(t('Stavitel')) ?></a> · <a href="<?= e($modul->url('edit', ['id' => $s['ids']])) ?>"><?= e(t('Nastavení')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Opravdu smazat stránku?')) ?>"><?= $csrf ?><input type="hidden" name="ids" value="<?= (int) $s['ids'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
