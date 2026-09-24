<?php
/**
 * Výpis novinek: /novinky, kategorie, štítek, výsledky hledání (a úvod webu, když nemá úvodní stránku).
 *
 * @var string $nadpis
 * @var string $popis  HTML úvod nad výpisem (popis kategorie, stránka tématu)
 * @var list<array<string, mixed>> $novinky
 * @var int $celkem
 * @var int $strana
 * @var int $stran
 * @var callable(int): string $strankaUrl
 * @var string|null $hledano  hledaný text; null = nejde o hledání
 * @var list<array{titulek:string, seo_link:string}> $nalezeneStranky  stránky webu odpovídající hledání
 * @var callable(string): string $url
 */
?>
<header class="vypis-hlavicka">
	<h1><?= e($nadpis) ?></h1>
<?php if ($popis !== ''): ?>
	<div class="perex"><?= $popis ?></div>
<?php endif ?>
<?php if ($hledano !== null): ?>
	<form class="hledani" method="get" action="<?= e($url('hledani')) ?>" role="search">
		<input type="search" name="q" value="<?= e($hledano) ?>" minlength="3" maxlength="100" aria-label="<?= e(t('Hledaný text')) ?>" required>
		<button type="submit"><?= e(t('Hledat')) ?></button>
	</form>
<?php if ($hledano !== ''): ?>
	<p><?= mb_strlen($hledano) < 3 ? e(t('Zadejte alespoň 3 znaky.')) : e(t('Nalezeno: %s', $celkem + count($nalezeneStranky))) ?></p>
<?php endif ?>
<?php endif ?>
</header>

<?php if ($nalezeneStranky !== []): ?>
<ul class="vypis-stranky">
<?php foreach ($nalezeneStranky as $s): ?>
	<li><a href="<?= e($url($s['seo_link'])) ?>"><?= e($s['titulek']) ?></a></li>
<?php endforeach ?>
</ul>
<?php endif ?>

<?php if ($novinky === [] && $hledano === null): ?>
<p><?= e(t('Zatím zde nejsou žádné novinky.')) ?></p>
<?php endif ?>
<?php if ($novinky !== []): ?>
<div class="novinky-mrizka">
<?php foreach ($novinky as $n): $adresa = $url('novinky/' . $n['seo_link']); ?>
	<article class="novinka-karta">
<?php if ($n['obrazek'] !== ''): ?>
		<a class="novinka-karta-obrazek" href="<?= e($adresa) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e($n['obrazek']) ?>"<?= ($n['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($n['obrazek_srcset']) . '" sizes="(max-width: 700px) 100vw, 400px"' : '' ?> alt="" loading="lazy"></a>
<?php endif ?>
		<p class="novinka-info"><time datetime="<?= e(date('c', strtotime($n['datum']))) ?>"><?= e(datum($n['datum'])) ?></time> · <a href="<?= e($url('novinky/kategorie/' . $n['tema_seo'])) ?>"><?= e($n['tema_jm']) ?></a></p>
		<h2><a href="<?= e($adresa) ?>"><?= e($n['titulek']) ?></a></h2>
		<div class="perex"><?= $n['uvod'] ?></div>
	</article>
<?php endforeach ?>
</div>
<?php endif ?>

<?php if ($stran > 1): ?>
<nav class="strankovani" aria-label="<?= e(t('Stránkování')) ?>">
<?php if ($strana > 1): ?>
	<a href="<?= e($strankaUrl($strana - 1)) ?>" rel="prev">&laquo; <?= e(t('novější')) ?></a>
<?php endif ?>
	<span aria-current="page"><?= e(t('strana %s z %s', $strana, $stran)) ?></span>
<?php if ($strana < $stran): ?>
	<a href="<?= e($strankaUrl($strana + 1)) ?>" rel="next"><?= e(t('starší')) ?> &raquo;</a>
<?php endif ?>
</nav>
<?php endif ?>
