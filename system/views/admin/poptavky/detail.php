<?php
/**
 * Detail poptávky.
 *
 * @var MiroCMS\Core\App $app
 * @var MiroCMS\Admin\Moduly\Poptavky $modul
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array{0:string, 1:string}> $data
 */
use MiroCMS\Admin\Moduly\Poptavky;

?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>">← <?= e(t('Všechny poptávky')) ?></a></p>
<div class="formular">
<dl class="poptavka">
	<dt><?= e(t('Přijato')) ?></dt><dd><?= e(datum($p['datum'], true)) ?> · <?= e($p['formular']) ?><?php if ($p['stranka'] !== ''): ?> · <a href="<?= e($p['stranka']) ?>" target="_blank" rel="noopener"><?= e($p['stranka']) ?></a><?php endif ?></dd>
	<dt><?= e(t('Stav')) ?></dt><dd><?= e(t(Poptavky::STAVY[(int) $p['stav']])) ?></dd>
<?php foreach ($data as [$popisek, $hodnota]): ?>
	<dt><?= e($popisek) ?></dt><dd><?= $hodnota === '' ? '<span class="napoveda">—</span>' : nl2br(e($hodnota)) ?></dd>
<?php endforeach ?>
</dl>
<div class="tlacitka">
<?php if ($p['email'] !== ''): ?>
	<a class="tl" href="mailto:<?= e($p['email']) ?>?subject=<?= e(rawurlencode('Re: ' . $p['formular'])) ?>"><?= e(t('Odpovědět e-mailem')) ?></a>
<?php endif ?>
	<form class="vradku" method="post" action="<?= e($modul->url('stav')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><input type="hidden" name="stav" value="<?= (int) $p['stav'] === 2 ? 1 : 2 ?>"><button class="tl<?= (int) $p['stav'] === 2 ? ' tl-vedlejsi' : '' ?>" type="submit"><?= e(t((int) $p['stav'] === 2 ? 'Znovu otevřít' : 'Označit jako vyřízenou')) ?></button></form>
	<form class="vradku" method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Opravdu smazat poptávku?')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
</div>
</div>
