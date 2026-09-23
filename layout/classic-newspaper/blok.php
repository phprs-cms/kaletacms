<?php
/**
 * @var string $nadpis
 * @var string $obsah  HTML
 * @var int $typ       vzhled: 1 běžný, 2 podbarvený, 3 zvýrazněný nadpis, 4 v rámečku, 5 bez nadpisu
 * @var string $zona   zóna, ve které se blok vykresluje
 * @var string $sys    zkratka systémového bloku, u běžného prázdná
 */
?>
<section class="blok blok-typ<?= $typ ?><?= $sys !== '' ? ' blok-' . e($sys) : '' ?>">
	<h2 class="blok-nadpis"><?= e($nadpis) ?></h2>
	<div class="blok-obsah">
<?= $obsah ?>
	</div>
</section>
