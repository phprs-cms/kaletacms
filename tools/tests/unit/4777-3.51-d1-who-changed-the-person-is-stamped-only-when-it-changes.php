<?php

declare(strict_types=1);

/* ---------- 3.51 D1 (SECURITY-3): the hand-over and one assignment stamp assigned_by – only when the person changes ---------- */
use Kaleta\Core\Handover as D777Handover;

// an in-memory database with every table the hand-over of a leaver moves; an App whose signed-in person is $actor
$d777App = function (int $actor): Kaleta\Core\App {
    $db = new Kaleta\Core\Db('sqlite::memory:', '', '');
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
    foreach ([
        'CREATE TABLE ka_stranky (ids INTEGER PRIMARY KEY, owner_id INTEGER NULL, smazano TEXT NULL)',
        'CREATE TABLE ka_kolekce_polozky (idp INTEGER PRIMARY KEY, owner_id INTEGER NULL, smazano TEXT NULL)',
        'CREATE TABLE ka_poptavky (idp INTEGER PRIMARY KEY, stav INTEGER DEFAULT 0, prirazeno INTEGER NULL, assigned_by INTEGER NULL)',
        'CREATE TABLE ka_tasks (id INTEGER PRIMARY KEY, assignee_id INTEGER NULL, assigned_by INTEGER NULL, done_at TEXT NULL, notified_at TEXT NULL, escalated_at TEXT NULL, updated_at TEXT NULL)',
        'CREATE TABLE ka_contacts (id INTEGER PRIMARY KEY, owner_id INTEGER NULL)',
        'CREATE TABLE ka_companies (id INTEGER PRIMARY KEY, owner_id INTEGER NULL)',
        'CREATE TABLE ka_deals (id INTEGER PRIMARY KEY, owner_id INTEGER NULL, assigned_by INTEGER NULL)',
        "CREATE TABLE ka_applications (id INTEGER PRIMARY KEY, assignee_id INTEGER NULL, status TEXT DEFAULT 'new')",
        'INSERT INTO ka_stranky (ids, owner_id) VALUES (1, 5)',
        'INSERT INTO ka_poptavky (idp, stav, prirazeno, assigned_by) VALUES (1, 1, 5, 2), (2, 2, 5, 2), (3, 0, 6, 2), (4, 0, NULL, 2)',
        "INSERT INTO ka_tasks (id, assignee_id, assigned_by, done_at) VALUES (1, 5, 2, NULL), (2, 5, 2, '2026-11-01 10:00:00')",
        'INSERT INTO ka_deals (id, owner_id, assigned_by) VALUES (1, 5, 2), (2, 6, 2)',
    ] as $sql) {
        $pdo->exec($sql);
    }
    $auth = (new ReflectionClass(Kaleta\Core\Auth::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Auth::class, 'user'))->setValue($auth, $actor > 0 ? ['idu' => $actor] : null);
    $app = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\App::class, 'db'))->setValue($app, $db);
    (new ReflectionProperty(Kaleta\Core\App::class, 'auth'))->setValue($app, $auth);

    return $app;
};
$d777Stamps = fn (Kaleta\Core\App $app): array => [
    $app->db()->pairs('SELECT idp, COALESCE(assigned_by, 0) FROM {poptavky} ORDER BY idp'),
    $app->db()->pairs('SELECT id, COALESCE(assigned_by, 0) FROM {tasks} ORDER BY id'),
    $app->db()->pairs('SELECT id, COALESCE(assigned_by, 0) FROM {deals} ORDER BY id')];

$d777Admin = $d777App(9);
$d777Moved = D777Handover::handOver($d777Admin->db(), 5, 7, 9);
$d777Nobody = $d777App(0);
D777Handover::handOver($d777Nobody->db(), 5, null);
check('3.51 D1: the hand-over of a leaver\'s work stamps the administrator on the open enquiries, the open tasks and the deals that move (none given: NULL) – a resolved enquiry, a done task and another person\'s work keep their stamp', [
    $d777Moved['enquiry'] . '|' . $d777Moved['task'] . '|' . $d777Moved['deal'], $d777Stamps($d777Admin), $d777Stamps($d777Nobody)],
    ['1|1|1', [[1 => 9, 2 => 2, 3 => 2, 4 => 2], [1 => 9, 2 => 2], [1 => 9, 2 => 2]], [[1 => 0, 2 => 2, 3 => 2, 4 => 2], [1 => 0, 2 => 2], [1 => 0, 2 => 2]]]);

$d777One = $d777App(4);
$d777First = D777Handover::assign($d777One, 'enquiry', 3, null);   // someone gives enquiry 3 to nobody
$d777Same = D777Handover::assign($d777One, 'enquiry', 4, null);  // the person it already has (nobody): nothing changes, the stamp stays 2
$d777Page = D777Handover::assign($d777One, 'page', 1, null);     // a page has no assigner column – it moves without one
check('3.51 D1: Handover::assign (MCP assign, update_enquiry) stamps the signed-in person on an enquiry only when the person changes; a page moves without a stamp', [
    $d777First['changed'], $d777One->db()->value('SELECT assigned_by FROM {poptavky} WHERE idp = 3'),
    $d777Same['changed'], $d777One->db()->value('SELECT assigned_by FROM {poptavky} WHERE idp = 4'), $d777One->db()->value('SELECT COALESCE(owner_id, 0) FROM {stranky} WHERE ids = 1'), $d777Page['changed']],
    [true, 4, false, 2, 0, true]);
