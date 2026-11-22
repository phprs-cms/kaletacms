<?php

declare(strict_types=1);

/* ---------- 3.51 D2: the hourly application form watch covers the item templates of collections (Front\Forms' rule) ---------- */
use Kaleta\Builder\ApplicationFormWatch as D781Watch;
use Kaleta\Builder\Build as D781Build;

$d781Form = fn (array $over = []): array => $over + ['id' => 'f781', 'typ' => 'formular', 'obsah' => ['ucel' => '', 'pole' => []]];
$d781Build = fn (array ...$children): string => (string) json_encode(['v' => 1, 'deti' => [['id' => 's781', 'typ' => 'sekce', 'deti' => $children]]]);
$d781Purpose = $d781Form(['obsah' => ['ucel' => Kaleta\Core\Applications::PURPOSE, 'pole' => []]]);
$d781Slot = ['id' => 'k781', 'typ' => 'komponenta', 'obsah' => ['komponenta' => 7]];

check('3.51 D2: applicationFormPresence with $everyForm – a plain Form holds and shows; hidden by its style it holds but does not show; a component holding any Form counts; without $everyForm a plain Form is not watched', [
    D781Build::applicationFormPresence($d781Build($d781Form()), [], [], [], true),
    D781Build::applicationFormPresence($d781Build($d781Form(['styl' => ['zaklad' => ['zobrazeni' => 'none']]])), [], [], [], true),
    D781Build::applicationFormPresence($d781Build($d781Slot), [], [7], [7], true),
    D781Build::applicationFormPresence($d781Build($d781Slot), [], [7], [], true),
    D781Build::applicationFormPresence($d781Build($d781Form()), [], [], []),
    D781Build::applicationFormPresence($d781Build($d781Purpose), [], [], []),
    D781Build::applicationFormPresence($d781Build(['id' => 't781', 'typ' => 'text', 'obsah' => ['text' => 'x']]), [], [], [], true)],
    [[true, true], [true, false], [true, true], [true, false], [false, false], [true, true], [false, false]]);

// $everyForm = false gives the result of 3.50.1 on the fixtures of unit 4697 (the shared detection of the locks is untouched)
$d781Old = (string) json_encode(['v' => 1, 'deti' => [['id' => 's697', 'typ' => 'sekce', 'deti' => [['id' => 'f697', 'typ' => 'formular', 'obsah' => ['ucel' => Kaleta\Core\Applications::PURPOSE, 'pole' => []],
    'tridy' => ['card697']]]]]]);
check('3.51 D2: without $everyForm the presence of an application form, its classes and its components are what they were', [
    D781Build::applicationFormPresence($d781Old, ['card697'], [], []), D781Build::applicationFormPresence($d781Old, [], [], []),
    D781Build::applicationFormPresence($d781Old, [], [], [], false) === D781Build::applicationFormPresence($d781Old, [], [], []),
    D781Build::showsApplicationForm($d781Build($d781Form()), [], []), D781Build::showsApplicationForm($d781Build($d781Purpose), [], [])],
    [[true, false], [true, true], true, false, true]);

check('3.51 D2: collectionKey – the collection and the language hashed into the 40 characters of a notification target, different per language; the codec keeps it', [
    D781Watch::collectionKey(4, ''), strlen(D781Watch::collectionKey(4, str_repeat('x', 35))) <= 40, D781Watch::collectionKey(4, 'en') !== D781Watch::collectionKey(4, ''),
    D781Watch::collectionKey(4, '') !== D781Watch::collectionKey(5, ''),
    D781Watch::state(D781Watch::encode(['reachable' => ['form:page:3', D781Watch::collectionKey(4, 'en')], 'alerted' => [D781Watch::collectionKey(5, '') => 'Jobs – item page']]))],
    ['form:coll:' . substr(sha1('4:'), 0, 16), true, true, true,
        ['reachable' => ['form:page:3', D781Watch::collectionKey(4, 'en')], 'alerted' => [D781Watch::collectionKey(5, '') => 'Jobs – item page']]]);
$d781Many = array_map(fn (int $i): string => D781Watch::collectionKey($i, ''), range(1, 3000));
$d781Cut = D781Watch::encode(['reachable' => [...array_map(fn (int $i): string => 'form:page:' . $i, range(1, 3000)), ...$d781Many], 'alerted' => []]);
check('3.51 D2: the stored state stays under MAX_STATE – the pages are cut first, then the item templates; what stays decodes', [
    strlen($d781Cut) <= D781Watch::MAX_STATE, count(D781Watch::state($d781Cut)['reachable']) > 0,
    strlen(D781Watch::encode(['reachable' => $d781Many, 'alerted' => []])) <= D781Watch::MAX_STATE],
    [true, true, true]);

// scan() over an in-memory database: the collections and their language templates, the components, no pages
$d781Db = function (array $rows): Kaleta\Core\Db {
    $db = new Kaleta\Core\Db('sqlite::memory:', '', '');
    $pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    (new ReflectionProperty($db, 'pdo'))->setValue($db, $pdo);
    foreach ([
        'CREATE TABLE ka_tridy (nazev TEXT, styl TEXT, css TEXT)',
        'CREATE TABLE ka_komponenty (idm INTEGER PRIMARY KEY, stavba TEXT NULL)',
        'CREATE TABLE ka_stranky (ids INTEGER PRIMARY KEY, titulek TEXT, stavba TEXT NULL, zobrazit INTEGER, heslo_hash TEXT NULL, valid_until TEXT NULL, smazano TEXT NULL)',
        'CREATE TABLE ka_popupy (idpp INTEGER PRIMARY KEY, nazev TEXT, stavba TEXT NULL, aktivni INTEGER, spoustec TEXT, hodnota INTEGER, pravidla TEXT, valid_until TEXT NULL)',
        'CREATE TABLE ka_casti (typ TEXT, jazyk TEXT, varianta TEXT, nazev TEXT, stranky TEXT, pravidla TEXT, stavba TEXT NULL)',
        "CREATE TABLE ka_kolekce (idk INTEGER PRIMARY KEY, nazev TEXT, seo_link TEXT, detail INTEGER DEFAULT 0, preset TEXT DEFAULT '', stavba TEXT NULL)",
        'CREATE TABLE ka_kolekce_sablony (idk INTEGER, jazyk TEXT, stavba TEXT NULL)',
        'CREATE TABLE ka_collection_categories (id INTEGER PRIMARY KEY, idk INTEGER, visible INTEGER)',
    ] as $sql) {
        $pdo->exec($sql);
    }
    foreach ($rows as [$sql, $params]) {
        $pdo->prepare($sql)->execute($params);
    }

    return $db;
};
$d781Coll = 'INSERT INTO ka_kolekce (idk, nazev, seo_link, detail, preset, stavba) VALUES (?, ?, ?, ?, ?, ?)';
$d781Scan = $d781Db([
    [$d781Coll, [1, 'Jobs', 'jobs', 1, 'jobs', $d781Build($d781Form())]],                                      // jobs: a plain form is watched
    [$d781Coll, [2, 'Openings', 'openings', 1, 'jobs', $d781Build($d781Slot)]],                                // jobs: a form inside a component
    [$d781Coll, [3, 'Services', 'services', 1, '', $d781Build($d781Form())]],                                  // another collection, no Purpose: not watched
    [$d781Coll, [4, 'Careers', 'careers', 1, '', $d781Build($d781Purpose)]],                                    // another collection with the Purpose
    [$d781Coll, [5, 'Roles', 'roles', 0, 'jobs', $d781Build($d781Form())]],                                    // item pages off: not reachable
    [$d781Coll, [6, 'Hidden', 'hidden', 1, 'jobs', $d781Build($d781Form(['podminky' => [['typ' => 'jazyk', 'hodnota' => 'en']]]))]], // a hidden form
    ['INSERT INTO ka_kolekce_sablony (idk, jazyk, stavba) VALUES (?, ?, ?)', [1, 'en', $d781Build($d781Form(['styl' => ['mobil' => ['zobrazeni' => 'none']]]))]],
    ['INSERT INTO ka_kolekce_sablony (idk, jazyk, stavba) VALUES (?, ?, ?)', [4, 'de', $d781Build($d781Purpose)]],
    ['INSERT INTO ka_komponenty (idm, stavba) VALUES (?, ?)', [7, $d781Build($d781Form())]],
]);
$d781Found = D781Watch::scan($d781Scan, '2026-11-22');
ksort($d781Found);
$d781Expected = [
    D781Watch::collectionKey(1, '') => ['reachable' => true, 'title' => 'Jobs – item page'],
    D781Watch::collectionKey(1, 'en') => ['reachable' => false, 'title' => 'Jobs – item page (en)'],
    D781Watch::collectionKey(2, '') => ['reachable' => true, 'title' => 'Openings – item page'],
    D781Watch::collectionKey(4, '') => ['reachable' => true, 'title' => 'Careers – item page'],
    D781Watch::collectionKey(4, 'de') => ['reachable' => true, 'title' => 'Careers – item page (de)'],
    D781Watch::collectionKey(5, '') => ['reachable' => false, 'title' => 'Roles – item page'],
    D781Watch::collectionKey(6, '') => ['reachable' => false, 'title' => 'Hidden – item page'],
];
ksort($d781Expected);
check('3.51 D2: scan – jobs templates with a plain form or one in a component, another collection only with the Purpose, language templates on their own; item pages off or a hidden form is not reachable, and how many items are visible plays no part',
    Kaleta\Core\Language::runWith('en', function () use ($d781Scan): array {
        $found = D781Watch::scan($d781Scan, '2026-11-22');
        ksort($found);

        return $found;
    }, 'admin-'), $d781Expected);
check('3.51 D2: the first run after the update takes the item templates as they are and rings nothing; item pages switched off later ring once; on again resolves', (function () use ($d781Found): array {
    $first = D781Watch::changes(D781Watch::state('{"pages":[],"popups":[],"parts":[],"alerted":{}}'), $d781Found);
    $key = D781Watch::collectionKey(1, '');
    $off = D781Watch::changes($first['state'], [$key => ['reachable' => false, 'title' => 'Jobs – item page']] + $d781Found);
    $on = D781Watch::changes($off['state'], $d781Found);

    return [$first['alert'], $off['alert'], $on['resolve']];
})(), [[], [D781Watch::collectionKey(1, '') => 'Jobs – item page'], [D781Watch::collectionKey(1, '')]]);
check('3.51 D2: the title of a key the scan no longer returns – the collection\'s own name, or "deleted collection"', Kaleta\Core\Language::runWith('en', fn (): array => [
    (new ReflectionMethod(D781Watch::class, 'titleOf'))->invoke(null, $d781Scan, D781Watch::collectionKey(3, '')),
    (new ReflectionMethod(D781Watch::class, 'titleOf'))->invoke(null, $d781Scan, D781Watch::collectionKey(4, 'de')),
    (new ReflectionMethod(D781Watch::class, 'titleOf'))->invoke(null, $d781Scan, D781Watch::collectionKey(99, ''))], 'admin-'),
    ['Services – item page', 'Careers – item page (de)', 'deleted collection']);
