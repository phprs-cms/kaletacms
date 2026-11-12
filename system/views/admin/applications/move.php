<?php
/**
 * Move by form (3.34 B, administrators, Core\ApplicationMove): enquiries of one form become job applications – for a career
 * form that is not on a job's item page – and applications of one form go back to Enquiries. A batch at a time, safe to
 * run again; the forms that look like applications are listed first.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Applications $module
 * @var string $csrf
 * @var list<array{form: string, source: string, count: int, jobs: int, looks: bool}> $enquiryForms
 * @var list<array{form: string, source: string, count: int}> $applicationForms
 * @var int $batch
 * @var array{key: string, form: string, source: string, preview: array{total: int, move: int, expired: int, kept: int, months: int}, all: array{total: int, move: int, expired: int, kept: int, months: int}}|null $review
 */
use Kaleta\Core\ApplicationMove;

$name = fn (string $form, string $source): string => ($form !== '' ? $form : t('(no name)')) . ' · ' . $source;
?>
<?= $app->view->render('admin/applications/tabs', ['module' => $module, 'current' => 'move', 'admin' => true]) ?>
<p class="napoveda"><?= e(t('Applications from the forms on your job pages come here by themselves. A career form elsewhere – on an ordinary page, in a pop-up – sent enquiries until its Purpose was set to “Job application”: move them here by form. The fields, replies, notes, tasks and the responsible person move along; a customer record made only by them is deleted; a deal keeps going without the link. At most %d at a time – run it again for the rest.', $batch)) ?></p>
<section aria-labelledby="presun-k-uchazecum">
<h2 id="presun-k-uchazecum"><?= e(t('Enquiries to Applications')) ?></h2>
<?php if ($enquiryForms === []): ?>
<p class="napoveda"><?= e(t('There are no enquiries.')) ?></p>
<?php else: ?>
<form class="uchazec-stav" method="post" action="<?= e($module->url('move_run')) ?>" data-move-to-applications data-potvrdit="<?= e(t('Move the enquiries of this form to Applications? People with Enquiries will no longer see them.')) ?>">
	<?= $csrf ?><input type="hidden" name="direction" value="to">
	<label for="presun-formular"><?= e(t('Form')) ?></label>
	<select id="presun-formular" name="form">
<?php foreach ($enquiryForms as $f): ?>
		<option value="<?= e(ApplicationMove::key($f['form'], $f['source'])) ?>"><?= e($name($f['form'], $f['source'])) ?> (<?= format_count($f['count']) ?>)<?= $f['looks'] ? ' – ' . e(t('looks like applications')) : '' ?></option>
<?php endforeach ?>
	</select>
	<button class="tl" type="submit"><?= e(t('Move to Applications')) ?></button>
</form>
<?php endif ?>
</section>
<section aria-labelledby="presun-zpet">
<h2 id="presun-zpet"><?= e(t('Applications back to Enquiries')) ?></h2>
<?php if ($applicationForms === []): ?>
<p class="napoveda"><?= e(t('There are no applications.')) ?></p>
<?php else: ?>
<?php if ($review !== null): // 3.34 second review N3: what goes back and what stays, before anything moves
	$p = $review['preview'];
	$all = $review['all']; ?>
<div class="hlaska hlaska-varovani" data-move-back-review="<?= e($p['total'] . '|' . $p['move'] . '|' . $p['expired'] . '|' . $p['kept'] . '|' . $all['move']) ?>">
<p><strong><?= e($name($review['form'], $review['source'])) ?></strong> – <?= e(t('%d applications. %d will move back to Enquiries.', $p['total'], $p['move'])) ?></p>
<?php if ($p['expired'] > 0): ?>
<p><?= e(t('%d stay here: they arrived more than %d months ago, and the retention of enquiries would delete them at once.', $p['expired'], $p['months'])) ?></p>
<?php endif ?>
<?php if ($p['kept'] > 0): ?>
<p><?= e(t('%d stay here: they are hired or kept for future openings – tick the box below to move them too.', $p['kept'])) ?></p>
<?php endif ?>
<p data-move-back-drops><?= e(t('Enquiries have no consent for future openings, no status and no history of statuses: these are dropped (the status goes into an internal note). The fields, CVs, replies, notes, tasks and the responsible person move along.')) ?></p>
<form class="uchazec-stav" method="post" action="<?= e($module->url('move_run')) ?>" data-move-to-enquiries data-potvrdit="<?= e(t('Move the applications of this form back to Enquiries? Everyone with Enquiries will see them and their CVs.')) ?>">
	<?= $csrf ?><input type="hidden" name="direction" value="back"><input type="hidden" name="confirmed" value="1"><input type="hidden" name="form" value="<?= e($review['key']) ?>">
<?php if ($p['kept'] > 0): ?>
	<label><input type="checkbox" name="include_kept" value="1"> <?= e(t('Move the hired ones and those kept for future openings too (%d would move)', $all['move'])) ?></label>
<?php endif ?>
	<button class="navigace" type="submit"<?= $p['move'] === 0 && $all['move'] === 0 ? ' disabled' : '' ?>><?= e(t('Move back to Enquiries')) ?></button>
	<a href="<?= e($module->url('move')) ?>"><?= e(t('Cancel')) ?></a>
</form>
</div>
<?php else: ?>
<form class="uchazec-stav" method="get" action="<?= e($app->url('admin.php')) ?>" data-move-to-enquiries-review>
	<input type="hidden" name="module" value="applications"><input type="hidden" name="action" value="move">
	<label for="presun-zpet-formular"><?= e(t('Form')) ?></label>
	<select id="presun-zpet-formular" name="back">
<?php foreach ($applicationForms as $f): ?>
		<option value="<?= e(ApplicationMove::key($f['form'], $f['source'])) ?>"><?= e($name($f['form'], $f['source'])) ?> (<?= format_count($f['count']) ?>)</option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit"><?= e(t('Review the move back')) ?></button>
</form>
<?php endif ?>
<p class="napoveda"><?= e(t('A form on a job page or with the Purpose “Job application” keeps sending applications – change it in the builder first.')) ?></p>
<?php endif ?>
</section>
