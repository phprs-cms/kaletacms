<?php
/**
 * The deals of a customer or a company (3.31, Admin\Modules\Pipeline::deals) on their page: open ones first with the stage,
 * value and expected close (overdue marked), closed ones with how they ended (each row with its admin url); "New deal" for
 * them and the full list.
 *
 * @var array{deals: list<array<string, mixed>>, labels: array<string, string>, kinds: array<string, string>, today: string, newUrl: string, allUrl: string} $box
 */
use Kaleta\Core\Deals;
?>
<section class="zakaznik-box obchody-box" id="deals" data-deals-box>
<h2><?= e(t('Deals')) ?> <small><?= format_count(count($box['deals'])) ?></small></h2>
<?php if ($box['deals'] === []): ?>
<p class="napoveda"><?= e(t('No deals yet.')) ?></p>
<?php else: ?>
<ul class="obchody-box-seznam">
<?php foreach ($box['deals'] as $d): $kind = $box['kinds'][$d['stage']] ?? 'open';
    $overdue = $d['closed_at'] === null && $d['expected_close'] !== null && $d['expected_close'] < $box['today']; ?>
	<li data-deal="<?= (int) $d['id'] ?>"><a href="<?= e($d['url']) ?>"><?= bdi($d['title']) ?></a>
		<span class="stitek<?= $kind === 'won' ? ' stitek-vydano' : ($kind === 'lost' ? ' stitek-chyba' : '') ?>"><?= e($box['labels'][$d['stage']] ?? $d['stage']) ?></span>
		<?= $d['value'] !== null ? '<span class="napoveda">' . e(Deals::formatValue($d['value'], $d['currency'])) . '</span>' : '' ?>
		<?= $d['closed_at'] === null && $d['expected_close'] !== null ? '<span class="napoveda">' . e(t('close %s', format_date($d['expected_close']))) . '</span>' . ($overdue ? ' <span class="stitek stitek-chyba">' . e(t('overdue')) . '</span>' : '') : '' ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<p class="tlacitka"><a class="navigace" href="<?= e($box['newUrl']) ?>" data-deal-new-here><?= e(t('New deal')) ?></a><?php if ($box['deals'] !== []): ?> <a class="navigace" href="<?= e($box['allUrl']) ?>"><?= e(t('All their deals')) ?></a><?php endif ?></p>
</section>
