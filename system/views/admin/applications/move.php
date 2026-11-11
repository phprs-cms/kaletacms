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
 */
use Kaleta\Core\ApplicationMove;

$name = fn (string $form, string $source): string => ($form !== '' ? $form : t('(no name)')) . ' · ' . $source;
?>
<?= $app->view->render('admin/applications/tabs', ['module' => $module, 'current' => 'move']) ?>
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
<form class="uchazec-stav" method="post" action="<?= e($module->url('move_run')) ?>" data-move-to-enquiries data-potvrdit="<?= e(t('Move the applications of this form back to Enquiries? Everyone with Enquiries will see them and their CVs.')) ?>">
	<?= $csrf ?><input type="hidden" name="direction" value="back">
	<label for="presun-zpet-formular"><?= e(t('Form')) ?></label>
	<select id="presun-zpet-formular" name="form">
<?php foreach ($applicationForms as $f): ?>
		<option value="<?= e(ApplicationMove::key($f['form'], $f['source'])) ?>"><?= e($name($f['form'], $f['source'])) ?> (<?= format_count($f['count']) ?>)</option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit"><?= e(t('Move back to Enquiries')) ?></button>
</form>
<p class="napoveda"><?= e(t('A form on a job page or with the Purpose “Job application” keeps sending applications – change it in the builder first.')) ?></p>
<?php endif ?>
</section>
