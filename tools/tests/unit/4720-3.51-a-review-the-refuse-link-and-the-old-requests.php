<?php

declare(strict_types=1);

/* ---------- 3.51 A review: the refuse link acts only for a request whose e-mail carried it; 0139 counts the requests made before the update as e-mailed, never as refusable ---------- */
use Kaleta\Core\Testimonials as A720Testimonials;

$a720Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$a720Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($a720Db, 'pdo'))->setValue($a720Db, $a720Pdo);
$a720Pdo->exec('CREATE TABLE ka_testimonial_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, idp INTEGER, token_hash TEXT, email TEXT, created_at TEXT, expires_at TEXT, used_at TEXT NULL, item_id INTEGER NULL, consent TEXT NULL, sent_at TEXT NULL, refuse_link INTEGER NOT NULL DEFAULT 0)');
// tokens built at run time (never a 32-hex literal in a test): a link only handed out (staff, Claude with send=false) and an e-mailed one
$a720Tokens = ['link' => bin2hex(random_bytes(16)), 'mailed' => bin2hex(random_bytes(16))];
$a720Insert = $a720Pdo->prepare('INSERT INTO ka_testimonial_requests (idp, token_hash, email, created_at, expires_at, sent_at, refuse_link) VALUES (?, ?, ?, ?, ?, ?, ?)');
$a720Insert->execute([7, hash('sha256', $a720Tokens['link']), 'jana@example.com', '2026-11-22 10:00:00', '2026-12-22 10:00:00', null, 0]);
$a720Insert->execute([8, hash('sha256', $a720Tokens['mailed']), 'petr@example.com', '2026-11-22 10:00:00', '2026-12-22 10:00:00', '2026-11-22 10:00:01', 1]);
$a720Email = fn (?array $row): ?string => $row === null ? null : (string) $row['email'];
$a720Key = bin2hex(random_bytes(32));
$a720Refuse = fn (string $token): ?array => A720Testimonials::forRefusal($a720Db, $a720Key, $token, A720Testimonials::refuseCode($a720Key, hash('sha256', $token)));
check('3.51 A review: the refuse link finds only a request that was e-mailed – a link handed out by hand cannot put its customer on the register or end the subscription; the form (find()) still opens both', [
    $a720Email($a720Refuse($a720Tokens['link'])), $a720Email($a720Refuse($a720Tokens['mailed'])),
    $a720Email(A720Testimonials::find($a720Db, $a720Tokens['link'])) !== null],
    [null, 'petr@example.com', true]);

// 0139 on the same table: every request made before the update counts as e-mailed (which ones were is not recorded) – "once per enquiry" holds for them
$a720Pdo->exec('CREATE TABLE ka_nastaveni (promenna TEXT PRIMARY KEY, hodnota TEXT)');
$a720Pdo->exec("CREATE TABLE ka_odberatele (ido INTEGER PRIMARY KEY, zdroj TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', contact_id INTEGER NULL)");
$a720Pdo->exec('CREATE TABLE ka_tasks (id INTEGER PRIMARY KEY, assignee_id INTEGER NULL, created_by INTEGER NULL, assigned_by INTEGER NULL)');
$a720Run = require KALETA_SYSTEM . '/sql/migrace/0139-subscriber-language-and-task-assigners.php';
$a720Run($a720Db, new Kaleta\Core\Settings($a720Db));
$a720Sent = fn (): array => array_map(fn (array $r): string => $r['sent_at'] . '|' . $r['refuse_link'], array_column($a720Pdo->query('SELECT idp, sent_at, refuse_link FROM ka_testimonial_requests ORDER BY idp')->fetchAll(), null, 'idp'));
$a720First = $a720Sent();
$a720Run($a720Db, new Kaleta\Core\Settings($a720Db));
check('3.51 A review: 0139 sets sent_at = created_at on a request without it and keeps the time of one that has it; refuse_link is never set by it; a second run changes nothing', [$a720First, $a720Sent()],
    [[7 => '2026-11-22 10:00:00|0', 8 => '2026-11-22 10:00:01|1'], [7 => '2026-11-22 10:00:00|0', 8 => '2026-11-22 10:00:01|1']]);
check('3.51 A review (second round): the old request counts as e-mailed for "once per enquiry" (already_sent), but its link still refuses nothing – an e-mail from before 3.51 never carried the refuse line, so a link only handed to staff or Claude puts nobody on the register',
    [$a720Email($a720Refuse($a720Tokens['link'])), (string) $a720Db->value('SELECT COUNT(*) FROM {testimonial_requests} WHERE idp = 7 AND sent_at IS NOT NULL')],
    [null, '1']);

// 3.51 review (third round): settle() – a request whose e-mail waited in the mail queue learns how it ended
$a720Pdo->exec('ALTER TABLE ka_testimonial_requests ADD COLUMN mail_id INTEGER NULL');
$a720Pdo->exec("CREATE TABLE ka_posta (idp INTEGER PRIMARY KEY, komu TEXT NOT NULL DEFAULT '', odeslano TEXT NULL, dalsi_pokus TEXT NULL, telo TEXT NULL)");
$a720Pdo->exec("INSERT INTO ka_posta (idp, odeslano, dalsi_pokus) VALUES (21, '2026-11-22 10:05:00', NULL), (22, NULL, NULL), (23, NULL, '2026-11-22 12:00:00')");
$a720Mail = $a720Pdo->prepare('INSERT INTO ka_testimonial_requests (idp, token_hash, email, created_at, expires_at, sent_at, refuse_link, mail_id) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
foreach ([31 => 21, 32 => 22, 33 => 23, 34 => 99] as $a720Idp => $a720Row) { // delivered later, given up, still trying, its log row gone
    $a720Mail->execute([$a720Idp, hash('sha256', bin2hex(random_bytes(16))), 'q@example.com', '2026-11-22 10:00:00', '2026-12-22 10:00:00', '2026-11-22 10:00:01', $a720Row]);
}
A720Testimonials::settle($a720Db);
check('3.51 review (third round): settle() – delivered later: still e-mailed, the queue row forgotten; given up: no longer e-mailed and refuses nothing; still trying or the log row gone: unchanged',
    array_map(fn (array $r): string => ($r['sent_at'] !== null ? 'sent' : '-') . '|' . $r['refuse_link'] . '|' . ($r['mail_id'] ?? '-'), array_column($a720Pdo->query('SELECT idp, sent_at, refuse_link, mail_id FROM ka_testimonial_requests WHERE idp > 30 ORDER BY idp')->fetchAll(), null, 'idp')),
    [31 => 'sent|1|-', 32 => '-|0|-', 33 => 'sent|1|23', 34 => 'sent|1|99']);
