<?php
/**
 * A company (3.28, Core\Contacts): its details, its contacts, the timeline of its contacts (the latest entries of each,
 * merged), the team's comments and tasks (company:N); Edit; Delete for administrators – the contacts stay.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Contacts $module
 * @var string $csrf
 * @var array<string, mixed> $co Core\Contacts::findCompany
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} $contacts
 * @var list<array<string, mixed>> $timeline
 * @var array<int, string> $people who may be responsible
 * @var array<int, string> $owners names of the responsible people
 * @var array<string, string> $draft
 * @var array<string, mixed> $thread
 * @var array<string, mixed> $tasks
 * @var bool $admin
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url('companies')) ?>">← <?= e(t('All companies')) ?></a></p>
<section class="zakaznik-karta" data-company-card>
<dl class="poptavka">
<?php if ($co['domain'] !== ''): ?>
	<dt><?= e(t('Domain')) ?></dt><dd><?= e($co['domain']) ?></dd>
<?php endif ?>
<?php if ($co['registration_no'] !== ''): ?>
	<dt><?= e(t('Registration number')) ?></dt><dd><?= e($co['registration_no']) ?></dd>
<?php endif ?>
<?php if ($co['vat_id'] !== ''): ?>
	<dt><?= e(t('VAT number')) ?></dt><dd><?= e($co['vat_id']) ?></dd>
<?php endif ?>
	<dt><?= e(t('Responsible person')) ?></dt><dd><?= $co['owner_id'] !== null ? e($owners[$co['owner_id']] ?? '') : '<span class="napoveda">—</span>' ?></dd>
<?php if ($co['note'] !== ''): ?>
	<dt><?= e(t('Note')) ?></dt><dd class="zakaznik-shrnuti"><?= nl2br(e($co['note'])) ?></dd>
<?php endif ?>
</dl>
<div class="tlacitka zakaznik-tlacitka">
	<details class="zakaznik-upravit"<?= $draft !== [] ? ' open' : '' ?>><summary><?= e(t('Edit')) ?></summary>
	<?= $app->view->render('admin/contacts/company_form', ['module' => $module, 'csrf' => $csrf, 'co' => $co, 'draft' => $draft, 'people' => $people]) ?>
	</details>
	<a class="navigace" href="<?= e($module->url('new', ['company' => $co['id']])) ?>"><?= e(t('New contact here')) ?></a>
<?php if ($admin): ?>
	<form class="vradku" method="post" action="<?= e($module->url('company_delete')) ?>" data-company-delete data-potvrdit="<?= e(t('Delete the company “%s”? Its contacts stay, without a company; its comments and tasks go.', $co['name'])) ?>">
		<?= $csrf ?><input type="hidden" name="company" value="<?= (int) $co['id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
<?php endif ?>
</div>
</section>

<section id="company-contacts" data-company-contacts>
<h2><?= e(t('Contacts')) ?> <small><?= format_count($contacts['total']) ?></small></h2>
<?php if ($contacts['rows'] === []): ?>
<p class="napoveda"><?= e(t('No contacts in this company yet.')) ?></p>
<?php else: ?>
<div class="tab-obal">
<table class="vypis vypis-karty">
<thead><tr><th scope="col"><?= e(t('Full name')) ?></th><th scope="col"><?= e(t('E-mail')) ?></th><th scope="col"><?= e(t('Phone')) ?></th><th scope="col"><?= e(t('Responsible person')) ?></th><th scope="col"><?= e(t('Last activity')) ?></th></tr></thead>
<tbody>
<?php foreach ($contacts['rows'] as $c): ?>
<tr data-contact="<?= (int) $c['id'] ?>">
	<td><a href="<?= e($module->url('detail', ['id' => $c['id']])) ?>"><?= bdi(Kaleta\Core\Contacts::displayName($c)) ?></a></td>
	<td data-popisek="<?= e(t('E-mail')) ?>"><?= $c['email'] !== '' ? '<a href="mailto:' . e($c['email']) . '">' . bdi($c['email']) . '</a>' : '' ?></td>
	<td data-popisek="<?= e(t('Phone')) ?>"><?= $c['phone'] !== '' ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', $c['phone']) ?? '') . '">' . bdi($c['phone']) . '</a>' : '' ?></td>
	<td data-popisek="<?= e(t('Responsible person')) ?>"><?= $c['owner_id'] !== null ? e($owners[$c['owner_id']] ?? '') : '' ?></td>
	<td data-popisek="<?= e(t('Last activity')) ?>"><?= $c['last_activity_at'] !== null ? e(format_date($c['last_activity_at'])) : '' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($contacts['total'] > count($contacts['rows'])): ?>
<p><a class="navigace" href="<?= e($module->url('', ['company' => $co['id']])) ?>"><?= e(t('All %s contacts', format_count($contacts['total']))) ?></a></p>
<?php endif ?>
<?php endif ?>
</section>

<section class="zakaznik-osa" id="timeline" data-timeline>
<h2><?= e(t('Timeline')) ?></h2>
<?php if ($timeline === []): ?>
<p class="napoveda" data-timeline-empty><?= e(t('Nothing yet.')) ?></p>
<?php else: ?>
<p class="napoveda"><?= e(t('The latest of everything that happened with its contacts.')) ?></p>
<?= $app->view->render('admin/contacts/timeline', ['app' => $app, 'module' => $module, 'csrf' => $csrf, 'timeline' => $timeline, 'contactId' => 0, 'authors' => [], 'admin' => $admin, 'me' => 0]) ?>
<?php endif ?>
</section>
<?= $app->view->render('admin/comments', $thread + ['app' => $app, 'csrf' => $csrf]) ?>
<?= $app->view->render('admin/tasks_panel', $tasks + ['app' => $app, 'csrf' => $csrf]) ?>
