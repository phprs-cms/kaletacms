<?php
/**
 * The trends of a fleet site (3.17, Fleet\History::trend): uptime and response this month and last month, the last 90 days
 * as bars (Core\UptimeStrip since 3.26.1 – the same strip as in the site's monthly report – with a summary for screen
 * readers and the same days as a table), the outages and the versions.
 *
 * @var array<string, mixed> $trend
 */
use Kaleta\Core\UptimeStrip;
use Kaleta\Fleet\History;

$percent = fn (?float $p): string => $p === null ? t('not measured') : format_number($p, 2) . ' %';
$uptimeLine = fn (array $u): string => $percent($u['percent']) . ($u['checks'] > 0 ? ' · ' . t('%d checks, %d failed', $u['checks'], $u['failed']) : '')
    . ($u['response_ms'] !== null ? ' · ' . t('average response %d ms', $u['response_ms']) : '');
$bars = $trend['bars'];
$measured = array_values(array_filter($bars, fn (array $b): bool => $b['percent'] !== null));
$lowest = $measured === [] ? null : array_reduce($measured, fn (?array $low, array $b): array => $low === null || $b['percent'] < $low['percent'] ? $b : $low);
$full = count(array_filter($measured, fn (array $b): bool => $b['percent'] >= 100.0));
$summary = $measured === [] ? t('Uptime in the last %d days: not measured yet.', History::TREND_DAYS)
    : t('Uptime in the last %d days: %d days measured, %d of them without an outage; the lowest %s on %s.', History::TREND_DAYS, count($measured), $full, $percent($lowest['percent']), format_date($lowest['day']));
$duration = fn (int $minutes): string => $minutes >= 120 ? t('%s hours', format_number($minutes / 60, 1)) : t('%d minutes', $minutes);
?>
<h2><?= e(t('Trends')) ?></h2>
<?php if (!$trend['recorded']): ?>
<p class="smltxt"><?= e(t('The console keeps the history of each site from Kaleta 3.17 on – the trends appear after its first checks.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis"><tbody>
	<tr><th scope="row"><?= e(t('Uptime this month')) ?></th><td><?= e($uptimeLine($trend['this_month'])) ?></td></tr>
	<tr><th scope="row"><?= e(t('Uptime last month')) ?></th><td><?= e($uptimeLine($trend['last_month'])) ?></td></tr>
</tbody></table></div>
<figure class="trend">
<?= UptimeStrip::html(array_map(fn (array $b): array => UptimeStrip::fromPercent($b['day'], $b['percent']), $bars), ['label' => $summary, 'height' => 40, 'color' => 'inherit']) ?>
<figcaption><?= e($summary) ?></figcaption>
</figure>
<?php if ($measured !== []): ?>
<details class="trend-tabulka"><summary><?= e(t('The days as a table')) ?></summary>
<div class="tab-obal"><table class="vypis"><thead><tr><th scope="col"><?= e(t('Day')) ?></th><th scope="col" class="cislo"><?= e(t('Uptime')) ?></th><th scope="col" class="cislo"><?= e(t('Average response')) ?></th></tr></thead><tbody>
<?php foreach (array_reverse($measured) as $b): ?><tr><td><?= e(format_date($b['day'])) ?></td><td class="cislo"><?= e($percent($b['percent'])) ?></td><td class="cislo"><?= $b['response_ms'] !== null ? e(t('%d ms', $b['response_ms'])) : '–' ?></td></tr>
<?php endforeach ?></tbody></table></div>
</details>
<?php endif ?>
<?php endif ?>
<?php if ($trend['incidents'] !== []): ?>
<h3><?= e(t('Outages in the last %d days', History::TREND_DAYS)) ?></h3>
<div class="tab-obal"><table class="vypis"><thead><tr><th scope="col"><?= e(t('Start')) ?></th><th scope="col"><?= e(t('End')) ?></th><th scope="col" class="cislo"><?= e(t('Duration')) ?></th></tr></thead><tbody>
<?php foreach ($trend['incidents'] as $incident): ?><tr><td><?= e(format_date($incident['started_at'], true)) ?><?= $incident['status'] > 0 ? ' · HTTP ' . (int) $incident['status'] : '' ?></td><td><?= $incident['ended_at'] !== null ? e(format_date($incident['ended_at'], true)) : '<span class="stitek stitek-chyba">' . e(t('still down')) . '</span>' ?></td><td class="cislo"><?= e($duration($incident['minutes'])) ?></td></tr>
<?php endforeach ?></tbody></table></div>
<?php endif ?>
<?php if ($trend['versions'] !== []): ?>
<h3><?= e(t('Versions in the last %d days', History::TREND_DAYS)) ?></h3>
<ul class="smltxt"><?php foreach ($trend['versions'] as $v): ?><li><?= e(format_date($v['date'], true)) ?> · <?= e($v['version']) ?></li><?php endforeach ?></ul>
<?php endif ?>
