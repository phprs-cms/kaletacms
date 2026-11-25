<?php

declare(strict_types=1);

/* ---------- 3.51 R0.5: a paused newsletter of a restored backup ends cancelled; who counts as a customer (won deal, quote the customer accepted) ---------- */
use Kaleta\Core\RestoreLedger as R794Ledger;

$r794Sqlite = function (array $tables): Kaleta\Core\Db {
    $db = new Kaleta\Core\Db('sqlite::memory:', '', '');
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
    foreach ($tables as $sql) {
        $pdo->exec($sql);
    }

    return $db;
};

check('3.51 R0.5: cancelledNewsletter() – status cancelled, the remainder as skipped (never below 0), the end kept or now', [
    array_intersect_key(R794Ledger::cancelledNewsletter(['id' => 4, 'status' => 'paused', 'recipients' => 10, 'sent_count' => 6, 'failed_count' => 1, 'finished_at' => null, 'paused_at' => '2026-11-20 10:00:00']),
        array_flip(['id', 'status', 'skipped_count', 'paused_at', 'recipients'])),
    R794Ledger::cancelledNewsletter(['status' => 'paused', 'recipients' => 3, 'sent_count' => 3, 'failed_count' => 2, 'finished_at' => '2026-11-21 08:00:00'])['skipped_count'],
    R794Ledger::cancelledNewsletter(['status' => 'paused', 'recipients' => 3, 'sent_count' => 0, 'failed_count' => 0, 'finished_at' => '2026-11-21 08:00:00'])['finished_at'],
    (string) R794Ledger::cancelledNewsletter(['status' => 'paused'])['finished_at'] !== ''],
    [['status' => 'cancelled', 'skipped_count' => 3, 'recipients' => 10, 'paused_at' => '2026-11-20 10:00:00'], 0, '2026-11-21 08:00:00', true]);

// the restore step over the restored table: today's rows win, a send that was under way is a draft, a paused one cancelled
$r794Db = $r794Sqlite(["CREATE TABLE ka_newsletters (id INTEGER PRIMARY KEY, status TEXT, scheduled_at TEXT NULL, started_at TEXT NULL, finished_at TEXT NULL, recipients INTEGER DEFAULT 0,
    sent_count INTEGER DEFAULT 0, failed_count INTEGER DEFAULT 0, skipped_count INTEGER DEFAULT 0, paused_at TEXT NULL, pause_reason TEXT DEFAULT '', resumed_at TEXT NULL,
    language TEXT DEFAULT '', audience TEXT DEFAULT 'all', audience_filter TEXT DEFAULT '')", // 3.54: the audience columns (0142) – SQLite has no "unknown column" fallback
    "INSERT INTO ka_newsletters (id, status, recipients, sent_count, failed_count, paused_at, pause_reason) VALUES
        (1, 'paused', 20, 5, 1, '2026-11-01 10:00:00', 'brake'), (2, 'sending', 20, 5, 0, NULL, ''), (3, 'sent', 10, 9, 1, NULL, ''), (4, 'sending', 8, 2, 0, NULL, ''), (5, 'draft', 0, 0, 0, NULL, '')"]);
$r794Today = [
    ['id' => 3, 'status' => 'sent', 'scheduled_at' => null, 'started_at' => '2026-11-02 08:00:00', 'finished_at' => '2026-11-02 09:00:00', 'recipients' => 10, 'sent_count' => 9, 'failed_count' => 1,
        'skipped_count' => 0, 'paused_at' => null, 'pause_reason' => '', 'resumed_at' => null],
    ['id' => 4, 'status' => 'paused', 'scheduled_at' => null, 'started_at' => '2026-11-03 08:00:00', 'finished_at' => null, 'recipients' => 8, 'sent_count' => 4, 'failed_count' => 1,
        'skipped_count' => 0, 'paused_at' => '2026-11-03 09:00:00', 'pause_reason' => 'manual', 'resumed_at' => null],
];
(new ReflectionMethod(R794Ledger::class, 'newsletters'))->invoke(null, $r794Db, $r794Today);
$r794Rows = array_column(array_map(fn (array $r): array => [$r['id'], $r['status'] . '|' . $r['skipped_count'] . '|' . ($r['finished_at'] !== null ? 'end' : '') . '|' . $r['pause_reason']],
    $r794Db->all('SELECT id, status, skipped_count, finished_at, pause_reason FROM {newsletters} ORDER BY id')), 1, 0);
check('3.51 R0.5: after a restore a paused newsletter of the backup and one paused today are cancelled with the remainder skipped (never resumable, never a draft that looks unsent); a send under way is a draft; today\'s sent row is kept', $r794Rows,
    [1 => 'cancelled|14|end|brake', 2 => 'draft|0||', 3 => 'sent|0|end|', 4 => 'cancelled|3|end|manual', 5 => 'draft|0||']);

// customers: a won deal (a stage of the kind won, closed), a quote the customer accepted on its page
$r794Crm = $r794Sqlite(['CREATE TABLE ka_deals (id INTEGER PRIMARY KEY, contact_id INTEGER, stage TEXT, closed_at TEXT NULL)',
    "INSERT INTO ka_deals (contact_id, stage, closed_at) VALUES (1, 'won', '2026-10-01 10:00:00'), (2, 'won', NULL), (3, 'lost', '2026-10-01 10:00:00'), (4, 'proposal', NULL)",
    'CREATE TABLE ka_quotes (id INTEGER PRIMARY KEY, contact_id INTEGER, status TEXT, history TEXT)',
    "INSERT INTO ka_quotes (contact_id, status, history) VALUES
        (5, 'accepted', '" . json_encode([['action' => 'status', 'from' => 'sent', 'to' => 'accepted', 'via' => 'customer']]) . "'),
        (6, 'accepted', '" . json_encode([['action' => 'status', 'from' => 'sent', 'to' => 'accepted', 'by' => 1]]) . "'),
        (7, 'declined', '" . json_encode([['action' => 'status', 'from' => 'sent', 'to' => 'declined', 'via' => 'customer']]) . "'),
        (8, 'accepted', NULL)"]);
$r794Stages = new Kaleta\Core\Settings(new Kaleta\Core\Db('invalid-r794:', '', ''));
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($r794Stages, ['pipeline_stages' => '']);
(new ReflectionProperty(Kaleta\Core\Settings::class, 'lazyRead'))->setValue($r794Stages, array_fill_keys((new ReflectionMethod(Kaleta\Core\Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));
check('3.51 R0.5: Deals::hasWon() – a closed deal in a won stage only (an open "won", a lost and an open one are not); 0 is nobody', array_map(fn (int $c): bool => Kaleta\Core\Deals::hasWon($r794Crm, $c, $r794Stages), [1, 2, 3, 4, 0]),
    [true, false, false, false, false]);
check('3.51 R0.5: Quotes::acceptedByCustomer() – accepted by the customer on the quote page; one a person marked accepted, a declined one and one without history are not', array_map(fn (int $c): bool => Kaleta\Core\Quotes::acceptedByCustomer($r794Crm, $c), [5, 6, 7, 8, 0]),
    [true, false, false, false, false]);
