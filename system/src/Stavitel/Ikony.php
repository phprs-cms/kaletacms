<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

/**
 * Sada čárových ikon pro prvek Ikona: 24×24, tah currentColor – barvu a velikost řídí styl prvku.
 * Kreslené pro MiroCMS jednoduchými tvary, bez cizí knihovny a bez načítání písma s ikonami.
 */
final class Ikony
{
    /** @var array<string, array{0: string, 1: string}> klíč => [název, vnitřek SVG] */
    public const array SADA = [
        'fajfka' => ['Fajfka', '<path d="m5 12.5 4.5 4.5L19 7.5"/>'],
        'fajfka-kruh' => ['Fajfka v kruhu', '<circle cx="12" cy="12" r="9"/><path d="m8 12.5 3 3 5-6"/>'],
        'hvezda' => ['Hvězda', '<path d="m12 3.5 2.6 5.4 5.9.8-4.3 4.1 1 5.8L12 16.8l-5.2 2.8 1-5.8-4.3-4.1 5.9-.8z"/>'],
        'srdce' => ['Srdce', '<path d="M12 20s-7.5-4.6-7.5-10A4.3 4.3 0 0 1 12 7.4 4.3 4.3 0 0 1 19.5 10c0 5.4-7.5 10-7.5 10z"/>'],
        'telefon' => ['Telefon', '<path d="M6.5 3.5h3l1.5 4-2 1.5a11 11 0 0 0 6 6l1.5-2 4 1.5v3a2 2 0 0 1-2 2A16 16 0 0 1 4.5 5.5a2 2 0 0 1 2-2z"/>'],
        'email' => ['E-mail', '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>'],
        'misto' => ['Místo', '<path d="M12 21s-6.5-6.2-6.5-11a6.5 6.5 0 0 1 13 0c0 4.8-6.5 11-6.5 11z"/><circle cx="12" cy="10" r="2.3"/>'],
        'hodiny' => ['Hodiny', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3.5 2"/>'],
        'kalendar' => ['Kalendář', '<rect x="3.5" y="5" width="17" height="15" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>'],
        'clovek' => ['Člověk', '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>'],
        'lide' => ['Lidé', '<circle cx="9" cy="8.5" r="3"/><path d="M3.5 19a5.5 5.5 0 0 1 11 0"/><circle cx="16.5" cy="9.5" r="2.5"/><path d="M15.5 14.2A4.8 4.8 0 0 1 21 19"/>'],
        'dum' => ['Dům', '<path d="M4 11 12 4l8 7"/><path d="M6 9.5V20h12V9.5"/><path d="M10 20v-5h4v5"/>'],
        'stit' => ['Štít', '<path d="M12 3.5 5 6v5.5c0 4.3 3 7.7 7 9 4-1.3 7-4.7 7-9V6z"/>'],
        'stit-fajfka' => ['Štít s fajfkou', '<path d="M12 3.5 5 6v5.5c0 4.3 3 7.7 7 9 4-1.3 7-4.7 7-9V6z"/><path d="m9 12 2 2 4-4.5"/>'],
        'blesk' => ['Blesk', '<path d="M13 3 5.5 13.5H11L10 21l7.5-10.5H12z"/>'],
        'list' => ['List', '<path d="M5 19c0-8 5-13 14-14-1 9-6 14-14 14z"/><path d="M5 19 13 11"/>'],
        'auto' => ['Doprava', '<path d="M3 7h10v9H3zM13 10h4l3 3v3h-7"/><circle cx="7" cy="17.5" r="1.7"/><circle cx="16.5" cy="17.5" r="1.7"/>'],
        'klic' => ['Klíč', '<circle cx="8" cy="15" r="4"/><path d="m11 12 8.5-8.5M16 7l2.5 2.5M14 9l2 2"/>'],
        'naradi' => ['Nářadí', '<path d="M14.5 5.5a4 4 0 0 0-5 5L4 16l4 4 5.5-5.5a4 4 0 0 0 5-5l-2.5 2.5-2.5-.5-.5-2.5z"/>'],
        'bublina' => ['Zpráva', '<path d="M4 5.5h16v10H9l-5 4z"/>'],
        'svet' => ['Svět', '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3c2.5 2.6 3.6 5.6 3.6 9s-1.1 6.4-3.6 9c-2.5-2.6-3.6-5.6-3.6-9S9.5 5.6 12 3z"/>'],
        'zamek' => ['Zámek', '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V7.5a4 4 0 0 1 8 0v3"/>'],
        'penize' => ['Peníze', '<rect x="3" y="6.5" width="18" height="11" rx="2"/><circle cx="12" cy="12" r="2.5"/><path d="M6.5 9.5v5M17.5 9.5v5"/>'],
        'graf' => ['Graf', '<path d="M4 20V4M4 20h16"/><path d="M8 16v-4M12 16V8M16 16v-6"/>'],
        'rust' => ['Růst', '<path d="m4 17 5-5 3.5 3.5L20 8"/><path d="M15 8h5v5"/>'],
        'darek' => ['Dárek', '<rect x="4" y="9" width="16" height="11" rx="1"/><path d="M3 9h18M12 9v11M12 9S10.5 4 8 5.5 9.5 9 12 9zM12 9s1.5-5 4-3.5S14.5 9 12 9z"/>'],
        'fotak' => ['Fotoaparát', '<path d="M4 8h3.5L9 5.5h6L16.5 8H20v11H4z"/><circle cx="12" cy="13.5" r="3.3"/>'],
        'dokument' => ['Dokument', '<path d="M6 3h8l4 4v14H6z"/><path d="M14 3v4h4M9 12h6M9 16h6"/>'],
        'sipka' => ['Šipka', '<path d="M4 12h16M14 6l6 6-6 6"/>'],
        'plus' => ['Znaménko plus', '<path d="M12 5v14M5 12h14"/>'],
        'info' => ['Informace', '<circle cx="12" cy="12" r="9"/><path d="M12 11v5.5M12 7.5v.01"/>'],
        'pozor' => ['Pozor', '<path d="M12 4 2.8 19.5h18.4z"/><path d="M12 10v4.5M12 17v.01"/>'],
        'medaile' => ['Ocenění', '<circle cx="12" cy="9" r="5.5"/><path d="m8.5 13.5-1.5 7 5-2.5 5 2.5-1.5-7"/>'],
        'kufr' => ['Práce', '<rect x="3" y="7" width="18" height="13" rx="2"/><path d="M9 7V4.5h6V7M3 12.5h18"/>'],
        'salek' => ['Šálek', '<path d="M4 9h13v5a5 5 0 0 1-5 5H9a5 5 0 0 1-5-5z"/><path d="M17 10.5h1.5a2.5 2.5 0 0 1 0 5H17M8 3.5v2.5M12 3.5v2.5"/>'],
        'cil' => ['Cíl', '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.2"/>'],
        'raketa' => ['Raketa', '<path d="M12 3c3.5 2 5 5.5 5 9l-2.5 3h-5L7 12c0-3.5 1.5-7 5-9z"/><circle cx="12" cy="9.5" r="1.6"/><path d="M9.5 15 7 19l3-1M14.5 15l2.5 4-3-1"/>'],
        'palec' => ['Palec nahoru', '<path d="M7 11v9H4v-9zM7 11l4-7c1.5 0 2.5 1 2.2 2.6L12.5 10H19a1.5 1.5 0 0 1 1.5 1.8l-1.4 6.5A2 2 0 0 1 17.2 20H7"/>'],
        'zarovka' => ['Nápad', '<path d="M9 17.5h6M10 21h4"/><path d="M12 3a6 6 0 0 0-3.5 10.9c.5.4.5 1.1.5 1.6v1h6v-1c0-.5 0-1.2.5-1.6A6 6 0 0 0 12 3z"/>'],
        'nastaveni' => ['Nastavení', '<circle cx="12" cy="12" r="3"/><path d="M12 2.5v3M12 18.5v3M2.5 12h3M18.5 12h3M5.3 5.3l2.1 2.1M16.6 16.6l2.1 2.1M5.3 18.7l2.1-2.1M16.6 7.4l2.1-2.1"/>'],
    ];

    /** Možnosti pro výběr v editoru (klíč => název). */
    public static function moznosti(): array
    {
        return array_map(fn (array $i): string => $i[0], self::SADA);
    }

    public static function svg(string $klic): string
    {
        $vnitrek = self::SADA[$klic][1] ?? self::SADA['fajfka'][1];

        return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $vnitrek . '</svg>';
    }
}
