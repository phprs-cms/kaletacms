<?php

declare(strict_types=1);

/* ---------- 2.11: official notice board (Core\Notices) ---------- */
$board = ['idk' => 7, 'preset' => 'notices', 'seo_link' => 'deska', 'detail' => 1,
    'pole' => Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2]], Kaleta\Builder\Presets::get('notices')['fields']))];
check('2.11 Notices: the preset has the dates, the archive page and its own item template; the board is recognised by preset and field', [
    array_column(Kaleta\Builder\Presets::get('notices')['fields'], 0), Kaleta\Builder\Presets::get('notices')['extra_pages'][0]['suffix'], Kaleta\Builder\Presets::get('notices')['extra_pages'][0]['list']['obdobi'],
    is_callable(Kaleta\Builder\Presets::get('notices')['template']), Kaleta\Core\Notices::isBoard($board), Kaleta\Core\Notices::isBoard(['preset' => 'people', 'pole' => $board['pole']]),
    Kaleta\Core\Notices::isNotices(['preset' => 'notices', 'pole' => []]), Kaleta\Core\Notices::isBoard(['preset' => 'notices', 'pole' => []])],
    [['posted', 'taken_down', 'reference', 'issuer', 'category', 'document', 'summary'], 'archive', 'minule', true, true, false, true, false]);
check('2.11 Notices::status – to be posted, on the board (the takedown day still counts), archived the day after, no dates', [
    Kaleta\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-02'), Kaleta\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-03'), Kaleta\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-18'),
    Kaleta\Core\Notices::status('2026-10-03', '2026-10-18', '2026-10-19'), Kaleta\Core\Notices::status('2026-10-03', '', '2027-01-01'), Kaleta\Core\Notices::status('', '', '2026-10-02')],
    ['upcoming', 'current', 'current', 'archived', 'current', '']);
check('2.11 Notices::statusText – the sentence for visitors with the site\'s date format', [
    Kaleta\Core\Notices::statusText('2026-10-03', '2026-10-18', '2026-10-10'), Kaleta\Core\Notices::statusText('2026-10-03', '2026-10-18', '2026-10-19'), Kaleta\Core\Notices::statusText('2026-10-03', '2026-10-18', '2026-10-01'),
    Kaleta\Core\Notices::statusText('2026-10-03', '', '2026-10-10'), Kaleta\Core\Notices::statusText('', '', '2026-10-10')],
    [t('Posted from %s to %s', format_date('2026-10-03'), format_date('2026-10-18')), t('Taken down on %s – archived', format_date('2026-10-18')), t('To be posted on %s', format_date('2026-10-03')), t('Posted from %s', format_date('2026-10-03')), '']);
$noticeItem = ['nazev' => 'Záměr', 'seo_link' => 'zamer', 'datum' => '2026-10-02 10:00:00', 'data' => ['posted' => '2026-10-03', 'taken_down' => '2026-10-18', 'reference' => 'MU/1', 'document' => '/media/zamer.pdf']];
check('2.11 {{notice_status}} only for a notice board – the people preset has none', [isset(Kaleta\Builder\Collections::values($board, $noticeItem, fn (string $p): string => '/' . $p)['notice_status']),
    isset(Kaleta\Builder\Collections::values(['preset' => 'people', 'seo_link' => 'lide', 'detail' => 1, 'pole' => $board['pole']], $noticeItem, fn (string $p): string => '/' . $p)['notice_status'])], [true, false]);
check('2.11 Notices::canHide – only a notice still to be posted (or without a date) may be hidden', [Kaleta\Core\Notices::canHide('2026-10-03', '2026-10-02'), Kaleta\Core\Notices::canHide('2026-10-02', '2026-10-02'), Kaleta\Core\Notices::canHide('2026-09-01', '2026-10-02'), Kaleta\Core\Notices::canHide('', '2026-10-02')],
    [true, false, false, true]);
$noticeRows = [
    ['idp' => 1, 'visible' => true, 'posted' => '2026-10-01', 'taken_down' => '2026-10-18'],  // on the board: posted today
    ['idp' => 2, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-10-01'],  // taken down yesterday: both in one run
    ['idp' => 3, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-10-02'],  // the takedown day itself still counts
    ['idp' => 4, 'visible' => false, 'posted' => '2026-09-01', 'taken_down' => ''],          // hidden – never posted
    ['idp' => 5, 'visible' => true, 'posted' => '2026-10-03', 'taken_down' => ''],           // to be posted tomorrow
    ['idp' => 6, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-09-20'], // already recorded, both
    ['idp' => 7, 'visible' => true, 'posted' => '2026-09-01', 'taken_down' => '2026-09-20'], // posted recorded, the takedown not yet
];
check('2.11 Notices::due – posted when the day comes (visible only), taken_down the day after, each once', Kaleta\Core\Notices::due($noticeRows, [6 => ['posted' => true, 'taken_down' => true], 7 => ['posted' => true]], '2026-10-02'),
    [[1, 'posted', '2026-10-01'], [2, 'posted', '2026-09-01'], [2, 'taken_down', '2026-10-01'], [3, 'posted', '2026-09-01'], [7, 'taken_down', '2026-09-20']]);
check('2.11 Notices::due – a second run adds nothing', Kaleta\Core\Notices::due($noticeRows, [1 => ['posted' => true], 2 => ['posted' => true, 'taken_down' => true], 3 => ['posted' => true], 6 => ['posted' => true, 'taken_down' => true], 7 => ['posted' => true, 'taken_down' => true]], '2026-10-02'), []);
$noticePrevious = ['idp' => 5, 'nazev' => 'Rozpočet', 'seo_link' => 'rozpocet', 'zobrazit' => 1, 'data' => '{"posted":"2026-10-01","taken_down":"","reference":"MU/12","issuer":"","category":"","document":"","summary":""}'];
check('2.11 Notices::changes – a new notice lists its values, a change only what differs, no change nothing', [
    Kaleta\Core\Notices::changes($board, null, ['nazev' => 'Rozpočet', 'seo_link' => 'rozpocet', 'zobrazit' => 1, 'data' => '{"posted":"2026-10-01","taken_down":"","reference":"MU/12"}']),
    Kaleta\Core\Notices::changes($board, $noticePrevious, ['nazev' => 'Rozpočet 2026', 'data' => '{"posted":"2026-10-01","taken_down":"2026-10-20","reference":"MU/12","issuer":"","category":"","document":"","summary":""}', 'zmeneno' => 'x']),
    Kaleta\Core\Notices::changes($board, $noticePrevious, ['nazev' => 'Rozpočet', 'seo_link' => 'rozpocet', 'data' => $noticePrevious['data']])],
    [['name' => ['', 'Rozpočet'], 'slug' => ['', 'rozpocet'], 'visible' => ['', 'yes'], 'posted' => ['', '2026-10-01'], 'reference' => ['', 'MU/12']],
        ['name' => ['Rozpočet', 'Rozpočet 2026'], 'taken_down' => ['', '2026-10-20']], []]);
check('2.11 Notices::changesText and the job are known', [Kaleta\Core\Notices::changesText(['posted' => ['', '2026-10-03'], 'name' => ['A', 'B'], 'taken_down' => '2026-10-18']),
    Kaleta\Core\Scheduler::JOBS['notices'][0], Kaleta\Core\Scheduler::JOBS['notices'][1], isset(Kaleta\Core\Scheduler::jobs()['notices'])], ['posted: → 2026-10-03; name: A → B; taken_down: 2026-10-18', 3600, 'any', true]);
