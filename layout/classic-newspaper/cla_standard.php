<?php
/**
 * Šablona článku "Standardní" pro layout Classic Newspaper.
 * Režimy: nahled / kratky (výpisy) a cely. První článek titulní strany ($poradi 0) je otvírák.
 *
 * @var array<string, mixed> $clanek  sloupce rs_clanky + tema_jm, tema_seo, autor_jm; u celého článku i "stitky" (nazev, seo_link), "shrnuti_html" (blok Ve zkratce), "faq_html", "hodnoceni_html" a "komentare_html" - hotové HTML, stačí vypsat
 * @var string $rezim
 * @var int $poradi
 * @var callable(string): string $url
 * @var list<array<string, mixed>> $souvisejici
 */
$adresa = $url('clanek/' . $clanek['seo_link']);
$rubrika = '<a class="clanek-rubrika" href="' . e($url('rubrika/' . $clanek['tema_seo'])) . '">' . e($clanek['tema_jm']) . '</a>';
$cas = '<time datetime="' . e(date('c', strtotime($clanek['datum']))) . '">' . e(datum($clanek['datum'])) . '</time>';
?>
<?php if ($rezim === 'cely'): ?>
<article class="clanek clanek-cely<?= preg_match('/^[a-z0-9-]+$/', (string) ($clanek['sablona_soubor'] ?? '')) ? ' sablona-' . $clanek['sablona_soubor'] : '' ?>">
	<header class="clanek-hlavicka">
		<?= $rubrika ?>
		<?= $clanek['komercni_html'] ?? '' ?>
		<h1><?= e($clanek['titulek']) ?></h1>
		<div class="perex"><?= $clanek['uvod'] ?></div>
		<p class="clanek-podpis"><?= $clanek['autor_jm'] !== null ? '<span class="autor">' . e($clanek['autor_jm']) . '</span>' : '' ?><?= $cas ?></p>
	</header>
<?php if ($clanek['obrazek'] !== ''): ?>
	<figure class="clanek-foto"><img src="<?= e($clanek['obrazek']) ?>"<?= ($clanek['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($clanek['obrazek_srcset']) . '" sizes="(max-width: 900px) 100vw, 900px"' : '' ?> alt="<?= e($clanek['obrazek_alt'] ?? '') ?>"><?= $clanek['obrazek_popisek_html'] ?? '' ?></figure>
<?php endif ?>
<?php if (!empty($clanek['aktualizovano'])): ?>
	<p class="clanek-aktualizovano obal-uzky"><?= e(t('Aktualizováno')) ?> <?= e(datum($clanek['aktualizovano'], true)) ?></p>
<?php endif ?>
	<?= $clanek['shrnuti_html'] ?? '' ?>
	<div class="clanek-text"><?= $clanek['text'] ?></div>
	<?= $clanek['faq_html'] ?? '' ?>
<?php if (!empty($clanek['stitky'])): ?>
	<p class="clanek-stitky"><?php foreach ($clanek['stitky'] as $st): ?><a href="<?= e($url('stitek/' . $st['seo_link'])) ?>" rel="tag">#<?= e($st['nazev']) ?></a> <?php endforeach ?></p>
<?php endif ?>
	<footer class="clanek-paticka">
<?php if ($clanek['zdroj'] !== ''): ?>
		<span><?= e(t('Zdroj')) ?>: <?= e($clanek['zdroj']) ?></span>
<?php endif ?>
		<span><?= e(t('Přečteno')) ?> <?= (int) $clanek['visit'] + 1 ?>&times;</span>
	</footer>
<?php if ($souvisejici !== []): ?>
	<aside class="souvisejici">
		<h2><?= e(t('Související články')) ?></h2>
		<ul>
<?php foreach ($souvisejici as $s): ?>
			<li><a href="<?= e($url('clanek/' . $s['seo_link'])) ?>"><?= e($s['titulek']) ?></a></li>
<?php endforeach ?>
		</ul>
	</aside>
<?php endif ?>
	<?= $clanek['reklama_html'] ?? '' ?>
	<?= $clanek['hodnoceni_html'] ?? '' ?>
	<?= $clanek['komentare_html'] ?? '' ?>
</article>
<?php else: ?>
<article class="clanek clanek-nahled<?= $poradi === 0 ? ' clanek-otvirak' : '' ?><?= $clanek['obrazek'] !== '' ? ' ma-foto' : '' ?>">
<?php if ($clanek['obrazek'] !== ''): ?>
	<<?= $rezim === 'nahled' ? 'a href="' . e($adresa) . '" tabindex="-1" aria-hidden="true"' : 'div' ?> class="clanek-foto"><img src="<?= e($clanek['obrazek']) ?>"<?= ($clanek['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($clanek['obrazek_srcset']) . '" sizes="(max-width: 900px) 100vw, 900px"' : '' ?> alt="" loading="<?= $poradi === 0 ? 'eager' : 'lazy' ?>"></<?= $rezim === 'nahled' ? 'a' : 'div' ?>>
<?php endif ?>
	<div class="clanek-telo">
		<?= $rubrika ?>
		<?= $clanek['komercni_html'] ?? '' ?>
		<h2><?= $rezim === 'nahled' ? '<a href="' . e($adresa) . '">' . e($clanek['titulek']) . '</a>' : e($clanek['titulek']) ?></h2>
		<div class="perex"><?= $clanek['uvod'] ?></div>
		<p class="clanek-podpis"><?= $clanek['autor_jm'] !== null ? '<span class="autor">' . e($clanek['autor_jm']) . '</span>' : '' ?><?= $cas ?></p>
	</div>
</article>
<?php endif ?>
