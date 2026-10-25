<?php
/**
 * CSV import of redirects, the preview (3.6): what saving would do with every row. Nothing is saved yet.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Redirects $module
 * @var string $csrf
 * @var list<array{row: int, from: string, to: string, code: int, status: string, reason: string}> $plan
 * @var array<string, int> $counts  added, changed, unchanged, refused
 * @var string $csv  the text that is sent again with the confirmation
 */
$labels = ['added' => t('will be added'), 'changed' => t('will change'), 'unchanged' => t('no change'), 'refused' => t('refused')];
$saving = $counts['added'] + $counts['changed'];
?>
<p id="nahled-importu"><?= e(t('%d rows: %d will be added, %d will change, %d stay as they are, %d are refused.', count($plan), $counts['added'], $counts['changed'], $counts['unchanged'], $counts['refused'])) ?></p>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Row')) ?></th><th scope="col"><?= e(t('Old address')) ?></th><th scope="col"><?= e(t('Target')) ?></th><th scope="col"><?= e(t('Code')) ?></th><th scope="col"><?= e(t('Result')) ?></th></tr></thead>
<tbody>
<?php foreach ($plan as $p): ?>
<tr><td class="cislo"><?= (int) $p['row'] ?></td><td><?= e($p['from']) ?></td><td><?= e($p['to']) ?></td><td class="cislo"><?= (int) $p['code'] ?></td>
	<td><span class="stitek<?= $p['status'] === 'refused' ? ' stitek-chyba' : '' ?>"><?= e($labels[$p['status']] ?? $p['status']) ?></span><?= $p['reason'] !== '' ? ' <span class="smltxt">' . e(t($p['reason'])) . (isset($p['existing']) ? ' ' . e($p['existing']) : '') . '</span>' : '' ?></td></tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<form method="post" action="<?= e($module->url('import_save')) ?>">
<?= $csrf ?>
<textarea name="csv" hidden readonly><?= e($csv) ?></textarea>
<p class="tlacitka"><?php if ($saving > 0): ?><input class="tl" type="submit" value="<?= e(t('Save %d redirects', $saving)) ?>"> <?php endif ?><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
