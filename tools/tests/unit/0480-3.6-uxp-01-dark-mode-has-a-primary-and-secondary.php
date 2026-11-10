<?php

declare(strict_types=1);

/* ---------- 3.6 (UXP-01): dark mode has a primary and secondary of its own; (UXP-11) focus ring and link on surface are checked ---------- */
$darkBlock = static function (string $css): array { // the colours the "always dark" block of the token CSS sets
    preg_match('/:root\[data-tmavy\]\[data-tema="tmavy"\] \{(.*?)\n\}/s', $css, $block);
    preg_match_all('/--ka-barva-([a-z-]+): (#[0-9a-f]{6});/', $block[1] ?? '', $colours, PREG_SET_ORDER);

    return array_column($colours, 2, 1);
};
$presetFailures = [];
foreach (array_keys(Kaleta\Builder\DesignSystem::PRESETS) as $presetKey) {
    $presetDs = (array) Kaleta\Builder\DesignSystem::preset($presetKey);
    foreach ([false, true] as $dark) {
        foreach (Kaleta\Builder\DesignSystem::contrasts($presetDs, $dark) as $c) {
            if (!$c['ok']) {
                $presetFailures[] = $presetKey . ($dark ? ' dark: ' : ' light: ') . $c['popis'] . ' ' . $c['pomer'];
            }
        }
    }
    // until 3.5 the dark block set only text, background and surface – the light primary (Consulting #1e293b: 1.14 : 1) stayed on the dark page
    $block = $darkBlock(Kaleta\Builder\DesignSystem::css($presetDs));
    foreach ([['primarni', 'pozadi'], ['primarni', 'plocha'], ['sekundarni', 'pozadi'], ['sekundarni', 'plocha'], ['na-primarni', 'primarni']] as [$fg, $bg]) {
        if (!isset($block[$fg], $block[$bg]) || Kaleta\Builder\DesignSystem::contrast($block[$fg], $block[$bg]) < 4.5) {
            $presetFailures[] = $presetKey . ' dark CSS: ' . $fg . ' on ' . $bg;
        }
    }
}
check('3.6 (UXP-01): every style preset reads in light and in dark mode, also in the colours the dark CSS block really sets', $presetFailures, []);
$chosenDark = Kaleta\Builder\DesignSystem::sanitize(['barvy_tmave' => ['primarni' => '#FFD7A8', 'sekundarni' => 'auto', 'text' => '#ffffff']]);
check('3.6: a chosen dark primary is kept and wins, "auto" leaves the secondary derived; existing dark colours stay as they are', [$chosenDark['barvy_tmave'],
    Kaleta\Builder\DesignSystem::darkColors($chosenDark)['primarni'], $darkBlock(Kaleta\Builder\DesignSystem::css($chosenDark))['primarni'] ?? null,
    array_key_exists('sekundarni', Kaleta\Builder\DesignSystem::sanitize(Kaleta\Builder\DesignSystem::DEFAULTS)['barvy_tmave'])],
    [['text' => '#ffffff', 'pozadi' => '#121418', 'plocha' => '#1b1e24', 'primarni' => '#ffd7a8'], '#ffd7a8', '#ffd7a8', false]);
$readability = array_column(Kaleta\Builder\DesignSystem::contrasts(Kaleta\Builder\DesignSystem::DEFAULTS), null, 'popis');
$weakRing = array_column(Kaleta\Builder\DesignSystem::contrasts(Kaleta\Builder\DesignSystem::sanitize(['barvy' => ['sekundarni' => '#a0a0a0']])), 'ok', 'popis');
check('3.6 (UXP-11): the readability check has the focus ring on background and surface (3 : 1) and the link on surface (4.5 : 1); a pale secondary fails the ring', [
    $readability['Focus ring (secondary colour) on background']['min'] ?? null, $readability['Focus ring (secondary colour) on surface']['min'] ?? null,
    $readability['Link (primary colour) on surface']['min'] ?? null, $weakRing['Focus ring (secondary colour) on background'] ?? null,
    count(Kaleta\Builder\DesignSystem::contrasts(Kaleta\Builder\DesignSystem::DEFAULTS, true))], [3.0, 3.0, 4.5, false, 9]);
check('3.6: OKLCH round trip, color-mix in OKLCH like the browser (white and black have no hue)', [array_map(fn (string $hex): string => Kaleta\Builder\DesignSystem::fromOklch(...Kaleta\Builder\DesignSystem::toOklch($hex)),
    ['#2b5be3', '#9a3412', '#1e293b', '#ffffff', '#000000', '#7c3aed']), Kaleta\Builder\DesignSystem::mixOklch('#000000', '#ffffff', 0.5)],
    [['#2b5be3', '#9a3412', '#1e293b', '#ffffff', '#000000', '#7c3aed'], '#636363']);
$buildLibraryErrors = [];
// raw builds (section() already cleans them, so an invalid value would disappear silently)
foreach ((new ReflectionMethod(Kaleta\Builder\Library::class, 'sections'))->invoke(null) as $buildKey => $buildSection) {
    $buildSection['klic'] = $buildKey;
    [, $buildErrors] = Kaleta\Builder\Build::sanitize(['deti' => [($buildSection['stavba'])()]]);
    $buildLibraryErrors += array_map(fn (string $c): string => $buildSection['klic'] . ': ' . $c, $buildErrors);
}
check('Knihovna: všechny hotové sekce projdou validátorem', $buildLibraryErrors, []);
check('Stavba::schema: bez vlastního HTML pro ne-správce', in_array('html', array_column(Kaleta\Builder\Build::schema(false)['prvky'], 'typ'), true), false);

$fromHtml = Kaleta\Builder\HtmlConverter::convert('<style>.hero { padding: 2rem; background: url(x) } .hero h1 { color: red } @media (max-width: 9px) { .hero { padding: 0 } }</style>'
    . '<header class="hero container-x"><div class="wrap"><h1>A <em>b</em></h1><p>Jedna.</p><p>Dvě.</p><a class="btn btn-outline" href="/k">K</a></div></header>'
    . '<p>Volný text</p><details><summary>Otázka?</summary><p>Odpověď.</p></details><form></form><svg></svg><script>x</script>');
$fromHtmlTypes = fn (array $children): array => array_map(fn (array $p): string => $p['typ'] . '<' . $p['znacka'] . '>', $children);
check('ZHtml: sekce z <header>, vnitřní obal bez stylu odpadne', $fromHtmlTypes($fromHtml['stavba']['deti'][0]['deti']), ['nadpis<h1>', 'text<div>', 'tlacitko<a>']);
check('ZHtml: souvislé odstavce v jednom prvku Text', $fromHtml['stavba']['deti'][0]['deti'][1]['obsah']['html'], '<p>Jedna.</p><p>Dvě.</p>');
check('ZHtml: tlačítko s variantou podle třídy', [$fromHtml['stavba']['deti'][0]['deti'][2]['obsah']['varianta'], $fromHtml['stavba']['deti'][0]['deti'][2]['tridy']], ['obrys', ['btn', 'btn-outline']]);
check('ZHtml: volné prvky na konci se zabalí do sekce, details → FAQ, form → Formulář', $fromHtmlTypes($fromHtml['stavba']['deti'][1]['deti']), ['text<div>', 'faq<div>', 'formular<form>']);
check('ZHtml: třída z <style> jen s bezpečnými deklaracemi', $fromHtml['tridy'], ['hero' => 'padding: 2rem;']);
check('ZHtml: hlášení o @media, složitém selektoru, url(), formuláři, SVG a skriptu', count($fromHtml['hlaseni']), 6);
$fromHtml2 = Kaleta\Builder\HtmlConverter::convert('<style>.mriz { display: grid; grid-template-columns: repeat(3, 1fr); gap: var(--ka-mezera-l) } .karta:hover { box-shadow: var(--ka-stin-m); transform: translateY(-4px) }'
    . ' @media (max-width: 1023px) { .mriz { grid-template-columns: repeat(2, 1fr) } } @media (max-width: 767px) { .mriz { grid-template-columns: 1fr; gap: var(--ka-mezera-m) } .karta { padding: var(--ka-mezera-m) var(--ka-mezera-s) } }'
    . ' @media (min-width: 768px) { .mriz { gap: 0 } }</style><section><div class="mriz"><div class="karta"><h3>A</h3></div><div>B</div></div></section>');
check('ZHtml: @media (max-width) a :hover jako stavy třídy', $fromHtml2['tridy_styl'], ['mriz' => ['tablet' => ['sloupce' => '2'], 'mobil' => ['sloupce' => '1', 'mezera' => 'm']],
    'karta' => ['mobil' => ['odsazeni_y' => 'm', 'odsazeni_x' => 's'], 'hover' => ['stin' => 'm', 'posun' => '0 -4px']]]);
check('ZHtml: prvek se stylovanou třídou nemá výchozí styl (přebil by třídu), bez třídy ho má', [$fromHtml2['stavba']['deti'][0]['deti'][0]['styl'], $fromHtml2['stavba']['deti'][0]['deti'][0]['deti'][1]['styl']['zaklad']['zobrazeni'] ?? null], [[], 'flex']);
check('ZHtml: mobile-first @media (min-width) se nahlásí', count(array_filter($fromHtml2['hlaseni'], fn (string $h): bool => str_contains($h, 'min-width'))), 1);
check('Styl::zCss: tokeny, zkratky a mřížka', [Kaleta\Builder\Style::fromCss('padding', 'var(--ka-mezera-l) 2rem'), Kaleta\Builder\Style::fromCss('margin', '0 auto'), Kaleta\Builder\Style::fromCss('grid-template-columns', 'repeat(auto-fit, minmax(16rem, 1fr))'),
    Kaleta\Builder\Style::fromCss('color', 'var(--ka-barva-tlumeny)'), Kaleta\Builder\Style::fromCss('font-size', 'var(--ka-krok--1)'), Kaleta\Builder\Style::fromCss('color', 'expression(1)'), Kaleta\Builder\Style::fromCss('filter', 'blur(2px)')],
    [['odsazeni_y' => 'l', 'odsazeni_x' => '2rem'], ['okraj_nahore' => '0', 'okraj_dole' => '0', 'na_stred' => 'auto'], ['sloupce' => 'auto:16rem'], ['barva' => 'tlumeny'], ['velikost_pisma' => '-1'], null, null]);

$editBuild = ['v' => 1, 'deti' => [['id' => 'sek1', 'typ' => 'sekce', 'deti' => [['id' => 'nad1', 'typ' => 'nadpis', 'obsah' => ['text' => 'A'], 'styl' => ['zaklad' => ['barva' => 'primarni']]], ['id' => 'tl1', 'typ' => 'tlacitko', 'obsah' => ['text' => 'B', 'odkaz' => '/docs']]]], ['id' => 'sek2', 'typ' => 'sekce']]];
$editErrors = [];
$edit = Kaleta\Builder\Edits::apply($editBuild, [
    ['op' => 'uprav', 'id' => 'tl1', 'obsah' => ['odkaz' => '/guide']],
    ['op' => 'uprav', 'id' => 'nad1', 'styl' => ['zaklad' => ['barva' => null], 'mobil' => ['zarovnani_textu' => 'center']], 'tridy' => ['nadpis-sekce']],
    ['op' => 'vloz', 'do' => 'sek2', 'prvky' => [['id' => 'txt1', 'typ' => 'text']]],
    ['op' => 'presun', 'id' => 'tl1', 'za' => 'txt1'],
    ['op' => 'vloz', 'pozice' => 0, 'prvek' => ['id' => 'sek0', 'typ' => 'sekce']],
    ['op' => 'smaz', 'id' => 'neni'],
    ['op' => 'presun', 'id' => 'sek2', 'do' => 'txt1'],
    ['op' => 'kouzlo'],
], $editErrors);
check('Upravy: úprava obsahu a stylu (null odebere), vložení, přesun, vložení na začátek', [array_column($edit['deti'], 'id'), $edit['deti'][1]['deti'][0]['styl'], $edit['deti'][1]['deti'][0]['tridy'], array_column($edit['deti'][2]['deti'], 'id'), $edit['deti'][2]['deti'][1]['obsah']['odkaz']],
    [['sek0', 'sek1', 'sek2'], ['mobil' => ['zarovnani_textu' => 'center']], ['nadpis-sekce'], ['txt1', 'tl1'], '/guide']);
check('Upravy: chybné operace se nahlásí a přeskočí (i přesun do potomka)', array_keys($editErrors), ['op[5]', 'op[6]', 'op[7]']);
$editErrors2 = [];
check('Upravy: přesun prvku do jeho potomka nejde', Kaleta\Builder\Edits::apply($editBuild, [['op' => 'presun', 'id' => 'sek1', 'do' => 'nad1']], $editErrors2) === $editBuild && isset($editErrors2['op[0]']), true);

[$compactBuild] = Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['typ' => 'sekce', 'id' => 'abc', 'deti' => [['typ' => 'tlacitko', 'id' => 'def', 'obsah' => ['text' => 'Jdi']], ['typ' => 'kontejner', 'id' => 'ghi', 'styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]]]]]]);
$compact = Kaleta\Builder\Build::compact($compactBuild);
check('Stavba::kompaktni: bez výchozích hodnot, styl zůstane', $compact, ['v' => 1, 'deti' => [['id' => 'abc', 'typ' => 'sekce', 'deti' => [['id' => 'def', 'typ' => 'tlacitko', 'obsah' => ['text' => 'Jdi']], ['id' => 'ghi', 'typ' => 'kontejner', 'styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]]]]]]);
check('Stavba: zvýraznění <mark> v nadpisu zůstane, třídy a styly ne', Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['typ' => 'nadpis', 'id' => 'mk1', 'obsah' => ['text' => 'Publish<mark class="x" style="color:red">.</mark>']]]])[0]['deti'][0]['obsah']['text'], 'Publish<mark>.</mark>');
$buttonContext = null;
check('Styl::zCss: aliasy margin-top a flex-start', [Kaleta\Builder\Style::fromCss('margin-bottom', '24px'), Kaleta\Builder\Style::fromCss('align-items', 'flex-start')], [['okraj_dole' => '24px'], ['zarovnani' => 'start']]);
check('Styl::zCss: text-align left/right', [Kaleta\Builder\Style::fromCss('text-align', 'left'), Kaleta\Builder\Style::fromCss('text-align', 'right')], [['zarovnani_textu' => 'start'], ['zarovnani_textu' => 'end']]);
check('Ikony: GitHub v sadě', str_contains(Kaleta\Builder\Icons::svg('github'), 'M9 19c-4'), true);
check('Tlačítko: ikona za textem a vlevo od textu', [
    (bool) preg_match('#>Start<svg#', Kaleta\Builder\Elements\Button::render(['obsah' => ['text' => 'Start', 'odkaz' => '/x', 'varianta' => 'primarni', 'nove_okno' => false, 'ikona' => 'sipka', 'ikona_vlevo' => false]], '', '', (new ReflectionClass(Kaleta\Builder\Context::class))->newInstanceWithoutConstructor())),
    (bool) preg_match('#</svg>GitHub</a>#', Kaleta\Builder\Elements\Button::render(['obsah' => ['text' => 'GitHub', 'odkaz' => '/x', 'varianta' => 'obrys', 'nove_okno' => false, 'ikona' => 'github', 'ikona_vlevo' => true]], '', '', (new ReflectionClass(Kaleta\Builder\Context::class))->newInstanceWithoutConstructor())),
    Kaleta\Builder\Elements\Button::render(['obsah' => ['text' => 'Bez', 'odkaz' => '/x', 'varianta' => 'primarni', 'nove_okno' => false]], '', '', (new ReflectionClass(Kaleta\Builder\Context::class))->newInstanceWithoutConstructor()) === '<a class="ka-tlacitko ka-tlacitko--primarni" href="/x">Bez</a>',
], [true, true, true]);
check('Stavba::kompaktni: po vyčištění stejná stavba', Kaleta\Builder\Build::sanitize($compact)[0], $compactBuild);
$overview = Kaleta\Builder\Build::overview(Kaleta\Builder\Build::schema());
check('Stavba::prehled: prvek na řádek, výchozí možnost s hvězdičkou, schéma výrazně menší', [str_contains($overview['prvky']['tlacitko'], 'varianta:vyber(primarni*|'), strlen((string) json_encode($overview)) < strlen((string) json_encode(Kaleta\Builder\Build::schema())) / 2],
    [true, true]);
check('ZHtml: výsledek projde validátorem bez chyb', Kaleta\Builder\Build::sanitize($fromHtml['stavba'])[1], []);
check('Stavba::jakoText: sémantický obsah bez rozložení', Kaleta\Builder\Build::asText($fromHtml['stavba']), "<h1>A <em>b</em></h1>\n<p>Jedna.</p><p>Dvě.</p>\n<p><a href=\"/k\">K</a></p>\n<p>Volný text</p>\n<h3>Otázka?</h3><p>Odpověď.</p>");

// disabled extensions: the builder offers neither their elements nor sections using them
$schemaTypes = array_column(Kaleta\Builder\Build::schema(true, 'cs', false, ['statistika'])['prvky'], 'typ');
check('Rozšíření: bez novinek a poptávek schéma nemá jejich prvky', [in_array('novinky', $schemaTypes, true), in_array('formular', $schemaTypes, true), in_array('nadpis', $schemaTypes, true)], [false, false, true]);
$libraryKey = array_column(Kaleta\Builder\Library::listAll(['statistika']), 'klic');
check('Rozšíření: knihovna bez sekcí s formulářem a novinkami', [in_array('kontakt-formular', $libraryKey, true), in_array('novinky', $libraryKey, true), in_array('uvod', $libraryKey, true)], [false, false, true]);
check('Rozšíření: bez omezení je knihovna celá', count(Kaleta\Builder\Library::listAll()) > count($libraryKey), true);
$libraryEn = Kaleta\Builder\Library::section('uvod', 'en')['prvek'];
check('Knihovna: sekce v angličtině včetně odkazů na stránky', [$libraryEn['deti'][0]['obsah']['text'], $libraryEn['deti'][2]['deti'][0]['obsah']['odkaz'], $libraryEn['deti'][2]['deti'][1]['obsah']['odkaz']], ['We help businesses grow – quickly and hassle-free', '/contact', '/services']);
check('Knihovna: česky se odkazuje na české adresy', Kaleta\Builder\Library::section('uvod')['prvek']['deti'][2]['deti'][0]['obsah']['odkaz'], '/kontakt');
check('Knihovna: jazyk se po sestavení sekce vrátí', Kaleta\Core\Language::code(), 'cs');
$librarySchema = array_column(Kaleta\Builder\Build::schema(true, 'en')['prvky'], 'vlastnosti', 'typ');
check('Stavba::schema: výchozí obsah prvků v jazyce stránky', [$librarySchema['nadpis']['text']['vychozi'], $librarySchema['tlacitko']['text']['vychozi']], ['Heading', 'Contact us']);
$libraryEnDictionary = (require KALETA_ROOT . '/system/jazyky/en.php') + (require KALETA_ROOT . '/system/jazyky/cs.php');
preg_match_all("/\bt\('((?:[^'\\\\]|\\\\.)*)'\)/", file_get_contents(KALETA_ROOT . '/system/src/Builder/Library.php') . implode('', array_map('file_get_contents', glob(KALETA_ROOT . '/system/src/Builder/Elements/*.php'))), $libraryTexts);
check('Knihovna a prvky: všechny ukázkové texty mají anglický překlad', array_values(array_diff(array_unique(array_map('stripslashes', $libraryTexts[1])), array_keys($libraryEnDictionary), ['Menu', 'Standard', 'Video'])), []);

check('Firma::hodiny: rozsah dnů, víc úseků, zavřeno', Kaleta\Front\Company::parseOpeningHours("Po–Pá 8:00–17:00\nÚt 8-12, 13-17\nNe zavřeno"), [
    ['dny' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'od' => '08:00', 'do' => '17:00'],
    ['dny' => ['Tuesday'], 'od' => '08:00', 'do' => '12:00'], ['dny' => ['Tuesday'], 'od' => '13:00', 'do' => '17:00'],
]);
check('Firma::hodiny: nesrozumitelný řádek se odmítne', [Kaleta\Front\Company::parseOpeningHours('každý den 8-17'), Kaleta\Front\Company::parseOpeningHours('Po 8-25')], [null, null]);
check('Firma: typy v Nastavení odpovídají Firma::TYPY', (new ReflectionClassConstant(Kaleta\Admin\Modules\Settings::class, 'COMPANY_TYPES'))->getValue(), implode('|', array_keys(Kaleta\Front\Company::TYPES)));

