<?php
/**
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Komentare $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $komentare
 * @var bool $cekajici
 * @var int $pocetCekajicich
 * @var int $strana
 * @var int $stran
 */
?>
<nav class="zalozky" aria-label="<?= e(t('Stav komentářů')) ?>">
	<a href="<?= e($modul->url()) ?>"<?= $cekajici ? '' : ' class="aktivni"' ?>><?= e(t('Všechny')) ?></a>
	<a href="<?= e($modul->url('', ['stav' => 'cekajici'])) ?>"<?= $cekajici ? ' class="aktivni"' : '' ?>><?= e(t('Čekají na schválení (%s)', $pocetCekajicich)) ?></a>
</nav>
<?php if ($komentare === []): ?>
<?= $app->view->render('admin/prazdno', ['ikona' => 'komentare', 'nadpis' => t('Žádné komentáře.'), 'text' => t('Komentáře čtenářů se tu objeví hned, jak je někdo pod článkem napíše. Ty, které čekají na schválení, uvidíte nahoře.')]) ?>
<?php else: ?>
<form method="post" action="<?= e($modul->url('hromadne')) ?>">
<?= $csrf ?>
<input type="hidden" name="stav" value="<?= $cekajici ? 'cekajici' : '' ?>">
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"></th><th scope="col"><?= e(t('Komentář')) ?></th><th scope="col"><?= e(t('Autor')) ?></th><th scope="col"><?= e(t('Článek')) ?></th><th scope="col"><?= e(t('Datum')) ?></th><th scope="col"><?= e(t('Stav')) ?></th></tr></thead>
<tbody>
<?php foreach ($komentare as $k): ?>
<tr<?= $k['zobrazit'] ? '' : ' class="nevydany"' ?>>
	<td class="stred"><input type="checkbox" name="oznacene[]" value="<?= (int) $k['idk'] ?>" aria-label="<?= e(t('Označit komentář od %s', (string) $k['od'])) ?>"></td>
	<td style="max-width:420px; overflow-wrap:anywhere"><?= nl2br(e(mb_strimwidth($k['obsah'], 0, 400, '…'))) ?></td>
	<td><?= e($k['od']) ?><?= $k['od_mail'] !== '' ? '<br><small>' . e($k['od_mail']) . '</small>' : '' ?><?= $k['od_ip'] !== '' ? '<br><small title="' . e(t('Otisk adresy pisatele – stejný otisk znamená stejného pisatele. Samotná IP adresa se neukládá.')) . '">#' . e(substr((string) $k['od_ip'], 0, 8)) . '</small>' : '' ?><?= (int) $k['nahlaseno'] > 0 ? '<br><span class="stitek stitek-koncept">' . e(t('nahlášeno %s×', (int) $k['nahlaseno'])) . '</span>' : '' ?><?= $k['idct'] !== null ? '<br><small>' . e(t('registrovaný čtenář')) . '</small>' : '' ?></td>
	<td><a href="<?= e($app->url('clanek/' . $k['seo_link'] . '#komentare')) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($k['titulek'], 0, 60, '…')) ?></a></td>
	<td class="cislo"><?= e(datum($k['datum'], true)) ?></td>
	<td><span class="stitek stitek-<?= $k['zobrazit'] ? 'vydano' : 'koncept' ?>"><?= e(t($k['zobrazit'] ? 'zveřejněný' : 'čeká / skrytý')) ?></span></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-hromadne"><?= e(t('S označenými:')) ?>
	<button class="tl" type="submit" name="provest" value="schvalit"><?= e(t('Schválit')) ?></button>
	<button class="navigace" type="submit" name="provest" value="skryt"><?= e(t('Skrýt')) ?></button>
	<button class="navigace nebezpecne" type="submit" name="provest" value="smazat" data-potvrdit="<?= e(t('Opravdu smazat označené komentáře? Smažou se i reakce na ně.')) ?>"><?= e(t('Smazat')) ?></button>
</p>
</form>
<?php if ($stran > 1): ?>
<p class="strankovani"><?php for ($s = 1; $s <= $stran; $s++): ?><?= $s === $strana ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($modul->url('', array_filter(['stav' => $cekajici ? 'cekajici' : '', 'strana' => $s]))) . '">' . $s . '</a>' ?> <?php endfor ?></p>
<?php endif ?>
<?php endif ?>
