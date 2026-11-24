<?php

declare(strict_types=1);

/* ---------- 3.53 A4: the search text cannot be forgotten – one rule in Db, a database before migration 0141 still saves ---------- */
// Behaviour on an in-memory SQLite database, never a pin of source text: the rule of Db::update/insert, the retry without the
// column on an old schema, the item fields that empty the index, the journal comparison. The MySQL parts (the agent journal,
// undo, the export, MCP) are smoke 4930.

$u4930Db = function (bool $withColumn): Kaleta\Core\Db {
    $db = new Kaleta\Core\Db('sqlite::memory:', '', '');
    // a database before migration 0141 answers a statement naming hledani as MySQL does: 42S22 / 1054 "Unknown column"
    $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
        public bool $withColumn = true;
        /** @var list<string> */
        public array $prepared = [];

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            $this->prepared[] = $query;
            if (!$this->withColumn && str_contains($query, 'hledani')) {
                $e = new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'hledani' in 'field list'");
                $e->errorInfo = ['42S22', 1054, "Unknown column 'hledani' in 'field list'"];
                throw $e;
            }

            return parent::prepare($query, $options);
        }
    };
    $pdo->withColumn = $withColumn;
    (new ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
    $search = $withColumn ? ', hledani TEXT NULL' : '';
    $pdo->exec("CREATE TABLE ka_stranky (ids INTEGER PRIMARY KEY, titulek TEXT, text TEXT, stavba TEXT NULL, zobrazit INTEGER DEFAULT 1, seo_link TEXT DEFAULT ''{$search})");
    $pdo->exec("CREATE TABLE ka_kolekce_polozky (idp INTEGER PRIMARY KEY, idk INTEGER, nazev TEXT, popis TEXT DEFAULT '', data TEXT DEFAULT '{}', zobrazit INTEGER DEFAULT 1{$search})");
    $pdo->exec('CREATE TABLE ka_novinky (idc INTEGER PRIMARY KEY, titulek TEXT, hledani TEXT NULL)');

    return $db;
};

$u4930 = $u4930Db(true);
$u4930->insert('stranky', ['titulek' => 'Pergoly', 'text' => '<p>Pergola</p>', 'hledani' => 'cizi text']);
$u4930->run("UPDATE {stranky} SET hledani = 'pergoly pergola'");
$u4930->update('stranky', ['zobrazit' => 0], ['ids' => 1]);
$u4930Hidden = $u4930->value('SELECT hledani FROM {stranky} WHERE ids = 1');
$u4930->update('stranky', ['titulek' => 'Altány'], ['ids' => 1]);
$u4930Edited = $u4930->value('SELECT hledani FROM {stranky} WHERE ids = 1');
$u4930->run("UPDATE {stranky} SET hledani = 'altany pergola'");
$u4930->update('pages', ['titulek' => 'Altány', 'text' => '<p>Kopie</p>', 'hledani' => 'starý index jiné stránky'], ['ids' => 1]); // a full row copied over, by the English name
$u4930Copied = $u4930->value('SELECT hledani FROM {stranky} WHERE ids = 1');
$u4930->insert('kolekce_polozky', ['idk' => 1, 'nazev' => 'Stan', 'hledani' => 'cizi']);
$u4930->run("UPDATE {kolekce_polozky} SET hledani = 'stan'");
$u4930->update('collection_items', ['zobrazit' => 0], ['idp' => 1]);
$u4930ItemHidden = $u4930->value('SELECT hledani FROM {kolekce_polozky} WHERE idp = 1');
$u4930->update('kolekce_polozky', ['data' => '{"podnadpis":"Lehký"}'], ['idp' => 1]);
$u4930->insert('novinky', ['titulek' => 'Zpráva', 'hledani' => 'zprava']);
$u4930->update('novinky', ['hledani' => 'zprava nova'], ['idc' => 1]);
check('3.53 A4: Db – an insert of a page or item never sets the search text (a copied row carries none); an update of a content column empties it, also over a passed value; a visibility change keeps it; news are Search::index\'s own',
    [$u4930Hidden, $u4930Edited, $u4930Copied, $u4930ItemHidden,
        $u4930->value('SELECT hledani FROM {kolekce_polozky} WHERE idp = 1'), $u4930->value('SELECT hledani FROM {novinky} WHERE idc = 1'),
        $u4930->update('stranky', ['hledani' => 'jen index'], ['ids' => 1]), $u4930->value("SELECT hledani FROM {stranky} WHERE ids = 1")],
    ['pergoly pergola', null, null, 'stan', null, 'zprava nova', 0, null]);

$u4930Fresh = $u4930Db(true);
$u4930Fresh->insert('stranky', ['titulek' => 'Nová', 'text' => '', 'hledani' => 'podvržený']);
check('3.53 A4: Db::insert – the search text of a new page starts NULL (the job or SearchIndex::page makes it)',
    $u4930Fresh->value('SELECT hledani FROM {stranky} WHERE ids = 1'), null);

// new files on a database before migration 0141: the page is saved, the index is silence (no log line, no exception)
$u4930Old = $u4930Db(false);
$u4930Old->insert('stranky', ['titulek' => 'Stará', 'text' => '<p>A</p>']);
$u4930Old->update('stranky', ['titulek' => 'Stará 2'], ['ids' => 1]);
$u4930Old->update('stranky', ['text' => '<p>B</p>'], ['ids' => 1]);
$u4930LogBefore = is_file(KALETA_ROOT . '/storage/log/chyby.log') ? (string) file_get_contents(KALETA_ROOT . '/storage/log/chyby.log') : '';
Kaleta\Core\SearchIndex::page($u4930Old, 1);
Kaleta\Core\SearchIndex::item($u4930Old, 1);
$u4930LogAfter = is_file(KALETA_ROOT . '/storage/log/chyby.log') ? (string) file_get_contents(KALETA_ROOT . '/storage/log/chyby.log') : '';
$u4930Settings = new Kaleta\Core\Settings($u4930Old);
(new ReflectionProperty($u4930Settings, 'values'))->setValue($u4930Settings, ['db_version' => '140', Kaleta\Core\SearchIndex::STATE => '']);
$u4930Pending = Kaleta\Core\SearchIndex::pending($u4930Old, $u4930Settings);
check('3.53 A4: a database before migration 0141 (db_version 140, no column) – pages save, the search text is asked for once per connection, SearchIndex::page/item/pending are silent',
    [$u4930Old->value('SELECT titulek || text FROM {stranky} WHERE ids = 1'), count(array_filter((new ReflectionProperty($u4930Old, 'pdo'))->getValue($u4930Old)->prepared, fn (string $q): bool => str_starts_with($q, 'UPDATE') && str_contains($q, 'hledani'))),
        $u4930LogAfter === $u4930LogBefore, $u4930Pending, Kaleta\Core\SearchIndex::ready($u4930Settings)],
    ['Stará 2<p>B</p>', 1, true, ['pages' => 0, 'items' => 0, 'news' => 0], false]);

// a change of the fields whose values are searched empties the items' search text; a new label or an image field does not
$u4930Fields = [['klic' => 'podnadpis', 'popisek' => 'Podnadpis', 'typ' => 'text'], ['klic' => 'foto', 'popisek' => 'Foto', 'typ' => 'obrazek']];
$u4930C = $u4930Db(true);
$u4930C->insert('kolekce_polozky', ['idk' => 7, 'nazev' => 'A']);
$u4930C->insert('kolekce_polozky', ['idk' => 8, 'nazev' => 'B']);
$u4930C->run("UPDATE {kolekce_polozky} SET hledani = 'x'");
$u4930State = fn (): string => implode(',', array_map(fn (mixed $v): string => $v === null ? 'NULL' : (string) $v, array_column($u4930C->all('SELECT hledani FROM {kolekce_polozky} ORDER BY idp'), 'hledani')));
Kaleta\Builder\Collections::searchFieldsChanged($u4930C, 7, $u4930Fields, [['klic' => 'podnadpis', 'popisek' => 'Krátký popis', 'typ' => 'text'], ['klic' => 'foto2', 'popisek' => 'Foto', 'typ' => 'obrazek']]);
$u4930Label = $u4930State();
Kaleta\Builder\Collections::searchFieldsChanged($u4930C, 7, $u4930Fields, [['klic' => 'podnadpis', 'popisek' => 'Podnadpis', 'typ' => 'cislo'], ['klic' => 'foto', 'popisek' => 'Foto', 'typ' => 'obrazek']]);
check('3.53 A4: Collections::searchFieldsChanged – a new label or a non-searched field keeps the items\' search text; a text field turned into a number empties it in that collection only',
    [$u4930Label, $u4930State()], ['x,x', 'NULL,x']);

check('3.53 A4: AgentJournal::same() compares rows without the search text – the job filled it after the session, a journal of 3.52 still carries it',
    [Kaleta\Core\AgentJournal::same(['ids' => 1, 'titulek' => 'A', 'hledani' => 'a'], ['ids' => '1', 'titulek' => 'A']),
        Kaleta\Core\AgentJournal::same(['ids' => 1, 'titulek' => 'A'], ['ids' => 1, 'titulek' => 'A', 'hledani' => null]),
        Kaleta\Core\AgentJournal::same(['ids' => 1, 'titulek' => 'A', 'hledani' => 'a'], ['ids' => 1, 'titulek' => 'B', 'hledani' => 'a'])],
    [true, true, false]);

check('3.53 A4: the site export leaves the derived search text of pages out (news already were)', Kaleta\Core\SiteExport::EXCLUDED_PAGE_COLUMNS, ['hledani']);
