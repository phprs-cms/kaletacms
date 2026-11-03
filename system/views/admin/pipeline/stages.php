<?php
/**
 * The stages of the pipeline (3.31, administrators, Core\Deals::saveStages): rename, reorder (the numbers), add (the empty
 * rows) and remove; each stage is open, won or lost. 3 to 12 stages, at least one open, exactly one won and at least one
 * lost; a stage that still has deals cannot be removed – the refusal says so and the form keeps what was typed.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var string $csrf
 * @var list<array{key: string, label: string, kind: string}> $rows the stages (or a refused form)
 * @var array<string, int> $counts stage key => deals
 */
use Kaleta\Admin\Modules\Pipeline;
use Kaleta\Core\Deals;

$rows = array_values($rows);
for ($i = 0, $free = min(Pipeline::NEW_STAGE_ROWS, Deals::MAX_STAGES - count($rows)); $i < $free; $i++) {
    $rows[] = ['key' => '', 'label' => '', 'kind' => 'open'];
}
?>
<?= $app->view->render('admin/pipeline/tabs', ['module' => $module, 'current' => 'stages', 'query' => [], 'admin' => true]) ?>
<p class="napoveda"><?= e(t('The stages a deal moves through, in their order. A won or lost stage closes the deal. The pipeline needs %d to %d stages: at least one open, exactly one won and at least one lost.', Deals::MIN_STAGES, Deals::MAX_STAGES)) ?></p>
<form method="post" action="<?= e($module->url('stages_save')) ?>" data-stages-form>
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis vypis-karty obchody-etapy">
<thead><tr><th scope="col"><?= e(t('Order')) ?></th><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Kind')) ?></th><th scope="col" class="cislo"><?= e(t('Deals')) ?></th><th scope="col"><?= e(t('Remove')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $i => $row): $deals = $row['key'] !== '' ? ($counts[$row['key']] ?? 0) : 0; $n = $i + 1; ?>
<tr data-stage-row="<?= e($row['key']) ?>">
	<td data-popisek="<?= e(t('Order')) ?>"><input type="hidden" name="key[]" value="<?= e($row['key']) ?>"><label class="navod-skryte" for="etapa-poradi-<?= $n ?>"><?= e(t('Order')) ?></label><input class="textpole obchody-poradi" type="number" id="etapa-poradi-<?= $n ?>" name="order[]" min="1" max="99" value="<?= $row['key'] !== '' || $row['label'] !== '' ? $n : '' ?>"></td>
	<td data-popisek="<?= e(t('Name')) ?>"><label class="navod-skryte" for="etapa-nazev-<?= $n ?>"><?= e(t('Name')) ?></label><input class="textpole" type="text" id="etapa-nazev-<?= $n ?>" name="label[]" maxlength="<?= Deals::LABEL_MAX ?>" value="<?= e($row['label']) ?>"<?= $row['key'] === '' ? ' placeholder="' . e(t('New stage')) . '"' : '' ?>></td>
	<td data-popisek="<?= e(t('Kind')) ?>"><label class="navod-skryte" for="etapa-druh-<?= $n ?>"><?= e(t('Kind')) ?></label><select id="etapa-druh-<?= $n ?>" name="kind[]">
<?php foreach (Pipeline::KIND_LABELS as $kind => $label): ?>
		<option value="<?= e($kind) ?>"<?= $row['kind'] === $kind ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select></td>
	<td class="cislo" data-popisek="<?= e(t('Deals')) ?>"><?= $row['key'] !== '' ? format_count($deals) : '' ?></td>
	<td data-popisek="<?= e(t('Remove')) ?>"><?php if ($row['key'] === ''): ?><span class="napoveda"><?= e(t('new')) ?></span><?php elseif ($deals > 0): ?><span class="napoveda"><?= e(t('has deals')) ?></span><?php else: ?><label><input type="checkbox" name="remove[]" value="<?= $i ?>"> <span class="navod-skryte"><?= e(t('Remove “%s”', $row['label'])) ?></span></label><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="napoveda"><?= e(t('Change the numbers to reorder the stages. A stage with deals cannot be removed – move its deals first.')) ?></p>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save the stages')) ?></button></p>
</form>
