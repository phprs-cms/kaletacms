<?php

declare(strict_types=1);

/* ---------- 3.51 R0: migration 0139 – the subscribers' language from the sign-up page or the customer, the assigners of existing tasks ---------- */

// the migration file itself on an in-memory SQLite database with the tables it reads (it needs only Db and Settings)
$r790Db = new Kaleta\Core\Db('sqlite::memory:', '', '');
$r790Pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
(new ReflectionProperty($r790Db, 'pdo'))->setValue($r790Db, $r790Pdo);
$r790Pdo->exec('CREATE TABLE ka_nastaveni (promenna TEXT PRIMARY KEY, hodnota TEXT)');
$r790Pdo->exec("CREATE TABLE ka_odberatele (ido INTEGER PRIMARY KEY, zdroj TEXT NOT NULL DEFAULT '', language TEXT NOT NULL DEFAULT '', contact_id INTEGER NULL)");
$r790Pdo->exec("CREATE TABLE ka_contacts (id INTEGER PRIMARY KEY, language TEXT NOT NULL DEFAULT '')");
$r790Pdo->exec('CREATE TABLE ka_tasks (id INTEGER PRIMARY KEY, assignee_id INTEGER NULL, created_by INTEGER NULL, assigned_by INTEGER NULL)');
$r790Pdo->exec('CREATE TABLE ka_testimonial_requests (id INTEGER PRIMARY KEY, created_at TEXT NOT NULL, sent_at TEXT NULL)'); // step 3 (review): unit 4720
// a Czech site in a sub-folder with English and German versions
$r790Pdo->exec("INSERT INTO ka_nastaveni VALUES ('site_language', 'cs'), ('additional_languages', 'en,de'), ('site_url', 'https://example.com/web')");
$r790Pdo->exec("INSERT INTO ka_contacts VALUES (7, 'de'), (8, 'fr'), (9, 'en')");
$r790Pdo->exec("INSERT INTO ka_odberatele (ido, zdroj, language, contact_id) VALUES
    (1, '/web/en/novinky', '', NULL),        -- the English version's page
    (2, 'https://example.com/web/de/', '', NULL), -- a full address of the German home
    (3, '/web/kontakt', '', 7),               -- a Czech page, the customer record says German
    (4, '/web/sk/clanek', '', NULL),          -- a page whose address only looks like a code (no Slovak version)
    (5, '/web/en', 'de', 9),                  -- already has a language: kept
    (6, '/web/cs/x', '', NULL),               -- the default language's code: the default
    (7, '/web/kontakt', '', 8),               -- a customer language the site has no version of
    (8, '/en/novinky', '', NULL),             -- an address from before the site moved into the sub-folder
    (9, '/web/EN/news', '', NULL)             -- the code in capitals
");
$r790Pdo->exec('INSERT INTO ka_tasks VALUES (1, 5, 2, NULL), (2, NULL, 2, NULL), (3, 5, 3, 9), (4, 4, 4, NULL), (5, 6, NULL, NULL)');
$r790Run = require KALETA_SYSTEM . '/sql/migrace/0139-subscriber-language-and-task-assigners.php';
$r790Settings = new Kaleta\Core\Settings($r790Db);
$r790State = fn (): array => [
    array_column($r790Pdo->query('SELECT ido, language FROM ka_odberatele ORDER BY ido')->fetchAll(), 'language', 'ido'),
    array_map(fn ($v) => $v === null ? null : (int) $v, array_column($r790Pdo->query('SELECT id, assigned_by FROM ka_tasks ORDER BY id')->fetchAll(), 'assigned_by', 'id')),
];
$r790Run($r790Db, $r790Settings);
$r790First = $r790State();
$r790Run($r790Db, $r790Settings);
check('3.51 R0.2: 0139 takes the language from the sign-up page under the base path, else from the customer record when the site has that version; a code-like slug, the default language and a foreign customer language stay the default', $r790First, [
    [1 => 'en', 2 => 'de', 3 => 'de', 4 => '', 5 => 'de', 6 => '', 7 => '', 8 => 'en', 9 => 'en'],
    [1 => 2, 2 => null, 3 => 9, 4 => 4, 5 => null],
]);
check('3.51 R0.2: 0139 run again gives the same result', $r790State(), $r790First);

// a site without additional languages changes no subscriber; the tasks are still stamped
$r790Pdo->exec("UPDATE ka_nastaveni SET hodnota = '' WHERE promenna = 'additional_languages'");
$r790Pdo->exec("UPDATE ka_odberatele SET language = '' WHERE ido = 1");
$r790Pdo->exec('UPDATE ka_tasks SET assigned_by = NULL WHERE id = 1');
$r790Run($r790Db, $r790Settings);
check('3.51 R0.2: on a site with one language 0139 leaves the subscribers alone and still stamps a task with its creator', [$r790State()[0][1], $r790State()[1][1]], ['', 2]);

check('3.51 R0.2: 0139 is the highest migration, a data migration listed in Migration::DATA, and the database version', [
    max(array_keys(Kaleta\Core\Migration::files())), in_array('0139-subscriber-language-and-task-assigners', Kaleta\Core\Migration::DATA, true), KALETA_DB_VERSION], [139, true, 139]);
