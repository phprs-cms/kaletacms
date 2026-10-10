<?php
/**
 * Door sign from an exception to the opening hours (Core\HoursSign): a standalone page, one A4 or A5 sheet in portrait, printed
 * from the browser. Inline CSS only, black on white (a printer), the site's own logo at most. Not part of the admin layout;
 * texts are in the site's default language (Language::runWith).
 *
 * @var string $language
 * @var string $siteName
 * @var string $logo URL of the site logo, or empty
 * @var string $headline
 * @var list<string> $dates the first and the last day in words (one item for a single day)
 * @var string $note
 * @var string $hours "Open 9:00–12:00", or empty when closed
 * @var string $thanks
 * @var list<string> $regular the regular week, line by line as the owner wrote it
 * @var string $qr SVG of the QR code to the site
 * @var string $address the site address without the scheme
 * @var string $phone
 * @var string $format a4 | a5
 * @var array<string, string> $formatUrls format => URL of the sign in that format
 * @var string $scriptUrl image/tisk.js – the Print button
 */
$a5 = $format === 'a5';
?>
<!DOCTYPE html>
<html lang="<?= e(Kaleta\Core\Language::tag($language)) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($headline) ?> – <?= e($siteName) ?></title>
<style>
@page { size: <?= $a5 ? 'A5' : 'A4' ?> portrait; margin: 0; }
:root {
	--list-sirka: <?= $a5 ? '148mm' : '210mm' ?>;
	--list-vyska: <?= $a5 ? '210mm' : '297mm' ?>;
	--u: <?= $a5 ? '0.705mm' : '1mm' ?>; /* one unit of the sheet: the A5 sign is the A4 one scaled down */
	--ink: #111;
	--muted: #555;
	--rule: #ddd;
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
	background: #e9e9e9;
	color: var(--ink);
	font-family: system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	line-height: 1.3;
	-webkit-print-color-adjust: exact;
	print-color-adjust: exact;
}
@media (prefers-color-scheme: dark) { body { background: #2a2a2a; } }
.listina {
	display: flex;
	flex-direction: column;
	width: var(--list-sirka);
	height: var(--list-vyska);
	margin: calc(var(--u) * 12) auto;
	padding: calc(var(--u) * 16) calc(var(--u) * 18);
	background: #fff;
	color: var(--ink);
	box-shadow: 0 2px 24px rgba(0, 0, 0, .18);
	overflow: hidden;
}
.hlava { display: flex; align-items: center; gap: calc(var(--u) * 5); padding-bottom: calc(var(--u) * 5); border-bottom: calc(var(--u) * .6) solid var(--ink); }
.hlava img { max-height: calc(var(--u) * 16); max-width: calc(var(--u) * 60); width: auto; height: auto; }
.hlava .nazev { font-size: calc(var(--u) * 7); font-weight: 600; letter-spacing: .01em; }
.telo { flex: 1; display: flex; flex-direction: column; justify-content: center; text-align: center; padding: calc(var(--u) * 8) 0; }
h1 { margin: 0; font-size: calc(var(--u) * 26); line-height: 1; font-weight: 800; letter-spacing: -.02em; text-transform: uppercase; overflow-wrap: anywhere; }
h1.dlouhy { font-size: calc(var(--u) * 15); text-transform: none; letter-spacing: -.01em; }
.datum { margin: calc(var(--u) * 8) 0 0; font-size: calc(var(--u) * 9); font-weight: 600; line-height: 1.25; }
.datum .az { display: block; font-size: calc(var(--u) * 6); font-weight: 400; color: var(--muted); }
.poznamka { margin: calc(var(--u) * 5) 0 0; font-size: calc(var(--u) * 7); color: var(--muted); }
.hodiny { margin: calc(var(--u) * 7) 0 0; font-size: calc(var(--u) * 9); font-weight: 700; }
.dik { margin: calc(var(--u) * 8) 0 0; font-size: calc(var(--u) * 5.5); color: var(--muted); }
.bezne { padding-top: calc(var(--u) * 6); border-top: calc(var(--u) * .3) solid var(--rule); }
.bezne h2 { margin: 0 0 calc(var(--u) * 3); font-size: calc(var(--u) * 5); font-weight: 600; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); }
.bezne ul { margin: 0; padding: 0; list-style: none; columns: 2; column-gap: calc(var(--u) * 10); font-size: calc(var(--u) * 5.5); line-height: 1.5; }
.bezne li { break-inside: avoid; }
.pata { display: flex; align-items: center; gap: calc(var(--u) * 6); margin-top: calc(var(--u) * 7); padding-top: calc(var(--u) * 6); border-top: calc(var(--u) * .3) solid var(--rule); }
.pata svg { display: block; width: calc(var(--u) * 30); height: calc(var(--u) * 30); flex: none; }
.pata .kontakt { font-size: calc(var(--u) * 5.5); line-height: 1.5; overflow-wrap: anywhere; }
.pata .kontakt strong { display: block; font-size: calc(var(--u) * 6.5); }
.lista { position: sticky; top: 0; z-index: 1; display: flex; justify-content: center; gap: 1rem; align-items: center; padding: .75rem 1rem; background: #fff; border-bottom: 1px solid #ccc; font-size: 1rem; }
.lista button { padding: .5rem 1.25rem; border: 0; border-radius: .4rem; background: #121212; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
.lista a { color: #121212; }
.lista .vybrano { font-weight: 700; text-decoration: none; }
@media (prefers-color-scheme: dark) { .lista { background: #1b1b1b; color: #eee; border-color: #444; } .lista button { background: #f6f4ee; color: #121212; } .lista a { color: #eee; } }
@media print {
	body { background: #fff; }
	.lista { display: none; }
	.listina { margin: 0; box-shadow: none; page-break-after: avoid; }
}
</style>
</head>
<body>
<div class="lista"><button type="button" data-tisk><?= e(t('Print')) ?></button>
<?php foreach ($formatUrls as $f => $url): ?><a href="<?= e($url) ?>"<?= $f === $format ? ' class="vybrano" aria-current="page"' : '' ?>><?= e(strtoupper($f)) ?></a><?php endforeach ?></div>
<main class="listina">
<header class="hlava">
<?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt=""><?php endif ?>
<span class="nazev"><?= e($siteName) ?></span>
</header>
<section class="telo">
<h1<?= mb_strlen($headline) > 12 ? ' class="dlouhy"' : '' ?>><?= e($headline) ?></h1>
<p class="datum"><?= e($dates[0]) ?><?php if (isset($dates[1])): ?><span class="az">–</span><?= e($dates[1]) ?><?php endif ?></p>
<?php if ($note !== ''): ?><p class="poznamka"><?= e($note) ?></p><?php endif ?>
<?php if ($hours !== ''): ?><p class="hodiny"><?= e($hours) ?></p><?php endif ?>
<?php if ($thanks !== ''): ?><p class="dik"><?= e($thanks) ?></p><?php endif ?>
</section>
<?php if ($regular !== []): ?>
<section class="bezne">
<h2><?= e(t('Regular opening hours')) ?></h2>
<ul><?php foreach ($regular as $line): ?><li><?= e($line) ?></li><?php endforeach ?></ul>
</section>
<?php endif ?>
<footer class="pata">
<?= $qr ?>
<div class="kontakt"><strong><?= e($address) ?></strong>
<?php if ($phone !== ''): ?><?= e(t('Phone')) ?>: <?= e($phone) ?><?php endif ?></div>
</footer>
</main>
<script src="<?= e($scriptUrl) ?>" defer></script>
</body>
</html>
