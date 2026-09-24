<?php
/**
 * Stránka 404: hledání a hlavní stránky webu, aby návštěvník neodešel s prázdnou.
 *
 * @var callable(string): string $url
 * @var list<array{titulek:string, seo_link:string}> $stranky
 */
?>
<header class="vypis-hlavicka"><h1><?= e(t('Stránka nenalezena')) ?></h1></header>
<p><?= e(t('Požadovaná stránka na webu není. Možná má jinou adresu.')) ?></p>
<form class="hledani" method="get" action="<?= e($url('hledani')) ?>" role="search">
	<input type="search" name="q" placeholder="<?= e(t('Hledaný text')) ?>" aria-label="<?= e(t('Hledaný text')) ?>" minlength="3" required>
	<button type="submit"><?= e(t('Hledat')) ?></button>
</form>
<?php if ($stranky !== []): ?>
<ul>
<?php foreach ($stranky as $s): ?>
	<li><a href="<?= e($url($s['seo_link'])) ?>"><?= e($s['titulek']) ?></a></li>
<?php endforeach ?>
<?php if ($novinky ?? true): ?>
	<li><a href="<?= e($url('novinky')) ?>"><?= e(t('Novinky')) ?></a></li>
<?php endif ?>
</ul>
<?php endif ?>
