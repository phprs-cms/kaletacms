<?php
/**
 * The Team tab of My work (3.27, administrators, Core\Tasks::team): open tasks per person – overdue, today, upcoming
 * (7 days), later or without a date. Tasks of a removed or blocked account or handed to nobody are counted too, and their
 * rows link to "Not assigned" below (Core\Tasks::unassigned): every such task with what it is about, its due day and
 * "Reassign to…" (the people its own form would offer, Admin\TaskPanel::people) – one small form per task posting
 * MyWork::actionReassign, so nothing is lost.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\MyWork $module
 * @var string $csrf
 * @var array<int, array{name: string, overdue: int, today: int, upcoming: int, later: int, open: int, unassigned: bool}> $people
 * @var list<array<string, mixed>> $unassigned  rows of Tasks::unassigned + about (Tasks::labels), people (id => name), was (who had it)
 * @var array<string, string> $modules the sections the administrator may open (3.32, Core\RecordTitle::place)
 */
$me = $app->auth()->id();
$due = fn (?string $at): string => $at === null ? '' : format_date($at, substr($at, 11, 5) !== Kaleta\Core\Tasks::DEFAULT_TIME);
$aboutText = fn (array $about): string => isset($about['name'], $modules[Kaleta\Core\Assignment::KINDS[$about['kind']] ?? '']) ? Kaleta\Core\RecordTitle::place($about, $modules) : match ($about['kind']) { // 3.32
    'enquiry' => t('Enquiry #%s · %s', (string) $about['id'], $about['title']),
    'page' => t('Page “%s”', $about['title']),
    'contact' => t('Customer “%s”', $about['title']), // 3.28
    'company' => t('Company “%s”', $about['title']),
    'fleet_site' => t('Site “%s”', $about['title']), // 3.30: a site of a fleet console
    'fleet_customer' => t('Console customer “%s”', $about['title']),
    'deal' => t('Deal #%s', (string) $about['id']), // 3.31: by its number, as the bell names it
    'application' => t('Application #%s · %s', (string) $about['id'], $about['title'] !== '' ? $about['title'] : t('no position')), // 3.34: the number and the position, as the bell names it
    'quote' => t('Quote %s', $about['title']), // 3.40: a follow-up of a quote, by its number only
    default => t('“%s” in %s', $about['title'], (string) ($about['collection'] ?? '')),
};
?>
<?= $app->view->render('admin/my_work/tabs', ['module' => $module, 'current' => 'team']) ?>
<?php if ($people === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'moje', 'heading' => t('Nobody has an open task.'),
    'text' => t('Tasks the team creates – for themselves or for a colleague, in the administration or through Claude – are counted here per person.')]) ?>
<?php else: ?>
<p class="napoveda"><?= e(t('Open tasks per person. Each person completes and snoozes their own in My work.')) ?></p>
<section class="moje-prace" data-list="team" aria-labelledby="moje-team">
<h2 id="moje-team"><?= e(t('Open tasks of the team')) ?></h2>
<div class="tab-obal">
<table class="vypis vypis-karty">
<thead><tr><th scope="col"><?= e(t('Person')) ?></th><th scope="col" class="cislo"><?= e(t('Overdue')) ?></th><th scope="col" class="cislo"><?= e(t('Today')) ?></th>
	<th scope="col" class="cislo"><?= e(t('Upcoming')) ?></th><th scope="col" class="cislo"><?= e(t('Later')) ?></th><th scope="col" class="cislo"><?= e(t('All open')) ?></th></tr></thead>
<tbody>
<?php foreach ($people as $p): ?>
<tr<?= $p['unassigned'] ? ' data-unassigned' : '' ?>>
	<th scope="row"><?= $p['unassigned'] && $unassigned !== [] ? '<a href="#unassigned">' . e($p['name']) . '</a>' : e($p['name']) ?></th>
	<td class="cislo"><?= $p['overdue'] > 0 ? '<span class="stitek stitek-koncept">' . format_count($p['overdue']) . '</span>' : '0' ?></td>
	<td class="cislo"><?= format_count($p['today']) ?></td>
	<td class="cislo"><?= format_count($p['upcoming']) ?></td>
	<td class="cislo"><?= format_count($p['later']) ?></td>
	<td class="cislo"><?= format_count($p['open']) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</section>
<?php endif ?>
<?php if ($unassigned !== []): ?>
<section class="moje-prace" id="unassigned" data-list="unassigned" aria-labelledby="moje-unassigned">
<h2 id="moje-unassigned"><?= e(t('Not assigned')) ?> <small><?= format_count(count($unassigned)) ?></small></h2>
<p class="napoveda"><?= e(t('Open tasks nobody active has – handed to nobody, or left on a removed or blocked account. Give each to a colleague; they are notified.')) ?></p>
<div class="tab-obal">
<table class="vypis vypis-karty">
<thead><tr><th scope="col"><?= e(t('Task')) ?></th><th scope="col"><?= e(t('Due')) ?></th><th scope="col"><?= e(t('Was for')) ?></th><th scope="col"><?= e(t('Reassign to…')) ?></th></tr></thead>
<tbody>
<?php foreach ($unassigned as $r): ?>
<tr id="task-<?= (int) $r['id'] ?>" data-task="<?= (int) $r['id'] ?>">
	<td><?= e($r['title'] !== '' ? $r['title'] : t('(no title)')) ?><?php if ($r['about'] !== null): ?><br><a class="smltxt" href="<?= e($app->url($r['about']['url'])) ?>"><?= e($aboutText($r['about'])) ?></a><?php endif ?></td>
	<td class="cislo"><?= $r['due_at'] === null ? '<span class="smltxt">' . e(t('no date')) . '</span>' : ($r['group'] === 'overdue' ? '<span class="stitek stitek-koncept">' . e($due($r['due_at'])) . '</span>' : e($due($r['due_at']))) ?></td>
	<td><?= e((string) $r['was']) ?></td>
	<td class="akce"><?php if ($r['people'] === []): ?><span class="smltxt"><?= e(t('Nobody can be given this task now – what it is about is gone, in the trash or switched off.')) ?></span><?php else: ?>
		<form class="vradku moje-predat" method="post" action="<?= e($module->url('reassign')) ?>" data-task-reassign>
		<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
		<select id="reassign-<?= (int) $r['id'] ?>" name="assignee_id" required aria-label="<?= e(t('Reassign to…')) ?>">
			<option value=""><?= e(t('Choose…')) ?></option>
<?php foreach ($r['people'] as $id => $name): ?>
			<option value="<?= (int) $id ?>"><?= e((int) $id === $me ? t('Me (%s)', $name) : $name) ?></option>
<?php endforeach ?>
		</select>
		<button class="navigace" type="submit"><?= e(t('Save')) ?></button>
		</form><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</section>
<?php endif ?>
