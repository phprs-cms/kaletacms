<?php
/**
 * Import of a Kaleta export (1.8): preview, progress in batches and the result. Until it is done, the form submits
 * itself (data-auto-odeslat in image/admin.js) – each submission is one batch.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state
 * @var string $error  already translated error of the last batch (the import stopped)
 * @var array{prazdny: bool, stranky: int, novinky: int, polozky: int, media: int} $siteContent
 */
$h = $state['hlavicka'];
$counts = $state['pocty'];
$labels = [
    'stranky' => 'Pages', 'novinky' => 'News', 'kategorie' => 'Categories', 'stitky' => 'Tags', 'kolekce' => 'Collections', 'kolekce_polozky' => 'Collection items', 'collection_categories' => 'Collection categories',
    'komponenty' => 'Components', 'tridy' => 'Shared classes', 'casti' => 'Site parts', 'menu' => 'Menus', 'popupy' => 'Pop-ups', 'presmerovani' => 'Redirects', 'media' => 'Media',
];
$tablesDone = min(count(Kaleta\Core\SiteImport::TABLES), (int) $state['tabulka']);
$rowsTotal = max(1, array_sum($counts));
$rowsDone = array_sum(array_map(fn (array $v): int => (int) ($v['ok'] ?? 0) + (int) ($v['preskoceno'] ?? 0), $state['vysledek']));
?>
<h2><?= e(t('Import from Kaleta')) ?></h2>
<?= $app->view->render('admin/transfer/steps', ['step' => $state['faze'] === 'nahled' ? 2 : 3]) ?>
<?php if ($error !== ''): ?>
<p class="hlaska hlaska-chyba"><?= e(t('The import has stopped:')) ?> <?= e($error) ?></p>
<?php if ($state['zaloha'] !== ''): ?>
<p><?= $module->hint(t('The state before the import is in backup %s (Settings → Backups and updates).', $state['zaloha'])) ?></p>
<?php endif ?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>
<?php elseif ($state['faze'] === 'nahled'): ?>
<p><?= e(t('Export of the site “%s” from Kaleta %s, created %s.', $h['nazev'], $h['kaleta'], $h['vytvoreno'] !== '' ? format_date(date('Y-m-d H:i:s', (int) strtotime($h['vytvoreno'])), true) : '–')) ?></p>
<div class="dlazdice">
<?php foreach ($labels as $table => $label): if (($counts[$table] ?? 0) === 0) { continue; } ?>
	<div class="dlazdice-polozka"><strong><?= (int) $counts[$table] ?></strong><span><?= e(t($label)) ?></span></div>
<?php endforeach ?>
	<div class="dlazdice-polozka"><strong><?= (int) $state['media_celkem'] ?></strong><span><?= e(t('Files in media/')) ?></span></div>
</div>
<?php if ($state['media_celkem'] === 0 && ($counts['media'] ?? 0) > 0): ?>
<p class="hlaska"><?= e(t('The export has no files – copy the media/ folder from the old site over FTP before or after the import.')) ?></p>
<?php endif ?>
<p><?= e(t('The import replaces all content of this site: pages, news, collections, components, menus, site parts, pop-ups, redirects and the media library, and takes over the site name, company details, languages and the look. User accounts, passwords, keys and the settings of mail and backups stay as they are on this site – the export never contains them. Imported news will be yours.')) ?></p>
<?php if (!$siteContent['prazdny']): ?>
<p class="hlaska hlaska-chyba"><?= e(t('This site already has its own content (pages: %d, news: %d, collection items: %d, media: %d). A Kaleta export can be imported only into a new, empty site – install Kaleta again and choose “Start from an export”.', $siteContent['stranky'], $siteContent['novinky'], $siteContent['polozky'], $siteContent['media'])) ?></p>
<?php else: ?>
<form class="formular" method="post" action="<?= e($module->url('kaleta_run')) ?>">
<?= $csrf ?>
<input type="hidden" name="soubor" value="<?= e($state['soubor']) ?>">
<p><label><input type="checkbox" name="potvrzeni" value="1" required> <?= e(t('Replace the content of this site with the export. A database backup is made first.')) ?></label></p>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Import')) ?>"></p>
</form>
<?php endif ?>
<?php elseif ($state['faze'] === 'data' || $state['faze'] === 'media'): ?>
<?php if ($state['faze'] === 'data'): ?>
<p class="hlaska" role="status"><?= e(t('Importing: %s of %s items. Keep this page open, it continues by itself.', $rowsDone, array_sum($counts))) ?></p>
<progress class="prenos-prubeh" max="<?= $rowsTotal ?>" value="<?= $rowsDone ?>"></progress>
<?php else: ?>
<p class="hlaska" role="status"><?= e(t('Copying files into media/: %s of %s. Keep this page open, it continues by itself.', (int) $state['media']['ulozeno'] + (int) $state['media']['preskoceno'], (int) $state['media_celkem'])) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, (int) $state['media_celkem']) ?>" value="<?= (int) $state['media']['ulozeno'] + (int) $state['media']['preskoceno'] ?>"></progress>
<?php endif ?>
<form method="post" action="<?= e($module->url('kaleta')) ?>" data-auto-odeslat="400">
	<?= $csrf ?>
	<input type="hidden" name="soubor" value="<?= e($state['soubor']) ?>">
	<p><button class="tl" type="submit"><?= e(t('Continue')) ?></button></p>
</form>
<?php else: ?>
<p class="hlaska hlaska-ok"><?= e(t('The site has been imported.')) ?></p>
<?php if (($changes = Kaleta\Core\SiteImport::changes($state)) !== []): // 3.7: what the import changed so the site keeps working ?>
<div class="hlaska" id="import-zmeny"><p><?= e(t('Changed so the site keeps working:')) ?></p><ul>
<?php foreach ($changes as $change): ?>
	<li><?= e($change) ?></li>
<?php endforeach ?>
</ul></div>
<?php endif ?>
<div class="dlazdice">
<?php foreach ($labels as $table => $label): $v = $state['vysledek'][$table] ?? null; if ($v === null) { continue; } ?>
	<div class="dlazdice-polozka"><strong><?= (int) ($v['ok'] ?? 0) ?></strong><span><?= e(t($label)) ?><?= (int) ($v['preskoceno'] ?? 0) > 0 ? ' – ' . e(t('%d skipped', (int) $v['preskoceno'])) : '' ?></span></div>
<?php endforeach ?>
	<div class="dlazdice-polozka"><strong><?= (int) $state['media']['ulozeno'] ?></strong><span><?= e(t('Files in media/')) ?></span></div>
</div>
<?php if ($state['chyby'] !== []): ?>
<details class="pokrocile"><summary><?= e(t('Files that could not be saved')) ?></summary><ul>
<?php foreach ($state['chyby'] as $row): ?>
	<li><?= e($row) ?></li>
<?php endforeach ?>
</ul></details>
<?php endif ?>
<p><?= $module->hint(t('The state before the import is in backup %s (Settings → Backups and updates). Check the site, then delete the export file – it is no longer needed.', $state['zaloha'])) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url('')) ?>"><?= e(t('Zobrazit web')) ?></a> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>
<?php endif ?>
