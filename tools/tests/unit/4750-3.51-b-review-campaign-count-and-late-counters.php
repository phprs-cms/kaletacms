<?php

declare(strict_types=1);

/* ---------- 3.51 B review: the campaign name counts the subscribers a newsletter can reach; an attempt that ends after a cancel keeps the equality ---------- */
use Kaleta\Core\Mailing as B750Mailing;
use Kaleta\Core\RestoreLedger as B750Ledger;
use Kaleta\Core\SendRegister as B750Register;

$b750Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$b750Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($b750Db, 'pdo'))->setValue($b750Db, $b750Pdo);
$b750Pdo->exec('CREATE TABLE ka_restore_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, hash TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (kind, hash))');
$b750Pdo->exec("INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES ('key', '" . str_repeat('cd', 32) . "', '2026-11-22 10:00:00')");
$b750Pdo->exec('CREATE TABLE ka_odberatele (ido INTEGER PRIMARY KEY, email TEXT NOT NULL, stav INTEGER NOT NULL)');
$b750Pdo->exec("INSERT INTO ka_odberatele (email, stav) VALUES ('a@example.com', 1), ('b@example.com', 1), ('c@example.com', 1), ('Dead1@Example.com', 1), ('dead2@example.com', 1), ('waiting@example.com', 0)");
$b750Before = [B750Mailing::confirmedCount($b750Db), B750Mailing::reachableCount($b750Db)];
$b750Put = fn (string $kind, string $email) => $b750Pdo->prepare('INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES (?, ?, ?)')->execute([$kind, B750Ledger::fingerprint($b750Db, $email), '2026-11-22 10:00:00']);
$b750Put('bounced', 'dead1@example.com');
$b750Put('refused', 'dead2@example.com');
$b750Put('unsubscribed', 'a@example.com');
$b750Put('refused', 'waiting@example.com');
check('3.51 B review: five confirmed with one bounced and one refused can reach three – an unsubscribed record and an unconfirmed address change nothing; an empty register reaches all five', [
    $b750Before, B750Mailing::confirmedCount($b750Db), B750Mailing::reachableCount($b750Db), B750Register::reachable($b750Db, []), B750Register::reachable($b750Db, ['x@example.com', ' DEAD1@example.com '])],
    [[5, 5], 5, 3, 0, 1]);
check('3.51 B review: fingerprints() gives what fingerprint() gives, address by address', B750Ledger::fingerprints($b750Db, ['A@example.com', 'b@example.com']),
    [B750Ledger::fingerprint($b750Db, 'a@example.com'), B750Ledger::fingerprint($b750Db, 'b@example.com')]);
check('3.51 B review (unit 4734 case): 5 confirmed of whom 2 are on the register give no utm_campaign', B750Mailing::campaignUrl('https://firma.example', 'https://firma.example/novinky', 'Jaro', 9, B750Mailing::reachableCount($b750Db)),
    'https://firma.example/novinky?utm_source=newsletter&utm_medium=email');

// the counter update of an attempt that ends after the newsletter ended: finish() counted that row as skipped
$b750Late = (new ReflectionClassConstant(B750Mailing::class, 'LATE'))->getValue();
$b750Pdo->exec('CREATE TABLE ka_newsletters (id INTEGER PRIMARY KEY, status TEXT, recipients INTEGER, sent_count INTEGER, failed_count INTEGER, skipped_count INTEGER)');
$b750Pdo->exec("INSERT INTO ka_newsletters VALUES (1, 'cancelled', 4, 1, 0, 3), (2, 'sending', 4, 1, 0, 0), (3, 'cancelled', 4, 3, 1, 0)");
$b750Pdo->exec("UPDATE ka_newsletters SET sent_count = sent_count + 1, $b750Late WHERE id IN (1, 2)");
$b750Pdo->exec("UPDATE ka_newsletters SET failed_count = failed_count + 1, $b750Late WHERE id = 3");
check('3.51 B review: a delivery that ends after a cancel moves one from skipped to sent (recipients = sent + failed + skipped); a newsletter still sending keeps skipped at 0; skipped never goes below 0',
    array_map(fn (array $r): string => implode('|', $r), $b750Pdo->query('SELECT status, recipients, sent_count, failed_count, skipped_count FROM ka_newsletters ORDER BY id')->fetchAll()),
    ['cancelled|4|2|0|2', 'sending|4|2|0|0', 'cancelled|4|3|2|0']);
