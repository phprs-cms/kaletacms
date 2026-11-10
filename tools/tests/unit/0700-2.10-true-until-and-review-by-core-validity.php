<?php

declare(strict_types=1);

/* ---------- 2.10: true until and review by (Core\Validity) ---------- */
$validityRows = [
    ['id' => 1, 'title' => 'Spring offer', 'visible' => 1, 'valid_until' => '2026-09-30', 'review_by' => null],   // expired yesterday, still visible
    ['id' => 2, 'title' => 'Today', 'visible' => 1, 'valid_until' => '2026-10-01', 'review_by' => '2026-10-01'],  // true until today: still true, review due today
    ['id' => 3, 'title' => 'Hidden', 'visible' => 0, 'valid_until' => '2026-01-01', 'review_by' => '2026-01-01'], // already hidden: not hidden again, review still due
    ['id' => 4, 'title' => 'Future', 'visible' => 1, 'valid_until' => '2026-12-31', 'review_by' => '2026-10-02'],
    ['id' => 5, 'title' => 'Asked', 'visible' => 1, 'valid_until' => null, 'review_by' => '2026-09-20'],           // review already recorded for this date
    ['id' => 6, 'title' => 'Re-asked', 'visible' => 1, 'valid_until' => null, 'review_by' => '2026-09-25'],        // recorded for an older date – a changed date asks again
];
$validityAsked = ['5|2026-09-20' => true, '6|2026-09-01' => true];
check('2.10 Validity::expired – visible rows whose day has passed, today still counts as true', array_column(Kaleta\Core\Validity::expired($validityRows, '2026-10-01'), 'id'), [1]);
check('2.10 Validity::expired – the day after, today\'s row expires too', array_column(Kaleta\Core\Validity::expired($validityRows, '2026-10-02'), 'id'), [1, 2]);
check('2.10 Validity::dueForReview – today or earlier, once per content and date', array_column(Kaleta\Core\Validity::dueForReview($validityRows, '2026-10-01', $validityAsked), 'id'), [2, 3, 6]);
check('2.10 Validity::dueForReview – nothing asked yet', array_column(Kaleta\Core\Validity::dueForReview($validityRows, '2026-10-02', []), 'id'), [2, 3, 4, 5, 6]);
check('2.10 Validity::date – a form or Claude date, a datetime cut to its day, nonsense and empty = none', [Kaleta\Core\Validity::date('2026-10-01'), Kaleta\Core\Validity::date(' 2026-10-01T12:00 '),
    Kaleta\Core\Validity::date('2026-02-30'), Kaleta\Core\Validity::date(''), Kaleta\Core\Validity::date(null), Kaleta\Core\Validity::date('tomorrow')], ['2026-10-01', '2026-10-01', null, null, null, null]);
check('2.10 Validity::isDate', [Kaleta\Core\Validity::isDate('2026-10-01'), Kaleta\Core\Validity::isDate('2026-13-01'), Kaleta\Core\Validity::isDate('1.10.2026'), Kaleta\Core\Validity::isDate(20261001)], [true, false, false, false]);
check('2.10: the validity job, the audit kind and the events are known', [Kaleta\Core\Scheduler::JOBS['validity'][0], Kaleta\Core\Scheduler::JOBS['validity'][1], isset(Kaleta\Core\Scheduler::jobs()['validity']),
    Kaleta\Core\Audit::KINDS['review'], isset(Kaleta\Core\Events::TYPES['content.expired'], Kaleta\Core\Events::TYPES['content.review'])], [3600, 'any', true, 'Review by', true]);

