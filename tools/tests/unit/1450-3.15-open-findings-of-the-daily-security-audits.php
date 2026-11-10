<?php

declare(strict_types=1);

/* ---------- 3.15: open findings of the daily security audits ---------- */
// N73: an XML comment never stays in a cleaned SVG – "<!--><img onerror=…>-->" is a comment in XML, but markup once inlined into HTML
check('3.15 N73: Svg::sanitize drops XML comments, the drawing stays',
    Kaleta\Core\Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg"><!--><img src=x onerror=alert(1)>--><g><!-- note --><rect/></g></svg>'),
    '<svg xmlns="http://www.w3.org/2000/svg"><g><rect/></g></svg>');
// N74: PHP 8.5 deprecates reading the magic $http_response_header (and PHP 9 removes it) – the response headers come from
// last_response_headers(), which passes the variable only as its PHP 8.3 fallback
$n74 = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/src', FilesystemIterator::SKIP_DOTS)) as $n74File) {
    if ($n74File instanceof SplFileInfo && $n74File->getExtension() === 'php' && $n74File->getFilename() !== 'helpers.php') {
        $n74Code = str_replace('last_response_headers($http_response_header ?? null)', '', (string) file_get_contents($n74File->getPathname()));
        if (preg_match('/\$http_response_header\b/', (string) preg_replace('#^\s*(//|\*|/\*).*$#m', '', $n74Code))) {
            $n74[] = substr($n74File->getPathname(), strlen(KALETA_SYSTEM) + 1);
        }
    }
}
check('3.15 N74: system/src reads response headers only through last_response_headers(), never the bare $http_response_header', $n74, []);
// N72: a file from a Kaleta export gets lower-case extensions (the header rules of media/.htaccess match them on any server),
// the content follows the new name, and the bytes must be what the extension says
check('3.15 N72: SiteImport::mediaTarget writes the extensions in lower case, the name and folders stay', array_map(Kaleta\Core\SiteImport::mediaTarget(...),
    ['media/2026/10/route.GPX', 'media/2026/10/Photo.JPG.webp', 'media/Docs/Price-List.PDF', 'media/2026/10/photo.jpg', 'media/x.PHP.jpg', 'media/x.Svg']),
    ['media/2026/10/route.gpx', 'media/2026/10/Photo.jpg.webp', 'media/Docs/Price-List.pdf', 'media/2026/10/photo.jpg', null, 'media/x.svg']);
check('3.15 N72: SiteImport::renameMedia renames the archive names in every text of a row, also JSON-escaped, nothing else',
    Kaleta\Core\SiteImport::renameMedia(['text' => '<img src="/media/a/Photo.JPG"><img src="/media/a/Photo.JPG.webp">', 'stavba' => '{"src":"media\/a\/Photo.JPG"}',
        'deep' => ['x' => 'https://old.example/media/a/Photo.JPG'], 'other' => 'media/a/Photo.JPGX', 'n' => 5],
        ['media/a/Photo.JPG' => 'media/a/Photo.jpg', 'media/a/Photo.JPG.webp' => 'media/a/Photo.jpg.webp']),
    ['text' => '<img src="/media/a/Photo.jpg"><img src="/media/a/Photo.jpg.webp">', 'stavba' => '{"src":"media\/a\/Photo.jpg"}',
        'deep' => ['x' => 'https://old.example/media/a/Photo.jpg'], 'other' => 'media/a/Photo.jpgX', 'n' => 5]);
$n72 = sys_get_temp_dir() . '/kaleta-n72-' . bin2hex(random_bytes(3));
ob_start();
imagepng(imagecreatetruecolor(2, 2));
file_put_contents($n72 . '.png', (string) ob_get_clean());
file_put_contents($n72 . '.html', '<!DOCTYPE html><html><body><script>alert(1)</script></body></html>');
file_put_contents($n72 . '.gpx', '<?xml version="1.0"?><gpx version="1.1"><trk><name>r</name></trk></gpx>');
check('3.15 N72: SiteImport::contentMatches – an image must be an image, an attachment must not be HTML', [
    Kaleta\Core\SiteImport::contentMatches($n72 . '.png', 'media/a/x.png'), Kaleta\Core\SiteImport::contentMatches($n72 . '.png', 'media/a/x.jpg.webp'),
    Kaleta\Core\SiteImport::contentMatches($n72 . '.html', 'media/a/x.png'), Kaleta\Core\SiteImport::contentMatches($n72 . '.html', 'media/a/x.pdf'),
    Kaleta\Core\SiteImport::contentMatches($n72 . '.gpx', 'media/a/route.gpx'), Kaleta\Core\SiteImport::contentMatches($n72 . '.png', 'media/a/notes.txt')],
    [true, true, false, false, true, true]);
array_map('unlink', [$n72 . '.png', $n72 . '.html', $n72 . '.gpx']);
// N18: the sign-in remembers the step of a code from the app (Auth::verifyCode), so Totp::step names it
check('3.15 N18: Totp::step gives the 30-second step of a valid code (also one step off), null for a wrong one', [
    Kaleta\Core\Totp::step('JBSWY3DPEHPK3PXP', Kaleta\Core\Totp::code('JBSWY3DPEHPK3PXP', 1_000_000), 30_000_000),
    Kaleta\Core\Totp::step('JBSWY3DPEHPK3PXP', Kaleta\Core\Totp::code('JBSWY3DPEHPK3PXP', 999_999), 30_000_000),
    Kaleta\Core\Totp::step('JBSWY3DPEHPK3PXP', Kaleta\Core\Totp::code('JBSWY3DPEHPK3PXP', 999_990), 30_000_000),
    Kaleta\Core\Totp::verify('JBSWY3DPEHPK3PXP', Kaleta\Core\Totp::code('JBSWY3DPEHPK3PXP', 1_000_001), 30_000_000)], [1_000_000, 999_999, null, true]);
// N21: a text from a user keeps its anchors, never an id the site's own pages and scripts look up (DOM clobbering)
check('3.15 N21: Html::safe keeps anchor ids, drops ids of the site\'s hooks (ka-…, the skip link, the cookie bar, window.L, dataLayer) and odd ones',
    Kaleta\Core\Html::safe('<h2 id="cenik">a</h2><h2 id="kontaktní-údaje">b</h2><div id="L">c</div><p id="KA-leaflet-js">d</p><p id="obsah">e</p>'
        . '<p id="cookies-lista">f</p><p id="dataLayer">g</p><p id="a b">h</p><p id="x&quot;y">i</p><p id="section_2">j</p>'),
    '<h2 id="cenik">a</h2><h2 id="kontaktní-údaje">b</h2><div>c</div><p>d</p><p>e</p><p>f</p><p>g</p><p>h</p><p>i</p><p id="section_2">j</p>');
// N48: the CSS checks of Build::code read the CSS as the browser does – escapes decoded, comments dropped
check('3.15 N48: Build::code sees through CSS escapes and comments (@\69mport, java\script), ordinary escapes stay',
    Kaleta\Builder\Build::code('<style>@\69mport url(x.css); .a{}</style><style>.b{color:red}</style><p style="background:url(java\script:alert(1))">x</p>'
        . '<p style="color:\72 ed">y</p><style>@im/**/port url(y.css)</style>'),
    '<style>.b{color:red}</style><p>x</p><p style="color:\72 ed">y</p>');
// N49: the state files of the website import, the migration report and the collection items are not offered as Kaleta exports
check('3.15 N49: SiteImport::isValidName leaves out every import state file, an upload named web.json still counts', array_map(Kaleta\Core\SiteImport::isValidName(...),
    ['web-0123456789abcdef.json', 'parita-0123456789abcdef.json', 'polozky-0123456789abcdef.json', 'polozky-0123456789abcdef.radky.json', 'web.json', 'web-stary.zip', 'parita.json']),
    [false, false, false, false, true, true, true]);
