<?php
/**
 * Kolekce webu.
 *
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Kolekce $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $kolekce
 */
$spravce = $app->auth()->isAdmin();
?>
<?php if ($spravce): ?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová kolekce')) ?></a></p>
<?php endif ?>
<?php if ($kolekce === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'kolekce', 'nadpis' => t('Zatím žádné kolekce.'), 'text' => t('Kolekce je seznam podobných věcí s vlastními poli – reference, členové týmu, produkty, pobočky, ceník. Na web je dostanete prvkem Výpis kolekce ve staviteli, každá položka může mít i vlastní stránku.'), 'akce' => $spravce ? [$modul->url('novy'), t('Založit kolekci')] : null]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Kolekce')) ?></th><th scope="col"><?= e(t('Položek')) ?></th><th scope="col"><?= e(t('Stránky položek')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($kolekce as $k): ?>
<tr>
	<td><a href="<?= e($modul->url('polozky', ['id' => $k['idk']])) ?>"><strong><?= e($k['nazev']) ?></strong></a></td>
	<td><?= (int) $k['pocet'] ?></td>
	<td><?= $k['detail'] ? '/' . e($k['seo_link']) . '/…' : e(t('ne')) ?></td>
	<td class="akce"><a href="<?= e($modul->url('polozka', ['id' => $k['idk']])) ?>"><?= e(t('Přidat položku')) ?></a><?php if ($spravce): ?> · <a href="<?= e($modul->url('edit', ['id' => $k['idk']])) ?>"><?= e(t('Pole a nastavení')) ?></a><?php if ($k['detail']): ?> · <a href="<?= e($modul->url('stavitel', ['id' => $k['idk']])) ?>"><?= e(t('Šablona detailu')) ?></a><?php endif ?><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
