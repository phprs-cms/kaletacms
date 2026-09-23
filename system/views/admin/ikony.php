<?php
/**
 * Sada ikon administrace (čárové, 24x24). Použití: $ikona('clanek').
 *
 * @return callable(string): string
 */
$cesty = [
    'prehled' => '<rect x="3" y="3" width="7" height="9" rx="1.5"/><rect x="14" y="3" width="7" height="5" rx="1.5"/><rect x="14" y="12" width="7" height="9" rx="1.5"/><rect x="3" y="16" width="7" height="5" rx="1.5"/>',
    'clanek' => '<path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"/><path d="M14 3v5h5"/><path d="M9 13h6M9 17h6"/>',
    'media' => '<rect x="3" y="4" width="18" height="16" rx="2"/><circle cx="9" cy="10" r="1.6"/><path d="m21 16-5-5-8 8"/>',
    'stranky' => '<path d="M6 3h9l4 4v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M8 12h8M8 16h5"/>',
    'rubriky' => '<path d="M4 6a2 2 0 0 1 2-2h4l2 3h6a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2z"/>',
    'novinky' => '<path d="M3 11v2a1 1 0 0 0 1 1h2l5 4V6L6 10H4a1 1 0 0 0-1 1z"/><path d="M15.5 8.5a5 5 0 0 1 0 7M18.5 6a8.5 8.5 0 0 1 0 12"/>',
    'bloky' => '<rect x="3" y="3" width="18" height="6" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/><rect x="15" y="13" width="6" height="8" rx="1.5"/>',
    'uzivatele' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.5a6.5 6.5 0 0 1 3.5 5.5"/>',
    'nastaveni' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.9 2.9l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 0 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.9-2.9l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 0 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.9-2.9l.1.1a1.7 1.7 0 0 0 1.8.3h0a1.7 1.7 0 0 0 1-1.5V3a2 2 0 0 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.9 2.9l-.1.1a1.7 1.7 0 0 0-.3 1.8v0a1.7 1.7 0 0 0 1.5 1H21a2 2 0 0 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    'presmerovani' => '<path d="M4 7h11a4 4 0 0 1 0 8H9"/><path d="m12 12-3 3 3 3"/><path d="M4 4v6"/>',
    'komentare' => '<path d="M21 12a8 8 0 0 1-11.6 7.1L4 20l1-4.6A8 8 0 1 1 21 12z"/>',
    'ankety' => '<path d="M5 20V10M12 20V4M19 20v-7"/>',
    'statistika' => '<path d="M3 3v18h18"/><path d="m7 15 4-5 3 3 5-7"/>',
    'reklama' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M7 15l2.2-6 2.3 6M7.8 13h3M14 9v6h1.5a3 3 0 0 0 0-6z"/>',
    'rozsireni' => '<path d="M10 3h4v4h4a1 1 0 0 1 1 1v3h-2a2 2 0 1 0 0 4h2v3a1 1 0 0 1-1 1h-4v-2a2 2 0 1 0-4 0v2H6a1 1 0 0 1-1-1v-4H3v-4h2V8a1 1 0 0 1 1-1h4z"/>',
    'protokol' => '<path d="M8 6h12M8 12h12M8 18h12"/><circle cx="4" cy="6" r="1"/><circle cx="4" cy="12" r="1"/><circle cx="4" cy="18" r="1"/>',
    'ctenari' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/><path d="M17 3.5l1 2 2 .3-1.5 1.4.4 2-1.9-1-1.9 1 .4-2L14 5.800l2-.3z" stroke-width="1.2"/>',
    'stitky' => '<path d="M3 12V4a1 1 0 0 1 1-1h8l9 9-9 9z"/><circle cx="8" cy="8" r="1.4"/>',
    'newsletter' => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>',
    'identita' => '<circle cx="12" cy="12" r="9"/><circle cx="8.5" cy="10" r="1.2"/><circle cx="12" cy="7.5" r="1.2"/><circle cx="15.5" cy="10" r="1.2"/><path d="M12 21a3 3 0 0 1 0-6h2a2.5 2.5 0 0 0 2.5-2.5"/>',
    'web' => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3a14 14 0 0 1 0 18M12 3a14 14 0 0 0 0 18"/>',
    'odhlasit' => '<path d="M9 21H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h3"/><path d="m16 17 5-5-5-5M21 12H9"/>',
    'tema' => '<circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/>',
    // bloky ve vizuálním editoru (Bloky::KATALOG)
    'b-otvirak' => '<rect x="3" y="4" width="18" height="10" rx="1.5"/><path d="M3 18h12M3 21h7"/>',
    'b-seznam' => '<rect x="3" y="4.5" width="4" height="4" rx="1"/><rect x="3" y="13.5" width="4" height="4" rx="1"/><path d="M10 5.5h11M10 8h7M10 14.500h11M10 17h7"/>',
    'b-nejctenejsi' => '<path d="M3 17l5-5 4 3 8-9"/><path d="M15 6h5v5"/><path d="M3 21h18"/>',
    'b-hash' => '<path d="M9 3 7 21M17 3l-2 18M4 9h17M3 15h17"/>',
    'b-archiv' => '<rect x="3" y="4" width="18" height="5" rx="1.5"/><path d="M5 9v9a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V9"/><path d="M10 13h4"/>',
    'b-autori' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20a7 7 0 0 1 14 0"/>',
    'b-menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
    'b-hledani' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.300-4.300"/>',
    'b-anketa' => '<path d="M4 6h9M4 12h16M4 18h6"/><circle cx="17" cy="6" r="1.2"/><circle cx="14" cy="18" r="1.2"/>',
    'b-srdce' => '<path d="M12 20s-7.500-4.600-7.500-10A4.300 4.300 0 0 1 12 7.400 4.300 4.300 0 0 1 19.500 10c0 5.400-7.500 10-7.500 10z"/>',
    'b-zvonek' => '<path d="M6 16V11a6 6 0 0 1 12 0v5l2 2H4z"/><path d="M10 21a2 2 0 0 0 4 0"/>',
    'b-ucet' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="10" r="3"/><path d="M6.500 18.500a6 6 0 0 1 11 0"/>',
    'b-site' => '<circle cx="6" cy="12" r="2.500"/><circle cx="18" cy="6" r="2.500"/><circle cx="18" cy="18" r="2.500"/><path d="m8.200 10.900 7.600-3.800M8.200 13.100l7.600 3.800"/>',
    'b-kontakt' => '<path d="M4 5h16v11H9l-5 4z"/><path d="M8 9.500h8M8 12.500h5"/>',
    'b-text' => '<path d="M5 6V4h14v2M12 4v16M9 20h6"/>',
    'napoveda' => '<circle cx="12" cy="12" r="9"/><path d="M9.6 9.2a2.5 2.5 0 1 1 3.6 2.3c-.8.4-1.2 1-1.2 1.9"/><path d="M12 17h.01"/>',
    'ven' => '<path d="M14 5h5v5"/><path d="M19 5 10 14"/><path d="M18 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4"/>',
];

return fn (string $klic): string => '<svg class="ikona" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($cesty[$klic] ?? $cesty['clanek']) . '</svg>';
