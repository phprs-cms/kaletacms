<?php

declare(strict_types=1);

/* ---------- 2.8: real-user speed (Core\WebVitals) – histogram buckets, p75, Google's ratings, the audit rule ---------- */
use Kaleta\Core\WebVitals;
check('2.8: WebVitals::bucket – an edge value belongs to its bucket, the next value to the next one, above the last edge to the open bucket',
    [WebVitals::bucket('lcp', 2500), WebVitals::bucket('lcp', 2500.1), WebVitals::bucket('lcp', 0), WebVitals::bucket('lcp', 9000), WebVitals::bucket('cls', 0.1), WebVitals::bucket('cls', 0.11), WebVitals::bucket('inp', 200), WebVitals::bucket('inp', 201)],
    [4, 5, 0, 11, 4, 5, 3, 4]);
check('2.8: WebVitals::THRESHOLDS are bucket edges, so a rating from a bucket edge is exact',
    array_map(fn (string $m): bool => in_array(WebVitals::THRESHOLDS[$m][0], WebVitals::BUCKETS[$m], true) && in_array(WebVitals::THRESHOLDS[$m][1], WebVitals::BUCKETS[$m], true), array_keys(WebVitals::BUCKETS)), [true, true, true]);
// 100 samples: 70 in (1500, 2000], 10 in (2000, 2500], 20 in (4000, 5000] – the 75th sample is in the (2000, 2500] bucket
check('2.8: WebVitals::percentile – p75 is the upper edge of the bucket with the 75th sample', WebVitals::percentile('lcp', [3 => 70, 4 => 10, 8 => 20]), 2500.0);
check('2.8: WebVitals::percentile – p50 of the same samples', WebVitals::percentile('lcp', [3 => 70, 4 => 10, 8 => 20], 0.5), 2000.0);
check('2.8: WebVitals::percentile – a single sample is its own p75', WebVitals::percentile('inp', [2 => 1]), 150.0);
check('2.8: WebVitals::percentile – the open bucket reports its lower edge (at least that), no samples give null', [WebVitals::percentile('lcp', [11 => 4]), WebVitals::percentile('cls', [])], [8000.0, null]);
check('2.8: WebVitals::rating – Google\'s thresholds', [WebVitals::rating('lcp', 2500), WebVitals::rating('lcp', 2501), WebVitals::rating('lcp', 4000), WebVitals::rating('lcp', 4001),
    WebVitals::rating('cls', 0.1), WebVitals::rating('cls', 0.25), WebVitals::rating('cls', 0.3), WebVitals::rating('inp', 200), WebVitals::rating('inp', 500), WebVitals::rating('inp', 501)],
    ['good', 'needs_improvement', 'needs_improvement', 'poor', 'good', 'needs_improvement', 'poor', 'good', 'needs_improvement', 'poor']);
check('2.8: WebVitals::isRegression – more than 25 % worse with at least 30 measurements in both periods', [WebVitals::isRegression(3000.0, 2000.0, 30, 30), WebVitals::isRegression(2500.0, 2000.0, 100, 100),
    WebVitals::isRegression(2501.0, 2000.0, 100, 100), WebVitals::isRegression(3000.0, 2000.0, 29, 30), WebVitals::isRegression(3000.0, 2000.0, 30, 29), WebVitals::isRegression(3000.0, null, 30, 30), WebVitals::isRegression(null, 2000.0, 30, 30)],
    [true, false, true, false, false, false, false]);
check('[source-pin] 2.8: the beacon script looks for nothing but its own endpoint and sends with sendBeacon', [str_contains((string) file_get_contents(KALETA_ROOT . '/image/vitals.js'), "getAttribute('data-vitals')"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/vitals.js'), 'navigator.sendBeacon('), preg_match('/document\.cookie|localStorage|sessionStorage/', (string) file_get_contents(KALETA_ROOT . '/image/vitals.js'))], [true, true, 0]);
check('2.8: /vitals is a reserved address', in_array('vitals', Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true), true);

