<?php

declare(strict_types=1);

/* ---------- 3.8: size and nesting limits before any HTML is parsed (Core\HtmlLimits) ---------- */
use Kaleta\Core\HtmlLimits;
use Kaleta\Core\HtmlTooLarge;

// the N37-3 shapes (and the one that made PHP 8.4's own parser use 2 GB): refused in well under a second each, by the limit
// that is passed first; "p soup" is linear for every parser and stays allowed (39,000 elements)
$limitShapes = $pathological + ['reopened in divs' => implode('', array_map(fn (int $i): string => '<div><b id=' . $i . '></div>', range(1, 3000))) . str_repeat('<p>x</p>', 3000),
    '160,000 divs' => str_repeat('<div>', 160000), 'oversized' => str_repeat('x', HtmlLimits::MAX_BYTES + 1)];
$limitResults = [];
$limitSlow = [];
foreach ($limitShapes as $shape => $html) {
    $started = microtime(true);
    $limitResults[$shape] = HtmlLimits::check($html)['limit'] ?? 'allowed';
    if (($took = microtime(true) - $started) > 1.0) {
        $limitSlow[] = $shape . ' ' . round($took, 2) . ' s';
    }
}
check('3.8: every pathological shape is refused fast, by the right limit', [$limitResults, $limitSlow], [[
    'unclosed div' => 'depth', 'p in button scope' => 'depth', 'p soup' => 'allowed', 'nested formatting' => 'depth', 'reopened formatting' => 'elements',
    'formatting with ids' => 'elements', 'misnested a' => 'depth', 'many attributes' => 'attributes', 'unclosed li' => 'depth', 'svg' => 'depth', 'table' => 'depth',
    'reopened in divs' => 'elements', '160,000 divs' => 'depth', 'oversized' => 'bytes'], []]);
// the measured value is in the violation and in the messages (English for MCP and logs, the admin's language in forms)
$limitDeep = HtmlLimits::check(str_repeat('<div>', 40000));
check('3.8: a refusal names the limit and the measured value', [$limitDeep, HtmlLimits::english($limitDeep ?? ['limit' => '', 'value' => 0, 'max' => 0]),
    (new HtmlTooLarge($limitDeep ?? ['limit' => '', 'value' => 0, 'max' => 0], 'text'))->getMessage()],
    [['limit' => 'depth', 'value' => 40000, 'max' => 512], 'The markup is nested 40,000 levels deep; the limit is 512.', 'text: The markup is nested 40,000 levels deep; the limit is 512.']);
// the model of the parser: what it does not see is markup – comments, raw text, attribute values, CDATA in SVG, script escapes
check('3.8: the pre-scan skips comments, raw text, attribute values and script escapes; implied end tags close', [
    HtmlLimits::measure(str_repeat('<!--<div>-->', 600) . '<style>' . str_repeat('<div>', 600) . '</style><textarea>' . str_repeat('<div>', 600) . '</textarea>'
        . '<p title="' . str_repeat('<div>', 600) . '">x</p><script><!--<script></script>' . str_repeat('<div>', 600) . '</script>--></script>'),
    HtmlLimits::measure('<svg><![CDATA[' . str_repeat('<g>', 600) . ']]><g/><g/></svg>'),
    HtmlLimits::measure(str_repeat('<p>x', 2000) . '<ul>' . str_repeat('<li>x', 2000) . '</ul><table>' . str_repeat('<tr><td>a<td>b', 2000) . '</table><dl>' . str_repeat('<dt>a<dd>b', 2000)),
    HtmlLimits::measure(str_repeat('<div>', 600), true)['depth'], HtmlLimits::check(str_repeat('<g>', 300), true)['limit'] ?? null,
], [['depth' => 1, 'elements' => 4, 'attributes' => 1], ['depth' => 2, 'elements' => 3, 'attributes' => 0], ['depth' => 4, 'elements' => 14004, 'attributes' => 0], 600, 'depth']);
// the model never lets the tree outgrow it: a fixed set of snippets, each repeated 10 and 40 times – a snippet whose tree grows
// faster than the model would let pathological markup through (3.8 fuzzing on 8.4 and 8.3: 40,000 snippets, none)
mt_srand(38);
$limitTags = ['div', 'p', 'b', 'i', 'a', 'span', 'li', 'ul', 'dd', 'dt', 'table', 'tr', 'td', 'tbody', 'caption', 'col', 'svg', 'math', 'g', 'foreignObject',
    'desc', 'mi', 'font', 'nobr', 'em', 'h1', 'h2', 'form', 'button', 'select', 'option', 'optgroup', 'script', 'style', 'title', 'textarea', 'template', 'object',
    'marquee', 'br', 'img', 'input', 'ruby', 'rb', 'rt', 'rtc', 'section', 'small', 'sub', 'sup', 'label', 'details', 'address', 'frameset', 'noscript'];
$limitAttributes = ['', '', '', ' id=1', ' id=2', ' class="x"', ' color=red', ' encoding="text/html"'];
$limitTree = static function (string $html): array {
    $doc = Dom\HTMLDocument::createFromString('<!DOCTYPE html><html><body>' . $html . '</body></html>', LIBXML_NOERROR, 'UTF-8'); // the parser itself, for comparison
    [$deepest, $count, $stack] = [0, 0, [[$doc, 0]]];
    while ($stack !== []) {
        [$node, $depth] = array_pop($stack);
        foreach ($node->childNodes as $child) {
            if ($child instanceof Dom\Element) {
                [$count, $deepest, $stack[]] = [$count + 1, max($deepest, $depth + 1), [$child, $depth + 1]];
            }
        }
    }

    return [$deepest - 2, $count - 3]; // without html and body (and head)
};
$limitFaster = [];
for ($i = 0; $i < 250; $i++) {
    $snippet = '';
    for ($k = mt_rand(2, 10); $k > 0; $k--) {
        $tag = $limitTags[mt_rand(0, count($limitTags) - 1)];
        $roll = mt_rand(0, 9);
        $snippet .= $roll < 5 ? '<' . $tag . $limitAttributes[mt_rand(0, count($limitAttributes) - 1)] . (mt_rand(0, 12) === 0 ? '/' : '') . '>' : ($roll < 8 ? '</' . $tag . '>' : ['x', ' ', '&amp;', '</p>'][mt_rand(0, 3)]);
    }
    $few = HtmlLimits::measure(str_repeat($snippet, 10));
    $many = HtmlLimits::measure(str_repeat($snippet, 40));
    [$fewDepth, $fewCount] = $limitTree(str_repeat($snippet, 10));
    [$manyDepth, $manyCount] = $limitTree(str_repeat($snippet, 40));
    // unsafe would be: the tree gets deeper faster than the model, or it gets over twice as many more elements while the model
    // does not get deeper with every repetition (if it does, the depth limit stops it after at most 512 repetitions)
    if ((PHP_VERSION_ID >= 80400 || $manyDepth < 500) && ($many['depth'] - $few['depth'] < $manyDepth - $fewDepth
        || (2 * ($many['elements'] - $few['elements']) < $manyCount - $fewCount && $many['depth'] - $few['depth'] < 30))) {
        $limitFaster[] = $snippet;
    }
}
check('3.8: the pre-scan grows at least as fast as the tree the parser builds (250 repeated snippets)', $limitFaster, []);

// each kind of place that parses HTML refuses the pathological input – fast, never with the input passed through
$limitDeepHtml = str_repeat('<div><b>', 20000);
$limitDeepPage = '<!DOCTYPE html><html><head><title>Old</title></head><body><main>' . $limitDeepHtml . '</main></body></html>';
$limitTimed = static function (callable $work): mixed {
    $started = microtime(true);
    try {
        $result = $work();
    } catch (HtmlTooLarge $e) {
        $result = 'refused: ' . $e->violation['limit'];
    }

    return microtime(true) - $started < 2.0 ? $result : 'slow';
};
[, $limitBuildErrors] = Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['typ' => 'sekce', 'deti' => [
    ['id' => 'kod1', 'typ' => 'html', 'obsah' => ['kod' => str_repeat('<div>', 4000)]], ['id' => 'txt1', 'typ' => 'text', 'obsah' => ['html' => str_repeat('<b>', 1000)]]]]]], true);
$limitTooLarge = null;
$limitBuildErrors2 = [];
$limitItem =Kaleta\Builder\Collections::sanitizeData([['klic' => 'popis', 'popisek' => 'Description', 'typ' => 'html']], ['popis' => $limitDeepHtml], $limitBuildErrors2, $limitTooLarge);
check('3.8: sanitizers, conversions, imports and the SVG cleaner refuse pathological markup', [
    $limitTimed(fn () => Kaleta\Core\Html::safe($limitDeepHtml)), $limitTimed(fn () => HtmlLimits::guard(fn () => Kaleta\Core\Html::safe($limitDeepHtml))),
    $limitTimed(fn () => Kaleta\Core\Html::safeOrFail($limitDeepHtml, 'description')), $limitTimed(fn () => Kaleta\Core\Html::transform($limitDeepHtml, fn () => null)),
    $limitTimed(fn () => Kaleta\Core\Html::rewriteImages('<img src=a>' . $limitDeepHtml, fn () => null)),
    $limitTimed(fn () => Kaleta\Core\WpContent::safeHtml($limitDeepHtml)), $limitTimed(fn () => Kaleta\Core\WpContent::sanitize($limitDeepHtml)),
    $limitTimed(fn () => Kaleta\Builder\Build::code($limitDeepHtml)), array_values($limitBuildErrors),
    $limitTimed(fn () => Kaleta\Builder\HtmlConverter::convert($limitDeepHtml)), $limitTimed(fn () => Kaleta\Core\WebImport::safeContent($limitDeepHtml)),
    $limitTimed(fn () => Kaleta\Core\WebImport::extract($limitDeepPage, 'https://old.example/a')), $limitTimed(fn () => Kaleta\Core\MigrationReport::analyse($limitDeepPage, 'https://old.example/a')),
    $limitTimed(fn () => Kaleta\Core\WebImport::sitemap('<urlset>' . str_repeat('<url>', 300) . '</urlset>')),
    $limitTimed(fn () => Kaleta\Core\Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg">' . str_repeat('<g>', 300) . str_repeat('</g>', 300) . '</svg>')),
    $limitTimed(fn () => Kaleta\Core\Svg::sanitize('<svg xmlns="http://www.w3.org/2000/svg"><path ' . implode(' ', array_map(fn (int $i): string => 'a' . $i . '="1"', range(1, 300))) . '/></svg>')),
    $limitTimed(function () use ($limitDeepHtml): int {
        $log = ini_set('error_log', '/dev/null'); // the recheck logs the refusal
        try {
            return Kaleta\Core\ImportRecheck::risk($limitDeepHtml);
        } finally {
            ini_set('error_log', (string) $log);
        }
    }), [$limitItem, $limitTooLarge['limit'] ?? null],
], ['', 'refused: depth', 'refused: depth', '', '', '', '', '', [
        'Kód je vnořený do 4000 úrovní, nejvýš smí do 512 – obsah pole vynechán.', 'Kód je vnořený do 1000 úrovní, nejvýš smí do 512 – obsah pole vynechán.'],
    'refused: depth', '', 'refused: depth', 'refused: depth', 'refused: depth', 'refused: depth', 'refused: attributes', 2, [['popis' => ''], 'depth']]);
check('3.8: the builder note over a limit has its English for Claude and the editor', Kaleta\Mcp\Translator::message(Kaleta\Builder\Build::limitNote(['limit' => 'depth', 'value' => 4000, 'max' => 512])),
    'The markup is nested 4000 levels deep; the limit is 512 – the field was left empty.');

// legitimate large input passes unchanged: a long article (2,000 paragraphs, 1,000 list items, a 500-row table), a full page
// of a big site (3 MB, 30,000 elements, 40 levels), a builder Custom HTML element of 20,000 characters, an SVG of 1.9 MB
$limitArticle = str_repeat('<h2>Section</h2><p>Some <strong>bold</strong> and <a href="/x">linked</a> text, <em>long</em> enough to read.</p>', 1000)
    . '<ul>' . str_repeat('<li>Item with <b>bold</b></li>', 1000) . '</ul><table><tbody>' . str_repeat('<tr><td>a</td><td>b</td><td>c</td></tr>', 500) . '</tbody></table>';
$limitPage = '<!DOCTYPE html><html><head><title>Big</title><style>' . str_repeat('.a>.b{color:red}', 2000) . '</style></head><body>'
    . str_repeat(str_repeat('<div class="wrap">', 30) . '<nav><ul>' . str_repeat('<li><a href="/p">Page</a></li>', 100) . '</ul></nav><p>' . str_repeat('Lorem ipsum dolor sit amet. ', 650)
        . '</p>' . str_repeat('</div>', 30) . '<script>var x = "<div>"; if (a < b) {}</script>', 140) . '</body></html>';
$limitEmbed = mb_substr('<iframe src="https://www.google.com/maps/embed?pb=' . str_repeat('x', 1000) . '" width="600" height="450" loading="lazy"></iframe>'
    . str_repeat('<div class="booking"><span class="slot">9:00</span><button type="button">Book</button></div>', 300), 0, 20000);
$limitSvg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 1000">' . str_repeat('<g><path d="M' . str_repeat('10 20 L30 40 ', 10) . 'Z" fill="#123"/></g>', 11500) . '</svg>';
$limitArticleClean = Kaleta\Core\Html::safe($limitArticle);
$limitPageMeasure = HtmlLimits::measure($limitPage);
check('3.8: large legitimate input is within the limits and comes out whole', [
    HtmlLimits::check($limitArticle), HtmlLimits::check($limitPage), $limitPageMeasure['elements'] > 25000 && $limitPageMeasure['depth'] >= 32, strlen($limitPage) > 3_000_000,
    HtmlLimits::check($limitEmbed), HtmlLimits::check($limitSvg, true), strlen($limitSvg) > 1_800_000,
    substr_count($limitArticleClean, '<li>'), substr_count($limitArticleClean, '<tr>'), substr_count(Kaleta\Core\WpContent::safeHtml($limitArticle), '<p>'),
    substr_count(Kaleta\Builder\Build::code($limitEmbed), '<button'), substr_count((string) Kaleta\Core\Svg::sanitize($limitSvg), '<path'),
    Kaleta\Core\WebImport::extract($limitPage, 'https://old.example/big')['titulek'],
], [null, null, true, true, null, null, true, 1000, 500, 1000, 205, 11500, 'Big']);
// every place that parses markup goes through HtmlLimits: no other file calls the parsers itself (the compat parser is the parser)
$limitDirect = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM, FilesystemIterator::SKIP_DOTS)) as $limitFile) {
    $limitPath = $limitFile->getPathname();
    if (str_ends_with($limitPath, '.php') && !preg_match('#/(compat|Compat)/#', $limitPath) && !str_ends_with($limitPath, '/Core/HtmlLimits.php')
        && preg_match('/createFromString\(|createFromFile\(|->loadXML\(|->loadHTML\(|loadHTMLFile\(|simplexml_load_|new \\\\?SimpleXMLElement\(|Html5Parser::parse\(/', (string) file_get_contents($limitPath))) {
        $limitDirect[] = substr($limitPath, strlen(KALETA_SYSTEM) + 1);
    }
}
check('3.8: no file parses HTML or XML past Core\HtmlLimits', $limitDirect, []);

// 3.8 N38-1: the pre-scan itself is linear – every search is remembered per needle, so floods of what makes a scanner
// search ahead (comments, script escapes, quotes, CDATA, DOCTYPE, end tags of raw text) take well under a second at the
// 5 MB limit (before: 1.9 s for 100 KB of "<!--a-->", 82 s for 640 KB). Each shape is tried at 600 KB first, so a
// quadratic scan fails fast instead of running for hours.
$scanBytes = static fn (string $piece, int $bytes, string $prefix = '', string $suffix = ''): string
    => $prefix . str_repeat($piece, intdiv($bytes - strlen($prefix) - strlen($suffix), strlen($piece))) . $suffix;
$scanShapes = [
    'comments' => ['<!--a-->'], 'unterminated comments' => ['<!--a--'], 'bang comments' => ['<!--a--!'], 'one script, many <!--' => ['<!--a', '<script>'],
    'scripts, one <!-- at the end' => ['<script>a</script>', '', '<!--'], 'escaped scripts' => ['<script><!--a</script>'],
    'double escaped scripts' => ['<script><!--<script>a</script>-->'], 'unclosed quotes' => ['<a b="x\'>'], '</ flood' => ['</'],
    '</a flood' => ['</a '], 'bogus comments' => ['</3'], '<! flood' => ['<!x'], '<? flood' => ['<?x'], 'CDATA flood in SVG' => ['<![CDATA[a]]', '<svg>'],
    'raw text near misses' => ['<style>a</stylex>'], 'textareas' => ['<textarea>a</textarea'], 'attribute floods' => ['<p a=1 b="2" c=\'3\'>x</p>'],
];
$scanSlow = [];
foreach ($scanShapes as $shape => $parts) {
    foreach ([600_000, HtmlLimits::MAX_BYTES - 100] as $bytes) {
        $html = $scanBytes($parts[0], $bytes, $parts[1] ?? '', $parts[2] ?? '');
        $started = microtime(true);
        HtmlLimits::check($html);
        if (($took = microtime(true) - $started) > ($bytes < 1_000_000 ? 0.3 : 1.0)) {
            $scanSlow[] = $shape . ' (' . round($bytes / 1_000_000, 1) . ' MB) ' . round($took, 2) . ' s';
            break;
        }
    }
}
foreach (['CDATA flood (XML)' => $scanBytes('<![CDATA[a]]', HtmlLimits::MAX_BYTES - 100, '<svg>'), 'DOCTYPE flood (XML)' => $scanBytes('<!DOCTYPE a', HtmlLimits::MAX_BYTES - 100, '', '>'),
    'processing instructions (XML)' => $scanBytes('<?a', HtmlLimits::MAX_BYTES - 100)] as $shape => $xml) {
    $started = microtime(true);
    HtmlLimits::check($xml, true);
    if (($took = microtime(true) - $started) > 1.0) {
        $scanSlow[] = $shape . ' ' . round($took, 2) . ' s';
    }
}
check('3.8 N38-1: 5 MB of every scanner-pathological shape is checked in well under a second (linear pre-scan)', $scanSlow, []);

// 3.8 N38-2: the encoding is decided once (byte order mark, the caller's encoding, <meta> in the first 1024 bytes, else
// UTF-8) and the check and the parser read the same UTF-8 string – so markup in UTF-16 or behind a <meta> label can never
// be deeper for the parser than for the check. Differential: the depth the check measures is the depth the parser builds.
$encodingDepth = static function (Dom\HTMLDocument $doc): int {
    [$deepest, $stack] = [0, [[$doc, 0]]];
    while ($stack !== []) {
        [$node, $depth] = array_pop($stack);
        foreach ($node->childNodes as $child) {
            if ($child instanceof Dom\Element) {
                [$deepest, $stack[]] = [max($deepest, $depth + 1), [$child, $depth + 1]];
            }
        }
    }

    return $deepest - 2; // without html and body
};
$encodingDivs = static fn (int $n): string => str_repeat('<div>', $n) . 'Příliš žluťoučký kůň' . str_repeat('</div>', $n);
$encodingPage = static fn (string $meta, string $body): string => '<!DOCTYPE html><html><head>' . $meta . '<title>T</title></head><body>' . $body . '</body></html>';
$encodingCases = [
    'UTF-16LE with BOM' => static fn (int $n): string => "\xFF\xFE" . mb_convert_encoding($encodingPage('', $encodingDivs($n)), 'UTF-16LE', 'UTF-8'),
    'UTF-16BE with BOM' => static fn (int $n): string => "\xFE\xFF" . mb_convert_encoding($encodingPage('', $encodingDivs($n)), 'UTF-16BE', 'UTF-8'),
    'meta charset=utf-16' => static fn (int $n): string => $encodingPage('<meta charset=utf-16>', $encodingDivs($n)),
    'windows-1250' => static fn (int $n): string => (string) iconv('UTF-8', 'Windows-1250', $encodingPage('<meta http-equiv="Content-Type" content="text/html; charset=windows-1250">', $encodingDivs($n))),
    'iso-2022-jp' => static fn (int $n): string => (string) mb_convert_encoding($encodingPage('<meta charset="iso-2022-jp">', str_repeat('<div>', $n) . '日本語' . str_repeat('</div>', $n)), 'ISO-2022-JP', 'UTF-8'),
    'bogus label (utf-7)' => static fn (int $n): string => $encodingPage('<meta charset=utf-7>', str_repeat('+ADw-div+AD4-', 1000) . $encodingDivs($n)),
];
$encodingResults = [];
foreach ($encodingCases as $case => $page) {
    $doc = HtmlLimits::document($page(100));
    $measured = HtmlLimits::measure(HtmlLimits::toUtf8($page(100), null))['depth'];
    try {
        HtmlLimits::document($page(600));
        $deep = 'parsed';
    } catch (HtmlTooLarge $e) {
        $deep = $e->violation['limit'];
    }
    $encodingResults[$case] = [$measured === $encodingDepth($doc) ? 'same' : $measured . '/' . $encodingDepth($doc), $deep,
        str_contains((string) $doc->body?->textContent, $case === 'iso-2022-jp' ? '日本語' : 'žluťoučký')];
}
// a fragment that names its own encoding (HtmlConverter lets a <meta> decide) and an SVG in UTF-16 are decided the same way
$encodingSvg = static fn (int $n): string => "\xFF\xFE" . mb_convert_encoding('<?xml version="1.0" encoding="UTF-16"?><svg xmlns="http://www.w3.org/2000/svg">'
    . str_repeat('<g>', $n) . str_repeat('</g>', $n) . '</svg>', 'UTF-16LE', 'UTF-8');
$encodingSvgDeep = 'parsed';
try {
    HtmlLimits::xml(new DOMDocument(), $encodingSvg(300), LIBXML_NONET);
} catch (HtmlTooLarge $e) {
    $encodingSvgDeep = $e->violation['limit'];
}
$encodingSvgDom = new DOMDocument();
HtmlLimits::xml($encodingSvgDom, $encodingSvg(20), LIBXML_NONET);
try {
    HtmlLimits::fragmentOrFail('<meta charset=utf-16>' . str_repeat('<div>', 600), null);
    $encodingFragment = 'parsed';
} catch (HtmlTooLarge $e) {
    $encodingFragment = $e->violation['limit'];
}
check('3.8 N38-2: the check measures what the parser builds in every encoding, and deep markup in another encoding is refused', [
    $encodingResults, $encodingSvgDom->getElementsByTagName('g')->length, $encodingSvgDeep, $encodingFragment,
], [array_fill_keys(array_keys($encodingCases), ['same', 'depth', true]), 20, 'depth', 'depth']);
// the contract PHPStan checks the code against (phpVersion 8.3) must not promise more than PHP 8.4 has
if (PHP_VERSION_ID >= 80400) {
    $domMissing = [];
    $domClass = null;
    foreach (file(KALETA_ROOT . '/tools/phpstan-dom.php') ?: [] as $line) {
        if (preg_match('/^(?:abstract |final )?class (\w+)/', $line, $m) === 1) {
            $domClass = 'Dom\\' . $m[1];
        } elseif ($domClass !== null && preg_match('/public (?:static )?function (\w+)\(/', $line, $m) === 1 && !method_exists($domClass, $m[1])) {
            $domMissing[] = $domClass . '::' . $m[1] . '()';
        } elseif ($domClass !== null && preg_match('/public [^$(]*\$(\w+);/', $line, $m) === 1 && !property_exists($domClass, $m[1])) {
            $domMissing[] = $domClass . '::$' . $m[1];
        }
    }
    check('3.7 tools/phpstan-dom.php declares only what PHP 8.4 has (no 8.5-only member such as Element::$children)', $domMissing, []);
}
// every Dom\ class the code names exists in system/compat/Dom – otherwise it would fail only on PHP 8.3
preg_match_all('/\\\\?\bDom\\\\([A-Z][A-Za-z]+)\b/', implode("\n", array_map('file_get_contents', array_merge(
    glob(KALETA_SYSTEM . '/src/*/*.php') ?: [], glob(KALETA_SYSTEM . '/src/*/*/*.php') ?: [], [KALETA_ROOT . '/tools/find-czech.php']))), $domNames);
check('3.7 compat: every Dom\ class used in system/src has its PHP 8.3 counterpart in system/compat/Dom',
    array_values(array_filter(array_unique($domNames[1]), fn (string $n): bool => !is_file(KALETA_SYSTEM . '/compat/Dom/' . $n . '.php'))), []);
// updates: a release for a newer PHP is not offered and does not install (the manifest's min_php; none = 8.4, as before 3.7)
check('3.7 updates: min_php of a manifest decides, a missing or odd one counts as 8.4', [
    Kaleta\Core\Updater::minPhp(['min_php' => '8.3']), Kaleta\Core\Updater::minPhp([]), Kaleta\Core\Updater::minPhp(['min_php' => '8.4; rm']),
    Kaleta\Core\Updater::phpTooOld(['min_php' => '8.4'], '8.3.30'), Kaleta\Core\Updater::phpTooOld(['min_php' => '8.3'], '8.3.0'),
    Kaleta\Core\Updater::phpTooOld([], '8.3.30'), Kaleta\Core\Updater::phpTooOld(['min_php' => '8.4'], '8.4.1')],
    ['8.3', '8.4', '8.4', true, false, true, false]);
// 3.8 (D3): release channels – the version choice is pure (Updater::choose), the stable manifest lies next to the latest one
$offer = static function (?array $manifest, string $channel, string $current, string $php = '8.4.5'): string {
    $c = Kaleta\Core\Updater::choose($manifest, $channel, $current, $php);

    return $c['nova'] !== null ? 'offer ' . $c['nova']['verze'] . (!empty($c['nova']['bezpecnostni']) ? ' security' : '')
        : ($c['vyzaduje_php'] !== null ? 'needs php ' . $c['vyzaduje_php']['min_php'] : ($c['ahead_of'] !== null ? 'ahead of ' . $c['ahead_of'] : ($c['chyba'] !== null ? 'error' : 'nothing')));
};
$latest = ['verze' => '3.10.0', 'min_php' => '8.3', 'kanal' => 'latest'];
$stable = ['verze' => '3.8.2', 'min_php' => '8.3', 'kanal' => 'stable', 'bezpecnostni' => true];
check('3.8 channels: latest offers the newest minor, stable only its own line, no downgrade from either', [
    $offer($latest, 'latest', '3.8.0'), $offer($stable, 'stable', '3.8.0'), $offer($stable, 'stable', '3.8.2'),
    $offer(['kanal' => 'stable', 'verze' => '3.10.0'] + $stable, 'stable', '3.8.2'),
    // switched from latest while running a newer minor: nothing, never 3.8.2 – the site waits until the stable line passes it
    $offer($stable, 'stable', '3.9.1'), $offer($latest, 'latest', '3.11.0'),
    // a latest manifest served as the stable one (a wrong redirect, an old mirror without "kanal") offers nothing
    $offer($latest, 'stable', '3.8.0'), $offer(['verze' => '3.10.0', 'min_php' => '8.3'], 'stable', '3.8.0'),
    // a manifest from before 3.8 (no "kanal") still works on latest, as it did
    $offer(['verze' => '3.10.0', 'min_php' => '8.3'], 'latest', '3.8.0'),
    // the PHP gate holds on both channels; a stable release for a newer PHP is not offered either
    $offer(['min_php' => '8.4'] + $stable, 'stable', '3.8.0', '8.3.30'), $offer(['min_php' => '8.4'] + $latest, 'latest', '3.8.0', '8.3.30'),
    $offer(null, 'stable', '3.8.0'), $offer(['kanal' => 'stable'], 'stable', '3.8.0')],
    ['offer 3.10.0', 'offer 3.8.2 security', 'nothing', 'offer 3.10.0 security', 'ahead of 3.8.2', 'nothing', 'error', 'error', 'offer 3.10.0',
        'needs php 8.4', 'needs php 8.4', 'nothing', 'nothing']);
check('3.8 channels: the stable manifest is the twin of aktualizace.json, wherever the source is; another file has none', [
    Kaleta\Core\Updater::stableUrl(Kaleta\Core\Updater::DEFAULT_URL), Kaleta\Core\Updater::stableUrl('https://mirror.example/kaleta/aktualizace.json?t=1'),
    Kaleta\Core\Updater::stableUrl('https://mirror.example/updates.php'), Kaleta\Core\Updater::stableUrl('https://mirror.example/aktualizace.json.bak'),
    Kaleta\Core\Settings::DEFAULTS['update_channel'], Kaleta\Core\Updater::CHANNELS, in_array('update_channel', Kaleta\Core\SiteExport::SETTINGS, true),
    Kaleta\Admin\Modules\Settings::verifyValue('update_channel', 'stable'), Kaleta\Admin\Modules\Settings::verifyValue('update_channel', 'beta')],
    ['https://kaletacms.com/aktualizace-stable.json', 'https://mirror.example/kaleta/aktualizace-stable.json?t=1', null, null, 'latest', ['latest', 'stable'], false, 'stable', null]);
check('3.7 updates: response headers of file_get_contents() on 8.3 come from the calling scope, on 8.4 from PHP',
    PHP_VERSION_ID >= 80400 ? is_array(last_response_headers(['HTTP/1.1 999 ignored'])) : last_response_headers(['HTTP/1.1 200 OK']), PHP_VERSION_ID >= 80400 ? true : ['HTTP/1.1 200 OK']);
// 3.9 (N38-3, N37-4): manifest signature v2 covers every field a site acts on, in a canonical encoding with byte lengths
$v2Fixed = ['verze' => '3.9.0', 'vydano' => '2026-10-16', 'url' => 'https://example.org/kaleta-3.9.0.zip', 'sha256' => str_repeat('AB', 32), 'min_php' => '8.3',
    'bezpecnostni' => false, 'kanal' => 'stable', 'klic' => '0123abcd', 'zmeny' => ['Fix: ř|x', '']];
check('3.9 manifest v2: the canonical message is stable (fixed vector: field order, byte lengths, lower-case hash, the list of changes)',
    Kaleta\Core\Signature::manifestMessage($v2Fixed),
    "kaleta-manifest-v2\nverze=5:3.9.0\nvydano=10:2026-10-16\nurl=36:https://example.org/kaleta-3.9.0.zip\nsha256=64:" . str_repeat('ab', 32)
    . "\nmin_php=3:8.3\nbezpecnostni=5:false\nkanal=6:stable\nklic=8:0123abcd\nzmeny=13:9:Fix: ř|x0:\n");
check('3.9 manifest v2: a value cannot pass for another field, a missing field reads as the site reads it, odd types have no message', [
    Kaleta\Core\Signature::manifestMessage(['url' => "x\nmin_php=3:8.3"]) === Kaleta\Core\Signature::manifestMessage(['url' => 'x', 'min_php' => '8.3']),
    Kaleta\Core\Signature::manifestMessage(['zmeny' => ['a', 'b']]) === Kaleta\Core\Signature::manifestMessage(['zmeny' => ['ab']]),
    Kaleta\Core\Signature::manifestMessage([]) === Kaleta\Core\Signature::manifestMessage(['bezpecnostni' => false, 'kanal' => null, 'zmeny' => []]),
    Kaleta\Core\Signature::manifestMessage(['bezpecnostni' => 'true']), Kaleta\Core\Signature::manifestMessage(['verze' => 390]),
    Kaleta\Core\Signature::manifestMessage(['kanal' => ['stable']]), Kaleta\Core\Signature::manifestMessage(['zmeny' => ['a' => 'b']]), Kaleta\Core\Signature::manifestMessage(['zmeny' => [1]])],
    [false, false, true, null, null, null, null, null]);
if (function_exists('sodium_crypto_sign_seed_keypair')) {
    $v2Pair = sodium_crypto_sign_seed_keypair(str_repeat("\x07", 32));
    [$v2Sk, $v2Pk] = [sodium_crypto_sign_secretkey($v2Pair), sodium_crypto_sign_publickey($v2Pair)];
    $v2Backup = sodium_crypto_sign_keypair();
    $v2Pub = (string) tempnam(sys_get_temp_dir(), 'v2');
    file_put_contents($v2Pub, base64_encode($v2Pk) . " provozni\n" . base64_encode(sodium_crypto_sign_publickey($v2Backup)) . " zalozni\n");
    $v2Signed = Kaleta\Core\Signature::signManifest($v2Fixed, $v2Sk);
    check('3.9 manifest v2: a signed manifest carries v1 exactly as before 3.9 (deterministic Ed25519, fixed key) and a valid v2', [
        $v2Signed['klic'], $v2Signed['podpis'] === base64_encode(sodium_crypto_sign_detached(Kaleta\Core\Signature::packageMessage('3.9.0', str_repeat('AB', 32), false), $v2Sk)),
        hash('sha256', (string) $v2Signed['podpis2']), Kaleta\Core\Signature::manifestValid($v2Signed, $v2Pub),
        Kaleta\Core\Signature::isValid(Kaleta\Core\Signature::packageMessage('3.9.0', str_repeat('ab', 32), false), (string) $v2Signed['podpis'], $v2Pub)],
        ['fe812c12', true, '703ffe3b35d916b2d819d77c80c5b14d1dfc2c0219734092eb0050638e284389', true, true]);
    // tampering with any signed field – changing it or removing it – breaks v2; an unsigned extra field does not
    $tampered = [];
    foreach (Kaleta\Core\Signature::MANIFEST_FIELDS as $field) {
        $changed = $v2Signed;
        $changed[$field] = match ($field) {
            'bezpecnostni' => true, 'zmeny' => ['Fix: ř|x', '', 'Visit evil.example'], 'klic' => Kaleta\Core\Signature::id(sodium_crypto_sign_publickey($v2Backup)),
            'sha256' => str_repeat('ab', 31) . 'ac', 'min_php' => '8.2', 'kanal' => 'latest', default => (is_string($changed[$field]) ? $changed[$field] : '') . '1',
        };
        $removed = $v2Signed;
        unset($removed[$field]);
        $tampered[$field] = [Kaleta\Core\Signature::manifestValid($changed, $v2Pub), $field === 'bezpecnostni' ? 'flag false = absent' : Kaleta\Core\Signature::manifestValid($removed, $v2Pub)];
    }
    check('3.9 manifest v2: changing or removing any signed field breaks the signature', $tampered, array_replace(array_fill_keys(Kaleta\Core\Signature::MANIFEST_FIELDS, [false, false]), ['bezpecnostni' => [false, 'flag false = absent']]));
    $v2BackupSigned = Kaleta\Core\Signature::signManifest($v2Fixed, sodium_crypto_sign_secretkey($v2Backup));
    check('3.9 manifest v2: the hash is compared in any case, extra fields are not signed, the backup key signs too, a foreign one does not', [
        Kaleta\Core\Signature::manifestValid(['sha256' => str_repeat('ab', 32)] + $v2Signed, $v2Pub), Kaleta\Core\Signature::manifestValid($v2Signed + ['poznamka' => 'x'], $v2Pub),
        Kaleta\Core\Signature::manifestValid($v2BackupSigned, $v2Pub), Kaleta\Core\Signature::manifestValid(Kaleta\Core\Signature::signManifest($v2Fixed, sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair())), $v2Pub),
        // the signature of one key under the id of the other: "klic" is signed and names the only key that counts
        Kaleta\Core\Signature::manifestValid(['klic' => $v2BackupSigned['klic'], 'podpis2' => base64_encode(sodium_crypto_sign_detached((string) Kaleta\Core\Signature::manifestMessage(['klic' => $v2BackupSigned['klic']] + $v2Fixed), $v2Sk))] + $v2Fixed, $v2Pub),
        Kaleta\Core\Signature::manifestValid(['podpis2' => 'AAAA'] + $v2Signed, $v2Pub)],
        [true, true, true, false, false, false]);
    // what a 3.9 site acts on (Updater::verified, then Updater::choose): v2 decides; without v2 only an older release, without its channel
    $v2Verdict = static function (array $manifest, string $channel = 'stable', string $current = '3.8.0') use ($v2Pub): string {
        try {
            $c = Kaleta\Core\Updater::choose(Kaleta\Core\Updater::verified($manifest, $v2Pub), $channel, $current, '8.4.5');
        } catch (RuntimeException) {
            return 'refused';
        }

        return $c['nova'] !== null ? 'offer ' . $c['nova']['verze'] : ($c['vyzaduje_php'] !== null ? 'needs php ' . $c['vyzaduje_php']['min_php'] : ($c['chyba'] !== null ? 'error' : 'nothing'));
    };
    $v1Only = static fn (array $m): array => array_diff_key(Kaleta\Core\Signature::signManifest($m, $v2Sk), ['podpis2' => 1]);
    check('3.9 manifest v2: a signed stable manifest is offered; a forged channel or min_php, a stripped v2 or a v1-only newer release are refused', [
        $v2Verdict($v2Signed), $v2Verdict(['kanal' => 'stable'] + Kaleta\Core\Signature::signManifest(['kanal' => 'latest'] + $v2Fixed, $v2Sk)),
        $v2Verdict(['min_php' => '8.2'] + Kaleta\Core\Signature::signManifest(['min_php' => '99.0'] + $v2Fixed, $v2Sk), 'latest'),
        $v2Verdict(Kaleta\Core\Signature::signManifest(['min_php' => '99.0'] + $v2Fixed, $v2Sk), 'latest'),
        $v2Verdict(array_diff_key($v2Signed, ['podpis2' => 1])), $v2Verdict($v1Only(['verze' => '3.10.0'] + $v2Fixed), 'latest'),
        $v2Verdict(['verze' => 3.8] + $v1Only($v2Fixed), 'latest')],
        ['offer 3.9.0', 'refused', 'refused', 'needs php 99.0', 'refused', 'refused', 'refused']);
    check('3.9 manifest v2: a v1-only manifest of an older release is read as before, but its unsigned channel never makes it stable', [
        $v2Verdict($v1Only(['verze' => '3.8.5'] + $v2Fixed), 'stable', '3.8.0'), $v2Verdict($v1Only(['verze' => '3.8.5'] + $v2Fixed), 'latest', '3.8.0'),
        array_key_exists('kanal', Kaleta\Core\Updater::verified($v1Only(['verze' => '3.8.5'] + $v2Fixed), $v2Pub)),
        array_key_exists('kanal', Kaleta\Core\Updater::verified($v2Signed, $v2Pub))],
        ['error', 'offer 3.8.5', false, true]);
    // 3.10 (N39-8): pre-release tags of 3.9.0 need v2 – version_compare() alone would read them as "before 3.9.0"
    check('3.10 manifest v2 (N39-8): only the numeric X.Y.Z decides whether v2 is required, so no 3.9.0 pre-release passes on v1 alone', [
        version_compare('3.9.0-rc1', Kaleta\Core\Updater::SIGNED_V2_SINCE, '<'), version_compare('3.9.0-x', '3.9.0-dev', '<'),
        array_map(Kaleta\Core\Updater::needsSignatureV2(...), ['3.8.5', '3.8.99-rc1', '3.9.0', '3.9.0-rc1', '3.9.0-dev', '3.9.0-x', '3.9.0.1', '3.10.0', '10.0.0', '3.9', '']),
        $v2Verdict($v1Only(['verze' => '3.9.0-rc1'] + $v2Fixed), 'latest'), $v2Verdict($v1Only(['verze' => '3.9.0-dev'] + $v2Fixed), 'latest'),
        $v2Verdict($v1Only(['verze' => '3.8.9-rc1'] + $v2Fixed), 'latest'), $v2Verdict(Kaleta\Core\Signature::signManifest(['verze' => '3.9.0-rc1'] + $v2Fixed, $v2Sk))],
        [true, true, [false, false, true, true, true, true, true, true, true, true, true], 'refused', 'refused', 'offer 3.8.9-rc1', 'offer 3.9.0-rc1']);
    // 3.10 (N39-7): freshness – a manifest older than the newest one the source gave before is stale (signed date, then version)
    $v2At = static fn (string $version, ?string $date): array => Kaleta\Core\Signature::signManifest(array_merge($v2Fixed, ['verze' => $version, 'vydano' => $date]), $v2Sk);
    $v2Seen = ['verze' => '3.9.2', 'vydano' => '2026-10-09'];
    check('3.10 freshness (N39-7): the signed date decides, then the version on the same day; a later re-signing wins; a v1-only or undated one after a v2 one is stale', [
        Kaleta\Core\Updater::isStale($v2At('3.9.1', '2026-10-01'), null),
        Kaleta\Core\Updater::isStale($v2At('3.9.2', '2026-10-09'), $v2Seen), Kaleta\Core\Updater::isStale($v2At('3.10.0', '2026-10-16'), $v2Seen),
        Kaleta\Core\Updater::isStale($v2At('3.9.3', '2026-10-09'), $v2Seen), Kaleta\Core\Updater::isStale($v2At('3.9.1', '2026-10-17'), $v2Seen),
        Kaleta\Core\Updater::isStale($v2At('3.9.1', '2026-10-09'), $v2Seen), Kaleta\Core\Updater::isStale($v2At('3.9.2-rc1', '2026-10-09'), $v2Seen),
        Kaleta\Core\Updater::isStale($v2At('3.9.3', '2026-10-08'), $v2Seen), Kaleta\Core\Updater::isStale($v2At('3.10.0', null), $v2Seen),
        Kaleta\Core\Updater::isStale($v1Only(['verze' => '3.8.4'] + $v2Fixed), $v2Seen), Kaleta\Core\Updater::isStale($v2At('3.9.0', null), ['verze' => '3.8.9', 'vydano' => ''])],
        [false, false, false, false, false, true, true, true, true, true, false]);
    check('3.10 freshness: the release date is read only as a real YYYY-MM-DD; a date changed after signing breaks v2', [
        Kaleta\Core\Updater::releaseDate(['vydano' => '2026-10-09']), Kaleta\Core\Updater::releaseDate(['vydano' => '2026-02-30']), Kaleta\Core\Updater::releaseDate(['vydano' => '2026-10-09T10:00']),
        Kaleta\Core\Updater::releaseDate(['vydano' => 20261009]), Kaleta\Core\Updater::releaseDate([]),
        Kaleta\Core\Signature::manifestValid(['vydano' => '2026-12-31'] + $v2At('3.9.1', '2026-10-01'), $v2Pub)],
        ['2026-10-09', '', '', '', '', false]);
    // 3.10 (N310-1, N310-3): the channel is bound before anything is remembered, the memory is per source AND channel, and a
    // date in the future is never remembered – sequences as a site sees them (admit() is what manifest() applies)
    $v2Ch = static fn (string $version, string $date, string $kanal): array => Kaleta\Core\Signature::signManifest(array_merge($v2Fixed, ['verze' => $version, 'vydano' => $date, 'kanal' => $kanal]), $v2Sk);
    $v2Run = static function (array $steps, string $channel, string $url): array {
        $seen = [];
        $verdicts = [];
        foreach ($steps as $manifest) {
            $v = Kaleta\Core\Updater::admit($manifest, $channel, $url, $seen, (int) strtotime('2026-10-10 12:00:00 UTC'));
            $verdicts[] = ($v['refused'] ?? 'ok') . ($v['future'] !== null ? ' future ' . $v['future'] : '');
            $seen = $v['seen'];
        }

        return [$verdicts, array_map(static fn (array $e): string => $e['verze'] . ' ' . $e['vydano'], $seen)];
    };
    [$L, $S, $C] = ['https://x.example/aktualizace.json', 'https://x.example/aktualizace-stable.json', 'https://mirror.example/kaleta.json'];
    check('3.10 freshness (N310-1): the other channel\'s genuine manifest is refused before it is remembered – no freeze on Latest, no block on Stable', [
        // A: Latest – latest 3.10.1, the stable manifest (newer date) served once, latest 3.10.1 again, then a v1-only replay
        $v2Run([$v2Ch('3.10.1', '2026-10-01', 'latest'), $v2Ch('3.9.2', '2026-10-05', 'stable'), $v2Ch('3.10.1', '2026-10-01', 'latest'), Kaleta\Core\Updater::verified($v1Only(['verze' => '3.8.4'] + $v2Fixed), $v2Pub)], 'latest', $L),
        // B: Stable – stable 3.10.0, the latest manifest served once, then a genuine new stable 3.10.2 (older than that latest one)
        $v2Run([$v2Ch('3.10.0', '2026-09-20', 'stable'), $v2Ch('3.10.3', '2026-10-08', 'latest'), $v2Ch('3.10.2', '2026-10-06', 'stable')], 'stable', $S),
        // a custom source without a stable twin takes both channels, each remembered on its own (the one used last goes last)
        $v2Run([$v2Ch('3.10.1', '2026-10-01', 'latest'), $v2Ch('3.9.2', '2026-10-05', 'stable'), $v2Ch('3.10.1', '2026-10-01', 'latest'), $v2Ch('3.9.1', '2026-10-04', 'stable')], 'custom', $C),
        $v2Run([Kaleta\Core\Updater::verified($v1Only(['verze' => '3.8.4'] + $v2Fixed), $v2Pub)], 'stable', $S)],
        [[['ok', 'channel', 'ok', 'stale'], [$L . '#latest' => '3.10.1 2026-10-01']],
         [['ok', 'channel', 'ok'], [$S . '#stable' => '3.10.2 2026-10-06']],
         [['ok', 'ok', 'ok', 'stale'], [$C . '#stable' => '3.9.2 2026-10-05', $C . '#latest' => '3.10.1 2026-10-01']],
         [['channel'], []]]);
    check('3.10 freshness (N310-3): a date more than 2 days ahead is offered but never remembered, so it cannot lock out later releases', [
        $v2Run([$v2Ch('3.10.4', '2099-12-31', 'latest'), $v2Ch('3.10.5', '2026-10-10', 'latest')], 'latest', $L),
        $v2Run([$v2Ch('3.10.4', '2026-10-12', 'latest'), $v2Ch('3.10.5', '2026-10-13', 'latest')], 'latest', $L),
        Kaleta\Core\Updater::newestOf([$C . '#latest' => ['verze' => '3.10.1', 'vydano' => '2026-10-01', 'at' => 0], $C . '#stable' => ['verze' => '3.9.2', 'vydano' => '2026-10-05', 'at' => 0],
            $C . 'x#latest' => ['verze' => '9.0.0', 'vydano' => '2027-01-01', 'at' => 0]], $C)['verze'] ?? null,
        Kaleta\Core\Updater::newestOf([], $C)],
        [[['ok future 2099-12-31', 'ok'], [$L . '#latest' => '3.10.5 2026-10-10']], [['ok', 'ok future 2026-10-13'], [$L . '#latest' => '3.10.4 2026-10-12']], '3.9.2', null]);
    unlink($v2Pub);
}
// 3.10: a manifest the publisher really published (3.9.1, signed with the key in system/aktualizace.pub) keeps verifying
// and is read the same way; after 3.9.2 of the same day it is stale
$published391 = json_decode('{"verze":"3.9.1","vydano":"2026-10-09","url":"https://github.com/phprs-cms/kaletacms/releases/download/v3.9.1/kaleta-3.9.1.zip","sha256":"766fb576f904fbfeea82f24b80e4630b7f5310cdbf796e1c6b6fe8671f4f9f48","podpis":"gookkb/YqOvbfDcqQ/DFjNvnqE40uEgrbnambX/JfoeREy30TOImpGCZO+JJtyZRCUzHM7WZrImJaBKfCEx4Cw==","klic":"3c68e740","min_php":"8.3","bezpecnostni":false,"zmeny":["The phone menu keeps its rows close together and every submenu arrow in the same place","Support Kaleta in the admin footer leads to Buy Me a Coffee"],"kanal":"latest","podpis2":"7kGxcSRC1CTo3T5yfKHXDMm+9/p672i1VB7zshlgRwIe065BqIJnzF+kbt81JuqRUk4LsqVGSNStWA3qC25/BQ=="}', true);
if (function_exists('sodium_crypto_sign_verify_detached')) {
    check('3.10: the published 3.9.1 manifest keeps verifying (v1 and v2) and is fresh until a newer one of the source was seen', [
        Kaleta\Core\Signature::manifestValid($published391, KALETA_SYSTEM . '/aktualizace.pub'),
        Kaleta\Core\Signature::isValid(Kaleta\Core\Signature::packageMessage('3.9.1', $published391['sha256'], false), $published391['podpis'], KALETA_SYSTEM . '/aktualizace.pub'),
        Kaleta\Core\Updater::verified($published391, KALETA_SYSTEM . '/aktualizace.pub') === $published391,
        Kaleta\Core\Updater::isStale($published391, null), Kaleta\Core\Updater::isStale($published391, ['verze' => '3.9.1', 'vydano' => '2026-10-09']),
        Kaleta\Core\Updater::isStale($published391, ['verze' => '3.9.2', 'vydano' => '2026-10-09'])],
        [true, true, true, false, false, true]);
}
check('3.10 freshness: the threshold of System status, the setting, and the daily check warns two weeks earlier', [
    Kaleta\Core\Updater::FRESHNESS_DAYS, array_key_exists('update_seen', Kaleta\Core\Settings::DEFAULTS), in_array('update_seen', Kaleta\Core\SiteExport::SETTINGS, true),
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/check-channel.php'), 'Updater::needsSignatureV2($m[\'verze\'])'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/release.php'), "'vydano' => date('Y-m-d')")],
    [60, true, false, true, true]);
check('3.9 updates: the PHP a package needs is read from its own bootstrap (none before 3.7)', [
    Kaleta\Core\Updater::packageMinPhp((string) file_get_contents(KALETA_SYSTEM . '/bootstrap.php')),
    Kaleta\Core\Updater::packageMinPhp("<?php\nif (PHP_VERSION_ID < 80400) {\n    exit('Kaleta vyžaduje PHP 8.4');\n}\n"),
    Kaleta\Core\Updater::packageMinPhp("<?php\n// const KALETA_MIN_PHP = '7.0';\nconst KALETA_MIN_PHP = '8.5';\n"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/release.php'), 'Kaleta\Core\Signature::signManifest(')],
    [KALETA_MIN_PHP, null, '8.5', true]);
// one minimum everywhere: the bootstrap gate, PHPStan, the CI matrix, the release manifest and the README
$bootstrapSource = (string) file_get_contents(KALETA_SYSTEM . '/bootstrap.php');
[$minMajor, $minMinor] = array_map('intval', explode('.', KALETA_MIN_PHP));
check('3.7 minimum PHP ' . KALETA_MIN_PHP . ': bootstrap (English and Czech), PHPStan, CI, release and README say the same', [
    str_contains($bootstrapSource, "version_compare(PHP_VERSION, KALETA_MIN_PHP, '<')") && str_contains($bootstrapSource, "'Kaleta requires PHP ' . KALETA_MIN_PHP") && str_contains($bootstrapSource, "'Kaleta vyžaduje PHP ' . KALETA_MIN_PHP"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/phpstan.neon.dist'), 'phpVersion: ' . ($minMajor * 10000 + $minMinor * 100)),
    str_contains((string) file_get_contents(KALETA_ROOT . '/.github/workflows/kontrola.yml'), "php: ['" . KALETA_MIN_PHP . "'"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/tools/release.php'), "'min_php' => \$minPhp[1]"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/README.md'), 'PHP ' . KALETA_MIN_PHP . '+'),
], [true, true, true, true, true]);
