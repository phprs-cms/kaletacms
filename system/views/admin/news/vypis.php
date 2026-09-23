<?php
/**
 * Novinky: formulář (nová / úprava) a pod ním výpis.
 *
 * @var MiroCMS\Admin\Moduly\Novinky $modul
 * @var string $csrf
 * @var list<array<string, mixed>> $novinky
 * @var array<string, mixed> $novinka
 */
?>
<?php if ($novinka['idn']): ?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<?php endif ?>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<input type="hidden" name="idn" value="<?= (int) $novinka['idn'] ?>">
<div class="radek">
	<label for="titulek"><?= e(t('Titulek novinky')) ?></label>
	<input class="textpole siroke" type="text" id="titulek" name="titulek" value="<?= e($novinka['titulek']) ?>" maxlength="150" required>
</div>
<?= $app->view->render('admin/jazyk_pole', ['app' => $app, 'hodnota' => (string) ($novinka['jazyk'] ?? ''), 'napoveda' => t('Novinka se ukáže jen v této jazykové verzi webu.')]) ?>
<div class="radek">
	<label for="informace"><?= e(t('Text novinky')) ?></label>
	<div><textarea class="textbox" id="informace" name="informace" rows="4"><?= e($novinka['informace']) ?></textarea>
	<span class="napoveda"><?= e(t('Krátká zpráva, HTML je povoleno.')) ?></span></div>
</div>
<div class="radek">
	<label for="datum"><?= e(t('Datum')) ?></label>
	<input class="textpole" type="datetime-local" id="datum" name="datum" value="<?= e(date('Y-m-d\TH:i', strtotime($novinka['datum']))) ?>">
</div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($novinka['idn'] ? 'Uložit' : 'Přidat')) ?>"></p>
</form>

<?php if ($novinky !== []): ?>
<h3 class="stred"><?= e(t('Výpis novinek')) ?></h3>
<form method="post" action="<?= e($modul->url('smaz')) ?>" data-potvrdit="<?= e(t('Opravdu vymazat všechny označené novinky?')) ?>">
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Datum')) ?></th><th scope="col"><?= e(t('Titulek')) ?></th><th scope="col"><?= e(t('Text')) ?></th><th scope="col"><?= e(t('Akce')) ?></th><th scope="col"><?= e(t('Smazat')) ?></th></tr></thead>
<tbody>
<?php foreach ($novinky as $n): ?>
<tr>
	<td class="cislo"><?= e(datum($n['datum'], true)) ?></td>
	<td><?= e($n['titulek']) ?></td>
	<td><?= e(mb_strimwidth(strip_tags($n['informace']), 0, 120, '…')) ?></td>
	<td class="akce"><a href="<?= e($modul->url('edit', ['id' => $n['idn']])) ?>"><?= e(t('Upravit')) ?></a></td>
	<td class="stred"><input type="checkbox" name="smaz[]" value="<?= (int) $n['idn'] ?>" aria-label="<?= e(t('Označit ke smazání: %s', $n['titulek'])) ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="stred"><input class="tl" type="submit" value="<?= e(t('Smazat označené')) ?>"></p>
</form>
<?php endif ?>
