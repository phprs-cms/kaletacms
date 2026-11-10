<?php

declare(strict_types=1);

/* ---------- 3.0: online booking of appointments (Core\Booking) ---------- */
$bk = Kaleta\Core\Booking::class;
$bkTz = new DateTimeZone('Europe/Prague');
$bkNow = new DateTimeImmutable('2026-11-09 08:00', $bkTz); // the day before
$bkDay = '2026-11-10';
$bkRanges = [['09:00', '12:00'], ['13:00', '17:00']];
check('3.0 Booking::free – slots across a lunch break step by the duration', $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, $bkNow, 0, 60),
    ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30']);
check('3.0 Booking::free – a 45-minute service fits only where 45 minutes are left', $bk::free([['09:00', '11:00']], [], [], 45, 0, 45, $bkDay, $bkNow, 0, 60), ['09:00', '09:45']);
check('3.0 Booking::free – a day off (an exception) takes its hours out', $bk::free($bkRanges, [], [['2026-11-10 13:00:00', '2026-11-10 15:00:00']], 30, 0, 30, $bkDay, $bkNow, 0, 60),
    ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '15:00', '15:30', '16:00', '16:30']);
check('3.0 Booking::free – an existing booking blocks its time and the buffer around it; a booking of another day does not', $bk::free([['09:00', '12:00']], [['2026-11-10 10:00:00', '2026-11-10 10:30:00'], ['2026-11-11 09:00:00', '2026-11-11 12:00:00']], [], 30, 15, 30, $bkDay, $bkNow, 0, 60),
    ['09:00', '11:00', '11:30']);
check('3.0 Booking::free – the lead time: nothing sooner than two hours from now, never in the past', [
    $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, new DateTimeImmutable('2026-11-10 08:30', $bkTz), 2, 60),
    $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, new DateTimeImmutable('2026-11-10 16:45', $bkTz), 0, 60),
    $bk::free($bkRanges, [], [], 30, 0, 30, $bkDay, new DateTimeImmutable('2026-11-11 08:00', $bkTz), 0, 60)],
    [['10:30', '11:00', '11:30', '13:00', '13:30', '14:00', '14:30', '15:00', '15:30', '16:00', '16:30'], [], []]);
check('3.0 Booking::free – the horizon: a day beyond it has no times, the last day in it has', [
    $bk::free($bkRanges, [], [], 60, 0, 60, '2026-11-20', $bkNow, 0, 10), $bk::free($bkRanges, [], [], 60, 0, 60, '2026-11-19', $bkNow, 0, 10), $bk::free($bkRanges, [], [], 60, 0, 60, 'nonsense', $bkNow, 0, 10)],
    [[], ['09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00'], []]);
// the clocks go forward on 2026-03-29 at 02:00 in Prague: the slots are stepped on the real timeline and shown as wall-clock times
check('3.0 Booking::free – a DST day gives the same wall-clock times as any other, and a range across the switch skips the hour that does not exist', [
    $bk::free([['09:00', '12:00']], [], [], 60, 0, 60, '2026-03-29', new DateTimeImmutable('2026-03-28 10:00', $bkTz), 0, 60),
    $bk::free([['01:00', '04:00']], [], [], 60, 0, 60, '2026-03-29', new DateTimeImmutable('2026-03-28 10:00', $bkTz), 0, 60),
    $bk::free([['09:00', '12:00']], [['2026-03-29 10:00:00', '2026-03-29 11:00:00']], [], 60, 0, 60, '2026-03-29', new DateTimeImmutable('2026-03-28 10:00', $bkTz), 0, 60)],
    [['09:00', '10:00', '11:00'], ['01:00', '03:00'], ['09:00', '11:00']]);
check('3.0 Booking::leastBooked – "anyone" goes to the least-booked free person that day, ties to the first in the order, nobody free = null', [
    $bk::leastBooked([1, 2, 3], [1 => 2, 2 => 0, 3 => 1], [1, 2, 3]), $bk::leastBooked([1, 2], [], [2, 1]), $bk::leastBooked([3], [1 => 0, 3 => 5], [1, 2, 3]), $bk::leastBooked([], [], [1, 2])],
    [2, 2, 3, null]);
check('3.0 Booking::step – the duration up to an hour, otherwise a quarter', [$bk::step(30), $bk::step(45), $bk::step(60), $bk::step(90), $bk::step(0)], [30, 45, 60, 15, 15]);
check('3.0 Booking::parseHours – weekday names or numbers with ranges, empty days left out, a wrong range or day refused', [
    $bk::parseHours(['monday' => '9-12, 13:00-17:00', 2 => '', '7' => '10-14']), $bk::parseHours(['funday' => '9-12']), $bk::parseHours([1 => '12-9']), $bk::parseHours([3 => 'whenever'])],
    [[1 => [['09:00', '12:00'], ['13:00', '17:00']], 7 => [['10:00', '14:00']]], null, null, null]);
check('3.0 Booking::offRange – a day, a part of a day, a span; an end before the start is refused', [
    $bk::offRange('2026-12-24', ''), $bk::offRange('2026-12-24 08:00', '2026-12-24 12:00'), $bk::offRange('2026-12-24', '2026-12-26'), $bk::offRange('2026-12-26', '2026-12-24'), $bk::offRange('christmas', '')],
    [['2026-12-24 00:00:00', '2026-12-25 00:00:00'], ['2026-12-24 08:00:00', '2026-12-24 12:00:00'], ['2026-12-24 00:00:00', '2026-12-27 00:00:00'], null, null]);
$bkSite = ['Tuesday' => [['08:00', '16:00']]] + array_fill_keys(Kaleta\Core\Hours::DAYS, []);
$bkClosed = [['id' => 1, 'from' => '2026-11-10', 'to' => '2026-11-10', 'closed' => true, 'hours' => '', 'note' => 'Inventory', 'notice_days' => 0]];
check('3.0 Booking::dayRanges – own hours, else the site\'s; a closed day of the site is a day off for everyone', [
    $bk::dayRanges([2 => [['10:00', '18:00']]], $bkSite, [], new DateTimeImmutable('2026-11-10', $bkTz)), $bk::dayRanges([], $bkSite, [], new DateTimeImmutable('2026-11-10', $bkTz)),
    $bk::dayRanges([2 => [['10:00', '18:00']]], $bkSite, $bkClosed, new DateTimeImmutable('2026-11-10', $bkTz)), $bk::dayRanges([], $bkSite, $bkClosed, new DateTimeImmutable('2026-11-10', $bkTz)), $bk::dayRanges([], $bkSite, [], new DateTimeImmutable('2026-11-11', $bkTz))],
    [[['10:00', '18:00']], [['08:00', '16:00']], [], [], []]);
check('3.0 Booking: the tools are in the catalog with the right access, the job and the events exist, the element has an English name and its hook attribute is reserved', [
    Kaleta\Mcp\Catalog::TOOLS['list_bookings'], Kaleta\Mcp\Catalog::TOOLS['booking_availability'], Kaleta\Mcp\Catalog::TOOLS['save_booking_staff'], Kaleta\Mcp\Catalog::TOOLS['cancel_booking'], Kaleta\Mcp\Catalog::allows('drafts', 'list_bookings'), Kaleta\Mcp\Catalog::allows('drafts', 'cancel_booking'),
    Kaleta\Core\Scheduler::JOBS['booking_reminders'][0], isset(Kaleta\Core\Events::TYPES['booking.created']), isset(Kaleta\Core\Events::TYPES['booking.cancelled']), Kaleta\Mcp\Vocabulary::TYPES['rezervace'],
    Kaleta\Builder\Build::className('rezervace'), (bool) preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, 'data-rezervace'), (bool) preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, 'data-rezervace-x'), Kaleta\Admin\Guide::MODULES['bookings'] ?? null,
    Kaleta\Core\Settings::DEFAULTS['booking_lead_hours'], Kaleta\Core\Settings::DEFAULTS['booking_horizon_days'], in_array('booking_services', Kaleta\Core\SiteImport::TABLES, true)],
    [['read', ''], ['read', ''], ['write', ''], ['destructive', ''], true, false, 3600, true, true, 'booking', Kaleta\Builder\Elements\Booking::class, false, true, 'bookings', '2', '60', true]);

