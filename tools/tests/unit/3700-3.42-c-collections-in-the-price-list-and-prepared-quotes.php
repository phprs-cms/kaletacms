<?php

declare(strict_types=1);

use Kaleta\Core\Prices;
use Kaleta\Core\Quotes;

/* ---------- 3.42 C: any collection feeds the internal price list; enquiries about its items lead to a prepared quote ---------- */

// the two settings: the mapping (empty) and the automatic draft (off), neither over MCP
check('3.42 C: the mapping is empty and the automatic draft off by default; neither can be changed over MCP', [
    Kaleta\Core\Settings::DEFAULTS[Prices::MAPPING_SETTING] ?? null, Kaleta\Core\Settings::DEFAULTS[Quotes::AUTO_DRAFT_SETTING] ?? null,
    array_map(fn (string $k): int => (int) Kaleta\Core\Settings::claudeMaySet($k), [Prices::MAPPING_SETTING, Quotes::AUTO_DRAFT_SETTING])],
    ['', '0', [0, 0]]);

// the stored mapping: a list of {collection, code, variants}; ids once, field keys of the collection-field pattern, anything else dropped
check('3.42 C: parseMapping keeps valid entries once, cleans field keys and drops the rest', [
    Prices::parseMapping(''), Prices::parseMapping('{"collection":3}'), Prices::parseMapping('nonsense'),
    Prices::parseMapping('[{"collection":"7","code":"kod","variants":"provedeni"},{"collection":7,"code":"x"},{"collection":0},{"collection":9,"code":"Bad Key!","variants":["a"]},"x"]'),
    count(Prices::parseMapping((string) json_encode(array_map(fn (int $i): array => ['collection' => $i], range(1, 50)))))],
    [[], [], [], [['collection' => 7, 'code' => 'kod', 'variants' => 'provedeni'], ['collection' => 9, 'code' => '', 'variants' => '']], Prices::MAPPED_MAX]);

// which fields may be a code (short text, number) and variants (the variants type, longer text) – in the collection's order
check('3.42 C: mappableFields offers short texts and numbers as the code, variants and longer texts as variants', Prices::mappableFields(['pole' => [
    ['klic' => 'kod', 'popisek' => 'Kód', 'typ' => 'text'], ['klic' => 'popis', 'popisek' => 'Popis', 'typ' => 'html'], ['klic' => 'cislo', 'popisek' => 'Číslo', 'typ' => 'cislo'],
    ['klic' => 'verze', 'popisek' => 'Verze', 'typ' => 'varianty'], ['klic' => 'poznamky', 'popisek' => 'Poznámky', 'typ' => 'radky'], ['klic' => 'foto', 'popisek' => 'Foto', 'typ' => 'obrazek']]]),
    ['code' => [['key' => 'kod', 'label' => 'Kód'], ['key' => 'cislo', 'label' => 'Číslo']], 'variants' => [['key' => 'verze', 'label' => 'Verze'], ['key' => 'poznamky', 'label' => 'Poznámky']]]);

// the bell of a prepared draft: a kind of its own, named in every admin language – the quote only, never an amount
check('3.42 C: the bell "a draft quote was prepared" is a notification kind with its text in English and Czech', [
    in_array('quote_prepared', Kaleta\Core\Inbox::KINDS, true),
    Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Inbox::what('quote_prepared', ''), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Core\Inbox::what('quote_prepared', ''), 'admin-')],
    [true, 'A draft quote was prepared from an enquiry', 'Z poptávky vznikl koncept nabídky']);

// nothing is queued while the feature or the setting is off – the visitor's request does no work for it
$c342Settings = static function (array $values): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, $values + ['site_name' => 'Firma', 'site_language' => 'cs']);

    return $s;
};
$c342App = static function (Kaleta\Core\Settings $s): Kaleta\Core\App {
    $app = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\App::class, 'settings'))->setValue($app, $s);

    return $app;
};
$c342Queued = [];
foreach ([['extensions' => 'poptavky', Quotes::AUTO_DRAFT_SETTING => '1'], ['extensions' => 'poptavky,quotes', Quotes::AUTO_DRAFT_SETTING => '0'], ['extensions' => 'poptavky,quotes', Quotes::AUTO_DRAFT_SETTING => '1']] as $c342Values) {
    $c342Before = Quotes::preparing();
    Quotes::prepareLater($c342App($c342Settings($c342Values)), 12);
    $c342Queued[] = Quotes::preparing() - $c342Before;
}
check('3.42 C: a new enquiry is queued for a draft only with the feature and the setting on', $c342Queued, [0, 0, 1]);
