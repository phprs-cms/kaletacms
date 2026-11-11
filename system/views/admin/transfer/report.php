<?php
/**
 * The migration parity report (2.7): finding the old site's pages, checking them in batches (the form submits itself,
 * data-auto-odeslat in image/admin.js), then the result – problems first, then the checks of the whole site.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state  Core\MigrationReport
 * @var array{souhrn: array<string, int>, radky: list<array<string, mixed>>, web: list<array{zprava: string, uprava: string}>, poznamky: list<string>} $result
 */
use Kaleta\Core\MigrationReport;

$s = $result['souhrn'];
$severityClass = ['error' => 'stitek stitek-chyba', 'warning' => 'stitek stitek-koncept', 'info' => 'stitek'];
?>
<p><?= e(t('Old site: %s', $state['web'])) ?></p>
<?php foreach ($result['poznamky'] as $note): ?><p class="hlaska"><?= e($note) ?></p><?php endforeach ?>
<?php if ($state['faze'] !== 'hotovo'): ?>
<p class="hlaska" role="status"><?= e($state['faze'] === 'hledani' ? t('Finding the pages of the old site. Keep this page open, it continues by itself.')
    : t('Checking: %s of %s addresses. Keep this page open, it continues by itself.', count($state['radky']), count($state['adresy']))) ?></p>
<?php if ($state['faze'] === 'kontrola'): ?><progress class="prenos-prubeh" max="<?= max(1, count($state['adresy'])) ?>" value="<?= count($state['radky']) ?>"></progress><?php endif ?>
<form method="post" action="<?= e($module->url('report', ['id' => $state['id']])) ?>" data-auto-odeslat="400"><?= $csrf ?>
	<p><button class="tl" type="submit"><?= e(t('Continue')) ?></button></p></form>
<?php else: ?>
<?php if ($s['chyb'] === 0 && $s['varovani'] === 0 && $result['web'] === []): ?>
<p class="hlaska hlaska-ok"><?= e(t('Everything moved: every old address leads to a published page and nothing was lost.')) ?></p>
<?php elseif ($s['chyb'] > 0): ?>
<p class="hlaska hlaska-chyba"><?= e(t('Not ready to go live: %s problems would cost visitors or search engines something.', $s['chyb'])) ?></p>
<?php else: ?>
<p class="hlaska"><?= e(t('Nearly ready: check the warnings below.')) ?></p>
<?php endif ?>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= $s['adres'] ?></strong><span><?= e(t('Old addresses')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= $s['ok'] ?></strong><span><?= e(t('Same address')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= $s['presmerovano'] ?></strong><span><?= e(t('Redirected')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= $s['skryto'] ?></strong><span><?= e(t('Not published yet')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= $s['chybi'] ?></strong><span><?= e(t('Missing')) ?></span></div>
<?php if (($s['vzor'] ?? 0) > 0): // 3.33 (M5) ?>
	<div class="dlazdice-polozka"><strong><?= (int) $s['vzor'] ?></strong><span><?= e(t('On a sample page')) ?></span></div>
<?php endif ?>
</div>
<?php foreach ($result['formulare_webu'] ?? [] as $fields => $pages): // 3.33 (M5): reported once, not on every page ?>
<p class="hlaska"><?= e(t('Site-wide form: on %s old pages (fields: %s) – a pop-up or the footer. It is checked once for the whole site, not on each page.', $pages, $fields !== '' ? $fields : '–')) ?></p>
<?php endforeach ?>
<?php if ($result['web'] !== []): ?>
<h2><?= e(t('The whole site')) ?></h2>
<ul>
<?php foreach ($result['web'] as $c): ?>
	<li><?= e($c['zprava']) ?> <a href="<?= e($app->url($c['uprava'])) ?>"><?= e(t('Fix')) ?></a></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<h2><?= e(t('Old addresses')) ?></h2>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Old address')) ?></th><th scope="col"><?= e(t('On this site')) ?></th><th scope="col"><?= e(t('Problems')) ?></th></tr></thead>
<tbody>
<?php foreach (array_slice($result['radky'], 0, 500) as $r): // problems first; thousands of OK rows would only slow the page (3.7) ?>
	<tr>
		<td><a href="<?= e($state['web'] . $r['stara']) ?>" rel="noopener noreferrer" target="_blank"><?= e($r['stara']) ?></a><?php if ($r['titulek_stary'] !== ''): ?><br><span class="smltxt"><?= e($r['titulek_stary']) ?></span><?php endif ?></td>
		<td><?php if ($r['nova'] !== ''): ?><a href="<?= e(str_starts_with((string) $r['nova'], 'http') ? $r['nova'] : $app->url(ltrim((string) $r['nova'], '/'))) ?>"><?= e($r['nova']) ?></a><?php else: ?>–<?php endif ?><?php if ($r['titulek_novy'] !== ''): ?><br><span class="smltxt"><?= e($r['titulek_novy']) ?></span><?php endif ?></td>
		<td><?php if ($r['problemy'] === []): ?><span class="stitek stitek-vydano"><?= e(t('OK')) ?></span><?php else: ?><ul>
			<?php foreach ($r['problemy'] as $p): ?><li><span class="<?= e($severityClass[MigrationReport::PROBLEMS[$p] ?? 'info']) ?>"><?= e(t(['error' => 'Error', 'warning' => 'Check', 'info' => 'Note'][MigrationReport::PROBLEMS[$p] ?? 'info'])) ?></span> <?= e(MigrationReport::describe($p)) ?></li><?php endforeach ?>
		</ul><?php endif ?></td>
	</tr>
<?php endforeach ?>
</tbody></table></div>
<?php if (count($result['radky']) > 500): ?><p class="smltxt"><?= e(t('… and %s more.', count($result['radky']) - 500)) ?></p><?php endif ?>
<p><?= e(t('Fix what is missing – redirects in Redirects, descriptions and forms on the pages – and check again. Claude can do it through the connection (migration_report).')) ?></p>
<form method="post" action="<?= e($module->url('report_start')) ?>"><?= $csrf ?><input type="hidden" name="adresa" value="<?= e($state['web']) ?>">
	<p class="navigace-radek"><button class="tl" type="submit"><?= e(t('Check again')) ?></button> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p></form>
<?php endif ?>
