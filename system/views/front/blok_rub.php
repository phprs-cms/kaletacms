<?php
/**
 * Systémový blok: seznam rubrik.
 *
 * @var list<array<string, mixed>> $rubriky  strom do hloubky, klíč "uroven"
 * @var callable(string): string $url
 */
?>
<ul class="rubriky">
<?php foreach ($rubriky as $r): ?>
	<li class="uroven-<?= (int) $r['uroven'] ?>"><a href="<?= e($url('rubrika/' . $r['seo_link'])) ?>"><?= e($r['nazev']) ?></a></li>
<?php endforeach ?>
</ul>
