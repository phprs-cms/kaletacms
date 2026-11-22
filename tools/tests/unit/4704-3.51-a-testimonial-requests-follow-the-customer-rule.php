<?php

declare(strict_types=1);

/* ---------- 3.51 A4: a testimonial request is e-mailed only to a customer, once per enquiry, with a named sender, never to the register ---------- */
use Kaleta\Core\RestoreLedger as A704Ledger;
use Kaleta\Core\Settings as A704Settings;
use Kaleta\Core\Testimonials as A704Testimonials;

$a704Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$a704Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($a704Db, 'pdo'))->setValue($a704Db, $a704Pdo);
$a704Pdo->exec('CREATE TABLE ka_restore_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, hash TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (kind, hash))');
$a704Pdo->exec("INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES ('key', '" . str_repeat('cd', 32) . "', '2026-11-22 10:00:00')");
$a704Pdo->exec('CREATE TABLE ka_testimonial_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, idp INTEGER, token_hash TEXT, email TEXT, created_at TEXT, expires_at TEXT, used_at TEXT NULL, item_id INTEGER NULL, consent TEXT NULL, sent_at TEXT NULL)');
$a704Pdo->exec('CREATE TABLE ka_deals (id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INTEGER, stage TEXT, closed_at TEXT NULL)');
$a704Pdo->exec("CREATE TABLE ka_quotes (id INTEGER PRIMARY KEY AUTOINCREMENT, contact_id INTEGER, status TEXT, history TEXT NULL)");
// contact 1: a won deal; 2: a quote the customer accepted on its page; 3: a quote a person marked accepted; 4: a quote the
// customer declined; 5: a lost deal and an open one; 6: nothing
$a704Pdo->exec("INSERT INTO ka_deals (contact_id, stage, closed_at) VALUES (1, 'won', '2026-11-01 10:00:00'), (5, 'lost', '2026-11-01 10:00:00'), (5, 'proposal', NULL), (6, 'won', NULL)");
$a704Customer = fn (string $to): string => (string) json_encode([['at' => '2026-11-01 10:00:00', 'by' => 0, 'action' => 'status', 'from' => 'sent', 'to' => $to, 'via' => 'customer']]);
$a704Person = (string) json_encode([['at' => '2026-11-01 10:00:00', 'by' => 3, 'action' => 'status', 'from' => 'sent', 'to' => 'accepted']]);
$a704Pdo->prepare("INSERT INTO ka_quotes (contact_id, status, history) VALUES (2, 'accepted', ?), (3, 'accepted', ?), (4, 'declined', ?)")->execute([$a704Customer('accepted'), $a704Person, $a704Customer('declined')]);
$a704Settings = function (array $values): A704Settings {
    $s = new A704Settings(new Kaleta\Core\Db('invalid-a704:', '', ''));
    (new ReflectionProperty(A704Settings::class, 'values'))->setValue($s, $values + ['site_language' => 'cs', 'company_name' => 'Parabel s.r.o.', 'company_street' => 'Hlavní 1', 'company_city' => 'Brno',
        'extensions' => 'customers,pipeline,quotes']);
    (new ReflectionProperty(A704Settings::class, 'lazyRead'))->setValue($s, array_fill_keys((new ReflectionMethod(A704Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));

    return $s;
};
$a704Full = $a704Settings([]);
$a704Ask = fn (A704Settings $s, int $contact, string $email = 'jana@example.com', int $idp = 10): ?string => A704Testimonials::maySend($a704Db, $s, ['idp' => $idp, 'email' => $email, 'contact_id' => $contact]);
check('3.51 A4: maySend() – a won deal or a quote the customer accepted makes a customer; a quote a person marked accepted, a declined quote, a lost or open deal or nothing does not', [
    $a704Ask($a704Full, 1), $a704Ask($a704Full, 2), $a704Ask($a704Full, 3), $a704Ask($a704Full, 4), $a704Ask($a704Full, 5), $a704Ask($a704Full, 6), $a704Ask($a704Full, 0)],
    [null, null, 'not_a_customer', 'not_a_customer', 'not_a_customer', 'not_a_customer', 'not_a_customer']);
check('3.51 A4: a feature that is off has no customers (Pipeline off: the won deal no longer counts; Quotes off: the accepted quote no longer counts)', [
    $a704Ask($a704Settings(['extensions' => 'customers,quotes']), 1), $a704Ask($a704Settings(['extensions' => 'customers,quotes']), 2),
    $a704Ask($a704Settings(['extensions' => 'customers,pipeline']), 2), $a704Ask($a704Settings(['extensions' => 'customers,pipeline']), 1)],
    ['not_a_customer', null, 'not_a_customer', null]);
check('3.51 A4: without the company name or the postal address nothing is e-mailed (text 8) – before any other reason', [
    $a704Ask($a704Settings(['company_name' => '']), 1), $a704Ask($a704Settings(['company_street' => '', 'company_city' => '']), 1), $a704Ask($a704Settings(['company_name' => '']), 6)],
    ['sender_missing', 'sender_missing', 'sender_missing']);
$a704Pdo->exec("INSERT INTO ka_testimonial_requests (idp, token_hash, email, created_at, expires_at, sent_at) VALUES (10, 'a', 'jana@example.com', '2026-11-01', '2026-12-01', NULL)");
$a704First = $a704Ask($a704Full, 1);
$a704Pdo->exec("INSERT INTO ka_testimonial_requests (idp, token_hash, email, created_at, expires_at, sent_at) VALUES (10, 'b', 'jana@example.com', '2026-11-02', '2026-12-02', '2026-11-02 10:00:00')");
check('3.51 A4: once per enquiry – a link only given does not count, an e-mailed request does; another enquiry of the same customer may still be asked', [
    $a704First, $a704Ask($a704Full, 1), $a704Ask($a704Full, 1, 'jana@example.com', 11)], [null, 'already_sent', null]);
$a704Put = function (string $kind, string $email) use ($a704Db, $a704Pdo): void {
    $a704Pdo->prepare('INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES (?, ?, ?)')->execute([$kind, A704Ledger::fingerprint($a704Db, $email), '2026-11-22 10:00:00']);
};
$a704Put('bounced', 'dead@example.com');
$a704Put('refused', 'Said.No@example.com');
$a704Put('unsubscribed', 'left@example.com');
check('3.51 A4: an address on the send register is never e-mailed – refused and bounced name themselves; an unsubscribe alone does not stop a request', [
    $a704Ask($a704Full, 1, 'dead@example.com', 12), $a704Ask($a704Full, 1, 'said.no@example.com', 12), $a704Ask($a704Full, 1, 'left@example.com', 12)], ['bounced', 'refused', null]);
check('3.51 A4: every reason has its sentence for the screen (and the reason codes are the plan\'s)', array_keys(A704Testimonials::REASONS), ['sender_missing', 'not_a_customer', 'already_sent', 'refused', 'bounced']);
