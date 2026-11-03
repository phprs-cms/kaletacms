<?php
/**
 * A deal (3.31, Core\Deals): its stage, value, expected close, customer, company, the enquiry it came from and the
 * responsible person; Move (won, lost with a reason and a note, reopen – a lost stage reveals the reason with :has(), or is
 * chosen already when the board sent the person here, ?lose=); the stage history; the team's comments and tasks (deal:N);
 * Edit; Delete for administrators.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var string $csrf
 * @var array<string, mixed> $d Core\Deals::get
 * @var array{key: string, label: string, kind: string, custom: bool}|null $stage the deal's stage
 * @var list<array{key: string, label: string, kind: string, custom: bool}> $stages
 * @var string $lose a lost stage to choose in Move ('' = the deal's own)
 * @var list<array{from: ?string, to: string, at: string, user_id: ?int}> $history
 * @var array<string, string> $labels stage key => label
 * @var array<int, string> $names people by id
 * @var array<int, string> $people who may be responsible
 * @var array<int, string> $contacts
 * @var array<int, string> $companies
 * @var array<string, mixed>|null $enquiry the enquiry it came from (idp, datum, formular) – for whoever may open Enquiries
 * @var array<string, string> $draft a refused edit
 * @var string $currency the currency the edit form offers first
 * @var string $today
 * @var bool $canContacts
 * @var array<string, mixed> $thread Admin\CommentActions::commentThread
 * @var array<string, mixed> $tasks Admin\TaskActions::taskPanel
 * @var bool $admin
 * @var int $me
 */
use Kaleta\Admin\Modules\Pipeline;
use Kaleta\Core\Contacts;
use Kaleta\Core\Deals;

$id = (int) $d['id'];
$kind = $stage['kind'] ?? 'open';
$customer = Pipeline::contactLabel($d);
$overdue = $d['closed_at'] === null && $d['expected_close'] !== null && $d['expected_close'] < $today;
$chosen = $lose !== '' ? $lose : $d['stage'];
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url() . '#deal-' . $id) ?>">← <?= e(t('The pipeline')) ?></a></p>
<section class="zakaznik-karta obchod-detail" data-deal-card>
<dl class="poptavka">
	<dt><?= e(t('Stage')) ?></dt><dd data-deal-stage="<?= e($d['stage']) ?>"><span class="stitek<?= $kind === 'won' ? ' stitek-vydano' : ($kind === 'lost' ? ' stitek-chyba' : '') ?>"><?= e($labels[$d['stage']] ?? $d['stage']) ?></span><?= $d['closed_at'] !== null ? ' · ' . e(t('closed %s', format_date($d['closed_at']))) : '' ?></dd>
<?php if ($d['lost_reason'] !== null): ?>
	<dt><?= e(t('Why it was lost')) ?></dt><dd data-deal-lost><?= e(t(Deals::LOST_REASONS[$d['lost_reason']] ?? $d['lost_reason'])) ?><?= $d['lost_note'] !== '' ? ' – ' . bdi($d['lost_note']) : '' ?></dd>
<?php endif ?>
	<dt><?= e(t('Value')) ?></dt><dd><?= $d['value'] !== null ? '<strong>' . e(Deals::formatValue($d['value'], $d['currency'])) . '</strong>' : '<span class="napoveda">—</span>' ?></dd>
	<dt><?= e(t('Expected close')) ?></dt><dd><?= $d['expected_close'] !== null ? e(format_date($d['expected_close'])) . ($overdue ? ' <span class="stitek stitek-chyba">' . e(t('overdue')) . '</span>' : '') : '<span class="napoveda">—</span>' ?></dd>
	<dt><?= e(t('Customer')) ?></dt><dd><?= $customer !== '' ? ($canContacts ? '<a href="' . e($app->url(Contacts::adminPath((int) $d['contact_id']))) . '">' . bdi($customer) . '</a>' : bdi($customer)) : '<span class="napoveda">—</span>' ?><?= $canContacts && $d['contact_email'] !== null && $d['contact_email'] !== '' && $d['contact_email'] !== $customer ? ' · <a href="mailto:' . e($d['contact_email']) . '">' . bdi($d['contact_email']) . '</a>' : '' ?></dd>
<?php if ($d['company_id'] !== null): ?>
	<dt><?= e(t('Company')) ?></dt><dd><?= $canContacts ? '<a href="' . e($app->url(Contacts::companyAdminPath($d['company_id']))) . '">' . bdi((string) $d['company_name']) . '</a>' : bdi((string) $d['company_name']) ?></dd>
<?php endif ?>
<?php if ($d['source_enquiry_id'] !== null): ?>
	<dt><?= e(t('Came from')) ?></dt><dd data-deal-enquiry><?= $enquiry !== null ? '<a href="' . e($app->url('admin.php?module=enquiries&action=detail&id=' . (int) $enquiry['idp'])) . '">' . e(t('Enquiry #%s', (string) $enquiry['idp'])) . '</a> · ' . bdi((string) $enquiry['formular']) . ' · ' . e(format_date((string) $enquiry['datum'])) : e(t('Enquiry #%s', (string) $d['source_enquiry_id'])) ?></dd>
<?php endif ?>
	<dt><?= e(t('Created')) ?></dt><dd><?= e(format_date($d['created_at'], true)) ?><?= $d['created_by'] !== null && ($names[$d['created_by']] ?? '') !== '' ? ' · ' . e($names[$d['created_by']]) : '' ?></dd>
</dl>
<form class="zakaznik-odpovedny" method="post" action="<?= e($module->url('save')) ?>" data-deal-owner>
	<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>">
	<label for="obchod-odpovedny"><?= e(t('Responsible person')) ?></label>
	<select id="obchod-odpovedny" name="owner_id"><option value="0">—</option>
<?php foreach ($people as $userId => $name): ?>
		<option value="<?= (int) $userId ?>"<?= (int) $d['owner_id'] === (int) $userId ? ' selected' : '' ?>><?= e((int) $userId === $me ? t('Me (%s)', $name) : $name) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit"><?= e(t('Save')) ?></button>
</form>
<div class="tlacitka zakaznik-tlacitka">
	<details class="zakaznik-upravit"<?= $draft !== [] ? ' open' : '' ?>><summary><?= e(t('Edit')) ?></summary>
	<?= $app->view->render('admin/pipeline/form', ['module' => $module, 'csrf' => $csrf, 'd' => $d, 'draft' => $draft + ['currency' => $currency], 'contacts' => $contacts, 'companies' => $companies,
        'people' => $people, 'stages' => [], 'canContacts' => $canContacts, 'me' => $me]) ?>
	</details>
<?php if ($admin): ?>
	<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-deal-delete data-potvrdit="<?= e(t('Delete this deal for good? Its history, comments and tasks go too; the customer and the enquiry stay. This cannot be undone.')) ?>">
		<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
<?php endif ?>
</div>
</section>

<section class="obchod-posun" id="move" aria-labelledby="obchod-posun-nadpis">
<h2 id="obchod-posun-nadpis"><?= e(t('Move')) ?></h2>
<form class="obchod-presun obchod-presun-detail" method="post" action="<?= e($module->url('move')) ?>" data-deal-move>
	<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="return" value="detail">
	<label for="obchod-etapa"><?= e(t('Stage')) ?></label>
	<select id="obchod-etapa" name="stage">
<?php foreach ($stages as $st): ?>
		<option value="<?= e($st['key']) ?>" data-kind="<?= e($st['kind']) ?>"<?= $st['key'] === $d['stage'] ? ' data-current' : '' ?><?= $st['key'] === $chosen ? ' selected' : '' ?>><?= e($st['label']) ?></option>
<?php endforeach ?>
	</select>
	<span class="obchod-duvod">
		<label for="obchod-duvod"><?= e(t('Why it was lost')) ?></label>
		<select id="obchod-duvod" name="lost_reason">
			<option value=""><?= e(t('Why it was lost…')) ?></option>
<?php foreach (Deals::LOST_REASONS as $key => $label): ?>
			<option value="<?= e($key) ?>"><?= e(t($label)) ?></option>
<?php endforeach ?>
		</select>
		<label for="obchod-poznamka"><?= e(t('Note (optional)')) ?></label>
		<input class="textpole siroke" type="text" id="obchod-poznamka" name="lost_note" maxlength="<?= Deals::NOTE_MAX ?>">
	</span>
	<button class="tl" type="submit"><?= e(t('Move')) ?></button>
</form>
<p class="napoveda"><?= e(t('Won and lost close the deal; moving a closed deal to an open stage opens it again.')) ?></p>
</section>

<section class="obchod-historie-obal" id="history" data-deal-history>
<h2><?= e(t('History')) ?></h2>
<ol class="obchod-historie">
<?php foreach ($history as $h): ?>
	<li data-history-to="<?= e($h['to']) ?>"><span class="obchod-historie-co"><?= $h['from'] === null ? e(t('Created in %s', $labels[$h['to']] ?? $h['to'])) : e(($labels[$h['from']] ?? $h['from']) . ' → ' . ($labels[$h['to']] ?? $h['to'])) ?></span>
		<span class="napoveda"><?= e(format_date($h['at'], true)) ?><?= $h['user_id'] !== null && ($names[$h['user_id']] ?? '') !== '' ? ' · ' . e($names[$h['user_id']]) : '' ?></span></li>
<?php endforeach ?>
</ol>
</section>
<?= $app->view->render('admin/comments', $thread + ['app' => $app, 'csrf' => $csrf]) ?>
<?= $app->view->render('admin/tasks_panel', $tasks + ['app' => $app, 'csrf' => $csrf]) ?>
