<?php
/**
 * The admin start screen.
 * Site overview: first steps, warnings, counts, traffic, new enquiries and recently edited content.
 *
 * @var Kaleta\Core\App $app
 * @var array<string, class-string<Kaleta\Admin\Module>> $modules
 * @var array<string, array{0: int, 1: string}> $counts  label => [count, url]
 * @var list<array{0: string, 1: string, 2?: string, 3?: string, 4?: string}> $warnings  [text, url, link text, action that dismisses it, its button]
 * @var list<array<string, mixed>> $enquiries
 * @var list<array{druh: string, titulek: string, kdy: string, url: string, stav: string}> $edited
 * @var array<string, mixed>|null $ask "Ask Claude" (3.1), null without the Requests section or the Claude connection
 * @var array{url: string, admin: bool}|null $connect "Connect Claude" (3.5) until a Claude connection has ever called the site
 * @var list<array{kind: string, label: string, count: int, url: string, examples: list<string>}> $pending "Waiting for you" (3.2, Core\PendingReview)
 */
?>
<div class="prehled-hlavicka">
	<h1><?= e(t('Dashboard')) ?></h1>
	<p class="navigace-radek">
<?php if (isset($modules['pages'])): ?>
		<a class="tl" href="<?= e($app->url('admin.php?module=pages&action=new')) ?>"><?= e(t('New page')) ?></a>
<?php endif ?>
<?php if (isset($modules['news'])): ?>
		<a class="navigace" href="<?= e($app->url('admin.php?module=news&action=new')) ?>"><?= e(t('Write a news item')) ?></a>
<?php endif ?>
		<a class="navigace" href="<?= e($app->url('')) ?>" target="_blank" rel="noopener"><?= e(t('Zobrazit web')) ?></a>
		<?= $app->view->render('admin/guide_link', ['url' => Kaleta\Admin\Guide::forScreen('', '', '', Kaleta\Core\Language::code())]) ?>
	</p>
</div>
<?php foreach ($warnings as $w): [$text, $url] = $w; ?>
<div class="hlaska hlaska-varovani hlaska-akce"><p><?= e($text) ?></p>
	<p class="navigace-radek"><a class="navigace" href="<?= e($url) ?>"><?= e($w[2] ?? t('Fix')) ?></a><?php if (isset($w[3])): ?>
		<form class="vradku" method="post" action="<?= e($w[3]) ?>"><?= $app->session->csrfField() ?><input type="hidden" name="zpet" value="prehled"><button class="navigace" type="submit"><?= e($w[4] ?? t('Ignore all')) ?></button></form><?php endif ?></p>
</div>
<?php endforeach ?>
<?php if ($connect !== null): // until Claude is connected: Connect Claude, then First steps, Ask Claude below (3.5) ?>
<?= $app->view->render('admin/claude_connect', ['app' => $app, 'connect' => $connect]) ?>
<?php elseif ($ask !== null): ?>
<?= $app->view->render('admin/ask_claude', ['app' => $app, 'ask' => $ask]) ?>
<?php endif ?>
<?php if (!empty($firstSteps)): $finished = count(array_filter($firstSteps, fn (array $k): bool => $k['hotovo'])); ?>
<section class="pruvodce" aria-label="<?= e(t('First steps')) ?>">
	<div class="pruvodce-hlava">
		<h2><?= e(t('First steps')) ?> <small><?= $finished ?> / <?= count($firstSteps) ?></small></h2>
		<form method="post" action="<?= e($app->url('admin.php?action=hide_first_steps')) ?>"><?= $app->session->csrfField() ?><button class="navigace" type="submit"><?= e(t('Hide')) ?></button></form>
	</div>
	<ol class="pruvodce-kroky">
<?php foreach ($firstSteps as $k): ?>
		<li class="<?= $k['hotovo'] ? 'hotovo' : '' ?>"><a href="<?= e($k['url']) ?>"><strong><?= e(t($k['nazev'])) ?></strong><span><?= e(t($k['popis'])) ?></span></a></li>
<?php endforeach ?>
	</ol>
</section>
<?php endif ?>
<?php if ($connect !== null && $ask !== null): ?>
<?= $app->view->render('admin/ask_claude', ['app' => $app, 'ask' => $ask]) ?>
<?php endif ?>
<?php if ($pending !== []): ?>
<?= $app->view->render('admin/pending_review', ['app' => $app, 'pending' => $pending]) ?>
<?php endif ?>
<div class="dlazdice">
<?php foreach ($counts as $description => [$count, $url]): ?>
	<a class="dlazdice-polozka" href="<?= e($app->url($url)) ?>"><strong><?= format_count($count) ?></strong><span><?= e(t($description)) ?></span></a>
<?php endforeach ?>
</div>
<?php if (count($traffic) >= 2):
    // bar chart in plain SVG: one bar per day, height by visits
    $days = [];
    for ($i = 13; $i >= 0; $i--) { $days[date('Y-m-d', strtotime("-{$i} day"))] = 0; }
    foreach ($traffic as $n) { $days[$n['den']] = (int) $n['navstevy']; }
    $max = max(1, ...array_values($days));
?>
<section class="prehled-graf" aria-label="<?= e(t('Visits in the last 14 days')) ?>">
	<h2><?= e(t('Visits in the last 14 days')) ?> <small><?= e(t('%s visits', format_count(array_sum($days)))) ?></small></h2>
	<svg viewBox="0 0 280 70" preserveAspectRatio="none" role="img" aria-label="<?= e(t('Visits in the last 14 days')) ?>">
<?php $x = 0; foreach ($days as $day => $count): $v = max(1, (int) round($count / $max * 62)); ?>
		<rect x="<?= $x * 20 + 2 ?>" y="<?= 66 - $v ?>" width="16" height="<?= $v ?>" rx="2" data-tip="<?= e(t('%s: %s visits', format_date($day), $count)) ?>" aria-label="<?= e(t('%s: %s visits', format_date($day), $count)) ?>" tabindex="0"></rect>
<?php $x++; endforeach ?>
	</svg>
	<p class="smltxt"><a href="<?= e($app->url('admin.php?module=stats')) ?>"><?= e(t('Full statistics')) ?></a></p>
</section>
<?php endif ?>
<?php if ($enquiries !== []): ?>
<h2><?= e(t('Latest enquiries')) ?></h2>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Form')) ?></th><th scope="col"><?= e(t('Email')) ?></th><th scope="col"><?= e(t('Received')) ?></th></tr></thead>
<tbody>
<?php foreach ($enquiries as $p): ?>
<tr<?= (int) $p['stav'] === 0 ? '' : ' class="nevydany"' ?>>
	<td><a href="<?= e($app->url('admin.php?module=enquiries&action=detail&id=' . (int) $p['idp'])) ?>"><?= e($p['formular'] !== '' ? $p['formular'] : t('Enquiry')) ?></a><?= (int) $p['stav'] === 0 ? ' <span class="stitek stitek-koncept">' . e(t('new')) . '</span>' : '' ?></td>
	<td><?= e($p['email']) ?></td>
	<td class="cislo"><?= e(format_date($p['datum'], true)) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<?php if ($edited !== []): ?>
<h2><?= e(t('Recently edited')) ?></h2>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Název')) ?></th><th scope="col"><?= e(t('Druh')) ?></th><th scope="col"><?= e(t('Edited')) ?></th></tr></thead>
<tbody>
<?php foreach ($edited as $u): ?>
<tr>
	<td><a href="<?= e($u['url']) ?>"><?= e($u['titulek']) ?></a><?= $u['stav'] !== '' ? ' <span class="stitek stitek-koncept">' . e($u['stav']) . '</span>' : '' ?></td>
	<td><?= e($u['druh']) ?></td>
	<td class="cislo"><?= e(format_date($u['kdy'], true)) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
