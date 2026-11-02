<?php
/**
 * Notifications (3.26, Admin\InboxPage): who did what where and when, newest first. An unread one is marked; opening it
 * (the link goes through action=inbox&open=<id>) marks it read and leads to its place. Plain text only, escaped.
 *
 * @var Kaleta\Core\App $app
 * @var list<array<string, mixed>> $rows  Core\Inbox::list rows
 * @var bool $more  an older page exists
 * @var int $page
 * @var int $unread
 * @var string $email  the person's e-mail choice (instant | daily | off)
 * @var string $csrf
 */
$icon = require __DIR__ . '/icons.php';
$kindIcon = ['mention' => 'komentare', 'comment' => 'komentare', 'assigned' => 'moje', 'review_due' => 'audit', 'task_due' => 'moje'];
$emailNote = match ($email) {
    'daily' => t('You get one e-mail summary a day, in the morning.'),
    'off' => t('You get no e-mail about them – they are only here.'),
    default => t('You get an e-mail about each one right away.'),
};
?>
<div class="upozorneni-lista">
<p class="napoveda"><?= e(t('Mentions, comments on what you are responsible for, things assigned to you and review dates. Kept for 90 days.')) ?>
	<?= e($emailNote) ?> <a href="<?= e($app->url('admin.php?action=account#notifications')) ?>"><?= e(t('Change it in My account')) ?></a></p>
<?php if ($unread > 0): ?>
<form method="post" action="<?= e($app->url('admin.php?action=inbox')) ?>"><?= $csrf ?><button class="navigace" type="submit" name="read_all" value="1"><?= e(t('Mark all as read')) ?></button></form>
<?php endif ?>
</div>
<?php if ($rows === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'zvonek', 'heading' => $page > 1 ? t('No older notifications.') : t('No notifications yet.'),
    'text' => t('When a colleague mentions you, comments on what you are responsible for or assigns you something, and when a review date comes, you will see it here.'),
    'action' => $page > 1 ? [$app->url('admin.php?action=inbox'), t('Back to the newest')] : null]) ?>
<?php else: ?>
<ul class="upozorneni" data-inbox>
<?php foreach ($rows as $row):
    $place = $row['place'];
    $unreadRow = $row['read_at'] === null;
    $what = Kaleta\Core\Inbox::what($row['kind'], $row['actor_name']);
    $where = Kaleta\Core\Inbox::where($place);
    ?>
	<li class="upozorneni-polozka<?= $unreadRow ? ' upozorneni-neprectene' : '' ?>" data-kind="<?= e($row['kind']) ?>">
		<span class="upozorneni-ikona" aria-hidden="true"><?= $icon($kindIcon[$row['kind']] ?? 'zvonek') ?></span>
		<div class="upozorneni-text">
			<p class="upozorneni-co"><?php if ($unreadRow): ?><span class="navod-skryte"><?= e(t('Unread:')) ?> </span><?php endif ?><strong><?= e($what) ?></strong>
				<span class="upozorneni-kde"><?php if ($place !== null): ?><a href="<?= e($app->url('admin.php?action=inbox&open=' . (int) $row['id'])) ?>"><?= e($where) ?></a><?php else: ?><?= e($where) ?><?php endif ?></span></p>
<?php if ($row['excerpt'] !== '' && $row['kind'] !== 'assigned'): ?>
			<p class="upozorneni-vynatek"><?= e($row['excerpt']) ?></p>
<?php endif ?>
		</div>
		<time class="upozorneni-cas" datetime="<?= e(str_replace(' ', 'T', $row['created_at'])) ?>"><?= e(format_date($row['created_at'], true)) ?></time>
	</li>
<?php endforeach ?>
</ul>
<?php if ($more || $page > 1): ?>
<nav class="strankovani" aria-label="<?= e(t('Pages')) ?>">
<?php if ($page > 1): ?><a href="<?= e($app->url('admin.php?action=inbox' . ($page > 2 ? '&page=' . ($page - 1) : ''))) ?>"><?= e(t('Newer')) ?></a><?php endif ?>
<?php if ($more): ?><a href="<?= e($app->url('admin.php?action=inbox&page=' . ($page + 1))) ?>"><?= e(t('Older')) ?></a><?php endif ?>
</nav>
<?php endif ?>
<?php endif ?>
