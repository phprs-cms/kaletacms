<?php
/**
 * The quotes (3.38 B, Core\Quotes::list): number, title, customer, the net total with its currency, status, validity and
 * the responsible person; filters by status, person ("Mine", nobody, a person) and a word, or the record of a box; paging.
 * "Create a quote" makes an empty draft (POST); the empty state also says how to ask Claude. A card per quote on a phone.
 * Amounts appear only here, for people with the section – never in the change log, a bell or a report.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Quotes $module
 * @var string $csrf
 * @var array{status: string, owner: string, search: string, enquiry: int, deal: int, contact: int, company: int} $filter
 * @var array<string, int|string> $query
 * @var array{rows: list<array<string, mixed>>, total: int, page: int, pages: int} $result
 * @var array<int, string> $people
 * @var array<int, string> $owners
 * @var bool $admin
 * @var int $me
 */
use Kaleta\Core\Clock;
use Kaleta\Core\Money;
use Kaleta\Core\Quotes;

$labels = ['all' => 'All quotes', 'open' => 'Draft or sent', 'draft' => 'Drafts', 'sent' => 'Sent', 'accepted' => 'Accepted', 'declined' => 'Declined', 'expired' => 'Expired'];
$filtered = $query !== [];
$today = Clock::today();
?>
<?= $app->view->render('admin/quotes/tabs', ['module' => $module, 'current' => 'list', 'admin' => $admin]) ?>
<div class="nabidky-lista" data-quotes-bar>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr" data-quotes-filter>
	<input type="hidden" name="module" value="<?= e($module::IDENT) ?>">
<?php foreach (['enquiry', 'deal', 'contact', 'company'] as $key): if ($filter[$key] > 0): ?>
	<input type="hidden" name="<?= $key ?>" value="<?= (int) $filter[$key] ?>">
<?php endif; endforeach ?>
	<label><span><?= e(t('Status')) ?></span> <select name="status">
<?php foreach ($labels as $key => $label): ?>
		<option value="<?= e($key) ?>"<?= $filter['status'] === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select></label>
	<label><span><?= e(t('Responsible person')) ?></span> <select name="owner">
		<option value=""><?= e(t('Anyone')) ?></option>
		<option value="mine"<?= $filter['owner'] === 'mine' ? ' selected' : '' ?>><?= e(t('Mine')) ?></option>
		<option value="none"<?= $filter['owner'] === 'none' ? ' selected' : '' ?>><?= e(t('Nobody')) ?></option>
<?php foreach ($people as $userId => $name): if ((int) $userId !== $me): ?>
		<option value="<?= (int) $userId ?>"<?= $filter['owner'] === (string) $userId ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endif; endforeach ?>
	</select></label>
	<label class="zakaznici-hledat"><span><?= e(t('Search')) ?></span> <input class="textpole" type="search" name="search" value="<?= e($filter['search']) ?>" placeholder="<?= e(t('Number, title or customer')) ?>"></label>
	<button class="navigace" type="submit"><?= e(t('Filter')) ?></button>
</form>
<form method="post" action="<?= e($module->url('create')) ?>" data-quote-create><?= $csrf ?><button class="tl" type="submit"><?= e(t('Create a quote')) ?></button></form>
</div>
<?php if ($result['rows'] === [] && $filtered): ?>
<?= $app->view->render('admin/empty', ['icon' => 'clanek', 'heading' => t('No quote matches the filter.'), 'text' => t('Try another word or clear the filter.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($result['rows'] === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'clanek', 'heading' => t('No quotes yet.'),
    'text' => t('A quote takes its prices from Internal prices and gets its number when it is sent. Create one here, with “New quote” on an enquiry, a deal or a customer – or ask Claude: “Make a quote for enquiry 214, valid three weeks.”')]) ?>
<?php else: ?>
<p class="napoveda" data-quotes-total><?= e(t('Quotes: %s', format_count($result['total']))) ?></p>
<div class="tab-obal">
<table class="vypis vypis-karty" data-quotes-list>
<thead><tr><th scope="col"><?= e(t('Quote')) ?></th><th scope="col"><?= e(t('Customer')) ?></th><th scope="col"><?= e(t('Status')) ?></th>
	<th scope="col" class="cislo"><?= e(t('Total without VAT')) ?></th><th scope="col"><?= e(t('Valid until')) ?></th><th scope="col"><?= e(t('Responsible person')) ?></th></tr></thead>
<tbody>
<?php foreach ($result['rows'] as $q): $customer = $q['customer_company'] !== '' ? $q['customer_company'] : ($q['customer_name'] !== '' ? $q['customer_name'] : $q['customer_email']);
    $late = $q['status'] === 'sent' && $q['valid_until'] !== null && $q['valid_until'] < $today; ?>
<tr id="quote-<?= (int) $q['id'] ?>" data-quote="<?= (int) $q['id'] ?>">
	<td><a href="<?= e($module->url($q['status'] === 'draft' ? 'edit' : 'detail', ['id' => $q['id']])) ?>"><strong><?= e($q['label']) ?></strong></a><?= $q['title'] !== '' ? '<br><small>' . bdi($q['title']) . '</small>' : '' ?></td>
	<td data-popisek="<?= e(t('Customer')) ?>"><?= $customer !== '' ? bdi($customer) : '<span class="napoveda">—</span>' ?><?= $q['customer_company'] !== '' && $q['customer_name'] !== '' ? '<br><small>' . bdi($q['customer_name']) . '</small>' : '' ?></td>
	<td data-popisek="<?= e(t('Status')) ?>"><span class="<?= e($module::statusClass($q['status'])) ?>" data-quote-status="<?= e($q['status']) ?>"><?= e(t(Quotes::STATUS_LABELS[$q['status']] ?? $q['status'])) ?></span></td>
	<td class="cislo" data-popisek="<?= e(t('Total without VAT')) ?>"><?= e(Money::format(Money::fromDecimal($q['total_net']), $q['currency'])) ?></td>
	<td data-popisek="<?= e(t('Valid until')) ?>"><?= $q['valid_until'] !== null ? e(format_date($q['valid_until'])) . ($late ? ' <span class="stitek stitek-chyba">' . e(t('past')) . '</span>' : '') : '' ?></td>
	<td data-popisek="<?= e(t('Responsible person')) ?>"><?= $q['owner_id'] !== null ? e($owners[$q['owner_id']] ?? '') : '' ?></td>
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
