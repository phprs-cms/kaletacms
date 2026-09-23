<?php
/**
 * Stránka 404: hledání a nejčtenější články, aby čtenář neodešel s prázdnou.
 *
 * @var callable(string): string $url
 * @var list<array<string, mixed>> $nejctenejsi
 */
?>
<header class="vypis-hlavicka"><h1><?= e(t('Stránka nenalezena')) ?></h1></header>
<p><?= e(t('Požadovaná stránka na webu není. Možná byl článek stažen nebo má jinou adresu.')) ?></p>
<form class="hledani mc-hledani-404" method="get" action="<?= e($url('hledani')) ?>" role="search">
	<input type="search" name="q" placeholder="<?= e(t('Hledaný text')) ?>" aria-label="<?= e(t('Hledaný text')) ?>" minlength="3" required>
	<button type="submit"><?= e(t('Hledat')) ?></button>
</form>
<?php if (!empty($nejctenejsi)): ?>
<h2><?= e(t('Nejčtenější články')) ?></h2>
<ul>
<?php foreach ($nejctenejsi as $c): ?>
	<li><a href="<?= e($url('clanek/' . $c['seo_link'])) ?>"><?= e($c['titulek']) ?></a></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<p><a href="<?= e($url('')) ?>"><?= e(t('Přejít na hlavní stránku')) ?></a></p>
