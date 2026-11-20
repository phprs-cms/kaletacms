<?php
/**
 * The stages of the pipeline (3.31, administrators, Core\Deals::saveStages): rename, reorder (3.48: the arrows), add (the empty
 * rows) and remove; each stage is open, won or lost. 3 to 12 stages, at least one open, exactly one won and at least one
 * lost; a stage that still has deals cannot be removed and keeps its kind (3.31.1) – the refusal says so (how many deals)
 * and the form keeps what was typed.
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
$saved = count(array_filter($rows, fn (array $r): bool => $r['key'] !== '' || $r['label'] !== '')); // the rows a shift moves (3.48 B, U31)
for ($i = 0, $free = min(Pipeline::NEW_STAGE_ROWS, Deals::MAX_STAGES - count($rows)); $i < $free; $i++) {
    $rows[] = ['key' => '', 'label' => '', 'kind' => 'open'];
}
?>
<?= $app->view->render('admin/pipeline/tabs', ['module' => $module, 'current' => 'stages', 'query' => [], 'admin' => true]) ?>
<p class="napoveda"><?= e(t('The stages a deal moves through, in their order. A won or lost stage closes the deal. The pipeline needs %d to %d stages: at least one open, exactly one won and at least one lost.', Deals::MIN_STAGES, Deals::MAX_STAGES)) ?></p>
<form method="post" action="<?= e($module->url('stages_save')) ?>" data-stages-form data-unsaved>
<?php /* the first submit button decides what Enter does: save, never move the first stage down */ ?>
<button type="submit" class="vychozi-odeslani" tabindex="-1" aria-hidden="true"><?= e(t('Save the stages')) ?></button>
<?= $csrf ?>
<div class="tab-obal">
<table class="vypis vypis-karty obchody-etapy">
<thead><tr><th scope="col"><?= e(t('Order')) ?></th><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Kind')) ?></th><th scope="col" class="cislo"><?= e(t('Deals')) ?></th><th scope="col"><?= e(t('Remove')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $i => $row): $deals = $row['key'] !== '' ? ($counts[$row['key']] ?? 0) : 0; $n = $i + 1; ?>
<tr data-stage-row="<?= e($row['key']) ?>">
	<?php /* 3.48 B (U31): buttons instead of numbers – each submits the form with the shift, the order is kept in order[] */ ?>
	<td data-popisek="<?= e(t('Order')) ?>"><input type="hidden" name="key[]" value="<?= e($row['key']) ?>"><input type="hidden" name="order[]" value="<?= $i < $saved ? $n : '' ?>"><?php if ($i < $saved): ?><span class="etapa-posun">
		<?php if ($i > 0): ?><button class="navigace" type="submit" name="shift" value="up:<?= $i ?>" title="<?= e(t('Move up: %s', $row['label'])) ?>"><span aria-hidden="true">↑</span><span class="navod-skryte"><?= e(t('Move up: %s', $row['label'])) ?></span></button><?php endif ?>
		<?php if ($i < $saved - 1): ?><button class="navigace" type="submit" name="shift" value="down:<?= $i ?>" title="<?= e(t('Move down: %s', $row['label'])) ?>"><span aria-hidden="true">↓</span><span class="navod-skryte"><?= e(t('Move down: %s', $row['label'])) ?></span></button><?php endif ?>
	</span><?php endif ?></td>
	<td data-popisek="<?= e(t('Name')) ?>"><label class="navod-skryte" for="etapa-nazev-<?= $n ?>"><?= e(t('Name')) ?></label><input class="textpole" type="text" id="etapa-nazev-<?= $n ?>" name="label[]" maxlength="<?= Deals::LABEL_MAX ?>" value="<?= e($row['label']) ?>"<?= $row['key'] === '' ? ' placeholder="' . e(t('New stage')) . '"' : '' ?>></td>
	<td data-popisek="<?= e(t('Kind')) ?>"><label class="navod-skryte" for="etapa-druh-<?= $n ?>"><?= e(t('Kind')) ?></label><select id="etapa-druh-<?= $n ?>" name="kind[]">
<?php foreach (Pipeline::KIND_LABELS as $kind => $label): ?>
		<option value="<?= e($kind) ?>"<?= $row['kind'] === $kind ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select></td>
	<td class="cislo" data-popisek="<?= e(t('Deals')) ?>"><?= $row['key'] !== '' ? format_count($deals) : '' ?></td>
	<td data-popisek="<?= e(t('Remove')) ?>"><?php if ($row['key'] === ''): ?><span class="napoveda"><?= e(t('new')) ?></span><?php elseif ($deals > 0): ?><span class="napoveda"><?= e(t('%d deals – move them first', $deals)) ?></span><?php else: /* 3.44 (U58): a visible, 40 px tall choice instead of a bare 13 px box */ ?><label class="odebrat-volba"><input type="checkbox" name="remove[]" value="<?= $i ?>"> <span aria-hidden="true"><?= e(t('Remove')) ?></span><span class="navod-skryte"><?= e(t('Remove “%s”', $row['label'])) ?></span></label><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="napoveda"><?= e(t('Move a stage up or down with its arrows – the order is saved at once, with any other change on the page. A stage with deals cannot be removed and keeps its kind – move its deals first.')) ?></p>
<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save the stages')) ?></button></p>
</form>
