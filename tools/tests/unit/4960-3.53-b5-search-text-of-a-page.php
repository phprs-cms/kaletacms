<?php

declare(strict_types=1);

use Kaleta\Builder\Build;
use Kaleta\Core\BuildText;
use Kaleta\Core\SearchIndex;
use Kaleta\Core\SearchText;

/* ---------- 3.53 B5: the search text of a builder page (Core\SearchText) – a decision for every element type, components ---------- */
// Behaviour, never a pin of source text: the decisions against Build::ELEMENTS, the text of a sample page, components read
// from an in-memory SQLite database, the pages emptied when a component changes. The MySQL path (publish, the job, the
// site search) is smoke 4960.

$b5Types = array_map(fn (string $c): string => $c::TYPE, Build::ELEMENTS);
$b5Decided = array_keys(SearchText::DECISIONS);
sort($b5Types);
sort($b5Decided);
// a type whose default content Core\BuildText writes is page content, so it may never be decided "no text" or "children only"
$b5Lost = array_values(array_filter(Build::ELEMENTS, fn (string $c): bool => BuildText::of(['deti' => [Build::fresh($c::TYPE)]]) !== ''
    && !in_array(SearchText::DECISIONS[$c::TYPE] ?? '', ['content', 'more'], true)));
// and the index never has less than the content: for every type, the text of its default element holds what BuildText writes
$b5Less = array_values(array_filter(Build::ELEMENTS, fn (string $c): bool => !str_contains(SearchText::of(['deti' => [$e = Build::fresh($c::TYPE)]]), BuildText::of(['deti' => [$e]]))));
check('3.53 B5: every element type in Build::ELEMENTS has a decision, and no other; the decisions are content / more / children / none; containers are "children"; the index never has less than the page content',
    [$b5Decided === $b5Types, array_diff(SearchText::DECISIONS, ['children', 'content', 'more', 'none']) === [],
        array_values(array_filter(Build::ELEMENTS, fn (string $c): bool => $c::CONTAINER && !in_array(SearchText::DECISIONS[$c::TYPE], ['children', 'more', 'none'], true))),
        $b5Lost, $b5Less, SearchText::MAX_NESTING === Kaleta\Builder\Components::MAX_NESTING],
    [true, true, [], [], [], true]);

$b5El = fn (string $type, array $content = [], array $children = []): array => Build::fresh($type, $content, $children);
$b5Build = ['v' => 1, 'deti' => [$b5El('sekce', [], [
    $b5El('nadpis', ['text' => 'Naše práce']),
    $b5El('obrazek', ['src' => '/media/a.jpg', 'alt' => 'Terasa z modřínu', 'popisek' => 'Hotová terasa']),
    $b5El('galerie', ['fotky' => [['src' => '/media/b.jpg', 'alt' => 'Pergola u domu'], ['src' => '/media/c.jpg', 'alt' => '']], 'popisek' => 'Realizace 2026']),
    $b5El('karusel', ['popis' => 'Reference zákazníků'], [$b5El('kontejner', [], [$b5El('text', ['html' => '<p>Skvělá spolupráce</p>'])])]),
    $b5El('hodnoceni', ['hodnota' => '4,9', 'text' => 'z 5 · 120 recenzí']),
    $b5El('pred_po', ['popisek_pred' => 'Před', 'alt_pred' => 'Starý plot', 'popisek_po' => 'Po', 'alt_po' => 'Nový plot']),
    $b5El('prubeh', ['polozky' => [['nazev' => 'Spokojení zákazníci', 'hodnota' => 98]]]),
    $b5El('ikona', ['popis' => 'Záruka kvality']),
    $b5El('vlozeni', ['adresa' => 'https://calendly.com/x', 'titulek' => 'Objednat konzultaci']),
    $b5El('do_poptavky', ['text' => 'Přidat do poptávky']),
    $b5El('html', ['kod' => '<table><tr><td>Montáž</td><td>Servis</td></tr></table><script>var tajne = 1;</script><style>.x{color:red}</style>']),
    // no text: a form, a map, a countdown; the inside of a Collection list is a pattern
    $b5El('formular', []), $b5El('mapa', ['adresa' => 'Údolní 5, Brno']), $b5El('odpocet', ['konec' => 'Akce skončila']),
    $b5El('kolekce', [], [$b5El('nadpis', ['text' => '{{nazev}} vzor'])]),
])]];
$b5Row = ['titulek' => 'Reference', 'seo_titulek' => '', 'popis' => '', 'text' => '', 'stavba' => json_encode($b5Build, JSON_UNESCAPED_UNICODE)];
check('3.53 B5: the index of a page has what a visitor reads – custom HTML (no script or style, cells apart), image and gallery descriptions, carousel name and slides, rating, before/after, progress, icon, embed and enquiry button labels; a form, map, countdown and a collection pattern add nothing; the page content (Build::asText) does not change',
    [SearchIndex::text('stranky', $b5Row), Build::asText($b5Build) === BuildText::of($b5Build), str_contains(Build::asText($b5Build), 'Pergola'), str_contains(Build::asText($b5Build), 'Montáž')],
    ['reference nase prace hotova terasa terasa z modrinu pergola u domu realizace 2026 reference zakazniku skvela spoluprace 4 9 z 5 120 recenzi pred stary plot po novy plot spokojeni zakaznici 98 zaruka kvality objednat konzultaci pridat do poptavky montaz servis',
        true, false, false]);

// components: a SQLite database with the components table (the index reads idm, stavba, vlastnosti)
$b5Db = function (bool $withColumn = true): Kaleta\Core\Db {
    $db = new Kaleta\Core\Db('sqlite::memory:', '', '');
    $pdo = new class ('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]) extends PDO {
        public bool $withColumn = true;

        public function prepare(string $query, array $options = []): PDOStatement|false
        {
            if (!$this->withColumn && str_contains($query, 'hledani')) { // a database before migration 0141, as MySQL answers
                $e = new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'hledani' in 'field list'");
                $e->errorInfo = ['42S22', 1054, "Unknown column 'hledani' in 'field list'"];
                throw $e;
            }

            return parent::prepare($query, $options);
        }
    };
    $pdo->withColumn = $withColumn;
    (new ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
    $pdo->exec('CREATE TABLE ka_komponenty (idm INTEGER PRIMARY KEY, nazev TEXT, vlastnosti TEXT, stavba TEXT NULL, stavba_koncept TEXT NULL)');
    $pdo->exec('CREATE TABLE ka_stranky (ids INTEGER PRIMARY KEY, titulek TEXT, text TEXT, stavba TEXT NULL' . ($withColumn ? ', hledani TEXT NULL' : '') . ')');

    return $db;
};
$b5Use = fn (int $id, array $values = []): array => $b5El('komponenta', ['komponenta' => (string) $id, 'hodnoty' => $values]);
$b5Json = fn (array ...$children): string => Build::toJson(['v' => 1, 'deti' => $children]);
$b5 = $b5Db();
$b5Properties = json_encode([['klic' => 'nadpis', 'popisek' => 'Nadpis', 'typ' => 'text', 'vychozi' => 'Výchozí titulek'], ['klic' => 'obsah', 'popisek' => 'Obsah', 'typ' => 'html', 'vychozi' => ''],
    ['klic' => 'foto', 'popisek' => 'Foto', 'typ' => 'obrazek', 'vychozi' => '/media/skryta-cesta.jpg']], JSON_UNESCAPED_UNICODE);
$b5->insert('komponenty', ['idm' => 1, 'nazev' => 'Karta', 'vlastnosti' => $b5Properties,
    'stavba' => $b5Json($b5El('nadpis', ['text' => '{{nadpis}}']), $b5El('text', ['html' => '<p>Pevný text karty</p>{{obsah}}']), $b5El('obrazek', ['src' => '{{foto}}', 'alt' => '{{neznamy}}']))]);
$b5->insert('komponenty', ['idm' => 2, 'nazev' => 'Obal', 'vlastnosti' => '[]', 'stavba' => $b5Json($b5El('text', ['html' => '<p>Obal kolem</p>']), $b5Use(1, ['nadpis' => 'Vnořená karta']))]);
$b5->insert('komponenty', ['idm' => 3, 'nazev' => 'Smyčka', 'vlastnosti' => '[]', 'stavba' => $b5Json($b5El('text', ['html' => '<p>Smyčka jednou</p>']), $b5Use(3))]);
$b5->insert('komponenty', ['idm' => 4, 'nazev' => 'Koncept', 'vlastnosti' => '[]', 'stavba' => null, 'stavba_koncept' => $b5Json($b5El('text', ['html' => '<p>Neveřejný koncept</p>']))]);
$b5->insert('komponenty', ['idm' => 12, 'nazev' => 'Jiná', 'vlastnosti' => '[]', 'stavba' => $b5Json($b5El('text', ['html' => '<p>Dvanáctka</p>']))]);
SearchIndex::componentChanged($b5, 0); // forgets the components another test may have read
$b5Page = ['titulek' => 'Služby', 'stavba' => $b5Json($b5Use(1, ['nadpis' => 'Údržba zahrad', 'obsah' => '<p>Sekání <b>trávy</b></p>']), $b5Use(1), $b5Use(2), $b5Use(3), $b5Use(4), $b5Use(99))];
check('3.53 B5: a component on a page is indexed with the values of that use (the default where the use gives none; an image value, an unknown tag nothing), also inside another component; itself inside itself once; a component never published or gone adds nothing; without a database no component text',
    [SearchIndex::text('stranky', $b5Page, null, $b5), SearchIndex::text('stranky', $b5Page)],
    ['sluzby udrzba zahrad pevny text karty sekani travy vychozi titulek pevny text karty obal kolem vnorena karta pevny text karty smycka jednou', 'sluzby']);

// a component changes: the pages whose published build uses it (also through another component) are emptied, never 12 for 1
$b5->insert('stranky', ['ids' => 1, 'titulek' => 'Přímo', 'stavba' => $b5Json($b5Use(1))]);
$b5->insert('stranky', ['ids' => 2, 'titulek' => 'Dvanáct', 'stavba' => $b5Json($b5Use(12))]);
$b5->insert('stranky', ['ids' => 3, 'titulek' => 'Přes obal', 'stavba' => $b5Json($b5Use(2))]);
$b5->insert('stranky', ['ids' => 4, 'titulek' => 'Bez', 'stavba' => $b5Json($b5El('text', ['html' => '<p>komponenta 1</p>']))]);
$b5->insert('stranky', ['ids' => 5, 'titulek' => 'Text', 'stavba' => null, 'text' => '"komponenta":"1"']);
$b5->run("UPDATE {stranky} SET hledani = 'x'"); // Db::insert never sets the index (3.53 A4)
$b5Changed = SearchIndex::componentChanged($b5, 1);
$b5Old = $b5Db(false);
check('3.53 B5: componentChanged empties the pages using the component directly or inside another (2), keeps a page with component 12, a page without it and a text page; on a database before 0141 it does nothing',
    [$b5Changed, array_column($b5->all('SELECT ids FROM {stranky} WHERE hledani IS NULL ORDER BY ids'), 'ids'), SearchIndex::componentChanged($b5, 12), SearchIndex::componentChanged($b5Old, 1)],
    [2, [1, 3], 1, 0]);
