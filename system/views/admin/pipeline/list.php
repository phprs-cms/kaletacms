<?php
/**
 * The Pipeline as a list (3.31, Core\Deals::list): the filter of the board and the status (open, closed, won, lost, all);
 * title, stage, customer, company, responsible person, value, expected close (overdue marked) or when it closed; paging.
 * A card per deal on a phone.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var array{owner: string, contact: int, company: int, search: string, status: string, view: string} $filter
 * @var array<string, int|string> $query
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} $result
 * @var array<string, string> $labels stage key => label
 * @var array<string, string> $kinds stage key => kind
 * @var array<int, string> $owners
 * @var array<int, string> $people
 * @var list<array<string, mixed>> $companies
 * @var string $contactName
 * @var string $companyName
 * @var bool $canContacts
 * @var bool $admin
 * @var int $me
 */
use Kaleta\Admin\Modules\Pipeline;
use Kaleta\Core\Clock;
use Kaleta\Core\Deals;

$today = Clock::today();
$filtered = $filter['search'] !== '' || $filter['owner'] !== '' || $filter['company'] > 0 || $filter['contact'] > 0 || $filter['status'] !== 'open';
?>
<?= $app->view->render('admin/pipeline/tabs', ['module' => $module, 'current' => 'list', 'query' => $query, 'admin' => $admin]) ?>
<?= $app->view->render('admin/pipeline/filter', ['app' => $app, 'module' => $module, 'filter' => $filter, 'people' => $people, 'companies' => $companies, 'contactName' => $contactName,
    'companyName' => $companyName, 'canContacts' => $canContacts, 'me' => $me]) ?>
<?php if ($result['rows'] === [] && $filtered): ?>
<?= $app->view->render('admin/empty', ['icon' => 'obchody', 'heading' => t('No deal matches the filter.'), 'text' => t('Try another word or clear the filter.'), 'action' => [$module->url('', ['view' => 'list']), t('Clear filter')]]) ?>
<?php elseif ($result['rows'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'obchody', 'heading' => t('No open deals.'),
    'text' => t('A deal is what a customer may buy – it moves from New to Won or Lost. Create one here, or with “Create deal” on an enquiry or a customer.'),
    'action' => [$module->url('new'), t('New deal')]]) ?>
<?php else: ?>
<p class="napoveda" data-deals-total><?= e(t('Deals: %s', format_count($result['total']))) ?></p>
<div class="tab-obal">
<table class="vypis vypis-karty" data-deals-list>
<thead><tr><th scope="col"><?= e(t('Deal')) ?></th><th scope="col"><?= e(t('Stage')) ?></th><th scope="col"><?= e(t('Customer')) ?></th><th scope="col"><?= e(t('Responsible person')) ?></th>
	<th scope="col" class="cislo"><?= e(t('Value')) ?></th><th scope="col"><?= e(t($filter['status'] === 'open' ? 'Expected close' : 'Expected close or closed')) ?></th></tr></thead>
<tbody>
<?php foreach ($result['rows'] as $d): $customer = Pipeline::contactLabel($d); $kind = $kinds[$d['stage']] ?? 'open';
    $overdue = $d['closed_at'] === null && $d['expected_close'] !== null && $d['expected_close'] < $today; ?>
<tr id="deal-<?= (int) $d['id'] ?>" data-deal="<?= (int) $d['id'] ?>">
	<td><a href="<?= e($module->url('detail', ['id' => $d['id']])) ?>"><?= bdi($d['title']) ?></a></td>
	<td data-popisek="<?= e(t('Stage')) ?>"><span class="stitek<?= $kind === 'won' ? ' stitek-vydano' : ($kind === 'lost' ? ' stitek-chyba' : '') ?>"><?= e($labels[$d['stage']] ?? $d['stage']) ?></span></td>
	<td data-popisek="<?= e(t('Customer')) ?>"><?= $customer !== '' ? ($canContacts ? '<a href="' . e($app->url(Kaleta\Core\Contacts::adminPath((int) $d['contact_id']))) . '">' . bdi($customer) . '</a>' : bdi($customer)) : '' ?><?= ($d['company_name'] ?? null) !== null ? ($customer !== '' ? '<br>' : '') . '<small>' . bdi((string) $d['company_name']) . '</small>' : '' ?></td>
	<td data-popisek="<?= e(t('Responsible person')) ?>"><?= $d['owner_id'] !== null ? e($owners[$d['owner_id']] ?? '') : '' ?></td>
	<td class="cislo" data-popisek="<?= e(t('Value')) ?>"><?= e(Deals::formatValue($d['value'], $d['currency'])) ?></td>
	<td data-popisek="<?= e(t('Expected close')) ?>"><?php if ($d['closed_at'] !== null): ?><?= e(t('closed %s', format_date($d['closed_at']))) ?><?php elseif ($d['expected_close'] !== null): ?><?= e(format_date($d['expected_close'])) ?><?= $overdue ? ' <span class="stitek stitek-chyba">' . e(t('overdue')) . '</span>' : '' ?><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($result['pages'] > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $result['pages']; $s++): ?>
	<?= $s === $result['page'] ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', $query + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
