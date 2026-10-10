<?php
/**
 * Language version switcher. The layout gets it ready-made in the variable $jazyky_html (empty if the site has a single language).
 * Two to three languages = a switcher in one row, more = a button with a menu (Popover API, no JavaScript). Colours, corner
 * rounding and font come from the design system (tokens --ka-…), so it matches the appearance of the site.
 *
 * The label "Language" is deliberately in English (even a visitor who does not understand the page language understands it), hence lang="en".
 *
 * The Language switcher element (e.g. in the footer) chooses the style: "rada" (row), or "nabidka" (menu) with the full language
 * name in the button, and the direction in which the menu opens (upwards in the footer).
 *
 * @var array<string, array{nazev:string, url:string, aktivni:bool, preklad:bool}> $jazyky
 * @var string|null $styl auto (a row up to three languages, a menu for more) | rada | nabidka
 * @var string|null $smer dolu | nahoru
 * @var string|null $atributy attributes of the element from the builder (id, classes)
 */
$aktivni = array_key_first(array_filter($jazyky, fn (array $j): bool => $j['aktivni'])) ?? array_key_first($jazyky);
$styl ??= 'auto';
$atributy ??= '';
?>
<?php if ($styl === 'rada' || ($styl === 'auto' && count($jazyky) <= 3)): ?>
<nav<?= Kaleta\Builder\Elements\Text::withClass($atributy, 'ka-jazyky') ?> lang="en" aria-label="Language">
<?php foreach ($jazyky as $kod => $j): ?>
	<a href="<?= e($j['url']) ?>" hreflang="<?= e(Kaleta\Core\Language::tag($kod)) ?>" lang="<?= e(Kaleta\Core\Language::tag($kod)) ?>" title="<?= e($j['nazev']) ?>"<?= $j['aktivni'] ? ' aria-current="true"' : '' ?>><?= e(strtoupper($kod)) ?></a>
<?php endforeach ?>
</nav>
<?php else: $id = 'ka-jazyky-' . bin2hex(random_bytes(3)); ?>
<nav<?= Kaleta\Builder\Elements\Text::withClass($atributy, 'ka-jazyky-vyber' . (($smer ?? '') === 'nahoru' ? ' ka-jazyky-vyber--nahoru' : '')) ?> lang="en" aria-label="Language">
	<button type="button" class="ka-jazyky-tl" popovertarget="<?= $id ?>" style="anchor-name: --<?= $id ?>" aria-label="Language: <?= e($jazyky[$aktivni]['nazev']) ?>">
		<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
		<span><?= e($styl === 'nabidka' ? $jazyky[$aktivni]['nazev'] : strtoupper((string) $aktivni)) ?></span><?php if ($styl === 'nabidka'): ?>
		<svg class="ka-jazyky-sipka" viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg><?php endif ?>
	</button>
	<ul id="<?= $id ?>" popover style="position-anchor: --<?= $id ?>">
<?php foreach ($jazyky as $kod => $j): ?>
		<li><a href="<?= e($j['url']) ?>" hreflang="<?= e(Kaleta\Core\Language::tag($kod)) ?>" lang="<?= e(Kaleta\Core\Language::tag($kod)) ?>"<?= $j['aktivni'] ? ' aria-current="true"' : '' ?>><span><?= e($j['nazev']) ?></span><small><?= e(strtoupper($kod)) ?></small></a></li>
<?php endforeach ?>
	</ul>
</nav>
<?php endif ?>
