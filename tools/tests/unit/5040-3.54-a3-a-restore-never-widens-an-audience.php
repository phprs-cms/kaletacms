<?php

declare(strict_types=1);

/* ---------- 3.54 A3: a restore keeps today's audience of a newsletter, and one scheduled today whose restored row reaches other people goes back to a draft ---------- */
use Kaleta\Core\RestoreLedger as A5040Ledger;

$a5040Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$a5040Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($a5040Db, 'pdo'))->setValue($a5040Db, $a5040Pdo);
// the restored table (a backup of 3.54): the backup's content, language and audience
$a5040Pdo->exec("CREATE TABLE ka_newsletters (id INTEGER PRIMARY KEY, status TEXT, scheduled_at TEXT NULL, started_at TEXT NULL, finished_at TEXT NULL, recipients INTEGER DEFAULT 0,
    sent_count INTEGER DEFAULT 0, failed_count INTEGER DEFAULT 0, skipped_count INTEGER DEFAULT 0, paused_at TEXT NULL, pause_reason TEXT DEFAULT '', resumed_at TEXT NULL,
    language TEXT DEFAULT '', audience TEXT DEFAULT 'all', audience_filter TEXT DEFAULT '', unsubscribed_count INTEGER DEFAULT 0)");
$a5040Pdo->exec("INSERT INTO ka_newsletters (id, status, language, audience, audience_filter, unsubscribed_count) VALUES
    (1, 'draft', 'en', 'language', '', 0), (2, 'draft', '', 'all', '', 0), (3, 'draft', 'en', 'language', '', 0), (4, 'draft', 'en', 'all', '', 0), (5, 'draft', '', 'all', '', 0),
    (6, 'draft', '', 'all', '', 7), (7, 'scheduled', '', 'all', 'customers', 0)");
$a5040Row = fn (int $id, string $status, array $audience): array => ['id' => $id, 'status' => $status, 'scheduled_at' => $status === 'scheduled' ? '2030-01-01 10:00:00' : null,
    'started_at' => null, 'finished_at' => null, 'recipients' => 0, 'sent_count' => 0, 'failed_count' => 0, 'skipped_count' => 0, 'paused_at' => null, 'pause_reason' => '', 'resumed_at' => null] + $audience;
$a5040Today = [
    $a5040Row(1, 'scheduled', ['audience' => 'language', 'audience_filter' => '', 'audience_language' => 'en']),   // the same audience
    $a5040Row(2, 'scheduled', ['audience' => 'all', 'audience_filter' => 'customers', 'audience_language' => '']), // today customers, the backup everyone
    $a5040Row(3, 'scheduled', ['audience' => 'language', 'audience_filter' => '', 'audience_language' => 'de']),   // the audience "language" of another language
    $a5040Row(4, 'scheduled', ['audience' => 'all', 'audience_filter' => '', 'audience_language' => '']),          // everyone – the language does not decide
    $a5040Row(5, 'scheduled', []),                                                                                  // a capture without the audience: not known
    ['id' => 6, 'status' => 'sent', 'scheduled_at' => null, 'started_at' => '2026-11-20 08:00:00', 'finished_at' => '2026-11-20 09:00:00', 'recipients' => 3, 'sent_count' => 3, 'failed_count' => 0,
        'skipped_count' => 0, 'paused_at' => null, 'pause_reason' => '', 'resumed_at' => null, 'audience' => 'all', 'audience_filter' => 'customers', 'audience_language' => ''],
];
(new ReflectionMethod(A5040Ledger::class, 'newsletters'))->invoke(null, $a5040Db, $a5040Today);
check('3.54 A3: after a restore – the same audience stays scheduled; a different audience, filter or (for the audience "language") language goes back to a draft; everyone in another language stays; an unknown audience today is a draft; today\'s audience is kept on every row; a sent one keeps its own unsubscriptions; one the backup has scheduled and today does not is a draft',
    array_map(fn (array $r): string => implode('|', array_map('strval', $r)), $a5040Db->all('SELECT id, status, COALESCE(scheduled_at, \'-\') AS at, audience, audience_filter, language, unsubscribed_count FROM {newsletters} ORDER BY id')),
    ['1|scheduled|2030-01-01 10:00:00|language||en|0', '2|draft|-|all|customers||0', '3|draft|-|language||en|0', '4|scheduled|2030-01-01 10:00:00|all||en|0', '5|draft|-|all|||0',
        '6|sent|-|all|customers||7', '7|draft|-|all|customers||0']);
check('3.54 A3: widens() – a restored row without the audience columns (a backup before 0142) has the default audience: it widens a customers newsletter, not one for everyone; an unknown audience today always widens', [
    A5040Ledger::widens(['audience' => 'all', 'audience_filter' => ''], ['id' => 1, 'status' => 'draft', 'language' => 'en']),
    A5040Ledger::widens(['audience' => 'all', 'audience_filter' => 'customers'], ['id' => 1, 'status' => 'draft', 'language' => '']),
    A5040Ledger::widens(['audience' => 'language', 'audience_filter' => '', 'audience_language' => ''], ['id' => 1, 'status' => 'draft', 'language' => '']),
    A5040Ledger::widens(['audience_filter' => ''], ['audience' => 'all', 'audience_filter' => ''])],
    [false, true, true, true]);
