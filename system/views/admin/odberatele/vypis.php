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
 * @var string $sluzba napojená mailingová služba (prázdné = žádná)
 * @var array{ceka: ?string, chyby: ?string} $fronta
 */
use Kaleta\Core\Newsletter;

$spravce = $app->auth()->isAdmin();
?>
<p class="smltxt"><?= e(t('Adresy z prvku Odběr novinek. Za odběratele se počítá, kdo přihlášení potvrdil odkazem v e-mailu. Rozesílejte svým nástrojem – export obsahuje i odkaz na odhlášení.')) ?></p>
<?php if ($sluzba !== ''): ?>
<div class="hlaska">
	<p><?= e(t('Potvrzení odběratelé jdou automaticky do služby %s, odhlášení se z ní odebírají.', t(Newsletter::SLUZBY[$sluzba][0]))) ?>
	<?= (int) $fronta['ceka'] > 0 ? e(t('Čeká na odeslání: %d.', (int) $fronta['ceka'])) : '' ?> <?= (int) $fronta['chyby'] > 0 ? '<strong>' . e(t('Nepovedlo se: %d.', (int) $fronta['chyby'])) . '</strong>' : '' ?></p>
<?php if ($spravce): ?>
	<p><form class="vradku" method="post" action="<?= e($modul->url('synchronizuj')) ?>"><?= $csrf ?><button class="navigace" type="submit"><?= e(t('Poslat do služby všechny potvrzené')) ?></button></form>
	<?php if ((int) $fronta['chyby'] > 0): ?><form class="vradku" method="post" action="<?= e($modul->url('znovu')) ?>"><?= $csrf ?><button class="navigace" type="submit"><?= e(t('Zkusit nepovedené znovu')) ?></button></form><?php endif ?>
	<a href="<?= e($app->url('admin.php?modul=rozsireni#newsletter')) ?>"><?= e(t('Nastavení služby')) ?></a></p>
<?php endif ?>
</div>
<?php elseif ($spravce): ?>
<p class="smltxt"><?= e(t('Používáte Brevo, MailerLite, Mailchimp, Ecomail nebo SmartEmailing? Po napojení v Rozšíření posílá web potvrzené odběratele rovnou do vašeho seznamu.')) ?> <a href="<?= e($app->url('admin.php?modul=rozsireni#newsletter')) ?>"><?= e(t('Napojit službu')) ?></a></p>
<?php endif ?>
<form class="navigace-radek" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="modul" value="odberatele">
	<input class="textpole" type="search" name="hledat" value="<?= e($hledat) ?>" placeholder="<?= e(t('Hledat e-mail')) ?>" aria-label="<?= e(t('Hledat e-mail')) ?>">
	<button class="navigace" type="submit"><?= e(t('Filtrovat')) ?></button>
<?php if ($potvrzenych > 0): ?>
	<a class="tl" href="<?= e($modul->url('csv')) ?>"><?= e(t('Export potvrzených (CSV)')) ?> · <?= $potvrzenych ?></a>
<?php else: ?>
	<button class="tl" type="button" disabled><?= e(t('Export potvrzených (CSV)')) ?> · 0</button>
<?php endif ?>
</form>
<?php if ($odberatele === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'newsletter', 'nadpis' => t($hledat !== '' ? 'Nic nenalezeno.' : 'Zatím žádní odběratelé.'), 'text' => t('Vložte na web prvek Odběr novinek – třeba do patičky.')]) ?>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><?php if ($sluzba !== ''): ?><th scope="col"><?= e(t('Služba')) ?></th><?php endif ?><th scope="col"><?= e(t('Přihlášen')) ?></th><th scope="col"><?= e(t('Stránka')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($odberatele as $o): ?>
<tr<?= (int) $o['stav'] === 1 ? '' : ' class="nevydany"' ?>>
	<td><?= e($o['email']) ?></td>
	<td><?= (int) $o['stav'] === 1 ? '<span class="stitek stitek-vydano">' . e(t('potvrzený')) . '</span>' : e(t('čeká na potvrzení')) ?></td>
<?php if ($sluzba !== ''): ?>
	<td><?= match ((string) $o['sync']) {
        'ok' => '<span class="stitek stitek-vydano">' . e(t('odesláno')) . '</span>',
        'ceka' => '<span class="stitek">' . e(t('čeká')) . '</span>',
        'chyba' => '<span class="stitek stitek-koncept" title="' . e(t((string) $o['sync_chyba'])) . '">' . e(t('chyba')) . '</span> <span class="napoveda">' . e(mb_strimwidth(t((string) $o['sync_chyba']), 0, 80, '…')) . '</span>',
        default => '—',
    } ?></td>
<?php endif ?>
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
