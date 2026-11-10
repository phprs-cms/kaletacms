<?php
/**
 * My work (3.24, Core\MyWork): one table per list the person may open, only lists with something in them; every row links
 * to where the work is done. Nothing at all = the empty state. 3.27: the person's tasks first, grouped overdue / today /
 * upcoming / later, each with Done and the snooze buttons (one form, the buttons post with formaction); administrators get
 * the tabs Mine and Team; "New task" (folded) is the shared form views/admin/task_form.php. 3.31: the open deals the person
 * is responsible for (Core\Deals::forOwner) – title, stage, customer, expected close, overdue marked.
 *
 * @var array{action: string, people: array<int, string>, draft: string} $newTask
 * @var string $csrf
 * @var bool $team  show the tabs (administrators)
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\MyWork $module
 * @var array<string, list<array<string, mixed>>> $lists  list => rows (Core\MyWork::lists)
 * @var array<string, class-string<Kaleta\Admin\Module>> $modules
 */
$headings = [
    'assigned' => 'Enquiries you are responsible for', // 3.32: one term for the responsible person (Core\Glossary)
    'unassigned' => 'Unanswered enquiries nobody is responsible for', // 3.32 (R1): read ones too – new, or from the last 30 days (MyWork::UNASSIGNED_DAYS)
    'pages' => 'Pages you are responsible for',
    'items' => 'Collection items you are responsible for',
    'requests' => 'Your requests Claude answered',
    'deals' => 'Deals you are responsible for', // 3.31
];
$reason = fn (string $code, array $row): string => match ($code) {
    'overdue' => t('Review overdue since %s', format_date((string) $row['review_by'])),
    'review' => t('Review by %s', format_date((string) $row['review_by'])),
    'draft' => t('Unpublished changes'),
    'comments' => t('Unresolved comments: %d', (int) $row['comments']),
    'hidden' => t('Hidden – not on the site yet'),
    default => '',
};
$filled = array_filter($lists);
$tasks = $filled['tasks'] ?? [];
unset($filled['tasks']);
$groups = ['overdue' => 'Overdue', 'today' => 'Today', 'upcoming' => 'Upcoming (7 days)', 'later' => 'Later or without a date'];
$due = fn (?string $at): string => $at === null ? '' : format_date($at, substr($at, 11, 5) !== Kaleta\Core\Tasks::DEFAULT_TIME);
// 3.32 (U27): a task about an enquiry, a deal or a customer names who and what (Core\RecordTitle::place) for a section the
// person may open; the labels below stay for everything else
$aboutText = fn (array $about): string => isset($about['name'], $modules[Kaleta\Core\Assignment::KINDS[$about['kind']] ?? '']) ? Kaleta\Core\RecordTitle::place($about, $modules) : match ($about['kind']) {
    'enquiry' => t('Enquiry #%s · %s', (string) $about['id'], $about['title']),
    'page' => t('Page “%s”', $about['title']),
    'contact' => t('Customer “%s”', $about['title']), // 3.28
    'company' => t('Company “%s”', $about['title']),
    'fleet_site' => t('Site “%s”', $about['title']), // 3.30: a site of a fleet console
    'fleet_customer' => t('Console customer “%s”', $about['title']),
    'deal' => t('Deal #%s', (string) $about['id']), // 3.31: by its number, as the bell names it
    default => t('“%s” in %s', $about['title'], (string) ($about['collection'] ?? '')),
};
?>
<?php if ($team): ?>
<?= $app->view->render('admin/my_work/tabs', ['module' => $module, 'current' => '']) ?>
<?php endif ?>
<details class="ukol-pridat moje-novy-ukol" data-new-task<?= $newTask['draft'] !== '' ? ' open' : '' ?>><summary><?= e(t('New task')) ?></summary>
<?= $app->view->render('admin/task_form', ['csrf' => $csrf, 'action' => $newTask['action'], 'fields' => [], 'people' => $newTask['people'], 'me' => $app->auth()->id(), 'draft' => $newTask['draft'], 'prefix' => 'novy-ukol']) ?>
</details>
<?php if ($tasks !== []): ?>
<section class="moje-prace" data-list="tasks" aria-labelledby="moje-tasks">
<h2 id="moje-tasks"><?= e(t('Your tasks')) ?> <small><?= format_count(count($tasks)) ?></small></h2>
<form method="post" action="<?= e($module->url('complete')) ?>">
<?= $csrf ?>
<?php foreach ($groups as $group => $heading): ?>
<?php $rows = array_values(array_filter($tasks, fn (array $t): bool => $t['group'] === $group)); if ($rows === []) { continue; } ?>
<h3 class="moje-skupina" data-group="<?= e($group) ?>"><?= e(t($heading)) ?> <small><?= format_count(count($rows)) ?></small></h3>
<div class="tab-obal">
<table class="vypis vypis-karty">
<thead><tr><th scope="col"><?= e(t('Task')) ?></th><th scope="col"><?= e(t('Due')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr id="task-<?= (int) $r['id'] ?>" data-task="<?= (int) $r['id'] ?>">
	<td><?php /* 3.32 (U35): the title opens the record the task is about (its task panel); a task about nothing opens Edit */ ?><a class="moje-ukol-nazev" href="<?= e($r['about'] !== null ? $app->url($r['about']['url']) . '#tasks' : '#task-' . (int) $r['id'] . '-edit') ?>"><?= e($r['title'] !== '' ? $r['title'] : t('(no title)')) ?></a><?php if ($r['about'] !== null): ?><br><a class="smltxt" href="<?= e($app->url($r['about']['url'])) ?>"><?= e($aboutText($r['about'])) ?></a><?php endif ?></td>
	<td class="cislo"><?= $r['due_at'] === null ? '<span class="smltxt">' . e(t('no date')) . '</span>' : ($group === 'overdue' ? '<span class="stitek stitek-koncept">' . e($due($r['due_at'])) . '</span>' : e($due($r['due_at']))) ?></td>
	<td class="akce"><button class="navigace" type="submit" name="id" value="<?= (int) $r['id'] ?>"><?= e(t('Done')) ?></button>
		<button class="navigace" type="submit" formaction="<?= e($module->url('snooze', ['by' => 'tomorrow'])) ?>" name="id" value="<?= (int) $r['id'] ?>"><?= e(t('Tomorrow')) ?></button>
		<button class="navigace" type="submit" formaction="<?= e($module->url('snooze', ['by' => '3days'])) ?>" name="id" value="<?= (int) $r['id'] ?>"><?= e(t('In 3 days')) ?></button>
<?php $editId = 'task-' . (int) $r['id'] . '-edit'; $editForm = 'ukol-uprava-' . (int) $r['id']; $remindChoice = Kaleta\Admin\TaskPanel::remindChoice($r['due_at'], $r['remind_at']); ?>
		<details class="komentar-upravit ukol-upravit moje-ukol-upravit" id="<?= e($editId) ?>"><summary><?= e(t('Edit')) ?></summary><?php /* 3.32 (U35): the fields belong to a form after the table (form=…) – forms cannot nest */ ?>
			<div class="ukol-pole ukol-pole-uprava">
				<div class="ukol-pole-nazev"><label for="<?= e($editId) ?>-nazev"><?= e(t('Task')) ?></label><input form="<?= e($editForm) ?>" class="textpole siroke" type="text" id="<?= e($editId) ?>-nazev" name="title" maxlength="<?= Kaleta\Core\Tasks::TITLE ?>" value="<?= e($r['title']) ?>" required></div>
				<div><label for="<?= e($editId) ?>-datum"><?= e(t('Due')) ?></label><input form="<?= e($editForm) ?>" class="textpole" type="date" id="<?= e($editId) ?>-datum" name="due_date" value="<?= e(substr((string) $r['due_at'], 0, 10)) ?>"></div>
				<div><label for="<?= e($editId) ?>-cas"><?= e(t('Time')) ?></label><input form="<?= e($editForm) ?>" class="textpole" type="time" id="<?= e($editId) ?>-cas" name="due_time" value="<?= e(substr((string) $r['due_at'], 11, 5)) ?>"></div>
				<div><label for="<?= e($editId) ?>-pripominka"><?= e(t('Reminder')) ?></label><select form="<?= e($editForm) ?>" id="<?= e($editId) ?>-pripominka" name="remind">
<?php foreach (Kaleta\Admin\TaskPanel::REMIND_LABELS + ($remindChoice === 'keep' ? ['keep' => ''] : []) as $remindKey => $remindLabel): ?>
					<option value="<?= e($remindKey) ?>"<?= $remindKey === $remindChoice ? ' selected' : '' ?>><?= e($remindKey === 'keep' ? t('As set: %s', format_date((string) $r['remind_at'], true)) : t($remindLabel)) ?></option>
<?php endforeach ?>
				</select></div>
			</div>
			<p class="tlacitka"><button form="<?= e($editForm) ?>" class="navigace" type="submit"><?= e(t('Save the task')) ?></button></p>
		</details></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endforeach ?>
</form>
<?php foreach ($tasks as $r): /* 3.32 (U35): the edit form of each task (its fields are in the row, form=…) */ ?>
<form id="ukol-uprava-<?= (int) $r['id'] ?>" method="post" action="<?= e($module->url('task_edit')) ?>" data-task-edit hidden><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $r['id'] ?>"><input type="hidden" name="assignee_id" value="0"></form>
<?php endforeach ?>
</section>
<?php endif ?>
<?php if ($filled === [] && $tasks === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'moje', 'heading' => t('Nothing waits for you right now.'),
    'text' => t('Your tasks, enquiries assigned to you, the pages and collection items you are responsible for when a review is due or changes wait unpublished, and your requests Claude answered appear here.'),
    'action' => [$app->url('admin.php'), t('Go to the dashboard')]]) ?>
<?php elseif ($filled !== []): ?>
<p class="napoveda"><?= e(t('Every row opens the place where the work is done. You are responsible for a page or an item when its settings name you as the responsible person.')) ?></p>
<?php endif ?>
<?php foreach ($filled as $list => $rows): ?>
<section class="moje-prace" data-list="<?= e($list) ?>" aria-labelledby="moje-<?= e($list) ?>">
<h2 id="moje-<?= e($list) ?>"><?= e(t($headings[$list])) ?> <small><?= format_count(count($rows)) ?></small></h2>
<div class="tab-obal">
<table class="vypis">
<?php if ($list === 'assigned' || $list === 'unassigned'): ?>
<thead><tr><th scope="col"><?= e(t('Enquiry')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Received')) ?></th><?php if ($list === 'unassigned'): ?><th scope="col"><?= e(t('Actions')) ?></th><?php endif ?></tr></thead>
<tbody>
<?php foreach ($rows as $r): /* 3.32 (U27): who and what first, the form and its number under it */ ?>
<tr data-enquiry="<?= (int) $r['id'] ?>">
	<td><a href="<?= e($app->url($r['url'])) ?>"><?= bdi((string) ($r['name'] ?? $r['title'])) ?></a><br><span class="smltxt"><?= bdi(($r['title'] !== '' ? $r['title'] : t('Enquiry')) . ' #' . (int) $r['id']) ?></span></td>
	<td><?= $r['status'] === 'new' ? '<span class="stitek stitek-koncept">' . e(t('new')) . '</span>' : '' ?></td>
	<td class="cislo"><?= e(format_date($r['date'], true)) ?></td>
<?php if ($list === 'unassigned'): /* 3.32 (U35, R1): take an unanswered enquiry nobody handles; the Who cell above names it */ ?>
	<td class="akce"><form method="post" action="<?= e($module->url('take')) ?>" data-enquiry-take><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $r['id'] ?>"><button class="navigace" type="submit"><?= e(t('Take it')) ?></button></form></td>
<?php endif ?>
</tr>
<?php endforeach ?>
</tbody>
<?php elseif ($list === 'deals'): /* 3.31: the open deals, overdue first */ ?>
<thead><tr><th scope="col"><?= e(t('Deal')) ?></th><th scope="col"><?= e(t('Stage')) ?></th><th scope="col"><?= e(t('Expected close')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr data-deal="<?= (int) $r['id'] ?>">
	<td><a href="<?= e($app->url($r['url'])) ?>"><?= bdi($r['title']) ?></a><?= ($r['contact'] ?? null) !== null ? '<br><span class="smltxt">' . bdi((string) $r['contact']) . '</span>' : '' ?></td>
	<td><span class="stitek"><?= e($r['stage_label']) ?></span></td>
	<td class="cislo"><?= $r['expected_close'] === null ? '<span class="smltxt">' . e(t('no date')) . '</span>' : ($r['overdue'] ? '<span class="stitek stitek-chyba">' . e(t('overdue since %s', format_date($r['expected_close']))) . '</span>' : e(format_date($r['expected_close']))) ?></td>
</tr>
<?php endforeach ?>
</tbody>
<?php elseif ($list === 'requests'): ?>
<thead><tr><th scope="col"><?= e(t('Request')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Updated')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
	<td><a href="<?= e($app->url($r['url'])) ?>"><?= e($r['title']) ?></a></td>
	<td><span class="stitek"><?= e(t(Kaleta\Core\Requests::STATUSES[$r['status']] ?? $r['status'])) ?></span></td>
	<td class="cislo"><?= e(format_date($r['date'], true)) ?></td>
</tr>
<?php endforeach ?>
</tbody>
<?php else: ?>
<thead><tr><th scope="col"><?= e(t($list === 'pages' ? 'Page' : 'Item')) ?></th><th scope="col"><?= e(t('What waits')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
	<td><a href="<?= e($app->url($r['url'])) ?>"><?= e($r['title']) ?></a><?= isset($r['collection']) ? '<br><span class="smltxt">' . e($r['collection']) . '</span>' : '' ?></td>
	<td><?php foreach ($r['reasons'] as $code): ?><span class="stitek<?= $code === 'overdue' ? ' stitek-koncept' : '' ?>"><?= e($reason($code, $r)) ?></span> <?php endforeach ?></td>
</tr>
<?php endforeach ?>
</tbody>
<?php endif ?>
</table>
</div>
</section>
<?php endforeach ?>
