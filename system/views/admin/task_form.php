<?php
/**
 * "Add a task" (3.27, Core\Tasks; the fields are read by Admin\TaskPanel::fromForm): the title, the person (me by default),
 * the due date with an optional time, an optional earlier reminder. "Today", "Tomorrow", "In 3 days" and "In a week" add
 * the task at once with that due day. No script needed. The task panel of a record includes it; My work can include it
 * for a task about nothing (no hidden fields naming a record).
 *
 * @var string $csrf
 * @var string $action the URL of the action that takes op=add
 * @var array<string, int|string> $fields hidden fields naming the record ([] = none)
 * @var array<int, string> $people who may get the task (user id => name)
 * @var int $me the signed-in user (chosen by default)
 * @var string $draft a refused title to show again
 * @var string $prefix makes the ids of the fields unique on the page
 */
$prefix ??= 'ukol';
?>
<form method="post" action="<?= e($action) ?>" class="ukol-novy" data-task-add>
	<?= $csrf ?><?php foreach ($fields as $name => $value): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endforeach ?><input type="hidden" name="op" value="add">
	<div class="ukol-pole">
		<div class="ukol-pole-nazev"><label for="<?= e($prefix) ?>-nazev"><?= e(t('Task')) ?></label><input class="textpole siroke" type="text" id="<?= e($prefix) ?>-nazev" name="title" maxlength="<?= Kaleta\Core\Tasks::TITLE ?>" value="<?= e($draft) ?>" required placeholder="<?= e(t('e.g. Call back about the offer')) ?>"></div>
		<div><label for="<?= e($prefix) ?>-kdo"><?= e(t('Responsible person')) ?></label>
			<select id="<?= e($prefix) ?>-kdo" name="assignee_id">
<?php foreach ($people as $id => $name): ?>
				<option value="<?= (int) $id ?>"<?= (int) $id === $me ? ' selected' : '' ?>><?= e((int) $id === $me ? t('Me (%s)', $name) : $name) ?></option>
<?php endforeach ?>
			</select></div>
		<div><label for="<?= e($prefix) ?>-datum"><?= e(t('Due')) ?></label><input class="textpole" type="date" id="<?= e($prefix) ?>-datum" name="due_date"></div>
		<div><label for="<?= e($prefix) ?>-cas"><?= e(t('Time')) ?></label><input class="textpole" type="time" id="<?= e($prefix) ?>-cas" name="due_time"></div>
		<div><label for="<?= e($prefix) ?>-pripominka"><?= e(t('Reminder')) ?></label>
			<select id="<?= e($prefix) ?>-pripominka" name="remind">
<?php foreach (Kaleta\Admin\TaskPanel::REMIND_LABELS as $key => $label): ?>
				<option value="<?= e($key) ?>"><?= e(t($label)) ?></option>
<?php endforeach ?>
			</select></div>
	</div>
	<p class="napoveda"><?= e(t('Without a time it is due at %s. The person gets a notification when it is due, or at the reminder.', Kaleta\Core\Tasks::DEFAULT_TIME)) ?></p>
	<div class="ukol-tlacitka">
		<button class="tl" type="submit"><?= e(t('Add task')) ?></button>
		<span class="ukol-rychle" role="group" aria-label="<?= e(t('Add it with a due day')) ?>"><span class="napoveda-inline"><?= e(t('or add it for:')) ?></span>
<?php foreach (Kaleta\Admin\TaskPanel::QUICK as $key => $days): ?>
			<button class="navigace" type="submit" name="due_quick" value="<?= e($key) ?>"><?= e(t(Kaleta\Admin\TaskPanel::DAY_LABELS[$key])) ?></button>
<?php endforeach ?>
		</span>
	</div>
</form>
