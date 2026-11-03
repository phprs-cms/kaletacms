<?php
/**
 * The tabs of the fleet console (3.17): Today (the console opens on it since 3.29), the inbox, the health matrix, the
 * workload (3.29), the sites, the customers, the monthly fleet reports, the alerts and digest, the shared kit, and (3.30)
 * the inventory, the expiry calendar and the rollout.
 * A pairing key made just now is shown here once, on whichever tab the owner lands.
 *
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $current '' (today) | inbox | health | workload | sites | customers | reports | alerts | kit | inventory | calendar | rollout
 * @var string $pairingKey
 */
?>
<nav class="zalozky" aria-label="<?= e(t('Fleet console')) ?>">
<?php foreach (['' => t('Today'), 'inbox' => t('Inbox'), 'health' => t('Health'), 'workload' => t('Workload'), 'sites' => t('Sites'), 'customers' => t('Customers'), 'reports' => t('Fleet reports'), 'alerts' => t('Alerts and digest'), 'kit' => t('Shared kit'),
    'inventory' => t('Inventory'), 'calendar' => t('Calendar'), 'rollout' => t('Rollout')] as $action => $label): ?>
	<a href="<?= e($module->url($action)) ?>"<?= $current === $action ? ' class="aktivni" aria-current="page"' : '' ?>><?= e($label) ?></a>
<?php endforeach ?>
</nav>
<?php if (($pairingKey ?? '') !== ''): ?>
<div class="hlaska hlaska-ok"><p><?= e(t('Paste this pairing key on the site in Settings → Reporting to a console. It is valid for 24 hours and only once; it is not shown again.')) ?></p>
<p><code class="totp-klic"><?= e($pairingKey) ?></code></p></div>
<?php endif ?>
