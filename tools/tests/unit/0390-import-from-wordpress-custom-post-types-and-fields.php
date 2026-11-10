<?php

declare(strict_types=1);

/* ---------- import from WordPress: custom post types and fields as collections (2.7, tools/fixtures/wordpress-cpt.xml) ---------- */
check('WpTypes::isCustomType: own types yes, WordPress and plugin internals no', array_map(Kaleta\Core\WpTypes::isCustomType(...), ['reference', 'team_member', 'product', 'page', 'nav_menu_item', 'wp_block', 'acf-field', 'breakdance_template', 'shop_order', 'wpcf7_contact_form', 'Bad Type!']),
    [true, true, true, false, false, false, false, false, false, false, false]);
check('WpTypes::fields: ACF fields always, plugin and underscore meta never', Kaleta\Core\WpTypes::fields(['klient' => 'A', '_klient' => 'field_1', 'rank_math_title' => 'x', '_edit_lock' => '1', 'ekit_views' => '3', 'cena' => '100', 'site-sidebar-layout' => 'x']),
    ['klient' => 'A', 'cena' => '100']);
check('WpTypes::guessType', array_map(fn (array $c): ?string => Kaleta\Core\WpTypes::guessType($c[0], $c[1], [301 => 'https://x/a.jpg']), [
    ['fotka', '301'], ['logo_firmy', '77'], ['pocet', '77'], ['x', 'https://old.example/a/b.png?v=2'], ['datum', '20240315'], ['datum', '20241345'], ['web', 'https://novakovi.example'],
    ['cena', '1 200'], ['cena', '1200,50'], ['popis', '<p>Hi</p>'], ['adresa', "Ulice 1\nMěsto"], ['jmeno', 'Jana'], ['galerie', 'a:2:{i:0;s:3:"301";}'], ['x', '']]),
    ['obrazek', 'obrazek', 'cislo', 'obrazek', 'datum', 'cislo', 'odkaz', 'text', 'cislo', 'html', 'radky', 'text', null, 'text']);
check('WpTypes::fieldType, date, label, prefix', [Kaleta\Core\WpTypes::fieldType(['text' => 3, 'radky' => 1]), Kaleta\Core\WpTypes::fieldType(['obrazek' => 1, 'text' => 0]), Kaleta\Core\WpTypes::fieldType([]),
    Kaleta\Core\WpTypes::date('20240315'), Kaleta\Core\WpTypes::date('2024-02-30'), Kaleta\Core\WpTypes::label('team_member-role'), Kaleta\Core\WpTypes::prefix('https://a.cz/reference/kuchyne/'), Kaleta\Core\WpTypes::prefix('https://a.cz/?p=4')],
    ['radky', 'obrazek', 'text', '2024-03-15', '', 'Team member role', 'reference', '']);
$cptPath = KALETA_ROOT . '/tools/fixtures/wordpress-cpt.xml';
$cptItems = iterator_to_array((new Kaleta\Core\WpFile($cptPath))->items());
check('WpSoubor: fields of a custom post type are read, of other types not', [array_keys($cptItems[1]['pole']), $cptItems[0]['pole']],
    [['klient', '_klient', 'rok_dokonceni', '_rok_dokonceni', 'datum_predani', '_datum_predani', 'web_klienta', '_web_klienta', 'fotka', '_fotka', 'galerie', '_galerie', 'rank_math_seo_score', 'ekit_post_views_count'], []]);
$cptState = Kaleta\Core\WpImport::newState('wordpress-cpt.xml');
Kaleta\Core\WpImport::analyze($cptState, 30, $cptPath);
check('WpImport náhled: a custom post type with its fields, address and what is left out', [$cptState['prehled']['typy'], $cptState['prehled']['jine']], [['reference' => [
    'pocet' => 2, 'predpony' => ['reference' => 2], 'pole' => ['klient' => ['text' => 2], 'rok_dokonceni' => ['cislo' => 2], 'datum_predani' => ['datum' => 2], 'web_klienta' => ['odkaz' => 1], 'fotka' => ['obrazek' => 1, 'text' => 0]],
    'vynechano' => ['galerie' => true], 'obsah' => true, 'perex' => false, 'woo' => false, 'kategorie' => []]], []]);

