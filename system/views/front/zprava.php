<?php
/**
 * Jednoduchá stránka se zprávou (potvrzení odběru, odhlášení...).
 *
 * @var string $nadpis
 * @var string $text
 * @var callable(string): string $url
 */
?>
<article class="clanek clanek-cely">
	<header class="clanek-hlavicka obal-uzky"><h1><?= e($nadpis) ?></h1></header>
	<div class="clanek-text obal-uzky"><p><?= e($text) ?></p><p><a href="<?= e($url('')) ?>"><?= e(t('Zpět na hlavní stránku')) ?></a></p></div>
</article>
