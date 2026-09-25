<?php
/**
 * Přepínač vzhledu pro návštěvníky: podle zařízení / světlý / tmavý. Volbu uloží image/web.js do prohlížeče (localStorage
 * „ka-tema“) a skript v hlavičce šablony ji použije ještě před vykreslením, takže stránka neblikne. Vzhled i chování
 * sdílí s přepínačem jazyků (třída ka-jazyky-vyber, tokeny design systému).
 *
 * @var string $vychozi auto | tmavy – výchozí vzhled webu (Vzhled webu → Tmavý režim)
 */
$id = 'ka-tema-' . bin2hex(random_bytes(3));
$ikony = [
    'auto' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17a8.5 8.5 0 0 0 0-17z" fill="currentColor" stroke="none"/>',
    'svetly' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
    'tmavy' => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/>',
];
$volby = ['auto' => t('Podle zařízení'), 'svetly' => t('Světlý'), 'tmavy' => t('Tmavý')];
?>
<nav class="ka-jazyky-vyber ka-tema" data-volba="<?= e($vychozi) ?>" data-tema-vychozi="<?= e($vychozi) ?>" aria-label="<?= e(t('Vzhled')) ?>">
	<button type="button" class="ka-jazyky-tl ka-tema-tl" popovertarget="<?= $id ?>" style="anchor-name: --<?= $id ?>" aria-label="<?= e(t('Vzhled')) ?>">
<?php foreach ($ikony as $klic => $ikona): ?>
		<svg class="ka-tema-ikona ka-tema-ikona--<?= $klic ?>" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><?= $ikona ?></svg>
<?php endforeach ?>
	</button>
	<div id="<?= $id ?>" popover style="position-anchor: --<?= $id ?>">
<?php foreach ($volby as $klic => $nazev): ?>
		<button type="button" data-tema-volba="<?= $klic ?>" aria-pressed="<?= $klic === $vychozi ? 'true' : 'false' ?>"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><?= $ikony[$klic] ?></svg><span><?= e($nazev) ?></span></button>
<?php endforeach ?>
	</div>
</nav>
