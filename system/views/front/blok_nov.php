<?php
/**
 * Systémový blok: novinky.
 *
 * @var list<array<string, mixed>> $novinky
 */
?>
<?php foreach ($novinky as $n): ?>
<div class="novinka">
	<time datetime="<?= e(date('c', strtotime($n['datum']))) ?>"><?= e(datum($n['datum'])) ?></time>
	<strong><?= e($n['titulek']) ?></strong>
	<div><?= $n['informace'] ?></div>
</div>
<?php endforeach ?>
