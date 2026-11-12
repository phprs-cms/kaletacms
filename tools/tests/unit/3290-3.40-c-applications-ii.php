<?php

declare(strict_types=1);

use Kaleta\Core\ApplicationCounts;
use Kaleta\Core\Applications;
use Kaleta\Core\TalentPool;

/* ---------- 3.40 (C): applications II – counts, the talent pool renewal, the webhook, the reminder refined ---------- */
$c340History = (string) json_encode([['at' => '2026-11-03 10:00:00', 'by' => 1, 'from' => 'new', 'to' => 'interview'], ['at' => '2026-10-20 10:00:00', 'by' => 1, 'from' => 'new', 'to' => 'offer']]);
check('3.40 counts: a counted status once per application and month (the history of this month decides); new and reviewed are never counted', [
    ApplicationCounts::counts('interview', $c340History, '2026-11-12 09:00:00'), ApplicationCounts::counts('offer', $c340History, '2026-11-12 09:00:00'),
    ApplicationCounts::counts('interview', $c340History, '2026-12-01 00:00:00'), ApplicationCounts::counts('hired', null, '2026-11-12 09:00:00'),
    ApplicationCounts::counts('reviewed', null, '2026-11-12 09:00:00'), ApplicationCounts::counts('new', '', '2026-11-12 09:00:00')],
    [false, true, true, true, false, false]);
check('3.40 counts: the months of the statistics, oldest first, across a year; the columns', [
    ApplicationCounts::monthsBetween('2025-12', '2026-02'), count(ApplicationCounts::monthsBetween('2025-12', '2026-11')), ApplicationCounts::monthsBetween('2026-03', '2026-02'),
    ApplicationCounts::COLUMNS, ApplicationCounts::MONTHS],
    [['2025-12', '2026-01', '2026-02'], 12, [], ['received', 'interview', 'offer', 'hired', 'rejected'], 12]);

$c340Pool = [
    ['id' => 1, 'email' => 'a@example.com', 'pool_consent_at' => '2025-11-20 10:00:00', 'pool_until' => '2026-11-20', 'pool_renewal_sent_at' => null],                 // due
    ['id' => 2, 'email' => 'b@example.com', 'pool_consent_at' => '2025-11-20 10:00:00', 'pool_until' => '2026-11-20', 'pool_renewal_sent_at' => '2026-11-01 08:00:00'], // sent already
    ['id' => 3, 'email' => 'c@example.com', 'pool_consent_at' => '2026-11-01 10:00:00', 'pool_until' => '2026-11-30', 'pool_renewal_sent_at' => '2026-10-01 08:00:00'], // renewed since: due again
    ['id' => 4, 'email' => 'd@example.com', 'pool_consent_at' => '2026-01-20 10:00:00', 'pool_until' => '2027-01-20', 'pool_renewal_sent_at' => null],                 // too early
    ['id' => 5, 'email' => 'e@example.com', 'pool_consent_at' => '2025-11-01 10:00:00', 'pool_until' => '2026-11-10', 'pool_renewal_sent_at' => null],                 // lapsed
    ['id' => 6, 'email' => '', 'pool_consent_at' => '2025-11-20 10:00:00', 'pool_until' => '2026-11-20', 'pool_renewal_sent_at' => null],                              // nobody to write to
    ['id' => 7, 'email' => 'g@example.com', 'pool_consent_at' => null, 'pool_until' => '2026-11-20', 'pool_renewal_sent_at' => null],                                  // no consent
];
check('3.40 talent pool: the renewal e-mail is due within RENEW_DAYS of the lapse, once per consent, only to one address', [
    TalentPool::due($c340Pool, '2026-11-12'), TalentPool::renewalSent($c340Pool[1]), TalentPool::renewalSent($c340Pool[2]), TalentPool::RENEW_DAYS, TalentPool::PATH],
    [[1, 3], true, false, 30, 'application-consent']);
$c340Body = TalentPool::webhookBody('Site', 42, ['item' => 7, 'title' => 'Welder'], '2026-11-12 09:30:00');
check('3.40 webhook application.received: the event, the site, the number and the position – no key of a person', [
    array_keys($c340Body), $c340Body['event'], $c340Body['application'], $c340Body['position'], TalentPool::webhookBody('Site', 1, ['item' => null, 'title' => ''], '2026-11-12 09:30:00')['position'],
    array_intersect(array_keys($c340Body), ['name', 'email', 'phone', 'fields', 'data', 'pole', 'attachments'])],
    [['event', 'site', 'application', 'position', 'position_id', 'received_at'], 'application.received', 42, 'Welder', null, []]);

$c340Rows = [
    ['id' => 1, 'status' => 'interview', 'decided_at' => null, 'last_activity_at' => '2026-05-15 09:00:00', 'pool_until' => null, 'assignee_id' => 4],             // goes 2026-11-15
    ['id' => 2, 'status' => 'hired', 'decided_at' => '2026-05-15 09:00:00', 'last_activity_at' => '2026-05-15 09:00:00', 'pool_until' => null, 'assignee_id' => 4], // hired, goes 2026-11-15
    ['id' => 3, 'status' => 'rejected', 'decided_at' => '2026-05-15 09:00:00', 'last_activity_at' => '2026-05-15 09:00:00', 'pool_until' => null, 'assignee_id' => 4],
];
$c340Ids = fn (array $due): array => array_column($due, 'id');
check('3.40 reminder: once per deletion day – the same day stays quiet, a moved day is announced again, a 3.34 bell entry (true) never again; hired ones with $hired, rejected never', [
    $c340Ids(Applications::dueForWarning($c340Rows, 6, '2026-11-11', [])), $c340Ids(Applications::dueForWarning($c340Rows, 6, '2026-11-11', [], true)),
    $c340Ids(Applications::dueForWarning($c340Rows, 6, '2026-11-11', [1 => '2026-11-15', 2 => '2026-11-15'], true)),
    $c340Ids(Applications::dueForWarning($c340Rows, 6, '2026-11-11', [1 => '2026-11-13', 2 => true], true))],
    [[1], [1, 2], [], [1]]);

// the renewal e-mail and its page speak every visitor language (cs/de/es and the others); the German informal overlay has its own
$c340Visitor = ['The link is invalid, has already been used or has expired. If you have a question about your application, reply to the e-mail you received.', 'Your application was deleted',
    'We have deleted your application and everything you sent with it. Thank you for your interest in %s.', 'Thank you – we keep your application',
    'We keep your application until %s and will contact you when a suitable opening comes up.', 'Keep my application for another %d months', 'Delete my application now',
    'Your application with %s', 'You applied for %s and agreed that we keep your application until %s for future openings.', 'If you do nothing, we delete your application after %s.',
    'Hello %s,', 'when you applied for %s, you agreed that we keep your application until %s and contact you about other openings.', 'May we keep it for another %d months?',
    'Yes, keep my application:', 'No, delete my application now:', 'May we keep your application? – %s'];
$c340Missing = [];
foreach (['cs', 'de', 'es', 'fr', 'it', 'pl', 'pt', 'sk', 'hu', 'zh', 'zh-hant', 'ar'] as $c340Code) {
    $c340Dict = require KALETA_SYSTEM . '/jazyky/' . $c340Code . '.php';
    foreach ($c340Visitor as $c340Key) {
        $c340Value = $c340Dict[$c340Key] ?? null;
        if (!is_string($c340Value) || substr_count($c340Value, '%') !== substr_count($c340Key, '%')) {
            $c340Missing[] = $c340Code . ': ' . $c340Key;
        }
    }
}
check('3.40: the texts of the renewal e-mail and its page are in every visitor dictionary, with their placeholders', $c340Missing, []);
check('3.40: the counts table is in the English name map; the statistics, board and talent pool are plain reads of the section', [
    Kaleta\Core\Names::table('application_counts'), Applications::BOARD_DECIDED_DAYS, Applications::BULK_MAX],
    ['application_counts', 30, 100]);
