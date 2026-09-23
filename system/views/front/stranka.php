<?php
/**
 * Statická stránka (O nás, Kontakt...). Používá stejné třídy jako celý článek, takže ji layouty umí vysázet.
 *
 * @var array<string, mixed> $stranka
 */
?>
<article class="clanek clanek-cely stranka-staticka">
	<header class="clanek-hlavicka obal-uzky"><h1><?= e($stranka['titulek']) ?></h1></header>
	<div class="clanek-text obal-uzky"><?= $stranka['text'] ?></div>
</article>
