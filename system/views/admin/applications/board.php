<?php
/**
 * The board of one position (3.40, design B15): a column per status with its count – hired and rejected hold the ones
 * decided in the last 30 days. A light card (3.37): the name in at most two lines, one line of received · waiting · the
 * responsible person's initials (the name for screen readers and on hover), and its own form – the status, the reason of a
 * rejection (shown by CSS :has() when Rejected is chosen) and Move – so it works with the keyboard, on a phone and without
 * a script. On a phone the columns stack (the board styles of the Pipeline). 3.47 (U65): image/admin.js adds the drag and the
 * keyboard of the Pipeline (boardBehaviour, the status posted as JSON); "All positions" shows every position, each card
 * naming its own. 3.47 review: the count keeps the cards left out in data-board-more, the chips bound to a status carry
 * data-board-waiting and data-board-reason, so the script can drop them when the card moves.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Applications $module
 * @var string $csrf
 * @var list<array{job_item_id: ?int, job_title: string, open: int, all: int}> $jobs
 * @var string $job the position shown: an item id, 'none' (no position), 'all' (3.47) or '' (no application yet)
 * @var string $title the position's title
 * @var array{columns: array<string, list<array<string, mixed>>>, more: array<string, int>} $board Core\Applications::board
 * @var array<int, string> $names the responsible people of the cards
 * @var bool $admin
 */
use Kaleta\Core\Applications as Store;

$label = fn (string $status): string => t(Store::STATUS_LABELS[$status] ?? $status);
?>
<?= $app->view->render('admin/applications/tabs', ['module' => $module, 'current' => 'board', 'admin' => $admin]) ?>
<?php if ($jobs === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'uzivatele', 'heading' => t('No applications here.'),
    'text' => t('Applications come from the forms on your job pages and from forms whose Purpose is “Job application”. They never become customers or deals.')]) ?>
<?php else: ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr" data-applications-board-job>
	<input type="hidden" name="module" value="<?= e(Store::SECTION) ?>"><input type="hidden" name="action" value="board">
	<label><span><?= e(t('Position')) ?></span> <select name="job">
		<option value="all"<?= $job === 'all' ? ' selected' : '' ?>><?= e(t('All positions')) ?></option>
<?php foreach ($jobs as $j): $value = $j['job_item_id'] !== null ? (string) $j['job_item_id'] : 'none'; ?>
		<option value="<?= e($value) ?>"<?= $job === $value ? ' selected' : '' ?>><?= e($j['job_item_id'] !== null ? ($j['job_title'] !== '' ? $j['job_title'] : '#' . $j['job_item_id']) : t('No position')) ?> (<?= format_count($j['open']) ?>/<?= format_count($j['all']) ?>)</option>
<?php endforeach ?>
	</select></label>
	<button class="navigace" type="submit"><?= e(t('Show')) ?></button>
	<a class="navigace" href="<?= e($module->url('', ['status' => 'open'] + ($job !== 'all' ? ['job' => $job] : []))) ?>"><?= e(t('Open the list')) ?></a>
</form>
<h2 class="navod-skryte"><?= bdi($title) ?></h2>
<details class="obchody-navod"><summary><?= e(t('How to move an application')) ?></summary><p class="napoveda" id="uchazeci-navod"><?= e(t('Drag a card to another column, or choose its status and press Move – with a mouse they are behind ⋯ on the card. With the keyboard: focus the handle of a card, press Space, choose the column with the arrow keys and press Space again; Esc cancels. Rejected asks for the reason. Hired and Rejected show the applications decided in the last %d days; the list has all of them.', Store::BOARD_DECIDED_DAYS)) ?></p></details>
<div class="obchody-tabule" data-applications-board>
<?php foreach (Store::STATUSES as $status): $cards = $board['columns'][$status]; ?>
	<section class="obchody-sloupec<?= $status === 'hired' ? ' obchody-won' : ($status === 'rejected' ? ' obchody-lost' : '') ?>" data-board-status="<?= e($status) ?>"<?= $status === 'rejected' ? ' data-board-needs-reason' : '' ?> aria-labelledby="stav-<?= e($status) ?>">
		<header class="obchody-hlava">
			<h2 id="stav-<?= e($status) ?>"><span data-board-label><?= e($label($status)) ?></span> <span class="obchody-pocet" data-board-count<?= $board['more'][$status] > 0 ? ' data-board-more="' . (int) $board['more'][$status] . '"' : '' ?>><?= format_count(count($cards) + $board['more'][$status]) ?></span></h2>
<?php if (in_array($status, Store::DECIDED, true)): ?>
			<p class="obchody-soucty"><?= e(t('decided in the last %d days', Store::BOARD_DECIDED_DAYS)) ?></p>
<?php endif ?>
		</header>
		<ol class="obchody-karty" data-board-cards>
<?php foreach ($cards as $c): $id = (int) $c['id']; $owner = $c['assignee_id'] !== null ? (string) ($names[$c['assignee_id']] ?? '') : '';
    $initials = mb_strtoupper(implode('', array_map(fn (string $word): string => mb_substr($word, 0, 1), array_slice(preg_split('/\s+/u', trim($owner), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 2))));
    $who = (string) $c['name'] !== '' ? (string) $c['name'] : ((string) $c['email'] !== '' ? (string) $c['email'] : t('Application #%s', (string) $id)); ?>
			<li class="obchod-karta<?= $c['waiting'] ? ' obchod-po-terminu' : '' ?>" id="application-<?= $id ?>" data-board-card="<?= $id ?>">
				<h3 class="obchod-titulek"><a href="<?= e($module->url('detail', ['id' => $id])) ?>"><?= bdi($who) ?></a></h3>
<?php if ($job === 'all'): /* 3.47 (U65): on "All positions" each card names its position */ ?>
				<p class="obchod-kdo" data-board-position><?= (string) $c['job_title'] !== '' ? bdi((string) $c['job_title']) : e(t('No position')) ?></p>
<?php endif ?>
				<div class="obchod-udaje">
					<span>#<?= $id ?> · <?= e(format_date((string) $c['received_at'])) ?></span>
<?php if ($c['waiting']): ?>
					<span class="stitek stitek-chyba" data-board-waiting><?= e(t('waiting')) ?></span>
<?php endif ?>
<?php if ($c['pool_until'] !== null): ?>
					<span class="stitek" title="<?= e(t('Kept for future openings')) ?>"><?= e(t('pool')) ?></span>
<?php endif ?>
<?php if ($c['reject_reason'] !== null): ?>
					<span data-board-reason><?= e(t(Store::REJECT_REASONS[$c['reject_reason']] ?? (string) $c['reject_reason'])) ?></span>
<?php endif ?>
<?php if ($initials !== ''): ?>
					<span class="obchod-osoba" title="<?= e($owner) ?>"><span aria-hidden="true"><?= e($initials) ?></span><span class="navod-skryte"><?= e(t('Responsible person: %s', $owner)) ?></span></span>
<?php endif ?>
				</div>
				<form class="obchod-presun" method="post" action="<?= e($module->url('status')) ?>" data-board-move>
					<?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="return" value="board"><input type="hidden" name="job" value="<?= e($job) ?>">
					<label class="navod-skryte" for="stav-<?= $id ?>"><?= e(t('Status of application #%s', (string) $id)) ?></label>
					<select id="stav-<?= $id ?>" name="status" aria-describedby="uchazeci-navod">
<?php foreach (Store::STATUSES as $option): ?>
						<option value="<?= e($option) ?>"<?= $option === $status ? ' selected data-current' : '' ?>><?= e($label($option)) ?></option>
<?php endforeach ?>
					</select>
					<span class="uchazec-duvod">
						<label class="navod-skryte" for="duvod-<?= $id ?>"><?= e(t('Reason when rejected')) ?></label>
						<select id="duvod-<?= $id ?>" name="reject_reason">
<?php foreach (Store::REJECT_REASONS as $key => $reason): ?>
							<option value="<?= e($key) ?>"<?= $key === $c['reject_reason'] ? ' selected' : '' ?>><?= e(t($reason)) ?></option>
<?php endforeach ?>
						</select>
					</span>
					<button class="navigace" type="submit"><?= e(t('Move')) ?></button>
				</form>
			</li>
<?php endforeach ?>
		</ol>
		<p class="obchody-prazdne napoveda" data-board-empty<?= $cards !== [] ? ' hidden' : '' ?>><?= e(t('No application here.')) ?></p>
<?php if ($board['more'][$status] > 0): ?>
		<p class="obchody-prazdne napoveda"><a href="<?= e($module->url('', ['status' => $status] + ($job !== 'all' ? ['job' => $job] : []))) ?>"><?= e(t('%d more in the list', $board['more'][$status])) ?></a></p>
<?php endif ?>
	</section>
<?php endforeach ?>
</div>
<p class="navod-skryte" aria-live="polite" data-board-announce></p>
<?php /* 3.48 B (U30): the visible message line and the reason a drop on Rejected asks for (image/admin.js) – without script the card's own form does it */ ?>
<p class="napoveda tabule-zprava" data-board-message hidden aria-hidden="true"></p>
<div class="tabule-duvod" id="uchazeci-duvod" popover data-board-reason-pop aria-labelledby="uchazeci-duvod-nadpis">
	<p id="uchazeci-duvod-nadpis" data-board-pop-title><?= e(t('Reason when rejected')) ?></p>
	<label for="uchazeci-duvod-vyber"><?= e(t('Reason when rejected')) ?></label>
	<select id="uchazeci-duvod-vyber">
<?php foreach (Store::REJECT_REASONS as $key => $reason): ?>
		<option value="<?= e($key) ?>"><?= e(t($reason)) ?></option>
<?php endforeach ?>
	</select>
	<p class="tlacitka"><button class="tl" type="button" data-board-pop-move><?= e(t('Move')) ?></button> <button class="navigace" type="button" popovertarget="uchazeci-duvod" popovertargetaction="hide"><?= e(t('Cancel')) ?></button></p>
</div>
<?php endif ?>
