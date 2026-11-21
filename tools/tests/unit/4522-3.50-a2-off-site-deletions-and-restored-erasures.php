<?php

declare(strict_types=1);

/* ---------- 3.50 A2: an off-site copy counts as deleted only on a positive answer; a restore names the sources it could not read (N169, N170) ---------- */
use Kaleta\Core\RemoteBackup as A522Remote;

check('3.50 N169: listingLacks – a listing that succeeded and lacks the name (bare or with its folder) is proof; a failed listing (false) or one with the name is not', [
    A522Remote::listingLacks(['kaleta-1.zip', 'private/kaleta-2.bin'], 'private/kaleta-3.bin'), A522Remote::listingLacks([], 'kaleta-1.zip'),
    A522Remote::listingLacks(false, 'kaleta-1.zip'), A522Remote::listingLacks(['./private/kaleta-3.bin'], 'private/kaleta-3.bin'),
    A522Remote::listingLacks([" kaleta-1.zip\r"], 'kaleta-1.zip')],
    [true, true, false, false, false]);
check('3.50 N169: s3Gone – only a 404 that says NoSuchKey; NoSuchBucket, AccessDenied or a bare 404 keep the entry', [
    A522Remote::s3Gone(404, 'NoSuchKey'), A522Remote::s3Gone(404, 'NoSuchBucket'), A522Remote::s3Gone(403, 'AccessDenied'), A522Remote::s3Gone(404, ''), A522Remote::s3Gone(500, 'NoSuchKey')],
    [true, false, false, false, false]);

// N170: candidates() on a database where one source cannot be read for another reason than a missing table or column (SQLite
// answers "no such table" with its own code, which Db::tableMissing does not take for MySQL's) – the source is named
$a522Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$a522Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($a522Db, 'pdo'))->setValue($a522Db, $a522Pdo);
foreach (['poptavky' => 'idp INTEGER, email TEXT, data TEXT', 'odberatele' => 'ido INTEGER, email TEXT', 'contacts' => 'id INTEGER, email TEXT', 'bookings' => 'id INTEGER, email TEXT',
    'testimonial_requests' => 'id INTEGER, email TEXT', 'posta' => 'idp INTEGER, komu TEXT', 'applications' => 'id INTEGER, email TEXT', 'quotes' => 'id INTEGER, customer_email TEXT',
    'contact_activities' => 'id INTEGER, merged_email TEXT, kind TEXT, text TEXT', 'webhook_deliveries' => 'id INTEGER, body TEXT', 'connector_queue' => 'id INTEGER, payload TEXT'] as $a522Table => $a522Columns) {
    $a522Pdo->exec('CREATE TABLE ka_' . $a522Table . ' (' . $a522Columns . ')');
}
$a522Pdo->exec("INSERT INTO ka_poptavky VALUES (1, 'Jana@Example.com', '[]'), (2, '', '[[\"E-mail\",\"petr@example.com\"]]')");
$a522Pdo->exec("INSERT INTO ka_quotes VALUES (1, 'quote@example.com')");
$a522Read = function () use ($a522Db): array {
    $skipped = [];
    $found = iterator_to_array(Kaleta\Core\PersonalData::candidates($a522Db, $skipped), false);
    sort($found);

    return [$found, $skipped];
};
$a522All = $a522Read();
$a522Pdo->exec('DROP TABLE ka_quotes');
check('3.50 N170: candidates() reads every source; a source that fails for another reason is skipped and named in $skipped, the others still give their addresses', [$a522All, $a522Read()],
    [[['jana@example.com', 'petr@example.com', 'quote@example.com'], []], [['jana@example.com', 'petr@example.com'], ['quotes']]]);
