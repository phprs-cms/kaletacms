<?php
/**
 * The tasks of a record (3.27, Core\Tasks, Admin\TaskActions::taskPanel) – the enquiry detail, the page settings and the
 * item form: the open ones by due date (overdue and today marked), the done ones folded. Who may (the person, the one who
 * added it, an administrator) marks a task done, snoozes it, edits it in place, deletes it or – with the Requests section –
 * hands it to Claude; everyone with the section sees them. On an enquiry the follow-up buttons add the signed-in person's
 * own task (views/admin/task_follow_up.php); right after a reply the detail shows them at its top instead. A page or an item with a review date offers one click for a task
 * "Review: <title>" due that day (for me; Edit changes the person). "Add a task" is views/admin/task_form.php.
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var string $target
 * @var list<array<string, mixed>> $tasks Tasks::forTarget rows + who, by, done_by_name, may, people, remind_choice
 * @var array<string, int|string> $fields hidden fields naming the record
 * @var string $action the URL of the module's task action
 * @var array<int, string> $people who may get a new task
 * @var int $me
 * @var bool $claude the signed-in person may hand a task to Claude (the Requests section)
 * @var string $requestUrl the admin URL of a request without its id
 * @var string $draft a refused title
 * @var bool $followUp the follow-up buttons (an enquiry)
 * @var bool $replied a reply was just sent – the follow-up is offered first
 * @var array{date: string, title: string}|null $review
 */
use Kaleta\Admin\TaskPanel;

$hidden = '';
foreach ($fields as $name => $value) {
    $hidden .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
}
$open = array_values(array_filter($tasks, fn (array $t): bool => $t['done_at'] === null));
$done = array_values(array_filter($tasks, fn (array $t): bool => $t['done_at'] !== null));
$row = function (array $t) use ($app, $csrf, $hidden, $action, $claude, $requestUrl, $me): string {
    $title = $t['title'] !== '' ? $t['title'] : t('(no title)');
    $form = fn (string $op, string $inner, string $extra = ''): string => '<form class="vradku" method="post" action="' . e($action) . '"' . $extra . '>' . $csrf . $hidden
        . '<input type="hidden" name="task" value="' . (int) $t['id'] . '"><input type="hidden" name="op" value="' . e($op) . '">' . $inner . '</form>';
    $meta = [e($t['assignee_id'] === $me ? t('For me') : t('For %s', $t['who']))];
    if ($t['done_at'] !== null) {
        $meta[] = e($t['done_by_name'] !== '' ? t('Done by %s on %s', $t['done_by_name'], format_date((string) $t['done_at'], true)) : t('Done on %s', format_date((string) $t['done_at'], true)));
    } elseif ($t['due_at'] !== null) {
        $badge = match ($t['group']) {
            'overdue' => ' <span class="stitek stitek-chyba">' . e(t('overdue')) . '</span>',
            'today' => ' <span class="stitek stitek-koncept">' . e(t('today')) . '</span>',
            default => '',
        };
        $meta[] = e(t('due %s', format_date((string) $t['due_at'], true))) . $badge;
    } else {
        $meta[] = e(t('no date'));
    }
    if ($t['done_at'] === null && $t['remind_at'] !== null) {
        $meta[] = e(t('reminder %s', format_date((string) $t['remind_at'], true)));
    }
    if ($t['by'] !== '' && (int) $t['created_by'] !== (int) $t['assignee_id']) {
        $meta[] = e(t('added by %s', $t['by']));
    }
    $html = '<li class="ukol ukol-' . e($t['group']) . '" data-task="' . (int) $t['id'] . '"><p class="ukol-nazev"><strong>' . e($title) . '</strong></p>'
        . '<p class="ukol-meta">' . implode(' · ', $meta) . '</p>';
    if ($t['request_id'] !== null) {
        $html .= '<p class="ukol-meta" data-task-request>' . e(t('Handed to Claude:')) . ' <a href="' . e($requestUrl . (int) $t['request_id']) . '">' . e(t('request #%s', (int) $t['request_id'])) . '</a></p>';
    }
    if (!$t['may']) {
        return $html . '</li>';
    }
    $html .= '<div class="ukol-akce" data-task-actions>';
    if ($t['done_at'] !== null) {
        $html .= $form('reopen', '<button class="navigace" type="submit">' . e(t('Reopen')) . '</button>');
    } else {
        $html .= $form('done', '<button class="navigace ukol-hotovo" type="submit">' . e(t('Mark done')) . '</button>');
        $snooze = '';
        foreach (Kaleta\Core\Tasks::SNOOZE as $key => $days) {
            $snooze .= '<button class="navigace" type="submit" name="snooze" value="' . e($key) . '">' . e(t(TaskPanel::DAY_LABELS[$key])) . '</button>';
        }
        $html .= $form('snooze', '<span class="napoveda-inline">' . e(t('Snooze:')) . '</span>' . $snooze, ' data-task-snooze');
        // edit in place, no script needed
        // N327-2: a task handed to nobody keeps nobody unless a person is chosen (the browser would pick the first one)
        $people = $t['assignee_id'] === null ? '<option value="0" selected>' . e(t('nobody')) . '</option>' : '';
        foreach ($t['people'] as $id => $name) {
            $people .= '<option value="' . (int) $id . '"' . ((int) $id === (int) $t['assignee_id'] ? ' selected' : '') . '>' . e((int) $id === $me ? t('Me (%s)', $name) : $name) . '</option>';
        }
        $remind = '';
        foreach (TaskPanel::REMIND_LABELS + ($t['remind_choice'] === 'keep' ? ['keep' => ''] : []) as $key => $label) {
            $text = $key === 'keep' ? t('As set: %s', format_date((string) $t['remind_at'], true)) : t($label);
            $remind .= '<option value="' . e($key) . '"' . ($key === $t['remind_choice'] ? ' selected' : '') . '>' . e($text) . '</option>';
        }
        $id = 'ukol-' . (int) $t['id'];
        $html .= '<details class="komentar-upravit ukol-upravit"><summary>' . e(t('Edit')) . '</summary>'
            . $form('edit', '<div class="ukol-pole ukol-pole-uprava"><div class="ukol-pole-nazev"><label for="' . $id . '-nazev">' . e(t('Task')) . '</label><input class="textpole siroke" type="text" id="' . $id . '-nazev" name="title" maxlength="' . Kaleta\Core\Tasks::TITLE . '" value="' . e($t['title']) . '" required></div>'
                . '<div><label for="' . $id . '-kdo">' . e(t('For')) . '</label><select id="' . $id . '-kdo" name="assignee_id">' . $people . '</select></div>'
                . '<div><label for="' . $id . '-datum">' . e(t('Due')) . '</label><input class="textpole" type="date" id="' . $id . '-datum" name="due_date" value="' . e(substr((string) $t['due_at'], 0, 10)) . '"></div>'
                . '<div><label for="' . $id . '-cas">' . e(t('Time')) . '</label><input class="textpole" type="time" id="' . $id . '-cas" name="due_time" value="' . e(substr((string) $t['due_at'], 11, 5)) . '"></div>'
                . '<div><label for="' . $id . '-pripominka">' . e(t('Reminder')) . '</label><select id="' . $id . '-pripominka" name="remind">' . $remind . '</select></div></div>'
                . '<p class="tlacitka"><button class="navigace" type="submit">' . e(t('Save the task')) . '</button></p>', ' data-task-edit')
            . '</details>';
        if ($claude && $t['request_id'] === null) {
            $html .= $form('claude', '<button class="navigace" type="submit">' . e(t('Hand to Claude')) . '</button>',
                ' data-task-claude data-potvrdit="' . e(t('Hand “%s” to Claude? It becomes a request in Ask Claude and the task is marked done here.', $title)) . '"');
        }
    }
    $html .= $form('delete', '<button class="navigace nebezpecne" type="submit">' . e(t('Delete')) . '</button>', ' data-task-delete data-potvrdit="' . e(t('Delete the task “%s”? This cannot be undone.', $title)) . '"');

    return $html . '</div></li>';
};
?>
<section class="ukoly" id="tasks" data-tasks="<?= e($target) ?>">
<h2><?= e(t('Tasks')) ?><?= $open !== [] ? ' (' . count($open) . ')' : '' ?></h2>
<?php if ($followUp && !$replied): /* right after a reply the enquiry detail shows them at its top */ ?>
<?= $app->view->render('admin/task_follow_up', ['csrf' => $csrf, 'action' => $action, 'hidden' => $hidden, 'replied' => false]) ?>
<?php endif ?>
<?php if ($review !== null): ?>
<form method="post" action="<?= e($action) ?>" class="ukol-navazat" data-task-review>
	<?= $csrf ?><?= $hidden ?><input type="hidden" name="op" value="add"><input type="hidden" name="review" value="1">
	<span class="ukol-navazat-text"><?= e(t('Review by %s.', format_date($review['date']))) ?></span>
	<button class="navigace" type="submit"><?= e(t('Add a task for the review date')) ?></button>
	<span class="napoveda"><?= e(t('“%s”, for you on that day – Edit changes the person or the time.', $review['title'])) ?></span>
</form>
<?php endif ?>
<?php if ($open !== []): ?>
<ol class="ukoly-seznam" data-tasks-open>
<?php foreach ($open as $t): ?>
	<?= $row($t) ?>
<?php endforeach ?>
</ol>
<?php else: ?>
<p class="napoveda" data-tasks-none><?= e(t('No open tasks.')) ?></p>
<?php endif ?>
<?php if ($done !== []): ?>
<details class="ukoly-hotove"><summary><?= e(t('Done (%s)', count($done))) ?></summary>
<ol class="ukoly-seznam" data-tasks-done>
<?php foreach ($done as $t): ?>
	<?= $row($t) ?>
<?php endforeach ?>
</ol>
</details>
<?php endif ?>
<details class="ukol-pridat"<?= $draft !== '' ? ' open' : '' ?>><summary><?= e(t('Add a task')) ?></summary>
<?= $app->view->render('admin/task_form', ['csrf' => $csrf, 'action' => $action, 'fields' => $fields, 'people' => $people, 'me' => $me, 'draft' => $draft, 'prefix' => 'ukol-novy']) ?>
</details>
</section>
