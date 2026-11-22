<?php

declare(strict_types=1);

/* ---------- 3.51 B review (second round): the campaign count reads every address only when the number can be below TAG_MIN ---------- */
use Kaleta\Core\Mailing as B752Mailing;
use Kaleta\Core\RestoreLedger as B752Ledger;

$b752Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$b752Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($b752Db, 'pdo'))->setValue($b752Db, $b752Pdo);
$b752Pdo->exec('CREATE TABLE ka_restore_ledger (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, hash TEXT NOT NULL, created_at TEXT NOT NULL, UNIQUE (kind, hash))');
$b752Pdo->exec("INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES ('key', '" . str_repeat('ab', 32) . "', '2026-11-22 10:00:00')");
$b752Pdo->exec('CREATE TABLE ka_odberatele (ido INTEGER PRIMARY KEY, email TEXT NOT NULL, stav INTEGER NOT NULL)');
$b752Put = fn (string $kind, string $email) => $b752Pdo->prepare('INSERT INTO ka_restore_ledger (kind, hash, created_at) VALUES (?, ?, ?)')->execute([$kind, B752Ledger::fingerprint($b752Db, $email), '2026-11-22 10:00:00']);
$b752Subscribers = function (int $count) use ($b752Pdo): void {
    $b752Pdo->exec('DELETE FROM ka_odberatele');
    for ($i = 1; $i <= $count; $i++) {
        $b752Pdo->exec("INSERT INTO ka_odberatele (email, stav) VALUES ('s$i@example.com', 1)");
    }
};
// the register holds two addresses that are no subscribers here and one bounced subscriber (a refused + bounced address is two rows)
$b752Put('bounced', 'gone1@example.com');
$b752Put('refused', 'gone2@example.com');
$b752Put('bounced', 's1@example.com');
$b752Subscribers(10);
$b752Large = B752Mailing::reachableForCampaign($b752Db);
$b752Subscribers(7);
$b752Small = B752Mailing::reachableForCampaign($b752Db);
check('3.51 B review (second round): ten confirmed minus the three rows of the register still reach TAG_MIN – the lower bound 7 is returned without fingerprinting the list (the exact number would be 9); seven confirmed minus three can be below it, so every address is read: exactly 6',
    [$b752Large, $b752Small, B752Mailing::TAG_MIN], [7, 6, 5]);
$b752Subscribers(5);
$b752Pdo->exec("DELETE FROM ka_restore_ledger WHERE kind IN ('bounced', 'refused')");
check('3.51 B review (second round): an empty register and five confirmed – the campaign name is used (5)', B752Mailing::reachableForCampaign($b752Db), 5);
