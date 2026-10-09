<?php
/**
 * Import and export: step 1 of the WordPress import (file) and export of the whole site.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array{soubor:string, velikost:int, cas:int, stav:array<string,mixed>|null}> $files  WordPress exports in storage/import/
 * @var int $uploadLimit  how many bytes the server allows to upload through a form
 * @var bool $missingXml  the server lacks the extension for reading XML
 * @var list<array{soubor:string, velikost:int, cas:int}> $exports
 * @var bool $hasZip
 * @var list<array{soubor:string, velikost:int, cas:int, stav:array<string,mixed>|null}> $kaletaFiles  Kaleta exports in storage/import/
 * @var array{prazdny: bool, stranky: int, novinky: int, polozky: int, media: int} $siteContent
 * @var list<array<string, mixed>> $webImports  imports from a website (2.6)
 * @var bool $canDownload  the server can download from other sites and has GD
 * @var list<string> $languages  additional language versions of the site
 * @var list<array<string, mixed>> $reports  migration parity reports (2.7)
 * @var array<string, class-string<Kaleta\Import\Source>> $sources  structured importers of other systems (3.0)
 * @var array<string, class-string<Kaleta\Import\Source&Kaleta\Import\Remote>> $remoteSources  those fetched from the site's API (Joomla, Drupal)
 * @var bool $canFetch  the server has curl, so it can read a site's API
 * @var list<array{soubor:string, zdroj:string, velikost:int, cas:int, stav:array<string,mixed>|null}> $sourceFiles  their exports in storage/import/sources/
 */
$phase = [
    'stahovani' => 'being fetched from the site', 'analyza' => 'being read', 'nahled' => 'ready to import', 'import' => 'import in progress', 'hotovo' => 'content imported',
    'obrazky' => 'downloading images', 'obrazky-hotovo' => 'imported including images',
];
?>
<?php /* 3.2: one panel per task, folded until it is opened or has work in progress – the screen no longer stacks seven forms */ ?>
<details class="panel-sbaleny"<?= $webImports !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Import from a website')) ?></h2></summary>
<p><?= e(t('Enter the address of a site on any platform – Wix, Webnode, Jimdo, Squarespace, Joomla, Drupal or WordPress without an export. Its pages become builder pages with their images, and the old addresses redirect to the new ones. The pages stay hidden until you check and publish them; the design is not copied – the pages take this site’s look.')) ?></p>
<?php if (!$canDownload): ?>
<p class="hlaska hlaska-chyba"><?= e(t('This server cannot download from other sites (both curl and allow_url_fopen are missing, or the GD extension).')) ?></p>
<?php else: ?>
<form class="formular" method="post" action="<?= e($module->url('web_start')) ?>">
<?= $csrf ?>
<div class="radek"><label for="adresa"><?= e(t('Address of the site')) ?></label><div><input class="textpole siroke" type="url" id="adresa" name="adresa" placeholder="https://www.example.com" required maxlength="300">
	<span class="napoveda"><?= e(t('Kaleta reads the sitemap, or follows the site’s links when there is none – at most %s pages.', Kaleta\Core\WebImport::MAX_PAGES)) ?></span></div></div>
<?php if ($languages !== []): ?>
<div class="radek"><label for="web_jazyk"><?= e(t('Language version')) ?></label><div><select id="web_jazyk" name="jazyk"><option value=""><?= e(t('the main language')) ?></option>
<?php foreach ($languages as $code): ?><option value="<?= e($code) ?>"><?= e(Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code) ?></option><?php endforeach ?></select></div></div>
<?php endif ?>
<div class="radek"><span></span><div>
	<label><input type="checkbox" name="obrazky" value="1" checked> <?= e(t('Download the images into Media')) ?></label><br>
	<label><input type="checkbox" name="presmerovani" value="1" checked> <?= e(t('Redirect the old addresses to the new pages')) ?></label><br>
	<label><input type="checkbox" name="novinky" value="1" checked> <?= e(t('Import blog posts as news (addresses like /blog/…, or with a publication date)')) ?></label>
</div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Find the pages')) ?>"></p>
</form>
<?php endif ?>
<?php if ($webImports !== []): ?>
<ul class="seznam-importu">
<?php foreach ($webImports as $w): ?>
	<li><a href="<?= e($module->url('web_progress', ['id' => $w['id']])) ?>"><?= e($w['web']) ?></a> – <?= e(t(['hledani' => 'finding pages', 'nahled' => 'ready to import', 'import' => 'import in progress', 'hotovo' => 'content imported'][$w['faze']] ?? '–')) ?>
		<form class="vradku" method="post" action="<?= e($module->url('web_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($w['id']) ?>"><button class="navigace" type="submit"><?= e(t('Remove from the list')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
</details>

<details class="panel-sbaleny"<?= $reports !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Check the move before going live')) ?></h2></summary>
<p><?= e(t('Before you point the domain to this site, check the old site against it: every old address must lead somewhere, and no page may lose its search engine description, its form or most of its images. Nothing is changed – the check only reads.')) ?></p>
<?php if ($canDownload): ?>
<form class="formular" method="post" action="<?= e($module->url('report_start')) ?>">
<?= $csrf ?>
<div class="radek"><label for="stary_web"><?= e(t('Address of the old site')) ?></label><div><input class="textpole siroke" type="url" id="stary_web" name="adresa" placeholder="https://www.example.com" required maxlength="300"></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Check the move')) ?>"></p>
</form>
<?php endif ?>
<?php if ($reports !== []): ?>
<ul class="seznam-importu">
<?php foreach ($reports as $r): ?>
	<li><a href="<?= e($module->url('report', ['id' => $r['id']])) ?>"><?= e($r['web']) ?></a> – <?= e($r['faze'] === 'hotovo' ? t('checked %s', substr((string) ($r['dokonceno'] ?? $r['zalozeno']), 0, 16)) : t('check in progress')) ?>
		<form class="vradku" method="post" action="<?= e($module->url('report_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="navigace" type="submit"><?= e(t('Remove from the list')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
</details>

<details class="panel-sbaleny"<?= $files !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Import from WordPress')) ?></h2></summary>
<?= $app->view->render('admin/transfer/steps', ['step' => 1]) ?>
<p><?= e(t('In WordPress, open Tools → Export, choose “All content” and download the .xml file. Then upload it here. Pages, posts (as news), categories and tags are converted and redirects from the old addresses are created; nothing changes on the site until you confirm the import in the next step.')) ?></p>
<?php if ($missingXml): ?>
<p class="hlaska hlaska-chyba"><?= e(t('The PHP extension xmlreader or dom is missing on the server – a WordPress export cannot be read without them.')) ?></p>
<?php else: ?>
<form class="formular" method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>">
<?= $csrf ?>
<div class="radek"><label for="soubor"><?= e(t('WordPress export')) ?></label><div><input type="file" id="soubor" name="soubor" accept=".xml,text/xml,application/xml" required>
	<span class="napoveda"><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/ folder – it will appear in the list below.', Kaleta\Core\Files::size($uploadLimit))) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Upload and show preview')) ?>"></p>
</form>
<?php endif ?>

<?php if ($files !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Uploaded')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($files as $s): $state = $s['stav']; ?>
<tr>
	<td><?= e($s['soubor']) ?></td>
	<td class="cislo"><?= e(Kaleta\Core\Files::size($s['velikost'])) ?></td>
	<td class="cislo"><?= e(format_date(date('Y-m-d H:i:s', $s['cas']), true)) ?></td>
	<td><?= $state === null ? '–' : e(t($phase[$state['faze']] ?? '–')) . ($state['faze'] === 'import' ? ' (' . (int) $state['pozice'] . ' / ' . (int) $state['celkem'] . ')' : '') ?></td>
	<td class="akce">
<?php if ($state !== null): ?>
		<a href="<?= e($module->url($state['faze'] === 'nahled' ? 'preview' : 'progress', ['file' => $s['soubor']])) ?>"><?= e(t(in_array($state['faze'], ['hotovo', 'obrazky-hotovo'], true) ? 'Result' : 'Continue')) ?></a>
<?php endif ?>
<?php if (!$missingXml): ?>
		<form class="vradku" method="post" action="<?= e($module->url('select')) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace" type="submit"><?= e(t($state === null ? 'Show preview' : 'Read again')) ?></button></form>
<?php endif ?>
		<form class="vradku" method="post" action="<?= e($module->url('delete_file')) ?>" data-potvrdit="<?= e(t('Delete the file %s? Content that has already been imported stays on the site.', $s['soubor'])) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="smltxt"><?= e(t('You can import the same file repeatedly – whatever has already been imported is skipped. Delete the file when the import is finished; it contains e-mail addresses of authors and commenters from the old site.')) ?></p>
<?php endif ?>
</details>

<details class="panel-sbaleny"<?= $sourceFiles !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('From another system')) ?></h2></summary>
<p><?= e(t('Moving from a system with its own export: posts become news items, pages become pages, categories and tags come along, old addresses redirect to the new ones. In the next step you see what the file contains and choose what becomes what; nothing changes on the site until you confirm.')) ?></p>
<form class="formular" method="post" enctype="multipart/form-data" action="<?= e($module->url('source_upload')) ?>">
<?= $csrf ?>
<div class="radek"><label for="system"><?= e(t('System')) ?></label><div><select id="system" name="system">
	<option value="wordpress">WordPress</option>
<?php foreach (array_diff_key($sources, $remoteSources) as $key => $class): ?>
	<option value="<?= e($key) ?>"><?= e($class::name()) ?></option>
<?php endforeach ?>
</select><span class="napoveda"><?php foreach (array_diff_key($sources, $remoteSources) as $class): ?><?= e($class::name() . ': ' . t($class::hint())) ?> <?php endforeach ?></span></div></div>
<div class="radek"><label for="soubor-system"><?= e(t('Export file')) ?></label><div><input type="file" id="soubor-system" name="soubor" accept=".xml,.json,.csv,text/xml,application/xml,application/json,text/csv" required>
	<span class="napoveda"><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/sources/ folder, named system-name.extension (for example ghost-blog.json) – it will appear in the list below.', Kaleta\Core\Files::size($uploadLimit))) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Upload and show preview')) ?>"></p>
</form>
<?php foreach ($remoteSources as $key => $class): ?>
<h3><?= e(t('From %s', $class::name())) ?></h3>
<p><?= e(t($class::hint())) ?></p>
<?php if (!$canFetch): ?>
<p class="hlaska hlaska-chyba"><?= e(t('The PHP extension curl is missing on the server – the site’s API cannot be read without it.')) ?></p>
<?php else: ?>
<form class="formular" method="post" action="<?= e($module->url('source_fetch')) ?>" autocomplete="off">
<?= $csrf ?>
<input type="hidden" name="system" value="<?= e($key) ?>">
<div class="radek"><label for="adresa-<?= e($key) ?>"><?= e(t('Site address')) ?></label><div><input class="textpole siroke" type="url" id="adresa-<?= e($key) ?>" name="adresa" placeholder="https://www.example.com" maxlength="300" required></div></div>
<div class="radek"><label for="token-<?= e($key) ?>"><?= e(t('API token')) ?></label><div><input class="textpole siroke" type="password" id="token-<?= e($key) ?>" name="token" maxlength="500" autocomplete="off">
	<span class="napoveda"><?= e(t($class::tokenHint())) ?> <?= e(t('The token is used only for this fetch and is not stored anywhere.')) ?></span></div></div>
<div class="radek"><span class="popisek"><?= e(t('What to fetch')) ?></span><div class="volby">
<?php foreach ($class::steps() as $i => $label): ?>
	<label><input type="checkbox" name="kroky[]" value="<?= e($i) ?>" checked<?= array_key_first($class::steps()) === $i ? ' disabled' : '' ?>> <?= e(t($label)) ?></label>
<?php endforeach ?>
</div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Fetch and show preview')) ?>"></p>
</form>
<?php endif ?>
<?php endforeach ?>
<?php if ($sourceFiles !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('System')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Uploaded')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($sourceFiles as $s): $state = $s['stav']; ?>
<tr>
	<td><?= e($s['soubor']) ?></td>
	<td><?= e($sources[$s['zdroj']]::name()) ?></td>
	<td class="cislo"><?= e(Kaleta\Core\Files::size($s['velikost'])) ?></td>
	<td class="cislo"><?= e(format_date(date('Y-m-d H:i:s', $s['cas']), true)) ?></td>
	<td><?= $state === null ? '–' : e(t($phase[$state['faze']] ?? '–')) . ($state['faze'] === 'import' ? ' (' . (int) $state['pozice'] . ' / ' . (int) $state['celkem'] . ')' : '') ?></td>
	<td class="akce">
<?php if ($state !== null): ?>
		<a href="<?= e($module->url($state['faze'] === 'nahled' ? 'source_preview' : 'source_progress', ['file' => $s['soubor']])) ?>"><?= e(t(in_array($state['faze'], ['hotovo', 'obrazky-hotovo'], true) ? 'Result' : 'Continue')) ?></a>
<?php endif ?>
		<form class="vradku" method="post" action="<?= e($module->url('source_select')) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace" type="submit"><?= e(t($state === null ? 'Show preview' : 'Read again')) ?></button></form>
		<form class="vradku" method="post" action="<?= e($module->url('source_delete')) ?>" data-potvrdit="<?= e(t('Delete the file %s? Content that has already been imported stays on the site.', $s['soubor'])) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="smltxt"><?= e(t('You can import the same file repeatedly – whatever has already been imported is skipped. Delete the file when the import is finished; it contains e-mail addresses of authors from the old site.')) ?></p>
<?php endif ?>
</details>

<details class="panel-sbaleny"<?= $kaletaFiles !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Import from Kaleta')) ?></h2></summary>
<p><?= e(t('Moving a site from another Kaleta installation: upload its export (the .zip archive from Export of the whole site). Everything is imported – pages, news, collections, components, menus, the look and the media – into a new, empty site; user accounts and secrets are never part of an export.')) ?></p>
<?php if (!$siteContent['prazdny']): ?>
<p class="hlaska"><?= e(t('This site already has its own content, so an export cannot be imported here. Install Kaleta again and choose “Start from an export”.')) ?></p>
<?php else: ?>
<form class="formular" method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>">
<?= $csrf ?>
<div class="radek"><label for="soubor-kaleta"><?= e(t('Kaleta export')) ?></label><div><input type="file" id="soubor-kaleta" name="soubor" accept=".zip,.json,application/zip,application/json" required>
	<span class="napoveda"><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/ folder – it will appear in the list below.', Kaleta\Core\Files::size($uploadLimit))) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Upload and show preview')) ?>"></p>
</form>
<?php endif ?>
<?php if ($kaletaFiles !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Uploaded')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($kaletaFiles as $s): $state = $s['stav']; ?>
<tr>
	<td><?= e($s['soubor']) ?></td>
	<td class="cislo"><?= e(Kaleta\Core\Files::size($s['velikost'])) ?></td>
	<td class="cislo"><?= e(format_date(date('Y-m-d H:i:s', $s['cas']), true)) ?></td>
	<td><?= $state === null ? '–' : e(t(['priprava' => 'being read', 'nahled' => 'ready to import', 'data' => 'import in progress', 'media' => 'import in progress', 'hotovo' => 'imported'][$state['faze']] ?? '–')) ?></td>
	<td class="akce">
<?php if ($state !== null && $state['faze'] !== 'priprava'): ?>
		<a href="<?= e($module->url('kaleta', ['file' => $s['soubor']])) ?>"><?= e(t($state['faze'] === 'hotovo' ? 'Result' : 'Continue')) ?></a>
<?php endif ?>
<?php if ($state === null || $state['faze'] === 'nahled'): ?>
		<form class="vradku" method="post" action="<?= e($module->url('kaleta_select')) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace" type="submit"><?= e(t($state === null ? 'Show preview' : 'Read again')) ?></button></form>
<?php endif ?>
		<form class="vradku" method="post" action="<?= e($module->url('kaleta_delete')) ?>" data-potvrdit="<?= e(t('Delete the file %s? Content that has already been imported stays on the site.', $s['soubor'])) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($s['soubor']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
</details>

<details class="panel-sbaleny"<?= $exports !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Export of the whole site')) ?></h2></summary>
<p><?= e(t('One archive gives you the whole site in an open format (JSON): pages, news, categories, tags, redirects, menus, collections, pop-ups, site parts, components, shared classes and uploaded files – as a content backup or for moving elsewhere. Enquiries, passwords, keys and accounts are not included.')) ?></p>
<?php if (!$hasZip): ?>
<p class="hlaska"><?= e(t('The PHP zip extension is missing on the server, so the export contains data only (JSON). Download the media/ folder over FTP.')) ?></p>
<?php endif ?>
<form method="post" action="<?= e($module->url('export')) ?>"><?= $csrf ?><p><button class="tl" type="submit"><?= e(t('Create export')) ?></button></p></form>
<?php if ($exports !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Velikost')) ?></th><th scope="col"><?= e(t('Vytvořeno')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($exports as $x): ?>
<tr>
	<td><?= e($x['soubor']) ?></td>
	<td class="cislo"><?= e(Kaleta\Core\Files::size($x['velikost'])) ?></td>
	<td class="cislo"><?= e(format_date(date('Y-m-d H:i:s', $x['cas']), true)) ?></td>
	<td class="akce"><a href="<?= e($module->url('download', ['file' => $x['soubor']])) ?>"><?= e(t('Download')) ?></a>
		<form class="vradku" method="post" action="<?= e($module->url('delete_export')) ?>" data-potvrdit="<?= e(t('Delete the export %s?', $x['soubor'])) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($x['soubor']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="smltxt"><?= e(t('The last three exports are kept. To restore this site, use the database backup in Settings → Backups and updates.')) ?></p>
<?php endif ?>
</details>
