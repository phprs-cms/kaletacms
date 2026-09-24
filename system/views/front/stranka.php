<?php
/**
 * Stránka webu (O nás, Služby, Kontakt…). Na úvodní stránce se nadpis nevypisuje – úvod si nese vlastní obsah.
 *
 * @var array<string, mixed> $stranka
 * @var bool $uvod  stránka je úvodem webu
 */
?>
<article class="stranka<?= $uvod ? ' stranka-uvod' : '' ?>">
<?php if (!$uvod): ?>
	<h1><?= e($stranka['titulek']) ?></h1>
<?php endif ?>
	<div class="text"><?= $stranka['text'] ?></div>
</article>
