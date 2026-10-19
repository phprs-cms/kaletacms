<?php
/**
 * The fleet console: all paired sites, the ones that need attention first (Fleet\Console::overview).
 *
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var list<array<string, mixed>> $sites
 * @var int $total
 * @var int $needing
 * @var string $show attention | all
 * @var string $query
 * @var string $latest the newest version the console knows
 * @var string $pairingKey a pairing key made just now (shown once)
 */
use Kaleta\Fleet\Console;

$reasonLabels = require __DIR__ . '/reasons.php';
$severity = fn (string $r): string => Console::REASONS[$r] >= Console::REASONS['errors'] ? 'chyba' : (Console::REASONS[$r] >= Console::REASONS['warnings'] ? 'koncept' : '');
?>
<?php if ($pairingKey !== ''): ?>
<div class="hlaska hlaska-ok"><p><?= e(t('Paste this pairing key on the site in Settings → Fleet console. It is valid for 24 hours and only once; it is not shown again.')) ?></p>
<p><code class="totp-klic"><?= e($pairingKey) ?></code></p></div>
<?php endif ?>
<p class="hlaska<?= $total > 0 && $needing === 0 ? ' hlaska-ok' : '' ?>"><?= e($total === 0 ? t('No site reports to this console yet. Make a pairing key and paste it on the site.')
    : ($needing === 0 ? t('All %d sites are fine.', $total) : t('%d of %d sites need attention.', $needing, $total))) ?> <?= e(t('The newest version: %s.', $latest)) ?></p>
<form class="formular" method="post" action="<?= e($module->url('pairing_key')) ?>">
<?= $csrf ?>
<p><button class="tl" type="submit"><?= e(t('Add a site')) ?></button>
<a class="navigace" href="<?= e($module->url('kit')) ?>"><?= e(t('Shared kit')) ?></a>
<?php if ($total > 0): ?>
<button class="navigace" type="submit" formaction="<?= e($module->url('check')) ?>"><?= e(t('Check all now')) ?></button>
<button class="navigace" type="submit" formaction="<?= e($module->url('allow')) ?>" data-potvrdit="<?= e(t('Every site that lets the console decide installs version %s within an hour, without waiting for the test sites. Continue?', $latest)) ?>"><?= e(t('Allow the new version everywhere now')) ?></button>
<?php endif ?></p>
</form>
<?php if ($total > 0): ?>
<nav class="zalozky" aria-label="<?= e(t('Which sites')) ?>">
	<a href="<?= e($module->url('')) ?>"<?= $show === 'attention' ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t('Need attention')) ?> (<?= $needing ?>)</a>
	<a href="<?= e($module->url('', ['show' => 'all'])) ?>"<?= $show === 'all' ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t('All sites')) ?> (<?= $total ?>)</a>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>"><input type="hidden" name="module" value="fleet"><?php if ($show === 'all'): ?><input type="hidden" name="show" value="all"><?php endif ?>
	<label><?= e(t('Name or address contains:')) ?> <input class="textpole" type="search" name="q" value="<?= e($query) ?>" size="24"></label> <input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>"></form>
<?php if ($sites === []): ?>
<p><?= e(t('Nothing needs attention.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('What needs attention')) ?></th><th scope="col"><?= e(t('Version')) ?></th><th scope="col"><?= e(t('Last report')) ?></th><th scope="col"><?= e(t('Enquiries waiting')) ?></th></tr></thead>
<tbody>
<?php foreach ($sites as $s): ?>
<tr>
	<td><a href="<?= e($module->url('detail', ['id' => (int) $s['id']])) ?>"><strong><?= e((string) $s['name'] !== '' ? (string) $s['name'] : (string) $s['url']) ?></strong></a><br><span class="smltxt"><?= e((string) $s['url']) ?><?= $s['ring'] === 'canary' ? ' · ' . e(t('test site')) : '' ?></span></td>
	<td><?php if ($s['reasons'] === []): ?><span class="stitek stitek-vydano"><?= e(t('ok')) ?></span><?php endif ?><?php foreach ($s['reasons'] as $r): ?><span class="stitek<?= $severity($r) !== '' ? ' stitek-' . $severity($r) : '' ?>"><?= e($reasonLabels[$r]) ?></span> <?php endforeach ?></td>
	<td><?= e((string) $s['version'] !== '' ? (string) $s['version'] : '–') ?><?php if ((string) $s['update_allowed'] !== ''): ?><br><span class="smltxt"><?= e(t('allowed %s', (string) $s['update_allowed'])) ?></span><?php endif ?></td>
	<td><?= $s['last_seen'] !== null ? e(format_date(new DateTimeImmutable((string) $s['last_seen']), true)) : '–' ?></td>
	<td class="stred"><?= ($waiting = Console::enquiriesWaiting($s['beat'])) !== null ? $waiting : '–' ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php endif ?>
<p class="smltxt"><?= e(t('Sites report every hour; the console checks every 5 minutes that each home page answers. Problems reach you by the alert e-mail (System status → Alerts).')) ?></p>
