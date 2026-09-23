<?php
/**
 * Šablona článku "Standardní" – layout Minimal: ve výpisu datum, titulek a perex; obrázek jen malý vpravo.
 *
 * Šablona má tři režimy:
 *   nahled - úvod s odkazem na celý článek (hlavní stránka, rubrika, hledání)
 *   kratky - krátký článek: jen úvod, bez samostatné stránky
 *   cely   - celý článek
 *
 * @var array<string, mixed> $clanek  sloupce rs_clanky + tema_jm, tema_seo, autor_jm; u celého článku i "stitky" (nazev, seo_link), "shrnuti_html" (blok Ve zkratce), "faq_html", "hodnoceni_html" a "komentare_html" - hotové HTML, stačí vypsat
 * @var string $rezim
 * @var int $poradi  pořadí ve výpisu od nuly (0 = první článek první stránky)
 * @var callable(string): string $url
 * @var list<array<string, mixed>> $souvisejici
 */
$adresa = $url('clanek/' . $clanek['seo_link']);
$info = function () use ($clanek, $url): string {
    return '<p class="clanek-info">'
        . '<a class="clanek-rubrika" href="' . e($url('rubrika/' . $clanek['tema_seo'])) . '">' . e($clanek['tema_jm']) . '</a> '
        . '<time datetime="' . e(date('c', strtotime($clanek['datum']))) . '">' . e(datum($clanek['datum'])) . '</time>'
        . ($clanek['autor_jm'] !== null ? ' &middot; ' . e($clanek['autor_jm']) : '')
        . '</p>';
};
?>
<?php if ($rezim === 'cely'): ?>
<article class="clanek clanek-cely<?= preg_match('/^[a-z0-9-]+$/', (string) ($clanek['sablona_soubor'] ?? '')) ? ' sablona-' . $clanek['sablona_soubor'] : '' ?>">
	<header>
		<?= $info() ?>
		<?= $clanek['komercni_html'] ?? '' ?>
		<h1><?= e($clanek['titulek']) ?></h1>
	</header>
<?php if ($clanek['obrazek'] !== ''): ?>
	<figure class="clanek-foto"><img class="clanek-obrazek" src="<?= e($clanek['obrazek']) ?>"<?= ($clanek['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($clanek['obrazek_srcset']) . '" sizes="(max-width: 900px) 100vw, 900px"' : '' ?> alt="<?= e($clanek['obrazek_alt'] ?? '') ?>"><?= $clanek['obrazek_popisek_html'] ?? '' ?></figure>
<?php endif ?>
	<div class="perex"><?= $clanek['uvod'] ?></div>
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
		<p><?= e(t('Zdroj')) ?>: <?= e($clanek['zdroj']) ?></p>
<?php endif ?>
		<p><?= e(t('Přečteno')) ?>: <?= (int) $clanek['visit'] + 1 ?>&times;</p>
	</footer>
<?php if ($souvisejici !== []): ?>
	<aside class="souvisejici">
		<h2><?= e(t('Související články')) ?></h2>
		<ul>
<?php foreach ($souvisejici as $s): ?>
			<li><a href="<?= e($url('clanek/' . $s['seo_link'])) ?>"><?= e($s['titulek']) ?></a> <small><?= e(datum($s['datum'])) ?></small></li>
<?php endforeach ?>
		</ul>
	</aside>
<?php endif ?>
	<?= $clanek['reklama_html'] ?? '' ?>
	<?= $clanek['hodnoceni_html'] ?? '' ?>
	<?= $clanek['komentare_html'] ?? '' ?>
</article>
<?php else: ?>
<article class="clanek clanek-nahled<?= $clanek['priority'] > 0 ? ' clanek-dulezity' : '' ?><?= $clanek['obrazek'] !== '' ? ' ma-obrazek' : '' ?>">
	<div class="nahled-text">
		<?= $info() ?>
		<?= $clanek['komercni_html'] ?? '' ?>
<?php if ($rezim === 'kratky'): ?>
		<h2><?= e($clanek['titulek']) ?></h2>
<?php else: ?>
		<h2><a href="<?= e($adresa) ?>"><?= e($clanek['titulek']) ?></a></h2>
<?php endif ?>
		<div class="perex"><?= $clanek['uvod'] ?></div>
	</div>
<?php if ($clanek['obrazek'] !== ''): ?>
	<<?= $rezim === 'kratky' ? 'span' : 'a href="' . e($adresa) . '" tabindex="-1" aria-hidden="true"' ?> class="nahled-obrazek"><img src="<?= e($clanek['obrazek']) ?>"<?= ($clanek['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($clanek['obrazek_srcset']) . '" sizes="160px"' : '' ?> alt="" loading="lazy"></<?= $rezim === 'kratky' ? 'span' : 'a' ?>>
<?php endif ?>
</article>
<?php endif ?>
