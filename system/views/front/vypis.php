<?php
/**
 * Výpis článků: hlavní stránka, rubrika i výsledky hledání.
 *
 * @var list<string> $nahledy  hotové HTML náhledů (šablony cla_*)
 * @var int $celkem
 * @var int $strana
 * @var int $stran
 * @var callable(int): string $strankaUrl
 * @var array<string, mixed>|null $rubrika
 * @var string|null $hledano
 * @var callable(string): string $url
 * @var bool $hlavni  výpis hlavní stránky
 */
?>
<?php if ($rubrika !== null): ?>
<header class="vypis-hlavicka">
	<h1><?= e($rubrika['nazev']) ?></h1>
<?php if ($rubrika['popis'] !== ''): ?>
	<div class="perex"><?= $rubrika['popis'] ?></div>
<?php endif ?>
</header>
<?php elseif ($hledano !== null): ?>
<header class="vypis-hlavicka">
	<h1><?= e(t('Vyhledávání')) ?></h1>
	<form class="hledani" method="get" action="<?= e($url('hledani')) ?>" role="search">
		<input type="search" name="q" value="<?= e($hledano) ?>" minlength="3" maxlength="100" aria-label="<?= e(t('Hledaný text')) ?>" required>
		<button type="submit"><?= e(t('Hledat')) ?></button>
	</form>
<?php if ($hledano !== ''): ?>
	<p><?= mb_strlen($hledano) < 3 ? e(t('Zadejte alespoň 3 znaky.')) : e(t('Nalezeno článků')) . ': ' . $celkem ?></p>
<?php endif ?>
</header>
<?php elseif ($hlavni): ?>
<h1 class="mc-jen-ctecka"><?= e(t('Nejnovější články')) ?></h1>
<?php endif ?>

<?php if ($nahledy === [] && $hledano === null): ?>
<p><?= e(t('Zatím zde nejsou žádné články.')) ?></p>
<?php endif ?>
<div class="vypis-seznam<?= $hlavni && $strana === 1 ? ' vypis-titulni' : '' ?>">
<?= implode("\n", $nahledy) ?>
</div>

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
