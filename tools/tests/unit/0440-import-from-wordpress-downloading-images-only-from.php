<?php

declare(strict_types=1);

/* ---------- import from WordPress: downloading images only from the old site and only from public URLs (SSRF protection) ---------- */
$wpDownload = new Kaleta\Core\ImageDownloader('https://www.stary-web.example/blog/');
check('StahovaniObrazku: doména starého webu bez www', $wpDownload->domain(), 'stary-web.example');
foreach ([
    'https://www.stary-web.example/wp-content/uploads/a.jpg' => true,
    'http://stary-web.example/a.png' => true,
    'https://STARY-WEB.example./a.png' => true,
    'https://stary-web.example:443/a.png' => true,
    'https://stary-web.example:8443/a.png' => false,                 // another port
    'http://stary-web.example:22/a.png' => false,
    'https://cdn.stary-web.example/a.png' => false,                  // another (sub)domain
    'https://stary-web.example.utocnik.example/a.png' => false,
    'https://utocnik.example/stary-web.example/a.png' => false,
    'https://stary-web.example@utocnik.example/a.png' => false,      // a domain hidden behind a user name
    'https://uzivatel:heslo@stary-web.example/a.png' => false,       // credentials in the URL
    'ftp://stary-web.example/a.png' => false,
    'file:///etc/passwd' => false,
    'gopher://stary-web.example/' => false,
    '//stary-web.example/a.png' => false,
    'http://127.0.0.1/a.png' => false,
    'http://169.254.169.254/latest/meta-data/' => false,
    "https://stary-web.example/a.png\r\nHost: jinam" => false,       // injected headers
    'https://stary-web.example\\@utocnik.example/a.png' => false,
    '' => false,
] as $wpUrl => $expectedResult) {
    check('StahovaniObrazku::povolenaAdresa ' . json_encode((string) $wpUrl), $wpDownload->isAllowedUrl((string) $wpUrl), $expectedResult);
}
check('StahovaniObrazku: bez adresy starého webu se nestahuje nic', (new Kaleta\Core\ImageDownloader(''))->isAllowedUrl('https://cokoli.example/a.png'), false);
foreach ([
    '93.184.216.34' => true, '8.8.8.8' => true, '172.32.0.1' => true, '100.128.0.1' => true, '2606:4700:4700::1111' => true, '::ffff:93.184.216.34' => true,
    '10.0.0.5' => false, '172.16.0.1' => false, '172.31.255.255' => false, '192.168.1.1' => false, '127.0.0.1' => false, '127.255.255.254' => false,
    '169.254.169.254' => false, '100.64.0.1' => false, '100.127.255.255' => false, '0.0.0.0' => false, '0.1.2.3' => false, '224.0.0.1' => false, '255.255.255.255' => false,
    '192.0.2.10' => false, '198.18.0.1' => false, '::1' => false, '::' => false, 'fc00::1' => false, 'fd12:3456::1' => false, 'fe80::1' => false, 'ff02::1' => false,
    '::ffff:10.0.0.1' => false, '::ffff:127.0.0.1' => false, '64:ff9b::a00:1' => false, '::10.0.0.1' => false, '2002:a00:1::1' => false, '2001:0:4136:e378:8000:63bf:3fff:fdd2' => false,
    '2001:4860:4860::8888' => true, '[::1]' => false, 'neni-ip' => false, '' => false,
] as $wpIp => $expectedResult) {
    check('StahovaniObrazku::verejnaIp ' . $wpIp, Kaleta\Core\ImageDownloader::isPublicIp((string) $wpIp), $expectedResult);
}
check('StahovaniObrazku: IP adresa místo domény se posuzuje stejně', [$wpDownload->verifiedIp('127.0.0.1'), $wpDownload->verifiedIp('[::1]'), $wpDownload->verifiedIp('93.184.216.34')], [null, null, '93.184.216.34']);
check('StahovaniObrazku: přesměrování na jinou doménu neprojde dalším kolem kontroly', $wpDownload->isAllowedUrl(Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', 'https://utocnik.example/a.png')), false);
check('StahovaniObrazku: přesměrování //jinam a do vnitřní sítě neprojde', [$wpDownload->isAllowedUrl(Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', '//utocnik.example/a.png')), $wpDownload->isAllowedUrl(Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/a.png', 'http://169.254.169.254/'))], [false, false]);
check('StahovaniObrazku: relativní přesměrování zůstává na starém webu', [Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/u/a.png', '/jinde/b.png'), Kaleta\Core\ImageDownloader::redirectTarget('https://stary-web.example/u/a.png', 'b.png')], ['https://stary-web.example/jinde/b.png', 'https://stary-web.example/u/b.png']);
$wpPng = (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
$wpSvg = '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"><script>alert(1)</script></svg>';
check('StahovaniObrazku::typObrazku: PNG podle hlavičky i obsahu', Kaleta\Core\ImageDownloader::imageType('image/png; charset=binary', $wpPng), 'image/png');
check('StahovaniObrazku::typObrazku: hlavička tvrdí obrázek, obsah je HTML', Kaleta\Core\ImageDownloader::imageType('image/jpeg', '<html><body>přihlášení</body></html>'), null);
check('StahovaniObrazku::typObrazku: obsah je obrázek, hlavička ne', Kaleta\Core\ImageDownloader::imageType('text/html', $wpPng), null);
check('StahovaniObrazku::typObrazku: SVG se odmítá vždy', [Kaleta\Core\ImageDownloader::imageType('image/svg+xml', $wpSvg), Kaleta\Core\ImageDownloader::imageType('image/png', $wpSvg)], [null, null]);
check('StahovaniObrazku::typObrazku: prázdná odpověď', Kaleta\Core\ImageDownloader::imageType('image/png', ''), null);
check('StahovaniObrazku: limity podle zadání (15 MB, 3 přesměrování, 5 s spojení, 20 s celkem)', [Kaleta\Core\ImageDownloader::MAX_BYTES, Kaleta\Core\ImageDownloader::MAX_REDIRECTS, Kaleta\Core\ImageDownloader::CONNECT_TIMEOUT, Kaleta\Core\ImageDownloader::TOTAL_TIMEOUT], [15 * 1024 * 1024, 3, 5, 20]);
$wpSourceHtml = (string) file_get_contents(KALETA_ROOT . '/system/src/Core/ImageDownloader.php');
check('[source-pin] StahovaniObrazku: přesměrování se nikdy nenásledují automaticky a nic se neposílá navíc', [substr_count($wpSourceHtml, 'CURLOPT_FOLLOWLOCATION => false'), str_contains($wpSourceHtml, "'follow_location' => 0"), (bool) preg_match('/CURLOPT_(COOKIE\w*|USERPWD|HTTPHEADER|HTTPAUTH)\b/', $wpSourceHtml), str_contains($wpSourceHtml, "'Kaleta-import'")], [1, true, false, true]);

