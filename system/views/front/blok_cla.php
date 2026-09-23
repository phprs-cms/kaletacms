<?php
/**
 * Blok: články z rubriky (nebo nejnovější ze všech).
 *
 * @var list<array<string, mixed>> $clanky
 * @var callable(string): string $url
 */
?>
<ul class="blok-clanky">
<?php foreach ($clanky as $c): ?>
	<li><a href="<?= e($url('clanek/' . $c['seo_link'])) ?>"><?= e($c['titulek']) ?></a> <small><?= e(datum($c['datum'])) ?></small></li>
<?php endforeach ?>
</ul>
