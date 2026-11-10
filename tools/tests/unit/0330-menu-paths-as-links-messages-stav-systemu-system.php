<?php

declare(strict_types=1);

/* ---------- menu paths as links (messages, "Stav systému" (System status), help) ---------- */
Kaleta\Core\Language::set('cs', 'admin-');
$routesHtml = Kaleta\Admin\MenuPaths::links('/admin.php', 'Je k dispozici nová verze 3.0.1 – nainstalujete ji v Nastavení → Zálohy a aktualizace. <b>', ['settings']);
check('Cesty: známá cesta je odkaz', str_contains($routesHtml, '<a href="/admin.php?module=settings&amp;tab=backups">Nastavení → Zálohy a aktualizace</a>'), true);
check('Cesty: zbytek textu zůstává escapovaný', str_contains($routesHtml, '&lt;b&gt;'), true);
check('Cesty: delší cesta má přednost a odkaz se nevnořuje', substr_count($routesHtml, '<a '), 1);
check('Cesty: bez práva k modulu žádný odkaz', str_contains(Kaleta\Admin\MenuPaths::links('/admin.php', 'Nastavení → Pošta', []), '<a '), false);
Kaleta\Core\Language::set('en', 'admin-');
check('Cesty: v angličtině se odkazuje přeložená cesta', str_contains(Kaleta\Admin\MenuPaths::links('/admin.php', t('Je k dispozici nová verze %s – nainstalujete ji v Nastavení → Zálohy a aktualizace.', '3.0.1'), ['settings']), '>Settings → Backups and updates</a>'), true);
// 3.20 (C4): the paths the module hints name – Enquiries → settings (the longer phrase wins over Enquiries), Pages, Users → Roles
$pathsHtml = Kaleta\Admin\MenuPaths::links('/admin.php', 'Retention: Enquiries → settings. Pages. Users → Roles.', ['enquiries', 'pages', 'roles']);
check('3.20 C4: Enquiries → settings is one link to the settings action', [substr_count($pathsHtml, '<a '), str_contains($pathsHtml, '<a href="/admin.php?module=enquiries&amp;action=settings">Enquiries → settings</a>')], [3, true]);
check('3.20 C4: Pages and Users → Roles link their screens', [str_contains($pathsHtml, '<a href="/admin.php?module=pages">Pages</a>'), str_contains($pathsHtml, '<a href="/admin.php?module=roles">Users → Roles</a>')], [true, true]);
check('3.20 C4: without the module nothing is linked', substr_count(Kaleta\Admin\MenuPaths::links('/admin.php', 'Retention: Enquiries → settings. Pages.', ['pages']), '<a '), 1);
// 3.20 (U11, U13): the builder links to where the data lives only for the modules the person may open
$builderLinks = Kaleta\Admin\Modules\Pages::dataLinks(['enquiries', 'collections'], fn (string $q): string => '/admin.php?' . $q);
check('3.20 U11: builder data links only for allowed modules', [array_keys($builderLinks), $builderLinks['kolekce']], [['poptavky', 'kolekce', 'novaKolekce'], '/admin.php?module=collections&action=items&id=']);
check('3.20 U11: an editor without any of the modules gets no link', Kaleta\Admin\Modules\Pages::dataLinks(['pages', 'news'], fn (string $q): string => $q), []);
check('3.20 U13: the component link is there for whoever may open Components', Kaleta\Admin\Modules\Pages::dataLinks(['components'], fn (string $q): string => $q), ['upravKomponentu' => 'module=components&action=edit&id=']);
Kaleta\Core\Language::set('cs', 'admin-');

