<?php
/**
 * The Team tab of My work (3.27, administrators, Core\Tasks::team): open tasks per person – overdue, today, upcoming
 * (7 days), later or without a date. Tasks of a removed account or handed to nobody are listed too, so nothing is lost.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\MyWork $module
 * @var array<int, array{name: string, overdue: int, today: int, upcoming: int, later: int, open: int}> $people
 */
?>
<?= $app->view->render('admin/my_work/tabs', ['module' => $module, 'current' => 'team']) ?>
<?php if ($people === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'moje', 'heading' => t('Nobody has an open task.'),
    'text' => t('Tasks the team creates – for themselves or for a colleague, in the administration or through Claude – are counted here per person.')]) ?>
<?php else: ?>
<p class="napoveda"><?= e(t('Open tasks per person. Each person completes and snoozes their own in My work.')) ?></p>
<section class="moje-prace" data-list="team" aria-labelledby="moje-team">
<h2 id="moje-team"><?= e(t('Open tasks of the team')) ?></h2>
<div class="tab-obal">
<table class="vypis vypis-karty">
<thead><tr><th scope="col"><?= e(t('Person')) ?></th><th scope="col" class="cislo"><?= e(t('Overdue')) ?></th><th scope="col" class="cislo"><?= e(t('Today')) ?></th>
	<th scope="col" class="cislo"><?= e(t('Upcoming')) ?></th><th scope="col" class="cislo"><?= e(t('Later')) ?></th><th scope="col" class="cislo"><?= e(t('All open')) ?></th></tr></thead>
<tbody>
<?php foreach ($people as $p): ?>
<tr>
	<th scope="row"><?= e($p['name']) ?></th>
	<td class="cislo"><?= $p['overdue'] > 0 ? '<span class="stitek stitek-koncept">' . format_count($p['overdue']) . '</span>' : '0' ?></td>
	<td class="cislo"><?= format_count($p['today']) ?></td>
	<td class="cislo"><?= format_count($p['upcoming']) ?></td>
	<td class="cislo"><?= format_count($p['later']) ?></td>
	<td class="cislo"><?= format_count($p['open']) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</section>
<?php endif ?>
