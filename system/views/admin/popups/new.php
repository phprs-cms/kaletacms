<?php
/**
 * A new popup from a ready-made template.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Popups $module
 * @var string $csrf
 */
use Kaleta\Builder\Popups;

?>
<form class="formular" method="post" action="<?= e($module->url('create')) ?>">
<?= $csrf ?>
<div class="radek"><label for="nazev"><?= e(t('Pop-up name')) ?></label><div><input class="textpole siroke" id="nazev" name="nazev" maxlength="100" placeholder="<?= e(t('e.g. Newsletter on the blog')) ?>"><span class="napoveda"><?= e(t('Only for you in the admin and for screen readers. The address #popup-… is made from the name.')) ?></span></div></div>
<fieldset>
<legend><?= e(t('Start from')) ?></legend>
<div class="volby">
<?php $first = true; foreach (Popups::LIBRARY as $key => [$name, $description, $type]): ?>
<label><input type="radio" name="vzor" value="<?= e($key) ?>"<?= $first ? ' checked' : '' ?>> <strong><?= e(t($name)) ?></strong> (<?= e(mb_strtolower(t(Popups::TYPES[$type][0]))) ?>) – <?= e(t($description)) ?></label>
<?php $first = false; endforeach ?>
</div>
</fieldset>
<p class="napoveda"><?= e(t('The pop-up is created turned off and opens in the builder. It shows on the site once you publish it and turn it on.')) ?></p>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Create and open in the builder')) ?>"> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
