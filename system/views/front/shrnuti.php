<?php
/**
 * Blok "Ve zkratce" nad textem článku.
 *
 * @var list<string> $body
 */
if ($body === []) {
    return;
}
?>
<aside class="ve-zkratce obal-uzky" aria-label="<?= e(t('Ve zkratce')) ?>">
	<h2><?= e(t('Ve zkratce')) ?></h2>
	<ul>
<?php foreach ($body as $bod): ?>
		<li><?= e($bod) ?></li>
<?php endforeach ?>
	</ul>
</aside>
