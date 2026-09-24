<?php
/**
 * Komponenty webu.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Komponenty $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $komponenty
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová komponenta')) ?></a></p>
<?php if ($komponenty === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'komponenta', 'nadpis' => t('Zatím žádné komponenty.'), 'text' => t('Komponenta je blok, který používáte na víc místech – karta služby, kontaktní pruh, výzva. V builderu vyberte prvek a zvolte „Uložit jako komponentu“; když ji pak upravíte, změní se všude najednou.'), 'akce' => [$modul->url('novy'), t('Založit komponentu')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Komponenta')) ?></th><th scope="col"><?= e(t('Vlastnosti')) ?></th><th scope="col"><?= e(t('Použitá')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($komponenty as $k): ?>
<tr>
	<td><a href="<?= e($modul->url('stavitel', ['id' => $k['idm']])) ?>"><strong><?= e($k['nazev']) ?></strong></a><?= $k['stavba_koncept'] !== null && $k['stavba'] !== null ? ' <span class="stitek stitek-koncept">' . e(t('nepublikované změny')) . '</span>' : '' ?></td>
	<td><?= $k['vlastnosti'] === [] ? '—' : implode(' ', array_map(fn (array $v): string => '<code>{{' . e($v['klic']) . '}}</code>', $k['vlastnosti'])) ?></td>
	<td><?= e(t('%s×', (string) $k['pouziti'])) ?></td>
	<td class="akce"><a href="<?= e($modul->url('stavitel', ['id' => $k['idm']])) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($modul->url('edit', ['id' => $k['idm']])) ?>"><?= e(t('Název a vlastnosti')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e($k['pouziti'] > 0 ? t('Komponenta je použitá %s×. Po smazání tato místa zůstanou prázdná. Smazat?', (string) $k['pouziti']) : t('Opravdu smazat komponentu?')) ?>"><?= $csrf ?><input type="hidden" name="idm" value="<?= (int) $k['idm'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
