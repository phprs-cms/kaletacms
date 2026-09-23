<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Odkazy z administrace do příručky na webu projektu (https://mirocms.eu).
 *
 * Příručka existuje česky, anglicky a německy; slovenská administrace vede na českou. Stránky se zadávají
 * cestou souboru v docs/prirucka (např. 'provoz/posta'), adresy se překládají stejně jako na webu -
 * tabulka ADRESY musí odpovídat klíči "adresy" v docs/prirucka/osnova.json (hlídá to tools/testy.php).
 */
final class Napoveda
{
    public const string WEB = 'https://mirocms.eu';

    /** @var array<string, array{0:string, 1:array<string,string>}> jazyk => [složka dokumentace, překlad částí cesty] */
    public const array ADRESY = [
        'cs' => ['dokumentace', []],
        'en' => ['docs', [
            'zaciname' => 'getting-started', 'pozadavky' => 'requirements', 'instalace' => 'installation', 'prvni-kroky' => 'first-steps',
            'aktualizace' => 'updates', 'presun-webu' => 'moving-your-site', 'import-z-wordpressu' => 'importing-from-wordpress', 'provoz' => 'operations', 'posta' => 'mail', 'zalohy' => 'backups',
            'ulohy-na-pozadi' => 'background-tasks', 'stav-systemu' => 'system-status', 'bezpecnost' => 'security', 'reseni-potizi' => 'troubleshooting',
            'psani' => 'writing', 'editor' => 'editor', 'obrazky-a-galerie' => 'images-and-galleries', 'vkladani-obsahu' => 'embedding-content',
            'typy-obsahu' => 'content-types', 'rubriky-stitky-serialy' => 'sections-tags-series', 'planovani-a-revize' => 'scheduling-and-revisions',
            'ai-asistent' => 'ai-assistant', 'redakce' => 'newsroom', 'role-a-opravneni' => 'roles-and-permissions',
            'predavka-a-korektura' => 'handoff-and-proofreading', 'titulni-strana-a-kalendar' => 'front-page-and-calendar', 'komentare' => 'comments',
            'ucet-a-prihlaseni' => 'account-and-sign-in',
            'vzhled' => 'appearance', 'sablony' => 'templates', 'identita-webu' => 'site-identity', 'bloky-a-rozvrzeni' => 'blocks-and-layout',
            'uprava-na-webu' => 'editing-on-the-site', 'vlastni-sablona' => 'custom-template', 'ctenari-a-prijmy' => 'readers-and-revenue',
            'ucty-ctenaru' => 'reader-accounts', 'zamceny-obsah' => 'locked-content', 'newsletter' => 'newsletter', 'web-push' => 'web-push',
            'reklama' => 'advertising', 'podpora-a-prijmy' => 'support-and-revenue', 'platby-stripe' => 'payments-with-stripe', 'jazykove-verze' => 'language-versions',
            'jazyky-webu' => 'site-languages', 'preklad-obsahu' => 'translating-content', 'seo-a-ai' => 'seo-and-ai', 'seo' => 'seo',
            'ai-vyhledavace' => 'ai-search-engines', 'napojeni-na-claude' => 'connecting-claude', 'mereni-a-soukromi' => 'analytics-and-privacy',
            'pro-vyvojare' => 'for-developers', 'struktura-projektu' => 'project-structure', 'zasady' => 'principles',
            'testy-a-vydavani' => 'tests-and-releases', 'jak-prispet' => 'contributing',
        ]],
        'de' => ['dokumentation', [
            'zaciname' => 'erste-schritte', 'pozadavky' => 'voraussetzungen', 'instalace' => 'installation', 'prvni-kroky' => 'nach-der-installation',
            'aktualizace' => 'aktualisierungen', 'presun-webu' => 'umzug', 'import-z-wordpressu' => 'import-aus-wordpress', 'provoz' => 'betrieb', 'posta' => 'e-mail', 'zalohy' => 'backups',
            'ulohy-na-pozadi' => 'hintergrundaufgaben', 'stav-systemu' => 'systemstatus', 'bezpecnost' => 'sicherheit', 'reseni-potizi' => 'fehlerbehebung',
            'psani' => 'schreiben', 'editor' => 'editor', 'obrazky-a-galerie' => 'bilder-und-galerien', 'vkladani-obsahu' => 'inhalte-einbetten',
            'typy-obsahu' => 'inhaltstypen', 'rubriky-stitky-serialy' => 'rubriken-schlagwoerter-serien', 'planovani-a-revize' => 'planung-und-versionen',
            'ai-asistent' => 'ki-assistent', 'redakce' => 'redaktion', 'role-a-opravneni' => 'rollen-und-rechte',
            'predavka-a-korektura' => 'uebergabe-und-korrektur', 'titulni-strana-a-kalendar' => 'titelseite-und-kalender', 'komentare' => 'kommentare',
            'ucet-a-prihlaseni' => 'konto-und-anmeldung',
            'vzhled' => 'design', 'sablony' => 'vorlagen', 'identita-webu' => 'website-identitaet', 'bloky-a-rozvrzeni' => 'bloecke-und-layout',
            'uprava-na-webu' => 'bearbeiten-auf-der-website', 'vlastni-sablona' => 'eigene-vorlage', 'ctenari-a-prijmy' => 'leser-und-einnahmen',
            'ucty-ctenaru' => 'leserkonten', 'zamceny-obsah' => 'gesperrte-inhalte', 'newsletter' => 'newsletter', 'web-push' => 'web-push',
            'reklama' => 'werbung', 'podpora-a-prijmy' => 'unterstuetzung-und-einnahmen', 'platby-stripe' => 'zahlungen-mit-stripe', 'jazykove-verze' => 'sprachversionen',
            'jazyky-webu' => 'sprachen-der-website', 'preklad-obsahu' => 'inhalte-uebersetzen', 'seo-a-ai' => 'seo-und-ki', 'seo' => 'seo',
            'ai-vyhledavace' => 'ki-suchmaschinen', 'napojeni-na-claude' => 'anbindung-an-claude', 'mereni-a-soukromi' => 'messung-und-datenschutz',
            'pro-vyvojare' => 'fuer-entwickler', 'struktura-projektu' => 'projektstruktur', 'zasady' => 'grundsaetze',
            'testy-a-vydavani' => 'tests-und-releases', 'jak-prispet' => 'mitwirken',
        ]],
    ];

    /** Adresa stránky příručky v jazyce administrace; prázdná cesta = úvod příručky. */
    public static function url(string $cesta = '', ?string $jazyk = null): string
    {
        $jazyk ??= Jazyk::kod();
        $jazyk = isset(self::ADRESY[$jazyk]) ? $jazyk : ($jazyk === 'sk' ? 'cs' : 'en');
        [$slozka, $preklad] = self::ADRESY[$jazyk];
        $casti = array_map(static fn (string $c): string => $preklad[$c] ?? $c, array_filter(explode('/', $cesta), static fn (string $c): bool => $c !== ''));

        return self::WEB . "/{$jazyk}/{$slozka}/" . ($casti === [] ? '' : implode('/', $casti) . '/');
    }

    /**
     * Které stránky příručky patří ke které obrazovce administrace. Klíč je ident modulu, případně „modul:upřesnění“
     * (záložka Nastavení nebo akce modulu); layout z nich skládá nabídku Nápověda vedle nadpisu stránky.
     * Titulky jsou první nadpisy stránek v docs/prirucka/cs (hlídá tools/testy.php) a překládají se slovníkem administrace.
     */
    public const array TEMATA = [
        'prehled' => ['zaciname/prvni-kroky' => 'První kroky', 'redakce/titulni-strana-a-kalendar' => 'Titulní strana a kalendář'],
        'ucet' => ['redakce/ucet-a-prihlaseni' => 'Účet a přihlášení', 'provoz/bezpecnost' => 'Bezpečnost'],
        'clanky' => ['psani/editor' => 'Editor článku', 'psani/typy-obsahu' => 'Typy obsahu', 'psani/vkladani-obsahu' => 'Vkládání videa a příspěvků ze sítí',
            'psani/planovani-a-revize' => 'Plánování a revize', 'redakce/predavka-a-korektura' => 'Předávka a korektura', 'psani/ai-asistent' => 'AI asistent',
            'jazykove-verze/preklad-obsahu' => 'Překlad obsahu'],
        'clanky:kalendar' => ['redakce/titulni-strana-a-kalendar' => 'Titulní strana a kalendář', 'psani/planovani-a-revize' => 'Plánování a revize'],
        'clanky:titulni' => ['redakce/titulni-strana-a-kalendar' => 'Titulní strana a kalendář'],
        'intergal' => ['psani/obrazky-a-galerie' => 'Obrázky, galerie a přílohy'],
        'topic' => ['psani/rubriky-stitky-serialy' => 'Rubriky, štítky a seriály', 'jazykove-verze/preklad-obsahu' => 'Překlad obsahu'],
        'stitky' => ['psani/rubriky-stitky-serialy' => 'Rubriky, štítky a seriály'],
        'stranky' => ['vzhled/uprava-na-webu' => 'Úprava přímo na webu', 'jazykove-verze/preklad-obsahu' => 'Překlad obsahu'],
        'comment' => ['redakce/komentare' => 'Komentáře'],
        'newsletter' => ['ctenari-a-prijmy/newsletter' => 'Newsletter', 'provoz/posta' => 'Pošta'],
        'ctenari' => ['ctenari-a-prijmy/ucty-ctenaru' => 'Účty čtenářů', 'ctenari-a-prijmy/zamceny-obsah' => 'Zamčený obsah a předplatné',
            'ctenari-a-prijmy/platby-stripe' => 'Platby přes Stripe'],
        'prijmy' => ['ctenari-a-prijmy/podpora-a-prijmy' => 'Podpora a příjmy', 'ctenari-a-prijmy/zamceny-obsah' => 'Zamčený obsah a předplatné',
            'ctenari-a-prijmy/platby-stripe' => 'Platby přes Stripe', 'ctenari-a-prijmy/reklama' => 'Reklama'],
        'reklama' => ['ctenari-a-prijmy/reklama' => 'Reklama'],
        'stat' => ['seo-a-ai/mereni-a-soukromi' => 'Měření a soukromí'],
        'vzhled' => ['vzhled/identita-webu' => 'Identita webu', 'vzhled/sablony' => 'Šablony webu', 'vzhled/vlastni-sablona' => 'Vlastní šablona'],
        'bloky' => ['vzhled/bloky-a-rozvrzeni' => 'Bloky a rozvržení', 'vzhled/sablony' => 'Šablony webu'],
        'users' => ['redakce/role-a-opravneni' => 'Role a oprávnění', 'redakce/ucet-a-prihlaseni' => 'Účet a přihlášení'],
        'presmerovani' => ['seo-a-ai/seo' => 'SEO', 'zaciname/presun-webu' => 'Přesun webu na jiný hosting nebo doménu'],
        'protokol' => ['provoz/bezpecnost' => 'Bezpečnost'],
        'prenos' => ['zaciname/import-z-wordpressu' => 'Import z WordPressu a export webu', 'provoz/zalohy' => 'Zálohy a obnova'],
        'rozsireni' => ['zaciname/prvni-kroky' => 'První kroky', 'ctenari-a-prijmy/web-push' => 'Oznámení v prohlížeči (Web Push)',
            'jazykove-verze/jazyky-webu' => 'Jazyky webu', 'seo-a-ai/napojeni-na-claude' => 'Napojení na Claude'],
        'config' => ['zaciname/prvni-kroky' => 'První kroky', 'jazykove-verze/jazyky-webu' => 'Jazyky webu', 'ctenari-a-prijmy/zamceny-obsah' => 'Zamčený obsah a předplatné'],
        'config:ctenari' => ['ctenari-a-prijmy/platby-stripe' => 'Platby přes Stripe', 'ctenari-a-prijmy/zamceny-obsah' => 'Zamčený obsah a předplatné',
            'ctenari-a-prijmy/ucty-ctenaru' => 'Účty čtenářů', 'redakce/komentare' => 'Komentáře'],
        'config:seo' => ['seo-a-ai/seo' => 'SEO', 'seo-a-ai/ai-vyhledavace' => 'AI vyhledávače', 'seo-a-ai/napojeni-na-claude' => 'Napojení na Claude'],
        'config:mereni' => ['seo-a-ai/mereni-a-soukromi' => 'Měření a soukromí'],
        'config:cookies' => ['seo-a-ai/mereni-a-soukromi' => 'Měření a soukromí', 'redakce/komentare' => 'Komentáře'],
        'config:posta' => ['provoz/posta' => 'Pošta', 'provoz/reseni-potizi' => 'Řešení potíží'],
        'config:zalohy' => ['zaciname/aktualizace' => 'Aktualizace', 'provoz/zalohy' => 'Zálohy a obnova', 'zaciname/presun-webu' => 'Přesun webu na jiný hosting nebo doménu'],
        'config:stav' => ['provoz/stav-systemu' => 'Stav systému', 'provoz/ulohy-na-pozadi' => 'Úlohy na pozadí (cron)', 'provoz/bezpecnost' => 'Bezpečnost',
            'provoz/reseni-potizi' => 'Řešení potíží'],
    ];

    /**
     * Stránky příručky k obrazovce: nejdřív přesný klíč „modul:upřesnění“, jinak modul.
     *
     * @return array<string, string> cesta v příručce => český titulek
     */
    public static function temata(string $modul, string $upresneni = ''): array
    {
        return self::TEMATA[$modul . ':' . $upresneni] ?? self::TEMATA[$modul] ?? [];
    }

    /** Hotový odkaz „Nápověda: …“ pro šablony administrace. */
    public static function odkaz(string $cesta, string $text): string
    {
        return '<a class="napoveda-odkaz" href="' . e(self::url($cesta)) . '" target="_blank" rel="noopener">' . e(t('Nápověda')) . ': ' . e(t($text)) . '</a>';
    }
}
