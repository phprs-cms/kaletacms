<?php

declare(strict_types=1);

/* ---------- 2.7: copy and paste between Kaleta sites (Builder\ElementClipboard) ---------- */
$clipboardElements = [['id' => 'abc1234', 'typ' => 'sekce', 'kotva' => 'cenik', 'tridy' => ['karta'], 'deti' => [
    ['id' => 'def5678', 'typ' => 'obrazek', 'obsah' => ['src' => 'media/2026/foto.jpg', 'alt' => 'x']],
    ['id' => 'ghi9012', 'typ' => 'text', 'obsah' => ['html' => '<p><img src="/media/2026/a.png" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>'], 'styl' => ['zaklad' => ['obrazek_pozadi' => 'media/bg.webp']]],
    ['id' => 'jkl3456', 'typ' => 'komponenta', 'obsah' => ['komponenta' => '7', 'hodnoty' => []]],
]]];
$clipboardEnvelope = ['kaleta' => 'elements', 'v' => 1, 'site' => 'https://Zdroj.example/', 'elements' => $clipboardElements, 'classes' => [['nazev' => 'karta', 'styl' => [], 'css' => '']], 'components' => [['id' => 7, 'nazev' => 'K', 'vlastnosti' => [], 'stavba' => ['v' => 1, 'deti' => []]]]];
$clipboardParsed = Kaleta\Builder\ElementClipboard::parse($clipboardEnvelope);
check('2.7: clipboard envelope – checked and normalised', [$clipboardParsed['site'], count($clipboardParsed['prvky']), count($clipboardParsed['tridy']), count($clipboardParsed['komponenty'])], ['https://zdroj.example', 1, 1, 1]);
check('2.7: clipboard envelope – anything else is refused', [
    Kaleta\Builder\ElementClipboard::parse('text'), Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'page', 'v' => 1, 'elements' => $clipboardElements]),
    Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'elements', 'v' => 2, 'elements' => $clipboardElements]), Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'elements', 'v' => 1, 'elements' => []]),
    Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'elements', 'v' => 1, 'elements' => ['x', 1]]), Kaleta\Builder\ElementClipboard::parse(['kaleta' => 'elements', 'v' => 1, 'elements' => $clipboardElements, 'site' => 'javascript:x'])['site'],
], [null, null, null, null, null, '']);
$clipboardFresh = Kaleta\Builder\ElementClipboard::fresh($clipboardElements);
check('2.7: pasted elements lose their ids and anchors (new ids come from sanitize)', [isset($clipboardFresh[0]['id']), isset($clipboardFresh[0]['kotva']), isset($clipboardFresh[0]['deti'][1]['id']), $clipboardFresh[0]['deti'][1]['typ']], [false, false, false, 'text']);
$clipboardImages = 0;
$clipboardHttps = Kaleta\Builder\ElementClipboard::relinkMedia($clipboardElements, 'https://zdroj.example', $clipboardImages);
check('2.7: media of an https site are pointed at it and counted', [$clipboardImages, $clipboardHttps[0]['deti'][0]['obsah']['src'], $clipboardHttps[0]['deti'][1]['obsah']['html'], $clipboardHttps[0]['deti'][1]['styl']['zaklad']['obrazek_pozadi']],
    [3, 'https://zdroj.example/media/2026/foto.jpg', '<p><img src="https://zdroj.example/media/2026/a.png" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>', 'https://zdroj.example/media/bg.webp']);
$clipboardImages = 0;
$clipboardHttp = Kaleta\Builder\ElementClipboard::relinkMedia($clipboardElements, 'http://zdroj.example', $clipboardImages);
check('2.7: media of an http site are left out and counted', [$clipboardImages, $clipboardHttp[0]['deti'][0]['obsah']['src'], $clipboardHttp[0]['deti'][1]['obsah']['html'], $clipboardHttp[0]['deti'][1]['styl']['zaklad']['obrazek_pozadi']],
    [3, '', '<p><img src="" alt=""> <a href="https://jiny.cz/media/x.pdf">pdf</a></p>', '']);
check('2.7: relinked media pass the build validator', Kaleta\Builder\Build::sanitize(['deti' => $clipboardHttps])[0]['deti'][0]['deti'][0]['obsah']['src'], 'https://zdroj.example/media/2026/foto.jpg');
check('Stavba::zTextu: nadpis h1 a text', array_map(fn (array $p): string => $p['znacka'], Kaleta\Builder\Build::fromText('O nás', '<p>x</p>')['deti'][0]['deti']), ['h1', 'div']);
$buildStyleErrors = [];
check('Styl::vycisti: vloženo CSS, neznámá vlastnost a stav vypadnou', Kaleta\Builder\Style::sanitize(['zaklad' => ['barva' => 'red;}body{x:y', 'neznama' => '1', 'sirka' => '50%'], 'tisk' => []], 's', $buildStyleErrors), ['zaklad' => ['sirka' => '50%']]);
check('Styl::vycisti: chyby', array_keys($buildStyleErrors), ['s.zaklad.barva', 's.zaklad.neznama', 's.tisk']);
// 3.21 (U4): visibility per device is the Display style „none“ at the breakpoint – the Visibility row reads and writes what the two former
// checkboxes and the Style tab wrote, so an old build renders the same; the builder writes an explicit display (flex, block) where a
// smaller device must stay shown under a hidden larger one
check('3.21 (U4): hidden on phone = display none in the phone media query only', Kaleta\Builder\Style::css('#s-a', ['mobil' => ['zobrazeni' => 'none']]), "@media (max-width: 767px) { #s-a { display: none; } }\n");
check('3.21 (U4): hidden on tablet, shown again on phone with the container\'s own display', Kaleta\Builder\Style::css('#s-a', ['zaklad' => ['zobrazeni' => 'flex'], 'tablet' => ['zobrazeni' => 'none'], 'mobil' => ['zobrazeni' => 'flex']]),
    "#s-a { display: flex; }\n@media (max-width: 1023px) { #s-a { display: none; } }\n@media (max-width: 767px) { #s-a { display: flex; } }\n");
$visibilityErrors = [];
$visibilityStyle = ['zaklad' => ['zobrazeni' => 'none'], 'tablet' => ['zobrazeni' => 'block'], 'mobil' => ['zobrazeni' => 'none']];
check('3.21 (U4): sanitize keeps every visibility combination the builder writes', [Kaleta\Builder\Style::sanitize($visibilityStyle, 's', $visibilityErrors), $visibilityErrors], [$visibilityStyle, []]);
check('3.21 (U4): an old build hidden on mobile passes the build validator unchanged',
    Kaleta\Builder\Build::sanitize(['v' => 1, 'deti' => [['id' => 'a1', 'typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => 'Hi'], 'styl' => ['mobil' => ['zobrazeni' => 'none']]]]])[0]['deti'][0]['styl'], ['mobil' => ['zobrazeni' => 'none']]);
check('Styl::css: tokeny, sloupce, hover a breakpoint', Kaleta\Builder\Style::css('#s-a', ['zaklad' => ['odsazeni_y' => 'xl', 'barva' => 'primarni', 'sloupce' => '3'], 'mobil' => ['sloupce' => '1'], 'hover' => ['barva' => '#ff0000']]),
    "#s-a { padding-block: var(--ka-mezera-xl); color: var(--ka-barva-primarni); grid-template-columns: repeat(3, minmax(0, 1fr)); }\n#s-a:is(:hover, :focus-visible) { color: #ff0000; }\n@media (max-width: 767px) { #s-a { grid-template-columns: repeat(1, minmax(0, 1fr)); } }\n");
// 3.9.2: a max width replaced the template's img { max-width: 100% } – an 800px photo ran off a phone; percent and keywords stay
check('3.9.2: Style::css caps a max width with the space', [Kaleta\Builder\Style::css('#a', ['zaklad' => ['max_sirka' => '800px']]), Kaleta\Builder\Style::css('#a', ['zaklad' => ['max_sirka' => '60%']])],
    ["#a { max-width: min(800px, 100%); }\n", "#a { max-width: 60%; }\n"]);
check('Html::bezpecne: bez skriptů, obsluh událostí a javascript:, se strukturou a třídami', Kaleta\Core\Html::safe('<p class="x" onclick="a()">A <a href="javascript:alert(1)">b</a><img src="x" onerror="alert(1)"><script>alert(1)</script></p><iframe src="https://x"></iframe><a href="/k" target="_blank" data-vlozit="javascript:x">k</a>'),
    '<p class="x">A <a>b</a><img src="x"></p><a href="/k" target="_blank" rel="noopener">k</a>');
check('Stavba: háčky skriptů webu nejdou vložit jako vlastní atribut', [preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, 'data-vlozit'), preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, 'data-samo'), preg_match(Kaleta\Builder\Build::ATTRIBUTE_PATTERN, 'data-sledovat')], [0, 0, 1]);
check('Styl::css: najetí a stisk zvlášť pro tablet a mobil', Kaleta\Builder\Style::css('#x', ['mobil' => ['mezera' => 's'], 'hover_mobil' => ['barva' => 'primarni'], 'aktivni_tablet' => ['meritko' => '0.95']]),
    "@media (max-width: 1023px) { #x:active { scale: 0.95; } }\n@media (max-width: 767px) { #x { gap: var(--ka-mezera-s); } #x:is(:hover, :focus-visible) { color: var(--ka-barva-primarni); } }\n");
check('Styl::css: typografický styl první, jednotlivé vlastnosti ho doladí', Kaleta\Builder\Style::css('#x', ['zaklad' => ['velikost_pisma' => '3', 'typ_styl' => 'nadtitulek']]),
    "#x { font: var(--ka-typ-nadtitulek); text-transform: uppercase; letter-spacing: 0.08em; font-size: var(--ka-krok-3); }\n");
check('Styl: vlastní stín a rámeček s tokeny barev', [Kaleta\Builder\Style::value('stin', '0 8px 24px 0 primarni'), Kaleta\Builder\Style::value('stin', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)'), Kaleta\Builder\Style::value('ramecek', '2px dashed primarni')],
    ['0 8px 24px 0 var(--ka-barva-primarni)', 'inset 0 1px 0 #ffffff33, 0 4px 12px rgb(0 0 0 / 0.1)', '2px dashed var(--ka-barva-primarni)']);
check('Styl: stín a rámeček nepustí nic nebezpečného', [Kaleta\Builder\Style::value('stin', '0 0 1px url(x)'), Kaleta\Builder\Style::value('stin', '0 0 red; color: red'), Kaleta\Builder\Style::value('ramecek', '1px solid red}')], [null, null, null]);
check('Styl: mřížka – řádky, oblasti a oblast prvku', [Kaleta\Builder\Style::value('radky', '3'), Kaleta\Builder\Style::value('oblasti', 'hlava hlava / bok obsah'), Kaleta\Builder\Style::value('oblasti', 'a b / c'), Kaleta\Builder\Style::value('oblast', 'bok'), Kaleta\Builder\Style::value('oblast', 'x"y')],
    ['repeat(3, auto)', '"hlava hlava" "bok obsah"', null, 'bok', null]);
// 3.21 (U5): font size takes a step of the type scale or a length with a unit; words ("xl", "large") and a space before the unit are refused
check('3.21 (U5) Style: font size – steps and lengths pass, words do not', array_map(fn (string $h): ?string => Kaleta\Builder\Style::value('velikost_pisma', $h), ['2', '-1', '18px', '1.25rem', '120%', 'clamp(1rem, 2vw, 2rem)', 'xl', 'large', '18 px', '18', 'var(--x)']),
    ['var(--ka-krok-2)', 'var(--ka-krok--1)', '18px', '1.25rem', '120%', 'clamp(1rem, 2vw, 2rem)', null, null, null, null, null]);
// the builder checks a custom size in the field with the same rule, so the field and the save agree: the regular expression in
// image/stavitel.js is the body of Style::LENGTH_PATTERN, character for character
$builderScript = (string) file_get_contents(dirname(__DIR__, 3) . '/image/stavitel.js');
preg_match('~const LENGTH = /(.+)/i; // mirrors Style::LENGTH_PATTERN~', $builderScript, $lengthInScript);
$lengthInPhp = (string) (new ReflectionClassConstant(Kaleta\Builder\Style::class, 'LENGTH_PATTERN'))->getValue();
check('3.21 (U5) the builder\'s length check mirrors Style::LENGTH_PATTERN', $lengthInScript[1] ?? '', substr($lengthInPhp, 1, strrpos($lengthInPhp, '/') - 1));
// 3.21 (U2): every element type names its style groups in the builder (RELEVANT_GROUPS; null = all), and the groups exist – a new element must be placed there
preg_match('~const RELEVANT_GROUPS = \(\(\) => \{(.+?)\n\t\}\)\(\);~s', $builderScript, $relevantBlock);
preg_match_all('~(?:^|[\s{,])([a-z][a-z_]*):~', $relevantBlock[1] ?? '', $relevantTypes);
preg_match_all("~'([a-z]+)'~", $relevantBlock[1] ?? '', $relevantGroups);
check('3.21 (U2) every builder element type has its style groups in stavitel.js', array_values(array_diff(array_map(fn (string $c): string => $c::TYPE, Kaleta\Builder\Build::ELEMENTS), $relevantTypes[1])), []);
check('3.21 (U2) the style groups named in stavitel.js exist', array_values(array_diff(array_unique($relevantGroups[1]), array_keys(Kaleta\Builder\Style::GROUPS))), []);
check('3.21 (U2) the style groups map names no unknown element type', array_values(array_diff($relevantTypes[1], array_map(fn (string $c): string => $c::TYPE, Kaleta\Builder\Build::ELEMENTS))), []);
check('DesignSystem: typografické styly jako tokeny, úprava ve Vzhledu', [str_contains(Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::sanitize([])), '--ka-typ-perex: 400 var(--ka-krok-1)/1.55 var(--ka-pismo-text);'),
    str_contains(Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::sanitize(['typografie' => ['perex' => ['krok' => '2', 'tloustka' => '500'], 'titulek' => ['krok' => '99']]])), '--ka-typ-perex: 500 var(--ka-krok-2)/1.55'),
    Kaleta\Builder\DesignSystem::sanitize(['typografie' => ['titulek' => ['krok' => '99']]])['typografie']], [true, true, []]);
check('Styl::css: obrázek pozadí z Médií od kořene instalace', str_contains(Kaleta\Builder\Style::css('#s', ['zaklad' => ['obrazek_pozadi' => 'media/2026/09/a.jpg']], '', '/web'), 'url("/web/media/2026/09/a.jpg")'), true);
$takenSlugs = ['o-nas' => 1, 'o-nas-2' => 1, str_repeat('a', 10) => 1];
check('Volná adresa: číslo za obsazenou, s číslem se vejde do sloupce', [
    Kaleta\Core\Slug::makeUnique('o-nas', fn (string $a): bool => isset($takenSlugs[$a])),
    Kaleta\Core\Slug::makeUnique('sluzby', fn (string $a): bool => isset($takenSlugs[$a])),
    Kaleta\Core\Slug::makeUnique(str_repeat('a', 12), fn (string $a): bool => isset($takenSlugs[$a]), 10),
], ['o-nas-3', 'sluzby', 'aaaaaaaa-2']);
check('Kontejner jako odkaz: odkazy uvnitř se změní na span', Kaleta\Builder\Elements\Container::render(['znacka' => 'div', 'obsah' => ['odkaz' => '/k']], '', '<p>x</p><a class="ka-tlacitko" href="/y" target="_blank">B</a><abbr>z</abbr>', new Kaleta\Builder\Context((new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor())),
    '<a class="ka-karta-odkaz" href="/k"><p>x</p><span class="ka-tlacitko">B</span><abbr>z</abbr></a>');
check('Menu::vycisti: neznámý typ, nebezpečná adresa a třetí úroveň vypadnou', Kaleta\Core\Menu::sanitize([
    ['typ' => 'skript'], ['typ' => 'odkaz', 'text' => 'X', 'url' => 'javascript:alert(1)'],
    ['typ' => 'skupina', 'text' => 'Služby', 'deti' => [['typ' => 'stranka', 'ids' => 3, 'deti' => [['typ' => 'novinky']]], ['typ' => 'odkaz', 'text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => 1]]],
]), [['typ' => 'skupina', 'text' => 'Služby', 'deti' => [['typ' => 'stranka', 'text' => '', 'ids' => 3], ['typ' => 'odkaz', 'text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => true]]]]);
// 3.6: a submenu is a disclosure – its parent is a button with aria-controls (a group) or a link plus a toggle button (UXP-04)
$submenuIds = fn (string $html): string => (string) preg_replace('/ka-podmenu-\d+/', 'ka-podmenu-N', $html);
check('Menu::html: podmenu, aktivní položka a větev, úvod jen přesnou shodou', $submenuIds(Kaleta\Core\Menu::html([
    ['text' => 'Úvod', 'url' => '/', 'nove_okno' => false, 'deti' => []],
    ['text' => 'Služby', 'url' => '', 'nove_okno' => false, 'deti' => [['text' => 'Kuchyně', 'url' => '/kuchyne', 'nove_okno' => false, 'deti' => []]]],
], '/kuchyne/detail', '/')), '<li><a href="/">Úvod</a></li><li class="podmenu aktivni"><button type="button" class="menu-skupina" aria-controls="ka-podmenu-N">Služby</button><ul id="ka-podmenu-N"><li><a href="/kuchyne" aria-current="page">Kuchyně</a></li></ul></li>');
// a link with a fragment is never the current page: on the home page "/#produkty" used to match the path "/" (every anchor underlined)
check('Menu::html: a link with a fragment (#…) is never aria-current, the page itself still is', array_map(fn (string $path): int => substr_count(Kaleta\Core\Menu::html([
    ['text' => 'Home', 'url' => '/', 'nove_okno' => false, 'deti' => []], ['text' => 'Products', 'url' => '/#produkty', 'nove_okno' => false, 'deti' => []],
    ['text' => 'Price list', 'url' => '/sluzby#cenik', 'nove_okno' => false, 'deti' => []], ['text' => 'Contact', 'url' => '#kontakt', 'nove_okno' => false, 'deti' => []],
], $path, '/'), 'aria-current'), ['/', '/sluzby']), [1, 0]);
$linkedParent = Kaleta\Core\Menu::html([['text' => 'O nás', 'url' => '/o-nas', 'nove_okno' => false, 'deti' => [['text' => 'Tým', 'url' => '/tym', 'nove_okno' => false, 'deti' => []]]],
    ['text' => 'Služby', 'url' => '', 'nove_okno' => false, 'deti' => [['text' => 'Kuchyně', 'url' => '/kuchyne', 'nove_okno' => false, 'deti' => []]]]], '/', '/');
preg_match_all('/aria-controls="(ka-podmenu-\d+)"/', $linkedParent, $controls);
check('3.6 Menu::html: a linked parent keeps its link and gets a toggle button; every toggle controls the id of its own list, ids unique', [
    str_contains($submenuIds($linkedParent), '<li class="podmenu"><a href="/o-nas">O nás</a><button type="button" class="menu-rozbalit" aria-controls="ka-podmenu-N" aria-label="Submenu: O nás"></button><ul id="ka-podmenu-N">'),
    count($controls[1]), count(array_unique($controls[1])), array_map(fn (string $id): bool => str_contains($linkedParent, '<ul id="' . $id . '">'), $controls[1]),
], [true, 2, 2, [true, true]]);
// 2.7: icons and descriptions of menu items, a group inside a submenu with its own items (a column of the mega menu)
check('Menu::sanitize: ikona jen ze sady, popis bez značek do 120 znaků, skupina v podmenu smí mít položky, stránka v podmenu ne', Kaleta\Core\Menu::sanitize([
    ['typ' => 'odkaz', 'text' => 'Kontakt', 'url' => '/kontakt', 'ikona' => 'telefon', 'popis' => ' <b>Zavolejte</b> nám ' . str_repeat('x', 130)],
    ['typ' => 'novinky', 'ikona' => 'neexistuje', 'popis' => ['pole']],
    ['typ' => 'skupina', 'text' => 'Služby', 'deti' => [
        ['typ' => 'skupina', 'text' => 'Kuchyně', 'ikona' => 'dum', 'deti' => [['typ' => 'stranka', 'ids' => 3, 'deti' => [['typ' => 'novinky']]]]],
        ['typ' => 'stranka', 'ids' => 4, 'deti' => [['typ' => 'novinky']]],
    ]],
]), [
    ['typ' => 'odkaz', 'text' => 'Kontakt', 'ikona' => 'telefon', 'popis' => 'Zavolejte nám ' . str_repeat('x', 106), 'url' => '/kontakt', 'nove_okno' => false],
    ['typ' => 'novinky', 'text' => ''],
    ['typ' => 'skupina', 'text' => 'Služby', 'deti' => [
        ['typ' => 'skupina', 'text' => 'Kuchyně', 'ikona' => 'dum', 'deti' => [['typ' => 'stranka', 'text' => '', 'ids' => 3]]],
        ['typ' => 'stranka', 'text' => '', 'ids' => 4],
    ]],
]);
$menuWithColumns = [
    ['text' => 'Kontakt', 'url' => '/kontakt', 'nove_okno' => false, 'deti' => [], 'ikona' => 'telefon', 'popis' => 'Nahoře se popis neukáže'],
    ['text' => 'Služby', 'url' => '', 'nove_okno' => false, 'deti' => [
        ['text' => 'Kuchyně', 'url' => '', 'nove_okno' => false, 'deti' => [['text' => 'Na míru', 'url' => '/na-miru', 'nove_okno' => false, 'deti' => [], 'popis' => 'Podle vašich <rozměrů>']], 'ikona' => 'dum'],
        ['text' => 'Ceník', 'url' => '/cenik', 'nove_okno' => false, 'deti' => [], 'popis' => 'Orientační ceny'],
    ]],
];
$menuSvg = fn (string $key): string => Kaleta\Builder\Icons::svg($key, 'menu-ikona');
check('Menu::html: ikona před textem, skupina v podmenu jako sloupec s nadpisem, popis jen v mega menu pod položkami podmenu', $submenuIds(Kaleta\Core\Menu::html($menuWithColumns, '/na-miru', '/', true)),
    '<li><a href="/kontakt">' . $menuSvg('telefon') . 'Kontakt</a></li><li class="podmenu aktivni"><button type="button" class="menu-skupina" aria-controls="ka-podmenu-N">Služby</button><ul id="ka-podmenu-N">'
    . '<li class="menu-sloupec"><span class="menu-nadpis">' . $menuSvg('dum') . 'Kuchyně</span><ul><li><a href="/na-miru" aria-current="page">Na míru<small class="menu-popis">Podle vašich &lt;rozměrů&gt;</small></a></li></ul></li>'
    . '<li><a href="/cenik">Ceník<small class="menu-popis">Orientační ceny</small></a></li></ul></li>');
check('Menu::html: bez mega menu zůstane sloupec, popisy se nevypisují', [str_contains(Kaleta\Core\Menu::html($menuWithColumns, '/', '/'), 'menu-popis'), str_contains(Kaleta\Core\Menu::html($menuWithColumns, '/', '/'), '<li class="menu-sloupec"><span class="menu-nadpis">')], [false, true]);
check('Menu::flatten: všechny úrovně v pořadí', array_column(Kaleta\Core\Menu::flatten($menuWithColumns), 'text'), ['Kontakt', 'Služby', 'Kuchyně', 'Na míru', 'Ceník']);
check('MCP anglicky: ikona a popis položky menu tam i zpět', [
    Kaleta\Mcp\Translator::arguments('save_menu', ['location' => 'main', 'items' => [['type' => 'group', 'text' => 'S', 'icon' => 'phone', 'description' => 'D', 'children' => [['type' => 'link', 'url' => '/x', 'icon' => 'dum']]]]])['polozky'],
    Kaleta\Mcp\Translator::result('get_menu', ['umisteni' => 'hlavni', 'polozky' => [['typ' => 'odkaz', 'text' => 'K', 'url' => '/k', 'ikona' => 'telefon', 'popis' => 'D']], 'na_webu' => []])['items'],
], [
    [['typ' => 'skupina', 'text' => 'S', 'ikona' => 'telefon', 'popis' => 'D', 'deti' => [['typ' => 'odkaz', 'url' => '/x', 'ikona' => 'dum']]]],
    [['type' => 'link', 'text' => 'K', 'url' => '/k', 'icon' => 'phone', 'description' => 'D']],
]);
$navMegaCss = Kaleta\Builder\Elements\Navigation::baseCss();
check('Navigace: styl ikony, sloupce a popisu menu; popis se na telefonu skryje', [str_contains($navMegaCss, '.ka-nav .menu-ikona {'), str_contains($navMegaCss, '.ka-nav .menu-sloupec > ul {'), str_contains($navMegaCss, '.ka-nav .menu-nadpis {'),
    (bool) preg_match('/@media \(max-width: 767px\).*?\.ka-nav-menu\[popover\] \.menu-popis \{ display: none; \}/s', $navMegaCss)], [true, true, true, true]);
// 3.5: "Also on tablets" – a long menu collapses behind the button up to 1023 px; the wide panel moves to 1024 px for it, other navigations unchanged
$navTabletBuild = ['v' => 1, 'deti' => [['id' => 'nt1', 'typ' => 'navigace', 'obsah' => ['mobil_tablet' => true, 'mega' => true], 'deti' => []], ['id' => 'nt2', 'typ' => 'navigace', 'obsah' => [], 'deti' => []]]];
[$navTabletBuild] = Kaleta\Builder\Build::sanitize($navTabletBuild);
$navTabletContext = new Kaleta\Builder\Context($headerApp ?? new Kaleta\Core\App([]));
$navTabletContext->source = 'cast:hlavicka:';
$navTabletHtml = Kaleta\Builder\Build::html($navTabletBuild, $navTabletContext);
preg_match('/@media \(min-width: 768px\) and \(max-width: 1023px\) \{(.*)\}$/s', $navMegaCss, $navTabletBlock);
$navTabletSelectors = array_filter(array_map('trim', explode(',', implode(',', array_filter(preg_split('/\{[^}]*\}/', (string) preg_replace('#/\*.*?\*/#s', '', $navTabletBlock[1] ?? '')) ?: [])))));
check('Navigace 3.5: na tabletech za tlačítkem jen s volbou; panel mega menu pro ni až od 1024 px; anglický název tablet_menu', [
    (bool) preg_match('/<nav[^>]*class="[^"]*ka-nav--mega ka-nav--tablet"/', $navTabletHtml), substr_count($navTabletHtml, 'ka-nav--tablet'),
    $navTabletSelectors !== [] && array_filter($navTabletSelectors, fn (string $x): bool => !str_contains($x, 'ka-nav--tablet')) === [],
    (bool) preg_match('/@media \(min-width: 768px\) \{[^@]*\.ka-nav--mega:not\(\.ka-nav--tablet\) \{ position: relative; \}/', $navMegaCss),
    (bool) preg_match('/@media \(min-width: 1024px\) \{[^@]*\.ka-nav--mega\.ka-nav--tablet \{ position: relative; \}/', $navMegaCss),
    Kaleta\Mcp\Vocabulary::CONTENT['mobil_tablet'] ?? null,
], [true, 1, true, true, true, 'tablet_menu']);
// 2.7: the header that is transparent at the top (and/or smaller after scrolling) – only in the header site part, CSS only when used
$headerApp = new Kaleta\Core\App([]);
$headerBuild = ['v' => 1, 'deti' => [['id' => 'hl1', 'typ' => 'sekce', 'znacka' => 'header', 'obsah' => ['pri_rolovani' => 'pruhledna-zmensit', 'text_nahore' => 'svetly'], 'styl' => ['zaklad' => ['pozice' => 'sticky', 'pozadi' => 'pozadi']], 'deti' => []]]];
[$headerBuild] = Kaleta\Builder\Build::sanitize($headerBuild);
$headerContext = new Kaleta\Builder\Context($headerApp);
$headerContext->source = 'cast:hlavicka:';
$headerHtml = Kaleta\Builder\Build::html($headerBuild, $headerContext);
$headerCss = Kaleta\Builder\Build::css((new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor(), $headerContext);
check('Sekce při rolování: třídy záhlaví, fixní pozice až za stylem (sticky), animace podle posuvu, světlý text nahoře', [
    (bool) preg_match('/<header id="s-hl1" class="ka-hlavicka-rolovani ka-hlavicka-rolovani--pruhledna">/', $headerHtml),
    (bool) preg_match('/#s-hl1 \{ [^}]*position: sticky;[^}]*background-color: var\(--ka-barva-pozadi\); position: fixed; top: 0; inset-inline: 0; animation: ka-hlavicka-svetla linear both, ka-hlavicka-mensi linear both; animation-timeline: scroll\(root\); animation-range: 0 120px; \}/', $headerContext->css),
    str_contains($headerCss, '@keyframes ka-hlavicka-svetla'), str_contains($headerCss, '@keyframes ka-hlavicka-mensi { to { padding-block:'), str_contains($headerCss, 'prefers-reduced-motion: reduce) { .ka-hlavicka-rolovani { animation: none !important; } }'),
], [true, true, true, true, true]);
$pageContext = new Kaleta\Builder\Context($headerApp);
$pageContext->source = 'stranka:5';
check('Sekce při rolování: mimo záhlaví se neprojeví a CSS se nevypíše', [str_contains(Kaleta\Builder\Build::html($headerBuild, $pageContext), 'ka-hlavicka'), str_contains($pageContext->css, 'animation'),
    str_contains(Kaleta\Builder\Build::css((new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor(), $pageContext), 'ka-hlavicka')], [false, false, false]);
$shrinkOnly = ['v' => 1, 'deti' => [['id' => 'hl2', 'typ' => 'sekce', 'obsah' => ['pri_rolovani' => 'zmensit'], 'deti' => []]]];
$shrinkContext = new Kaleta\Builder\Context($headerApp);
$shrinkContext->source = 'cast:hlavicka:kampan';
check('Sekce při rolování: jen zmenšení nechá záhlaví v toku (žádné position: fixed), prvek bez stylu přesto dostane id a pravidlo', [
    str_contains(Kaleta\Builder\Build::html(Kaleta\Builder\Build::sanitize($shrinkOnly)[0], $shrinkContext), '<section id="s-hl2" class="ka-hlavicka-rolovani">'),
    str_contains($shrinkContext->css, 'position: fixed'), str_contains($shrinkContext->css, '#s-hl2 { animation: ka-hlavicka-mensi linear both;'),
], [true, false, true]);
// 3.15.3: the build's margin reset leaves the system content inside the Page content element of a wrapper alone (news text paragraphs keep the layout's spacing)
$wrapperBuild = ['v' => 1, 'deti' => [['id' => 'ob1', 'typ' => 'sekce', 'deti' => [['id' => 'ob2', 'typ' => 'obsah']]], ['id' => 'ob3', 'typ' => 'sekce', 'deti' => [['id' => 'ob4', 'typ' => 'text', 'obsah' => ['text' => '<p>CTA</p>']]]]]];
$wrapperContext = new Kaleta\Builder\Context($headerApp);
$wrapperContext->source = 'cast:novinka:';
$wrapperContext->content = '<p>First</p><p>Second</p>';
$wrapperHtml = Kaleta\Builder\Build::html(Kaleta\Builder\Build::sanitize($wrapperBuild)[0], $wrapperContext);
$wrapperCss = Kaleta\Builder\Build::css((new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor(), $wrapperContext);
preg_match_all('/^([^{}\n]*)\{[^}\n]*margin-block: 0;/m', $wrapperCss, $wrapperResets);
check('Page content in a wrapper: the content sits in .obal.obsah, the only margin reset of the build skips everything inside .obsah and keeps zero specificity', [
    (bool) preg_match('/class="obal obsah"><p>First<\/p><p>Second<\/p>/', $wrapperHtml),
    array_map('trim', $wrapperResets[1]),
], [true, [':where(.stavba) :where(h1, h2, h3, h4, h5, h6, p, ul, ol, blockquote, figure, hr):not(:where(.obsah *))']]);
check('Vocabulary: záhlaví při rolování anglicky', Kaleta\Mcp\Vocabulary::contentToEnglish('sekce', ['pri_rolovani' => 'pruhledna-zmensit', 'text_nahore' => 'svetly']), ['on_scroll' => 'transparent_shrink', 'text_at_top' => 'light']);
check('Hledani::najdi: shoda v názvu má přednost', array_column(Kaleta\Core\Search::find('search', [
    ['titulek' => 'Menus', 'adresa' => 'menus', 'text' => 'Link to site search from the menu.'],
    ['titulek' => 'Site search', 'adresa' => 'site-search', 'text' => 'How search works.'],
    ['titulek' => 'SEO', 'adresa' => 'seo', 'text' => 'Search engines and search results; search console.'],
]), 'adresa'), ['site-search', 'seo', 'menus']);
check('Hledani::najdi: bez diakritiky, všechna slova, úryvek', Kaleta\Core\Search::find('zkusenosti kuchyne', [
    ['titulek' => 'O nás', 'adresa' => 'o-nas', 'text' => '<p>Máme dvacet let zkušeností s nábytkem.</p>'],
    ['titulek' => 'Kuchyně', 'adresa' => 'kuchyne', 'text' => '<p>Kuchyně na míru – bohaté zkušenosti.</p>'],
]), [['titulek' => 'Kuchyně', 'adresa' => 'kuchyne', 'uryvek' => 'Kuchyně na míru – bohaté zkušenosti.']]);
check('Styl::css: bílé pozadí si nese tmavý text i v tmavém režimu', str_contains(Kaleta\Builder\Style::css('#s', ['zaklad' => ['pozadi' => 'bila']]), '--ka-barva-text: var(--ka-barva-text-svetle); color: var(--ka-barva-text-svetle)'), true);
check('Styl::css: vlastní barva textu na bílém pozadí se nepřepíše', str_contains(Kaleta\Builder\Style::css('#s', ['zaklad' => ['pozadi' => 'bila', 'barva' => 'primarni']]), 'text-svetle'), false);
$buildDiscarded = [];
check('Styl::vlastniCss: jen bezpečné deklarace', Kaleta\Builder\Style::customCss('color:red; background:url(javascript:x); --ka-x: 1; @import url(x); width: expression(1); a{b:c}', $buildDiscarded), 'color: red; --ka-x: 1;');
check('Styl::vlastniCss: zahozené se hlásí', count($buildDiscarded), 4);
check('DesignSystem::kontrast: černá na bílé', round(Kaleta\Builder\DesignSystem::contrast('#ffffff', '#000000'), 1), 21.0);
// 2.1: every stored token has an English name that reads it again on styled elements (follows a token overridden in a class)
$dsCss = Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::DEFAULTS);
preg_match_all('/^\t(--ka-[a-z0-9-]+):/m', substr($dsCss, 0, (int) strpos($dsCss, '@media')), $dsStored);
check('2.1: English token names cover every stored token', [array_values(array_diff($dsStored[1], Kaleta\Builder\DesignSystem::englishTokens(), ['--ka-akcent'])),
    str_contains($dsCss, ":where(:root, [class], [id], [style]) {\n\t--ka-color-primary: var(--ka-barva-primarni);"), str_contains($dsCss, '--ka-type-lead: var(--ka-typ-perex);'),
    count(array_unique(array_keys(Kaleta\Builder\DesignSystem::englishTokens()))) === count(array_unique(Kaleta\Builder\DesignSystem::englishTokens()))], [[], true, true, true]);
// 2.1: a page without its own description gets the start of its first longer paragraph
check('2.1: description from the first longer paragraph', [Kaleta\Front\Kernel::descriptionFrom('<h1>Hi</h1><p>Short.</p><p class="x">We build <strong>kitchens</strong> &amp; bathrooms in Zlín   and around it since 1998.</p><p>Later text that is long enough to be picked but comes second.</p>'),
    Kaleta\Front\Kernel::descriptionFrom('<p>tiny</p>'), mb_strlen(Kaleta\Front\Kernel::descriptionFrom('<p>' . str_repeat('word ', 80) . '</p>'))],
    ['We build kitchens & bathrooms in Zlín and around it since 1998.', '', 160]);
// 2.1: security.txt (RFC 9116) from the Security contact setting
check('2.1: security.txt', [Kaleta\Front\Seo::securityTxt('security@example.com', 'https://example.com/', ['en', 'de'], 1790000000),
    Kaleta\Front\Seo::securityTxt('https://example.com/security', 'https://example.com/web', [], 1790000000)],
    ["Contact: mailto:security@example.com\nExpires: 2027-03-23T00:00:00Z\nPreferred-Languages: en, de\nCanonical: https://example.com/.well-known/security.txt\n",
    "Contact: https://example.com/security\nExpires: 2027-03-23T00:00:00Z\nCanonical: https://example.com/web/.well-known/security.txt\n"]);
check('DesignSystem::css: pořadí vrstev na začátku', str_starts_with(Kaleta\Builder\DesignSystem::css(Kaleta\Builder\DesignSystem::DEFAULTS), Kaleta\Builder\DesignSystem::LAYERS), true);
check('DesignSystem::vycisti: nesmysl nahradí výchozí', Kaleta\Builder\DesignSystem::sanitize(['barvy' => ['primarni' => 'red;}']])['barvy']['primarni'], Kaleta\Builder\DesignSystem::DEFAULTS['barvy']['primarni']);
