<?php
/**
 * One note of the agent notebook (2.15).
 *
 * @var Kaleta\Admin\Modules\Notebook $module
 * @var string $csrf
 * @var array<string, mixed>|null $note
 * @var string $topic the topic a new note starts with
 */
use Kaleta\Core\Notebook;

$isNew = $note === null;
?>
<p><a href="<?= e($module->url('', $isNew ? [] : ['topic' => (string) $note['topic']])) ?>">← <?= e(t('All notes')) ?></a></p>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="id" value="<?= (int) ($note['id'] ?? 0) ?>">
<fieldset>
<legend><?= e($isNew ? t('New note') : t('Note')) ?></legend>
<div class="radek"><label for="title"><?= e(t('Title')) ?></label><div><input class="textpole siroke" id="title" name="title" required maxlength="150" value="<?= e((string) ($note['title'] ?? '')) ?>" placeholder="<?= e(t('e.g. We never use the word “cheap”')) ?>"></div></div>
<div class="radek"><label for="topic"><?= e(t('Topic')) ?></label><div><select id="topic" name="topic">
<?php foreach (Notebook::TOPICS as $k => $label): ?><option value="<?= e($k) ?>"<?= ($note['topic'] ?? $topic) === $k ? ' selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach ?>
</select></div></div>
<div class="radek"><label for="text"><?= e(t('Text')) ?></label><div><textarea class="textpole siroke" id="text" name="text" rows="10" required maxlength="<?= Notebook::MAX_TEXT ?>"><?= e((string) ($note['text'] ?? '')) ?></textarea>
<span class="napoveda"><?= e(t('Plain text. Write it for someone who knows nothing about the site yet – what was decided, why, and what to keep to.')) ?></span></div></div>
<div class="radek"><label for="pinned"><?= e(t('Pinned')) ?></label><div><label><input type="checkbox" id="pinned" name="pinned" value="1"<?= !empty($note['pinned']) ? ' checked' : '' ?>> <?= e(t('Pinned notes come first and Claude sees their titles as soon as it connects.')) ?></label></div></div>
</fieldset>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save')) ?>">
<?php if (!$isNew): ?> <button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the note? It cannot be brought back.')) ?>"><?= e(t('Delete')) ?></button><?php endif ?></p>
</form>
<?php if (!$isNew): ?>
<p class="smltxt"><?= e(t('Written by %s, %s; last changed %s.', (string) $note['author'], format_date($note['created_at'], true), format_date($note['updated_at'], true))) ?></p>
<?php endif ?>
