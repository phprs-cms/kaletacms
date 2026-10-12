<?php

declare(strict_types=1);

/*
 * A fake old site with many pages (3.7) for tools/test.sh – the import from a website and the migration report must go
 * past the old limit of 300 addresses: php -S 127.0.0.1:<port> tools/fake-old-site.php
 *
 *  - /robots.txt: /private/ is disallowed for every robot, Crawl-delay 0 (the test does not wait), the sitemap index;
 *  - /sitemap_index.xml → /sitemap-1.xml (pages 1–200) and /sitemap-2.xml (pages 201–350 and /private/secret/);
 *  - /, /p/<n>/ and /private/secret/: small HTML pages; /img/<name>.png: a PNG image (for the CSV import of items);
 *  - every request is appended to the file named by KALETA_FAKE_LOG (one path per line), so the test can see that
 *    a disallowed page was never downloaded;
 *  - /slow/<name>.png: the same image after 3 seconds (an item batch is still downloading while a person edits the item).
 *
 * KALETA_FAKE_MODE (3.7, the follow-up audit N37-23 and N37-24) turns it into a hostile old site:
 *  - robots: a 1 MB robots.txt of wildcard rules (Disallow: /blocked/ first), no sitemap, and a home page with 2,000 links;
 *  - sitemaps: robots.txt names sitemaps on other hosts, the sitemap index lists three sitemaps of 2,000 pages each,
 *    150 more sitemaps of this host (over the queue limit) and one on another host;
 *  - wordpress (3.12): the live side of a WordPress export named by KALETA_FAKE_WXR (tools/fixtures/wordpress-scale.php
 *    --site=http://127.0.0.1:<port>) – a sitemap of every published post, page and product and of the product category
 *    pages (/product-category/…, /ar/product-category/…), each of them a small page with its title, and a JPEG for every
 *    image under /wp-content/uploads/.
 */
const PAGES = 350;

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$log = (string) getenv('KALETA_FAKE_LOG');
if ($log !== '') {
    file_put_contents($log, $path . "\n", FILE_APPEND | LOCK_EX);
}
$origin = 'http://' . (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
$urlset = fn (array $paths): string => '<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
    . implode('', array_map(fn (string $p): string => '<url><loc>' . $origin . $p . '</loc></url>', $paths)) . '</urlset>';
$mode = (string) getenv('KALETA_FAKE_MODE');
$page = fn (string $title): string => '<!doctype html><html><head><title>' . $title . ' | Old Shop</title></head><body><header><nav><a href="/">Home</a></nav></header>'
    . '<main><h1>' . $title . '</h1><p>' . str_repeat('A product of the old shop, made with care and sold for many years. ', 3) . '</p></main></body></html>';

if ($mode === 'robots') {
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain');
        $rules = "User-agent: *\nCrawl-delay: 0\nDisallow: /blocked/\n";
        for ($n = 0; strlen($rules) < 1024 * 1024; $n++) {
            $rules .= 'Disallow: /*x' . $n . "*y*z*w$\n";
        }
        echo $rules;
    } elseif ($path === '/') {
        header('Content-Type: text/html; charset=utf-8');
        echo '<!doctype html><html><head><title>Many links</title></head><body><main><p><a href="/blocked/x">Hidden</a> '
            . implode(' ', array_map(fn (int $n): string => '<a href="/l/' . $n . '/">Page ' . $n . '</a>', range(1, 2000))) . '</p></main></body></html>';
    } elseif (preg_match('#^/(l/\d+|blocked/x)/?$#', $path) === 1) {
        header('Content-Type: text/html; charset=utf-8');
        echo $page('Linked page');
    } else {
        http_response_code(404);
        echo 'Not found';
    }

    return true;
}
if ($mode === 'wordpress') {
    // the published addresses of the export (links of posts, pages and products) and the product category pages
    $addresses = [];
    $xml = (string) file_get_contents((string) getenv('KALETA_FAKE_WXR'));
    preg_match_all('#<item>.*?</item>#s', $xml, $items);
    foreach ($items[0] as $item) {
        if (preg_match('#<wp:post_type><!\[CDATA\[(post|page|product)\]\]></wp:post_type>#', $item) === 1 && str_contains($item, '<wp:status><![CDATA[publish]]>')
            && preg_match('#<link>([^<]+)</link>#', $item, $link) === 1 && preg_match('#<title><!\[CDATA\[(.*?)\]\]></title>#s', $item, $title) === 1) {
            $addresses[(string) parse_url($link[1], PHP_URL_PATH)] = $title[1];
        }
    }
    preg_match_all('#<wp:term><wp:term_id>\d+</wp:term_id><wp:term_taxonomy>product_cat</wp:term_taxonomy><wp:term_slug>([^<]+)</wp:term_slug><wp:term_parent>([^<]*)</wp:term_parent><wp:term_name><!\[CDATA\[(.*?)\]\]>#', $xml, $terms, PREG_SET_ORDER);
    foreach ($terms as [, $slug, $parent, $name]) {
        $arabic = str_starts_with($slug, '%'); // the generator's Arabic categories have percent-encoded Arabic slugs
        $addresses[($arabic ? '/ar' : '') . '/product-category/' . ($parent !== '' ? $parent . '/' : '') . $slug . '/'] = $name;
    }
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain');
        echo "User-agent: *\nCrawl-delay: 0\n\nSitemap: {$origin}/sitemap.xml\n";
    } elseif ($path === '/sitemap.xml') {
        header('Content-Type: application/xml');
        echo $urlset(array_map('strval', array_keys($addresses)));
    } elseif (($title = array_column(array_map(null, array_map('rawurldecode', array_keys($addresses)), $addresses), 1, 0)[rawurldecode($path)] ?? null) !== null) {
        header('Content-Type: text/html; charset=utf-8'); // Arabic addresses are compared decoded: a client may change the case of %d8
        echo $page(htmlspecialchars((string) $title));
    } elseif (preg_match('#^/wp-content/uploads/\d{4}/\d{2}/[a-z0-9-]+\.jpg$#', $path) === 1) {
        header('Content-Type: image/jpeg');
        $image = imagecreatetruecolor(640, 480);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, crc32($path) & 255, 120, 90));
        imagejpeg($image, null, 80);
    } else {
        http_response_code(404);
        echo 'Not found';
    }

    return true;
}
if ($mode === 'sitemaps') {
    $port = (int) ($_SERVER['SERVER_PORT'] ?? 80);
    if ($path === '/robots.txt') {
        header('Content-Type: text/plain');
        echo "User-agent: *\nCrawl-delay: 0\n\nSitemap: http://localhost:{$port}/foreign-1.xml\nSitemap: http://cdn.invalid/x.xml\nSitemap: {$origin}/sitemap_index.xml\n";
    } elseif ($path === '/sitemap_index.xml') {
        header('Content-Type: application/xml');
        $maps = ['/sm-1.xml', '/sm-2.xml', '/sm-3.xml', ...array_map(fn (int $n): string => '/sm-x' . $n . '.xml', range(1, 150))];
        echo '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
            . implode('', array_map(fn (string $m): string => '<sitemap><loc>' . $origin . $m . '</loc></sitemap>', $maps))
            . '<sitemap><loc>http://localhost:' . $port . '/foreign-2.xml</loc></sitemap></sitemapindex>';
    } elseif (preg_match('#^/sm-(\d)\.xml$#', $path, $m) === 1) {
        header('Content-Type: application/xml');
        echo $urlset(array_map(fn (int $n): string => '/s' . $m[1] . '/' . $n . '/', range(1, 2000)));
    } elseif (preg_match('#^/sm-x\d+\.xml$#', $path) === 1) {
        header('Content-Type: application/xml');
        echo $urlset(['/extra' . substr($path, 5, -4) . '/']);
    } else {
        http_response_code(404);
        echo 'Not found';
    }

    return true;
}
if ($path === '/robots.txt') {
    header('Content-Type: text/plain');
    echo "User-agent: *\nDisallow: /private/\nCrawl-delay: 0\n\nSitemap: {$origin}/sitemap_index.xml\n";
} elseif ($path === '/sitemap_index.xml') {
    header('Content-Type: application/xml');
    echo '<?xml version="1.0" encoding="UTF-8"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'
        . '<sitemap><loc>' . $origin . '/sitemap-1.xml</loc></sitemap><sitemap><loc>' . $origin . '/sitemap-2.xml</loc></sitemap></sitemapindex>';
} elseif ($path === '/sitemap-1.xml' || $path === '/sitemap-2.xml') {
    header('Content-Type: application/xml');
    $range = $path === '/sitemap-1.xml' ? range(1, 200) : range(201, PAGES);
    echo $urlset([...array_map(fn (int $n): string => '/p/' . $n . '/', $range), ...($path === '/sitemap-2.xml' ? ['/private/secret/'] : [])]);
} elseif ($path === '/' || preg_match('#^/p/(\d+)/$#', $path, $m) === 1 && (int) $m[1] >= 1 && (int) $m[1] <= PAGES) {
    header('Content-Type: text/html; charset=utf-8');
    echo $page($path === '/' ? 'Old Shop' : 'Product ' . ($m[1] ?? ''));
} elseif ($path === '/private/secret/') {
    header('Content-Type: text/html; charset=utf-8');
    echo $page('Secret');
} elseif (preg_match('#^/(img|slow)/[a-z0-9-]+\.png$#', $path) === 1) {
    if (str_starts_with($path, '/slow/')) {
        sleep(3);
    }
    header('Content-Type: image/png');
    $image = imagecreatetruecolor(320, 240);
    imagefill($image, 0, 0, (int) imagecolorallocate($image, 40, 120, 90));
    imagepng($image);
} else {
    http_response_code(404);
    echo 'Not found';
}

return true;
