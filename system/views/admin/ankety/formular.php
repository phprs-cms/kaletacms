<?php
/**
 * @var MiroCMS\Admin\Moduly\Ankety $modul
 * @var string $csrf
 * @var array<string, mixed> $anketa
 * @var list<array<string, mixed>> $odpovedi
 * @var bool $aktivni
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět na přehled')) ?></a></p>
<form class="formular" method="post" action="<?= e($modul->url('uloz')) ?>">
<?= $csrf ?>
<input type="hidden" name="ida" value="<?= (int) $anketa['ida'] ?>">
<div class="radek"><label for="otazka"><?= e(t('Otázka')) ?></label><input class="textpole siroke" type="text" id="otazka" name="otazka" value="<?= e($anketa['otazka']) ?>" maxlength="255" required></div>
<?= $app->view->render('admin/jazyk_pole', ['app' => $app, 'hodnota' => (string) ($anketa['jazyk'] ?? ''), 'napoveda' => t('Anketa se ukáže jen v této jazykové verzi webu. V jazyce, který nemá aktivní anketu, se zobrazí nejnovější otevřená.')]) ?>
<div class="radek">
	<span class="popisek"><?= e(t('Odpovědi')) ?></span>
	<div>
<?php foreach ($odpovedi as $o): ?>
		<p style="margin:0 0 6px"><input class="textpole" type="text" name="odpoved[<?= (int) $o['ido'] ?>]" value="<?= e($o['odpoved']) ?>" maxlength="255" style="width:70%" aria-label="<?= e(t('Odpověď')) ?>"> <small><?= e(t('%s hlasů', (int) $o['pocitadlo'])) ?></small></p>
<?php endforeach ?>
<?php for ($i = 0; $i < ($odpovedi === [] ? 4 : 2); $i++): ?>
		<p style="margin:0 0 6px"><input class="textpole" type="text" name="nova[]" maxlength="255" style="width:70%" placeholder="<?= e(t('nová odpověď')) ?>" aria-label="<?= e(t('Nová odpověď')) ?>"></p>
<?php endfor ?>
		<span class="napoveda"><?= e(t('Odpověď smažete vymazáním jejího textu. Další pole pro odpovědi přibudou po uložení.')) ?></span>
	</div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Zobrazení')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="aktivni" value="1"<?= $aktivni ? ' checked' : '' ?>> <?= e(t('Zobrazit tuto anketu na webu (nahradí dosavadní)')) ?></label><br>
		<label><input type="checkbox" name="zobrazit" value="1"<?= $anketa['zobrazit'] ? ' checked' : '' ?>> <?= e(t('Anketa je veřejná')) ?></label><br>
		<label><input type="checkbox" name="uzavrena" value="1"<?= $anketa['uzavrena'] ? ' checked' : '' ?>> <?= e(t('Uzavřít hlasování – zobrazovat jen výsledky')) ?></label>
	</div>
</div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Uložit')) ?>"></p>
</form>
