<?php

declare(strict_types=1);

/* ---------- 3.51 R0.4: the send register (bounced, refused) on the restore ledger, and the sender's identity ---------- */
use Kaleta\Core\RestoreLedger as R791Ledger;
use Kaleta\Core\SendRegister as R791Register;
use Kaleta\Core\SenderIdentity as R791Identity;
use Kaleta\Core\Settings as R791Settings;

// the ledger on an in-memory SQLite database with its key row (record() itself is MySQL – smoke 4791 writes through it)
$r791Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$r791Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($r791Db, 'pdo'))->setValue($r791Db, $r791Pdo);
$r791Pdo->exec('CREATE TABLE ka_restore_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, hash TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (kind, hash))');
$r791Pdo->exec("INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES ('key', '" . str_repeat('ab', 32) . "', '2026-11-22 10:00:00')");
$r791Put = function (string $kind, string $email) use ($r791Db, $r791Pdo): void {
    $r791Pdo->prepare('INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES (?, ?, ?)')->execute([$kind, R791Ledger::fingerprint($r791Db, $email), '2026-11-22 10:00:00']);
};
$r791Put('bounced', 'dead@example.com');
$r791Put('refused', 'Both@Example.com');
$r791Put('bounced', 'both@example.com');
$r791Put('unsubscribed', 'gone@example.com');
check('3.51 R0.4: blocked() names the kind (a refusal before a bounce, the address cleaned), null for an address that is not on the register or only unsubscribed', [
    R791Register::blocked($r791Db, 'dead@example.com'), R791Register::blocked($r791Db, ' BOTH@example.com '), R791Register::blocked($r791Db, 'gone@example.com'),
    R791Register::blocked($r791Db, 'new@example.com'), R791Register::blocked($r791Db, '')], ['bounced', 'refused', null, null, null]);
check('3.51 R0.4: counts() counts the two kinds only', R791Register::counts($r791Db), ['bounced' => 2, 'refused' => 1]);
R791Ledger::forget($r791Db, 'bounced', 'dead@example.com');
R791Ledger::forget($r791Db, 'refused', 'both@example.com');
R791Ledger::forget($r791Db, 'unsubscribed', 'gone@example.com');
check('3.51 R0.4: RestoreLedger::forget() never ends an entry of the register (a confirmed sign-up forgets only the unsubscribe)', [
    R791Register::counts($r791Db), (int) $r791Pdo->query("SELECT COUNT(*) FROM ka_restore_ledger WHERE kind = 'unsubscribed'")->fetchColumn()], [['bounced' => 2, 'refused' => 1], 0]);
R791Register::lift($r791Db, 'bounced', 'both@example.com');
R791Register::lift($r791Db, 'erased', 'dead@example.com');
check('3.51 R0.4: lift() takes one kind off (the refusal stays); an unknown kind lifts nothing', [
    R791Register::blocked($r791Db, 'both@example.com'), R791Register::blocked($r791Db, 'dead@example.com'), R791Register::counts($r791Db)], ['refused', 'bounced', ['bounced' => 1, 'refused' => 1]]);
check('3.51 R0.4: the restore summary takes the erasures and unsubscribes of its second half, never the register kinds', R791Ledger::merge(['restored' => 1, 'failed_first' => []],
    ['erased' => 2, 'unsubscribed' => 1, 'bounced' => 5, 'refused' => 3, 'failed_after' => []]), ['restored' => 1, 'erased' => 2, 'unsubscribed' => 1, 'failed' => false, 'failed_parts' => '']);
check('3.51 R0.4: the register kinds are the two of the plan', R791Register::KINDS, ['bounced', 'refused']);

// the sender's identity: settings given as the store would have them
$r791Settings = function (array $values): R791Settings {
    $s = new R791Settings(new Kaleta\Core\Db('invalid-r791:', '', ''));
    (new ReflectionProperty(R791Settings::class, 'values'))->setValue($s, $values + ['site_language' => 'cs']);
    (new ReflectionProperty(R791Settings::class, 'lazyRead'))->setValue($s, array_fill_keys((new ReflectionMethod(R791Settings::class, 'keysWith'))->invoke(null, 'lazy'), true));

    return $s;
};
$r791Full = $r791Settings(['company_name' => 'Parabel s.r.o.', 'company_street' => 'Hlavní 1', 'company_postcode' => '602 00', 'company_city' => 'Brno', 'company_country' => 'CZ', 'additional_languages' => 'en']);
check('3.51 R0.4: SenderIdentity::address() is the address in lines (no country for CZ), and Front\Company::address() gives the same', [
    R791Identity::address($r791Full), Kaleta\Front\Company::address($r791Full),
    R791Identity::address($r791Settings(['company_street' => 'Main St 5', 'company_city' => 'Wien', 'company_country' => 'AT']))],
    [['Hlavní 1', '602 00 Brno'], ['Hlavní 1', '602 00 Brno'], ['Main St 5', 'Wien', 'AT']]);
check('3.51 R0.4: SenderIdentity::line() fills mail_identity of the language with the name and the address; null without the name or without the address', [
    R791Identity::line($r791Full, ''), R791Identity::line($r791Full, 'en'),
    R791Identity::line($r791Settings(['company_name' => 'Parabel s.r.o.']), ''), R791Identity::line($r791Settings(['company_name' => ' ', 'company_city' => 'Brno']), ''),
    R791Identity::line($r791Settings(['company_name' => 'X', 'company_city' => 'Brno', 'mail_identity' => '{{company}} ({{address}}) – {{company}}']), '')],
    ['Odesílatel: Parabel s.r.o., Hlavní 1, 602 00 Brno.', 'Sent by Parabel s.r.o., Hlavní 1, 602 00 Brno.', null, null, 'X (Brno) – X']);
