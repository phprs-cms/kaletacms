<?php
/**
 * @var Kaleta\Admin\Moduly\Role $modul
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $role
 * @var array<string, string> $nazvy  ident sekce => název
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url('admin.php?modul=users')) ?>"><?= e(t('Zpět na uživatele')) ?></a> <a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová role')) ?></a></p>
<p class="smltxt"><?= e(t('Vestavěné role Autor novinek, Editor a Správce stačí většině webů. Vlastní role se hodí, když má někdo vidět jen část administrace – třeba obchodník jen Poptávky.')) ?></p>
<?php if ($role === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'uzivatele', 'nadpis' => t('Zatím žádné vlastní role.'), 'akce' => [$modul->url('novy'), t('Nová role')]]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Role')) ?></th><th scope="col"><?= e(t('Sekce')) ?></th><th scope="col"><?= e(t('Uživatelů')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($role as $r): ?>
<tr>
	<td><a href="<?= e($modul->url('edit', ['id' => $r['idr']])) ?>"><?= e($r['nazev']) ?></a><?= $r['popis'] !== '' ? '<br><span class="smltxt">' . e($r['popis']) . '</span>' : '' ?></td>
	<td><?= e(implode(', ', array_map(fn (string $i): string => t($nazvy[$i] ?? $i), array_filter(explode(',', (string) $r['moduly']))))) ?></td>
	<td class="cislo"><?= (int) $r['clenu'] ?></td>
	<td class="akce"><a href="<?= e($modul->url('edit', ['id' => $r['idr']])) ?>"><?= e(t('Upravit')) ?></a>
		/ <form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Smazat roli? Její členové si ponechají dosavadní přístup.')) ?>"><?= $csrf ?><input type="hidden" name="idr" value="<?= (int) $r['idr'] ?>"><input type="hidden" name="nazev" value="<?= e($r['nazev']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
