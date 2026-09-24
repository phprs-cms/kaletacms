<?php
/**
 * Rámec administrace: menu, login proužek, nadpis sekce, hlášky, obsah.
 *
 * @var MiroCMS\Core\App $app
 * @var string $nadpis
 * @var string $obsah  hotové HTML modulu
 * @var array<string, class-string<MiroCMS\Admin\Modul>> $moduly
 * @var string $aktivni
 * @var array<string, mixed>|null $user
 * @var list<array{typ:string, text:string}> $hlasky
 */
$ikona = require __DIR__ . '/ikony.php';
$naPrehledu = $aktivni === '' && (string) $app->request->get('akce') === ''; // Můj účet (akce=ucet) není Přehled

// paleta příkazů (Ctrl/⌘+K): jen to, kam přihlášený smí – seznam modulů už je podle práv
$prikazy = [];
if ($user !== null) {
    $adm = fn (string $dotaz = ''): string => $app->url('admin.php' . ($dotaz !== '' ? '?' . $dotaz : ''));
    $prikazy[] = ['n' => t('Přehled'), 'u' => $adm(), 's' => ''];
    foreach ($moduly as $ident => $class) {
        $prikazy[] = ['n' => t($class::NAZEV), 'u' => $adm('modul=' . $ident), 's' => t($class::SKUPINA)];
    }
    $rychle = [
        'stranky' => [['Nová stránka', 'modul=stranky&akce=novy']],
        'novinky' => [['Nová novinka', 'modul=novinky&akce=novy'], ['Nefunkční odkazy', 'modul=novinky&akce=odkazy']],
        'kategorie' => [['Nová kategorie', 'modul=kategorie&akce=novy']],
        'users' => [['Nový uživatel', 'modul=users&akce=novy']],
        'prenos' => [['Import z WordPressu', 'modul=prenos']],
    ];
    foreach ($rychle as $ident => $polozky) {
        foreach (isset($moduly[$ident]) ? $polozky : [] as [$nazev, $dotaz]) {
            $prikazy[] = ['n' => t($nazev), 'u' => $adm($dotaz), 's' => t($moduly[$ident]::NAZEV)];
        }
    }
    foreach (isset($moduly['config']) ? MiroCMS\Admin\Moduly\Konfigurace::ZALOZKY : [] as $klic => $nazev) {
        $prikazy[] = ['n' => t('Nastavení') . ' → ' . t($nazev), 'u' => $adm('modul=config&zalozka=' . $klic), 's' => t('Nastavení')];
    }
    // stránky webu jdou v paletě najít podle názvu (novinky se hledají na serveru, je jich víc)
    foreach (isset($moduly['stranky']) ? $app->db()->all('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL ORDER BY poradi, titulek LIMIT 300') : [] as $st) {
        $prikazy[] = ['n' => $st['titulek'], 'u' => $adm('modul=stranky&akce=edit&id=' . (int) $st['ids']), 's' => t('Stránka')];
    }
    $prikazy[] = ['n' => t('Můj účet'), 'u' => $adm('akce=ucet'), 's' => ''];
    $prikazy[] = ['n' => t('Zobrazit web'), 'u' => $app->url(''), 's' => ''];
}
?>
<!doctype html>
<html lang="<?= e(MiroCMS\Core\Jazyk::kod()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/tema.js')) ?>?v=<?= e(MIROCMS_VERSION) ?>"></script>
<title><?= $nadpis !== '' ? e($nadpis) . ' – ' : '' ?>MiroCMS</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/mirocms-znacka.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($app->url('')) ?>image/mirocms-znacka-32.png">
<link rel="apple-touch-icon" href="<?= e($app->url('')) ?>image/mirocms-znacka-180.png">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(MIROCMS_VERSION) ?>">
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= e(MIROCMS_VERSION) ?>">
</head>
<body>
<?php if ($user !== null): ?>
<header class="hlavicka">
	<a class="znacka" href="<?= e($app->url('admin.php')) ?>" aria-label="MiroCMS – <?= e(t('Přehled')) ?>"><?= $app->view->render('admin/logo', ['vyska' => 28]) ?></a>
	<button class="menu-prepinac" type="button" aria-expanded="false" aria-controls="menu"><?= e(t('Menu')) ?></button>
	<ul class="menu" id="menu">
		<li class="menu-prehled<?= $naPrehledu ? ' aktivni' : '' ?>"><a href="<?= e($app->url('admin.php')) ?>"<?= $naPrehledu ? ' aria-current="page"' : '' ?>><?= $ikona('prehled') ?><?= e(t('Přehled')) ?></a></li>
<?php $skupina = ''; $vMenu = isset($moduly[$aktivni]) && $moduly[$aktivni]::NADRAZENY !== '' ? $moduly[$aktivni]::NADRAZENY : $aktivni; ?>
<?php foreach ($moduly as $ident => $class): if ($class::NADRAZENY !== '' && isset($moduly[$class::NADRAZENY])) { continue; } ?>
<?php if ($class::SKUPINA !== $skupina): $skupina = $class::SKUPINA; ?>
		<li class="menu-skupina" aria-hidden="true"><?= e(t($skupina)) ?></li>
<?php endif ?>
		<li<?= $ident === $vMenu ? ' class="aktivni"' : '' ?>><a href="<?= e($app->url('admin.php?modul=' . $ident)) ?>"<?= $ident === $vMenu ? ' aria-current="page"' : '' ?>><?= $ikona($class::IKONA) ?><?= e(t($class::NAZEV)) ?></a></li>
<?php endforeach ?>
		<li class="menu-web"><a href="<?= e($app->url('')) ?>" target="_blank" rel="noopener"><?= $ikona('web') ?><?= e(t('Zobrazit web')) ?></a></li>
		<li class="menu-logout"><form method="post" action="<?= e($app->url('admin.php?akce=logout')) ?>"><?= $app->session->csrfField() ?><button type="submit"><?= $ikona('odhlasit') ?><?= e(t('Odhlásit se')) ?></button></form></li>
	</ul>
</header>
<div class="loginprouzek">
	<button class="paleta-spustit" type="button" data-paleta title="<?= e(t('Rychlé hledání a příkazy')) ?>"><span><?= e(t('Hledat…')) ?></span> <kbd>Ctrl K</kbd></button>
	<button class="tema-prepinac" type="button" data-tema-prepinac title="<?= e(t('Světlý / tmavý režim')) ?>" aria-label="<?= e(t('Přepnout světlý a tmavý režim')) ?>"><?= $ikona('tema') ?></button>
	<a class="prihlasen" href="<?= e($app->url('admin.php?akce=ucet')) ?>" title="<?= e(t('Můj účet')) ?>" aria-label="<?= e(t('Můj účet') . ' – ' . ($user['jmeno'] ?: $user['user'])) ?>"><span class="avatar" title="<?= e(($user['jmeno'] ?: $user['user']) . ' – ' . t(MiroCMS\Core\Auth::TYPY[(int) $user['admin']] ?? '')) ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['jmeno'] ?: $user['user'], 0, 1))) ?></span></a>
</div>
<?php endif ?>
<?php if ($prikazy !== []): ?>
<dialog class="paleta" id="paleta" aria-label="<?= e(t('Rychlé hledání a příkazy')) ?>"<?= isset($moduly['novinky']) ? ' data-clanky="' . e($app->url('admin.php?modul=novinky&akce=hledej_json&uprava=1')) . '"' : '' ?>>
	<input class="paleta-pole" type="search" autocomplete="off" spellcheck="false" placeholder="<?= e(t('Kam chcete jít? Napište název sekce, akce, stránky nebo novinky…')) ?>" aria-label="<?= e(t('Rychlé hledání a příkazy')) ?>" aria-controls="paleta-seznam">
	<ul class="paleta-seznam" id="paleta-seznam" role="listbox"></ul>
	<p class="paleta-napoveda"><kbd>↑</kbd> <kbd>↓</kbd> <?= e(t('výběr')) ?> · <kbd>Enter</kbd> <?= e(t('otevřít')) ?> · <kbd>Esc</kbd> <?= e(t('zavřít')) ?></p>
	<script type="application/json" id="paleta-data"><?= json_encode($prikazy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</dialog>
<?php endif ?>
<main class="obsah">
<?php if ($nadpis !== ''): ?>
<div class="zahlavi-stranky">
<h2><?= e($nadpis) ?></h2>
</div>
<?php endif ?>
<?php foreach ($hlasky as $hlaska): ?>
<p class="hlaska hlaska-<?= e($hlaska['typ']) ?>" role="status"><?= MiroCMS\Admin\Cesty::odkazy($app->url('admin.php'), t($hlaska['text']), array_keys($moduly)) ?></p>
<?php endforeach ?>
<?= $obsah ?>
<footer class="verze">MiroCMS <?= e(MIROCMS_VERSION) ?></footer>
</main>
<?php if (MiroCMS\Core\Jazyk::kod() !== 'cs' && is_file(MIROCMS_ROOT . '/image/jazyky/admin-' . MiroCMS\Core\Jazyk::kod() . '.js')): ?>
<script src="<?= e($app->url('image/jazyky/admin-' . MiroCMS\Core\Jazyk::kod() . '.js')) ?>?v=<?= e(MIROCMS_VERSION) ?>" defer></script>
<?php endif ?>
<script src="<?= e($app->url('image/admin.js')) ?>?v=<?= e(MIROCMS_VERSION) ?>" defer></script>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= e(MIROCMS_VERSION) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" defer></script>
</body>
</html>
