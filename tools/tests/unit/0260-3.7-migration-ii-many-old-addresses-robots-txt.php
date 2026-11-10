<?php

declare(strict_types=1);

/* ---------- 3.7: migration II – many old addresses, robots.txt, items in batches and from CSV/JSON ---------- */
check('3.7 WebImport: the import and the report go past 300 addresses (fysiomed.eu has about 1,050)', Kaleta\Core\WebImport::MAX_PAGES >= 2000, true);
$robots = Kaleta\Core\WebImport::robots("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /private/ # internal\nDisallow: /*.pdf$\nAllow: /private/open\nCrawl-delay: 1.5\n\nSitemap: https://a.cz/s.xml\n");
check('3.7 WebImport::robots – the group for every robot: the longest rule wins, Allow wins a tie, * and $ as search engines read them, Crawl-delay',
    [Kaleta\Core\WebImport::robotsAllow($robots, 'https://a.cz/private/x'), Kaleta\Core\WebImport::robotsAllow($robots, 'https://a.cz/private/open/1'), Kaleta\Core\WebImport::robotsAllow($robots, 'https://a.cz/a.pdf'),
        Kaleta\Core\WebImport::robotsAllow($robots, 'https://a.cz/a.pdf?x=1'), Kaleta\Core\WebImport::robotsAllow($robots, 'https://a.cz/p/1/'), Kaleta\Core\WebImport::delay($robots)],
    [false, true, false, true, true, 1.5]);
check('3.7 WebImport::robots – a group for Kaleta-import wins, an empty Disallow allows everything, the default pause without Crawl-delay, a long one capped',
    [Kaleta\Core\WebImport::robotsAllow(Kaleta\Core\WebImport::robots("User-agent: *\nDisallow: /\n\nUser-agent: Kaleta-import\nDisallow:\n"), 'https://a.cz/x'),
        Kaleta\Core\WebImport::robotsAllow(Kaleta\Core\WebImport::robots("User-agent: *\nUser-agent: bingbot\nDisallow: /\n"), 'https://a.cz/x'), Kaleta\Core\WebImport::robotsAllow([], 'https://a.cz/x'),
        Kaleta\Core\WebImport::delay(Kaleta\Core\WebImport::robots("User-agent: *\nDisallow: /x\n")), Kaleta\Core\WebImport::delay(['delay' => 30.0]), Kaleta\Core\WebImport::delay(['delay' => 0.0])],
    [true, false, true, Kaleta\Core\WebImport::DEFAULT_DELAY, Kaleta\Core\WebImport::MAX_DELAY, 0.0]);
$itemCsv = Kaleta\Builder\ItemImport::parse("\xEF\xBB\xBFName;Price;Photo;Notes\n\"Oak; table\";1 200;https://old.example/oak.jpg;\"two\nlines\"\n\n;;;\nChair;99;;\n", 'items.csv');
check('3.7 ItemImport::parse – a CSV with a BOM, semicolons, a quoted cell over two lines, empty rows left out', $itemCsv,
    [['Name', 'Price', 'Photo', 'Notes'], [['Oak; table', '1 200', 'https://old.example/oak.jpg', "two\nlines"], ['Chair', '99', '', '']]]);
check('3.7 ItemImport::parse – a Windows-1250 CSV from Excel (Redirects::toUtf8), a tab-separated one',
    [Kaleta\Builder\ItemImport::parse(Kaleta\Admin\Modules\Redirects::toUtf8("N\xE1zev,Cena\n\x8Elut\xFD st\xF9l,5\n")), Kaleta\Builder\ItemImport::parse("a\tb\n1\t2\n")],
    [[['Název', 'Cena'], [['Žlutý stůl', '5']]], [['a', 'b'], [['1', '2']]]]);
check('3.7 ItemImport::parse – JSON: {"items": […]}, nested values come up a level, a list or an object as a value is left out',
    Kaleta\Builder\ItemImport::parse('{"items":[{"name":"A","values":{"price":"1"},"visible":true},{"name":"B","tags":["x"]}]}', 'items.json'),
    [['price', 'name', 'visible', 'tags'], [['1', 'A', '1', ''], ['', 'B', '', '']]]);
check('3.7 ItemImport::parse – what cannot be imported says why', [Kaleta\Builder\ItemImport::parse(''), Kaleta\Builder\ItemImport::parse("a,b\n"), Kaleta\Builder\ItemImport::parse('{"name":"x"}'),
    Kaleta\Builder\ItemImport::parse("name\n" . str_repeat("x\n", Kaleta\Builder\ItemImport::MAX_ROWS + 1))],
    ['The file is empty.', 'The file has no rows – the first row names the columns, every further row is one item.', 'The JSON file must be a list of items: [{"name":"…","price":"…"}, …].',
        'The file has more than 5,000 rows – split it into smaller files.']);
$itemFields = [['klic' => 'price', 'popisek' => 'Price', 'typ' => 'cislo'], ['klic' => 'photo', 'popisek' => 'Photo', 'typ' => 'obrazek'], ['klic' => 'notes', 'popisek' => 'Internal notes', 'typ' => 'radky']];
check('3.7 ItemImport::automap – by the field key, by the label, the item columns by their usual names, each target once',
    Kaleta\Builder\ItemImport::automap(['Název', 'PRICE', 'Foto', 'Internal notes', 'Slug', 'price', 'Meta description', 'Whatever'], $itemFields),
    ['_name', 'price', '', 'notes', '_slug', '', '_description', '']);
check('3.7 ItemImport::cleanMapping – only known targets, each once', Kaleta\Builder\ItemImport::cleanMapping(['a', 'b', 'c', 'd'], ['_name', 'price', 'evil', 'price'], $itemFields), ['_name', 'price', '', '']);
check('3.7 ItemImport::toRows – values, slug, language; an image address waits for the download, an empty cell changes nothing',
    Kaleta\Builder\ItemImport::toRows(['pole' => $itemFields], [['Oak', '1200', 'https://old.example/oak.jpg', '', 'oak', ''], ['Chair', '', 'media/2026/10/chair.png', 'Good', '', 'de']], ['_name', 'price', 'photo', 'notes', '_slug', '_language'], 'en'),
    [[array_replace(Kaleta\Builder\ItemBatch::emptyRow(), ['name' => 'Oak', 'slug' => 'oak', 'values' => ['price' => '1200'], 'language' => 'en']),
        array_replace(Kaleta\Builder\ItemBatch::emptyRow(), ['name' => 'Chair', 'values' => ['photo' => 'media/2026/10/chair.png', 'notes' => 'Good'], 'language' => 'de'])], [[0, 'photo', 'https://old.example/oak.jpg']]]);
check('3.7 ItemBatch::fromMcp – an item that is not an object, values as a list and media as a list are refused with the reason; English keys become the row',
    [Kaleta\Builder\ItemBatch::fromMcp('x')['error'] !== '', Kaleta\Builder\ItemBatch::fromMcp(['name' => 'A', 'values' => ['x']])['error'] !== '', Kaleta\Builder\ItemBatch::fromMcp(['name' => 'A', 'media' => ['https://a']])['error'] !== '',
        array_intersect_key(Kaleta\Builder\ItemBatch::fromMcp(['id' => '5', 'name' => 'A', 'slug' => 'a', 'visible' => 'false', 'order' => 3, 'seo_title' => 'T', 'values' => ['price' => 1], 'media' => ['photo' => ' https://a.cz/x.jpg ']]),
            array_flip(['id', 'visible', 'order', 'page', 'values', 'media', 'error']))],
    [true, true, true, ['id' => 5, 'values' => ['price' => 1], 'visible' => false, 'order' => 3, 'page' => ['seo_titulek' => 'T'], 'media' => ['photo' => 'https://a.cz/x.jpg'], 'error' => '']]);

