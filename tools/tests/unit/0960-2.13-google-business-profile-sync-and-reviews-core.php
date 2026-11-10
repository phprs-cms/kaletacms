<?php

declare(strict_types=1);

/* ---------- 2.13: Google Business Profile sync and reviews (Core\GoogleBusiness) ---------- */
$gbpWeek = array_fill_keys(Kaleta\Core\Hours::DAYS, []);
$gbpWeek['Monday'] = [['08:00', '12:00'], ['13:00', '17:30']];
$gbpWeek['Saturday'] = [['09:00', '24:00']];
$gbpRegular = Kaleta\Core\GoogleBusiness::regularHours($gbpWeek);
check('2.13 GBP: the regular week as Business Information periods – one per range, the day in capitals, 24:00 as hours 24, an empty week = no periods', [
    count($gbpRegular['periods']), $gbpRegular['periods'][1], $gbpRegular['periods'][2]['closeTime'], Kaleta\Core\GoogleBusiness::regularHours(array_fill_keys(Kaleta\Core\Hours::DAYS, []))],
    [3, ['openDay' => 'MONDAY', 'openTime' => ['hours' => 13, 'minutes' => 0], 'closeDay' => 'MONDAY', 'closeTime' => ['hours' => 17, 'minutes' => 30]], ['hours' => 24, 'minutes' => 0], ['periods' => []]]);
$gbpToday = new DateTimeImmutable('2026-10-03');
$gbpSpecial = Kaleta\Core\GoogleBusiness::specialHours([
    ['from' => '2026-12-24', 'to' => '2026-12-26', 'closed' => true, 'hours' => ''],              // three closed days → three periods
    ['from' => '2026-12-31', 'to' => '2026-12-31', 'closed' => false, 'hours' => '9-12, 13-15'], // two ranges → two periods
    ['from' => '2026-10-01', 'to' => '2026-10-04', 'closed' => true, 'hours' => ''],              // started before today: only today and tomorrow
    ['from' => '2027-11-01', 'to' => '2027-11-02', 'closed' => true, 'hours' => ''],              // beyond 12 months: left out
    ['from' => '2026-11-11', 'to' => '2026-11-11', 'closed' => false, 'hours' => 'nonsense'],     // unparsable hours: nothing Google could show
], $gbpToday)['specialHourPeriods'];
check('2.13 GBP: exceptions as specialHours – day by day, closed or with ranges, from today for 12 months', [
    count($gbpSpecial), $gbpSpecial[0], $gbpSpecial[3], array_map(fn (array $p): string => sprintf('%d-%02d-%02d', $p['startDate']['year'], $p['startDate']['month'], $p['startDate']['day']), $gbpSpecial)],
    [7, ['startDate' => ['year' => 2026, 'month' => 12, 'day' => 24], 'endDate' => ['year' => 2026, 'month' => 12, 'day' => 24], 'closed' => true],
        ['startDate' => ['year' => 2026, 'month' => 12, 'day' => 31], 'openTime' => ['hours' => 9, 'minutes' => 0], 'endDate' => ['year' => 2026, 'month' => 12, 'day' => 31], 'closeTime' => ['hours' => 12, 'minutes' => 0]],
        ['2026-12-24', '2026-12-25', '2026-12-26', '2026-12-31', '2026-12-31', '2026-10-03', '2026-10-04']]);
check('2.13 GBP: a years-long exception stops at 12 months and three ranges a day stop at 400 periods', [count(Kaleta\Core\GoogleBusiness::specialHours([['from' => '2026-01-01', 'to' => '2029-01-01', 'closed' => true, 'hours' => '']], $gbpToday)['specialHourPeriods']),
    count(Kaleta\Core\GoogleBusiness::specialHours([['from' => '2026-01-01', 'to' => '2029-01-01', 'closed' => false, 'hours' => '8-9, 9-10, 10-11']], $gbpToday)['specialHourPeriods'])], [366, 400]);
$gbpPost = Kaleta\Core\GoogleBusiness::postBody(['titulek' => 'New &amp; <b>bigger</b> hall', 'uvod' => "<p>We   opened\na new hall.</p>"], 'https://example.cz/novinky/hala', 'https://example.cz/media/hala.jpg', 'cs');
$gbpLong = Kaleta\Core\GoogleBusiness::postBody(['titulek' => 'T', 'uvod' => str_repeat('a', 2000)], 'https://example.cz/n', '', 'en');
check('2.13 GBP: a news item as a STANDARD post – title and intro as plain text, the image, a LEARN_MORE button; a long intro is cut to 1500 characters; no image = no media', [
    $gbpPost, mb_strlen($gbpLong['summary']), mb_substr($gbpLong['summary'], -1), isset($gbpLong['media'])],
    [['languageCode' => 'cs', 'summary' => "New & bigger hall\n\nWe opened a new hall.", 'topicType' => 'STANDARD', 'callToAction' => ['actionType' => 'LEARN_MORE', 'url' => 'https://example.cz/novinky/hala'],
        'media' => [['mediaFormat' => 'PHOTO', 'sourceUrl' => 'https://example.cz/media/hala.jpg']]], 1500, '…', false]);
$gbpRow = Kaleta\Core\GoogleBusiness::reviewRow(['reviewId' => 'r1', 'reviewer' => ['displayName' => ' <b>Jana</b> '], 'starRating' => 'FOUR', 'comment' => 'Fine', 'createTime' => '2026-09-20T10:00:00Z',
    'reviewReply' => ['comment' => 'Thanks', 'updateTime' => '2026-09-21T08:00:00Z']], '2026-10-03 12:00:00');
check('2.13 GBP: a review from the v4 API – stars from the word, the name without tags, the reply; no stars or id = no row', [
    $gbpRow['stars'], $gbpRow['author'], $gbpRow['reply'], $gbpRow['replied_at'] !== null, $gbpRow['reviewed_at'] !== '2026-10-03 12:00:00',
    Kaleta\Core\GoogleBusiness::reviewRow(['reviewId' => 'r2', 'starRating' => 'SIX'], 'now'), Kaleta\Core\GoogleBusiness::reviewRow(['starRating' => 'FIVE'], 'now')],
    [4, 'Jana', 'Thanks', true, true, null, null]);
$gbpRows = [['stars' => 5], ['stars' => 2], ['stars' => 4], ['stars' => 3], ['stars' => 5]];
check('2.13 GBP: the newest N reviews with at least M stars; the limits are clamped', [Kaleta\Core\GoogleBusiness::filter($gbpRows, 2, 4), Kaleta\Core\GoogleBusiness::filter($gbpRows, 10, 0), Kaleta\Core\GoogleBusiness::filter($gbpRows, 0, 9)],
    [[['stars' => 5], ['stars' => 4]], $gbpRows, [['stars' => 5]]]);
check('2.13 GBP: the queue handler, the daily job, the element in the builder and its English vocabulary, the facts', [
    Kaleta\Core\Connectors::handler('gbp.hours'), Kaleta\Core\Scheduler::JOBS['gbp'][0], in_array(Kaleta\Builder\Elements\GoogleReviews::class, Kaleta\Builder\Build::ELEMENTS, true),
    Kaleta\Mcp\Vocabulary::TYPES['recenze_google'], Kaleta\Mcp\Vocabulary::elementToCzech(['type' => 'google_reviews', 'content' => ['count' => 3, 'min_stars' => 4, 'summary' => true, 'link' => 'https://maps.google.com/?cid=1']]),
    isset(Kaleta\Core\Facts::BUILT_IN['google_rating']), isset(Kaleta\Core\Facts::BUILT_IN['google_reviews']), array_diff(Kaleta\Core\GoogleBusiness::CONFIG, array_keys(Kaleta\Connectors\Google::settings())) === []],
    [Kaleta\Core\GoogleBusiness::class, 86400, true, 'google_reviews', ['typ' => 'recenze_google', 'obsah' => ['pocet' => 3, 'min_hvezd' => 4, 'souhrn' => true, 'odkaz' => 'https://maps.google.com/?cid=1']], true, true, true]);
