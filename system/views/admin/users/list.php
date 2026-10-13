<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Users $module
 * @var string $csrf
 * @var list<array<string, mixed>> $authors
 * @var bool $teamExists the site has a people collection (3.14)
 * @var array<int, array{name: string, chosen: bool}> $linked idu => the Team record the account is linked to (Builder\TeamRecord::linked)
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($module->url('new')) ?>"><?= e(t('New user')) ?></a> <a class="navigace" href="<?= e($app->url('admin.php?module=roles')) ?>"><?= e(t('Roles')) ?></a></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('User')) ?></th><th scope="col"><?= e(t('Jméno')) ?></th><th scope="col"><?= e(t('Email')) ?></th><th scope="col"><?= e(t('Roles')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><?= e(t('Last sign-in')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($authors as $a): ?>
<tr<?= $a['blokovat'] ? ' class="nevydany"' : '' ?>>
	<td><a href="<?= e($module->url('edit', ['id' => $a['idu']])) ?>"><?= bdi($a['user']) ?></a><?= $a['blokovat'] ? ' <strong>(' . e(t($a['blokovano_automaticky'] !== null ? 'blocked automatically' : 'blocked')) . ')</strong>' : '' ?><?= $a['totp_tajemstvi'] !== '' ? ' <span class="stitek stitek-vydano" title="' . e(t('two-factor sign-in')) . '">2FA</span>' : '' ?></td>
	<td><?= bdi($a['jmeno']) ?><br><span class="smltxt"><?= e($a['shrnuti']) ?></span>
<?php if ($teamExists): ?>
		<br><span class="smltxt"><?= isset($linked[(int) $a['idu']]) ? e(t($linked[(int) $a['idu']]['chosen'] ? 'Team record: %s (chosen)' : 'Team record: %s (by e-mail)', $linked[(int) $a['idu']]['name'])) : e(t('Team record: not linked')) ?></span>
<?php endif ?></td>
	<td><?= bdi($a['email']) ?></td>
	<td><?= e($a['nazev_role'] ?? t(Kaleta\Core\Auth::TYPES[(int) $a['admin']] ?? '?')) ?></td>
	<td class="cislo"><?= (int) $a['pocet_clanku'] ?></td>
	<td class="cislo"><?= e(format_date($a['posledni_login'], true)) ?: '-' ?></td>
	<td class="akce">
		<a href="<?= e($module->url('edit', ['id' => $a['idu']])) ?>"><?= e(t('Edit')) ?></a>
<?php if ($a['blokovat']): ?>
		/ <form class="vradku" method="post" action="<?= e($module->url('reactivate')) ?>"><?= $csrf ?><input type="hidden" name="idu" value="<?= (int) $a['idu'] ?>"><input type="hidden" name="user" value="<?= e($a['user']) ?>"><button class="navigace" type="submit"><?= e(t('Reactivate')) ?></button></form>
<?php endif ?>
<?php if ((int) $a['idu'] !== $app->auth()->id()): ?>
		/ <form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Really delete the user? Their news items will remain, without an author.')) ?>"><?= $csrf ?><input type="hidden" name="idu" value="<?= (int) $a['idu'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
