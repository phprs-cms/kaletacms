<?php
/**
 * Seznam snímků do příručky pro tools/snimky-prirucky.php.
 *
 * název souboru => ['adresa' => adresa od kořene instance (řetězec, nebo pole podle jazyka – chybějící jazyk se nefotí),
 *                   'vyska' => výška okna, 'orez' => [x, y, šířka, výška] (nepovinné; výchozí je celé okno 1280 × výška)]
 * Článek ve formuláři se liší podle jazyka, aby obsah snímku odpovídal jazyku příručky (čísla platí pro ukázkový obsah vývojové instance).
 */

declare(strict_types=1);

$clanek = ['cs' => 2, 'en' => 16, 'de' => 28];
$uprava = array_map(static fn (int $id): string => 'admin.php?modul=clanky&akce=edit&id=' . $id, $clanek);

return [
    // Začínáme
    'prehled' => ['adresa' => 'admin.php', 'vyska' => 900],
    'napoveda-k-obrazovce' => ['adresa' => 'admin.php?modul=clanky&_otevri=napoveda', 'vyska' => 620],
    'rozsireni' => ['adresa' => 'admin.php?modul=rozsireni', 'vyska' => 1000],
    'aktualizace-a-zalohy' => ['adresa' => 'admin.php?modul=config&zalozka=zalohy', 'vyska' => 1100],
    'import-z-wordpressu' => ['adresa' => 'admin.php?modul=prenos', 'vyska' => 760],
    // Psaní
    'editor-clanku' => ['adresa' => $uprava, 'vyska' => 1250],
    'vypis-clanku' => ['adresa' => 'admin.php?modul=clanky', 'vyska' => 900],
    'media' => ['adresa' => 'admin.php?modul=intergal', 'vyska' => 900],
    'rubriky' => ['adresa' => 'admin.php?modul=topic', 'vyska' => 800],
    // Redakce
    'redakcni-kalendar' => ['adresa' => 'admin.php?modul=clanky&akce=kalendar', 'vyska' => 900],
    'titulni-strana' => ['adresa' => 'admin.php?modul=clanky&akce=titulni', 'vyska' => 900],
    'uzivatel-opravneni' => ['adresa' => 'admin.php?modul=users&akce=edit&id=12', 'vyska' => 1100],
    'muj-ucet' => ['adresa' => 'admin.php?akce=ucet', 'vyska' => 1300],
    'komentare' => ['adresa' => 'admin.php?modul=comment', 'vyska' => 700],
    // Vzhled
    'identita-webu' => ['adresa' => 'admin.php?modul=vzhled', 'vyska' => 1300],
    'editor-bloku' => ['adresa' => '?upravit=1', 'vyska' => 1000],
    // Čtenáři a příjmy
    'ctenari-a-platby' => ['adresa' => 'admin.php?modul=config&zalozka=ctenari', 'vyska' => 1500],
    'ctenari' => ['adresa' => 'admin.php?modul=ctenari', 'vyska' => 700],
    'prijmy' => ['adresa' => 'admin.php?modul=prijmy', 'vyska' => 800],
    'newsletter' => ['adresa' => 'admin.php?modul=newsletter', 'vyska' => 900],
    'reklama' => ['adresa' => 'admin.php?modul=reklama', 'vyska' => 700],
    // Jazykové verze, SEO a AI
    'nastaveni-zakladni' => ['adresa' => 'admin.php?modul=config&zalozka=zakladni', 'vyska' => 1300],
    'nastaveni-seo' => ['adresa' => 'admin.php?modul=config&zalozka=seo', 'vyska' => 1200],
    'soukromi-a-cookies' => ['adresa' => 'admin.php?modul=config&zalozka=cookies', 'vyska' => 1200],
    // Provoz
    'stav-systemu' => ['adresa' => 'admin.php?modul=config&zalozka=stav', 'vyska' => 1200],
    'posta' => ['adresa' => 'admin.php?modul=config&zalozka=posta', 'vyska' => 1100],
];
