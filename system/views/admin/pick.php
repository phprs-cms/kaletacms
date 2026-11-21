<?php
/**
 * A choice by search (3.49 C): an ordinary select – the value carrier, and the whole control without JavaScript – that
 * image/admin.js ("choosing by search") turns into a combobox searching $url (GET, read-only; the script adds &q= and
 * &pick=1). The options are today's capped first screen with the record's own value among them; the special options
 * ($specials: "—", "The enquiry's customer") stay at the top with their values.
 *
 * @var string $id the select's id (the label points at it; the script hands it to the text field)
 * @var string $name the field's name
 * @var string $pick what it searches: contacts | companies | deals (a branch of the script each – unit 4460)
 * @var string $url the search address
 * @var array<int|string, string> $options value => label
 * @var string $selected the chosen value
 * @var list<array{0: string, 1: string}> $specials value, label – before the options
 * @var array<string, string> $attributes more attributes of the select (data-saved of the quote editor)
 */
$specials ??= [];
$attributes ??= [];
?>
<select id="<?= e($id) ?>" name="<?= e($name) ?>" data-pick="<?= e($pick) ?>" data-pick-url="<?= e($url) ?>"<?php foreach ($attributes as $attribute => $value): ?> <?= e($attribute) ?>="<?= e($value) ?>"<?php endforeach ?>>
<?php foreach ($specials as [$value, $label]): ?>
	<option value="<?= e($value) ?>" data-pick-special<?= $value === $selected ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach ?>
<?php foreach ($options as $value => $label): ?>
	<option value="<?= e((string) $value) ?>"<?= (string) $value === $selected ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach ?>
</select>
