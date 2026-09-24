<?php
/**
 * Celá novinka. Layout ji může přepsat vlastním souborem novinka.php.
 *
 * @var array<string, mixed> $novinka  sloupce ka_novinky + tema_jm, tema_seo, autor_jm, stitky (nazev, seo_link),
 *                                     obrazek_srcset, obrazek_alt, obrazek_popisek_html, faq_html - hotové HTML, stačí vypsat
 * @var callable(string): string $url
 * @var list<array<string, mixed>> $souvisejici
 */
?>
<article class="novinka">
	<header>
		<p class="novinka-info">
			<time datetime="<?= e(date('c', strtotime($novinka['datum']))) ?>"><?= e(datum($novinka['datum'])) ?></time>
			· <a href="<?= e($url('novinky/kategorie/' . $novinka['tema_seo'])) ?>"><?= e($novinka['tema_jm']) ?></a>
<?php if ($novinka['autor_jm'] !== null): ?>
			· <?= e($novinka['autor_jm']) ?>
<?php endif ?>
		</p>
		<h1><?= e($novinka['titulek']) ?></h1>
	</header>
<?php if ($novinka['obrazek'] !== ''): ?>
	<figure class="novinka-obrazek"><img src="<?= e($novinka['obrazek']) ?>"<?= ($novinka['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($novinka['obrazek_srcset']) . '" sizes="(max-width: 900px) 100vw, 900px"' : '' ?> alt="<?= e($novinka['obrazek_alt'] ?? '') ?>" fetchpriority="high"><?= $novinka['obrazek_popisek_html'] ?? '' ?></figure>
<?php endif ?>
	<div class="perex"><?= $novinka['uvod'] ?></div>
<?php if (!empty($novinka['aktualizovano'])): ?>
	<p class="novinka-aktualizovano"><?= e(t('Aktualizováno')) ?> <?= e(datum($novinka['aktualizovano'], true)) ?></p>
<?php endif ?>
	<div class="text"><?= $novinka['text'] ?></div>
	<?= $novinka['faq_html'] ?? '' ?>
<?php if (!empty($novinka['stitky'])): ?>
	<p class="novinka-stitky"><?php foreach ($novinka['stitky'] as $st): ?><a href="<?= e($url('novinky/stitek/' . $st['seo_link'])) ?>" rel="tag">#<?= e($st['nazev']) ?></a> <?php endforeach ?></p>
<?php endif ?>
<?php if ($souvisejici !== []): ?>
	<aside class="souvisejici">
		<h2><?= e(t('Další novinky')) ?></h2>
		<ul>
<?php foreach ($souvisejici as $s): ?>
			<li><a href="<?= e($url('novinky/' . $s['seo_link'])) ?>"><?= e($s['titulek']) ?></a> <small><?= e(datum($s['datum'])) ?></small></li>
<?php endforeach ?>
		</ul>
	</aside>
<?php endif ?>
</article>
