<?php

declare(strict_types=1);

/* ---------- 3.6 INV-10: pattern redirects, 410, CSV import ---------- */
$rr = Kaleta\Core\RedirectRules::class;
check('3.6 redirects: a pattern keeps the rest of the path, matches case-insensitively and needs at least one character', [
    $rr::match('blog/*', 'blog/2019/post'), $rr::match('blog/*', 'blog'), $rr::match('*/amp', 'Novinky/x/AMP'), $rr::match('a/*/b/*', 'a/1/b/2/3'),
    $rr::substitute('news/*', ['2019/post']), $rr::substitute('x/*/y/*', ['1', 'č d'])],
    [['2019/post'], null, ['Novinky/x'], ['1', '2/3'], 'news/2019/post', 'x/1/y/%C4%8D%20d']);
check('3.6 redirects: what a visitor typed can never form another host – empty, "." and ".." segments refuse the match, the rest is encoded', [
    $rr::substitute('*', ['/evil.example']), $rr::substitute('news/*', ['a//evil.example']), $rr::substitute('*', ['..']), $rr::substitute('x/*', ['a\\evil']),
    $rr::substitute('x/*', ['a?b#c'])],
    [null, null, null, 'x/a%5Cevil', 'x/a%3Fb%23c']);
check('3.6 redirects: a wildcard in an absolute target only after the host; patterns need a fixed part; at most 3 wildcards', [
    $rr::problem('blog/*', 'https://*.evil.example/'), $rr::problem('blog/*', 'https://new.example*'), $rr::problem('blog/*', 'https://user@new.example/*') !== null,
    $rr::problem('blog/*', 'https://new.example/*'), $rr::problem('blog/*', '/\\evil.example/*') !== null, $rr::problem('*', 'x') !== null, $rr::problem('*/*', '*') !== null,
    $rr::problem('a/*/*/*/*', 'b') !== null, $rr::problem('a**', 'b') !== null, $rr::problem('a/*', 'b/*/*'), $rr::problem('stary', 'Stary'), $rr::problem('?p=12', 'novinky/x'),
    $rr::problem('old page', 'new'), $rr::problem('blog/*', '')],
    ['The target must be a path on this site or a full https://… address; a wildcard (*) only after the domain.', 'The target must be a path on this site or a full https://… address; a wildcard (*) only after the domain.', true,
        null, true, true, true, true, true, 'The target has more wildcards (*) than the old address.', 'The old address and the target are the same.', null, null, null]);
$patternRows = [['idp' => 1, 'z_adresy' => 'blog/*', 'na_adresu' => 'news/*', 'typ' => 301], ['idp' => 2, 'z_adresy' => 'blog/2019/*', 'na_adresu' => 'archive/*', 'typ' => 301],
    ['idp' => 3, 'z_adresy' => '*.html', 'na_adresu' => '*', 'typ' => 301], ['idp' => 4, 'z_adresy' => 'products/*', 'na_adresu' => '', 'typ' => 410],
    ['idp' => 5, 'z_adresy' => 'x/*', 'na_adresu' => 'https://*.evil.example/', 'typ' => 301]];
check('3.6 redirects: the longest fixed beginning wins among patterns, a 410 pattern has no target, a rule a save would refuse is never used', [
    $rr::resolve($patternRows, ['blog/2019/x.html'])['to'] ?? null, $rr::resolve($patternRows, ['blog/x'])['to'] ?? null, $rr::resolve($patternRows, ['stare.html'])['to'] ?? null,
    $rr::resolve($patternRows, ['products/spam-1'])['row']['typ'] ?? null, $rr::resolve($patternRows, ['x/evil']), $rr::resolve($patternRows, ['blog//evil.example'])['to'] ?? null],
    ['archive/x.html', 'news/x', 'stare', 410, null, null]);
check('3.6 redirects: codes 301/302/410 (308 and 307 as their twins), old addresses normalized', [
    $rr::code(''), $rr::code('302'), $rr::code('308'), $rr::code('410'), $rr::code('404'), $rr::normalizeFrom('https://old.example/Blog/%C4%8Dl%C3%A1nek/'), $rr::normalizeFrom('/?p=123'),
    $rr::normalizeFrom('/a%2A'), $rr::normalizeTo('/new/'), $rr::normalizeTo('https://x.example/a/')],
    [301, 302, 301, 410, null, 'Blog/článek', '?p=123', 'a%2A', 'new', 'https://x.example/a/']);
check('3.6 redirects: CSV – simple rows with any delimiter, a header, and the Redirection plugin export with a simple regular expression', [
    $rr::parseCsv("\xEF\xBB\xBF/a;/b;302\n/c;https://x.example/\n/gone;;410\n"),
    $rr::parseCsv("source,target,regex,code,type,hits,title,status\n/old,/new,0,301,url,3,,enabled\n\"^/blog/(.*)$\",/news/\$1,1,302,url,0,,enabled\n/x,/y,0,301,url,0,,disabled\n/(\\d+)/x,/y,1,301,url,0,,enabled\n")],
    [[['from' => '/a', 'to' => '/b', 'code' => '302'], ['from' => '/c', 'to' => 'https://x.example/', 'code' => ''], ['from' => '/gone', 'to' => '', 'code' => '410']],
        [['from' => '/old', 'to' => '/new', 'code' => '301'], ['from' => '/blog/*', 'to' => '/news/*', 'code' => '302'],
            ['from' => '/x', 'to' => '/y', 'code' => '301', 'refused' => 'The rule is switched off in the file.'],
            ['from' => '/(\\d+)/x', 'to' => '/y', 'code' => '301', 'refused' => 'A regular expression – add this one by hand as a pattern with * (e.g. /blog/* → /news/*).']]]);
check('3.6 redirects: only simple regular expressions become patterns – groups in order, nothing else', [
    $rr::fromRegex('^/blog/(.*)$', '/news/$1'), $rr::fromRegex('/a/(.+)/b/(.*)', '/x/$1/$2'), $rr::fromRegex('/a/(.*)/b/(.+)', '/x/$2/$1'), $rr::fromRegex('/old\\.html', '/new'),
    $rr::fromRegex('/old.html', '/new'), $rr::fromRegex('^/(\\d+)$', '/x/$1'), $rr::fromRegex('/a/(.*)', '/b/$1$')],
    [['/blog/*', '/news/*'], ['/a/*/b/*', '/x/*/*'], null, ['/old.html', '/new'], null, null, null]);
check('3.6 redirects: the limits the moved sites need (500 per MCP call, 5,000 CSV rows), and the 404 path reads patterns only after the exact lookup', [
    $rr::MAX_BATCH >= 500, $rr::MAX_CSV_ROWS >= 5000,
    (bool) preg_match('/storedRedirect\(\$requested[^;]+\)\) !== null\) \{\s+return \$redirect;\s+\}\s+\/\/ only then the pattern rules[^\n]*\n\s+if \(\(\$redirect = \$this->patternRedirect/', (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Kernel.php'))],
    [true, true, true]);
// INV-9: header and footer variants by kind of content
$sp = Kaleta\Builder\SiteParts::class;
$variantRules = $sp::sanitizeRules(['novinky' => 1, 'vypis' => '', 'kolekce' => ['reference', 'Bad Slug!', 3], 'nadrazene' => ['7', -1, 'x'], 'jine' => true]);
$where = fn (array $w): array => $w + ['kolekce' => null, 'novinka' => false, 'vypis' => false, 'predci' => []];
check('3.6 part variants: rules are sanitized like the pop-up rules and match news items, collections and pages under a parent', [
    $variantRules, $sp::hasRules($sp::sanitizeRules(null)), $sp::matchesRules($variantRules, $where(['novinka' => true])), $sp::matchesRules($variantRules, $where(['vypis' => true])),
    $sp::matchesRules($variantRules, $where(['kolekce' => 'reference'])), $sp::matchesRules($variantRules, $where(['kolekce' => 'tym'])), $sp::matchesRules($variantRules, $where(['predci' => [9, 7]])),
    $sp::matchesRules($variantRules, $where(['predci' => [9]])), $sp::matchesRules($sp::sanitizeRules([]), $where(['novinka' => true, 'vypis' => true, 'kolekce' => 'x', 'predci' => [1]]))],
    [['novinky' => true, 'vypis' => false, 'kolekce' => ['reference'], 'nadrazene' => [7]], false, true, false, true, false, true, false, false]);

