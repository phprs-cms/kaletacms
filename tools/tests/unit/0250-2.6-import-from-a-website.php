<?php

declare(strict_types=1);

/* ---------- 2.6: import from a website ---------- */
check('2.6 WebImport::sitemap: pages and nested sitemaps', [
    Kaleta\Core\WebImport::sitemap('<?xml version="1.0"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://a.cz/</loc></url><url><loc> https://a.cz/o-nas </loc></url></urlset>'),
    Kaleta\Core\WebImport::sitemap('<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://a.cz/page-sitemap.xml</loc></sitemap></sitemapindex>'),
    Kaleta\Core\WebImport::sitemap('<html>not a sitemap'), Kaleta\Core\WebImport::sitemap('<!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><urlset><url><loc>&e;</loc></url></urlset>'),
], [[['https://a.cz/', 'https://a.cz/o-nas'], []], [[], ['https://a.cz/page-sitemap.xml']], [[], []], [[], []]]); // an external entity is never loaded
check('2.6 WebImport: addresses – normalized, absolute, links without mail and scripts', [
    Kaleta\Core\WebImport::normalize('HTTPS://A.cz/o-nas/index.html?utm_source=x&id=5#top'), Kaleta\Core\WebImport::normalize('ftp://a.cz/x'),
    Kaleta\Core\WebImport::absolute('b/c', 'https://a.cz/dir/page'), Kaleta\Core\WebImport::absolute('//cdn.a.cz/x.jpg', 'https://a.cz/'),
    Kaleta\Core\WebImport::links('<a href="/x">1</a><a href="mailto:a@a.cz">2</a><a href="javascript:alert(1)">3</a><a href="#top">4</a>', 'https://a.cz/'),
], ['https://a.cz/o-nas/?id=5', '', 'https://a.cz/dir/b/c', 'https://cdn.a.cz/x.jpg', ['https://a.cz/x']]);
$webPage = Kaleta\Core\WebImport::extract('<html><head><title>About us | Acme</title><meta name="description" content="Who we are"></head><body>'
    . '<header><nav><a href="/">Home</a></nav></header><main><h1>About us</h1><p>We build oak furniture since 1990, for homes and offices across the region. ' . str_repeat('More text. ', 10) . '</p>'
    . '<img data-src="/img/team.jpg" src="data:image/gif;base64,x" alt="Our team"><p><a href="https://www.a.cz/contact/">Contact</a> <a href="https://other.cz/">Partner</a></p>'
    . '<div class="cookie-banner">We use cookies</div><script>alert(1)</script><form><input name="q"></form></main><footer>© Acme</footer></body></html>', 'https://www.a.cz/about-us/');
check('2.6 WebImport::extract: the main content without the header, footer, cookie bar, script and form', [
    $webPage['titulek'], $webPage['popis'], str_contains($webPage['obsah'], 'oak furniture'), str_contains($webPage['obsah'], 'Home'), str_contains($webPage['obsah'], '©'),
    str_contains($webPage['obsah'], 'cookies'), str_contains($webPage['obsah'], 'alert'), str_contains($webPage['obsah'], '<form'), str_contains($webPage['obsah'], '<h1'),
    str_contains($webPage['obsah'], 'src="https://www.a.cz/img/team.jpg"'), str_contains($webPage['obsah'], 'href="/contact"'), str_contains($webPage['obsah'], 'href="https://other.cz/"'), $webPage['clanek'],
], ['About us', 'Who we are', true, false, false, false, false, false, false, true, true, true, false]);
$webArticle = Kaleta\Core\WebImport::extract('<html><head><title>New workshop</title><meta property="article:published_time" content="2025-03-04T10:00:00+01:00"></head><body><article><p>' . str_repeat('We opened a new workshop. ', 6) . '</p></article></body></html>', 'https://a.cz/2025/03/new-workshop');
check('2.6 WebImport::extract: an article with its date, the title from <title> without the site name', [$webArticle['titulek'], substr($webArticle['datum'], 0, 10), $webArticle['clanek']], ['New workshop', '2025-03-04', true]);
check('2.6 ImageDownloader: images from any public host only when the import allows it', [(new Kaleta\Core\ImageDownloader('https://a.cz', true))->isAllowedUrl('https://cdn.wix.example/x.jpg'),
    (new Kaleta\Core\ImageDownloader('https://a.cz'))->isAllowedUrl('https://cdn.wix.example/x.jpg'), (new Kaleta\Core\ImageDownloader('https://a.cz', true))->isAllowedUrl('https://user:pw@cdn.example/x.jpg')], [true, false, false]);

