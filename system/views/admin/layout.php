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
 * @var bool $gated  3.19: the user is held at the required two-factor gate (no menu, no palette)
 */
$icon = require __DIR__ . '/icons.php';
$onDashboard = $active === '' && (string) $app->request->get('action') === ''; // My account (akce=ucet) is not the Dashboard

// 3.18.3 (owner's request): a collection can have its own menu item – a direct link to its items, right under Collections,
// for whoever may open Collections (no per-collection permissions exist). At most Collections::MENU_LIMIT of them, by name;
// the item is current on every screen of that collection (its items, an item, its settings…) instead of Collections.
$collections = $user !== null && isset($modules['collections']) ? Kaleta\Admin\Modules\Collections::menuCollections($app) : [];
// 3.25: at most MENU_LIMIT (5) of them, then "More…" to the list of collections
$wantMenu = array_values(array_filter($collections, static fn (array $c): bool => $c['menu']));
$menuCollections = array_slice($wantMenu, 0, Kaleta\Admin\Modules\Collections::MENU_LIMIT);
$moreCollections = count($wantMenu) > count($menuCollections);
$currentCollection = $active === 'collections' && !in_array((string) $app->request->get('action'), ['', 'list', 'new', 'preset'], true)
    && in_array($app->request->getInt('id'), array_column($menuCollections, 'id'), true) ? $app->request->getInt('id') : 0;

// command palette (Ctrl/⌘+K): only where the signed-in user may go – the module list already follows permissions
$statements = [];
if ($user !== null && empty($gated)) { // 3.19 (N3183-2): nothing to search at the two-factor gate
    $adminUrl = fn (string $query = ''): string => $app->url('admin.php' . ($query !== '' ? '?' . $query : ''));
    $statements[] = ['n' => t('Dashboard'), 'u' => $adminUrl(), 's' => ''];
    foreach ($modules as $ident => $class) {
        $statements[] = ['n' => t($class::NAME), 'u' => $adminUrl('module=' . $ident), 's' => t($class::GROUP)];
    }
    $quick = [
        'pages' => [['New page', 'module=pages&action=new']],
        'contacts' => [['New customer', 'module=contacts&action=new'], ['Companies', 'module=contacts&action=companies']], // 3.28
        'pipeline' => [['New deal', 'module=pipeline&action=new'], ['Deals as a list', 'module=pipeline&view=list']], // 3.31
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
    // every collection's items by its name (3.18.3) – also those left out of the menu
    foreach ($collections as $collection) {
        $statements[] = ['n' => $collection['name'], 'u' => $adminUrl('module=collections&action=items&id=' . $collection['id']), 's' => t('Collections')];
    }
    // 3.23 (C8): the console tab only where the Settings row shows it (a named agency or a pairing)
    foreach (isset($modules['settings']) ? array_intersect_key(Kaleta\Admin\Modules\Settings::TABS, Kaleta\Admin\Modules\Settings::visibleTabs($app->settings()) + Kaleta\Admin\Modules\Settings::MOVED_TABS) : [] as $key => $name) {
        $statements[] = ['n' => t('Settings') . ' → ' . t($name), 'u' => $adminUrl('module=settings&tab=' . $key), 's' => t('Settings')];
    }
    // site pages can be found in the palette by name (news is searched on the server, there are more of them)
    foreach (isset($modules['pages']) ? $app->db()->all('SELECT ids, titulek FROM {stranky} WHERE smazano IS NULL ORDER BY poradi, ' . $app->db()->sortText('titulek') . ' LIMIT 300') : [] as $pageRow) {
        $statements[] = ['n' => $pageRow['titulek'], 'u' => $adminUrl('module=pages&action=edit&id=' . (int) $pageRow['ids']), 's' => t('Page')];
    }
    $statements[] = ['n' => t('My account'), 'u' => $adminUrl('action=account'), 's' => ''];
    $statements[] = ['n' => t('Notifications'), 'u' => $adminUrl('action=inbox'), 's' => '']; // 3.26
    $statements[] = ['n' => t('Zobrazit web'), 'u' => $app->url(''), 's' => ''];
}
// 3.26: the bell – unread notifications of the signed-in person (Core\Inbox, one COUNT over its key); not at the two-factor gate
$bell = $user !== null && empty($gated) ? Kaleta\Core\Inbox::unreadCount($app->db(), (int) $user['idu']) : null;
$onInbox = $active === '' && (string) $app->request->get('action') === 'inbox';
// 3.18: whether Claude is connected (Core\ClaudeConnection) – for whoever may use Ask Claude or the Claude settings, with the
// Claude connection on and not in the public demo (as Ask Claude on the dashboard); it never breaks the frame
$claudeChip = null;
if ($user !== null && (isset($modules['requests']) || isset($modules['claude_settings'])) && Kaleta\Core\Extensions::isEnabled($app->settings(), 'claude') && !Kaleta\Core\Demo::active()) {
    try {
        $claudeChip = ['claude' => Kaleta\Core\ClaudeConnection::status($app->db(), $app->settings()), 'url' => $app->url('admin.php?module=' . (isset($modules['claude_settings']) ? 'claude_settings' : 'requests'))];
    } catch (\Throwable) {
        $claudeChip = null;
    }
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
<?php
// 3.24: My work is the first item for everyone but administrators, who keep the Dashboard first and have My work right after it
$myWorkItem = '';
if (isset($modules['my_work'])) {
    try { $myWorkBadge = $modules['my_work']::badge($app); } catch (\Throwable) { $myWorkBadge = 0; }
    $myWorkItem = '<li class="menu-moje' . ($active === 'my_work' ? ' aktivni' : '') . '"><a href="' . e($app->url('admin.php?module=my_work')) . '"' . ($active === 'my_work' ? ' aria-current="page"' : '') . '>' . $icon($modules['my_work']::ICON) . e(t($modules['my_work']::NAME))
        . ($myWorkBadge > 0 ? ' <span class="menu-pocet" aria-hidden="true">' . ($myWorkBadge > 99 ? '99+' : $myWorkBadge) . '</span><span class="navod-skryte"> – ' . e(t('%d assigned to you', $myWorkBadge)) . '</span>' : '') . '</a></li>' . "\n";
}
$myWorkFirst = !$app->auth()->isAdmin();
?>
<?= $myWorkFirst ? $myWorkItem : '' ?>
		<li class="menu-prehled<?= $onDashboard ? ' aktivni' : '' ?>"><a href="<?= e($app->url('admin.php')) ?>"<?= $onDashboard ? ' aria-current="page"' : '' ?>><?= $icon('prehled') ?><?= e(t('Dashboard')) ?></a></li>
<?= $myWorkFirst ? '' : $myWorkItem ?>
<?php
// 3.25: the items by group (Admin\MenuGroups) – each group a collapsible <details>, the one with the current screen open;
// eight items or fewer in all make a flat list without group headers. A parent stands for its hidden screens only when
// the person sees it (Subscribers without Newsletters is its own item).
$inMenu = isset($modules[$active]) && $modules[$active]::PARENT !== '' && isset($modules[$modules[$active]::PARENT]) ? $modules[$active]::PARENT : $active;
$menuFlat = Kaleta\Admin\MenuGroups::flat($modules);
$menuItem = function (string $ident, int &$badge) use ($modules, $app, $icon, $inMenu, $currentCollection, $menuCollections, $moreCollections): string {
    $class = $modules[$ident];
    try { $badge = $class::badge($app); } catch (\Throwable) { $badge = 0; } // 3.15: a count never breaks the menu
    $here = $ident === $inMenu && !($ident === 'collections' && $currentCollection > 0);
    $html = '<li' . ($here ? ' class="aktivni"' : '') . '><a href="' . e($app->url('admin.php?module=' . $ident)) . '"' . ($here ? ' aria-current="page"' : '') . '>' . $icon($class::ICON) . e(t($class::NAME))
        . ($badge > 0 ? ' <span class="menu-pocet" aria-hidden="true">' . ($badge > 99 ? '99+' : $badge) . '</span><span class="navod-skryte"> – ' . e(t('%d new', $badge)) . '</span>' : '') . '</a></li>' . "\n";
    foreach ($ident === 'collections' ? $menuCollections : [] as $collection) {
        $here = $collection['id'] === $currentCollection;
        $html .= '<li class="menu-kolekce' . ($here ? ' aktivni' : '') . '"><a href="' . e($app->url('admin.php?module=collections&action=items&id=' . $collection['id'])) . '"' . ($here ? ' aria-current="page"' : '') . '>'
            . ($collection['icon'] !== null ? Kaleta\Builder\Icons::svg($collection['icon'], 'ikona') : $icon('polozky')) . '<span>' . e($collection['name']) . '</span></a></li>' . "\n";
    }
    if ($ident === 'collections' && $moreCollections) {
        $html .= '<li class="menu-kolekce menu-kolekce-dalsi"><a href="' . e($app->url('admin.php?module=collections')) . '">' . $icon('polozky') . '<span>' . e(t('More…')) . '</span></a></li>' . "\n";
    }

    return $html;
};
foreach (Kaleta\Admin\MenuGroups::groups($modules) as $menuGroup):
    $groupHtml = '';
    $groupBadge = 0;
    foreach ($menuGroup['items'] as $ident) {
        $badge = 0;
        $groupHtml .= $menuItem($ident, $badge);
        $groupBadge += $badge;
    }
    if ($menuFlat): ?>
<?= $groupHtml ?>
<?php else: $groupHere = in_array($inMenu, $menuGroup['items'], true); ?>
		<li class="menu-sekce"><details class="menu-skupiny" data-menu-skupina="<?= e(Kaleta\Admin\MenuGroups::key($menuGroup['group'])) ?>"<?= $groupHere ? ' data-aktualni' : '' ?><?= $menuGroup['group'] === Kaleta\Admin\MenuGroups::OPEN_AT_SIGN_IN ? ' data-menu-vychozi' : '' ?> open><summary class="menu-skupina"><span><?= e(t($menuGroup['group'])) ?></span><?php if ($groupBadge > 0): ?><span class="menu-skupina-pocet"><span class="menu-pocet" aria-hidden="true"><?= $groupBadge > 99 ? '99+' : $groupBadge ?></span><span class="navod-skryte"> – <?= e(t('%d new', $groupBadge)) ?></span></span><?php endif ?></summary>
		<ul class="menu-cast">
<?= $groupHtml ?>
		</ul></details></li>
<?php endif ?>
<?php endforeach ?>
		<li class="menu-web"><a href="<?= e($app->url('')) ?>" target="_blank" rel="noopener"><?= $icon('web') ?><?= e(t('Zobrazit web')) ?></a></li>
		<li class="menu-logout"><form method="post" action="<?= e($app->url('admin.php?action=logout')) ?>"><?= $app->session->csrfField() ?><button type="submit"><?= $icon('odhlasit') ?><?= e(t('Sign out')) ?></button></form></li>
	</ul>
<?php if (!$menuFlat): // 3.25: before the first paint – the groups this browser closed stay closed (no jump after the page loads)
    // 3.29.1: the sign-in this page belongs to (its start, Core\Auth) – a new one starts the menu again with only Content open ?>
	<script src="<?= e($app->url('image/admin-menu.js')) ?>?v=<?= e(KALETA_VERSION) ?>" data-menu-prihlaseni="<?= (int) $app->session->get('login_at', 0) ?>"></script>
<?php endif ?>
	</nav>
</header>
<section class="loginprouzek" aria-label="<?= e(t('Account and tools')) ?>">
<?php if ($statements !== []): ?>
	<button class="paleta-spustit" type="button" data-paleta title="<?= e(t('Quick search and commands')) ?>"><span><?= e(t('Search…')) ?></span> <kbd>Ctrl K</kbd></button>
<?php endif ?>
	<button class="tema-prepinac" type="button" data-tema-prepinac title="<?= e(t('Light / dark mode')) ?>" aria-label="<?= e(t('Toggle light and dark mode')) ?>"><?= $icon('tema') ?></button>
<?php if ($claudeChip !== null): ?>
	<?= $app->view->render('admin/claude_chip', ['app' => $app] + $claudeChip) ?>
<?php endif ?>
<?php if ($bell !== null): $bellLabel = $bell > 0 ? t('Notifications – %d unread', $bell) : t('Notifications'); ?>
	<a class="zvonek<?= $bell > 0 ? ' zvonek-nove' : '' ?>" href="<?= e($app->url('admin.php?action=inbox')) ?>" title="<?= e($bellLabel) ?>" aria-label="<?= e($bellLabel) ?>"<?= $onInbox ? ' aria-current="page"' : '' ?> data-bell="<?= $bell ?>"><?= $icon('zvonek') ?><?php if ($bell > 0): ?><span class="zvonek-pocet" aria-hidden="true"><?= $bell > 99 ? '99+' : $bell ?></span><?php endif ?></a>
<?php endif ?>
	<a class="prihlasen" href="<?= e($app->url('admin.php?action=account')) ?>" title="<?= e(t('My account')) ?>" aria-label="<?= e(t('My account') . ' – ' . ($user['jmeno'] ?: $user['user'])) ?>"><span class="avatar" title="<?= e(($user['jmeno'] ?: $user['user']) . ' – ' . t(Kaleta\Core\Auth::TYPES[(int) $user['admin']] ?? '')) ?>" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($user['jmeno'] ?: $user['user'], 0, 1))) ?></span></a>
</section>
<?php endif ?>
<?php if ($statements !== []): ?>
<dialog class="paleta" id="paleta" aria-label="<?= e(t('Quick search and commands')) ?>"<?= isset($modules['news']) ? ' data-clanky="' . e($app->url('admin.php?module=news&action=search_json&edit=1')) . '"' : '' ?><?= isset($modules['contacts']) ? ' data-contacts="' . e($app->url('admin.php?module=contacts&action=search_json')) . '"' : '' /* 3.28: customers by name, e-mail or company */ ?><?= isset($modules['pipeline']) ? ' data-deals="' . e($app->url('admin.php?module=pipeline&action=search_json')) . '"' : '' /* 3.31: deals by title, customer or company */ ?>>
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
<?php if ($heading !== ''): $guide = $user !== null && !$onInbox ? Kaleta\Admin\Guide::forScreen($active === '' && $app->request->get('action') === 'account' ? 'account' : $active, '', (string) $app->request->get('tab'), Kaleta\Core\Language::code()) : null; ?>
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
<?php
// a draft look waits until it is published or discarded – not at the two-factor gate (N3183-2); 3.23: a user
// with the Appearance area sees it read only on the screens of that area (Menu, Site parts, Pop-ups, Components), with the preview
$lookReadOnly = !$app->auth()->isAdmin() && $app->auth()->hasAppearanceArea() && isset($modules[$active]) && $modules[$active]::SHARES_PERMISSION_OF === Kaleta\Core\Auth::APPEARANCE_AREA;
// 3.32 (A2): only where it belongs – the dashboard and the Appearance screens (Appearance, Menu, Site parts, Pop-ups, Components)
$lookHere = $onDashboard || (isset($modules[$active]) && $modules[$active]::GROUP === 'Appearance');
if (empty($gated) && $lookHere && ($app->auth()->isAdmin() || $lookReadOnly) && Kaleta\Core\Look::hasDraft($app->settings())): ?>
<?= $app->view->render('admin/look_bar', ['app' => $app, 'summary' => Kaleta\Core\Look::summary($app->db(), $app->settings(), false), 'menus' => Kaleta\Core\Look::menuChanges($app), 'areas' => Kaleta\Core\Look::areas($app->settings()), 'csrf' => $app->session->csrfField(), 'readOnly' => $lookReadOnly]) ?>
<?php endif ?>
<?= $content ?>
<?= $app->view->render('admin/agency', ['app' => $app, 'withLogo' => false]) ?>
<footer class="verze">Kaleta <?= e(KALETA_VERSION) ?> · <?= e(t('Kaleta is free and has no ads.')) ?>
	<a class="verze-podpora" href="https://buymeacoffee.com/Kaletacms" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg><?= e(t('Support the development of Kaleta CMS')) ?></a></footer>
</main>
<?php if (is_file(KALETA_ROOT . '/image/jazyky/admin-' . Kaleta\Core\Language::code() . '.js')): ?>
<script src="<?= e($app->url('image/jazyky/admin-' . Kaleta\Core\Language::code() . '.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php if (Kaleta\Core\Language::register() === 'informal' && is_file(KALETA_ROOT . '/image/jazyky/admin-' . Kaleta\Core\Language::code() . '-du.js')): ?>
<script src="<?= e($app->url('image/jazyky/admin-' . Kaleta\Core\Language::code() . '-du.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<?php endif ?>
<?php endif ?>
<script src="<?= e($app->url('image/admin.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= e(KALETA_VERSION) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" data-max-soubor="<?= Kaleta\Core\Files::limit() ?>" data-max-soubor-text="<?= e(Kaleta\Core\Files::limitText()) ?>" data-max-strana="<?= Kaleta\Core\Images::MAX_SIDE ?>" data-max-davka="<?= Kaleta\Core\UploadLimit::requestLimit() ?>" defer></script>
</body>
</html>
