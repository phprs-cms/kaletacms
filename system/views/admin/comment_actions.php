<?php
/**
 * Edit and Delete under one comment of a thread (3.26, Admin\CommentActions): the author rewrites their own comment (the
 * form opens in place, no script needed), the author or an administrator deletes it. Nothing for a deleted comment.
 *
 * @var array{id: int, by_id: int, text: string, deleted: bool} $c
 * @var string $action the URL of the module's comment action
 * @var array<string, int|string> $fields hidden fields naming the record
 * @var string $csrf
 * @var int $me the signed-in user
 * @var bool $admin
 * @var array{comment: int, text: string}|null $draft a refused text to show again
 * @var string $mentionsJson who may be mentioned (JSON for data-mentions)
 */
$own = $me > 0 && $c['by_id'] === $me;
if ($c['deleted'] || (!$own && !$admin)) {
    return;
}
$hidden = '';
foreach ($fields as $name => $value) {
    $hidden .= '<input type="hidden" name="' . e($name) . '" value="' . e($value) . '">';
}
$editing = $draft !== null && $draft['comment'] === $c['id'];
?>
<div class="komentar-akce" data-comment-actions>
<?php if ($own): ?>
	<details class="komentar-upravit"<?= $editing ? ' open' : '' ?>><summary><?= e(t('Edit')) ?></summary>
	<form method="post" action="<?= e($action) ?>" data-comment-edit><?= $csrf ?><?= $hidden ?><input type="hidden" name="op" value="edit"><input type="hidden" name="comment" value="<?= (int) $c['id'] ?>">
		<textarea class="textbox nizky" name="text" rows="3" maxlength="<?= Kaleta\Core\Comments::MAX_TEXT ?>" required aria-label="<?= e(t('Edit the comment')) ?>" data-mentions="<?= e($mentionsJson) ?>"><?= e($editing ? $draft['text'] : $c['text']) ?></textarea>
		<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Save the comment')) ?></button></p>
	</form></details>
<?php endif ?>
	<form class="vradku" method="post" action="<?= e($action) ?>" data-comment-delete data-potvrdit="<?= e(t($own ? 'Delete your comment? The thread keeps a note that it was deleted.' : 'Delete this comment of %s? The thread keeps a note that it was deleted.', $c['by'] ?? '')) ?>"><?= $csrf ?><?= $hidden ?><input type="hidden" name="op" value="delete"><input type="hidden" name="comment" value="<?= (int) $c['id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
</div>
