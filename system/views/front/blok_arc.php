<?php
/**
 * Blok: archiv po měsících.
 *
 * @var list<array{mesic:string, pocet:int}> $mesice
 * @var callable(string): string $url
 */
$nazvy = [1 => 'leden', 'únor', 'březen', 'duben', 'květen', 'červen', 'červenec', 'srpen', 'září', 'říjen', 'listopad', 'prosinec'];
?>
<ul class="blok-clanky">
<?php foreach ($mesice as $m): ?>
	<li><a href="<?= e($url('archiv/' . $m['mesic'])) ?>"><?= e(t($nazvy[(int) substr($m['mesic'], 5)]) . ' ' . substr($m['mesic'], 0, 4)) ?></a> <small>(<?= (int) $m['pocet'] ?>)</small></li>
<?php endforeach ?>
</ul>
