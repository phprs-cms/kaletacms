<?php

declare(strict_types=1);

/* ---------- 3.51 A review: the refuse link acts only for an e-mailed request; 0139 counts the requests made before the update as e-mailed ---------- */
use Kaleta\Core\Testimonials as A720Testimonials;

$a720Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$a720Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($a720Db, 'pdo'))->setValue($a720Db, $a720Pdo);
$a720Pdo->exec('CREATE TABLE ka_testimonial_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, idp INTEGER, token_hash TEXT, email TEXT, created_at TEXT, expires_at TEXT, used_at TEXT NULL, item_id INTEGER NULL, consent TEXT NULL, sent_at TEXT NULL)');
// tokens built at run time (never a 32-hex literal in a test): a link only handed out (staff, Claude with send=false) and an e-mailed one
$a720Tokens = ['link' => bin2hex(random_bytes(16)), 'mailed' => bin2hex(random_bytes(16))];
$a720Insert = $a720Pdo->prepare('INSERT INTO ka_testimonial_requests (idp, token_hash, email, created_at, expires_at, sent_at) VALUES (?, ?, ?, ?, ?, ?)');
$a720Insert->execute([7, hash('sha256', $a720Tokens['link']), 'jana@example.com', '2026-11-22 10:00:00', '2026-12-22 10:00:00', null]);
$a720Insert->execute([8, hash('sha256', $a720Tokens['mailed']), 'petr@example.com', '2026-11-22 10:00:00', '2026-12-22 10:00:00', '2026-11-22 10:00:01']);
$a720Email = fn (?array $row): ?string => $row === null ? null : (string) $row['email'];
check('3.51 A review: the refuse link finds only a request that was e-mailed – a link handed out by hand cannot put its customer on the register or end the subscription; the form (find()) still opens both', [
    $a720Email(A720Testimonials::forRefusal($a720Db, $a720Tokens['link'])), $a720Email(A720Testimonials::forRefusal($a720Db, $a720Tokens['mailed'])),
    $a720Email(A720Testimonials::find($a720Db, $a720Tokens['link'])) !== null],
    [null, 'petr@example.com', true]);

// 0139 on the same table: every request made before the update counts as e-mailed (which ones were is not recorded) – "once per enquiry" holds for them
$a720Pdo->exec('CREATE TABLE ka_nastaveni (promenna TEXT PRIMARY KEY, hodnota TEXT)');
$a720Pdo->exec("CREATE TABLE ka_odberatele (ido INTEGER PRIMARY KEY, zdroj TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', contact_id INTEGER NULL)");
$a720Pdo->exec('CREATE TABLE ka_tasks (id INTEGER PRIMARY KEY, assignee_id INTEGER NULL, created_by INTEGER NULL, assigned_by INTEGER NULL)');
$a720Run = require KALETA_SYSTEM . '/sql/migrace/0139-subscriber-language-and-task-assigners.php';
$a720Run($a720Db, new Kaleta\Core\Settings($a720Db));
$a720Sent = fn (): array => array_column($a720Pdo->query('SELECT idp, sent_at FROM ka_testimonial_requests ORDER BY idp')->fetchAll(), 'sent_at', 'idp');
$a720First = $a720Sent();
$a720Run($a720Db, new Kaleta\Core\Settings($a720Db));
check('3.51 A review: 0139 sets sent_at = created_at on a request without it and keeps the time of one that has it; a second run changes nothing', [$a720First, $a720Sent()],
    [[7 => '2026-11-22 10:00:00', 8 => '2026-11-22 10:00:01'], [7 => '2026-11-22 10:00:00', 8 => '2026-11-22 10:00:01']]);
check('3.51 A review: the old request now refuses (its e-mail may have carried no refuse line, but the person may still refuse)', $a720Email(A720Testimonials::forRefusal($a720Db, $a720Tokens['link'])), 'jana@example.com');
