<?php
/**
 * One customer of the fleet console (3.30): its sites with what needs attention, how much waits and their open tasks, and
 * the console tasks about the customer itself (views/admin/tasks_panel.php – "check prices at Fysiomed before January").
 * The customer, its tasks and notes stay on the console; the sites never see them.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var string $csrf
 * @var array{id: int, name: string} $customer
 * @var list<array<string, mixed>> $sites Console::overview rows of the customer's sites
 * @var array<int, int> $waiting site id => things waiting
 * @var array<int, int> $taskCounts site id => open console tasks
 * @var array<string, mixed> $tasks Admin\TaskActions::taskPanel
 */
use Kaleta\Fleet\Console;

$reasonLabels = require __DIR__ . '/reasons.php';
$severity = fn (string $r): string => Console::REASONS[$r] >= Console::REASONS['errors'] ? 'chyba' : (Console::REASONS[$r] >= Console::REASONS['warnings'] ? 'koncept' : '');
?>
<p><a href="<?= e($module->url('customers')) ?>">← <?= e(t('Customers')) ?></a> · <a href="<?= e($module->url('', ['customer' => $customer['id']])) ?>"><?= e(t('Today')) ?></a> · <a href="<?= e($module->url('inbox', ['customer' => $customer['id']])) ?>"><?= e(t('Inbox')) ?></a></p>
<h2><?= e(t('Sites')) ?> (<?= count($sites) ?>)</h2>
<?php if ($sites === []): ?>
<p class="napoveda" data-customer-no-sites><?= e(t('The customer has no site now – it stays in the console while it has an open task.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis vypis-karty" data-customer-sites>
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('What needs attention')) ?></th><th scope="col"><?= e(t('Waiting')) ?></th><th scope="col"><?= e(t('Tasks')) ?></th><th scope="col"><?= e(t('Version')) ?></th></tr></thead>
<tbody>
<?php foreach ($sites as $s): ?>
<tr>
	<td><a href="<?= e($module->url('detail', ['id' => (int) $s['id']])) ?>"><strong><?= e((string) $s['name'] !== '' ? (string) $s['name'] : (string) $s['url']) ?></strong></a><br><span class="smltxt"><?= e((string) $s['url']) ?></span></td>
	<td data-popisek="<?= e(t('What needs attention')) ?>"><?php if ($s['reasons'] === []): ?><span class="stitek stitek-vydano"><?= e(t('ok')) ?></span><?php endif ?><?php foreach ($s['reasons'] as $r): ?><span class="stitek<?= $severity($r) !== '' ? ' stitek-' . $severity($r) : '' ?>"><?= e($reasonLabels[$r]) ?></span> <?php endforeach ?></td>
	<td data-popisek="<?= e(t('Waiting')) ?>" class="stred"><?= ($n = $waiting[(int) $s['id']] ?? 0) > 0 ? '<a href="' . e($module->url('inbox', ['site' => (int) $s['id']])) . '">' . $n . '</a>' : '0' ?></td>
	<td data-popisek="<?= e(t('Tasks')) ?>" class="stred"><?= ($n = $taskCounts[(int) $s['id']] ?? 0) > 0 ? '<a href="' . e($module->url('detail', ['id' => (int) $s['id']]) . '#tasks') . '">' . $n . '</a>' : '0' ?></td>
	<td data-popisek="<?= e(t('Version')) ?>"><?= e((string) $s['version'] !== '' ? (string) $s['version'] : '–') ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?= $app->view->render('admin/tasks_panel', $tasks + ['app' => $app, 'csrf' => $csrf]) ?>
<p class="smltxt"><?= e(t('Tasks about one site are on that site\'s page. Everyone with the console sees them; they stay on the console.')) ?></p>
