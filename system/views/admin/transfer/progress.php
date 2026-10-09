<?php
/**
 * WordPress import, step 3: progress in batches (reading the file, importing content, downloading images) and the result.
 * Until it is done, the form submits itself (data-auto-odeslat in image/admin.js) – each submission is one batch.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state
 * @var string $error  already translated error of the last batch (the import stopped)
 * @var bool $canDownload  the server can download (curl or allow_url_fopen) and has GD
 * @var string $domain  domain of the old site – images are downloaded only from it
 */
$v = $state['vysledek'];
$o = $state['obr'];
$running = in_array($state['faze'], ['analyza', 'import', 'obrazky'], true);
?>
<?= $app->view->render('admin/transfer/steps', ['step' => $state['faze'] === 'analyza' ? 2 : 3]) ?>
<?php if ($error !== ''): ?>
<p class="hlaska hlaska-chyba"><?= e(t('The import has stopped:')) ?> <?= e($error) ?></p>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>
<?php elseif ($running): ?>
<?php if ($state['faze'] === 'analyza'): ?>
<p class="hlaska" role="status"><?= e(t('Reading file %s: %s items processed. Keep this page open.', $state['soubor'], (int) $state['pozice'])) ?></p>
<?php elseif ($state['faze'] === 'import'): ?>
<p class="hlaska" role="status"><?= e(t('Importing: %s of %s items. Keep this page open, it continues by itself.', (int) $state['pozice'], (int) $state['celkem'])) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, (int) $state['celkem']) ?>" value="<?= (int) $state['pozice'] ?>"></progress>
<?php else: ?>
<p class="hlaska" role="status"><?= e(t('Downloading images from the old site: %s of %s news items and pages done, %s images downloaded. Keep this page open, I will continue automatically.', (int) $o['hotovo'], (int) $o['celkem'], (int) $o['stazeno'])) ?></p>
<progress class="prenos-prubeh" max="<?= max(1, (int) $o['celkem']) ?>" value="<?= (int) $o['hotovo'] ?>"></progress>
<?php endif ?>
<form method="post" action="<?= e($module->url('progress', ['file' => $state['soubor']])) ?>" data-auto-odeslat="600">
	<?= $csrf ?>
	<p><button class="tl" type="submit"><?= e(t('Continue')) ?></button></p>
</form>
<?php else: ?>
<p class="hlaska hlaska-ok"><?= e(t('The content import is finished.')) ?></p>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= (int) $v['clanky'] ?></strong><span><?= e(t('New news items')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['stranky'] ?></strong><span><?= e(t('New pages')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['rubriky'] ?></strong><span><?= e(t('New categories')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['presmerovani'] ?></strong><span><?= e(t('Redirects from old addresses')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) $v['preskoceno'] ?></strong><span><?= e(t('Skipped (already imported earlier)')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= (int) ($v['seo'] ?? 0) ?></strong><span><?= e(t('With an SEO title, description or noindex from a plugin')) ?></span></div>
<?php if (($v['polozky'] ?? 0) > 0): ?>
	<div class="dlazdice-polozka"><strong><?= (int) $v['polozky'] ?></strong><span><?= e(t('Collection items (custom post types), in %s new collections', (int) ($v['kolekce'] ?? 0))) ?></span></div>
<?php endif ?>
<?php if (($v['skryto'] ?? 0) > 0): ?>
	<div class="dlazdice-polozka"><strong><?= (int) $v['skryto'] ?></strong><span><?= e(t('Public on WordPress, imported hidden')) ?></span></div>
<?php endif ?>
<?php if (Kaleta\Core\WpImport::isMultilingual($state)): ?>
	<div class="dlazdice-polozka"><strong><?= (int) ($v['preklady'] ?? 0) ?></strong><span><?= e(t('Translations linked to their original')) ?></span></div>
<?php endif ?>
</div>
<?php if (($state['jazyky']['pridano'] ?? []) !== []): ?>
<p><?= e(t('Language versions added to the site: %s.', implode(', ', array_map(fn (string $code): string => Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code, array_map('strval', (array) $state['jazyky']['pridano']))))) ?></p>
<?php endif ?>
<?php if (($state['kolize'] ?? []) !== []): // 3.9: slugs are unique across languages until per-language slugs are on ?>
<details class="pokrocile" open><summary><?= e(t('Addresses another language version already had (%s)', (int) ($v['kolize'] ?? count($state['kolize'])))) ?></summary>
<p class="napoveda"><?= e(t('These got a number at the end; the old address redirects to the new one. Once each language version can have its own addresses, they can get their original address back.')) ?></p><ul>
<?php foreach ($state['kolize'] as $r): ?>
	<li><?= e(t('%s instead of %s – %s has it', (string) $r[1], (string) $r[0], (string) $r[2])) ?></li>
<?php endforeach ?>
</ul></details>
<?php endif ?>
<?php if (($tooLarge = Kaleta\Core\WpImport::tooLarge($state, Kaleta\Core\HtmlLimits::message(...))) !== []): // 3.8: left out, never imported in part ?>
<div class="hlaska hlaska-varovani"><p><?= e(t('Not imported – the HTML is over a safety limit:')) ?></p><ul>
<?php foreach ($tooLarge as $row): ?>
	<li><?= e($row) ?></li>
<?php endforeach ?>
</ul></div>
<?php endif ?>
<?php $menuReasons =['no_location' => 'this site has a main and a footer menu, and other menus took them', 'empty' => 'none of its links lead to anything that was imported',
    'draft_taken' => 'the draft look already holds a different menu for this place – nothing was overwritten', 'already_imported' => 'it was imported by an earlier run']; ?>
<?php if (($state['menu_vysledek'] ?? []) !== []): ?>
<h2><?= e(t('Menus')) ?></h2>
<ul>
<?php foreach ($state['menu_vysledek'] as $m): ?>
	<li><?= e($m['stav'] === 'koncept'
        ? t('Menu “%s” is in the draft look – %s, %s items. Publish the look to show it on the site.', (string) $m['nazev'], t(Kaleta\Core\Menu::LOCATIONS[$m['umisteni']] ?? ''), (int) $m['polozky'])
        : t('Menu “%s” was not imported: %s.', (string) $m['nazev'], t($menuReasons[$m['duvod']] ?? (string) $m['duvod']))) ?><?= ($m['jazyk'] ?? '') !== '' ? ' (' . e(Kaleta\Core\Language::AVAILABLE[$m['jazyk']][0] ?? (string) $m['jazyk']) . ')' : '' ?></li>
<?php endforeach ?>
</ul>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url('admin.php?module=menu')) ?>"><?= e(t('Open the menu')) ?></a></p>
<?php endif ?>
<?php if (($state['autori_vysledek'] ?? []) !== []): ?>
<h2><?= e(t('Authors')) ?></h2>
<ul>
<?php foreach ($state['autori_vysledek'] as $a): ?>
	<li><?= e(match ($a['jak']) {
        'import' => t('%s: the news items belong to you', (string) $a['jmeno']),
        'email' => t('%s: the news items belong to %s, who has the same e-mail', (string) $a['jmeno'], (string) $a['uzivatel_jmeno']),
        default => t('%s: the news items belong to %s', (string) $a['jmeno'], (string) $a['uzivatel_jmeno']),
    }) ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php if (($state['presmerovani']['odmitnute'] ?? []) !== []): ?>
<details class="pokrocile"><summary><?= e(t('Old addresses that were not redirected (%s)', count($state['presmerovani']['odmitnute']))) ?></summary><ul>
<?php foreach ($state['presmerovani']['odmitnute'] as $r): ?>
	<li><?= e($r[0] . ' → ' . $r[1] . ' – ' . t(($r[2] ?? '') === 'address_in_use' ? 'the address is already in use on this site' : 'the address already redirects elsewhere')) ?></li>
<?php endforeach ?>
</ul></details>
<?php endif ?>
<p class="navigace-radek"><a class="navigace" href="<?= e($app->url('admin.php?module=news')) ?>"><?= e(t('Show news')) ?></a> <a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>

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
<?php elseif ($domain === ''): ?>
<p class="hlaska"><?= e(t('The file does not contain the address of the old site, so the images cannot be downloaded.')) ?></p>
<?php else: ?>
<p><?= e(t('News and pages still show images from the old site. Downloading saves the main news images and images in texts to Media (resized, with thumbnails and WebP) and rewrites the links in the texts. Images are downloaded only from the domain %s, and the old site must still be available.', $domain)) ?></p>
<form method="post" action="<?= e($module->url('images')) ?>"><?= $csrf ?><input type="hidden" name="soubor" value="<?= e($state['soubor']) ?>">
	<p><button class="tl" type="submit"><?= e(t($state['faze'] === 'obrazky-hotovo' ? 'Try downloading again' : 'Download images from the old site')) ?></button></p></form>
<?php endif ?>
<?php endif ?>
