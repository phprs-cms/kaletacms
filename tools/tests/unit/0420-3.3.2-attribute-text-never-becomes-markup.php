<?php

declare(strict_types=1);

/* ---------- 3.3.2: attribute text never becomes markup – sanitized HTML is changed on the DOM only (N23, N30, N6) ---------- */
// what a browser would run: a script-capable element or an on… attribute anywhere in the parsed HTML
$liveMarkup = static function (string $html): bool {
    $doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8');
    foreach ($doc->querySelectorAll('*') as $el) {
        if (in_array(strtolower($el->localName), ['svg', 'script', 'iframe', 'object', 'embed', 'math'], true)) {
            return true;
        }
        foreach ($el->attributes as $a) {
            if (str_starts_with(strtolower($a->name), 'on')) {
                return true;
            }
        }
    }

    return false;
};
// the two payloads of the 2026-10-06 audit: an image alt with markup, and text that fooled the website import's class/id strip
$n23Img = '<p>Photo</p><img src="https://old.example/a.jpg" alt="q><svg onload=alert(2)>">';
$n23Ids = '<p>' . str_repeat('Our workshop makes oak tables. ', 4) . ' id="</p><p title="><svg onload=alert(1)>">b</p>';
$n23Clean = Kaleta\Core\WpContent::sanitize($n23Img);
check('3.3 booking: a setting keeps the limits of the Bookings form, also over MCP', [Kaleta\Core\Booking::settingValue('booking_hold_hours', '0'), Kaleta\Core\Booking::settingValue('booking_hold_hours', '5000'),
    Kaleta\Core\Booking::settingValue('booking_lead_hours', 'abc'), Kaleta\Core\Booking::settingValue('booking_pending_mail', ' <b>Hi</b> {name} '), Kaleta\Core\Booking::settingValue('site_name', 'x')],
    ['1', '720', null, 'Hi {name}', null]);
check('3.3.2 N23: the sanitizers escape < and > inside attribute values', [$n23Clean, Kaleta\Core\Html::safe('<p title="a<b>c">x</p>'), Kaleta\Core\Html::safe('<xmp><img src=x onerror=alert(1)></xmp>')],
    ["<p>Photo</p>\n<figure><img src=\"https://old.example/a.jpg\" alt=\"q&gt;&lt;svg onload=alert(2)&gt;\" loading=\"lazy\"></figure>", '<p title="a&lt;b&gt;c">x</p>', '']);
check('3.3.2 N23: even the old regular expressions over sanitized HTML make no markup any more', [
    $liveMarkup((string) preg_replace_callback('#<img\b[^>]*>#i', fn (): string => '<img src="/media/2026/01/a.jpg" alt="">', $n23Clean)),
    $liveMarkup((string) preg_replace('/\s(?:class|id|srcset|sizes)="[^"]*"/i', '', Kaleta\Core\Html::safe($n23Ids))),
], [false, false]);
$n23Rewritten = Kaleta\Core\WpImport::rewriteImages($n23Clean, fn (string $src, string $alt): array => Kaleta\Core\WpImport::mediaImage('', ['obr_poloha' => 'media/2026/01/a.jpg', 'obr_width' => 800, 'obr_height' => 600, 'ido' => 7], $alt));
check('3.3.2 N23: importers point images at Media on the DOM, the alt stays text', [$liveMarkup($n23Rewritten), $n23Rewritten, Kaleta\Core\WpImport::rewriteImages($n23Clean, fn (): ?array => null)],
    [false, "<p>Photo</p>\n<figure><img src=\"/media/2026/01/a.jpg\" alt=\"q&gt;&lt;svg onload=alert(2)&gt;\" width=\"800\" height=\"600\" data-id=\"7\" loading=\"lazy\"></figure>", $n23Clean]);
$n23Page = Kaleta\Core\WebImport::extract('<html><body><main><h1>Workshop</h1>' . $n23Ids . $n23Img . '</main></body></html>', 'https://old.example/workshop');
check('3.3.2 N23: website import – no markup from the class/id strip, nor when an image download fails', [
    $liveMarkup($n23Page['obsah']), $liveMarkup(Kaleta\Core\Html::rewriteImages($n23Page['obsah'], fn (): bool => false)), str_contains($n23Page['obsah'], 'Our workshop'),
    Kaleta\Core\WebImport::safeContent('<picture><source srcset="a.webp" type="image/webp"><img src="https://x.example/a.jpg" srcset="a.jpg 2x" alt="A"></picture><p class="x" id="y">t</p>'),
], [false, false, true, '<img src="https://x.example/a.jpg" alt="A"><p>t</p>']);
check('3.3.2 N23: WordPress images – the old site\'s data-id goes, the Media number of a rewrite stays', [Kaleta\Core\WpContent::sanitize('<img src="https://old.example/a.jpg" alt="" data-id="99">'), Kaleta\Core\WpContent::safeHtml('<img src="/media/a.jpg" alt="" data-id="7">')],
    ['<figure><img src="https://old.example/a.jpg" alt="" loading="lazy"></figure>', '<figure><img src="/media/a.jpg" alt="" data-id="7" loading="lazy"></figure>']);
// text stored before 3.3.2 can have a raw > inside an attribute: what the site and the assistant do with it must stay inert
$n23Legacy = '<p><a href="https://www.youtube.com/watch?v=dQw4w9WgXcQ" title="a>b</a></p><img src=x onerror=alert(1)>">video</a></p><p>Rest</p>';
$n23Parts = Kaleta\Core\Assistant::decompose('<p title="x>y">Ahoj</p><p title=" onmouseover=alert(1) z">Svete</p>');
check('3.3.2 N23: video embedding and the translation skeleton work on the DOM, older text stays inert', [
    $liveMarkup($types->embedVideoUrls($n23Legacy)), substr_count($types->embedVideoUrls($n23Legacy), 'data-vlozit'),
    $liveMarkup(Kaleta\Core\Assistant::compose($n23Parts['kostra'], $n23Parts['useky'])),
], [false, 1, false]);
check('3.3.2 N30: custom HTML keeps no <select> family, no < in raw text and no raw < or > in attributes', [
    Kaleta\Builder\Build::code('<select><option>a</option><img src=x onerror=alert(1)></select><datalist><option value="x"></datalist><selectedcontent></selectedcontent>'),
    Kaleta\Builder\Build::code('<style>p::after{content:"<b>"}</style>'), Kaleta\Builder\Build::code('<iframe src="https://mapy.cz/x"><img src=x onerror=alert(1)></iframe>'),
    Kaleta\Builder\Build::code('<p title="</p><img src=x onerror=alert(1)>">x</p>'), Kaleta\Builder\Build::code('<svg><style><a onclick="x()">y</a></style></svg>'),
], ['', '<style>p::after{content:"\3c b>"}</style>', '<iframe src="https://mapy.cz/x"></iframe>', '<p title="&lt;/p&gt;&lt;img src=x onerror=alert(1)&gt;">x</p>', '<svg><style><a>y</a></style></svg>']);
// 3.33: a video file is a Video element now (its address checked like every link) – still no Custom HTML
check('3.3.2 N30: imported pages are converted without administrator rights – no Custom HTML', array_column(Kaleta\Builder\HtmlConverter::convert('<p>Text</p><video src="https://old.example/v.mp4" controls></video><svg><circle r="1"></circle></svg>', false)['stavba']['deti'][0]['deti'] ?? [], 'typ'), ['text', 'video']);
$n6Html = (new ReflectionMethod(Kaleta\Core\SiteImport::class, 'html'))->invoke(null, '<p class="lead" onclick="x()">Hi <a href="javascript:alert(1)">x</a></p><script>alert(1)</script><figure class="galerie"><img src="media/2026/01/a.jpg" alt="A" width="10" height="10" loading="lazy" data-id="5"></figure><iframe src="https://www.youtube.com/embed/dQw4w9WgXcQ"></iframe>', 100000);
check('3.3.2 N6: Kaleta export – page and news HTML sanitized like a save without code rights, the content kept', trim($n6Html),
    "<p class=\"lead\">Hi <a>x</a></p><figure class=\"galerie\"><img src=\"media/2026/01/a.jpg\" alt=\"A\" width=\"10\" height=\"10\" loading=\"lazy\" data-id=\"5\"></figure>\n\n<p>https://www.youtube.com/watch?v=dQw4w9WgXcQ</p>");

