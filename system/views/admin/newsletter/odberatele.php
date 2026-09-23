<?php
/**
 * @var MiroCMS\Admin\Moduly\NewsletterAdmin $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $odberatele
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na newsletter')) ?></a> <a class="navigace" href="<?= e($modul->url('odberatele', ['format' => 'csv'])) ?>"><?= e(t('Stáhnout CSV')) ?></a></p>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Přihlášen')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($odberatele as $o): ?>
<tr><td><?= e($o['email']) ?></td><td class="cislo"><?= e(datum($o['prihlasen'], true)) ?></td>
	<td><span class="stitek stitek-<?= $o['potvrzen'] ? 'vydano' : 'koncept' ?>"><?= e(t($o['potvrzen'] ? 'odebírá' : 'nepotvrdil')) ?></span></td>
	<td class="akce"><form class="vradku" method="post" action="<?= e($modul->url('smaz_odberatele')) ?>" data-potvrdit="<?= e(t('Odstranit odběratele?')) ?>"><?= $csrf ?><input type="hidden" name="ido" value="<?= (int) $o['ido'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td></tr>
<?php endforeach ?>
</tbody></table></div>
