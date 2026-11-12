<?php
/**
 * A deal on the board (3.31): the title, the customer and the company, the responsible person, the value and the expected
 * close (overdue marked), and the move form that works without JavaScript – a stage select and Move; choosing a lost stage
 * reveals the reason (CSS :has(); without it the detail asks). image/admin.js makes the card draggable and adds the
 * keyboard handle. 3.37 (U28): a lighter card – the title in at most two lines, the customer in one, one line of value ·
 * close · the responsible person's initials (the name for screen readers and on hover); with a mouse admin.js folds the
 * move form behind "⋯" (on a touch screen and without JavaScript it stays open). 3.41 (R5): an open deal says how many days
 * it is in its stage and its next step – the open task about it due first (its title and day, overdue marked) or "No next
 * step"; a move on the board (admin.js) sets the days to today.
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
 * @var string|null $since since when it is in its stage (open deals; Core\Deals::stageSince)
 * @var array<string, mixed>|null $next its next open task (Core\Tasks::nextFor)
 */
use Kaleta\Admin\Modules\Pipeline;
use Kaleta\Core\Deals;

$id = (int) $d['id'];
$since ??= null; // 3.41: only the board passes them
$next ??= null;
$customer = Pipeline::contactLabel($d);
$overdue = $d['closed_at'] === null && $d['expected_close'] !== null && $d['expected_close'] < $today;
$owner = $d['owner_id'] !== null ? (string) ($owners[$d['owner_id']] ?? '') : '';
// the initials of the first two words of the name ("Jana Nováková" → "JN")
$initials = mb_strtoupper(implode('', array_map(fn (string $word): string => mb_substr($word, 0, 1), array_slice(preg_split('/\s+/u', trim($owner), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2))));
?>
<li class="obchod-karta<?= $overdue ? ' obchod-po-terminu' : '' ?>" id="deal-<?= $id ?>" data-deal="<?= $id ?>"<?= $d['value'] !== null ? ' data-value="' . e($d['value']) . '" data-currency="' . e((string) $d['currency']) . '"' : '' ?>>
	<h3 class="obchod-titulek"><a href="<?= e($module->url('detail', ['id' => $id])) ?>"><?= bdi($d['title']) ?></a></h3>
<?php if ($customer !== '' || ($d['company_name'] ?? null) !== null): ?>
	<p class="obchod-kdo"><?= $customer !== '' ? ($canContacts ? '<a href="' . e($app->url(Kaleta\Core\Contacts::adminPath((int) $d['contact_id']))) . '">' . bdi($customer) . '</a>' : bdi($customer)) : '' ?><?= $customer !== '' && ($d['company_name'] ?? null) !== null ? ' · ' : '' ?><?= ($d['company_name'] ?? null) !== null ? bdi((string) $d['company_name']) : '' ?></p>
<?php endif ?>
<?php if ($d['value'] !== null || $d['expected_close'] !== null || $initials !== ''): ?>
	<div class="obchod-udaje">
<?php if ($d['value'] !== null): ?>
		<strong class="obchod-hodnota"><?= e(Deals::formatValue($d['value'], $d['currency'])) ?></strong>
<?php endif ?>
<?php if ($d['expected_close'] !== null): ?>
		<span class="obchod-termin"><?= e(t('close %s', format_date($d['expected_close']))) ?><?= $overdue ? ' <span class="stitek stitek-chyba">' . e(t('overdue')) . '</span>' : '' ?></span>
<?php endif ?>
<?php if ($initials !== ''): ?>
		<span class="obchod-osoba" title="<?= e($owner) ?>"><span aria-hidden="true"><?= e($initials) ?></span><span class="navod-skryte"><?= e(t('Responsible person: %s', $owner)) ?></span></span>
<?php endif ?>
	</div>
<?php endif ?>
<?php if ($d['closed_at'] === null && $since !== null): $days = Deals::daysSince($since, $today);
    $daysText = $days === 0 ? t('In this stage since today') : t('Days in this stage: %d', $days);
    $nextText = $next !== null ? t('Next: %s', $next['title'] !== '' ? $next['title'] : t('(no title)')) . ($next['due_at'] !== null ? ' · ' . format_date(substr((string) $next['due_at'], 0, 10)) : '') : t('No next step'); ?>
	<p class="obchod-krok" title="<?= e($daysText . ' · ' . $nextText) ?>"><span data-deal-days><?= e($days === 0 ? t('In this stage since today') : t('Days in this stage: %d', $days)) ?></span> · <?php if ($next !== null): ?><span class="obchod-dalsi"><?= e(t('Next: %s', $next['title'] !== '' ? $next['title'] : t('(no title)'))) ?><?= $next['due_at'] !== null ? ' · ' . e(format_date(substr((string) $next['due_at'], 0, 10))) : '' ?><?= $next['due_at'] !== null && substr((string) $next['due_at'], 0, 10) < $today ? ' <span class="stitek stitek-chyba">' . e(t('overdue')) . '</span>' : '' ?></span><?php else: ?><span class="obchod-bez-kroku"><?= e(t('No next step')) ?></span><?php endif ?></p>
<?php endif ?>
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
