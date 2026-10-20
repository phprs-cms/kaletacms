<?php
/**
 * The tabs of the fleet console (3.17): the inbox (the console opens on it), the sites, the customers and the shared kit.
 * A pairing key made just now is shown here once, on whichever tab the owner lands.
 *
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $current '' (inbox) | sites | customers | kit
 * @var string $pairingKey
 */
?>
<nav class="zalozky" aria-label="<?= e(t('Fleet console')) ?>">
<?php foreach (['' => t('Inbox'), 'sites' => t('Sites'), 'customers' => t('Customers'), 'kit' => t('Shared kit')] as $action => $label): ?>
	<a href="<?= e($module->url($action)) ?>"<?= $current === $action ? ' class="aktivni" aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
</nav>
<?php if (($pairingKey ?? '') !== ''): ?>
<div class="hlaska hlaska-ok"><p><?= e(t('Paste this pairing key on the site in Settings → Fleet console. It is valid for 24 hours and only once; it is not shown again.')) ?></p>
<p><code class="totp-klic"><?= e($pairingKey) ?></code></p></div>
<?php endif ?>
