<?php
/**
 * Saved replies of the firm (3.49 A, Enquiries::actionSavedReplies, Core\SavedReplies): a tab per language version of the
 * site; each reply one <details> with its name as the summary and the name, subject and text stacked, a Remove box; "Add a
 * reply" is an open empty one. One Save replaces the replies of the language. No script needed.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Enquiries $module
 * @var string $csrf
 * @var array<string, string> $languages code => name ('' = the default language, first)
 * @var string $language the tab shown
 * @var list<array{id: int, language: string, name: string, subject: string, text: string}> $replies the saved replies of the language
 * @var list<array<string, mixed>>|null $draft the rows of a refused save, shown again
 */
use Kaleta\Core\SavedReplies;

$rows = $draft ?? $replies;
$rows = array_values(array_filter($rows, fn (array $r): bool => trim((string) ($r['name'] ?? '')) !== '' || trim((string) ($r['text'] ?? '')) !== ''));
$canAdd = count($rows) < SavedReplies::MAX_PER_LANGUAGE;
?>
<p><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All enquiries')) ?></a></p>
<?php if (count($languages) > 1): ?>
<nav class="zalozky" aria-label="<?= e(t('Languages')) ?>" data-saved-replies-tabs>
<?php foreach ($languages as $code => $name): ?>
	<a href="<?= e($module->url('saved_replies', $code !== '' ? ['language' => $code] : [])) ?>"<?= $code === $language ? ' class="aktivni" aria-current="true"' : '' ?>><?= e($name) ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<p class="napoveda"><?= e(t('Offered in the reply form of an enquiry – the ones in the enquiry’s language first. Placeholders: {{name}} (who wrote the enquiry), {{topic}} (what it was about), {{company}} (your company). An empty subject keeps the reply’s own subject. Claude reads them, it never changes them.')) ?></p>
<form method="post" action="<?= e($module->url('saved_replies_save')) ?>" class="formular" data-saved-replies>
	<?= $csrf ?><input type="hidden" name="language" value="<?= e($language) ?>">
<?php if ($rows === []): ?>
	<?= $app->view->render('admin/empty', ['icon' => 'poptavky', 'heading' => t('No saved replies yet'), 'text' => t('Write the answers you give often once – the reply form then fills them in for you.')]) ?>
<?php endif ?>
	<div class="ulozene-odpovedi">
<?php foreach ([...$rows, ...($canAdd ? [['id' => 0, 'name' => '', 'subject' => '', 'text' => '']] : [])] as $i => $r): $new = (int) ($r['id'] ?? 0) === 0 && trim((string) ($r['name'] ?? '')) === ''; $field = 'odpoved-' . $i; ?>
	<details class="ulozena-odpoved"<?= $new ? ' open' : '' ?> data-saved-reply-row>
		<summary><?= $new ? e(t('Add a reply')) : e((string) $r['name']) ?></summary>
		<input type="hidden" name="replies[<?= $i ?>][id]" value="<?= (int) ($r['id'] ?? 0) ?>">
		<div class="radek"><label for="<?= $field ?>-n"><?= e(t('Name')) ?></label><div><input class="textpole siroke" type="text" id="<?= $field ?>-n" name="replies[<?= $i ?>][name]" maxlength="<?= SavedReplies::NAME_MAX ?>" value="<?= e((string) ($r['name'] ?? '')) ?>"></div></div>
		<div class="radek"><label for="<?= $field ?>-s"><?= e(t('Subject')) ?></label><div><input class="textpole siroke" type="text" id="<?= $field ?>-s" name="replies[<?= $i ?>][subject]" maxlength="<?= SavedReplies::SUBJECT_MAX ?>" value="<?= e((string) ($r['subject'] ?? '')) ?>"></div></div>
		<div class="radek"><label for="<?= $field ?>-t"><?= e(t('Text')) ?></label><div><textarea class="textbox" id="<?= $field ?>-t" name="replies[<?= $i ?>][text]" rows="8" maxlength="<?= SavedReplies::TEXT_MAX ?>"><?= e((string) ($r['text'] ?? '')) ?></textarea></div></div>
<?php if (!$new): ?>
		<div class="radek"><span></span><div><label><input type="checkbox" name="replies[<?= $i ?>][remove]" value="1"<?= !empty($r['remove']) ? ' checked' : '' ?>> <?= e(t('Remove this reply')) ?></label></div></div>
<?php endif ?>
	</details>
<?php endforeach ?>
	</div>
<?php if (!$canAdd): ?>
	<p class="napoveda"><?= e(t('At most %d saved replies per language.', SavedReplies::MAX_PER_LANGUAGE)) ?></p>
<?php endif ?>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save')) ?></button></p>
</form>
