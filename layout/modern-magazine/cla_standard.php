<?php
/**
 * Šablona článku "Standardní" pro layout Modern Magazine.
 * Režimy: nahled / kratky (karty ve výpisech) a cely. První článek titulní strany ($poradi 0) je hero.
 *
 * @var array<string, mixed> $clanek  sloupce rs_clanky + tema_jm, tema_seo, autor_jm; u celého článku i "stitky" (nazev, seo_link), "shrnuti_html" (blok Ve zkratce), "faq_html", "hodnoceni_html" a "komentare_html" - hotové HTML, stačí vypsat
 * @var string $rezim
 * @var int $poradi
 * @var callable(string): string $url
 * @var list<array<string, mixed>> $souvisejici
 */
$adresa = $url('clanek/' . $clanek['seo_link']);
$rubrika = '<a class="stitek" href="' . e($url('rubrika/' . $clanek['tema_seo'])) . '">' . e($clanek['tema_jm']) . '</a>';
$podpis = '<p class="podpis">' . ($clanek['autor_jm'] !== null ? '<span>' . e($clanek['autor_jm']) . '</span>' : '')
    . '<time datetime="' . e(date('c', strtotime($clanek['datum']))) . '">' . e(datum($clanek['datum'])) . '</time></p>';
?>
<?php if ($rezim === 'cely'): ?>
<article class="clanek-cely<?= preg_match('/^[a-z0-9-]+$/', (string) ($clanek['sablona_soubor'] ?? '')) ? ' sablona-' . $clanek['sablona_soubor'] : '' ?>">
	<header class="clanek-hlavicka obal-uzky">
		<?= $rubrika ?>
		<?= $clanek['komercni_html'] ?? '' ?>
		<h1><?= e($clanek['titulek']) ?></h1>
		<div class="perex"><?= $clanek['uvod'] ?></div>
		<?= $podpis ?>
	</header>
<?php if ($clanek['obrazek'] !== ''): ?>
	<figure class="clanek-foto"><img src="<?= e($clanek['obrazek']) ?>"<?= ($clanek['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($clanek['obrazek_srcset']) . '" sizes="(max-width: 900px) 100vw, 900px"' : '' ?> alt="<?= e($clanek['obrazek_alt'] ?? '') ?>"><?= $clanek['obrazek_popisek_html'] ?? '' ?></figure>
<?php endif ?>
<?php if (!empty($clanek['aktualizovano'])): ?>
	<p class="clanek-aktualizovano obal-uzky"><?= e(t('Aktualizováno')) ?> <?= e(datum($clanek['aktualizovano'], true)) ?></p>
<?php endif ?>
	<?= $clanek['shrnuti_html'] ?? '' ?>
	<div class="clanek-text obal-uzky"><?= $clanek['text'] ?></div>
	<?= $clanek['faq_html'] ?? '' ?>
<?php if (!empty($clanek['stitky'])): ?>
	<p class="clanek-stitky obal-uzky"><?php foreach ($clanek['stitky'] as $st): ?><a href="<?= e($url('stitek/' . $st['seo_link'])) ?>" rel="tag">#<?= e($st['nazev']) ?></a> <?php endforeach ?></p>
<?php endif ?>
	<footer class="clanek-paticka obal-uzky">
<?php if ($clanek['zdroj'] !== ''): ?>
		<span><?= e(t('Zdroj')) ?>: <?= e($clanek['zdroj']) ?></span>
<?php endif ?>
		<span><?= e(t('Přečteno')) ?> <?= (int) $clanek['visit'] + 1 ?>&times;</span>
	</footer>
<?php if ($souvisejici !== []): ?>
	<aside class="souvisejici obal-uzky">
		<h2><?= e(t('Čtěte dál')) ?></h2>
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
<article class="karta<?= $poradi === 0 ? ' karta-hero' : '' ?><?= $clanek['obrazek'] !== '' ? ' ma-foto' : ' bez-fota' ?>">
	<div class="karta-foto">
<?php if ($clanek['obrazek'] !== ''): ?>
		<img src="<?= e($clanek['obrazek']) ?>"<?= ($clanek['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($clanek['obrazek_srcset']) . '" sizes="(max-width: 900px) 100vw, 900px"' : '' ?> alt="" loading="<?= $poradi === 0 ? 'eager' : 'lazy' ?>">
<?php else: ?>
		<span aria-hidden="true"><?= e(mb_substr($clanek['tema_jm'], 0, 1)) ?></span>
<?php endif ?>
	</div>
	<div class="karta-telo">
		<?= $rubrika ?>
		<?= $clanek['komercni_html'] ?? '' ?>
		<h2><?= $rezim === 'nahled' ? '<a href="' . e($adresa) . '">' . e($clanek['titulek']) . '</a>' : e($clanek['titulek']) ?></h2>
		<div class="perex"><?= $clanek['uvod'] ?></div>
		<?= $podpis ?>
	</div>
</article>
<?php endif ?>
