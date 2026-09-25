<?php
/**
 * Přepínač jazykových verzí. Layout ho dostává hotový v proměnné $jazyky_html (prázdná, má-li web jediný jazyk).
 * Dva až tři jazyky = přepínač v jedné řadě, víc = tlačítko s nabídkou (Popover API, bez JavaScriptu). Barvy, zaoblení
 * a písmo bere z design systému (tokeny --ka-…), takže sedí ke vzhledu webu.
 *
 * Popisek „Language“ je záměrně anglicky (rozumí mu i návštěvník, který jazyku stránky nerozumí), proto lang="en".
 *
 * @var array<string, array{nazev:string, url:string, aktivni:bool, preklad:bool}> $jazyky
 */
$aktivni = array_key_first(array_filter($jazyky, fn (array $j): bool => $j['aktivni'])) ?? array_key_first($jazyky);
?>
<?php if (count($jazyky) <= 3): ?>
<nav class="ka-jazyky" lang="en" aria-label="Language">
<?php foreach ($jazyky as $kod => $j): ?>
	<a href="<?= e($j['url']) ?>" hreflang="<?= e($kod) ?>" lang="<?= e($kod) ?>" title="<?= e($j['nazev']) ?>"<?= $j['aktivni'] ? ' aria-current="true"' : '' ?>><?= e(strtoupper($kod)) ?></a>
<?php endforeach ?>
</nav>
<?php else: $id = 'ka-jazyky-' . bin2hex(random_bytes(3)); ?>
<nav class="ka-jazyky-vyber" lang="en" aria-label="Language">
	<button type="button" class="ka-jazyky-tl" popovertarget="<?= $id ?>" style="anchor-name: --<?= $id ?>" aria-label="Language: <?= e($jazyky[$aktivni]['nazev']) ?>">
		<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/></svg>
		<span><?= e(strtoupper((string) $aktivni)) ?></span>
	</button>
	<ul id="<?= $id ?>" popover style="position-anchor: --<?= $id ?>">
<?php foreach ($jazyky as $kod => $j): ?>
		<li><a href="<?= e($j['url']) ?>" hreflang="<?= e($kod) ?>" lang="<?= e($kod) ?>"<?= $j['aktivni'] ? ' aria-current="true"' : '' ?>><span><?= e($j['nazev']) ?></span><small><?= e(strtoupper($kod)) ?></small></a></li>
<?php endforeach ?>
	</ul>
</nav>
<?php endif ?>
