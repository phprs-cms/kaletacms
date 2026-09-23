<?php
/**
 * Systémový blok: nejčtenější články.
 *
 * @var list<array<string, mixed>> $clanky
 * @var callable(string): string $url
 */
?>
<?php if ($clanky !== []): ?>
<ol class="nejctenejsi">
<?php foreach ($clanky as $c): ?>
	<li><a href="<?= e($url('clanek/' . $c['seo_link'])) ?>"><?= e($c['titulek']) ?></a> <small>(<?= (int) $c['visit'] ?>&times;)</small></li>
<?php endforeach ?>
</ol>
<?php endif ?>
