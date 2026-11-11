<?php
/**
 * Import from a website (2.6): finding the pages, the preview, the import in batches and the result. While it runs, the
 * form submits itself (data-auto-odeslat in image/admin.js) – each submission is one batch.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state  Core\WebImport
 */
$v = $state['vysledek'];
$urls = array_keys($state['adresy']);
?>
<p><?= e(t('Site: %s', $state['web'])) ?></p>
<?php foreach (Kaleta\Core\WebImport::notes($state) as $note): ?><p class="hlaska"><?= e($note) ?></p><?php endforeach ?>
<?php if ($state['faze'] === 'hledani'): ?>
<p class="hlaska" role="status"><?= e(t('Finding pages: %s found so far. Keep this page open, it continues by itself.', count($urls))) ?></p>
<form method="post" action="<?= e($module->url('web_progress', ['id' => $state['id']])) ?>" data-auto-odeslat="400"><?= $csrf ?>
	<p><button class="tl" type="submit"><?= e(t('Continue')) ?></button></p></form>
<?php elseif ($state['faze'] === 'nahled'): ?>
<?php if ($urls === []): ?>
<p class="hlaska hlaska-chyba"><?= e(t('No pages were found. Check the address – the site has to be reachable from this server.')) ?></p>
<?php else: ?>
<p class="hlaska hlaska-ok"><?= e(t('%s pages found. They will be imported as hidden pages; nothing changes for visitors until you publish them.', count($urls))) ?></p>
<div class="tab-obal"><table class="vypis"><thead><tr><th scope="col"><?= e(t('Address')) ?></th></tr></thead><tbody>
<?php foreach (array_slice($urls, 0, 40) as $url): ?>
	<tr><td><?= e('/' . Kaleta\Core\WebImport::path($url)) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php if (count($urls) > 40): ?><p class="smltxt"><?= e(t('… and %s more.', count($urls) - 40)) ?></p><?php endif ?>
<form method="post" action="<?= e($module->url('web_run')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($state['id']) ?>">
	<p><button class="tl" type="submit"><?= e(t('Import %s pages', count($urls))) ?></button></p></form>
<?php endif ?>
<?php elseif ($state['faze'] === 'import'): ?>
<p class="hlaska" role="status"><?= e(t('Importing: %s of %s pages. Keep this page open, it continues by itself.', (int) $state['pozice'], count($urls))) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, count($urls)) ?>" value="<?= (int) $state['pozice'] ?>"></progress>
<form method="post" action="<?= e($module->url('web_progress', ['id' => $state['id']])) ?>" data-auto-odeslat="400"><?= $csrf ?>
	<p><button class="tl" type="submit"><?= e(t('Continue')) ?></button></p></form>
<?php else: ?>
<p class="hlaska hlaska-ok"><?= e(t('The import is finished. The pages are hidden until you check and publish them.')) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) $v['stranky'] ?></strong><span><?= e(t('New pages')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['clanky'] ?></strong><span><?= e(t('New news items')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['obrazky'] ?></strong><span><?= e(t('Images in Media')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['presmerovani'] ?></strong><span><?= e(t('Redirects from old addresses')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['preskoceno'] ?></strong><span><?= e(t('Skipped (already imported earlier)')) ?></span></div>
</div>
<?php // 3.33: what the pages kept of the old site's forms, accordions, videos, maps, files and SEO fields
$kept = Kaleta\Core\WebImport::keptSummary($state);
$keptNames = ['forms' => 'forms', 'faq' => 'FAQ and accordions', 'tabs' => 'tabs', 'videos' => 'videos', 'maps' => 'maps', 'embeds' => 'embedded pages',
    'counters' => 'counters', 'files' => 'files in Media', 'pages_with_seo_fields' => 'pages with the old SEO title or share image'];
if ($kept !== []): ?>
<p data-import-kept><?= e(t('Kept from the old pages:')) ?> <?= e(implode(', ', array_map(fn (string $key, int $count): string => $count . ' ' . t($keptNames[$key] ?? $key), array_keys($kept), $kept))) ?></p>
<?php endif ?>
<?php if (!empty($state['pozor'])): ?>
<details class="pokrocile" open><summary><?= e(t('%s things need your attention', count($state['pozor']))) ?></summary><ul>
<?php foreach ($state['pozor'] as $row): ?><li><?= e($row) ?></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<?php if ($state['chyby'] !== []): ?>
<details class="pokrocile"><summary><?= e(t('%s pages could not be imported', (int) $v['chyb'])) ?></summary><ul>
<?php foreach ($state['chyby'] as $row): ?><li><?= e($row) ?></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<p><?= e(t('Next: check the pages, put the ones you keep into the menu and publish them. Claude can match their look to the rest of the site and tidy the texts through the connection.')) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url('admin.php?module=pages')) ?>"><?= e(t('Show pages')) ?></a> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>
<?php endif ?>
