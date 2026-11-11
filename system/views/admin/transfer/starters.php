<?php
/**
 * The starter pages of this site an import lands on (3.33, M2 – Core\StarterPages): in the preview each one with the
 * choice to replace it (ticked) or keep it, a page someone edited only as information; after the import what it replaced
 * and what it kept. Shared by the website import and the WordPress import.
 *
 * @var list<array{old: string, page_id: int, title: string, address: string, replace: bool, why: string}>|null $choices  the preview (inside its form)
 * @var array{replaced: list<array{old: string, title: string, address: string}>, kept: list<array{old: string, title: string, address: string, why: string}>}|null $result  after the import
 */
$choices ??= null;
$result ??= null;
?>
<?php if ($choices !== null && $choices !== []): ?>
<fieldset id="starter-pages">
<legend><?= e(t('Sample pages of this site')) ?></legend>
<p class="napoveda"><?= e(t('These old addresses land on sample pages of the installation. A ticked sample page goes to the trash and the imported page takes its address; an unticked one stays and the imported page gets a number (/contact-2). A sample page someone has edited is never replaced.')) ?></p>
<?php foreach ($choices as $c): ?>
<?php if ($c['why'] === 'edited'): ?>
	<p><?= e(t('%s → %s stays: %s has been edited, the imported page gets a number.', $c['old'], $c['address'], $c['title'])) ?></p>
<?php else: ?>
	<label><input type="checkbox" name="nahradit_vzory[]" value="<?= (int) $c['page_id'] ?>"<?= $c['replace'] ? ' checked' : '' ?>> <?= e(t('Replace the sample page %s (%s) with the old page %s', $c['title'], $c['address'], $c['old'])) ?></label><br>
<?php endif ?>
<?php endforeach ?>
</fieldset>
<?php endif ?>
<?php if ($result !== null && ($result['replaced'] !== [] || $result['kept'] !== [])): ?>
<div class="hlaska" id="starter-pages-result">
<?php if ($result['replaced'] !== []): ?>
	<p><?= e(t('Sample pages replaced (in the trash for 30 days): %s', implode(', ', array_map(fn (array $r): string => $r['title'] . ' ← ' . $r['old'], $result['replaced'])))) ?></p>
<?php endif ?>
<?php if ($result['kept'] !== []): ?>
	<p><?= e(t('Sample pages kept – the imported page got a number: %s', implode(', ', array_map(fn (array $r): string => $r['title'] . ' (' . $r['address'] . ')', $result['kept'])))) ?></p>
<?php endif ?>
</div>
<?php endif ?>
