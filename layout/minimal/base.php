<?php
/**
 * Layout "minimal" - globální šablona stránky: jeden úzký sloupec pro osobní magazíny a blogy.
 *
 * Kolem obsahu vykreslí zóny s bloky podle rozvržení zvoleného v administraci (Úprava bloků).
 * Vlastní layout = kopie této složky pod jiným názvem; vybírá se v Konfiguraci.
 *
 * @var MiroCMS\Core\Settings $web
 * @var string $titulek  prázdný na hlavní stránce
 * @var array{hlavni:bool, popis:string, klicova_slova:string, obrazek:string, typ:string, noindex:bool} $meta
 * @var string $obsah  hotové HTML obsahu stránky (výpis, článek...)
 * @var array{hlavicka:string, leva:string, nad:string, pod:string, prava:string, paticka:string} $zony  HTML bloků v zónách
 * @var string $rozvrzeni  tri | dva | jeden | plna - zvolené v Úpravě bloků
 * @var callable(string): string $url
 * @var string $kanonicka
 * @var string $hlava  značky do <head> z Nastavení: ověření, strukturovaná data, měřicí kódy (vždy vypsat před </head>)
 * @var string $pata   cookie lišta a kódy před </body> (vždy vypsat)
 * @var string $jazyk  kód jazyka zobrazené verze webu (cs, en…) pro <html lang>
 * @var string $jazyky_html  hotový přepínač jazykových verzí; prázdný, má-li web jediný jazyk
 * @var string $ucet_html    hotový odkaz na účet čtenáře (Přihlásit se / jméno); prázdný bez rozšíření Čtenáři
 * @var list<array{titulek:string, seo_link:string}> $stranky  statické stránky do navigace
 * @var list<array<string, mixed>> $rubriky  viditelné rubriky jako strom (klíč "uroven") - pro navigaci v záhlaví
 */
$nazevWebu = $web->get('nazev_webu');
?>
<!doctype html>
<html lang="<?= e($jazyk ?? 'cs') ?>"<?= $web->get('tmavy_rezim') === 'auto' ? ' data-tmavy' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulek !== '' ? $titulek . ' - ' . $nazevWebu : $nazevWebu) ?></title>
<?php if ($meta['popis'] !== ''): ?>
<meta name="description" content="<?= e($meta['popis']) ?>">
<?php endif ?>
<?php if ($meta['klicova_slova'] !== ''): ?>
<meta name="keywords" content="<?= e($meta['klicova_slova']) ?>">
<?php endif ?>
<?php if ($meta['noindex']): ?>
<meta name="robots" content="noindex, follow">
<?php else: ?>
<link rel="canonical" href="<?= e($kanonicka) ?>">
<?php endif ?>
<meta property="og:type" content="<?= e($meta['typ']) ?>">
<meta property="og:title" content="<?= e($titulek !== '' ? $titulek : $nazevWebu) ?>">
<meta property="og:site_name" content="<?= e($nazevWebu) ?>">
<?php if ($meta['obrazek'] !== ''): ?>
<meta property="og:image" content="<?= e($meta['obrazek']) ?>">
<?php endif ?>
<link rel="alternate" type="application/rss+xml" title="<?= e($nazevWebu) ?>" href="<?= e($url('rss.xml')) ?>">
<link rel="stylesheet" href="<?= e($url('layout/minimal/style.css')) ?>?v=<?= e(MIROCMS_VERSION) ?>">
<?= $hlava ?>
</head>
<body>
<a class="preskocit" href="#obsah"><?= e(t('Přeskočit na obsah')) ?></a>
<header class="zahlavi">
	<div class="obal">
		<a class="nazev-webu" href="<?= e($url('')) ?>"><?php if ($web->get('logo_webu') !== ''): ?><img class="logo-obrazek" src="<?= e((preg_match('#^(https?:)?/#', $web->get('logo_webu')) ? '' : $url('')) . $web->get('logo_webu')) ?>" alt="<?= e($nazevWebu) ?>"><?php else: ?><?= e($nazevWebu) ?><?php endif ?></a>
<?php if ($web->get('popis_webu') !== ''): ?>
		<p class="motto"><?= e($web->get('popis_webu')) ?></p>
<?php endif ?>
		<nav class="rubriky-lista" aria-label="<?= e(t('Rubriky')) ?>">
<?php foreach ($rubriky as $r): if ($r['uroven'] > 0) { continue; } ?>
			<a href="<?= e($url('rubrika/' . $r['seo_link'])) ?>"<?= str_ends_with($kanonicka, '/rubrika/' . $r['seo_link']) ? ' aria-current="page"' : '' ?>><?= e($r['nazev']) ?></a>
<?php endforeach ?>
<?php foreach ($stranky as $st): ?>
			<a href="<?= e($url($st['seo_link'])) ?>"<?= str_ends_with($kanonicka, '/' . $st['seo_link']) ? ' aria-current="page"' : '' ?>><?= e($st['titulek']) ?></a>
<?php endforeach ?>
			<a href="<?= e($url('hledani')) ?>"><?= e(t('Hledat')) ?></a>
		</nav>
		<?= $ucet_html ?? '' ?>
		<?= $jazyky_html ?? '' ?>
	</div>
</header>
<?php if ($zony['hlavicka'] !== ''): ?>
<div class="obal zona zona-hlavicka"><?= $zony['hlavicka'] ?></div>
<?php endif ?>
<div class="obal stranka rozvrzeni-<?= e($rozvrzeni) ?><?= $zony['leva'] !== '' ? ' ma-levou' : '' ?><?= $zony['prava'] !== '' ? ' ma-pravou' : '' ?><?= $meta['typ'] === 'article' ? ' stranka-clanek' : '' ?>">
	<main id="obsah" class="hlavni">
<?php if ($zony['nad'] !== ''): ?>
		<div class="zona zona-nad"><?= $zony['nad'] ?></div>
<?php endif ?>
<?= $obsah ?>
<?php if ($zony['pod'] !== ''): ?>
		<div class="zona zona-pod"><?= $zony['pod'] ?></div>
<?php endif ?>
	</main>
<?php /* šablona je jednosloupcová: postranní zóny následují až za obsahem */ ?>
<?php if ($zony['leva'] !== ''): ?>
	<aside class="zona zona-leva" aria-label="<?= e(t('Levý sloupec')) ?>"><?= $zony['leva'] ?></aside>
<?php endif ?>
<?php if ($zony['prava'] !== ''): ?>
	<aside class="zona zona-prava" aria-label="<?= e(t('Pravý sloupec')) ?>"><?= $zony['prava'] ?></aside>
<?php endif ?>
</div>
<?php if ($zony['paticka'] !== ''): ?>
<div class="zona-paticka-obal"><div class="obal zona zona-paticka"><?= $zony['paticka'] ?></div></div>
<?php endif ?>
<?php
$site = array_filter(['Facebook' => $web->get('soc_facebook'), 'Instagram' => $web->get('soc_instagram'), 'X' => $web->get('soc_x'), 'YouTube' => $web->get('soc_youtube'), 'LinkedIn' => $web->get('soc_linkedin')]);
?>
<footer class="zapati">
	<div class="obal">
		<?php if ($web->get('text_paticky') !== ''): ?><span><?= e($web->get('text_paticky')) ?></span><?php endif ?>
<?php foreach ($site as $sit => $adresa): ?>
		<a href="<?= e($adresa) ?>" rel="me noopener" target="_blank"><?= e($sit) ?></a>
<?php endforeach ?>
		&copy; <?= date('Y') ?> <?= e($nazevWebu) ?> &middot; <a href="<?= e($url('rss.xml')) ?>">RSS</a> &middot; <?= e(t('běží na MiroCMS')) ?>
	</div>
</footer>
<?= $pata ?>
</body>
</html>
