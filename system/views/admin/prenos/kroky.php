<?php
/**
 * Ukazatel tří kroků importu (soubor → náhled → import).
 *
 * @var int $krok  právě probíhající krok 1–3
 */
$kroky = [1 => 'Soubor', 2 => 'Náhled', 3 => 'Import'];
?>
<ol class="prenos-kroky">
<?php foreach ($kroky as $cislo => $nazev): ?>
	<li<?= $cislo === $krok ? ' class="aktivni" aria-current="step"' : ($cislo < $krok ? ' class="hotovy"' : '') ?>><span><?= $cislo ?></span> <?= e(t($nazev)) ?></li>
<?php endforeach ?>
</ol>
