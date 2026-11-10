<?php

declare(strict_types=1);

/* ---------- 3.3.3: security release, workstream A ---------- */
// N52: one normalized host for the lookup, the pin and the request
check('3.3.3 N52: Outbound::host – IDN to punycode, lowercase; a percent sign, other characters and numeric IPv4 spellings are refused', array_map(Kaleta\Core\Outbound::host(...),
    ['%61.attacker.tld', '%70inme.localhost', 'čeština.example', 'WWW.Example.COM', 'ｅxample.com', 'a_b.example', 'a..b', '0x7f.1', '2130706433', '127.1', '[::1]', '[0:0::1]', '93.184.216.34', 'example.com.', 'ex ample.com', '[not-ip]', '']),
    [null, null, 'xn--etina-gya30d.example', 'www.example.com', 'example.com', null, null, null, null, null, '::1', '::1', '93.184.216.34', 'example.com.', null, null, null]);
check('3.3.3 N52: Outbound::url writes the normalized host into the URL – the name checked, pinned and requested is one string', [
    Kaleta\Core\Outbound::url('https://Čeština.Example:8443/a/%C4%8D?x=%41#f'), Kaleta\Core\Outbound::url('http://[::1]/x'), Kaleta\Core\Outbound::url('https://%61.example/'),
    Kaleta\Core\Outbound::url('https://u:p@example.com/'), Kaleta\Core\Outbound::url('ftp://example.com/'), Kaleta\Core\Outbound::url('https://example.com\\@169.254.169.254/')],
    [['url' => 'https://xn--etina-gya30d.example:8443/a/%C4%8D?x=%41#f', 'host' => 'xn--etina-gya30d.example', 'port' => 8443, 'scheme' => 'https'],
     ['url' => 'http://[::1]/x', 'host' => '::1', 'port' => 80, 'scheme' => 'http'], null, null, null, null]);
$n52Wp = new Kaleta\Core\ImageDownloader('https://stary-web.example');
$n52Any = new Kaleta\Core\ImageDownloader('https://čeština.example', true);
check('3.3.3 N52: every checker refuses a %xx host and accepts an IDN host in its normalized form (images, fetch, fleet, links)', [
    $n52Wp->isAllowedUrl('https://%73tary-web.example/a.png'), $n52Any->isAllowedUrl('https://%61.attacker.tld/x.jpg'), $n52Any->isAllowedUrl('https://čeština.example/a.png'), $n52Any->domain(),
    (new Kaleta\Core\ImageDownloader('https://xn--etina-gya30d.example'))->isAllowedUrl('https://www.čeština.example/a.png'), $n52Wp->verifiedIp('%61.example'),
    Kaleta\Import\Fetch::allowedUrl('https://%6fld.example/api', 'https://old.example'), Kaleta\Import\Fetch::allowedUrl('https://čeština.example/api', 'https://čeština.example'), Kaleta\Import\Fetch::allowedSite('https://%6fld.example'),
    Kaleta\Fleet\Http::allowedUrl('https://%61.example/'), Kaleta\Fleet\Http::allowedUrl('https://čeština.example/'), Kaleta\Fleet\Http::pin('https://%61.example/'), Kaleta\Fleet\Http::statuses(['https://%61.example/']),
    Kaleta\Core\Links::isPublic('https://%61.example/'), Kaleta\Core\Links::target('https://čeština.invalid/')],
    [false, false, true, 'xn--etina-gya30d.example', true, null, false, true, false, false, true, null, [0], false, false]);
check('3.3.3 N9: the link check pins every resolved address – IPv6, CGNAT 100.64/10 and NAT64 are internal, a name that does not resolve is not requested', [
    Kaleta\Core\Links::isPublic('http://100.64.0.1/'), Kaleta\Core\Links::isPublic('http://[::1]/'), Kaleta\Core\Links::isPublic('http://[fd00::1]/'), Kaleta\Core\Links::isPublic('http://[64:ff9b::a9fe:a9fe]/'),
    Kaleta\Core\Links::target('https://[2606:4700:4700::1111]/x'), Kaleta\Core\Links::target('https://nothing-here.invalid/'), Kaleta\Core\Links::target('https://example.com:8443/')],
    [false, false, false, false, ['url' => 'https://[2606:4700:4700::1111]/x', 'host' => '2606:4700:4700::1111', 'port' => 443, 'scheme' => 'https', 'ip' => '2606:4700:4700::1111'], false, null]);
check('3.3.3 N22: NAT64 (64:ff9b::/96, 64:ff9b:1::/48) and SIIT (::ffff:0:a.b.c.d) are not public; ordinary IPv6 still is', array_map(Kaleta\Core\ImageDownloader::isPublicIp(...),
    ['64:ff9b::a9fe:a9fe', '64:ff9b:1::a00:1', '64:ff9b:1:ffff::1', '::ffff:0:a9fe:a9fe', '::ffff:0:7f00:1', '::ffff:0:5db8:d822', '64:ff9b:2::1', '2606:4700::1111']),
    [false, false, false, false, false, false, true, true]);
check('3.3.3 N52: curl compares the address it connected to with the pinned one (any notation of the same address)', [
    defined('CURLOPT_PREREQFUNCTION') ? str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Outbound.php'), 'CURLOPT_PREREQFUNCTION') : true,
    Kaleta\Core\Outbound::sameAddress('127.0.0.1', '::ffff:127.0.0.1'), Kaleta\Core\Outbound::sameAddress('::1', '0:0::1'), Kaleta\Core\Outbound::sameAddress('[::1]', '::1'),
    Kaleta\Core\Outbound::sameAddress('127.0.0.1', '127.0.0.2'), Kaleta\Core\Outbound::sameAddress('', '127.0.0.1')],
    [true, true, true, true, false, false]);
$n52Sources = array_map(fn (string $f): string => (string) file_get_contents(KALETA_SYSTEM . '/src/' . $f), ['Core/ImageDownloader.php', 'Import/Fetch.php', 'Fleet/Http.php', 'Core/Links.php']);
check('3.3.3 N52: no curl caller builds its own CURLOPT_RESOLVE entry – all pin through Outbound::pin', [array_sum(array_map(fn (string $s): int => substr_count($s, 'CURLOPT_RESOLVE'), $n52Sources)),
    array_map(fn (string $s): bool => str_contains($s, 'Outbound::pin('), $n52Sources)], [0, [true, true, true, true]]);

// N55: a Kaleta archive brings settings through the same validation as the admin form and MCP
check('3.3.3 N55: imported company_map and social_* must be web addresses; texts and numbers are checked by their field type', [
    Kaleta\Admin\Modules\Settings::checkable('company_map'), Kaleta\Admin\Modules\Settings::checkable('social_facebook'), Kaleta\Admin\Modules\Settings::checkable('design_system'),
    Kaleta\Admin\Modules\Settings::verifyValue('company_map', 'javascript:alert(1)'), Kaleta\Admin\Modules\Settings::verifyValue('social_x', ' JavaScript:alert(2)'),
    Kaleta\Admin\Modules\Settings::verifyValue('social_linkedin', 'https://www.linkedin.com/company/x'), Kaleta\Admin\Modules\Settings::verifyValue('company_map', ''),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/SiteImport.php'), 'Settings::checkable($key) ? \Kaleta\Admin\Modules\Settings::verifyValue($key, $value)')],
    [true, true, false, null, null, 'https://www.linkedin.com/company/x', '', true]);

// N63: imported content checked again – only risky markup changes
$n63Wp = Kaleta\Core\WpContent::safeHtml(...);
check('3.3.3 N63: ImportRecheck::risk – script, handlers, script addresses and plugins are risky (2), a raw < or > in an attribute value is (1), the rest is fine (0)', array_map(Kaleta\Core\ImportRecheck::risk(...), [
    '<p>Hi <a href="https://x.example/">x</a></p>', '<p class="lead">Fine&nbsp;text</p>', '<p><img alt="<b>x</b>" src="a.jpg"></p>', '<p title="a > b">x</p>', '<img src=x onerror=alert(1)>',
    '<a href=" java&#10;script:alert(1)">x</a>', '<script>x()</script>', '<object data="x.swf"></object>', '<iframe srcdoc="<b>x</b>"></iframe>', '<img src="data:image/png;base64,AAAA">',
    '<a href="data:text/html,x">x</a>', '<img srcset="/a.jpg 1x, javascript:x 2x">', '<meta http-equiv="refresh" content="0;url=/">', 'plain text']),
    [0, 0, 1, 1, 2, 2, 2, 2, 2, 0, 2, 2, 2, 0]);
check('3.3.3 N63: ImportRecheck::html keeps safe HTML byte for byte, writes raw attribute text out again, and sanitizes risky HTML the way the import did', [
    Kaleta\Core\ImportRecheck::html('<p class="lead">v&nbsp;Praze <a href="/x">x</a></p>', $n63Wp), Kaleta\Core\ImportRecheck::html('<p><img alt="<b>x</b>" src="a.jpg"></p>', $n63Wp),
    Kaleta\Core\ImportRecheck::html('<p>Hi<img src="a.jpg" onerror="alert(1)"></p>', Kaleta\Core\Html::safe(...)), Kaleta\Core\ImportRecheck::html('<p><a href="javascript:alert(1)">x</a> ok</p>', $n63Wp)],
    ['<p class="lead">v&nbsp;Praze <a href="/x">x</a></p>', '<p><img alt="&lt;b&gt;x&lt;/b&gt;" src="a.jpg"></p>', '<p>Hi<img src="a.jpg"></p>', '<p>x ok</p>']);
$n63Safe = '{"v":1,"deti":[{"id":"txt001","typ":"text","znacka":"div","obsah":{"html":"<p>Fine</p>"}},{"id":"htm001","typ":"html","znacka":"div","obsah":{"html":"<script>own()</script>"}}]}';
$n63Risky = Kaleta\Core\ImportRecheck::build('{"v":1,"deti":[{"id":"txt001","typ":"text","znacka":"div","obsah":{"html":"<p onclick=\"x()\">T</p>"}},{"id":"btn001","typ":"tlacitko","obsah":{"text":"Go","odkaz":"javascript:alert(1)"}},{"id":"htm001","typ":"html","znacka":"div","obsah":{"html":"<script>own()</script>"}}]}');
check('3.3.3 N63: ImportRecheck::build leaves a safe build as it is (Custom HTML is the administrator\'s), sanitizes a risky one and keeps its Custom HTML; a second pass changes nothing', [
    Kaleta\Core\ImportRecheck::build($n63Safe) === $n63Safe, str_contains((string) $n63Risky, 'onclick'), str_contains((string) $n63Risky, 'javascript:'), str_contains((string) $n63Risky, '<script>own()</script>'),
    Kaleta\Core\ImportRecheck::build((string) $n63Risky) === $n63Risky, Kaleta\Core\ImportRecheck::build('not json')],
    [true, false, false, true, true, null]);
check('3.3.3 N63: migration 0074 is a data migration of this release, its background job finishes large sites, System status reports it', [
    in_array('0074-imported-content-recheck', Kaleta\Core\Migration::DATA, true), KALETA_DB_VERSION >= 74, Kaleta\Core\Scheduler::JOBS['import_recheck'][0] ?? null,
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Health.php'), 'ImportRecheck::state('), Kaleta\Core\Settings::DEFAULTS['imported_recheck'] ?? null],
    [true, true, 0, true, '']);
