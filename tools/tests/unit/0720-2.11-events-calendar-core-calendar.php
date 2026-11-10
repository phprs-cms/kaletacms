<?php

declare(strict_types=1);

/* ---------- 2.11: events calendar (Core\Calendar) ---------- */
use Kaleta\Core\Calendar;

check('2.11 Calendar::nextOccurrence – a weekly class that ended moves by whole weeks to the next one not ended yet', [
    Calendar::nextOccurrence('2026-09-29 18:00', '2026-09-29 19:30', 'weekly', '', '2026-10-02 12:00'),
    Calendar::nextOccurrence('2026-09-01 18:00', '2026-09-01 19:30', 'weekly', '', '2026-10-02 12:00'),
    Calendar::nextOccurrence('2026-10-06 18:00', '', 'weekly', '', '2026-10-02 12:00'),                  // not ended yet
    Calendar::nextOccurrence('2026-09-29', '', 'every 2 weeks', '', '2026-10-02 12:00'),                  // a whole day stays a day
    Calendar::nextOccurrence('2026-09-29 18:00', '', 'weekly', '2026-10-05', '2026-10-02 12:00'),         // the next one is after the last day
    Calendar::nextOccurrence('2026-09-29 18:00', '', 'never', '', '2026-10-02 12:00'),
], [['2026-10-06 18:00', '2026-10-06 19:30'], ['2026-10-06 18:00', '2026-10-06 19:30'], null, ['2026-10-13', ''], null, null]);
check('2.11 Calendar::nextOccurrence – monthly on the 31st ends on the last day of a shorter month, yearly keeps the day', [
    Calendar::nextOccurrence('2026-01-31 10:00', '', 'monthly', '', '2026-02-10 00:00'), Calendar::nextOccurrence('2026-01-31 10:00', '', 'monthly', '', '2026-03-10 00:00'),
    Calendar::nextOccurrence('2025-10-02', '', 'yearly', '', '2026-10-01 00:00')], [['2026-02-28 10:00', ''], ['2026-03-31 10:00', ''], ['2026-10-02', '']]);
check('2.11 Calendar::endsAt and registrationState: closed after the event or the deadline day, full at capacity, unlimited without', [
    Calendar::endsAt('2026-10-02', ''), Calendar::endsAt('2026-10-02 18:00', '2026-10-02 20:00'),
    Calendar::registrationState(20, 5, '', '2026-10-02 20:00', '2026-10-02 12:00'), Calendar::registrationState(20, 20, '', '2026-10-02 20:00', '2026-10-02 12:00'),
    Calendar::registrationState(0, 500, '', '2026-10-02 20:00', '2026-10-02 12:00'), Calendar::registrationState(20, 5, '', '2026-10-01 20:00', '2026-10-02 12:00'),
    Calendar::registrationState(20, 5, '2026-10-02', '2026-10-09 20:00', '2026-10-02 12:00'), Calendar::registrationState(20, 5, '2026-10-01', '2026-10-09 20:00', '2026-10-02 12:00'),
    Calendar::registrationState(20, 5, '2026-10-02 10:00', '2026-10-09 20:00', '2026-10-02 12:00')],
    ['2026-10-02 23:59', '2026-10-02 20:00', 'open', 'full', 'open', 'closed', 'open', 'closed', 'closed']);
check('2.11 Calendar::when – one day with times, several days, the start alone', [Calendar::when('2026-11-02 17:00', '2026-11-02 19:00'), Calendar::when('2026-11-02', '2026-11-04'), Calendar::when('2026-11-02 17:00', ''), Calendar::when('', '')],
    [format_date('2026-11-02 17:00', true) . '–19:00', format_date('2026-11-02') . ' – ' . format_date('2026-11-04'), format_date('2026-11-02 17:00', true), '']);
check('2.11 Calendar::escape and fold – RFC 5545 text, lines of at most 75 octets, never inside a character', [Calendar::escape("a;b,c\\d\nnext"),
    array_map('strlen', explode("\r\n", Calendar::fold('DESCRIPTION:' . str_repeat('č', 60)))), Calendar::fold('SUMMARY:short')],
    ['a\\;b\\,c\\\\d\\nnext', [74, 59], 'SUMMARY:short']);
$icsCollection = ['idk' => 1, 'seo_link' => 'akce', 'preset' => 'events', 'detail' => 1, 'pole' => Kaleta\Builder\Collections::sanitizeFields(array_map(fn (array $f): array => ['klic' => $f[0], 'popisek' => $f[1], 'typ' => $f[2], 'moznosti' => $f[3]['options'] ?? []],
    (array) (Kaleta\Builder\Presets::get('events')['fields'] ?? [])))];
$ics = Calendar::ics($icsCollection, [[['idp' => 7, 'nazev' => 'Jóga, pro začátečníky', 'zmeneno' => '2026-10-01 10:00:00', 'data' => ['start' => '2026-10-06 18:00', 'end' => '2026-10-06 19:30', 'venue' => 'Sál', 'address' => 'Hlavní 1, Brno', 'repeat' => 'weekly', 'repeat_until' => '2026-12-15', 'summary' => 'Přineste podložku.']], 'https://example.cz/akce/joga'],
    [['idp' => 8, 'nazev' => 'Den otevřených dveří', 'data' => ['start' => '2026-11-02']], ''], [['idp' => 9, 'nazev' => 'Bez data', 'data' => []], '']], 'Web – Akce', 'example.cz');
$utc = fn (string $local): string => (new DateTimeImmutable($local))->setTimezone(new DateTimeZone('UTC'))->format('Ymd\THis\Z');
check('2.11 Calendar::ics – a timed series with RRULE in UTC, a whole day as DATE, an event without a date left out', [str_starts_with($ics, "BEGIN:VCALENDAR\r\n"), str_contains($ics, "UID:kaleta-7@example.cz\r\n"),
    str_contains($ics, 'DTSTART:' . $utc('2026-10-06 18:00') . "\r\n"), str_contains($ics, 'RRULE:FREQ=WEEKLY;UNTIL=' . $utc('2026-12-15 23:59')), str_contains($ics, "SUMMARY:Jóga\\, pro začátečníky\r\n"),
    str_contains($ics, 'LOCATION:Sál\\, Hlavní 1\\, Brno'), str_contains($ics, "DTSTART;VALUE=DATE:20261102\r\nDTEND;VALUE=DATE:20261103\r\n"), substr_count($ics, 'BEGIN:VEVENT'), str_ends_with($ics, "END:VCALENDAR\r\n")],
    [true, true, true, true, true, true, true, 2, true]);
check('2.11 Calendar::fields – only a collection made from the events preset with its start field', [Calendar::fields($icsCollection)['repeat'] ?? null, Calendar::fields(['preset' => 'events', 'pole' => []]), Calendar::fields(['preset' => 'people', 'pole' => $icsCollection['pole']])],
    ['repeat', null, null]);

