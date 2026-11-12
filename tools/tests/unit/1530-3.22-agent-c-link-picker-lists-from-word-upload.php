<?php

declare(strict_types=1);

/* ---------- 3.22 (agent C): link picker, lists from Word, upload limits ---------- */
// C14: where the upload limit is raised on this server, and a .user.ini only where PHP reads one, never over an existing
// file, never lowering a larger limit
$c14 = sys_get_temp_dir() . '/kaleta-c14-' . bin2hex(random_bytes(4));
mkdir($c14);
$c14Small = ['upload_max_filesize' => '2M', 'post_max_size' => '8M'];
$c14First = Kaleta\Core\UploadLimit::write($c14, 'fpm-fcgi', $c14Small);
$c14Content = (string) file_get_contents($c14 . '/.user.ini');
$c14Again = Kaleta\Core\UploadLimit::write($c14, 'fpm-fcgi', $c14Small);
$c14Ours = Kaleta\Core\UploadLimit::fileState($c14);
$c14Advice = Kaleta\Core\UploadLimit::advice($c14, 'fpm-fcgi', $c14Small);
file_put_contents($c14 . '/.user.ini', "memory_limit = 256M\n");
$c14Other = [Kaleta\Core\UploadLimit::write($c14, 'cgi-fcgi', $c14Small), (string) file_get_contents($c14 . '/.user.ini'), Kaleta\Core\UploadLimit::fileState($c14),
    Kaleta\Core\UploadLimit::canWrite($c14, 'fpm-fcgi', $c14Small)];
unlink($c14 . '/.user.ini');
$c14Write = [Kaleta\Core\UploadLimit::canWrite($c14, 'fpm-fcgi', $c14Small), Kaleta\Core\UploadLimit::canWrite($c14, 'apache2handler', $c14Small), Kaleta\Core\UploadLimit::write($c14, 'cli-server', $c14Small),
    Kaleta\Core\UploadLimit::write($c14, 'apache2handler', $c14Small), Kaleta\Core\UploadLimit::write($c14, 'fpm-fcgi', ['upload_max_filesize' => '64M', 'post_max_size' => '128M']), is_file($c14 . '/.user.ini')];
chmod($c14, 0555);
$c14Locked = Kaleta\Core\UploadLimit::write($c14, 'fpm-fcgi', $c14Small);
chmod($c14, 0755);
rmdir($c14);
check('3.22 C14: .user.ini with 16M/20M where PHP-FPM reads it – written once, never overwritten, never over a file someone else wrote', [
    $c14First, str_starts_with($c14Content, Kaleta\Core\UploadLimit::MARK), str_contains($c14Content, "\nupload_max_filesize = 16M\npost_max_size = 20M\n"), $c14Again, $c14Ours,
    str_contains($c14Advice, 'user_ini.cache_ttl'), $c14Other, $c14Write, $c14Locked,
], ['written', true, true, 'exists', 'ours', true, ['exists', "memory_limit = 256M\n", 'other', false], [true, false, 'unsupported', 'unsupported', 'not_needed', false], 'not_writable']);
check('3.22 C14: a larger limit is never lowered – only the smaller directive is written', [
    Kaleta\Core\UploadLimit::missing(['upload_max_filesize' => '64M', 'post_max_size' => '8M']), Kaleta\Core\UploadLimit::missing(['upload_max_filesize' => '2M', 'post_max_size' => '0']),
    Kaleta\Core\UploadLimit::missing(['upload_max_filesize' => '1G', 'post_max_size' => '20M']), Kaleta\Core\UploadLimit::bytes('16M'), Kaleta\Core\UploadLimit::bytes('512K'),
], [['post_max_size' => '20M'], ['upload_max_filesize' => '16M'], [], 16777216, 524288]);
check('3.22 C14: the advice names the place of this server – .htaccess on mod_php and LiteSpeed, php.ini elsewhere, .user.ini on PHP-FPM and CGI', [
    Kaleta\Core\UploadLimit::environment('apache2handler'), Kaleta\Core\UploadLimit::environment('litespeed'), Kaleta\Core\UploadLimit::environment('fpm-fcgi'),
    Kaleta\Core\UploadLimit::environment('cgi-fcgi'), Kaleta\Core\UploadLimit::environment('cli-server'),
    str_contains(Kaleta\Core\UploadLimit::advice(sys_get_temp_dir(), 'apache2handler', $c14Small), 'php_value upload_max_filesize 16M, php_value post_max_size 20M'),
    str_contains(Kaleta\Core\UploadLimit::advice(sys_get_temp_dir(), 'cli-server', $c14Small), 'php.ini'),
], ['htaccess', 'htaccess', 'user_ini', 'user_ini', 'php_ini', true, true]);
$c14Installer = (string) file_get_contents(KALETA_SYSTEM . '/src/Install/Installer.php');
$c14Health = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Health.php');
check('[source-pin] 3.22 C14: the installer writes .user.ini after config.php; System status gives the advice and the button; /.user.ini is not in git; the editor knows the request limit', [
    (bool) preg_match("/file_put_contents\\(KALETA_ROOT \\. '\\/config\\.php'.*?UploadLimit::write\\(KALETA_ROOT\\)/s", $c14Installer),
    str_contains($c14Health, "UploadLimit::advice()"), str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/health.php'), "\$module->url('user_ini')"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/.gitignore'), "\n/.user.ini\n"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php'), 'data-max-davka="<?= Kaleta\Core\UploadLimit::requestLimit() ?>"'),
], [true, true, true, true, true]);
// C14: the note about photos the browser scaled down comes back after the upload of the Media form – numbers and a name only
check('3.22 C14: Media reads the "resized from … to …" lines of the upload form and nothing else', [
    count(Kaleta\Admin\Modules\Media::resizedNotes("IMG_2041.jpg|4032|3024|6500000|2000|1500|800000\nbad|<b>|1|1|1|1|1\n|1|1|1|1|1|1")),
    str_contains(implode('', Kaleta\Admin\Modules\Media::resizedNotes('IMG_2041.jpg|4032|3024|6500000|2000|1500|800000')), '4032 × 3024 px'),
    Kaleta\Admin\Modules\Media::resizedNotes(''),
], [1, true, []]);
$c22Editor = (string) file_get_contents(KALETA_ROOT . '/image/editor.js');
check('[source-pin] 3.22 C14: the browser scales photos to 2000 px at quality 0.85, keeps PNG as PNG, leaves GIF/SVG/PDF, respects the EXIF rotation, and sends large sets in batches', [
    str_contains($c22Editor, "if (!/^image\\/(jpeg|png|webp)$/.test(file.type) || !window.createImageBitmap)"), str_contains($c22Editor, "createImageBitmap(file, { imageOrientation: 'from-image' })"),
    str_contains($c22Editor, 'return write(0.85);'), str_contains($c22Editor, 'canvas.toBlob(done, file.type, quality)'), str_contains($c22Editor, 'function sendInBatches(files)'),
    Kaleta\Core\Images::MAX_SIDE,
], [true, true, true, true, true, 2000]);
// U7: numbered list and quote in the builder's text fields, still no subheadings there; Word lists converted on paste only
check('[source-pin] 3.22 U7: the builder\'s text editor has numbered lists and quotes, not H2/H3; Word list paragraphs become lists on paste', [
    str_contains($builderScript, "'data-editor': 'stavitel'"), str_contains($c22Editor, "[T('1. list'), T('Numbered list'), function () { statement('insertOrderedList'); }, 'text']"),
    str_contains($c22Editor, "[T('“quote”'), T('Quote'), function () { statement('formatBlock', 'BLOCKQUOTE'); }, 'text']"),
    str_contains($c22Editor, "['H2', T('Mezititulek'), function () { statement('formatBlock', 'H2'); }, 'velky']"),
    str_contains($c22Editor, "if ((small && t[3] === 'velky') || (mode === 'maly' && t[3] === 'text')) { return; }"),
    str_contains($c22Editor, "if (strict === 'paste') { wordLists(box); }"), str_contains($c22Editor, "cleanHtml(inserted, 'paste')"),
], [true, true, true, true, true, true, true]);
// U7: the server keeps numbered lists, nested lists and quotes of a builder Text element (Build::sanitize → WpContent::safeHtml)
[$u7Build] = Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['id' => 'u7t', 'typ' => 'text', 'obsah' => ['html' => '<ol><li>One<ol><li>Sub</li></ol></li></ol><ul><li>a<ul><li>b</li></ul></li></ul><blockquote><p>Quote</p></blockquote><h1 onclick="x">T</h1>']]]]);
check('3.22 U7: a builder Text keeps ol, nested lists and blockquote', str_replace("\n", '', $u7Build['deti'][0]['obsah']['html'] ?? ''),
    '<ol><li>One<ol><li>Sub</li></ol></li></ol><ul><li>a<ul><li>b</li></ul></li></ul><blockquote><p>Quote</p></blockquote><h2>T</h2>');
// U6: the link dialog searches one endpoint for every signed-in user, after the two-factor gate; anchors from the builder
$c22Kernel = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php');
check('[source-pin] 3.22 U6: link targets – a route behind the sign-in and the two-factor gate, the builder\'s anchors, mailto: and tel: helpers, a keyboard combobox', [
    strpos($c22Kernel, "\$action === 'link_targets'") > strpos($c22Kernel, 'isMissingRequired2fa'), str_contains($builderScript, 'window.kaletaKotvy = pageAnchors;'),
    str_contains($c22Editor, "url: 'mailto:' + q"), str_contains($c22Editor, "url: 'tel:' +"), str_contains($c22Editor, 'role="combobox" aria-autocomplete="list"'),
    str_contains($c22Editor, "input.setAttribute('aria-activedescendant'"), str_contains($c22Editor, "ADMIN + '?action=link_targets&q='"),
], [true, true, true, true, true, true, true]);
// 3.22 (C9, owner decision D1): the optional Appearance area – Menu, Site parts, Pop-ups and Components share one permission
// a role or a user may be given; Site appearance, Settings (Backups, Claude settings), Users and Roles stay administrator-only
$c9Area = [Kaleta\Admin\Modules\Menu::class, Kaleta\Admin\Modules\SiteParts::class, Kaleta\Admin\Modules\Popups::class, Kaleta\Admin\Modules\Components::class];
check('3.22 (C9): the four appearance modules share the Appearance area, the rest of the look and the administration stay with the administrator', [
    array_map(fn (string $c): string => ($c::ADMIN_ONLY ? 'admin' : 'open') . ':' . $c::SHARES_PERMISSION_OF, $c9Area),
    array_map(fn (string $c): bool => $c::ADMIN_ONLY, [Kaleta\Admin\Modules\Appearance::class, Kaleta\Admin\Modules\Settings::class, Kaleta\Admin\Modules\ClaudeSettings::class,
        Kaleta\Admin\Modules\Users::class, Kaleta\Admin\Modules\Roles::class, Kaleta\Admin\Modules\Transfer::class]),
    array_slice(Kaleta\Admin\Modules\Roles::configurable(), -2, 1, true), // 3.38: the prices area (Auth::PRICES_AREA) comes after it
], [array_fill(0, 4, 'open:appearance_area'), array_fill(0, 6, true), ['appearance_area' => 'Appearance: menu, site parts, pop-ups and components']]);
// nobody gains it on an update or by default: not in the default sections of an author or an editor; of the ready-made roles
// only the Client has it, with Business details (3.23.1, N-C1, owner decision D1) – saved roles keep their sections
check('3.22 (C9), 3.23.1 (N-C1): the Appearance area is never a default of a level; only the ready-made Client role ticks it, with Business details', [
    array_values(array_intersect([...Kaleta\Admin\Modules\Users::defaultModules(Kaleta\Core\Auth::EDITOR), ...Kaleta\Admin\Modules\Users::defaultModules(Kaleta\Core\Auth::AUTHOR)],
        ['appearance_area', 'menu', 'parts', 'popups', 'components', 'appearance'])),
    array_keys(array_filter(Kaleta\Admin\Modules\Roles::PRESETS, fn (array $p): bool => in_array('appearance_area', $p[3], true) && in_array('business', $p[3], true))),
    str_contains(Kaleta\Admin\Modules\Users::summary(Kaleta\Core\Auth::EDITOR, ['pages', 'appearance_area']), 'Appearance: menu, site parts, pop-ups and components'),
    array_diff(Kaleta\Admin\Modules\Roles::PRESETS['client'][3], array_keys(Kaleta\Admin\Modules\Roles::configurable())),
], [[], ['client'], true, []]);
// what changes the site at once needs the permission to publish; every listed action exists; the server checks it before the action
$c9Live = [];
foreach ($c9Area as $c9Class) {
    foreach ($c9Class::LIVE_ACTIONS as $c9Action) {
        $c9Live[] = method_exists($c9Class, 'action' . str_replace('_', '', ucwords($c9Action, '_'))) ? $c9Action : 'missing ' . $c9Action;
    }
}
$c9Module = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Module.php');
$c9Components = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Components.php');
check('[source-pin] 3.22 (C9): live actions of the appearance modules need canPublish, a component from the builder keeps Custom HTML only for an administrator', [
    $c9Live, (bool) preg_match('/in_array\(\$action, static::LIVE_ACTIONS, true\) && \$this->request->isPost\(\) && !\$this->app->auth\(\)->canPublish\(\)/', $c9Module),
    str_contains($c9Components, "Build::sanitize(['v' => Build::VERSION, 'deti' => [\$element]], \$this->app->auth()->isAdmin())"),
    (bool) preg_match('/function actionFromElement.+?canPublish\(\).+?Build::sanitize/s', $c9Components),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Menu.php'), "post('publikovat') === '1' && !\$this->app->auth()->canPublish()"),
], [['template', 'save_variant', 'save', 'toggle', 'delete', 'save', 'delete'], true, true, true, true]);
// MCP (additive): the tools of these areas check the Appearance area, never the administrator role alone
$c9Mcp = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php') . file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/BuilderTools.php')
    . file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/EnquiryAndPopupTools.php') . file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/PageTools.php');
check('[source-pin] 3.22 (C9): MCP – site parts, pop-ups, components and the menu follow the Appearance area', [
    substr_count($c9Mcp, 'hasAppearanceArea()'),
    preg_match_all('/function tool(ListSiteParts|SavePartVariant|ListPopups|SavePopup)\(string \$name, array \$a\): mixed\s*\{[^}]*?isAdmin\(\)/', $c9Mcp),
], [14, 0]);

// 3.22 (C13): the optional canonical address of a page, a news item and a collection item (Core\CanonicalUrl)
use Kaleta\Core\CanonicalUrl as C13;
check('3.22 (C13): a canonical address is an absolute http(s) address or a path on the site, nothing else', array_map(C13::clean(...), [
    '', '  ', ' https://example.com/original?x=1 ', 'HTTP://example.com/', '/services/web', '//evil.example/x', 'javascript:alert(1)', 'JavaScript:alert(1)',
    'data:text/html,x', 'mailto:a@b.c', 'relative/path', 'https://exa mple.com/', 'https://user:pass@example.com/', 'https://intranet/', '/a"onload=x', '/a<b',
    'https://example.com/' . str_repeat('a', 500), null, 5,
]), ['', '', 'https://example.com/original?x=1', 'http://example.com/', '/services/web', null, null, null, null, null, null, null, null, null, null, null, null, '', null]);
check('3.22 (C13): the printed canonical is absolute; the WordPress import keeps other sites, turns the old site into paths and drops the post itself', [
    C13::absolute('/services/web', 'https://firma.cz', '/web'), C13::absolute('https://medium.com/@a/post', 'https://firma.cz', ''), C13::absolute('', 'https://firma.cz', ''),
    C13::absolute('javascript:alert(1)', 'https://firma.cz', ''),
    C13::fromImport('https://www.old.cz/2019/05/post/', 'https://old.cz', 'https://old.cz/2019/05/post/'), C13::fromImport('https://old.cz/original/', 'http://www.old.cz/', 'https://old.cz/copy/'),
    C13::fromImport('https://medium.com/@a/post?x=1&amp;y=2', 'https://old.cz', 'https://old.cz/copy/'), C13::fromImport('javascript:alert(1)', 'https://old.cz', ''), C13::fromImport('', 'https://old.cz', ''),
], ['https://firma.cz/web/services/web', 'https://medium.com/@a/post', '', '', '', '/original', 'https://medium.com/@a/post?x=1&y=2', '', '']);
$c13Schema = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
$c13Migration = (string) @file_get_contents(KALETA_SYSTEM . '/sql/migrace/0109-canonical-url.sql');
$c13Kernel = (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Kernel.php');
check('[source-pin] 3.22 (C13): the column on pages, news and items (schema and migration 0109), one canonical link in the page frame, fed by the three detail pages', [
    preg_match_all("/^\s+canonical_url\s+VARCHAR\(500\) NOT NULL DEFAULT '',/m", $c13Schema),
    preg_match_all('/ALTER TABLE ka_(stranky|novinky|kolekce_polozky) ADD COLUMN canonical_url VARCHAR\(500\) NOT NULL DEFAULT \'\' AFTER noindex;/', $c13Migration),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/views/front/base.php'), 'rel="canonical"'),
    substr_count($c13Kernel, "'kanonicka' => (string) ("), str_contains($c13Kernel, 'CanonicalUrl::absolute('),
], [3, 3, 1, 3, true]);
check('[source-pin] 3.22 (C13): MCP takes canonical_url on pages, news and collection items (the same name on both sides), get_page and get_news return it', [
    Kaleta\Mcp\Translator::arguments('update_page', ['id' => 1, 'canonical_url' => '/original']), Kaleta\Mcp\Translator::arguments('update_news', ['id' => 1, 'canonical_url' => '']),
    Kaleta\Mcp\Translator::arguments('save_collection_item', ['collection' => 'x', 'canonical_url' => 'https://a.cz/']),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php'), 'seo_titulek, obrazek, noindex, canonical_url, text'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/NewsTools.php'), "'seo_popis', 'canonical_url']"),
], [['id' => 1, 'canonical_url' => '/original'], ['id' => 1, 'canonical_url' => ''], ['kolekce' => 'x', 'canonical_url' => 'https://a.cz/'], true, true]);

// 3.23 (C17): the hand-over checklist – SMTP counts as tested only for the server that accepted a message
$smtp323 = static function (array $values): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, $values);

    return $s;
};
check('3.23 (C17): Mail::smtpTested – the server set now accepted a message; another server, no record or no server is not tested', [
    Kaleta\Core\Mail::smtpTested($smtp323(['smtp_host' => 'smtp.example.com', 'smtp_last_ok' => '2026-11-01 10:00:00|smtp.example.com'])),
    Kaleta\Core\Mail::smtpTested($smtp323(['smtp_host' => 'SMTP.example.com', 'smtp_last_ok' => '2026-11-01 10:00:00|smtp.example.com'])),
    Kaleta\Core\Mail::smtpTested($smtp323(['smtp_host' => 'smtp.other.com', 'smtp_last_ok' => '2026-11-01 10:00:00|smtp.example.com'])),
    Kaleta\Core\Mail::smtpTested($smtp323(['smtp_host' => 'smtp.example.com', 'smtp_last_ok' => ''])),
    Kaleta\Core\Mail::smtpTested($smtp323(['smtp_host' => '', 'smtp_last_ok' => '2026-11-01 10:00:00|'])),
    Kaleta\Core\Settings::DEFAULTS['smtp_last_ok'],
], [true, true, false, false, false, '']);
$audit323 = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Audit.php');
$auditView323 = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/audit/list.php');
preg_match_all("/\\\$row\('([a-z_]+)'/", $audit323, $rows323);
check('[source-pin] 3.23 (C17): one checklist – the rows in order, the new checks among them, no folded "Building this for a client?" left in the view, the Notebook as a row', [
    $rows323[1], Kaleta\Core\Audit::KINDS['handover'], str_contains($auditView323, "t('Building this for a client?')"), str_contains($auditView323, "t('Open the Notebook')"),
    str_contains($auditView323, "t('%d of %d done'"), str_contains($audit323, 'Auth::APPEARANCE_AREA'), str_contains($audit323, "Roles::PRESETS['client'][0]"),
    str_contains($audit323, "promenna = 'claude_destructive'"), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Mail.php'), 'self::rememberSmtp($siteSettings);'),
], [['site_email', 'smtp', 'remote_backup', 'company', 'indexing', 'favicon', 'cookies', 'security_contact', 'two_step', 'unused_access', 'auto_suspend',
    'client_account', 'monthly_report', 'guardrails', 'agency', 'notebook', 'cron', 'domain'], 'Handing over to a client', false, false, true, true, true, true, true]);
check('[source-pin] 3.23 (C17): MCP site_audit returns handover_checklist additively (no new tool, no new parameter), its description stays in the essentials budget', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/SettingsTools.php'), "\$out['handover_checklist'] = ["),
    Kaleta\Mcp\Translator::arguments('site_audit', ['kind' => 'handover']), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php'), 'handover_checklist = every row with done'),
], [true, ['kind' => 'handover'], true]);
// 3.23 review (N323-1): the essentials list had 39 bytes left under its 50 KB budget – the repeated parameter texts (reason,
// the build targets) and site_audit were tightened; at least 1 KB stays free, so the next tool or parameter fits without a rush
check('[source-pin] 3.23 review (N323-1): the essentials tool list keeps at least 1 KB of headroom under 50 KB; the safety sentences stay', [
    $json310(['tools' => $essentials310]) <= 49000,
    str_contains((string) json_encode($all310, JSON_UNESCAPED_UNICODE), 'SENDS A REAL E-MAIL'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php'), 'never with invented ones'),
], [true, true, true]);
// 3.23 review (N323-3): Media → Used in links a setting to the tab that has it – the agency logo is on Agency since 3.23
check('[source-pin] 3.23 review (N323-3): a setting that uses a file links to its own Settings tab (or the screen of its own)', [
    Kaleta\Admin\Modules\Settings::fieldUrl('agency_logo'), Kaleta\Admin\Modules\Settings::fieldUrl('share_image'), Kaleta\Admin\Modules\Settings::fieldUrl('company_name'),
    Kaleta\Admin\Modules\Settings::fieldUrl('cookies_text_de'), Kaleta\Admin\Modules\Settings::fieldUrl('smtp_last_ok'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Media.php'), 'Settings::fieldUrl((string) $r[\'kde\'])'),
], ['module=settings&tab=agency#agency_logo', 'module=settings&tab=seo#share_image', 'module=business#company_name', 'module=settings&tab=cookies#cookies_text_de', 'module=settings', true]);
// 3.23 (C18): module names are English source strings (the Czech is in the dictionary), the dashboard copy says Ask Claude
check('[source-pin] 3.23 (C18): News, Users and Settings have English NAME source strings translated in every admin dictionary; Ask Claude, not Requests, in the dashboard copy', [
    Kaleta\Admin\Modules\News::NAME, Kaleta\Admin\Modules\Users::NAME, Kaleta\Admin\Modules\Settings::NAME,
    Kaleta\Core\Language::runWith('cs', fn (): string => t('News') . '|' . t('Users') . '|' . t('Settings'), 'admin-'),
    Kaleta\Core\Language::runWith('de', fn (): string => t('News') . '|' . t('Users') . '|' . t('Settings'), 'admin-'),
    Kaleta\Core\Language::runWith('es', fn (): string => t('News') . '|' . t('Users') . '|' . t('Settings'), 'admin-'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/ask_claude.php'), 'answers in Requests'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/claude_panel.php'), "t('Requests')"),
    Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Admin\MenuPaths::links('admin.php', 'Settings → Mail and Users → Roles', ['settings', 'roles']), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Admin\MenuPaths::links('admin.php', 'Nastavení → Pošta', ['settings']), 'admin-'),
], ['News', 'Users', 'Settings', 'Novinky|Uživatelé|Nastavení', 'News|Benutzer|Einstellungen', 'Noticias|Usuarios|Ajustes', false, false,
    '<a href="admin.php?module=settings&amp;tab=mail">Settings → Mail</a> and <a href="admin.php?module=roles">Users → Roles</a>', '<a href="admin.php?module=settings&amp;tab=mail">Nastavení → Pošta</a>']);
// 3.23 (owner): the draft look for whoever has the Appearance area – the preview only; publishing stays with the administrator
$kernel323 = (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Kernel.php');
$lookBar323 = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/look_bar.php');
check('[source-pin] 3.23: Front\Kernel activates the draft look for the Appearance area (build=koncept or preview=vzhled), never by isAdmin alone; the read-only look bar has no Publish or Discard', [
    str_contains($kernel323, "(\$r->get('build') === 'koncept' || \$r->get('preview') === 'vzhled') && \$this->app->auth()->hasAppearanceArea()"),
    str_contains($kernel323, "\$r->get('build') === 'koncept' && \$this->app->auth()->isAdmin()"),
    (bool) preg_match('/<\?php if \(\$readOnly\): \?>.*?<\?php else: \?>\s*<form[^>]*discard_look/s', $lookBar323),
    (bool) preg_match("/<\?php if \(\\\$readOnly\): \/\/[^\n]*\?>\n[^\n]*preview=vzhled[^\n]*\n<\?php else: \?>[^\n]*\n[^\n]*\n[^\n]*publish_look/", $lookBar323),
], [true, false, true, true]);
// 3.23 (U10): every field that refers to the site's data names a known source; the builder offers a choice for each, MCP
// describes where the value comes from; the stored value keeps its type (text or number) and the validator does not change
$u10Sources = [];
foreach (Kaleta\Builder\Build::ELEMENTS as $u10Class) {
    foreach ($u10Class::properties() as $u10Key => $u10Def) {
        if (isset($u10Def['volby'])) {
            $u10Sources[$u10Class::TYPE . '.' . $u10Key] = $u10Def['volby'] . ':' . $u10Def['typ'];
        }
    }
}
ksort($u10Sources);
$u10Script = (string) file_get_contents(KALETA_ROOT . '/image/stavitel.js');
check('3.23 (U10): the fields referring to collections, their fields and categories, news categories and Bookings name their source', $u10Sources, [
    'kolekce.filtr_pole' => 'pole:text', 'kolekce.kategorie' => 'kategorie:text', 'kolekce.kolekce' => 'kolekce:text', 'kolekce.obdobi_do' => 'pole:text', 'kolekce.obdobi_od' => 'pole:text',
    'kolekce.razeni_pole' => 'pole:text', 'novinky.kategorie' => 'kategorie_novinek:text', 'pobocky.kolekce' => 'kolekce:text', 'pobocky.pole_poloha' => 'pole:text',
    'predchozi_dalsi.pole_obrazku' => 'pole:text', 'rezervace.osoba' => 'osoba:cislo', 'rezervace.sluzba' => 'sluzba:cislo',
]);
check('[source-pin] 3.23 (U10): each source has a choice in the builder and a hint in MCP', array_values(array_filter(array_unique(array_map(fn (string $s): string => explode(':', $s)[0], $u10Sources)),
    fn (string $s): bool => !str_contains($u10Script, "case '" . $s . "':") || !isset(Kaleta\Mcp\Vocabulary::SOURCES[$s]))), []);
$u10Mcp = Kaleta\Mcp\Vocabulary::schema(Kaleta\Builder\Build::schema(true, 'en'), ['booking', 'store_locator', 'collection_list'], false)['elements'];
$u10Fields = array_column($u10Mcp, 'fields', 'type');
check('3.23 (U10): builder_schema says where a service, a collection, a location field and a category come from (additive: type and default stay)', [
    $u10Fields['booking']['service']['type'], $u10Fields['booking']['service']['default'], $u10Fields['booking']['service']['source'],
    $u10Fields['store_locator']['location_field']['source'], $u10Fields['collection_list']['category']['source'], isset($u10Fields['collection_list']['count']['source']),
], ['number', 0, 'a service id from booking_availability → services (0 = the visitor chooses)', 'a field key of the element\'s collection (list_collections → fields) (field types: location)',
    'a category slug of the element\'s collection from list_collection_categories', false]);
[$u10Build] = Kaleta\Builder\Build::sanitize(['deti' => [['typ' => 'sekce', 'deti' => [['typ' => 'rezervace', 'obsah' => ['sluzba' => '12', 'osoba' => 3]], ['typ' => 'pobocky', 'obsah' => ['kolekce' => 'zmizela', 'pole_poloha' => 'gps']]]]]]);
check('3.23 (U10): a stored reference is kept as it is – also one the site no longer has (the builder shows it as not found)', [
    $u10Build['deti'][0]['deti'][0]['obsah']['sluzba'], $u10Build['deti'][0]['deti'][0]['obsah']['osoba'], $u10Build['deti'][0]['deti'][1]['obsah']['kolekce'], $u10Build['deti'][0]['deti'][1]['obsah']['pole_poloha'],
], [12, 3, 'zmizela', 'gps']);
check('[source-pin] 3.23 (U10): the builder action of the choices is a read of every builder module, the data is asked with the people\'s sections', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/BuilderActions.php'), "'volby' => self::builderChoices(\$this->db, array_keys(\$this->kernel->modules())"),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/BuilderActions.php'), "self::builderChoices(\$this->db, array_keys(\$this->kernel->modules()), Language::column(\$this->app->settings(), (string) \$target['jazyk']))"), // N323-2: the language column, '' for the default
], [true, 2]);
// 3.23 (U15): a tablet (below 1180 px since 3.23.1, N-U5) folds the panels into icon rails over which they open; a desktop keeps its three columns
$u15Css = (string) file_get_contents(KALETA_ROOT . '/image/stavitel.css');
check('[source-pin] 3.23 (U15): the tablet layout – rails, panels over the canvas, two columns of elements; the phone notice below 720 px stays', [
    str_contains($u15Css, '@media (max-width: 1179px) {' . "\n\t" . '.st { grid-template: "lista lista lista" auto "pruh-levy platno pruh-pravy" 1fr / 48px minmax(0, 1fr) 48px; }'),
    str_contains($u15Css, '.st:not([data-panel="levy"]) .st-levy, .st:not([data-panel="pravy"]) .st-pravy { display: none; }'),
    str_contains($u15Css, '.st-prvky { grid-template-columns: repeat(2, minmax(0, 1fr)); }'), str_contains($u15Css, '@media (max-width: 719px) {'),
    str_contains($u10Script, "window.matchMedia('(max-width: 1179px)')"), str_contains($u10Script, "if (e.key === 'Escape' && root.dataset.panel"),
], [true, true, true, true, true, true]);
