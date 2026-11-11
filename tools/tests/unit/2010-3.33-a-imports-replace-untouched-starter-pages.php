<?php

declare(strict_types=1);

use Kaleta\Core\StarterPages;
use Kaleta\Core\WebImport;
use Kaleta\Core\WpImport;

/* ---------- 3.33 part A (M2): imports replace untouched starter pages, the choice in the preview ---------- */
$i333Choices = [
    ['old' => '/', 'page_id' => 1, 'title' => 'Úvod', 'address' => '/uvod', 'replace' => true, 'why' => ''],
    ['old' => '/kontakt', 'page_id' => 4, 'title' => 'Kontakt', 'address' => '/kontakt', 'replace' => true, 'why' => ''],
    ['old' => '/sluzby', 'page_id' => 3, 'title' => 'Služby', 'address' => '/sluzby', 'replace' => false, 'why' => 'edited'],
];
check('3.33 M2 keep_starter_pages: old addresses (with or without slashes), the starter page\'s own address, "*" or true = all; an edited page is never "kept" twice', [
    StarterPages::keepFromAddresses($i333Choices, ['kontakt/']), StarterPages::keepFromAddresses($i333Choices, ['/uvod']), StarterPages::keepFromAddresses($i333Choices, ['*']),
    StarterPages::keepFromAddresses($i333Choices, true), StarterPages::keepFromAddresses($i333Choices, '/kontakt'), StarterPages::keepFromAddresses($i333Choices, ['/sluzby']),
], [[4], [1], [1, 4], [1, 4], [], []]);
check('3.33 M2: the website import asks for the same address in the preview as when it creates the page', [
    WebImport::plannedSlug('', 'Vítejte'), WebImport::plannedSlug('o-nas', 'O nás'), WebImport::plannedSlug('kontakt.html', 'x'), WebImport::plannedSlug('sluzby/kotle', 'x'), WebImport::plannedSlug('de/sluzby/kotle.html', 'x', 'de'),
], ['home', 'o-nas', 'kontakt', 'sluzby/kotle', 'sluzby/kotle']);
check('3.33 M2: a WordPress page asks for its slug, else its title; the front page is the root link without ?page_id=', [
    WpImport::plannedSlug(['adresa' => 'kontakt', 'titulek' => 'Kontaktujte nás']), WpImport::plannedSlug(['adresa' => '', 'titulek' => 'Kontaktujte nás']),
    WpImport::isHomeLink('https://www.example.cz/'), WpImport::isHomeLink('https://www.example.cz'), WpImport::isHomeLink('https://www.example.cz/?page_id=5'),
    WpImport::isHomeLink('https://www.example.cz/kontakt/'), WpImport::isHomeLink(''),
], ['kontakt', 'kontaktujte-nas', true, true, false, false, false]);
check('3.33 M2: the import result in English – replaced and kept with the reason', StarterPages::summary(['nahrazeno' => [['/kontakt', 'Kontakt', '/kontakt-sample']], 'ponechano' => [['/sluzby', 'Služby', '/sluzby', 'edited']]]), [
    'replaced' => [['old' => '/kontakt', 'title' => 'Kontakt', 'address' => '/kontakt-sample']],
    'kept' => [['old' => '/sluzby', 'title' => 'Služby', 'address' => '/sluzby', 'why' => 'someone edited it, so it is never replaced – the imported page got a number']],
]);
