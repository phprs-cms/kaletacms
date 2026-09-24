<?php
/**
 * Layout "zakladni" - šablona firemního webu: hlavička s logem a navigací, obsah, patička s kontakty.
 * Na telefonu se navigace otevírá přes Popover API (bez JavaScriptu). Vlastní layout = kopie této složky pod jiným názvem.
 *
 * @var MiroCMS\Core\Settings $web
 * @var string $titulek  prázdný na úvodní stránce
 * @var array{hlavni:bool, popis:string, klicova_slova:string, obrazek:string, typ:string, noindex:bool} $meta
 * @var string $obsah  hotové HTML obsahu stránky (stránka, výpis novinek, novinka…)
 * @var callable(string): string $url
 * @var string $kanonicka
 * @var string $hlava  značky do <head> z Nastavení: SEO, strukturovaná data, měřicí kódy (vždy vypsat před </head>)
 * @var string $pata   cookie lišta a kódy před </body> (vždy vypsat)
 * @var string $jazyk  kód jazyka zobrazené verze webu (cs, en…) pro <html lang>
 * @var string $jazyky_html  hotový přepínač jazykových verzí; prázdný, má-li web jediný jazyk
 * @var list<array{titulek:string, seo_link:string, uvod:bool}> $stranky  stránky do hlavní navigace (úvodní má prázdnou adresu)
 */
$nazevWebu = $web->get('nazev_webu');
$cesta = (string) parse_url($kanonicka, PHP_URL_PATH);
$jeAktivni = fn (string $odkaz): bool => $odkaz === '' ? $cesta === $url('') : ($cesta === $url($odkaz) || str_starts_with($cesta, $url($odkaz) . '/'));
$site = array_filter(['LinkedIn' => $web->get('soc_linkedin'), 'Facebook' => $web->get('soc_facebook'), 'Instagram' => $web->get('soc_instagram'), 'YouTube' => $web->get('soc_youtube'), 'X' => $web->get('soc_x')]);
?>
<!doctype html>
<html lang="<?= e($jazyk ?? 'cs') ?>"<?= $web->get('tmavy_rezim') === 'auto' ? ' data-tmavy' : '' ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulek !== '' ? $titulek . ' – ' . $nazevWebu : $nazevWebu) ?></title>
<?php if ($meta['popis'] !== ''): ?>
<meta name="description" content="<?= e($meta['popis']) ?>">
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
<link rel="alternate" type="application/rss+xml" title="<?= e($nazevWebu) ?> – <?= e(t('Novinky')) ?>" href="<?= e($url('rss.xml')) ?>">
<link rel="stylesheet" href="<?= e($url('layout/zakladni/style.css')) ?>?v=<?= e(MIROCMS_VERSION) ?>">
<?= $hlava ?>
</head>
<body>
<a class="preskocit" href="#obsah"><?= e(t('Přeskočit na obsah')) ?></a>
<header class="hlavicka">
	<div class="obal hlavicka-obal">
		<a class="logo" href="<?= e($url('')) ?>"<?= $jeAktivni('') ? ' aria-current="page"' : '' ?>><?php if ($web->get('logo_webu') !== ''): ?><img src="<?= e((preg_match('#^(https?:)?/#', $web->get('logo_webu')) ? '' : $url('')) . $web->get('logo_webu')) ?>" alt="<?= e($nazevWebu) ?>"><?php else: ?><?= e($nazevWebu) ?><?php endif ?></a>
		<button class="menu-tl" type="button" popovertarget="navigace" aria-label="<?= e(t('Menu')) ?>"><span aria-hidden="true"></span></button>
		<nav class="navigace" id="navigace" popover aria-label="<?= e(t('Hlavní navigace')) ?>">
			<ul>
<?php foreach ($stranky as $st): ?>
				<li><a href="<?= e($url($st['seo_link'])) ?>"<?= $jeAktivni($st['seo_link']) ? ' aria-current="page"' : '' ?>><?= e($st['titulek']) ?></a></li>
<?php endforeach ?>
				<li><a href="<?= e($url('novinky')) ?>"<?= $jeAktivni('novinky') ? ' aria-current="page"' : '' ?>><?= e(t('Novinky')) ?></a></li>
			</ul>
			<?= $jazyky_html ?? '' ?>
		</nav>
	</div>
</header>
<main id="obsah" class="obal obsah">
<?= $obsah ?>
</main>
<footer class="paticka">
	<div class="obal paticka-obal">
		<div>
			<strong><?= e($nazevWebu) ?></strong>
<?php if ($web->get('popis_webu') !== ''): ?>
			<p><?= e($web->get('popis_webu')) ?></p>
<?php endif ?>
<?php if ($web->get('text_paticky') !== ''): ?>
			<p><?= e($web->get('text_paticky')) ?></p>
<?php endif ?>
<?php if ($web->get('email_webu') !== ''): ?>
			<p><a href="mailto:<?= e($web->get('email_webu')) ?>"><?= e($web->get('email_webu')) ?></a></p>
<?php endif ?>
		</div>
		<nav aria-label="<?= e(t('Odkazy v patičce')) ?>">
			<ul>
<?php foreach ($site as $nazevSite => $adresa): ?>
				<li><a href="<?= e($adresa) ?>" rel="me noopener" target="_blank"><?= e($nazevSite) ?></a></li>
<?php endforeach ?>
				<li><a href="<?= e($url('rss.xml')) ?>">RSS</a></li>
			</ul>
		</nav>
		<p class="paticka-copy">&copy; <?= date('Y') ?> <?= e($nazevWebu) ?></p>
	</div>
</footer>
<?= $pata ?>
</body>
</html>
