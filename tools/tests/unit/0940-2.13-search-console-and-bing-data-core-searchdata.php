<?php

declare(strict_types=1);

/* ---------- 2.13: Search Console and Bing data (Core\SearchData) ---------- */
check('2.13 Bing: a token service whose key goes in the query, never in a header; the daily job search_data', [Kaleta\Core\Connectors::service('bing'), Kaleta\Connectors\Bing::AUTH, Kaleta\Connectors\Bing::authHeaders('k', ''), Kaleta\Connectors\Bing::authQuery('k'),
    Kaleta\Connectors\Google::authQuery('k'), Kaleta\Core\Scheduler::JOBS['search_data'][0], isset(Kaleta\Connectors\Google::settings()['search_console_site']), array_keys(Kaleta\Connectors\Bing::settings())],
    [Kaleta\Connectors\Bing::class, 'token', [], ['apikey' => 'k'], [], 86400, true, ['site_url']]);
check('2.13 SearchData::googleRows – Search Console rows become query or page rows, the CTR in per cent, the position rounded; a row without a key is left out', Kaleta\Core\SearchData::googleRows(['rows' => [
    ['keys' => ['kaleta cms'], 'clicks' => 42, 'impressions' => 900, 'ctr' => 0.04666, 'position' => 3.44], ['keys' => [], 'clicks' => 1], ['keys' => ['  spaced  '], 'clicks' => 0, 'impressions' => 10, 'ctr' => 0, 'position' => 50.26]]], 'query'),
    [['kind' => 'query', 'key' => 'kaleta cms', 'clicks' => 42, 'impressions' => 900, 'ctr' => 4.67, 'position' => 3.4], ['kind' => 'query', 'key' => 'spaced', 'clicks' => 0, 'impressions' => 10, 'ctr' => 0.0, 'position' => 50.3]]);
check('2.13 SearchData::googleSitemaps – submitted and indexed summed over the content types; nothing from a malformed answer', [Kaleta\Core\SearchData::googleSitemaps(['sitemap' => [['path' => 'https://example.com/sitemap.xml', 'contents' => [['type' => 'web', 'submitted' => '12', 'indexed' => '9'], ['type' => 'image', 'submitted' => '3', 'indexed' => '1']]], ['contents' => []]]]),
    Kaleta\Core\SearchData::googleSitemaps(['error' => 'x']), Kaleta\Core\SearchData::googleRows([], 'page')],
    [[['kind' => 'sitemap', 'key' => 'https://example.com/sitemap.xml', 'clicks' => 10, 'impressions' => 15, 'ctr' => 66.67, 'position' => 0.0]], [], []]);
$bingSince = strtotime('-28 days');
$bingDay = '/Date(' . ((time() - 5 * 86400) * 1000) . '-0700)/';
$bingRows = Kaleta\Core\SearchData::bingRows(['d' => [
    ['Query' => 'kaleta bing', 'Clicks' => 5, 'Impressions' => 100, 'AvgImpressionPosition' => 4.0, 'Date' => $bingDay],
    ['Query' => 'kaleta bing', 'Clicks' => 1, 'Impressions' => 20, 'AvgImpressionPosition' => 10.0, 'Date' => $bingDay],
    ['Query' => 'stale', 'Clicks' => 9, 'Impressions' => 90, 'AvgImpressionPosition' => 1.0, 'Date' => '/Date(' . ((time() - 60 * 86400) * 1000) . ')/'],
    ['Query' => 'undated', 'Clicks' => 2, 'Impressions' => 0, 'AvgImpressionPosition' => 7.0], ['Query' => '', 'Clicks' => 3], 'junk',
]], 'query', $bingSince);
check('2.13 SearchData::bingRows – daily rows summed per query with the position weighted by impressions, old days dropped, undated rows kept, sorted by clicks', $bingRows,
    [['kind' => 'query', 'key' => 'kaleta bing', 'clicks' => 6, 'impressions' => 120, 'ctr' => 5.0, 'position' => 5.0], ['kind' => 'query', 'key' => 'undated', 'clicks' => 2, 'impressions' => 0, 'ctr' => 0.0, 'position' => 7.0]]);
check('2.13 SearchData::bingRows – pages come as Query or Page, a bare list works too; bingDate reads WCF and ISO dates', [
    array_column(Kaleta\Core\SearchData::bingRows([['Page' => 'https://example.com/a', 'Clicks' => 1, 'Impressions' => 2, 'AvgImpressionPosition' => 3], ['Query' => 'https://example.com/b', 'Clicks' => 4, 'Impressions' => 8, 'AvgImpressionPosition' => 2]], 'page'), 'key'),
    Kaleta\Core\SearchData::bingDate('/Date(1696291200000-0700)/'), Kaleta\Core\SearchData::bingDate('2026-10-01T00:00:00'), Kaleta\Core\SearchData::bingDate('soon'), Kaleta\Core\SearchData::bingDate(null)],
    [['https://example.com/b', 'https://example.com/a'], 1696291200, strtotime('2026-10-01T00:00:00'), null, null]);
