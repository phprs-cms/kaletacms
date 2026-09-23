<?php
/**
 * Blok: seznam odkazů (Menu, Stránky, Sociální sítě).
 *
 * @var list<array{0:string, 1:string}> $odkazy  [text, adresa]; adresa bez http(s) je cesta na tomto webu
 * @var callable(string): string $url
 */
if ($odkazy === []) {
    return;
}
?>
<ul class="blok-menu">
<?php foreach ($odkazy as [$text, $adresa]): $externi = (bool) preg_match('#^(https?:)?//|^mailto:#i', $adresa); ?>
	<li><a href="<?= e($externi ? $adresa : $url(ltrim($adresa, '/'))) ?>"<?= $externi ? ' rel="noopener" target="_blank"' : '' ?>><?= e($text) ?></a></li>
<?php endforeach ?>
</ul>
