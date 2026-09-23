<?php
/**
 * Blok: autoři.
 *
 * @var list<array<string, mixed>> $autori
 * @var callable(string): string $url
 */
?>
<ul class="blok-clanky">
<?php foreach ($autori as $a): ?>
	<li><a href="<?= e($url('autor/' . (int) $a['idu'])) ?>"><?= e($a['jmeno']) ?></a> <small>(<?= (int) $a['pocet'] ?>)</small></li>
<?php endforeach ?>
</ul>
