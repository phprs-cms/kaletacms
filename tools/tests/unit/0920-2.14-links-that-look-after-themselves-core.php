<?php

declare(strict_types=1);

/* ---------- 2.14: links that look after themselves (Core\RedirectMatcher, Core\Links, Core\InternalLinks) ---------- */
$f15Score = fn (string $missing, string $candidate): int => Kaleta\Core\RedirectMatcher::score($missing, $candidate, ['cs', 'en']);
check('2.14 RedirectMatcher: the same address written differently scores 100', [$f15Score('/O-nas/', 'o-nas'), $f15Score('o-nas.html', 'o-nas'), $f15Score('/kontakt/index.php', 'kontakt')], [100, 100, 100]);
check('2.14 RedirectMatcher: an old prefix or a moved language prefix scores 95', [$f15Score('novinky/moje-novinka', 'news/moje-novinka'), $f15Score('/blog/clanek-x', 'novinky/clanek-x'), $f15Score('en/kontakt', 'kontakt'), $f15Score('kontakt', 'en/kontakt')], [95, 95, 95, 95]);
check('2.14 RedirectMatcher: the same last segment elsewhere scores 90, a similar slug below', [$f15Score('sluzby/weby', 'weby'), $f15Score('weby', 'sluzby/weby'), $f15Score('kontakty', 'kontakt') < 90 && $f15Score('kontakty', 'kontakt') >= Kaleta\Core\RedirectMatcher::MIN_SCORE,
    $f15Score('cenik-2019', 'cenik') < 90 && $f15Score('cenik-2019', 'cenik') >= Kaleta\Core\RedirectMatcher::MIN_SCORE], [90, 90, true, true]);
check('2.14 RedirectMatcher: random paths, bot probes and unrelated pages do not match', [$f15Score('asdkjh-qwe', 'kontakt'), $f15Score('wp-content/uploads/x.jpg', 'kontakt'), $f15Score('o-nas', 'o-firme'), $f15Score('xy', 'xyz'), $f15Score('', 'kontakt')], [0, 0, 0, 0, 0]);
$f15Build = ['v' => 1, 'deti' => [['id' => 'e1', 'typ' => 'sekce', 'deti' => [['id' => 'e2', 'typ' => 'tlacitko', 'obsah' => ['text' => 'Go', 'odkaz' => 'http://127.0.0.1:1/dead']],
    ['id' => 'e3', 'typ' => 'text', 'obsah' => ['html' => '<p><a href="https://example.com/a?x=1&amp;y=2">a</a> <a href="mailto:a@b.cz">m</a> <a href="{{fact.web}}">f</a></p>']],
    ['id' => 'e4', 'typ' => 'cenik', 'obsah' => ['plany' => [['nazev' => 'A', 'odkaz' => '/kontakt'], ['nazev' => 'B', 'odkaz' => '#']]]]]]]];
check('2.14 Links::collect: links of a page build with their element ids, texts, nested plans; tokens, anchors and mailto left out', Kaleta\Core\Links::collect('page', ['stavba' => json_encode($f15Build), 'text' => '']),
    [['http://127.0.0.1:1/dead', 'e2'], ['https://example.com/a?x=1&y=2', 'e3'], ['/kontakt', 'e4']]);
check('2.14 Links::collect: a text page and a collection item (link fields and links in texts)', [Kaleta\Core\Links::collect('page', ['stavba' => null, 'text' => '<a href="https://example.com/">x</a>']),
    Kaleta\Core\Links::collect('item', ['data' => json_encode(['web' => 'https://example.org/firma', 'popis' => '<p><a href="https://example.com/b">b</a></p>', 'cislo' => 5])])],
    [[['https://example.com/', '']], [['https://example.org/firma', ''], ['https://example.com/b', '']]]);
check('2.14 Links::hint: the archived copy only as a suggestion for outside addresses', [str_contains(Kaleta\Core\Links::hint('https://example.com/x', 404), 'https://web.archive.org/web/2020/https://example.com/x'), str_contains(Kaleta\Core\Links::hint('/novinky/stara', 404), 'no longer exists')], [true, true]);
check('2.14 Links::isPublic: the test seam is off without the environment variable', Kaleta\Core\Links::isPublic('http://127.0.0.1:1/x'), false);
check('2.14 InternalLinks::words: title words for the term overlap – lower case, no diacritics, four letters or more', Kaleta\Core\InternalLinks::words('Reference &amp; portfolio: Zateplení domů v Brně'), ['reference', 'portfolio', 'zatepleni', 'domu', 'brne']);
check('2.14: the redirects job runs daily, redirect.auto is a known event, orphan is an audit kind', [Kaleta\Core\Scheduler::JOBS['redirects'][0], isset(Kaleta\Core\Events::TYPES['redirect.auto']), isset(Kaleta\Core\Audit::KINDS['orphan'])], [86400, true, true]);
