<?php
/**
 * The follow-up buttons of an enquiry (3.27, Admin\TaskActions op=follow_up): "Tomorrow", "In 3 days", "In a week" add the
 * signed-in person's own task "Follow up: <form>" about the enquiry, due that day at the default time. In the task panel,
 * or at the top of the detail right after a reply was sent.
 *
 * @var string $csrf
 * @var string $action the URL of the module's task action
 * @var string $hidden the hidden fields naming the enquiry (HTML)
 * @var bool $replied a reply was just sent
 */
use Kaleta\Admin\TaskPanel;

?>
<form method="post" action="<?= e($action) ?>" class="ukol-navazat<?= $replied ? ' ukol-navazat-po-odpovedi' : '' ?>" data-task-follow-up<?= $replied ? ' data-task-replied' : '' ?>>
	<?= $csrf ?><?= $hidden ?><input type="hidden" name="op" value="follow_up">
	<span class="ukol-navazat-text"><?= e(t($replied ? 'The reply is on its way. Follow up if they do not answer:' : 'Follow up:')) ?></span>
<?php foreach (TaskPanel::FOLLOW_UP as $key => $days): ?>
	<button class="navigace" type="submit" name="days" value="<?= e($key) ?>"><?= e(t(TaskPanel::DAY_LABELS[$key])) ?></button>
<?php endforeach ?>
	<span class="napoveda"><?= e(t('Adds a task for you in My work about this enquiry.')) ?></span>
</form>
