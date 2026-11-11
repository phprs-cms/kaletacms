<?php
/**
 * The settings of job applications (3.34 B, administrators): how long applications are kept, how long the consent for
 * future openings keeps one, whether the notification e-mail carries the fields, whether Claude may read applications, the
 * reply templates per language of the site, and who may open the section (with links to Users and Roles).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Applications $module
 * @var string $csrf
 * @var array{months: int, pool: int, full: bool, claude: bool} $values
 * @var array<string, string> $languages code => name (the default language first)
 * @var array<string, array<string, array{subject: string, text: string, own: bool}>> $templates code => key => template
 * @var list<array{id: int, name: string, admin: bool}> $people who may open applications
 * @var string|null $practice the usual retention where the company is (Core\Jobs::suggestedRetention)
 * @var array{count: int, before: string, months: int}|null $awaiting moved applications past the retention, kept until this form is saved (3.34 review H1)
 * @var bool $confirmed the retention has been confirmed since applications last moved in
 */
use Kaleta\Core\Applications as Store;
?>
<?= $app->view->render('admin/applications/tabs', ['module' => $module, 'current' => 'settings']) ?>
<form method="post" action="<?= e($module->url('settings_save')) ?>" class="formular" data-applications-settings>
	<?= $csrf ?>
	<h2><?= e(t('Retention')) ?></h2>
<?php if ($awaiting !== null): ?>
	<p class="hlaska hlaska-varovani" data-applications-retention-pending><?= e(t('%s application(s) moved from Enquiries have had no activity since before %s – past the retention of %d months. Nothing moved is deleted until you save these settings; then the daily clean-up deletes them with their CVs. Set 0 to keep them.', format_count($awaiting['count']), format_date($awaiting['before']), $awaiting['months'])) ?></p>
<?php elseif (!$confirmed): ?>
	<p class="napoveda" data-applications-retention-pending><?= e(t('Check the retention and save: applications moved from Enquiries are not deleted until an administrator saves these settings.')) ?></p>
<?php endif ?>
	<div class="radek"><label for="uchazeci-mesice"><?= e(t('Delete applications after (months)')) ?></label><div><input class="textpole kratke" type="number" id="uchazeci-mesice" name="applications_months" min="0" max="120" value="<?= (int) $values['months'] ?>">
		<span class="napoveda"><?= e(t('A decided application (hired or rejected) is deleted this many months after the decision, an undecided one after this long without any activity – with its CVs, notes and tasks. 0 = keep.')) ?><?= $practice !== null ? ' ' . e($practice) : '' ?></span></div></div>
	<div class="radek"><label for="uchazeci-zasoba"><?= e(t('Consent for future openings keeps an application (months)')) ?></label><div><input class="textpole kratke" type="number" id="uchazeci-zasoba" name="applications_pool_months" min="1" max="60" value="<?= (int) $values['pool'] ?>">
		<span class="napoveda"><?= e(t('Only when the form offers the consent (its property in the builder) and the applicant ticks it.')) ?></span></div></div>
	<h2><?= e(t('Notifications and Claude')) ?></h2>
	<div class="radek"><span></span><div><label><input type="checkbox" name="applications_notify_full" value="1"<?= $values['full'] ? ' checked' : '' ?>> <?= e(t('The e-mail about a new application carries the fields')) ?></label>
		<span class="napoveda"><?= e(t('Off: the e-mail names the position and links the application – nothing of the person leaves the site with it.')) ?></span></div></div>
	<div class="radek"><span></span><div><label><input type="checkbox" name="applications_claude" value="1"<?= $values['claude'] ? ' checked' : '' ?>> <?= e(t('Claude may read applications')) ?></label>
		<span class="napoveda"><?= e(t('Through the connection of a person who may open Applications: the fields, replies and notes – never the content of a CV. The record of processing says so.')) ?></span></div></div>
	<h2 id="templates"><?= e(t('Reply templates')) ?></h2>
	<p class="napoveda"><?= e(t('Offered when replying to an applicant; “Application received” also goes to the applicant when the form sends no confirmation of its own. Placeholders: {{name}}, {{position}}, {{company}}. Empty = the built-in text.')) ?></p>
<?php foreach ($templates as $code => $set): ?>
	<fieldset class="uchazec-sablony" data-templates-language="<?= e($code) ?>"><legend><?= e($languages[$code] ?? $code) ?></legend>
<?php foreach (Store::TEMPLATE_LABELS as $key => $templateLabel): $t = $set[$key]; $field = 'tpl_' . $code . '_' . $key; ?>
		<details class="uchazec-sablona-polozka"<?= $t['own'] ? ' open' : '' ?>><summary><?= e(t($templateLabel)) ?><?= $t['own'] ? ' <span class="stitek">' . e(t('own text')) . '</span>' : '' ?></summary>
		<div class="radek"><label for="<?= e($field) ?>-s"><?= e(t('Subject')) ?></label><div><input class="textpole siroke" type="text" id="<?= e($field) ?>-s" name="<?= e($field) ?>_subject" maxlength="<?= Kaleta\Core\EnquiryReply::MAX_SUBJECT ?>" value="<?= e($t['subject']) ?>"></div></div>
		<div class="radek"><label for="<?= e($field) ?>-t"><?= e(t('Text')) ?></label><div><textarea class="textbox" id="<?= e($field) ?>-t" name="<?= e($field) ?>_text" rows="8" maxlength="<?= Store::TEMPLATE_MAX ?>"><?= e($t['text']) ?></textarea></div></div>
		</details>
<?php endforeach ?>
	</fieldset>
<?php endforeach ?>
	<p class="tlacitka"><button class="tl" type="submit"><?= e(t('Save')) ?></button></p>
</form>
<section data-applications-people aria-labelledby="uchazeci-kdo">
<h2 id="uchazeci-kdo"><?= e(t('Who may open applications')) ?></h2>
<ul>
<?php foreach ($people as $p): ?>
	<li><?= e($p['name']) ?><?= $p['admin'] ? ' <span class="stitek">' . e(t('administrator')) . '</span>' : '' ?></li>
<?php endforeach ?>
</ul>
<p class="napoveda"><?= e(t('Administrators always may. Anybody else only when an administrator ticks Applications for them – a level never brings it.')) ?>
	<a href="<?= e($app->url('admin.php?module=users')) ?>"><?= e(t('Users')) ?></a> · <a href="<?= e($app->url('admin.php?module=roles')) ?>"><?= e(t('Roles')) ?></a></p>
</section>
