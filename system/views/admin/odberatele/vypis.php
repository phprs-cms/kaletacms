<?php
/**
 * @var Kaleta\Admin\Moduly\Odberatele $modul
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $odberatele
 * @var int $celkem
 * @var int $potvrzenych
 * @var string $hledat
 * @var int $strana
 */
?>
<p class="smltxt"><?= e(t('Adresy z prvku Odběr novinek. Za odběratele se počítá, kdo přihlášení potvrdil odkazem v e-mailu. Rozesílejte svým nástrojem – export obsahuje i odkaz na odhlášení.')) ?></p>
<form class="navigace-radek" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="modul" value="odberatele">
	<input class="textpole" type="search" name="hledat" value="<?= e($hledat) ?>" placeholder="<?= e(t('Hledat e-mail')) ?>" aria-label="<?= e(t('Hledat e-mail')) ?>">
	<button class="navigace" type="submit"><?= e(t('Hledat')) ?></button>
	<a class="tl" href="<?= e($modul->url('csv')) ?>"><?= e(t('Export potvrzených (CSV)')) ?> · <?= $potvrzenych ?></a>
</form>
<?php if ($odberatele === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'poptavky', 'nadpis' => t($hledat !== '' ? 'Nic nenalezeno.' : 'Zatím žádní odběratelé.'), 'text' => t('Vložte na web prvek Odběr novinek – třeba do patičky.')]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Přihlášen')) ?></th><th scope="col"><?= e(t('Stránka')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($odberatele as $o): ?>
<tr<?= (int) $o['stav'] === 1 ? '' : ' class="nevydany"' ?>>
	<td><?= e($o['email']) ?></td>
	<td><?= (int) $o['stav'] === 1 ? '<span class="stitek stitek-vydano">' . e(t('potvrzený')) . '</span>' : e(t('čeká na potvrzení')) ?></td>
	<td class="cislo"><?= e(datum($o['datum'], true)) ?></td>
	<td class="smltxt"><?= e($o['zdroj']) ?></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Smazat adresu ze seznamu odběratelů?')) ?>"><?= $csrf ?><input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php if ($celkem > 100): ?>
<p class="navigace-radek">
<?php if ($strana > 1): ?><a class="navigace" href="<?= e($modul->url('', ['hledat' => $hledat, 'strana' => $strana - 1])) ?>"><?= e(t('Předchozí')) ?></a><?php endif ?>
<?php if ($strana * 100 < $celkem): ?><a class="navigace" href="<?= e($modul->url('', ['hledat' => $hledat, 'strana' => $strana + 1])) ?>"><?= e(t('Další')) ?></a><?php endif ?>
</p>
<?php endif ?>
<?php endif ?>
