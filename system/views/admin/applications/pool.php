<?php
/**
 * The talent pool (3.40, design B8): the applications whose applicants agreed that they are kept for future openings, the
 * soonest to lapse first – the number, the name, the position, the status, when the consent was given, until when it
 * keeps the application and whether the renewal e-mail went (Core\TalentPool::RENEW_DAYS before). A card per application
 * on a phone.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Applications $module
 * @var array{rows: list<array<string, mixed>>, total: int} $result Core\TalentPool::list
 * @var int $page
 * @var int $pages
 * @var int $months applications_pool_months
 * @var bool $admin
 */
use Kaleta\Core\Applications as Store;
use Kaleta\Core\TalentPool;
?>
<?= $app->view->render('admin/applications/tabs', ['module' => $module, 'current' => 'pool', 'admin' => $admin]) ?>
<p class="napoveda" data-applications-pool-hint><?= e(t('Applicants who agreed that you keep their application for future openings. %d days before the consent lapses, each gets an e-mail in their language with two links: keep it for another %d months, or delete it now. Without an answer the application follows the normal retention.', TalentPool::RENEW_DAYS, $months)) ?></p>
<?php if ($result['rows'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'uzivatele', 'heading' => t('Nobody is in the talent pool.'),
    'text' => t('A form offers the consent when its property “Consent to keep the application” is filled in the builder.')]) ?>
<?php else: ?>
<p class="napoveda" data-applications-pool-total><?= e(t('Kept for future openings: %s', format_count($result['total']))) ?></p>
<div class="tab-obal">
<table class="vypis vypis-karty" data-applications-pool>
<thead><tr><th scope="col"><?= e(t('Number')) ?></th><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Position')) ?></th><th scope="col"><?= e(t('Status')) ?></th>
	<th scope="col"><?= e(t('Consent given')) ?></th><th scope="col"><?= e(t('Kept until')) ?></th><th scope="col"><?= e(t('Renewal e-mail')) ?></th></tr></thead>
<tbody>
<?php foreach ($result['rows'] as $r): ?>
<tr data-pool-application="<?= (int) $r['id'] ?>">
	<td><a href="<?= e($module->url('detail', ['id' => $r['id']])) ?>">#<?= (int) $r['id'] ?></a></td>
	<td data-popisek="<?= e(t('Name')) ?>"><a href="<?= e($module->url('detail', ['id' => $r['id']])) ?>"><?= bdi((string) $r['name'] !== '' ? (string) $r['name'] : (string) $r['email']) ?></a></td>
	<td data-popisek="<?= e(t('Position')) ?>"><?= (string) $r['job_title'] !== '' ? bdi((string) $r['job_title']) : '<span class="napoveda">' . e(t('No position')) . '</span>' ?></td>
	<td data-popisek="<?= e(t('Status')) ?>"><?= e(t(Store::STATUS_LABELS[(string) $r['status']] ?? (string) $r['status'])) ?></td>
	<td data-popisek="<?= e(t('Consent given')) ?>"><?= $r['pool_consent_at'] !== null ? e(format_date((string) $r['pool_consent_at'])) : '' ?></td>
	<td data-popisek="<?= e(t('Kept until')) ?>"><?= e(format_date((string) $r['pool_until'])) ?></td>
	<td data-popisek="<?= e(t('Renewal e-mail')) ?>"><?= $r['renewal_sent'] ? e(t('sent %s', format_date((string) $r['pool_renewal_sent_at']))) : '<span class="napoveda">' . e(t('not yet')) . '</span>' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($pages > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $pages; $s++): ?>
	<?= $s === $page ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('pool', ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
