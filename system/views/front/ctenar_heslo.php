<?php
/**
 * Nastavení nového hesla čtenáře (odkaz z e-mailu).
 *
 * @var string $akce
 * @var bool $chyba
 */
?>
<article class="clanek clanek-cely mc-ucet">
	<header class="clanek-hlavicka obal-uzky"><h1><?= e(t('Nové heslo')) ?></h1></header>
	<div class="obal-uzky">
<?php if ($chyba): ?>
	<p class="mc-zprava mc-zprava-chyba" role="alert"><?= e(t('Heslo musí mít aspoň 8 znaků.')) ?></p>
<?php endif ?>
	<form class="mc-formular" method="post" action="<?= e($akce) ?>">
		<label><?= e(t('Nové heslo')) ?> <small><?= e(t('(aspoň 8 znaků)')) ?></small> <input type="password" name="heslo" required minlength="8" autocomplete="new-password"></label>
		<button type="submit"><?= e(t('Nastavit heslo a přihlásit')) ?></button>
	</form>
	</div>
</article>
