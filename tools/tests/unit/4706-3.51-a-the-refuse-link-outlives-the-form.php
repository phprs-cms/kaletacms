<?php

declare(strict_types=1);

/* ---------- 3.51 A5: the refuse link of a testimonial request finds its request by the token alone – used and expired ones too ---------- */
use Kaleta\Core\Testimonials as A706Testimonials;

$a706Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$a706Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($a706Db, 'pdo'))->setValue($a706Db, $a706Pdo);
$a706Pdo->exec('CREATE TABLE ka_testimonial_requests (id INTEGER PRIMARY KEY AUTOINCREMENT, idp INTEGER, token_hash TEXT, email TEXT, created_at TEXT, expires_at TEXT, used_at TEXT NULL, item_id INTEGER NULL, consent TEXT NULL, sent_at TEXT NULL, refuse_link INTEGER NOT NULL DEFAULT 0)');
// three tokens built at run time (never a 32-hex literal in a test): open, used, expired
$a706Tokens = ['open' => bin2hex(random_bytes(16)), 'used' => bin2hex(random_bytes(16)), 'expired' => bin2hex(random_bytes(16))];
$a706Insert = $a706Pdo->prepare('INSERT INTO ka_testimonial_requests (idp, token_hash, email, created_at, expires_at, used_at) VALUES (?, ?, ?, ?, ?, ?)');
$a706Insert->execute([1, hash('sha256', $a706Tokens['open']), 'open@example.com', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 86400), null]);
$a706Insert->execute([2, hash('sha256', $a706Tokens['used']), 'used@example.com', date('Y-m-d H:i:s'), date('Y-m-d H:i:s', time() + 86400), date('Y-m-d H:i:s')]);
$a706Insert->execute([3, hash('sha256', $a706Tokens['expired']), 'old@example.com', date('Y-m-d H:i:s', time() - 40 * 86400), date('Y-m-d H:i:s', time() - 10 * 86400), null]);
$a706Pdo->exec('UPDATE ka_testimonial_requests SET sent_at = created_at, refuse_link = 1'); // the three were e-mailed with the refuse line (a link only handed out refuses nothing – unit 4720)
$a706Email = fn (?array $row): ?string => $row === null ? null : (string) $row['email'];
// 3.51 review (third round): the refuse line carries the code of its token, signed with the site's key
$a706Key = bin2hex(random_bytes(32));
$a706Refuse = fn (string $token, ?string $code = null): ?array => A706Testimonials::forRefusal($a706Db, $a706Key, $token, $code ?? A706Testimonials::refuseCode($a706Key, hash('sha256', $token)));
check('3.51 A5: forRefusal() finds an open, a used and an expired request by its token – the form (find()) only the open one', [
    $a706Email($a706Refuse($a706Tokens['open'])), $a706Email($a706Refuse($a706Tokens['used'])),
    $a706Email($a706Refuse($a706Tokens['expired'])),
    $a706Email(A706Testimonials::find($a706Db, $a706Tokens['open'])), $a706Email(A706Testimonials::find($a706Db, $a706Tokens['used'])), $a706Email(A706Testimonials::find($a706Db, $a706Tokens['expired']))],
    ['open@example.com', 'used@example.com', 'old@example.com', 'open@example.com', null, null]);
check('3.51 A5: an unknown token or one that is not 32 hex characters finds nothing', [
    $a706Refuse(bin2hex(random_bytes(16))), $a706Refuse(strtoupper($a706Tokens['open'])),
    $a706Refuse($a706Tokens['open'] . 'a'), $a706Refuse('')], [null, null, null, null]);
$a706Code = A706Testimonials::refuseCode($a706Key, hash('sha256', $a706Tokens['open']));
check('3.51 review (third round): the refuse code – 32 hex characters, the same for the same token and key, another for another token or key; the form link alone (no code, "1", a made-up or another token\'s code, another key\'s code) refuses nothing', [
    preg_match('/^[a-f0-9]{32}$/D', $a706Code), $a706Code === A706Testimonials::refuseCode($a706Key, hash('sha256', $a706Tokens['open'])),
    $a706Code === A706Testimonials::refuseCode($a706Key, hash('sha256', $a706Tokens['used'])), $a706Code === A706Testimonials::refuseCode(bin2hex(random_bytes(32)), hash('sha256', $a706Tokens['open'])),
    $a706Refuse($a706Tokens['open'], ''), $a706Refuse($a706Tokens['open'], '1'), $a706Refuse($a706Tokens['open'], bin2hex(random_bytes(16))),
    $a706Refuse($a706Tokens['open'], A706Testimonials::refuseCode($a706Key, hash('sha256', $a706Tokens['used']))),
    $a706Refuse($a706Tokens['open'], A706Testimonials::refuseCode(bin2hex(random_bytes(32)), hash('sha256', $a706Tokens['open']))),
    $a706Refuse($a706Tokens['open'], strtoupper($a706Code))],
    [1, true, false, false, null, null, null, null, null, null]);
