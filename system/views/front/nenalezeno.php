<?php
/**
 * 404 page: search and the main pages of the site, so the visitor does not leave empty-handed.
 *
 * @var callable(string): string $url
 * @var list<array{titulek:string, seo_link:string}> $stranky
 * @var string $newsTitle heading of the news list (Settings::newsTitle)
 */
?>
<header class="vypis-hlavicka"><h1><?= e(t('Page not found')) ?></h1></header>
<p><?= e(t('The page you are looking for is not here. It may have a different address.')) ?></p>
<form class="hledani" method="get" action="<?= e($url('hledani')) ?>" role="search">
	<input type="search" name="q" placeholder="<?= e(t('Search text')) ?>" aria-label="<?= e(t('Search text')) ?>" minlength="3" required>
	<button type="submit"><?= e(t('Hledat')) ?></button>
</form>
<?php if ($stranky !== []): ?>
<ul>
<?php foreach ($stranky as $s): ?>
	<li><a href="<?= e($url($s['seo_link'])) ?>"><?= e($s['titulek']) ?></a></li>
<?php endforeach ?>
<?php if ($novinky ?? true): ?>
	<li><a href="<?= e($url('novinky')) ?>"><?= e($newsTitle ?? t('Novinky')) ?></a></li>
<?php endif ?>
</ul>
<?php endif ?>
