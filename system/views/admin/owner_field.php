<?php
/**
 * The responsible person of a page or a collection item (3.24, Core\Assignment): people who can open the section, "—" =
 * nobody. Responsibility only – Mine, the review reminder and one e-mail when someone else chooses them; no approval and no
 * change of who may edit.
 *
 * @var array<int, string> $owners  user id => name (Core\Assignment::candidates – the stored one stays, "(former user)" when gone)
 * @var int|null $owner  the stored responsible person
 * @var string $id  the id of the select (one form can have one)
 */
$id ??= 'owner_id';
?>
<div class="radek" data-owner-field>
	<label for="<?= e($id) ?>"><?= e(t('Responsible person')) ?></label>
	<div><select id="<?= e($id) ?>" name="owner_id"><option value="0">—</option>
<?php foreach ($owners as $userId => $name): ?>
		<option value="<?= (int) $userId ?>"<?= (int) ($owner ?? 0) === (int) $userId ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Gets an e-mail when someone else chooses them, and the review reminders. It does not change who may edit.')) ?></span></div>
</div>
