<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Kategorie $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $kategorie
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová kategorie')) ?></a> <a class="navigace" href="<?= e($app->url('admin.php?modul=novinky')) ?>"><?= e(t('Zpět na novinky')) ?></a></p>
<?php if ($kategorie === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'rubriky', 'nadpis' => t('Zatím není založena žádná kategorie.'), 'text' => t('Každá novinka patří do jedné kategorie – bez ní novinka nepůjde uložit.'), 'akce' => [$modul->url('novy'), t('Založit první kategorii')]]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Novinek')) ?></th><th scope="col"><?= e(t('Pořadí')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($kategorie as $k): ?>
<tr>
	<td><a href="<?= e($modul->url('edit', ['id' => $k['idt']])) ?>"><?= e($k['nazev']) ?></a></td>
	<td>/novinky/kategorie/<?= e($k['seo_link']) ?></td>
	<td class="cislo"><?= (int) $k['pocet_clanku'] ?></td>
	<td class="cislo"><?= (int) $k['hodnost'] ?></td>
	<td class="akce">
		<a href="<?= e($modul->url('edit', ['id' => $k['idt']])) ?>"><?= e(t('Upravit')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Opravdu smazat kategorii?')) ?>"><?= $csrf ?><input type="hidden" name="idt" value="<?= (int) $k['idt'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
