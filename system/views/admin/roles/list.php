<?php
/**
 * @var Kaleta\Admin\Modules\Roles $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $role
 * @var array<string, string> $names  section ident => name
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url('admin.php?module=users')) ?>"><?= e(t('Back to users')) ?></a> <a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New role')) ?></a></p>
<p class="smltxt"><?= e(t('Start from a ready-made role:')) ?>
<?php foreach (Kaleta\Admin\Modules\Roles::PRESETS as $key => [$presetName, $presetHelp]): ?>
	<a href="<?= e($module->url('new', ['preset' => $key])) ?>" title="<?= e(t($presetHelp)) ?>"><?= e(t($presetName)) ?></a><?= $key !== array_key_last(Kaleta\Admin\Modules\Roles::PRESETS) ? ' ·' : '' ?>
<?php endforeach ?>
</p>
<p class="smltxt"><?= e(t('The built-in roles News author, Editor and Administrator are enough for most websites. A custom role is useful when someone should see only part of the administration – for example a salesperson only Enquiries.')) ?></p>
<?php if ($role === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'uzivatele', 'heading' => t('No custom roles yet.'), 'action' => [$module->url('new'), t('New role')]]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Roles')) ?></th><th scope="col"><?= e(t('Section')) ?></th><th scope="col"><?= e(t('Uživatelů')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($role as $r): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => $r['idr']])) ?>"><?= e($r['nazev']) ?></a><?= $r['popis'] !== '' ? '<br><span class="smltxt">' . e($r['popis']) . '</span>' : '' ?></td>
	<td><?= e(implode(', ', array_map(fn (string $i): string => t($names[$i] ?? $i), array_filter(explode(',', (string) $r['moduly']))))) ?></td>
	<td class="cislo"><?= (int) $r['clenu'] ?></td>
	<td class="akce"><a href="<?= e($module->url('edit', ['id' => $r['idr']])) ?>"><?= e(t('Edit')) ?></a>
		/ <form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the role? Its members will keep their current access.')) ?>"><?= $csrf ?><input type="hidden" name="idr" value="<?= (int) $r['idr'] ?>"><input type="hidden" name="nazev" value="<?= e($r['nazev']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
