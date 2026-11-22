<?php

declare(strict_types=1);

/* ---------- 3.51 B5 and B1: a newsletter needs its sender; a queue row is claimed only while its newsletter is being sent ---------- */
use Kaleta\Core\Mailing as B732Mailing;
use Kaleta\Core\Settings as B732Settings;

$b732Settings = function (array $values): B732Settings {
    $s = new B732Settings(new Kaleta\Core\Db('invalid-b732:', '', ''));
    (new ReflectionProperty(B732Settings::class, 'values'))->setValue($s, $values + ['site_language' => 'cs', 'additional_languages' => 'en', 'mail_mode' => 'smtp', 'smtp_host' => 'smtp.example.com',
        'tasks_last_run' => '1900000000', 'company_name' => 'Firma s.r.o.', 'company_street' => 'Hlavní 1', 'company_postcode' => '110 00', 'company_city' => 'Praha', 'company_country' => 'CZ']);
    (new ReflectionProperty(B732Settings::class, 'lazyRead'))->setValue($s, array_fill_keys((new ReflectionMethod(B732Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));

    return $s;
};
$b732Now = 1_900_000_100;
check('3.51 B5: problem() – SMTP first, then cron, then the sender: without the company name or the postal address a newsletter cannot go; with both nothing stops it', [
    B732Mailing::problemIn($b732Settings([]), $b732Now),
    B732Mailing::problemIn($b732Settings(['company_name' => '']), $b732Now),
    B732Mailing::problemIn($b732Settings(['company_street' => '', 'company_postcode' => '', 'company_city' => '']), $b732Now),
    B732Mailing::problemIn($b732Settings(['company_name' => '', 'mail_mode' => 'mail']), $b732Now) === B732Mailing::SENDER_MISSING,
    B732Mailing::problemIn($b732Settings(['company_name' => '', 'tasks_last_run' => '0']), $b732Now) === B732Mailing::SENDER_MISSING],
    [null, B732Mailing::SENDER_MISSING, B732Mailing::SENDER_MISSING, false, false]);

// the claim: an SQLite copy of the two tables
$b732Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$b732Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($b732Db, 'pdo'))->setValue($b732Db, $b732Pdo);
$b732Pdo->exec("CREATE TABLE ka_newsletters (id INTEGER PRIMARY KEY, status TEXT, recipients INTEGER DEFAULT 0, sent_count INTEGER DEFAULT 0, failed_count INTEGER DEFAULT 0, skipped_count INTEGER DEFAULT 0, finished_at TEXT NULL)");
$b732Pdo->exec("CREATE TABLE ka_newsletter_queue (id INTEGER PRIMARY KEY, newsletter_id INTEGER, subscriber_id INTEGER, attempts INTEGER DEFAULT 0, next_attempt TEXT NULL, sent_at TEXT NULL, error TEXT DEFAULT '', reply TEXT DEFAULT '', tried_at TEXT NULL)");
$b732Pdo->exec("CREATE TABLE ka_odberatele (ido INTEGER PRIMARY KEY, email TEXT, stav INTEGER)");
Kaleta\Core\Clock::freeze('2026-11-22 10:00:00');
$b732Pdo->exec("INSERT INTO ka_newsletters (id, status, recipients) VALUES (1, 'sending', 3), (2, 'sending', 2)");
$b732Pdo->exec("INSERT INTO ka_newsletter_queue (id, newsletter_id, subscriber_id, next_attempt) VALUES (1, 1, 1, '2026-11-22 09:59:00'), (2, 1, 2, '2026-11-22 09:59:00'), (3, 1, 3, '2026-11-22 09:59:00'),
    (4, 2, 1, '2026-11-22 09:59:00'), (5, 2, 2, '2026-11-22 09:59:00')");
$b732First = B732Mailing::claim($b732Db, 1);
$b732Again = B732Mailing::claim($b732Db, 1); // a second process: the row is no longer due
$b732Pdo->exec("UPDATE ka_newsletters SET status = 'paused' WHERE id = 1"); // a pause between the select and the claim
$b732AfterPause = B732Mailing::claim($b732Db, 2);
$b732Pdo->exec("UPDATE ka_newsletters SET status = 'cancelled' WHERE id = 2");
$b732AfterCancel = B732Mailing::claim($b732Db, 4);
check('3.51 B1: claim() – the first process takes a due row (one attempt more), a second one does not; after a pause or a cancel between the select and the claim the row is left alone', [
    $b732First, $b732Again, $b732AfterPause, $b732AfterCancel,
    $b732Db->all('SELECT id, attempts, next_attempt FROM {newsletter_queue} WHERE id IN (1, 2, 4) ORDER BY id')],
    [true, false, false, false, [['id' => 1, 'attempts' => 1, 'next_attempt' => '2026-11-22 10:10:00'], ['id' => 2, 'attempts' => 0, 'next_attempt' => '2026-11-22 09:59:00'],
        ['id' => 4, 'attempts' => 0, 'next_attempt' => '2026-11-22 09:59:00']]]);
Kaleta\Core\Clock::freeze(null);
