<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Autori $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $autori
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nový uživatel')) ?></a></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Uživatel')) ?></th><th scope="col"><?= e(t('Jméno')) ?></th><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Role')) ?></th><th scope="col"><?= e(t('Novinek')) ?></th><th scope="col"><?= e(t('Poslední přihlášení')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($autori as $a): ?>
<tr<?= $a['blokovat'] ? ' class="nevydany"' : '' ?>>
	<td><a href="<?= e($modul->url('edit', ['id' => $a['idu']])) ?>"><?= e($a['user']) ?></a><?= $a['blokovat'] ? ' <strong>(' . e(t('blokován')) . ')</strong>' : '' ?><?= $a['totp_tajemstvi'] !== '' ? ' <span class="stitek stitek-vydano" title="' . e(t('dvoufázové přihlášení')) . '">2FA</span>' : '' ?></td>
	<td><?= e($a['jmeno']) ?><br><span class="smltxt"><?= e($a['shrnuti']) ?></span></td>
	<td><?= e($a['email']) ?></td>
	<td><?= e(t(Kaleta\Core\Auth::TYPY[(int) $a['admin']] ?? '?')) ?></td>
	<td class="cislo"><?= (int) $a['pocet_clanku'] ?></td>
	<td class="cislo"><?= e(datum($a['posledni_login'], true)) ?: '-' ?></td>
	<td class="akce">
		<a href="<?= e($modul->url('edit', ['id' => $a['idu']])) ?>"><?= e(t('Upravit')) ?></a>
<?php if ((int) $a['idu'] !== $app->auth()->id()): ?>
		/ <form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Opravdu smazat uživatele? Jeho novinky zůstanou zachované bez autora.')) ?>"><?= $csrf ?><input type="hidden" name="idu" value="<?= (int) $a['idu'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
