<?php
/**
 * Přihlášení odkazem z e-mailu - potvrzení tlačítkem (odkaz sám nepřihlašuje, viz Front\Ctenari::vstupOdkazem).
 *
 * @var string $akce
 * @var string $email
 */
?>
<article class="clanek clanek-cely mc-ucet">
	<header class="clanek-hlavicka obal-uzky"><h1><?= e(t('Přihlášení čtenáře')) ?></h1></header>
	<div class="obal-uzky">
	<form class="mc-formular" method="post" action="<?= e($akce) ?>">
		<p><?= e(t('Přihlásit jako')) ?> <strong><?= e($email) ?></strong>?</p>
		<button type="submit"><?= e(t('Přihlásit se')) ?></button>
	</form>
	</div>
</article>
