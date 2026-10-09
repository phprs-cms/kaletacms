<?php
/**
 * Import from another system (3.0, Import\Batch), step 3: progress in batches (reading the file, importing content,
 * downloading images) and the result. Until it is done, the form submits itself (data-auto-odeslat in image/admin.js) –
 * each submission is one batch. The same pattern as the WordPress import (progress.php).
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state
 * @var class-string<Kaleta\Import\Source> $source
 * @var string $error  already translated error of the last batch (the import stopped)
 * @var bool $canDownload  the server can download (curl or allow_url_fopen) and has GD
 * @var string $domain  domain of the old site
 * @var bool $anyHost  the source keeps images on a CDN, so they are downloaded from any public host
 */
$v = $state['vysledek'];
$o = $state['obr'];
$running = in_array($state['faze'], ['stahovani', 'analyza', 'import', 'obrazky'], true);
$f = $state['stahovani'];
?>
<?= $app->view->render('admin/transfer/steps', ['step' => $state['faze'] === 'stahovani' ? 1 : ($state['faze'] === 'analyza' ? 2 : 3)]) ?>
<?php if ($error !== ''): ?>
<p class="hlaska hlaska-chyba"><?= e(t('The import has stopped:')) ?> <?= e($error) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>
<?php elseif ($running): ?>
<?php if ($state['faze'] === 'stahovani'): ?>
<p class="hlaska" role="status"><?= e(t('Fetching from %s: step %s of %s (%s), %s pages and %s items so far. Keep this page open, it continues by itself.', (string) ($f['web'] ?? ''), min((int) ($f['krok'] ?? 0) + 1, count($f['kroky'] ?? [])), count($f['kroky'] ?? []), t((string) ($source::steps()[$f['kroky'][$f['krok']] ?? ''] ?? '')), (int) ($f['strana'] ?? 0), (int) ($f['polozek'] ?? 0))) ?></p>
<?php elseif ($state['faze'] === 'analyza'): ?>
<p class="hlaska" role="status"><?= e(t('Reading file %s: %s items processed. Keep this page open.', $state['soubor'], (int) $state['pozice'])) ?></p>
<?php elseif ($state['faze'] === 'import'): ?>
<p class="hlaska" role="status"><?= e(t('Importing: %s of %s items. Keep this page open, it continues by itself.', (int) $state['pozice'], (int) $state['celkem'])) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, (int) $state['celkem']) ?>" value="<?= (int) $state['pozice'] ?>"></progress>
<?php else: ?>
<p class="hlaska" role="status"><?= e(t('Downloading images from the old site: %s of %s news items and pages done, %s images downloaded. Keep this page open, I will continue automatically.', (int) $o['hotovo'], (int) $o['celkem'], (int) $o['stazeno'])) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, (int) $o['celkem']) ?>" value="<?= (int) $o['hotovo'] ?>"></progress>
<?php endif ?>
<form method="post" action="<?= e($module->url('source_progress', ['file' => $state['soubor']])) ?>" data-auto-odeslat="600">
	<?= $csrf ?>
	<p><button class="tl" type="submit"><?= e(t('Continue')) ?></button></p>
</form>
<?php else: ?>
<p class="hlaska hlaska-ok"><?= e(t('The content import is finished.')) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) $v['clanky'] ?></strong><span><?= e(t('New news items')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['stranky'] ?></strong><span><?= e(t('New pages')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['rubriky'] ?></strong><span><?= e(t('New categories')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['stitky'] ?></strong><span><?= e(t('New tags')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['presmerovani'] ?></strong><span><?= e(t('Redirects from old addresses')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['preskoceno'] ?></strong><span><?= e(t('Skipped (already imported earlier)')) ?></span></div>
</div>
<?php if (($tooLarge = Kaleta\Core\WpImport::tooLarge($state, Kaleta\Core\HtmlLimits::message(...))) !== []): // 3.8: left out, never imported in part ?>
<div class="hlaska hlaska-varovani"><p><?= e(t('Not imported – the HTML is over a safety limit:')) ?></p><ul>
<?php foreach ($tooLarge as $row): ?>
	<li><?= e($row) ?></li>
<?php endforeach ?>
</ul></div>
<?php endif ?>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url('admin.php?module=news')) ?>"><?= e(t('Show news')) ?></a> <a class="navigace" href="<?= e($app->url('admin.php?module=pages')) ?>"><?= e(t('Show pages')) ?></a> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>

<h2><?= e(t('Images from the old site')) ?></h2>
<?php if ($state['faze'] === 'obrazky-hotovo'): ?>
<p class="hlaska <?= (int) $o['chyb'] > 0 ? 'hlaska-varovani' : 'hlaska-ok' ?>"><?= e(t('%s images downloaded, %s failed.', (int) $o['stazeno'], (int) $o['chyb'])) ?></p>
<?php if ($o['chyby'] !== []): ?>
<details class="pokrocile"><summary><?= e(t('Latest images that could not be downloaded')) ?></summary><ul>
<?php foreach ($o['chyby'] as $row): ?>
	<li><?= e($row) ?></li>
<?php endforeach ?>
</ul></details>
<?php endif ?>
<?php endif ?>
<?php if (!$canDownload): ?>
<p class="hlaska"><?= e(t('This server cannot download files from other sites (both curl and allow_url_fopen are missing, or the GD extension). Move the images manually: upload them to Media and replace them in the articles.')) ?></p>
<?php elseif ($domain === '' && !$anyHost): ?>
<p class="hlaska"><?= e(t('The file does not contain the address of the old site, so the images cannot be downloaded.')) ?></p>
<?php else: ?>
<p><?= e($anyHost
    ? t('News and pages still show images from the old site. Downloading saves the main news images and images in texts to Media (resized, with thumbnails and WebP) and rewrites the links in the texts. Images are downloaded from the image hosts of %s, which must still be available.', $source::name())
    : t('News and pages still show images from the old site. Downloading saves the main news images and images in texts to Media (resized, with thumbnails and WebP) and rewrites the links in the texts. Images are downloaded only from the domain %s, and the old site must still be available.', $domain)) ?></p>
<form method="post" action="<?= e($module->url('source_images')) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($state['soubor']) ?>">
	<p><button class="tl" type="submit"><?= e(t($state['faze'] === 'obrazky-hotovo' ? 'Try downloading again' : 'Download images from the old site')) ?></button></p></form>
<?php endif ?>
<?php endif ?>
