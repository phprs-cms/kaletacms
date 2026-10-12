<?php
/**
 * The frame of every page (Kaleta is themeless since 1.6): head, the header and footer site parts from the builder (or the
 * built-in ones), the content. The look comes from the design system and image/sablona.css. On a phone the navigation
 * opens via the Popover API (no JavaScript).
 *
 * @var Kaleta\Core\Settings $web
 * @var string $titulek  empty on the home page
 * @var array{hlavni:bool, popis:string, klicova_slova:string, obrazek:string, typ:string, noindex:bool, stavba?:bool} $meta  stavba = a page from the builder (full-width sections)
 * @var string $obsah  ready-made HTML of the page content (page, news list, news item…)
 * @var string $oznameni  the notice bar of exceptions to the opening hours (2.10), or empty
 * @var callable(string): string $url
 * @var string $kanonicka
 * @var string $hlava  tags for <head> from Settings: SEO, structured data, tracking codes (always output before </head>)
 * @var string $pata   cookie bar and codes before </body> (always output)
 * @var string $jazyk  language code of the displayed version of the site (cs, en…) for <html lang> and <html dir> (rtl for Arabic)
 * @var string $jazyky_html  ready-made language version switcher; empty if the site has a single language
 * @var bool $sNovinkami  the News extension is enabled (links to RSS)
 * @var list<array{titulek:string, seo_link:string, uvod:bool}> $stranky  pages "in the menu" (the home page has an empty slug) – only for older templates
 * @var list<array{text:string, url:string, nove_okno:bool, deti:list<array<string, mixed>>, novinky?:bool}> $menu  main menu (Vzhled → Menu), items may have a submenu
 * @var list<array<string, mixed>> $menu_paticka  footer menu (empty until the administrator builds it)
 * @var callable(list<array<string, mixed>>, string, string): string $menu_html  menu items as <li> (Core\Menu::html: items, page path, home page url)
 * @var array{hlavicka: ?string, paticka: ?string} $casti  header and footer from the builder (Vzhled → Části webu, i.e. Appearance → Site parts); null = the layout draws them
 */
$nazevWebu = $web->get('site_name');
$cesta = (string) parse_url($kanonicka, PHP_URL_PATH);
$jeAktivni = fn (string $odkaz): bool => $odkaz === '' ? $cesta === $url('') : ($cesta === $url($odkaz) || str_starts_with($cesta, $url($odkaz) . '/'));
$site = array_filter(['LinkedIn' => $web->get('social_linkedin'), 'Facebook' => $web->get('social_facebook'), 'Instagram' => $web->get('social_instagram'), 'YouTube' => $web->get('social_youtube'), 'X' => $web->get('social_x')]);
?>
<!doctype html>
<?php $tmavy = in_array($web->get('dark_mode'), ['auto', 'tmavy'], true); ?>
<html lang="<?= e(Kaleta\Core\Language::tag($jazyk ?? 'cs')) ?>"<?= Kaleta\Core\Language::dirAttribute($jazyk ?? 'cs') ?><?= $tmavy ? ' data-tmavy' : '' ?><?= $web->get('dark_mode') === 'tmavy' ? ' data-tema="tmavy"' : '' ?>>
<head>
<meta charset="utf-8">
<?php if ($tmavy && $web->get('theme_switcher') === '1'): ?>
<script>try{var t=localStorage.getItem('ka-tema'),r=document.documentElement;if(t==='auto')r.removeAttribute('data-tema');else if(t==='svetly'||t==='tmavy')r.setAttribute('data-tema',t)}catch(e){}</script>
<?php endif ?>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($titulek === '' ? $nazevWebu : (str_contains(mb_strtolower($titulek), mb_strtolower($nazevWebu)) ? $titulek : $titulek . ' – ' . $nazevWebu)) ?></title>
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
<meta property="og:url" content="<?= e($kanonicka) ?>">
<?php if ($meta['obrazek'] !== ''): ?>
<meta property="og:image" content="<?= e($meta['obrazek']) ?>">
<?php endif ?>
<?php if ($sNovinkami ?? true): ?>
<link rel="alternate" type="application/rss+xml" title="<?= e($nazevWebu) ?> – <?= e(t('Novinky')) ?>" href="<?= e($url('rss.xml')) ?>">
<?php endif ?>
<link rel="stylesheet" href="<?= e($url('image/sablona.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
<?= $hlava ?>
</head>
<body>
<a class="preskocit" href="#obsah"><?= e(t('Skip to content')) ?></a>
<?= $oznameni ?? '' ?>
<?php if (($casti['hlavicka'] ?? null) !== null): ?>
<?= $casti['hlavicka'] ?>
<?php else: ?>
<header class="hlavicka">
	<div class="obal hlavicka-obal">
		<a class="logo" href="<?= e($url('')) ?>"<?= $jeAktivni('') ? ' aria-current="page"' : '' ?>><?php $logo = \Kaleta\Core\Look::identity($web, 'logo'); if ($logo !== ''): ?><img src="<?= e(preg_match('#^(https?:)?/#', $logo) ? $logo : $url($logo)) ?>" alt="<?= e($nazevWebu) ?>"<?= \Kaleta\Front\ImageHtml::logoSize($logo) ?>><?php else: ?><?= e($nazevWebu) ?><?php endif ?></a>
		<button class="menu-tl" type="button" popovertarget="navigace" aria-label="<?= e(t('Menu')) ?>"><span aria-hidden="true"></span></button>
		<nav class="navigace" id="navigace" popover aria-label="<?= e(t('Main navigation')) ?>">
			<ul>
				<?= $menu_html($menu, $cesta, $url('')) ?>
			</ul>
			<?= $jazyky_html ?? '' ?>
		</nav>
	</div>
</header>
<?php endif ?>
<main id="obsah" class="<?= empty($meta['stavba']) ? 'obal obsah' : 'stavba' ?>" tabindex="-1">
<?= $obsah ?>
</main>
<?php if (($casti['paticka'] ?? null) !== null): ?>
<?= $casti['paticka'] ?>
<?php else: ?>
<footer class="paticka">
	<div class="obal paticka-obal">
		<div>
			<strong><?= e($nazevWebu) ?></strong>
<?php if ($web->get('site_description') !== ''): ?>
			<p><?= e($web->get('site_description')) ?></p>
<?php endif ?>
<?php if ($web->get('footer_text') !== ''): ?>
			<p><?= e($web->get('footer_text')) ?></p>
<?php endif ?>
<?php if ($web->get('company_email') !== ''): ?>
			<p><a href="mailto:<?= e($web->get('company_email')) ?>"><?= e($web->get('company_email')) ?></a></p>
<?php endif ?>
		</div>
<?php $plocha = []; foreach ($menu_paticka as $p) { $plocha[] = ['deti' => []] + $p; array_push($plocha, ...$p['deti']); } // no expanding in the footer ?>
<?php $odkazyPaticky = trim($menu_html($plocha, $cesta, $url(''))); ?>
<?php if ($odkazyPaticky !== '' || $site !== []): // an empty navigation landmark is only noise for screen readers ?>
		<nav aria-label="<?= e(t('Footer links')) ?>">
			<ul>
				<?= $odkazyPaticky ?>
<?php foreach ($site as $nazevSite => $adresa): ?>
				<li><a href="<?= e($adresa) ?>" rel="me noopener" target="_blank"><?= e($nazevSite) ?></a></li>
<?php endforeach ?>
			</ul>
		</nav>
<?php endif ?>
		<p class="paticka-copy">&copy; <?= date('Y') ?> <?= e($nazevWebu) ?></p>
	</div>
</footer>
<?php endif ?>
<?= $pata ?>
</body>
</html>
