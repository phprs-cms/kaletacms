<?php
/**
 * A list of first steps (3.45): on the dashboard the website's (2.x) and the office's (3.45, CPO 6), at the top of Quotes the
 * path to the first quote (3.45, CPO 3). Folded to one line once every step is done (3.32, U37); Hide posts the list's name
 * to Admin\Kernel (action hide_first_steps), which remembers it in a setting – only administrators see these lists.
 *
 * @var Kaleta\Core\App $app
 * @var string $title already translated
 * @var list<array{nazev: string, popis: string, url: string, hotovo: bool}> $steps nazev and popis are dictionary keys, translated here
 * @var string $list '' = the website's list | office | quotes
 */
$finished = count(array_filter($steps, fn (array $k): bool => $k['hotovo']));
$class = ['' => 'pruvodce', 'office' => 'pruvodce pruvodce-kancelar', 'quotes' => 'pruvodce pruvodce-nabidky'][$list] ?? 'pruvodce';
?>
<details class="<?= $class ?>" aria-label="<?= e($title) ?>"<?= $finished < count($steps) ? ' open' : '' ?>>
	<summary class="pruvodce-hlava">
		<h2><?= e($title) ?> <small><?= $finished ?> / <?= count($steps) ?></small></h2>
	</summary>
	<form class="pruvodce-skryt" method="post" action="<?= e($app->url('admin.php?action=hide_first_steps')) ?>"><?= $app->session->csrfField() ?><?php if ($list !== ''): ?><input type="hidden" name="list" value="<?= e($list) ?>"><?php endif ?><button class="navigace" type="submit" aria-label="<?= e(t('Hide') . ': ' . $title) ?>"><?= e(t('Hide')) ?></button></form>
	<ol class="pruvodce-kroky">
<?php foreach ($steps as $k): ?>
		<li class="<?= $k['hotovo'] ? 'hotovo' : '' ?>"><a href="<?= e($k['url']) ?>"><strong><?= e(t($k['nazev'])) ?></strong><span><?= e(t($k['popis'])) ?></span></a></li>
<?php endforeach ?>
	</ol>
</details>
