<?php
/**
 * The team's thread on a page (page settings) or a collection item (its form) – 3.26, Core\Comments, Admin\CommentActions.
 * Signed-in colleagues only; plain text, escaped, the mentions shown as names. "@" offers the people who may open the
 * section (image/admin.js, data-mentions); the server checks them again. Edit one's own, delete one's own (an administrator
 * any): a deleted comment stays as a line saying so.
 *
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var string $target
 * @var list<array{id: int, at: string, by: string, by_id: int, text: string, via: string, kind: string, mentions: list<int>, edited: ?string, deleted: bool}> $comments
 * @var array<int, array{user: string, name: string}> $people names of the people mentioned
 * @var list<array{id: int, user: string, name: string}> $mentionable who may be mentioned
 * @var array<string, int|string> $fields hidden fields naming the record
 * @var string $action
 * @var int $me
 * @var bool $admin
 * @var array{comment: int, text: string}|null $draft
 */
$mentionsJson = (string) json_encode(array_values(array_filter($mentionable, fn (array $p): bool => $p['id'] !== $me)), JSON_UNESCAPED_UNICODE);
$live = count(array_filter($comments, fn (array $c): bool => !$c['deleted']));
?>
<section class="komentare-tymu" id="comments" data-comments="<?= e($target) ?>">
<h2><?= e(t('Team comments')) ?><?= $live > 0 ? ' (' . $live . ')' : '' ?></h2>
<?php if ($comments !== []): ?>
<ol class="odpovedi vlakno">
<?php foreach ($comments as $c): ?>
<?php if ($c['deleted']): ?>
	<li class="vlakno-komentar vlakno-smazany" data-comment="<?= (int) $c['id'] ?>" data-comment-deleted><p class="napoveda"><?= e(t('Comment deleted · %s, %s', $c['by'] !== '' ? $c['by'] : '—', format_date($c['at'], true))) ?></p></li>
<?php else: ?>
	<li class="vlakno-komentar" data-comment="<?= (int) $c['id'] ?>"><p><strong><?= e($c['by'] !== '' ? $c['by'] : '—') ?></strong> · <?= e(format_date($c['at'], true)) ?><?= $c['via'] === 'mcp' ? ' · ' . e(t('through Claude')) : '' ?><?= $c['edited'] !== null ? ' · <span class="napoveda">' . e(t('edited')) . '</span>' : '' ?></p>
		<div class="odpoved-text"><?= Kaleta\Core\Comments::html($c['text'], $people) ?></div>
		<?= $app->view->render('admin/comment_actions', ['c' => $c, 'action' => $action, 'fields' => $fields, 'csrf' => $csrf, 'me' => $me, 'admin' => $admin, 'draft' => $draft, 'mentionsJson' => $mentionsJson]) ?></li>
<?php endif ?>
<?php endforeach ?>
</ol>
<?php endif ?>
<form method="post" action="<?= e($action) ?>" class="vlakno-poznamka-form" data-comment-form>
	<?= $csrf ?><?php foreach ($fields as $name => $value): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endforeach ?><input type="hidden" name="op" value="add">
	<div class="radek"><label for="novy-komentar"><?= e(t('New comment')) ?></label><div><textarea class="textbox nizky" id="novy-komentar" name="text" rows="3" maxlength="<?= Kaleta\Core\Comments::MAX_TEXT ?>" required data-mentions="<?= e($mentionsJson) ?>"><?= e($draft !== null && $draft['comment'] === 0 ? $draft['text'] : '') ?></textarea>
		<span class="napoveda"><?= e(t('Only administration users see it. Type @ to mention a colleague – they are notified.')) ?></span></div></div>
	<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Add comment')) ?></button></p>
</form>
</section>
