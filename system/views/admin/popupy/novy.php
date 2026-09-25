<?php
/**
 * Nové pop-up okno z hotového vzoru.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Moduly\Popupy $modul
 * @var string $csrf
 */
use Kaleta\Stavitel\Popupy;

?>
<form class="formular" method="post" action="<?= e($modul->url('zaloz')) ?>">
<?= $csrf ?>
<div class="radek"><label for="nazev"><?= e(t('Název okna')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" maxlength="100" placeholder="<?= e(t('např. Newsletter na blogu')) ?>"><span class="napoveda"><?= e(t('Jen pro vás v administraci a pro čtečky obrazovky. Z názvu vznikne adresa #popup-…')) ?></span></div></div>
<fieldset>
<legend><?= e(t('Začít od')) ?></legend>
<div class="volby">
<?php $prvni = true; foreach (Popupy::KNIHOVNA as $klic => [$nazev, $popis, $typ]): ?>
<label><input type="radio" name="vzor" value="<?= e($klic) ?>"<?= $prvni ? ' checked' : '' ?>> <strong><?= e(t($nazev)) ?></strong> (<?= e(mb_strtolower(t(Popupy::TYPY[$typ][0]))) ?>) – <?= e(t($popis)) ?></label>
<?php $prvni = false; endforeach ?>
</div>
</fieldset>
<p class="napoveda"><?= e(t('Okno se založí vypnuté a otevře se v builderu. Na webu se ukáže, až ho publikujete a zapnete.')) ?></p>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Založit a otevřít v builderu')) ?>"> <a class="navigace" href="<?= e($modul->url()) ?>"><?= e(t('Zpět')) ?></a></p>
</form>
