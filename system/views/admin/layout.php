<?php
/**
 * The admin frame: menu, login strip, section heading, messages, content.
 *
 * @var Kaleta\Core\App $app
 * @var string $heading
 * @var string $content  ready-made HTML of the module
 * @var array<string, class-string<Kaleta\Admin\Module>> $modules
 * @var string $active
 * @var array<string, mixed>|null $user
 * @var list<array{typ:string, text:string}> $flashes
 */
$icon = require __DIR__ . '/icons.php';
$onDashboard = $active === '' && (string) $app->request->get('action') === ''; // My account (akce=ucet) is not the Dashboard

// command palette (Ctrl/⌘+K): only where the signed-in user may go – the module list already follows permissions
$statements = [];
if ($user !== null) {
    $adminUrl = fn (string $query = ''): string => $app->url('admin.php' . ($query !== '' ? '?' . $query : ''));
    $statements[] = ['n' => t('Dashboard'), 'u' => $adminUrl(), 's' => ''];
    foreach ($modules as $ident => $class) {
        $statements[] = ['n' => t($class::NAME), 'u' => $adminUrl('module=' . $ident), 's' => t($class::GROUP)];
    }
    $quick = [
        'pages' => [['New page', 'module=pages&action=new']],
        'news' => [['New news item', 'module=news&action=new'], ['Broken links', 'module=news&action=links']],
        'categories' => [['New category', 'module=categories&action=new']],
        'users' => [['New user', 'module=users&action=new']],
        'transfer' => [['Import from WordPress', 'module=transfer']],
    ];
    foreach ($quick as $ident => $items) {
        foreach (isset($modules[$ident]) ? $items : [] as [$name, $query]) {
            $statements[] = ['n' => t($name), 'u' => $adminUrl($query), 's' => t($modules[$ident]::NAME)];
        }
    }
    foreach (isset($modules['settings']) ? Kaleta\Admin\Modules\Settings::TABS : [] as $key => $name) {
        $statements[] = ['n' => t('Nastavení') . ' → ' . t($name), 'u' => $adminUrl('module=settings&tab=' . $key), 's' => t('Nastavení')];
    }
    // site pages can be found in the palette by name (news is searched on the server, there are more of them)
    foreach (isset($modules['pages']) ? $app->db()->all('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL ORDER BY poradi, titulek LIMIT 300') : [] as $pageRow) {
        $statements[] = ['n' => $pageRow['titulek'], 'u' => $adminUrl('module=pages&action=edit&id=' . (int) $pageRow['ids']), 's' => t('Page')];
    }
    $statements[] = ['n' => t('My account'), 'u' => $adminUrl('action=account'), 's' => ''];
    $statements[] = ['n' => t('Zobrazit web'), 'u' => $app->url(''), 's' => ''];
}
?>
<!doctype html>
<html lang="<?= e(Kaleta\Core\Language::code()) ?>" data-pasmo="<?= e(date_default_timezone_get()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<script src="<?= e($app->url('image/tema.js')) ?>?v=<?= e(KALETA_VERSION) ?>"></script>
<title><?= $heading !== '' ? e($heading) . ' – ' : '' ?>Kaleta</title>
<link rel="icon" type="image/svg+xml" href="<?= e($app->url('')) ?>image/kaleta-znacka.svg">
<link rel="alternate icon" type="image/png" sizes="32x32" href="<?= e($app->url('')) ?>image/kaleta-znacka-32.png">
<link rel="apple-touch-icon" href="<?= e($app->url('')) ?>image/kaleta-znacka-180.png">
<link rel="stylesheet" href="<?= e($app->url('image/admin.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
</head>
<body>
<?php if ($user !== null): ?>
<a class="preskocit" href="#obsah"><?= e(t('Skip to content')) ?></a>
<header class="hlavicka">
	<a class="znacka" href="<?= e($app->url('admin.php')) ?>" aria-label="Kaleta – <?= e(t('Dashboard')) ?>"><?= $app->view->render('admin/logo', ['height' => 28]) ?></a>
	<button class="menu-prepinac" type="button" aria-expanded="false" aria-controls="menu"><?= e(t('Menu')) ?></button>
	<nav class="menu-obal" aria-label="<?= e(t('Main menu')) ?>">
	<ul class="menu" id="menu">
		<li class="menu-prehled<?= $onDashboard ? ' aktivni' : '' ?>"><a href="<?= e($app->url('admin.php')) ?>"<?= $onDashboard ? ' aria-current="page"' : '' ?>><?= $icon('prehled') ?><?= e(t('Dashboard')) ?></a></li>
<?php $group = ''; $inMenu = isset($modules[$active]) && $modules[$active]::PARENT !== '' ? $modules[$active]::PARENT : $active; ?>
<?php foreach ($modules as $ident => $class): if ($class::PARENT !== '' && isset($modules[$class::PARENT])) { continue; } ?>
<?php if ($class::GROUP !== $group): $group = $class::GROUP; ?>
		<li class="menu-skupina" aria-hidden="true"><?= e(t($group)) ?></li>
<?php endif ?>
		<li<?= $ident === $inMenu ? ' class="aktivni"' : '' ?>><a href="<?= e($app->url('admin.php?module=' . $ident)) ?>"<?= $ident === $inMenu ? ' aria-current="page"' : '' ?>><?= $icon($class::ICON) ?><?= e(t($class::NAME)) ?></a></li>
<?php endforeach ?>
		<li class="menu-web"><a href="<?= e($app->url('')) ?>" target="_blank" rel="noopener"><?= $icon('web') ?><?= e(t('Zobrazit web')) ?></a></li>
		<li class="menu-logout"><form method="post" action="<?= e($app->url('admin.php?action=logout')) ?>"><?= $app->session->csrfField() ?><button type="submit"><?= $icon('odhlasit') ?><?= e(t('Sign out')) ?></button></form></li>
	</ul>
	</nav>
</header>
<section class="loginprouzek" aria-label="<?= e(t('Account and tools')) ?>">
	<button class="paleta-spustit" type="button" data-paleta title="<?= e(t('Quick search and commands')) ?>"><span><?= e(t('Search…')) ?></span> <kbd>Ctrl K</kbd></button>
	<button class="tema-prepinac" type="button" data-tema-prepinac title="<?= e(t('Light / dark mode')) ?>" aria-label="<?= e(t('Toggle light and dark mode')) ?>"><?= $icon('tema') ?></button>
	<a class="prihlasen" href="<?= e($app->url('admin.php?action=account')) ?>" title="<?= e(t('My account')) ?>" aria-label="<?= e(t('My account') . ' – ' . ($user['jmeno'] ?: $user['user'])) ?>"><span class="avatar" title="<?= e(($user['jmeno'] ?: $user['user']) . ' – ' . t(Kaleta\Core\Auth::TYPES[(int) $user['admin']] ?? '')) ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['jmeno'] ?: $user['user'], 0, 1))) ?></span></a>
</section>
<?php endif ?>
<?php if ($statements !== []): ?>
<dialog class="paleta" id="paleta" aria-label="<?= e(t('Quick search and commands')) ?>"<?= isset($modules['news']) ? ' data-clanky="' . e($app->url('admin.php?module=news&action=search_json&edit=1')) . '"' : '' ?>>
	<input class="paleta-pole" type="search" autocomplete="off" spellcheck="false" placeholder="<?= e(t('Where do you want to go? Type the name of a section, action, page or news item…')) ?>" aria-label="<?= e(t('Quick search and commands')) ?>" aria-controls="paleta-seznam">
	<ul class="paleta-seznam" id="paleta-seznam" role="listbox"></ul>
	<p class="paleta-napoveda"><kbd>↑</kbd> <kbd>↓</kbd> <?= e(t('výběr')) ?> · <kbd>Enter</kbd> <?= e(t('open')) ?> · <kbd>Esc</kbd> <?= e(t('close')) ?></p>
	<script type="application/json" id="paleta-data"><?= json_encode($statements, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
</dialog>
<?php endif ?>
<main class="obsah" id="obsah" tabindex="-1">
<?php if (Kaleta\Core\Demo::active()): ?>
<p class="hlaska hlaska-varovani" role="status"><?= e(t('Public demo: everything you change here is reset in %s minutes. E-mail, imports, updates, users and the Claude connection are switched off.', (string) max(1, (int) ceil(Kaleta\Core\Demo::secondsToReset() / 60)))) ?></p>
<?php endif ?>
<?php if ($heading !== ''): $guide = $user !== null ? Kaleta\Admin\Guide::forScreen($active === '' && $app->request->get('action') === 'account' ? 'account' : $active, '', (string) $app->request->get('tab'), Kaleta\Core\Language::code()) : null; ?>
<div class="zahlavi-stranky">
<h1><?= e($heading) ?></h1>
<?php if ($guide !== null): ?>
<?= $app->view->render('admin/guide_link', ['url' => $guide]) ?>
<?php endif ?>
</div>
<?php endif ?>
<?php foreach ($flashes as $message): ?>
<p class="hlaska hlaska-<?= e($message['typ']) ?>" role="status"><?= Kaleta\Admin\MenuPaths::links($app->url('admin.php'), t($message['text']), array_keys($modules)) ?></p>
<?php endforeach ?>
<?php if ($app->auth()->isAdmin() && Kaleta\Core\Look::hasDraft($app->settings())): // a draft look waits on every screen until it is published or discarded ?>
<?= $app->view->render('admin/look_bar', ['app' => $app, 'summary' => Kaleta\Core\Look::summary($app->db(), $app->settings()), 'areas' => Kaleta\Core\Look::areas($app->settings()), 'csrf' => $app->session->csrfField()]) ?>
<?php endif ?>
<?= $content ?>
<?= $app->view->render('admin/agency', ['app' => $app, 'withLogo' => false]) ?>
<footer class="verze">Kaleta <?= e(KALETA_VERSION) ?> · <?= e(t('Kaleta is free and has no ads.')) ?>
	<a class="verze-podpora" href="https://kaletacms.com/why-free" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg><?= e(t('Support Kaleta')) ?></a></footer>
</main>
<?php if (is_file(KALETA_ROOT . '/image/jazyky/admin-' . Kaleta\Core\Language::code() . '.js')): ?>
<script src="<?= e($app->url('image/jazyky/admin-' . Kaleta\Core\Language::code() . '.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php if (Kaleta\Core\Language::register() === 'informal' && is_file(KALETA_ROOT . '/image/jazyky/admin-' . Kaleta\Core\Language::code() . '-du.js')): ?>
<script src="<?= e($app->url('image/jazyky/admin-' . Kaleta\Core\Language::code() . '-du.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php endif ?>
<?php endif ?>
<script src="<?= e($app->url('image/admin.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= e(KALETA_VERSION) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" data-max-soubor="<?= Kaleta\Core\Files::limit() ?>" data-max-soubor-text="<?= e(Kaleta\Core\Files::limitText()) ?>" data-max-strana="<?= Kaleta\Core\Images::MAX_SIDE ?>" defer></script>
</body>
</html>
