<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Collections;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;
use Kaleta\Core\Language;

/**
 * Store locator (2.11): the visible branches of a collection – by default the first one made from the Branches preset – as a
 * list with the address, a tel: link, the e-mail and a "Directions" link, usable without JavaScript. image/web.js adds a
 * search box filtering the list as you type, "Nearest to me" (navigator.geolocation only after the click, sorted by the
 * haversine distance, nothing sent anywhere) and a Leaflet map with OpenStreetMap tiles that loads only after a click, like
 * the Map element (3.18.2: or at once, and as a map beside a side panel with the search and a scrolling list, where a branch
 * and its marker point at each other). Each branch carries data-lat/data-lng for the script; every text the script shows is rendered here with
 * t(), so the site dictionary translates it.
 */
final class StoreLocator extends Element
{
    public const string TYPE = 'pobocky';
    public const string NAME = 'Store locator';
    public const string DESCRIPTION = 'Branches or stores from a collection: a list with directions, a search box, “Nearest to me” and a map that loads after a click.';
    public const string ICON = 'mapa';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['div', 'section'];

    /** Where the vendored Leaflet lives (image/vendor/leaflet/leaflet.js, leaflet.css, images/); the script loads it after the click. */
    public const string LEAFLET_PATH = 'image/vendor/leaflet/';


    /** The "Nearest to me" button of the side panel: a location crosshair (the label is in aria-label and title). */
    private const string LOCATE_ICON = '<svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="7"/><circle cx="12" cy="12" r="2.5" fill="currentColor"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>';

    public static function properties(): array
    {
        return [
            'kolekce' => ['typ' => 'text', 'popisek' => 'Collection (empty = the first Branches collection)', 'vychozi' => '', 'max' => 110],
            'pole_poloha' => ['typ' => 'text', 'popisek' => 'Location field (key)', 'vychozi' => 'location', 'max' => 31],
            'hledani' => ['typ' => 'prepinac', 'popisek' => 'Search box', 'vychozi' => true],
            'nejblizsi' => ['typ' => 'prepinac', 'popisek' => '“Nearest to me” button', 'vychozi' => true],
            'mapa' => ['typ' => 'prepinac', 'popisek' => 'Map (loads after a click)', 'vychozi' => true],
            // 3.18.2: the map beside a side panel with the search and a scrolling list (like a store locator of a maps platform)
            'rozlozeni' => ['typ' => 'vyber', 'popisek' => 'Layout', 'vychozi' => 'seznam', 'moznosti' => ['seznam' => 'List with the map above it', 'panel' => 'Map with a side panel']],
            'mapa_hned' => ['typ' => 'prepinac', 'popisek' => 'Load the map at once (the visitor’s browser contacts OpenStreetMap when the page opens)', 'vychozi' => false],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-pobocky { display: grid; gap: var(--ka-mezera-m); }
.ka-pobocky [hidden] { display: none; } /* the display of the controls and the cards below would otherwise beat the hidden attribute */
.ka-pobocky-ovladani { display: flex; flex-wrap: wrap; gap: var(--ka-mezera-xs); align-items: center; }
.ka-pobocky-ovladani label { flex: 1 1 12rem; display: grid; gap: 0.25em; min-width: 0; }
.ka-pobocky-ovladani input { width: 100%; min-width: 0; padding: 0.55em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni-s); background: var(--ka-barva-pozadi); color: inherit; font: inherit; }
.ka-pobocky-ovladani button { padding: 0.55em 1em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni-plne); background: var(--ka-barva-plocha); color: inherit; font: inherit; cursor: pointer; }
.ka-pobocky-ovladani button:hover { border-color: var(--ka-barva-primarni); }
.ka-pobocky-ovladani button[disabled] { opacity: 0.6; cursor: wait; }
.ka-pobocky-ovladani small { flex-basis: 100%; color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-pobocky-zprava { margin: 0; color: var(--ka-barva-tlumeny); }
.ka-pobocky-zprava:empty { display: none; }
.ka-pobocky-mapa { height: clamp(16rem, 50vh, 28rem); border-radius: var(--ka-zaobleni-m); overflow: hidden; background: var(--ka-barva-plocha); }
.ka-pobocky-mapa a { color: inherit; }
.ka-pobocky-seznam { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 16rem), 1fr)); gap: var(--ka-mezera-m); margin: 0; padding: 0; list-style: none; }
.ka-pobocky-seznam li { display: grid; gap: var(--ka-mezera-xs); align-content: start; padding: var(--ka-mezera-m); border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni-m); background: var(--ka-barva-plocha); overflow-wrap: anywhere; }
.ka-pobocky-seznam p { margin: 0; }
.ka-pobocky-nazev { font-size: var(--ka-krok-1); }
.ka-pobocky-nazev a { color: inherit; }
.ka-pobocky-vzdalenost { color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-pobocky-vzdalenost:empty { display: none; }
.ka-pobocky--panel { grid-template-columns: minmax(16rem, 24rem) minmax(0, 1fr); align-items: stretch; height: clamp(30rem, 78vh, 46rem); }
.ka-pobocky-panel { display: flex; flex-direction: column; gap: var(--ka-mezera-s); min-height: 0; }
.ka-pobocky--panel .ka-pobocky-seznam { flex: 1 1 auto; min-height: 0; overflow-y: auto; grid-template-columns: 1fr; gap: var(--ka-mezera-xs); padding-inline-end: var(--ka-mezera-2xs); overscroll-behavior: contain; }
.ka-pobocky--panel .ka-pobocky-seznam li { padding: var(--ka-mezera-s); cursor: pointer; }
.ka-pobocky--panel .ka-pobocky-seznam li[aria-current] { border-color: var(--ka-barva-primarni); box-shadow: inset 3px 0 0 var(--ka-barva-primarni); }
.ka-pobocky--panel .ka-pobocky-nazev { font-size: var(--ka-krok-0); }
.ka-pobocky--panel .ka-pobocky-mapa { height: auto; min-height: 100%; display: grid; place-items: center; align-content: center; gap: var(--ka-mezera-s); padding: 0; text-align: center; }
.ka-pobocky-mapa > button { padding: 0.65em 1.2em; border: 0; border-radius: var(--ka-zaobleni-plne); background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); font: inherit; cursor: pointer; }
.ka-pobocky-mapa > small { max-width: 26rem; color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-pobocky-mapa .leaflet-popup-content p { margin: 0.25em 0; }
.ka-pobocky--panel .ka-pobocky-ovladani { flex-wrap: nowrap; align-items: stretch; }
.ka-pobocky--panel .ka-pobocky-ovladani label { flex: 1 1 auto; }
.ka-pobocky-ovladani .ka-pobocky-poloha { display: grid; place-items: center; flex: 0 0 auto; width: 2.75rem; padding: 0; }
/* the markers are drawn in the site colour (image/web.js): a site sets its own with --ka-pobocky-pin in a class of the element */
.ka-pobocky-pin { color: var(--ka-pobocky-pin, var(--ka-barva-primarni)); }
.ka-pobocky-pin svg { display: block; width: 28px; height: 40px; filter: drop-shadow(0 2px 2px rgb(0 0 0 / 0.35)); }
.ka-pobocky-na-mape { justify-self: start; padding: 0; border: 0; background: none; color: var(--ka-barva-primarni); font: inherit; font-size: var(--ka-krok--1); text-decoration: underline; cursor: pointer; }
@media (max-width: 767px) {
	.ka-pobocky--panel { grid-template-columns: 1fr; height: auto; }
	.ka-pobocky--panel .ka-pobocky-mapa { order: -1; min-height: 55vh; }
	.ka-pobocky--panel .ka-pobocky-seznam { max-height: 60vh; }
}';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $db = $k->app->db();
        $collection = $o['kolekce'] !== '' ? Collections::bySlug($db, (string) $o['kolekce']) : self::defaultCollection($db);
        if ($collection === null) {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">'
                . e(t('Create a Branches collection first (Collections → New branches), or choose a collection in the Content panel.')) . '</div>' : '';
        }
        $field = fn (string $key, array $types): ?string => array_values(array_filter($collection['pole'], fn (array $f): bool => $f['klic'] === $key && in_array($f['typ'], $types, true)))[0]['klic'] ?? null;
        // the location field from the option, or the first location field the collection has; the contact fields by their preset keys
        $locationKey = preg_match(Collections::KEY_PATTERN, (string) $o['pole_poloha']) ? $field((string) $o['pole_poloha'], ['poloha']) : null;
        $locationKey ??= array_values(array_filter($collection['pole'], fn (array $f): bool => $f['typ'] === 'poloha'))[0]['klic'] ?? null;
        // the keys of the Branches preset, or the Czech keys a collection made by hand gets from its labels (Adresa, Telefon, E-mail)
        $keys = ['address' => $field('address', ['text', 'radky']) ?? $field('adresa', ['text', 'radky']), 'phone' => $field('phone', ['text']) ?? $field('telefon', ['text']),
            'email' => $field('email', ['text']) ?? $field('e_mail', ['text'])];
        [$items] = Collections::items($db, (int) $collection['idk'], Language::siteColumn(), Collections::MAX_ITEMS);
        if ($items === []) {
            return $k->editor ? '<div' . $a . ' style="padding:2rem;text-align:center;background:var(--ka-barva-plocha)">' . e(t('The collection has no visible items yet.')) . '</div>' : '';
        }
        $rows = '';
        foreach ($items as $item) {
            $values = Collections::values($collection, $item, $k->url(...), $db);
            $rows .= self::row($values, $keys, $locationKey);
        }
        $panel = $o['mapa'] && $o['rozlozeni'] === 'panel';
        $controls = '';
        if ($o['hledani']) {
            // 3.18.2: in the side panel the label is for screen readers only and its text is the placeholder – one line with the location button
            $controls .= $panel
                ? '<label><span class="ka-jen-ctecka">' . e(t('Search branches')) . '</span><input type="search" data-hledat placeholder="' . e(t('Search branches')) . '" autocomplete="off"></label>'
                : '<label><span>' . e(t('Search branches')) . '</span><input type="search" data-hledat placeholder="' . e(t('Name or address')) . '" autocomplete="off"></label>';
        }
        if ($o['nejblizsi']) {
            $controls .= $panel
                ? '<button type="button" class="ka-pobocky-poloha" data-nejblizsi aria-label="' . e(t('Nearest to me')) . '" title="' . e(t('Nearest to me')) . '">' . self::LOCATE_ICON . '</button>'
                : '<button type="button" data-nejblizsi>' . e(t('Nearest to me')) . '</button>';
        }
        $atOnce = $o['mapa'] && $o['mapa_hned'];
        $note = '<small>' . e(t('The map loads from OpenStreetMap after a click; “Nearest to me” asks for your location only then and sends it nowhere.')) . '</small>';
        $map = '';
        if ($o['mapa']) {
            $mapId = 'pobocky-mapa-' . e((string) $p['id']);
            $button = '<button type="button" data-mapa aria-controls="' . $mapId . '">' . e(t('Show map')) . '</button>';
            // the side panel keeps the map's place from the start: the button (or the map loaded at once) sits in it
            if (!$atOnce && !$panel) {
                $controls .= $button . $note;
            }
            $map = '<div class="ka-pobocky-mapa" id="' . $mapId . '" role="region" aria-label="' . e(t('Map of branches')) . '"' . ($panel || $atOnce ? '' : ' hidden') . '>'
                . ($panel && !$atOnce ? $button . $note : '') . '</div>';
        }
        // the controls work only with the script, so they appear once it runs; the list is the content and is always there
        $list = ($controls !== '' ? '<div class="ka-pobocky-ovladani" hidden>' . $controls . '</div>' : '')
            . '<p class="ka-pobocky-zprava" role="status" aria-live="polite" data-zprava></p>'
            . '<ul class="ka-pobocky-seznam" aria-label="' . e(t('Branches')) . '">' . $rows . '</ul>'
            . '<p class="ka-pobocky-zprava" data-prazdne hidden>' . e(t('No branch matches your search.')) . '</p>';
        $html = $panel ? '<div class="ka-pobocky-panel">' . $list . '</div>' . $map : (
            ($controls !== '' ? '<div class="ka-pobocky-ovladani" hidden>' . $controls . '</div>' : '')
            . '<p class="ka-pobocky-zprava" role="status" aria-live="polite" data-zprava></p>'
            . $map
            . '<ul class="ka-pobocky-seznam" aria-label="' . e(t('Branches')) . '">' . $rows . '</ul>'
            . '<p class="ka-pobocky-zprava" data-prazdne hidden>' . e(t('No branch matches your search.')) . '</p>');
        $data = ' data-pobocky' . ($atOnce ? ' data-mapa-hned' : '') . ' data-leaflet="' . e($k->url(self::LEAFLET_PATH)) . '"'
            . ' data-atribuce="' . e('© OpenStreetMap contributors') . '"' // plain text: image/web.js puts it in a link to the OSM copyright page
            . ' data-text-serazeno="' . e(t('Sorted by distance from you.')) . '"'
            . ' data-text-odmitnuto="' . e(t('Location access was refused – the list stays in its usual order.')) . '"'
            . ' data-text-chyba="' . e(t('Your location could not be determined.')) . '"'
            . ' data-text-na-mape="' . e(t('Show on map')) . '"';

        return '<' . $p['znacka'] . Text::withClass($a, $panel ? 'ka-pobocky ka-pobocky--panel' : 'ka-pobocky') . $data . '>' . $html . '</' . $p['znacka'] . '>';
    }

    /** The first collection made from the Branches preset (what the element shows when no collection is chosen). */
    public static function defaultCollection(\Kaleta\Core\Db $db): ?array
    {
        $slug = $db->value('SELECT seo_link FROM {kolekce} WHERE preset = ? ORDER BY idk LIMIT 1', ['branches']);

        return is_string($slug) ? Collections::bySlug($db, $slug) : null;
    }

    /**
     * One branch: the name (linking to its page), the address, phone and e-mail as links, "Directions" to a maps search,
     * and for the script data-lat/data-lng and the text to search in.
     *
     * @param array<string, array{0: string, 1: string}> $values Collections::values
     * @param array{address: ?string, phone: ?string, email: ?string} $keys field keys of the collection (null = no such field)
     */
    private static function row(array $values, array $keys, ?string $locationKey): string
    {
        $text = fn (?string $key): string => $key === null ? '' : trim(html_entity_decode(strip_tags($values[$key][0] ?? ''), ENT_QUOTES | ENT_HTML5));
        [$name, $url] = [$values['nazev'][0], $values['url'][0]];
        [$address, $phone, $email] = [$text($keys['address']), $text($keys['phone']), $text($keys['email'])];
        $coordinates = $locationKey === null ? null : self::coordinates($values[$locationKey][0] ?? '');
        $html = '<strong class="ka-pobocky-nazev">' . ($url !== '' ? '<a href="' . e($url) . '">' . e($name) . '</a>' : e($name)) . '</strong>';
        if ($address !== '') {
            $html .= '<p class="ka-pobocky-adresa">' . nl2br(e($address), false) . '</p>';
        }
        $contacts = array_filter([
            self::telHref($phone) !== '' ? '<a href="' . e(self::telHref($phone)) . '">' . e($phone) . '</a>' : e($phone),
            $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) ? '<a href="mailto:' . e($email) . '">' . e($email) . '</a>' : e($email),
        ]);
        if ($contacts !== []) {
            $html .= '<p class="ka-pobocky-kontakt">' . implode(' · ', $contacts) . '</p>';
        }
        $directions = self::directionsUrl($address, $coordinates);
        if ($directions !== '') {
            $html .= '<p><a href="' . e($directions) . '" target="_blank" rel="noopener">' . e(t('Directions')) . '</a></p>';
        }
        $html .= '<span class="ka-pobocky-vzdalenost" data-vzdalenost></span>';
        $position = $coordinates === null ? '' : ' data-lat="' . $coordinates[0] . '" data-lng="' . $coordinates[1] . '"';

        return '<li' . $position . ' data-text="' . e(mb_strtolower(trim($name . ' ' . $address))) . '">' . $html . '</li>';
    }

    /**
     * Latitude and longitude of a location field value as strings for data attributes ("49.1951", "16.6068"); null when it is
     * empty or not a location.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function coordinates(string $location): ?array
    {
        $clean = Collections::cleanLocation($location);

        return $clean === null || $clean === '' ? null : explode(', ', $clean);
    }

    /** The tel: link of a phone as people write it ("+420 123 456 789"); '' when nothing dialable is left. */
    public static function telHref(string $phone): string
    {
        $digits = (string) preg_replace('/[^+\d]/', '', $phone);
        $digits = (str_starts_with($digits, '+') ? '+' : '') . str_replace('+', '', $digits); // a plus only at the start

        return strlen(ltrim($digits, '+')) >= 3 ? 'tel:' . $digits : '';
    }

    /**
     * A maps search of the address (the same service as the Map element's link); the coordinates when there is no address;
     * '' with neither.
     *
     * @param array{0: string, 1: string}|null $coordinates
     */
    public static function directionsUrl(string $address, ?array $coordinates): string
    {
        $query = $address !== '' ? $address : ($coordinates === null ? '' : $coordinates[0] . ',' . $coordinates[1]);

        return $query === '' ? '' : 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }
}
