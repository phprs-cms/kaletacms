<?php
/**
 * The Summary tab of My work (3.41, step 7, Core\TeamSummary): how much of the team's work happened in the chosen period –
 * counts only, for the sections the person may open. An administrator gets a row per person (by name) and the team's
 * total; everyone else their own row and the team's total. Open enquiries and open and overdue tasks are counted as they
 * are now, the rest within the period. On a phone every row is a card (table.vypis-karty).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\MyWork $module
 * @var array{period: string, from: string, to: string, sections: list<string>, team: array<string, int>, you: array<string, int>, people: list<array{id: int, name: string, counts: array<string, int>}>|null} $summary
 * @var bool $team the Team tab and the per-person rows (administrators)
 */
use Kaleta\Core\TeamSummary;

$counts = array_keys($summary['team']);
$cell = fn (string $count, int $n): string => '<td class="cislo" data-count="' . e($count) . '">' . ($count === 'tasks_overdue' && $n > 0 ? '<span class="stitek stitek-koncept">' . format_count($n) . '</span>' : format_count($n)) . '</td>';
$to = substr($summary['to'], 11) === '00:00:00' ? (new DateTimeImmutable($summary['to']))->modify('-1 day')->format('Y-m-d') : substr($summary['to'], 0, 10);
?>
<?= $app->view->render('admin/my_work/tabs', ['module' => $module, 'current' => 'summary', 'team' => $team]) ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="zakaznici-filtr" data-team-summary-period>
	<input type="hidden" name="module" value="my_work"><input type="hidden" name="action" value="summary">
	<label><span><?= e(t('Period')) ?></span> <select name="period">
<?php foreach (TeamSummary::PERIODS as $key => $label): ?>
		<option value="<?= e($key) ?>"<?= $key === $summary['period'] ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select></label>
	<button class="navigace" type="submit"><?= e(t('Show')) ?></button>
</form>
<p class="napoveda"><?= e(t('%s – %s. Counts only – never hours or money. Open enquiries and open and overdue tasks are counted as they are now, everything else within the period.', format_date(substr($summary['from'], 0, 10)), format_date($to))) ?>
<?= e($team ? t('Only administrators see the counts of each person; the monthly report shows the team’s totals without names.') : t('You see your own counts and the team’s totals.')) ?></p>
<section class="moje-prace" data-list="summary" aria-labelledby="moje-souhrn">
<h2 id="moje-souhrn"><?= e(t('Team summary')) ?></h2>
<div class="tab-obal">
<table class="vypis vypis-karty" data-team-summary>
<thead><tr><th scope="col"><?= e(t('Person')) ?></th>
<?php foreach ($counts as $count): ?>
	<th scope="col" class="cislo"><?= e(t(TeamSummary::LABELS[$count])) ?></th>
<?php endforeach ?>
</tr></thead>
<tbody>
<?php if ($summary['people'] !== null): ?>
<?php if ($summary['people'] === []): ?>
<tr><td colspan="<?= count($counts) + 1 ?>"><?= e(t('Nothing counted in this period yet.')) ?></td></tr>
<?php endif ?>
<?php foreach ($summary['people'] as $p): ?>
<tr data-person="<?= (int) $p['id'] ?>">
	<th scope="row"><?= e($p['name']) ?></th>
<?php foreach ($counts as $count): ?>
	<?= $cell($count, $p['counts'][$count] ?? 0) ?>
<?php endforeach ?>
</tr>
<?php endforeach ?>
<?php else: ?>
<tr data-person="you">
	<th scope="row"><?= e(t('You')) ?></th>
<?php foreach ($counts as $count): ?>
	<?= $cell($count, $summary['you'][$count] ?? 0) ?>
<?php endforeach ?>
</tr>
<?php endif ?>
<tr data-person="team" class="souhrn-celkem">
	<th scope="row"><?= e(t('The whole team')) ?></th>
<?php foreach ($counts as $count): ?>
	<?= $cell($count, $summary['team'][$count]) ?>
<?php endforeach ?>
</tr>
</tbody>
</table>
</div>
</section>
