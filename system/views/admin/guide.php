<?php
/**
 * The guide to the office part (3.45, Admin\GuidePage, Admin\Guide::CHAPTERS): without $chapter the index – a card per
 * chapter of the sections the person may open; with $chapter one chapter – what it is for, the steps, good to know, and the
 * links on: the section itself (only for whoever may open it), the online article (when there is one) and every chapter.
 *
 * @var Kaleta\Core\App $app
 * @var list<string> $chapters the chapters of the sections the person may open
 * @var ?string $chapter the open chapter, null = the index
 * @var array<string, class-string<Kaleta\Admin\Module>> $modules the sections the person may open
 */
$guide = Kaleta\Admin\Guide::CHAPTERS;
?>
<?php if ($chapter === null): ?>
<div class="navod-kapitola">
<p class="navod-uvod"><?= e(t('Short chapters about the office part of Kaleta – customers, deals, quotes, job applications and the team. Each section also opens its own chapter with “How to use this” next to its heading.')) ?></p>
</div>
<?php if ($chapters === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'moje', 'heading' => t('No chapter for your sections yet.'), 'text' => t('The chapters follow the sections you may open. Ask an administrator for access.')]) ?>
<?php else: ?>
<ul class="navod-kapitoly">
<?php foreach ($chapters as $key): ?>
	<li><a href="<?= e($app->url('admin.php?action=guide&chapter=' . $key)) ?>"><strong><?= e(t($guide[$key]['title'])) ?></strong><span><?= e(t($guide[$key]['summary'])) ?></span></a></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php else:
    $c = $guide[$chapter];
    $section = array_values(array_filter($c['modules'], fn (string $m): bool => isset($modules[$m])))[0] ?? null;
    // 3.50.1: a chapter of tabs links to its screen – My account for everybody, Settings for whoever may open it
    $screen = match (true) {
        $section !== null => null,
        isset($c['tabs']['account']) => ['admin.php?action=account', t('My account')],
        isset($c['tabs']['settings'], $modules['settings']) => ['admin.php?module=settings&tab=' . $c['tabs']['settings'][0], t($modules['settings']::NAME)],
        default => null,
    };
?>
<article class="navod-kapitola">
<p class="navod-uvod"><?= e(t($c['summary'])) ?></p>
<h2><?= e(t('Step by step')) ?></h2>
<ol>
<?php foreach ($c['steps'] as $step): ?>
	<li><?= e(t($step)) ?></li>
<?php endforeach ?>
</ol>
<h2><?= e(t('Good to know')) ?></h2>
<ul>
<?php foreach ($c['tips'] as $tip): ?>
	<li><?= e(t($tip)) ?></li>
<?php endforeach ?>
</ul>
<p class="navod-dalsi">
<?php if ($section !== null): ?>
	<a class="tl" href="<?= e($app->url('admin.php?module=' . $section)) ?>"><?= e(t('Open %s', t($modules[$section]::NAME))) ?></a>
<?php elseif ($screen !== null): ?>
	<a class="tl" href="<?= e($app->url($screen[0])) ?>"><?= e(t('Open %s', $screen[1])) ?></a>
<?php endif ?>
<?php if ($c['online'] !== null): ?>
	<a href="<?= e(Kaleta\Admin\Guide::url($c['online'], Kaleta\Core\Language::code())) ?>" target="_blank" rel="noopener"><?= e(t('More in the online guide')) ?><span class="navod-skryte"> – <?= e(t('opens in a new tab')) ?></span></a>
<?php endif ?>
	<a href="<?= e($app->url('admin.php?action=guide')) ?>"><?= e(t('All chapters of the guide')) ?></a>
</p>
</article>
<?php endif ?>
