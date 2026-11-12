<?php
/**
 * Today (3.29, the console opens on it): what needs attention across the sites, ranked by Fleet\Checks::today – red
 * before amber, the check's weight, the oldest first. Each row opens the screen that fixes it on the site: a plain link
 * built by the console from the site's known address (no token, no sign-in on the owner's behalf). 3.30: the overdue console
 * tasks (Fleet\ConsoleTasks, kind "task") are ranked among them, amber; their row opens the task on the console. 3.31.1: a
 * rollout held back by the pilot sites for more than 3 days (Fleet\Rollout::heldItem, kind "rollout") – one amber row
 * that opens the Rollout tab of the console.
 * 3.39: grouped by check (Fleet\Health::groups) – the red groups open on top, the amber ones below (folded when they hold more
 * than Health::FOLD_OVER rows), each saying how
 * many sites it holds – and the checks the operator accepted (Fleet\Acceptances: a note, a last day, who and when) in a folded
 * group of their own. Accept is a small form on each row of a check; it is kept on the console and changes nothing on the site.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $csrf
 * @var list<array<string, mixed>> $groups Health::groups over the open items, filtered
 * @var list<array<string, mixed>> $accepted the accepted items (each with 'accepted'), filtered
 * @var int $total items without a filter (open and accepted)
 * @var string $today the console's day (Y-m-d)
 * @var array{sites: int, bad: int, warn: int, ok_sites: int, not_reporting: int, to_update: int, accepted: int} $summary
 * @var int $customer 0 = all, -1 = sites without a customer
 * @var string $severity '' | bad | warn
 * @var array<int, string> $customers id => name
 * @var int $now
 * @var string $pairingKey
 * @var int $overdueTasks overdue console tasks (3.30)
 * @var array<int, string> $names user id => name of the people the overdue tasks are for and who accepted
 * @var int $me the signed-in user
 */
use Kaleta\Fleet\Acceptances;
use Kaleta\Fleet\Health;

$filtered = $customer !== 0 || $severity !== '';
$severityLabel = ['bad' => t('Needs action'), 'warn' => t('Warning')];
$severityClass = ['bad' => ' stitek-chyba', 'warn' => ' stitek-koncept'];
$until = (new DateTimeImmutable($today))->modify('+' . Acceptances::DEFAULT_DAYS . ' days')->format('Y-m-d');
$latest = (new DateTimeImmutable($today))->modify('+' . Acceptances::MAX_DAYS . ' days')->format('Y-m-d');
$since = fn (?int $at): string => $at !== null ? '<span title="' . e(format_date((new DateTimeImmutable())->setTimestamp($at), true)) . '">' . e(Health::age($at, $now)) . '</span>' : '–';
$bad = array_values(array_filter($groups, fn (array $g): bool => $g['state'] === 'bad'));
$warn = array_values(array_filter($groups, fn (array $g): bool => $g['state'] !== 'bad'));
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => '', 'pairingKey' => $pairingKey]) ?>
<?php if ($summary['sites'] === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('No site reports to this console yet. Make a pairing key and paste it on the site.'),
    'text' => t('When the sites report, what needs your attention today – a site down, a failing backup, an expiring certificate, an update – comes together here.')]) ?>
<form class="stred" method="post" action="<?= e($module->url('pairing_key')) ?>"><?= $csrf ?><button class="tl" type="submit"><?= e(t('Add a site')) ?></button></form>
<?php else: ?>
<ul class="flotila-souhrn" aria-label="<?= e(t('Summary of the sites')) ?>">
	<li class="flotila-souhrn-bad"><strong><?= $summary['bad'] ?></strong> <?= e(t('need action')) ?></li>
	<li class="flotila-souhrn-warn"><strong><?= $summary['warn'] ?></strong> <?= e(t('warnings')) ?></li>
	<li><strong><?= $summary['ok_sites'] ?></strong> <?= e(t('of %d sites OK', $summary['sites'])) ?></li>
	<li><a href="<?= e($module->url('health')) ?>"><strong><?= $summary['not_reporting'] ?></strong> <?= e(t('not reporting')) ?></a></li>
	<li><a href="<?= e($module->url('health')) ?>"><strong><?= $summary['to_update'] ?></strong> <?= e(t('to update to see every check')) ?></a></li>
<?php if ($overdueTasks > 0): ?>
	<li class="flotila-souhrn-warn" data-fleet-overdue-tasks><strong><?= $overdueTasks ?></strong> <?= e(t('overdue tasks')) ?></li>
<?php endif ?>
<?php if ($summary['accepted'] > 0): ?>
	<li data-fleet-accepted-count><strong><?= $summary['accepted'] ?></strong> <?= e(t('accepted')) ?></li>
<?php endif ?>
</ul>
<?php if ($total === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'web', 'heading' => t('Everything is green on the %d sites.', $summary['sites']),
    'text' => t('Nothing needs your attention today. The health matrix shows every check of every site.'), 'action' => [$module->url('health'), t('Open the health matrix')]]) ?>
<?php else: ?>
<form class="hromadne" method="get" action="<?= e($app->url('admin.php')) ?>">
	<input type="hidden" name="module" value="fleet">
<?php if ($customers !== []): ?>
	<label><?= e(t('Customer')) ?> <select name="customer"><option value="0"><?= e(t('all')) ?></option><option value="-1"<?= $customer === -1 ? ' selected' : '' ?>><?= e(t('without a customer')) ?></option>
<?php foreach ($customers as $id => $name): ?>
		<option value="<?= (int) $id ?>"<?= $customer === (int) $id ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select></label>
<?php endif ?>
	<label><?= e(t('Severity')) ?> <select name="severity"><option value=""><?= e(t('all')) ?></option>
		<option value="bad"<?= $severity === 'bad' ? ' selected' : '' ?>><?= e(t('Needs action')) ?></option>
		<option value="warn"<?= $severity === 'warn' ? ' selected' : '' ?>><?= e(t('Warning')) ?></option>
	</select></label>
	<button class="navigace" type="submit"><?= e(t('Filtrovat')) ?></button>
<?php if ($filtered): ?>
	<a href="<?= e($module->url('')) ?>"><?= e(t('Clear filter')) ?></a>
<?php endif ?>
</form>
<?php if ($groups === [] && $accepted === []): ?>
<p><?= e(t('Nothing matches the filter.')) ?></p>
<?php elseif ($groups === []): ?>
<p class="hlaska hlaska-ok"><?= e(t('Nothing is open – everything that needs attention was accepted. The accepted states come back when they change or on their last day.')) ?></p>
<?php endif ?>
<?php foreach ([['bad', t('Needs action'), $bad], ['warn', t('Warnings'), $warn]] as [$part, $heading, $partGroups]): if ($partGroups === []) { continue; } ?>
<h2 class="flotila-dnes-cast"><?= e($heading) ?></h2>
<?php foreach ($partGroups as $g): ?>
<details class="stav-skupina flotila-kontrola" data-fleet-group="<?= e($g['check']) ?>"<?= $part === 'bad' || count($g['items']) <= Health::FOLD_OVER ? ' open' : '' ?>>
	<summary><h3><?= e($g['label']) ?></h3> <span class="stitek<?= $severityClass[$g['state']] ?>"><span aria-hidden="true"><?= Health::SYMBOLS[$g['state']] ?></span> <?= e($severityLabel[$g['state']]) ?></span>
		<span class="smltxt" data-fleet-group-sites><?= e($g['check'] === 'rollout' ? t('the whole fleet') : t('%s sites', format_count($g['sites']))) ?><?= $g['bad'] > 0 && $g['warn'] > 0 ? ' · ' . e(t('%d need action, %d warnings', $g['bad'], $g['warn'])) : '' ?></span></summary>
<div class="tab-obal"><table class="vypis vypis-karty flotila-dnes">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('What needs attention')) ?></th><th scope="col"><?= e(t('Since')) ?></th><th scope="col"><span class="navod-skryte"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($g['items'] as $i): ?>
<?php if (($i['kind'] ?? '') === 'task'): /* 3.30: an overdue console task – it opens on the console */ ?>
<tr class="flotila-<?= e($i['state']) ?>" data-fleet-task="<?= (int) $i['task_id'] ?>">
	<td><?php if ($i['site_id'] > 0): ?><a href="<?= e($module->url('detail', ['id' => $i['site_id']])) ?>"><?= e($i['site']) ?></a><?php if ($i['customer_id'] !== null && isset($customers[$i['customer_id']])): ?><br><span class="smltxt"><?= e($customers[$i['customer_id']]) ?></span><?php endif ?>
		<?php else: ?><a href="<?= e($module->url('customer_detail', ['id' => (int) $i['customer_id']])) ?>"><?= e($i['site']) ?></a><br><span class="smltxt"><?= e(t('Customer')) ?></span><?php endif ?></td>
	<td data-popisek="<?= e(t('What needs attention')) ?>"><strong><?= e($i['label'] !== '' ? $i['label'] : t('(no title)')) ?></strong><br><span class="smltxt"><?= e(t('due %s', format_date((string) $i['due_at'], true))) ?><?= $i['assignee_id'] !== null ? ' · ' . e((int) $i['assignee_id'] === $me ? t('For me') : t('For %s', $names[(int) $i['assignee_id']] ?? '')) : '' ?></span></td>
	<td data-popisek="<?= e(t('Since')) ?>"><?= $i['since'] !== null ? '<span title="' . e(format_date((string) $i['due_at'], true)) . '">' . e(Health::age($i['since'], $now)) . '</span>' : '–' ?></td>
	<td data-popisek="<?= e(t('Actions')) ?>" class="akce"><a class="navigace" href="<?= e($app->url($i['link'])) ?>"><?= e(t('Open')) ?><span class="navod-skryte"> – <?= e($i['label']) ?>, <?= e($i['site']) ?></span></a></td>
</tr>
<?php continue; endif ?>
<?php if (($i['kind'] ?? '') === 'rollout'): /* 3.31.1: the rollout held back by the pilots – it opens the Rollout tab */ ?>
<tr class="flotila-<?= e($i['state']) ?>" data-fleet-rollout-held="<?= e((string) $i['version']) ?>">
	<td><a href="<?= e($module->url('rollout')) ?>"><?= e(t('Rollout')) ?></a></td>
	<td data-popisek="<?= e(t('What needs attention')) ?>"><strong><?= e($i['label']) ?></strong><br><span class="smltxt"><?= e($i['detail']) ?></span></td>
	<td data-popisek="<?= e(t('Since')) ?>"><?= $since($i['since']) ?></td>
	<td data-popisek="<?= e(t('Actions')) ?>" class="akce"><a class="navigace" href="<?= e($app->url($i['link'])) ?>"><?= e(t('Open')) ?><span class="navod-skryte"> – <?= e($i['label']) ?></span></a></td>
</tr>
<?php continue; endif ?>
<tr class="flotila-<?= e($i['state']) ?>">
	<td><a href="<?= e($module->url('detail', ['id' => $i['site_id']])) ?>"><?= e($i['site']) ?></a><?php if ($i['customer_id'] !== null && isset($customers[$i['customer_id']])): ?><br><span class="smltxt"><?= e($customers[$i['customer_id']]) ?></span><?php endif ?></td>
	<td data-popisek="<?= e(t('What needs attention')) ?>"><?= $g['bad'] > 0 && $g['warn'] > 0 ? '<span class="stitek' . $severityClass[$i['state']] . '"><span aria-hidden="true">' . Health::SYMBOLS[$i['state']] . '</span> ' . e($severityLabel[$i['state']]) . '</span> ' : '' ?><?= e($i['detail']) ?></td>
	<td data-popisek="<?= e(t('Since')) ?>"><?= $since($i['since']) ?></td>
	<td data-popisek="<?= e(t('Actions')) ?>" class="akce"><?php if ($i['link'] !== ''): ?><a class="navigace" href="<?= e($i['link']) ?>" target="_blank" rel="noopener"><?= e(t('Open')) ?><span class="navod-skryte"> – <?= e($i['label']) ?>, <?= e($i['site']) ?></span></a><?php endif ?>
		<details class="flotila-prijmout" data-fleet-accept><summary class="navigace"><?= e(t('Accept')) ?><span class="navod-skryte"> – <?= e($i['label']) ?>, <?= e($i['site']) ?></span></summary>
		<form class="formular" method="post" action="<?= e($module->url('accept')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $i['site_id'] ?>"><input type="hidden" name="check" value="<?= e($i['check']) ?>">
			<p class="smltxt"><?= e(t('Accept this state on the console – it leaves Today and sends no alert until the day below or until its state changes. Nothing changes on the site.')) ?></p>
			<label><?= e(t('Why')) ?> <input type="text" name="note" maxlength="<?= Acceptances::NOTE_LENGTH ?>" required></label>
			<label><?= e(t('Until')) ?> <input type="date" name="until" value="<?= e($until) ?>" min="<?= e($today) ?>" max="<?= e($latest) ?>" required></label>
			<button class="tl" type="submit"><?= e(t('Accept')) ?></button>
		</form></details></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
</details>
<?php endforeach ?>
<?php endforeach ?>
<?php if ($accepted !== []): ?>
<details class="stav-skupina flotila-prijate" data-fleet-accepted>
	<summary><h2><?= e(t('Accepted')) ?></h2> <span class="stitek"><?= e(format_count(count($accepted))) ?></span> <span class="smltxt"><?= e(t('until their last day or until their state changes')) ?></span></summary>
<div class="tab-obal"><table class="vypis vypis-karty flotila-dnes">
<thead><tr><th scope="col"><?= e(t('What was accepted')) ?></th><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('Until')) ?></th><th scope="col"><?= e(t('Note')) ?></th><th scope="col"><span class="navod-skryte"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($accepted as $i): $a = $i['accepted']; ?>
<tr data-fleet-accepted-item="<?= e($i['check']) ?>">
	<td><strong><?= e($i['label']) ?></strong> <span class="stitek<?= $severityClass[$i['state']] ?>"><span aria-hidden="true"><?= Health::SYMBOLS[$i['state']] ?></span> <?= e($severityLabel[$i['state']]) ?></span><br><span class="smltxt"><?= e($i['detail']) ?></span></td>
	<td data-popisek="<?= e(t('Site')) ?>"><a href="<?= e($module->url('detail', ['id' => $i['site_id']])) ?>"><?= e($i['site']) ?></a><?php if ($i['customer_id'] !== null && isset($customers[$i['customer_id']])): ?><br><span class="smltxt"><?= e($customers[$i['customer_id']]) ?></span><?php endif ?></td>
	<td data-popisek="<?= e(t('Until')) ?>"><?= e(format_date((string) $a['until'])) ?></td>
	<td data-popisek="<?= e(t('Note')) ?>"><?= e((string) $a['note']) ?><br><span class="smltxt"><?= e($a['by'] !== null && isset($names[$a['by']]) ? t('Accepted by %s on %s', $names[$a['by']], format_date((string) $a['at'], true)) : t('Accepted on %s', format_date((string) $a['at'], true))) ?></span></td>
	<td data-popisek="<?= e(t('Actions')) ?>" class="akce"><form class="vradku" method="post" action="<?= e($module->url('unaccept')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $i['site_id'] ?>"><input type="hidden" name="check" value="<?= e($i['check']) ?>">
		<button class="navigace" type="submit"><?= e(t('Withdraw')) ?><span class="navod-skryte"> – <?= e($i['label']) ?>, <?= e($i['site']) ?></span></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
</details>
<?php endif ?>
<?php endif ?>
<p class="smltxt"><?= e(t('Built from each site\'s last report (every hour) and the console\'s own uptime checks. Open leads to the screen of the site\'s own administration that fixes it – sign in there as usual; the console has no access to the sites.')) ?></p>
<?php endif ?>
