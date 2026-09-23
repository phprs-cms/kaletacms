<?php
/**
 * Blok: otvírák - velká upoutávka na připnutý nebo nejnovější článek.
 *
 * @var array<string, mixed> $clanek
 * @var callable(string): string $url
 */
?>
<a class="blok-otvirak" href="<?= e($url('clanek/' . $clanek['seo_link'])) ?>">
<?php if ($clanek['obrazek'] !== ''): ?>
	<img src="<?= e($clanek['obrazek']) ?>"<?= ($clanek['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($clanek['obrazek_srcset']) . '" sizes="(max-width: 900px) 100vw, 600px"' : '' ?> alt="" loading="lazy">
<?php endif ?>
	<strong><?= e($clanek['titulek']) ?></strong>
	<span><?= e(mb_strimwidth(trim(strip_tags($clanek['uvod'])), 0, 160, '…')) ?></span>
</a>
