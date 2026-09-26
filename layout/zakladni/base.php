<?php
/**
 * Layout "zakladni" - šablona firemního webu: hlavička s logem a navigací, obsah, patička s kontakty.
 * Na telefonu se navigace otevírá přes Popover API (bez JavaScriptu). Vlastní layout = kopie této složky pod jiným názvem.
 *
 * @var Kaleta\Core\Settings $web
 * @var string $titulek  prázdný na úvodní stránce
 * @var array{hlavni:bool, popis:string, klicova_slova:string, obrazek:string, typ:string, noindex:bool, stavba?:bool} $meta  stavba = stránka z builderu (sekce přes celou šířku)
 * @var string $obsah  hotové HTML obsahu stránky (stránka, výpis novinek, novinka…)
 * @var callable(string): string $url
 * @var string $kanonicka
 * @var string $hlava  značky do <head> z Nastavení: SEO, strukturovaná data, měřicí kódy (vždy vypsat před </head>)
 * @var string $pata   cookie lišta a kódy před </body> (vždy vypsat)
 * @var string $jazyk  kód jazyka zobrazené verze webu (cs, en…) pro <html lang>
 * @var string $jazyky_html  hotový přepínač jazykových verzí; prázdný, má-li web jediný jazyk
 * @var bool $sNovinkami  je zapnuté rozšíření Novinky (odkazy na RSS)
 * @var list<array{titulek:string, seo_link:string, uvod:bool}> $stranky  stránky „v menu“ (úvodní má prázdnou adresu) – jen pro starší šablony
 * @var list<array{text:string, url:string, nove_okno:bool, deti:list<array<string, mixed>>, novinky?:bool}> $menu  hlavní menu (Vzhled → Menu), položky mohou mít podmenu
 * @var list<array<string, mixed>> $menu_paticka  menu v patičce (prázdné, dokud ho správce nesestaví)
 * @var callable(list<array<string, mixed>>, string, string): string $menu_html  položky menu jako <li> (Core\Menu::html: položky, cesta stránky, adresa úvodu)
 * @var array{hlavicka: ?string, paticka: ?string} $casti  záhlaví a patička z builderu (Vzhled → Části webu); null = kreslí je layout
 */
$nazevWebu = $web->get('nazev_webu');
$cesta = (string) parse_url($kanonicka, PHP_URL_PATH);
$jeAktivni = fn (string $odkaz): bool => $odkaz === '' ? $cesta === $url('') : ($cesta === $url($odkaz) || str_starts_with($cesta, $url($odkaz) . '/'));
$site = array_filter(['LinkedIn' => $web->get('soc_linkedin'), 'Facebook' => $web->get('soc_facebook'), 'Instagram' => $web->get('soc_instagram'), 'YouTube' => $web->get('soc_youtube'), 'X' => $web->get('soc_x')]);
?>
<!doctype html>
<?php $tmavy = in_array($web->get('tmavy_rezim'), ['auto', 'tmavy'], true); ?>
<html lang="<?= e($jazyk ?? 'cs') ?>"<?= $tmavy ? ' data-tmavy' : '' ?><?= $web->get('tmavy_rezim') === 'tmavy' ? ' data-tema="tmavy"' : '' ?>>
<head>
<meta charset="utf-8">
<?php if ($tmavy && $web->get('tmavy_prepinac') === '1'): ?>
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
<link rel="stylesheet" href="<?= e($url('layout/zakladni/style.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
<?= $hlava ?>
</head>
<body>
<a class="preskocit" href="#obsah"><?= e(t('Přeskočit na obsah')) ?></a>
<?php if (($casti['hlavicka'] ?? null) !== null): ?>
<?= $casti['hlavicka'] ?>
<?php else: ?>
<header class="hlavicka">
	<div class="obal hlavicka-obal">
		<a class="logo" href="<?= e($url('')) ?>"<?= $jeAktivni('') ? ' aria-current="page"' : '' ?>><?php if ($web->get('logo_webu') !== ''): ?><img src="<?= e(preg_match('#^(https?:)?/#', $web->get('logo_webu')) ? $web->get('logo_webu') : $url($web->get('logo_webu'))) ?>" alt="<?= e($nazevWebu) ?>"><?php else: ?><?= e($nazevWebu) ?><?php endif ?></a>
		<button class="menu-tl" type="button" popovertarget="navigace" aria-label="<?= e(t('Menu')) ?>"><span aria-hidden="true"></span></button>
		<nav class="navigace" id="navigace" popover aria-label="<?= e(t('Hlavní navigace')) ?>">
			<ul>
				<?= $menu_html($menu, $cesta, $url('')) ?>
			</ul>
			<?= $jazyky_html ?? '' ?>
		</nav>
	</div>
</header>
<?php endif ?>
<main id="obsah" class="<?= empty($meta['stavba']) ? 'obal obsah' : 'stavba' ?>">
<?= $obsah ?>
</main>
<?php if (($casti['paticka'] ?? null) !== null): ?>
<?= $casti['paticka'] ?>
<?php else: ?>
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
<?php if ($web->get('firma_email') !== ''): ?>
			<p><a href="mailto:<?= e($web->get('firma_email')) ?>"><?= e($web->get('firma_email')) ?></a></p>
<?php endif ?>
		</div>
		<nav aria-label="<?= e(t('Odkazy v patičce')) ?>">
			<ul>
<?php $plocha = []; foreach ($menu_paticka as $p) { $plocha[] = ['deti' => []] + $p; array_push($plocha, ...$p['deti']); } // v patičce bez rozbalování ?>
				<?= $menu_html($plocha, $cesta, $url('')) ?>
<?php foreach ($site as $nazevSite => $adresa): ?>
				<li><a href="<?= e($adresa) ?>" rel="me noopener" target="_blank"><?= e($nazevSite) ?></a></li>
<?php endforeach ?>
			</ul>
		</nav>
		<p class="paticka-copy">&copy; <?= date('Y') ?> <?= e($nazevWebu) ?></p>
	</div>
</footer>
<?php endif ?>
<?= $pata ?>
</body>
</html>
