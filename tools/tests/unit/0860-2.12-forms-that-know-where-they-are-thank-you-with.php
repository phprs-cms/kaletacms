<?php

declare(strict_types=1);

/* ---------- 2.12: forms that know where they are, thank-you with next steps ---------- */
check('2.12 EnquiryTopic::itemSlug – the item from the address of its page, with a language prefix or a query; a list page or another collection is none', [
    Kaleta\Front\EnquiryTopic::itemSlug('sluzby', '/sluzby/koupelna'), Kaleta\Front\EnquiryTopic::itemSlug('sluzby', '/en/sluzby/koupelna/?form=x'), Kaleta\Front\EnquiryTopic::itemSlug('sluzby', '/sluzby'),
    Kaleta\Front\EnquiryTopic::itemSlug('sluzby', '/jine/koupelna'), Kaleta\Front\EnquiryTopic::itemSlug('', '/a/b'), Kaleta\Front\EnquiryTopic::itemSlug('sluzby', '/sluzby/Koupelna%20X')],
    ['koupelna', 'koupelna', null, null, null, null]);
check('2.12 EnquiryTopic::compose – "collection – item", a page alone, trimmed and cut to the column', [
    Kaleta\Front\EnquiryTopic::compose('Služby', 'Rekonstrukce koupelny'), Kaleta\Front\EnquiryTopic::compose(' Kontakt '), Kaleta\Front\EnquiryTopic::compose('', 'Okno'), mb_strlen(Kaleta\Front\EnquiryTopic::compose(str_repeat('a', 200), str_repeat('b', 200)))],
    ['Služby – Rekonstrukce koupelny', 'Kontakt', 'Okno', 255]);
$nsWeek = Kaleta\Front\NextSteps::DEFAULT_WEEK;
$nsLunch = array_fill_keys(Kaleta\Core\Hours::DAYS, []);
foreach (['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'] as $nsDay) { $nsLunch[$nsDay] = [['08:00', '12:00'], ['13:00', '17:00']]; }
$nsHoliday = ['id' => 1, 'from' => '2026-10-12', 'to' => '2026-10-12', 'closed' => true, 'hours' => '', 'note' => 'Holiday', 'notice_days' => 0];
$nsShort = ['id' => 2, 'from' => '2026-10-13', 'to' => '2026-10-13', 'closed' => false, 'hours' => '9-10', 'note' => '', 'notice_days' => 0];
$nsDeadline = fn (array $week, array $ex, string $from, int $hours): string => Kaleta\Front\NextSteps::deadline($week, $ex, new DateTimeImmutable($from), $hours)->format('Y-m-d H:i');
check('2.12 NextSteps::deadline – Friday 16:00 + 4 working hours with Mo–Fr 8–17 is Monday 11:00; a holiday on Monday moves it to Tuesday', [
    $nsDeadline($nsWeek, [], '2026-10-09 16:00', 4), $nsDeadline($nsWeek, [$nsHoliday], '2026-10-09 16:00', 4)], ['2026-10-12 11:00', '2026-10-13 11:00']);
check('2.12 NextSteps::deadline – within the day, over the lunch break, sent before opening or on a weekend, shorter hours of an exception, two working days', [
    $nsDeadline($nsWeek, [], '2026-10-05 09:00', 2), $nsDeadline($nsLunch, [], '2026-10-05 11:30', 2), $nsDeadline($nsWeek, [], '2026-10-05 06:00', 1), $nsDeadline($nsWeek, [], '2026-10-10 10:00', 1),
    $nsDeadline($nsWeek, [$nsHoliday, $nsShort], '2026-10-09 16:30', 2), $nsDeadline($nsWeek, [], '2026-10-05 10:00', 18)],
    ['2026-10-05 11:00', '2026-10-05 14:30', '2026-10-05 09:00', '2026-10-12 09:00', '2026-10-14 08:30', '2026-10-07 10:00']);
check('2.12 NextSteps::deadline – no open day at all: plain hours', $nsDeadline(array_fill_keys(Kaleta\Core\Hours::DAYS, []), [], '2026-10-09 16:00', 4), '2026-10-09 20:00');
$nsNow = new DateTimeImmutable('2026-10-05 09:00');
check('2.12 NextSteps::deadlineText – today, tomorrow, a weekday within the week, further away with the date', [
    Kaleta\Front\NextSteps::deadlineText(new DateTimeImmutable('2026-10-05 16:00'), $nsNow), Kaleta\Front\NextSteps::deadlineText(new DateTimeImmutable('2026-10-06 08:30'), $nsNow),
    Kaleta\Front\NextSteps::deadlineText(new DateTimeImmutable('2026-10-08 11:00'), $nsNow), Kaleta\Front\NextSteps::deadlineText(new DateTimeImmutable('2026-10-19 11:00'), $nsNow)],
    [t('We will reply today by %s.', '16:00'), t('We will reply tomorrow by %s.', '8:30'), t('We will reply %s by %s.', t('on Thursday'), '11:00'), t('We will reply by %s.', format_date('2026-10-19 11:00', true))]);
check('2.12 NextSteps::steps – one step per line, empty lines dropped; the content keys have English names', [Kaleta\Front\NextSteps::steps(['dalsi_kroky' => "Zavoláme vám\r\n\n  Přijedeme na zaměření \n"]), Kaleta\Front\NextSteps::steps([]),
    Kaleta\Mcp\Vocabulary::CONTENT['dalsi_kroky'], Kaleta\Mcp\Vocabulary::CONTENT['odpovime_do'], Kaleta\Mcp\Vocabulary::CONTENT['odpovida']],
    [['Zavoláme vám', 'Přijedeme na zaměření'], [], 'next_steps', 'reply_within_hours', 'who_replies']);

