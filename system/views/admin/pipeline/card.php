<?php
/**
 * A deal on the board (3.31): the title, the customer and the company, the responsible person, the value and the expected
 * close (overdue marked), and the move form that works without JavaScript – a stage select and Move; choosing a lost stage
 * reveals the reason (CSS :has(); without it the detail asks). image/admin.js makes the card draggable and adds the
 * keyboard handle.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pipeline $module
 * @var string $csrf
 * @var array<string, mixed> $d a row of Core\Deals::list
 * @var list<array{key: string, label: string, kind: string, custom: bool}> $stages
 * @var array<int, string> $owners
 * @var string $return the query of the board (where the form returns)
 * @var string $today
 * @var bool $canContacts
 */
use Kaleta\Admin\Modules\Pipeline;
use Kaleta\Core\Deals;

$id = (int) $d['id'];
$customer = Pipeline::contactLabel($d);
$overdue = $d['closed_at'] === null && $d['expected_close'] !== null && $d['expected_close'] < $today;
?>
<li class="obchod-karta<?= $overdue ? ' obchod-po-terminu' : '' ?>" id="deal-<?= $id ?>" data-deal="<?= $id ?>"<?= $d['value'] !== null ? ' data-value="' . e($d['value']) . '" data-currency="' . e((string) $d['currency']) . '"' : '' ?>>
	<h3 class="obchod-titulek"><a href="<?= e($module->url('detail', ['id' => $id])) ?>"><?= bdi($d['title']) ?></a></h3>
<?php if ($customer !== '' || ($d['company_name'] ?? null) !== null): ?>
	<p class="obchod-kdo"><?= $customer !== '' ? ($canContacts ? '<a href="' . e($app->url(Kaleta\Core\Contacts::adminPath((int) $d['contact_id']))) . '">' . bdi($customer) . '</a>' : bdi($customer)) : '' ?><?= $customer !== '' && ($d['company_name'] ?? null) !== null ? ' · ' : '' ?><?= ($d['company_name'] ?? null) !== null ? bdi((string) $d['company_name']) : '' ?></p>
<?php endif ?>
	<p class="obchod-udaje">
<?php if ($d['value'] !== null): ?>
		<strong class="obchod-hodnota"><?= e(Deals::formatValue($d['value'], $d['currency'])) ?></strong>
<?php endif ?>
<?php if ($d['expected_close'] !== null): ?>
		<span class="obchod-termin"><?= e(t('close %s', format_date($d['expected_close']))) ?><?= $overdue ? ' <span class="stitek stitek-chyba">' . e(t('overdue')) . '</span>' : '' ?></span>
<?php endif ?>
		<span class="obchod-odpovedny"><?= $d['owner_id'] !== null ? e($owners[$d['owner_id']] ?? '') : '<span class="napoveda">' . e(t('nobody responsible')) . '</span>' ?></span>
	</p>
	<form class="obchod-presun" method="post" action="<?= e($module->url('move')) ?>" data-deal-move>
		<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="return" value="<?= e($return) ?>">
		<label class="navod-skryte" for="presun-<?= $id ?>"><?= e(t('Stage of “%s”', $d['title'])) ?></label>
		<select id="presun-<?= $id ?>" name="stage">
<?php foreach ($stages as $st): ?>
			<option value="<?= e($st['key']) ?>" data-kind="<?= e($st['kind']) ?>"<?= $st['key'] === $d['stage'] ? ' selected data-current' : '' ?>><?= e($st['label']) ?></option>
<?php endforeach ?>
		</select>
		<span class="obchod-duvod">
			<label class="navod-skryte" for="duvod-<?= $id ?>"><?= e(t('Why it was lost')) ?></label>
			<select id="duvod-<?= $id ?>" name="lost_reason">
				<option value=""><?= e(t('Why it was lost…')) ?></option>
<?php foreach (Deals::LOST_REASONS as $key => $label): ?>
				<option value="<?= e($key) ?>"><?= e(t($label)) ?></option>
<?php endforeach ?>
			</select>
		</span>
		<button class="navigace" type="submit"><?= e(t('Move')) ?></button>
	</form>
</li>
