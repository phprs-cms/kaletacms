<?php
/**
 * @var MiroCMS\Admin\Moduly\Rubriky $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $rubriky
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová rubrika')) ?></a></p>
<?php if ($rubriky === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'rubriky', 'nadpis' => t('Zatím není založena žádná rubrika.'), 'text' => t('Každý článek patří do jedné rubriky – bez ní článek nepůjde uložit.'), 'akce' => [$modul->url('novy'), t('Založit první rubriku')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název rubriky')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Článků')) ?></th><th scope="col"><?= e(t('Pořadí')) ?></th><th scope="col"><?= e(t('Zobrazit')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($rubriky as $r): ?>
<tr<?= $r['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td><?= str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $r['uroven']) . ($r['uroven'] > 0 ? '&#9492; ' : '') ?><a href="<?= e($modul->url('edit', ['id' => $r['idt']])) ?>"><?= e($r['nazev']) ?></a></td>
	<td>/rubrika/<?= e($r['seo_link']) ?></td>
	<td class="cislo"><?= (int) $r['pocet_clanku'] ?></td>
	<td class="cislo"><?= (int) $r['hodnost'] ?></td>
	<td class="stred"><?= $r['zobrazit'] ? e(t('Ano')) : '<strong>' . e(t('Ne')) . '</strong>' ?></td>
	<td class="akce">
		<a href="<?= e($modul->url('edit', ['id' => $r['idt']])) ?>"><?= e(t('Upravit')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Opravdu smazat rubriku?')) ?>"><?= $csrf ?><input type="hidden" name="idt" value="<?= (int) $r['idt'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
