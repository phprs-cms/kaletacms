<?php
/**
 * The inbox of the fleet console (3.17, Fleet\Inbox): everything that waits on the sites, the most urgent and the oldest
 * first. Built from the sites' last reports and the console's own checks; each row links – a plain link, no sign-in on the
 * owner's behalf – to the screen of that site's administration.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $csrf
 * @var list<array<string, mixed>> $rows the rows shown (Inbox::build, filtered)
 * @var array<string, int> $counts kind => rows under the other filters
 * @var int $narrowed rows under the filters but the kind
 * @var int $total rows without any filter
 * @var int $urgent urgent rows without any filter
 * @var string $kind
 * @var array{site: int, customer: int, urgent: bool} $filters
 * @var array<int, string> $sites id => name
 * @var array<int, string> $customers id => name
 * @var int $siteCount
 * @var string $pairingKey
 */
use Kaleta\Core\Requests;
use Kaleta\Fleet\Inbox;

$urgencyLabel = ['urgent' => t('urgent'), 'normal' => t('waiting'), 'low' => t('when convenient')];
$urgencyClass = ['urgent' => ' stitek-chyba', 'normal' => ' stitek-koncept', 'low' => ''];
$link = fn (array $change): string => $module->url('', array_filter(array_merge(['kind' => $kind, 'site' => $filters['site'], 'customer' => $filters['customer'], 'urgent' => $filters['urgent'] ? '1' : ''], $change)));
$moment = fn (int $ts): string => format_date((new DateTimeImmutable())->setTimestamp($ts), true);
$age = function (int $since): string {
    $hours = max(0, intdiv(time() - $since, 3600));

    return $hours < 48 ? t('%d h', $hours) : t('%d days', intdiv($hours, 24));
};
$filtered = $kind !== '' || $filters['site'] > 0 || $filters['customer'] > 0 || $filters['urgent'];
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => '', 'pairingKey' => $pairingKey]) ?>
<?php if ($siteCount === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No site reports to this console yet. Make a pairing key and paste it on the site.'),
    'text' => t('When the sites report, everything that waits on them – requests, enquiries, drafts, bookings, failed updates, expiring certificates – comes together here.')]) ?>
<form class="stred" method="post" action="<?= e($module->url('pairing_key')) ?>"><?= $csrf ?><button class="tl" type="submit"><?= e(t('Add a site')) ?></button></form>
<?php else: ?>
<p class="hlaska<?= $total === 0 ? ' hlaska-ok' : '' ?>"><?= e($total === 0 ? t('Nothing waits on the %d sites.', $siteCount) : t('%d things wait on the sites, %d of them urgent.', $total, $urgent)) ?></p>
<?php if ($total > 0): ?>
<nav class="zalozky" aria-label="<?= e(t('Kind')) ?>">
	<a href="<?= e($link(['kind' => ''])) ?>"<?= $kind === '' ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t('All')) ?> (<?= $narrowed ?>)</a>
<?php foreach (Inbox::KINDS as $key => $label): if ($counts[$key] === 0 && $kind !== $key) { continue; } ?>
	<a href="<?= e($link(['kind' => $key])) ?>"<?= $kind === $key ? ' class="aktivni" aria-current="true"' : '' ?>><?= e(t($label)) ?> (<?= $counts[$key] ?>)</a>
<?php endforeach ?>
</nav>
<form class="hromadne" method="get" action="<?= e($app->url('admin.php')) ?>">
	<input type="hidden" name="module" value="fleet"><?php if ($kind !== ''): ?><input type="hidden" name="kind" value="<?= e($kind) ?>"><?php endif ?>
	<label><?= e(t('Site')) ?> <select name="site"><option value="0"><?= e(t('all')) ?></option>
<?php foreach ($sites as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $filters['site'] === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
<?php if ($customers !== []): ?>
	<label><?= e(t('Customer')) ?> <select name="customer"><option value="0"><?= e(t('all')) ?></option>
<?php foreach ($customers as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $filters['customer'] === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
	<label><input type="checkbox" name="urgent" value="1"<?= $filters['urgent'] ? ' checked' : '' ?>> <?= e(t('only urgent')) ?></label>
	<button class="navigace" type="submit"><?= e(t('Filtrovat')) ?></button>
<?php if ($filtered): ?>
	<a href="<?= e($module->url('')) ?>"><?= e(t('Clear filter')) ?></a>
<?php endif ?>
</form>
<?php if ($rows === []): ?>
<p><?= e(t('Nothing matches the filter.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis vypis-karty">
<thead><tr><th scope="col"><?= e(t('What waits')) ?></th><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('Since')) ?></th><th scope="col"><?= e(t('Urgency')) ?></th><th scope="col"><span class="navod-skryte"><?= e(t('Open')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
	<td><?php if ($r['title'] !== null): ?><strong><?= e($r['title'] !== '' ? $r['title'] : t('(untitled)')) ?></strong><br><span class="smltxt"><?= e(t(Inbox::label($r))) ?> · <?= e(t(Requests::STATUSES[$r['status']] ?? (string) $r['status'])) ?></span>
	<?php elseif ($r['expires'] !== null || $r['days'] !== null): ?><strong><?= e(t(Inbox::label($r))) ?></strong><br><span class="smltxt"><?= e($r['expires'] !== null ? t('on %s', format_date(new DateTimeImmutable((string) $r['expires']))) . ' · ' : '') ?><?= e((int) $r['days'] < 0 ? t('expired') : t('in %d days', (int) $r['days'])) ?></span>
	<?php else: ?><strong><?= e(t(Inbox::label($r))) ?></strong><?= $r['count'] !== null ? ': ' . (int) $r['count'] : '' ?><?php endif ?></td>
	<td data-popisek="<?= e(t('Site')) ?>"><a href="<?= e($module->url('detail', ['id' => $r['site_id']])) ?>"><?= e($r['site']) ?></a><?php if ($r['customer'] !== null): ?><br><span class="smltxt"><?= e($r['customer']) ?></span><?php endif ?>
	<?php if ($r['stale']): ?><br><span class="stitek" title="<?= e(t('The last report of the site is older than 26 hours – this may be out of date.')) ?>"><?= e(t('possibly out of date')) ?></span><?php endif ?></td>
	<td data-popisek="<?= e(t('Since')) ?>"><?= $r['since'] !== null ? e($moment($r['since'])) . ' <span class="smltxt">(' . e($age($r['since'])) . ')</span>' : '–' ?></td>
	<td data-popisek="<?= e(t('Urgency')) ?>"><span class="stitek<?= $urgencyClass[$r['urgency']] ?>"><?= e($urgencyLabel[$r['urgency']]) ?></span></td>
	<td data-popisek="<?= e(t('Open')) ?>"><?php if ($r['link'] !== ''): ?><a href="<?= e($r['link']) ?>" target="_blank" rel="noopener"><?= e(in_array($r['what'], ['site_down', 'site_silent'], true) ? t('Open the site') : t('Open in its administration')) ?></a><?php else: ?>–<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php endif ?>
<p class="smltxt"><?= e(t('Built from each site\'s last report (every hour) and the console\'s own checks. The links open the site\'s own administration – sign in there as usual; the console has no access to the sites.')) ?></p>
<?php endif ?>
