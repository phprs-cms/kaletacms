<?php
/**
 * Site audit: findings by kind, each with a link to fix it, and the hand-over checklist (3.23, C17) with its progress.
 *
 * @var Kaleta\Admin\Modules\Audit $module
 * @var array<string, list<array<string, mixed>>> $groups
 * @var int $total
 * @var array{done: int, total: int, items: list<array{key: string, label: string, done: bool, edit: string, for_client: bool, findings: list<array{message: string, edit: string, target: array<string, int|string>}>}>} $checklist  Core\Audit::handoverChecklist
 * @var array<string, mixed>|null $move  Core\MoveStatus::summary (3.33, M7)
 */
use Kaleta\Core\Audit;

?>
<p class="hlaska<?= $total === 0 ? ' hlaska-ok' : '' ?>"><?= e($total === 0 ? t('No problems found – links lead where they should, pages have descriptions and the builder checks pass.') : t('Found %d things to fix. Each one links to where you fix it; Claude can go through them too – ask it to audit the site.', $total)) ?></p>
<?php
$table = function (array $rows): string {
    $html = '<div class="tab-obal"><table class="vypis"><thead><tr><th scope="col">' . e(t('Where')) . '</th><th scope="col">' . e(t('What')) . '</th><th scope="col">' . e(t('Actions')) . '</th></tr></thead><tbody>';
    foreach ($rows as $f) {
        $html .= '<tr><td>' . e($f['where']) . '</td><td>' . e($f['message']) . '</td><td class="akce"><a href="' . e($f['edit']) . '">' . e(t('Fix')) . '</a>'
            . ($f['url'] !== '' ? ' · <a href="' . e($f['url']) . '" target="_blank" rel="noopener">' . e(t('Show on the site')) . '</a>' : '') . '</td></tr>';
    }

    return $html . '</tbody></table></div>';
};
?>
<?php foreach (Audit::KINDS as $kind => $name): if ($kind === 'handover' || ($groups[$kind] ?? []) === []) { continue; } ?>
<h2><?= e(t($name)) ?> <span class="stitek"><?= count($groups[$kind]) ?></span></h2>
<?= $table($groups[$kind]) ?>
<?php endforeach ?>
<?php if (!empty($move)): // 3.33 (M7): where the move stands – counts and links only, from the importers and the newest migration report ?>
<section class="predani" id="move" aria-labelledby="move-nadpis">
<h2 id="move-nadpis"><?= e(t('Move to Kaleta')) ?> <span class="stitek"><?= e(t('%d of %d done', count(array_filter($move['rows'], fn (array $r): bool => $r['done'])), count($move['rows']))) ?></span></h2>
<p class="smltxt"><?= e(t('Where the move of the old site stands. Point the domain here once every row is done.')) ?></p>
<ul class="kontrola-seznam predani-seznam">
<?php foreach ($move['rows'] as $row): ?>
	<li class="<?= $row['done'] ? 'ok' : 'varovani' ?>" data-move-radek="<?= e($row['key']) ?>"><span aria-hidden="true"><?= $row['done'] ? '✓' : '!' ?></span>
		<a href="<?= e($app->url($row['edit'])) ?>"><?= e($row['label']) ?></a> <span class="navod-skryte"><?= e($row['done'] ? t('(done)') : t('(not done yet)')) ?></span></li>
<?php endforeach ?>
</ul>
</section>
<?php endif ?>
<?php /* 3.23 (C17): "Before handing over" and "Building this for a client?" are one checklist – what is done stays in it, ticked */ ?>
<section class="predani" aria-labelledby="predani-nadpis">
<h2 id="predani-nadpis"><?= e(t(Audit::KINDS['handover'])) ?> <span class="stitek"><?= e(t('%d of %d done', $checklist['done'], $checklist['total'])) ?></span></h2>
<progress class="predani-postup" max="<?= $checklist['total'] ?>" value="<?= $checklist['done'] ?>" aria-label="<?= e(t('%d of %d done', $checklist['done'], $checklist['total'])) ?>"></progress>
<p class="smltxt"><?= e(t('What to check before a client takes the site over. Each row links to where it is set; rows marked “for a client” matter only when you build the site for someone else.')) ?></p>
<ul class="kontrola-seznam predani-seznam">
<?php foreach ($checklist['items'] as $item): ?>
	<li class="<?= $item['done'] ? 'ok' : 'varovani' ?>" data-predani-radek="<?= e($item['key']) ?>"><span aria-hidden="true"><?= $item['done'] ? '✓' : '!' ?></span>
		<a href="<?= e($item['edit']) ?>"><?= e($item['label']) ?></a> <span class="navod-skryte"><?= e($item['done'] ? t('(done)') : t('(not done yet)')) ?></span><?php if ($item['for_client']): ?> <span class="stitek"><?= e(t('for a client')) ?></span><?php endif ?>
<?php foreach ($item['findings'] as $f): ?>
		<br><span class="smltxt"><?= e($f['message']) ?> <a href="<?= e($f['edit']) ?>"><?= e(t('Fix')) ?></a></span>
<?php endforeach ?>
	</li>
<?php endforeach ?>
</ul>
</section>
<p class="smltxt"><?= e(t('External links are checked in the background, one news item every five minutes. The audit runs each time you open this page.')) ?></p>
