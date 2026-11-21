<?php
/**
 * The Attachments block of a reply to an enquiry and of a quote e-mail (3.49 A, Admin\AttachmentBox, Core\ReplyAttachments),
 * inside the sending form (enctype multipart/form-data): the internal files chosen as ticked boxes (internal_files[]), for
 * a person with Internal files "Add another file" – a choice by search over a select of the most recently changed ready
 * files (image/admin.js "choosing by search" and "saved reply (part A)" add a ticked row); without the script the select
 * and "Add" belong to the page's reload form ($reloadForm, a GET form outside this one) – and the upload field files[].
 * The script refuses a submit over $box['maxBytes'] or $box['maxFiles'] before anything is uploaded and puts the number of
 * files into the confirmation ($confirm: the form's question with %2$d for the count).
 *
 * The files linked to the enquiry's customer or to the quote (3.49 A meets B) are rows of the same list, unticked.
 *
 * @var array{chosen: list<array{id: int, name: string, note: string, size: int}>, linked: list<array{id: int, name: string, note: string, size: int}>, canFiles: bool, recent: list<array{id: int, name: string, size: int}>, searchUrl: string, maxBytes: int, maxFiles: int, reload: array<string, int|string>} $box
 * @var string $prefix ids of the fields (odpoved | nabidka)
 * @var string $reloadForm the id of the GET form that reloads the page with the file added (no script)
 * @var string $confirm the confirmation with files – %2$d is the number of files (the recipient filled in already)
 * @var string $linkedLabel what the linked rows are linked to ("Linked to the customer" | "Linked to the quote")
 */
?>
<div class="radek" data-attachments data-max-bytes="<?= (int) $box['maxBytes'] ?>" data-max-files="<?= (int) $box['maxFiles'] ?>" data-attach-confirm="<?= e($confirm) ?>">
	<span class="popisek"><?= e(t('Attachments')) ?></span><div>
	<ul class="prilohy-seznam" data-attach-list>
<?php foreach ($box['chosen'] as $file): ?>
		<li><label><input type="checkbox" name="internal_files[]" value="<?= (int) $file['id'] ?>" data-bytes="<?= (int) $file['size'] ?>" checked> <?= e($file['name']) ?></label> <small class="napoveda"><?= e($file['note']) ?></small></li>
<?php endforeach ?>
<?php foreach ($box['linked'] as $file): ?>
		<li><label><input type="checkbox" name="internal_files[]" value="<?= (int) $file['id'] ?>" data-bytes="<?= (int) $file['size'] ?>"> <?= e($file['name']) ?></label> <small class="napoveda"><?= e($file['note'] . ' · ' . $linkedLabel) ?></small></li>
<?php endforeach ?>
	</ul>
<?php if ($box['canFiles']): ?>
	<p class="prilohy-pridat"><label for="<?= e($prefix) ?>-pridat-soubor"><?= e(t('Add another file')) ?></label>
		<select id="<?= e($prefix) ?>-pridat-soubor" name="attach_add" form="<?= e($reloadForm) ?>" data-pick="files" data-pick-url="<?= e($box['searchUrl']) ?>" data-attach-add>
			<option value="" data-pick-special>—</option>
<?php foreach ($box['recent'] as $file): ?>
			<option value="<?= (int) $file['id'] ?>" data-bytes="<?= (int) $file['size'] ?>"><?= e($file['name']) ?></option>
<?php endforeach ?>
		</select>
		<button class="navigace" type="submit" form="<?= e($reloadForm) ?>" data-attach-add-button><?= e(t('Add')) ?></button></p>
<?php endif ?>
	<p><label class="navod-skryte" for="<?= e($prefix) ?>-nahrat"><?= e(t('Upload files')) ?></label><input type="file" id="<?= e($prefix) ?>-nahrat" name="files[]" multiple data-max-bytes="<?= (int) $box['maxBytes'] ?>"></p>
	<span class="napoveda"><?= e(t('Up to %1$d files, %2$s together. An internal file leaves the company with this e-mail.', (int) $box['maxFiles'], Kaleta\Core\Files::size((int) $box['maxBytes']))) ?></span>
	<p class="hlaska hlaska-chyba" role="alert" data-attach-error hidden></p>
	</div>
</div>
