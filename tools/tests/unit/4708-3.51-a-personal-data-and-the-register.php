<?php

declare(strict_types=1);

/* ---------- 3.51 A6: a personal data request names the send register; the erasure keeps its fingerprint ---------- */
use Kaleta\Core\PersonalData as A708Personal;
use Kaleta\Core\RestoreLedger as A708Ledger;

$a708Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$a708Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($a708Db, 'pdo'))->setValue($a708Db, $a708Pdo);
$a708Pdo->exec('CREATE TABLE ka_restore_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, hash TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (kind, hash))');
$a708Pdo->exec("INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES ('key', '" . str_repeat('ef', 32) . "', '2026-11-22 10:00:00')");
foreach ([['refused', 'both@example.com'], ['bounced', 'both@example.com'], ['bounced', 'dead@example.com'], ['erased', 'gone@example.com'], ['unsubscribed', 'left@example.com']] as [$a708Kind, $a708Email]) {
    $a708Pdo->prepare('INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES (?, ?, ?)')->execute([$a708Kind, A708Ledger::fingerprint($a708Db, $a708Email), '2026-11-22 10:00:00']);
}
check('3.51 A6: registerKinds() – the kinds of the send register an address is on, in the register\'s order; erasures and unsubscribes are no part of it', [
    A708Personal::registerKinds($a708Db, 'both@example.com'), A708Personal::registerKinds($a708Db, ' Dead@Example.com '), A708Personal::registerKinds($a708Db, 'gone@example.com'),
    A708Personal::registerKinds($a708Db, 'left@example.com'), A708Personal::registerKinds($a708Db, 'new@example.com')],
    [['bounced', 'refused'], ['bounced'], [], [], []]);
