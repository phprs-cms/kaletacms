<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Ankety $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $ankety
 * @var int $aktivni
 * @var bool $maBlok
 */
?>
<p class="navigace-radek"><a class="tl" href="<?= e($modul->url('novy')) ?>"><?= e(t('Nová anketa')) ?></a></p>
<?php if (!$maBlok): ?>
<p class="hlaska"><?= e(t('Anketa se na webu zobrazuje v bloku „Anketa“. Zatím ho nemáte – přidejte ho v sekci')) ?> <a href="<?= e($app->url('admin.php?modul=bloky&akce=novy&sys=ank')) ?>"><?= e(t('Bloky a rozvržení')) ?></a>.</p>
<?php endif ?>
<?php if ($ankety === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'ankety', 'nadpis' => t('Zatím žádná anketa.'), 'text' => t('Anketa je jedna otázka s několika odpověďmi; na webu běží vždy ta aktuální.'), 'akce' => [$modul->url('novy'), t('Založit první anketu')]]) ?>
<?php endif ?>
<?php if ($ankety !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Otázka')) ?></th><th scope="col"><?= e(t('Hlasů')) ?></th><th scope="col"><?= e(t('Stav')) ?></th><th scope="col"><?= e(t('Vytvořena')) ?></th><th scope="col"><?= e(t('Akce')) ?></th></tr></thead>
<tbody>
<?php foreach ($ankety as $a): ?>
<tr>
	<td><a href="<?= e($modul->url('edit', ['id' => $a['ida']])) ?>"><?= e($a['otazka']) ?></a></td>
	<td class="cislo"><?= (int) $a['hlasu'] ?></td>
	<td><?php if ((int) $a['ida'] === $aktivni): ?><span class="stitek stitek-vydano"><?= e(t('na webu')) ?></span> <?php endif ?><?php if ($a['uzavrena']): ?><span class="stitek"><?= e(t('uzavřená')) ?></span><?php endif ?></td>
	<td class="cislo"><?= e(datum($a['datum'])) ?></td>
	<td class="akce"><a href="<?= e($modul->url('edit', ['id' => $a['ida']])) ?>"><?= e(t('Upravit')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Opravdu smazat anketu i s hlasy?')) ?>"><?= $csrf ?><input type="hidden" name="ida" value="<?= (int) $a['ida'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
