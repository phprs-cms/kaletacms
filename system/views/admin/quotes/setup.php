<?php
/**
 * Price list set-up (3.45, U62; administrators): what feeds and fills quotes, apart from the one form of Settings – the
 * collections in the price list (3.42, collections.php – the one place for the mapping; Internal prices links here), the
 * default texts of new quotes (3.38, texts.php) and the saved line sets (3.40).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $csrf
 * @var list<array{id: int, name: string, lines: int, created_by: ?int, updated_at: string}> $lineSets the saved line sets (3.40)
 * @var array<int, string> $setOwners who saved them
 * @var array<int, array{collection: int, code: string, variants: string}> $mapping 3.42: the collections mapped to the price list
 * @var list<array<string, mixed>> $mappable 3.42: every collection for the mapping form
 */
?>
<?= $app->view->render('admin/quotes/tabs', ['module' => $module, 'current' => 'setup', 'admin' => true]) ?>
<section aria-labelledby="nabidky-kolekce">
<h2 id="nabidky-kolekce"><?= e(t('Collections in the price list')) ?></h2>
<?= $app->view->render('admin/quotes/collections', ['app' => $app, 'module' => $module, 'csrf' => $csrf, 'back' => 'setup', 'mapping' => $mapping, 'mappable' => $mappable]) ?>
</section>
<?= $app->view->render('admin/quotes/texts', ['app' => $app, 'module' => $module, 'csrf' => $csrf]) ?>
<section id="line-sets" data-quotes-line-sets aria-labelledby="nabidky-sady">
<h2 id="nabidky-sady"><?= e(t('Saved line sets')) ?></h2>
<p class="napoveda"><?= e(t('Lines kept under a name in the editor of a quote (Save as a set) and inserted into another quote in one step. Inserting takes the prices of products and own rows from that quote’s price list.')) ?></p>
<?php if ($lineSets === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'clanek', 'heading' => t('No line sets yet'), 'text' => t('Save the lines of a draft as a set in its editor.')]) ?>
<?php else: ?>
<div class="tab-obal">
<table class="vypis vypis-karty">
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col" class="cislo"><?= e(t('Lines')) ?></th><th scope="col"><?= e(t('Saved')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($lineSets as $set): ?>
<tr data-line-set="<?= (int) $set['id'] ?>">
	<td><form class="vradku" method="post" action="<?= e($module->url('line_set_rename')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $set['id'] ?>">
		<input class="textpole" type="text" name="name" value="<?= e($set['name']) ?>" maxlength="<?= Kaleta\Core\QuoteLineSets::NAME_MAX ?>" aria-label="<?= e(t('Name of the line set')) ?>" required>
		<button class="navigace" type="submit"><?= e(t('Rename')) ?></button></form></td>
	<td class="cislo" data-popisek="<?= e(t('Lines')) ?>"><?= format_count($set['lines']) ?></td>
	<td data-popisek="<?= e(t('Saved')) ?>"><?= e(format_date($set['updated_at'], true)) ?><?= $set['created_by'] !== null && ($setOwners[$set['created_by']] ?? '') !== '' ? ' · ' . e($setOwners[$set['created_by']]) : '' ?></td>
	<td data-popisek="<?= e(t('Actions')) ?>"><form method="post" action="<?= e($module->url('line_set_delete')) ?>" data-potvrdit="<?= e(t('Delete the line set “%s”? Quotes made with it keep their lines.', $set['name'])) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $set['id'] ?>">
		<button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
</section>
