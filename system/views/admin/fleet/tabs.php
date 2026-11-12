<?php
/**
 * The tabs of the fleet console. 3.39 (fewer tabs): six sections (Admin\Modules\Fleet::SECTIONS) – Today (with the inbox),
 * Sites (the list, the health matrix, the inventory, the workload), Customers (with the fleet reports), Updates (the rollout
 * and the shared kit), Calendar, Alerts and digest. A section with more views shows them as a second row; every former tab
 * keeps its own address. The current section's tab is marked aria-current="true", the current view aria-current="page".
 * A pairing key made just now is shown here once, on whichever tab the owner lands.
 *
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $current the action of the screen ('' = Today)
 * @var string $pairingKey
 */

use Kaleta\Admin\Modules\Fleet;

$section = Fleet::sectionOf($current);
$views = Fleet::SECTIONS[$section][1];
?>
<nav class="zalozky" aria-label="<?= e(t('Fleet console')) ?>">
<?php foreach (Fleet::SECTIONS as $key => [$label, $sectionViews]): ?>
	<a href="<?= e($module->url($key)) ?>"<?= $section === $key ? ' class="aktivni" aria-current="' . ($sectionViews === [] && $current === $key ? 'page' : 'true') . '"' : '' ?>><?= e(t($label)) ?></a>
<?php endforeach ?>
</nav>
<?php if ($views !== []): ?>
<nav class="zalozky flotila-pohledy" aria-label="<?= e(t('Views of %s', t(Fleet::SECTIONS[$section][0]))) ?>">
<?php foreach ($views as $action => $label): ?>
	<a href="<?= e($module->url($action)) ?>"<?= $current === $action ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t($label)) ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<?php if (($pairingKey ?? '') !== ''): ?>
<div class="hlaska hlaska-ok"><p><?= e(t('Paste this pairing key on the site in Settings → Reporting to a console. It is valid for 24 hours and only once; it is not shown again.')) ?></p>
<p><code class="totp-klic"><?= e($pairingKey) ?></code></p></div>
<?php endif ?>
