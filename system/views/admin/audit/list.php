<?php
/**
 * Site audit: findings by kind, each with a link to fix it.
 *
 * @var Kaleta\Admin\Modules\Audit $module
 * @var array<string, list<array<string, mixed>>> $groups
 * @var int $total
 * @var string|null $notebookUrl  the Notebook (3.20, C22), null for whoever may not open it
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
<?php foreach (Audit::KINDS as $kind => $name): if (($groups[$kind] ?? []) === []) { continue; }
    $forClient = array_values(array_filter($groups[$kind], fn (array $f): bool => !empty($f['for_client'])));
    $rows = array_values(array_filter($groups[$kind], fn (array $f): bool => empty($f['for_client']))); ?>
<h2><?= e(t($name)) ?> <span class="stitek"><?= count($rows) ?></span></h2>
<?php if ($rows !== []): ?>
<?= $table($rows) ?>
<?php endif ?>
<?php if ($forClient !== []): // 3.11 (UX310-20): only a question for someone who builds the site for a client ?>
<details class="pokrocile"><summary><?= e(t('Building this for a client?')) ?> (<?= count($forClient) ?>)</summary>
<?= $table($forClient) ?>
</details>
<?php endif ?>
<?php endforeach ?>
<?php if ($notebookUrl !== null): // 3.20 (C22): handing over means notes too ?>
<p class="smltxt"><strong><?= e(t('Before handing over')) ?>:</strong> <?= e(t('write down the decisions for the next person – why the site is built the way it is, what the client asked for, what to watch.')) ?> <a href="<?= e($notebookUrl) ?>"><?= e(t('Open the Notebook')) ?></a></p>
<?php endif ?>
<p class="smltxt"><?= e(t('External links are checked in the background, one news item every five minutes. The audit runs each time you open this page.')) ?></p>
