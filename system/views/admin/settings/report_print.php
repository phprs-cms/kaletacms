<?php
/**
 * The printable monthly report (3.16, Core\ReportPage): a standalone A4 document the browser prints or saves as PDF, and the
 * same file to download. Inline CSS only, black on white; the agency's logo is the only image. Page numbers and the
 * site and month at the foot of every page come from the @page margin boxes; a table row, a short section and a heading
 * with what follows it never split across pages. Not part of the admin layout; texts in the site's default language.
 *
 * @var string $language
 * @var string $title
 * @var string $monthName
 * @var string $siteName
 * @var string $siteAddress the site address without the scheme
 * @var string $agency the agency's name, or empty
 * @var string $agencyLogo absolute URL of the agency logo, or empty
 * @var list<string> $agencyContacts the agency's web, e-mail and phone
 * @var string $generated when the report was made
 * @var string $rebuilt when an administrator rebuilt it, or empty
 * @var list<array{key: string, title: string, blocks: list<array<string, mixed>>}> $sections
 * @var array{script?: string, download?: string, back?: string} $links the screen toolbar; empty in the downloaded file
 */
// a text inside a CSS string of the page margin boxes: no quote, backslash, line break or "</style>" can get out of it
$cssString = static fn (string $text): string => '"' . str_replace(['\\', '"', "\n", "\r", '<'], ['\\\\', '\\"', ' ', ' ', '\\3C '], $text) . '"';
?>
<!DOCTYPE html>
<html lang="<?= e(Kaleta\Core\Language::tag($language)) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title><?= e($title) ?></title>
<style>
@page {
	size: A4 portrait;
	margin: 16mm 15mm 18mm;
	@bottom-left { content: <?= $cssString($siteName . ' · ' . $monthName) ?>; font: 8pt system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #666; }
	@bottom-right { content: counter(page) " / " counter(pages); font: 8pt system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #666; }
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
	background: #e9e9e9;
	color: #121212;
	font: 10pt/1.45 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	-webkit-print-color-adjust: exact;
	print-color-adjust: exact;
}
.listina { width: 210mm; max-width: 100%; margin: 10mm auto; padding: 16mm 15mm 14mm; background: #fff; box-shadow: 0 2px 24px rgba(0, 0, 0, .18); }
.hlava { display: flex; justify-content: space-between; align-items: flex-end; gap: 8mm; padding-bottom: 5mm; border-bottom: .6mm solid #121212; }
.znacka img { display: block; max-height: 14mm; max-width: 60mm; width: auto; height: auto; margin-bottom: 2.5mm; }
.znacka .agentura { font-size: 12pt; font-weight: 600; }
.znacka .kontakty { color: #555; font-size: 8.5pt; }
.titul { text-align: right; }
.titul .druh { margin: 0; color: #555; font-size: 8.5pt; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; }
.titul h1 { margin: 1mm 0 0; font-size: 20pt; line-height: 1.1; letter-spacing: -.01em; }
.titul .web { margin: 1.5mm 0 0; font-size: 10.5pt; }
.titul .web span { color: #555; }
.vznik { margin: 3mm 0 0; color: #555; font-size: 8.5pt; }
.sekce { margin-top: 7mm; break-inside: avoid-page; }
.sekce.dlouha { break-inside: auto; }
h2 { margin: 0 0 2.5mm; padding-top: 2mm; border-top: .3mm solid #ccc; font-size: 12.5pt; break-after: avoid-page; }
h3 { margin: 4mm 0 1.5mm; font-size: 10pt; break-after: avoid-page; }
table { width: 100%; border-collapse: collapse; }
thead { display: table-header-group; }
tr { break-inside: avoid-page; }
th, td { padding: 1.3mm 3mm 1.3mm 0; border-bottom: .25mm solid #e2e2e2; text-align: left; vertical-align: top; overflow-wrap: break-word; }
th { color: #555; font-size: 8.5pt; font-weight: 600; border-bottom-color: #bbb; }
td.cislo, th.cislo { text-align: right; padding-right: 0; white-space: nowrap; }
td.kratky, th.kratky { white-space: nowrap; }
table.pary td:last-child { text-align: right; padding-right: 0; white-space: nowrap; font-variant-numeric: tabular-nums; }
table.pary td.vnoreny { padding-left: 5mm; color: #444; }
table.data { font-size: 9pt; }
table.data td.cislo { font-variant-numeric: tabular-nums; }
.cisla { display: grid; grid-template-columns: repeat(4, 1fr); gap: 2.5mm; margin: 0; }
.cisla div { margin: 0; padding: 2.5mm 3mm; border: .3mm solid #ddd; border-radius: 1.5mm; break-inside: avoid; }
.cisla dt { color: #555; font-size: 8pt; }
.cisla dd { margin: .5mm 0 0; font-size: 15pt; font-weight: 700; font-variant-numeric: tabular-nums; }
.poznamka { margin: 2mm 0 0; color: #555; font-size: 8.5pt; }
ul.seznam { margin: 0; padding-left: 5mm; }
ul.seznam li { margin: 0 0 1mm; break-inside: avoid; }
.pata { margin-top: 9mm; padding-top: 3mm; border-top: .3mm solid #ccc; color: #555; font-size: 8pt; break-inside: avoid; }
.lista { position: sticky; top: 0; z-index: 1; display: flex; flex-wrap: wrap; justify-content: center; gap: .5rem 1rem; align-items: center; padding: .75rem 1rem; background: #fff; border-bottom: 1px solid #ccc; font-size: 1rem; }
.lista button { padding: .5rem 1.25rem; border: 0; border-radius: .4rem; background: #121212; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
.lista a { color: #121212; }
@media (prefers-color-scheme: dark) { body { background: #2a2a2a; } .lista { background: #1b1b1b; color: #eee; border-color: #444; } .lista button { background: #f6f4ee; color: #121212; } .lista a { color: #eee; } }
@media screen and (max-width: 220mm) { .listina { margin: 0; padding: 8mm 5mm; box-shadow: none; } .hlava { flex-direction: column; align-items: flex-start; } .titul { text-align: left; } .cisla { grid-template-columns: repeat(2, 1fr); } }
@media print {
	body { background: #fff; }
	.lista { display: none; }
	.listina { width: auto; max-width: none; margin: 0; padding: 0; box-shadow: none; }
}
</style>
</head>
<body>
<?php if (!empty($links)): ?>
<div class="lista">
<?php if (!empty($links['script'])): ?><button type="button" data-tisk><?= e(t('Print or save as PDF')) ?></button><?php endif ?>
<?php if (!empty($links['download'])): ?><a href="<?= e($links['download']) ?>"><?= e(t('Download')) ?></a><?php endif ?>
<?php if (!empty($links['back'])): ?><a href="<?= e($links['back']) ?>"><?= e(t('Back to Reports')) ?></a><?php endif ?>
</div>
<?php endif ?>
<main class="listina">
<header class="hlava">
<div class="znacka">
<?php if ($agencyLogo !== ''): ?><img src="<?= e($agencyLogo) ?>" alt="<?= e($agency) ?>"><?php endif ?>
<?php if ($agency !== ''): ?><div class="agentura"><?= e($agency) ?></div><?php if ($agencyContacts !== []): ?><div class="kontakty"><?= e(implode(' · ', $agencyContacts)) ?></div><?php endif ?>
<?php else: ?><div class="agentura"><?= e($siteName) ?></div><?php endif ?>
</div>
<div class="titul">
<p class="druh"><?= e(t('Website report')) ?></p>
<h1><?= e($monthName) ?></h1>
<p class="web"><?= e($siteName) ?> <span>· <?= e($siteAddress) ?></span></p>
</div>
</header>
<p class="vznik"><?= e(t('Made %s', $generated)) ?><?php if ($rebuilt !== ''): ?> · <?= e(t('rebuilt %s from the data still available', $rebuilt)) ?><?php endif ?></p>
<?php foreach ($sections as $section):
    $long = count(array_filter($section['blocks'], static fn (array $b): bool => $b['type'] === 'table' && count($b['rows']) > 8)) > 0; ?>
<section class="sekce<?= $long ? ' dlouha' : '' ?>" id="<?= e($section['key']) ?>">
<h2><?= e($section['title']) ?></h2>
<?php foreach ($section['blocks'] as $block): ?>
<?php if ($block['type'] === 'facts'): ?>
<dl class="cisla"><?php foreach ($block['items'] as [$label, $value]): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach ?></dl>
<?php elseif ($block['type'] === 'pairs'): ?>
<table class="pary"><tbody>
<?php foreach ($block['rows'] as $row): ?><tr><td<?= !empty($row[2]) ? ' class="vnoreny"' : '' ?>><?= e($row[0]) ?></td><td><?= e($row[1]) ?></td></tr>
<?php endforeach ?></tbody></table>
<?php elseif ($block['type'] === 'table'): ?>
<h3><?= e($block['caption']) ?></h3>
<?php $cellClass = static fn (int $i): string => in_array($i, $block['numeric'], true) ? ' class="cislo"' : (in_array($i, $block['short'] ?? [], true) ? ' class="kratky"' : ''); ?>
<table class="data"><thead><tr><?php foreach ($block['head'] as $i => $heading): ?><th scope="col"<?= $cellClass($i) ?>><?= e($heading) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($block['rows'] as $row): ?><tr><?php foreach ($row as $i => $cell): ?><td<?= $cellClass($i) ?>><?= e((string) $cell) ?></td><?php endforeach ?></tr>
<?php endforeach ?></tbody></table>
<?php elseif ($block['type'] === 'list'): ?>
<ul class="seznam"><?php foreach ($block['items'] as $item): ?><li><?= e($item) ?></li><?php endforeach ?></ul>
<?php else: ?>
<p class="poznamka"><?= e($block['text']) ?></p>
<?php endif ?>
<?php endforeach ?>
</section>
<?php endforeach ?>
<footer class="pata"><?= e(t('A summary of what happened to the website %s in %s: counts, titles and page addresses only – no personal data.', $siteName, $monthName)) ?></footer>
</main>
<?php if (!empty($links['script'])): ?><script src="<?= e($links['script']) ?>" defer></script><?php endif ?>
</body>
</html>
