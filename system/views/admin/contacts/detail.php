<?php
/**
 * A customer (3.28, Core\Contacts): who they are, the responsible person, everything that happened with them (the
 * timeline – enquiries, replies, bookings, the subscription, the team's comments, tasks and log), "Log a call / meeting /
 * e-mail / note", the team's comments and tasks; Edit; for administrators Merge with… and Delete. 3.37: the record template
 * (image/admin.css .zaznam-*) – the bar with New deal, E-mail and Call and More (merge, delete); the timeline and the comments
 * in the main column, the facts, the responsible person, Edit, the deals and the tasks in the side column.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $csrf
 * @var array<string, mixed> $c Core\Contacts::find
 * @var array<string, mixed>|null $company
 * @var array{enquiries: int, bookings: int, subscribed: bool} $counts
 * @var list<array<string, mixed>> $timeline Core\Contacts::timeline
 * @var array<int, ?int> $authors activity id => its author's id (who may delete it)
 * @var array{id: ?int, name: string, domain: string}|null $suggestion the company of the e-mail domain (never free-mail)
 * @var array<int, string> $people who may be responsible
 * @var list<array<string, mixed>> $companies
 * @var array<string, string> $draft a refused edit
 * @var array<string, string> $activityDraft a refused log entry
 * @var array<string, mixed> $thread Admin\CommentActions::commentThread
 * @var array<string, mixed> $tasks Admin\TaskActions::taskPanel
 * @var array<string, mixed>|null $deals the customer's deals (3.31, Admin\Modules\Pipeline::deals); null without the Pipeline
 * @var bool $admin
 * @var int $me
 */
use Kaleta\Admin\Modules\Contacts;
use Kaleta\Core\Contacts as Customers;

$icon = require __DIR__ . '/../icons.php';
$anonymised = $c['anonymised_at'] !== null;
$phoneLink = preg_replace('/[^+\d]/', '', $c['phone']) ?? '';
$languageName = $c['language'] !== '' ? (Kaleta\Core\Language::AVAILABLE[$c['language']][0] ?? $c['language']) : '';
$sources = ['enquiry' => 'from an enquiry', 'booking' => 'from a booking', 'subscriber' => 'from the newsletter', 'manual' => 'added by hand', 'import' => 'from an import'];
?>
<div class="zaznam-lista" data-record-bar>
<a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All customers')) ?></a>
<div class="zaznam-akce">
<?php if ($deals !== null && !$anonymised): ?>
	<a class="tl" href="<?= e($deals['newUrl']) ?>" data-record-new-deal><?= e(t('New deal')) ?></a>
<?php endif ?>
<?php if ($c['email'] !== ''): ?>
	<a class="navigace" href="mailto:<?= e($c['email']) ?>" data-record-email><?= e(t('E-mail')) ?></a>
<?php endif ?>
<?php if ($phoneLink !== ''): ?>
	<a class="navigace" href="tel:<?= e($phoneLink) ?>" data-record-call><?= e(t('Call')) ?></a>
<?php endif ?>
<?php if ($admin): ?>
	<details class="zaznam-vice" data-zavrit-mimo data-record-more><summary><?= e(t('More')) ?></summary><div class="zaznam-vice-menu">
<?php if (!$anonymised): ?>
		<a class="navigace" href="<?= e($module->url('merge', ['id' => $c['id']])) ?>" data-contact-merge><?= e(t('Merge with…')) ?></a>
<?php endif ?>
		<form method="post" action="<?= e($module->url('delete')) ?>" data-contact-delete data-potvrdit="<?= e(t('Delete this customer for good? Its log, comments and tasks go too; its enquiries, bookings and subscription stay. This cannot be undone.')) ?>">
			<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
	</div></details>
<?php endif ?>
</div>
</div>
<?php if ($anonymised): ?>
<p class="hlaska hlaska-info" data-contact-anonymised><?= e(t('This customer was anonymised on %s – the name, e-mail, phone and notes are gone; the record stays for statistics.', format_date((string) $c['anonymised_at']))) ?></p>
<?php endif ?>
<div class="zaznam-obal"><div class="zaznam zaznam-fakta-prvni">
<div class="zaznam-hlavni">
<section class="zakaznik-osa" id="timeline" data-timeline>
<h2><?= e(t('Timeline')) ?></h2>
<?php if (!$anonymised): ?>
<form class="zakaznik-zapis" method="post" action="<?= e($module->url('activity')) ?>" data-activity-form>
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>"><input type="hidden" name="op" value="add">
	<fieldset class="zakaznik-druh"><legend><?= e(t('Log')) ?></legend>
<?php foreach (Customers::ACTIVITY_KINDS as $kind => $label): ?>
		<label><input type="radio" name="kind" value="<?= e($kind) ?>"<?= ($activityDraft['kind'] ?? 'call') === $kind ? ' checked' : '' ?>> <?= $icon(Contacts::KINDS[$kind][0]) ?> <?= e(t($label)) ?></label>
<?php endforeach ?>
	</fieldset>
	<label class="navod-skryte" for="zapis-text"><?= e(t('What happened')) ?></label>
	<textarea class="textbox nizky" id="zapis-text" name="text" rows="3" maxlength="<?= Customers::MAX_TEXT ?>" required placeholder="<?= e(t('What happened – e.g. called about the offer, will decide by Friday.')) ?>"><?= e($activityDraft['text'] ?? '') ?></textarea>
	<div class="zakaznik-zapis-kdy">
		<label><span><?= e(t('Date')) ?></span> <input class="textpole" type="date" name="date" value="<?= e($activityDraft['date'] ?? '') ?>"></label>
		<label><span><?= e(t('Time')) ?></span> <input class="textpole" type="time" name="time" value="<?= e($activityDraft['time'] ?? '') ?>"></label>
		<button class="tl" type="submit"><?= e(t('Log it')) ?></button>
	</div>
	<span class="napoveda"><?= e(t('Without a date it is logged now. Only administration users see it.')) ?></span>
</form>
<?php endif ?>
<?php if ($timeline === []): ?>
<p class="napoveda" data-timeline-empty><?= e(t('Nothing yet.')) ?></p>
<?php else: ?>
<?= $app->view->render('admin/contacts/timeline', ['app' => $app, 'module' => $module, 'csrf' => $csrf, 'timeline' => $timeline, 'contactId' => $anonymised ? 0 : (int) $c['id'],
    'authors' => $authors, 'admin' => $admin, 'me' => $me]) ?>
<?php endif ?>
</section>
<?php if (!$anonymised): ?>
<?= $app->view->render('admin/comments', $thread + ['app' => $app, 'csrf' => $csrf]) ?>
<?php endif ?>
</div>

<div class="zaznam-bok">
<section class="zaznam-panel" aria-labelledby="zakaznik-udaje-nadpis" data-contact-card>
<h2 id="zakaznik-udaje-nadpis"><?= e(t('Details')) ?></h2>
<dl class="poptavka">
<?php if ($company !== null): ?>
	<dt><?= e(t('Company')) ?></dt><dd><a href="<?= e($module->url('company', ['id' => $company['id']])) ?>"><?= bdi($company['name']) ?></a></dd>
<?php endif ?>
<?php if ($c['email'] !== ''): ?>
	<dt><?= e(t('E-mail')) ?></dt><dd><a href="mailto:<?= e($c['email']) ?>"><?= bdi($c['email']) ?></a></dd>
<?php endif ?>
<?php if ($c['phone'] !== ''): ?>
	<dt><?= e(t('Phone')) ?></dt><dd><a href="tel:<?= e($phoneLink) ?>"><?= bdi($c['phone']) ?></a></dd>
<?php endif ?>
<?php if ($languageName !== ''): ?>
	<dt><?= e(t('Language')) ?></dt><dd><?= e($languageName) ?></dd>
<?php endif ?>
	<dt><?= e(t('First contact')) ?></dt><dd><?= $c['first_seen_at'] !== null ? e(format_date($c['first_seen_at'])) . ' · ' : '' ?><?= e(t($sources[$c['source']] ?? $c['source'])) ?></dd>
	<dt><?= e(t('Last activity')) ?></dt><dd><?= $c['last_activity_at'] !== null ? e(format_date($c['last_activity_at'], true)) : '<span class="napoveda">—</span>' ?></dd>
	<dt><?= e(t('Has')) ?></dt><dd data-contact-counts><?= e(t('Enquiries: %d', $counts['enquiries'])) ?> · <?= e(t('Bookings: %d', $counts['bookings'])) ?> · <?= e(t($counts['subscribed'] ? 'Subscribed to the newsletter' : 'Not subscribed to the newsletter')) ?></dd>
<?php if ($c['summary'] !== ''): ?>
	<dt><?= e(t('Summary')) ?></dt><dd class="zakaznik-shrnuti"><?= nl2br(e($c['summary'])) ?></dd>
<?php endif ?>
</dl>
<?php if (!$anonymised): ?>
<form class="zakaznik-odpovedny" method="post" action="<?= e($module->url('save')) ?>" data-contact-owner>
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
	<label for="zakaznik-odpovedny"><?= e(t('Responsible person')) ?></label>
	<select id="zakaznik-odpovedny" name="owner_id"><option value="0">—</option>
<?php foreach ($people as $userId => $name): ?>
		<option value="<?= (int) $userId ?>"<?= (int) $c['owner_id'] === (int) $userId ? ' selected' : '' ?>><?= e((int) $userId === $me ? t('Me (%s)', $name) : $name) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit"><?= e(t('Save')) ?></button>
</form>
<?php if ($suggestion !== null): ?>
<form class="zakaznik-navrh" method="post" action="<?= e($module->url('save')) ?>" data-company-suggestion>
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
<?php if ($suggestion['id'] !== null): ?>
	<input type="hidden" name="company_id" value="<?= (int) $suggestion['id'] ?>">
<?php else: ?>
	<input type="hidden" name="company_new" value="<?= e($suggestion['name']) ?>"><input type="hidden" name="company_domain" value="<?= e($suggestion['domain']) ?>">
<?php endif ?>
	<span><?= e(t('Company from the e-mail domain: %s (%s)', $suggestion['name'], $suggestion['domain'])) ?></span>
	<button class="navigace" type="submit"><?= e(t($suggestion['id'] !== null ? 'Add to this company' : 'Create the company and add')) ?></button>
</form>
<?php endif ?>
<div class="tlacitka zakaznik-tlacitka">
	<details class="zakaznik-upravit"<?= $draft !== [] ? ' open' : '' ?>><summary><?= e(t('Edit')) ?></summary>
	<?= $app->view->render('admin/contacts/form', ['app' => $app, 'module' => $module, 'csrf' => $csrf, 'c' => $c, 'draft' => $draft, 'companies' => $companies, 'people' => $people]) ?>
	</details>
</div>
<?php endif ?>
</section>
<?php if ($deals !== null): ?>
<?= $app->view->render('admin/pipeline/deals_box', ['box' => $deals]) ?>
<?php endif ?>
<?php if (!$anonymised): ?>
<?= $app->view->render('admin/tasks_panel', $tasks + ['app' => $app, 'csrf' => $csrf]) ?>
<?php endif ?>
</div>
</div></div>
