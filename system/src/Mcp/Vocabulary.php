<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

use Kaleta\Builder\Build;
use Kaleta\Core\Language;

/**
 * The builder vocabulary in English at the MCP boundary (1.6): build JSON keys, element types, content fields and their
 * values, style states, properties and tokens. Stored builds keep their Czech keys (a contract with existing data); only
 * what goes over MCP is translated – English out, English or Czech in (a key or value this class does not know passes
 * through unchanged, so the Czech form keeps working).
 *
 * Every map must be one to one in its namespace; tools/unit-tests.php checks that and that every library section goes to
 * English and back unchanged.
 */
final class Vocabulary
{
    /** Keys of a build node. id, css and v are the same in both languages. */
    public const array NODE = ['typ' => 'type', 'znacka' => 'tag', 'obsah' => 'content', 'styl' => 'style', 'tridy' => 'classes', 'kotva' => 'anchor',
        'popis' => 'label', 'atributy' => 'attributes', 'podminky' => 'conditions', 'zamek' => 'locked', 'deti' => 'children'];

    public const array CONDITIONS = ['prihlaseni' => 'signed_in', 'od' => 'from', 'do' => 'to', 'jazyky' => 'languages', 'parametr' => 'url_parameter'];

    public const array CONDITION_VALUES = ['ano' => 'yes', 'ne' => 'no'];

    /** Keys of the url_parameter condition {name, value}. */
    public const array URL_PARAMETER = ['nazev' => 'name', 'hodnota' => 'value'];

    public const array TYPES = [
        'sekce' => 'section', 'kontejner' => 'container', 'mrizka' => 'grid', 'nadpis' => 'heading', 'text' => 'text', 'obrazek' => 'image', 'tlacitko' => 'button',
        'seznam' => 'list', 'citat' => 'testimonial', 'faq' => 'faq', 'video' => 'video', 'oddelovac' => 'divider', 'ikona' => 'icon', 'galerie' => 'gallery',
        'zalozky' => 'tabs', 'karusel' => 'carousel', 'mapa' => 'map', 'vlozeni' => 'embed', 'drobecky' => 'breadcrumbs', 'pocitadlo' => 'counter',
        'prubeh' => 'progress_bars', 'hodnoceni' => 'rating', 'odpocet' => 'countdown', 'socialni' => 'social_links', 'hledani' => 'search', 'novinky' => 'news_list',
        'kolekce' => 'collection_list', 'do_poptavky' => 'enquiry_button', 'pobocky' => 'store_locator', 'formular' => 'form', 'rezervace' => 'booking', 'newsletter' => 'newsletter_signup', 'komponenta' => 'component', 'html' => 'custom_html',
        'nahoru' => 'back_to_top', 'logo' => 'logo', 'navigace' => 'navigation', 'jazyky' => 'language_switcher', 'udaje' => 'company_details', 'obsah' => 'page_content',
        'cenik' => 'pricing_table', 'pred_po' => 'before_after', 'hotspoty' => 'hotspots', 'casova_osa' => 'timeline', 'recenze_google' => 'google_reviews',
        'predchozi_dalsi' => 'previous_next',
    ];

    /** Content fields of elements (one meaning each across all elements). */
    public const array CONTENT = [
        'sirka' => 'width', 'video' => 'background_video', 'odkaz' => 'link', 'text' => 'text', 'html' => 'html', 'src' => 'src', 'alt' => 'alt', 'popisek' => 'caption',
        'priorita' => 'priority', 'varianta' => 'variant', 'nove_okno' => 'new_window', 'ikona' => 'icon', 'ikona_vlevo' => 'icon_left', 'polozky' => 'items',
        'styl' => 'style', 'autor' => 'author', 'pozice' => 'position', 'jedna' => 'single_open', 'faq' => 'faq_schema', 'url' => 'url', 'titulek' => 'title',
        'plakat' => 'poster', 'tvar' => 'shape', 'popis' => 'description', 'fotky' => 'photos', 'pomer' => 'ratio', 'karty' => 'tabs', 'naraz' => 'per_view',
        'adresa' => 'address', 'priblizeni' => 'zoom', 'vyska' => 'height', 'cislo' => 'number', 'pred' => 'prefix', 'za' => 'suffix',
        'hodnota' => 'value', 'cil' => 'target', 'konec' => 'end_text', 'nazvy' => 'show_names', 'napoveda' => 'placeholder', 'tlacitko' => 'button_text',
        'pocet' => 'count', 'kategorie' => 'category', 'obrazky' => 'images', 'kolekce' => 'collection', 'razeni' => 'sort', 'razeni_pole' => 'sort_field',
        'filtr_pole' => 'filter_field', 'filtr_hodnota' => 'filter_value', 'bez_aktualni' => 'exclude_current', 'obdobi' => 'period', 'obdobi_od' => 'period_start_field', 'obdobi_do' => 'period_end_field', 'filtry' => 'filters',
        'kosik' => 'basket_page', 'mnozstvi' => 'quantity', 'porovnani' => 'compare', 'strankovani' => 'pagination',
        'prazdne' => 'empty_text', 'nazev' => 'name', 'pole' => 'fields', 'dekujeme' => 'thank_you', 'prijemce' => 'recipient', 'skryta_kopie' => 'bcc', 'dekovna' => 'thank_you_page',
        'potvrzeni' => 'confirmation', 'bez_captcha' => 'no_captcha', 'poslat_soubor' => 'send_file', 'souhlas' => 'consent', 'komponenta' => 'component', 'hodnoty' => 'values', 'kod' => 'code', 'menu' => 'menu',
        'novinky' => 'news_link', 'mobil' => 'phone_menu', 'mobil_tablet' => 'tablet_menu', 'mega' => 'mega_menu', 'zvyrazneni' => 'highlight', 'jazyky' => 'language_switcher', 'smer' => 'direction',
        'udaj' => 'detail', 'pri_rolovani' => 'on_scroll', 'text_nahore' => 'text_at_top', 'cast' => 'menu_part',
        'pole_poloha' => 'location_field', 'hledani' => 'search_box', 'nejblizsi' => 'nearest', 'mapa' => 'show_map', 'rozlozeni' => 'layout', 'mapa_hned' => 'map_at_once',
        'dalsi_kroky' => 'next_steps', 'odpovime_do' => 'reply_within_hours', 'odpovida' => 'who_replies',
        'plany' => 'plans', 'obrazek_pred' => 'before_image', 'alt_pred' => 'before_alt', 'popisek_pred' => 'before_label', 'obrazek_po' => 'after_image', 'alt_po' => 'after_alt',
        'popisek_po' => 'after_label', 'delic' => 'divider_position', 'body' => 'points', 'udalosti' => 'milestones',
        'min_hvezd' => 'min_stars', 'souhrn' => 'summary',
        'sluzba' => 'service', 'osoba' => 'staff_member',
        // 3.7: a Collection list of categories, Previous / next item, the attachment limit of a form
        'zdroj' => 'source', 'podle' => 'order_by', 'stejna_kategorie' => 'same_category', 'popisek_predchozi' => 'previous_label', 'popisek_dalsi' => 'next_label',
        'nahledy' => 'thumbnails', 'pole_obrazku' => 'image_field', 'max_priloha' => 'max_attachment_mb',
    ];

    /** A field that means something else in one element. */
    public const array CONTENT_BY_TYPE = ['logo' => ['nazev' => 'show_name']];

    /** Fields of items (FAQ, gallery photos, tabs, progress bars, form fields, pricing plans, hotspot points, timeline milestones). */
    public const array ITEMS = ['otazka' => 'question', 'odpoved' => 'answer', 'src' => 'src', 'alt' => 'alt', 'nazev' => 'name', 'obsah' => 'content', 'hodnota' => 'value',
        'popisek' => 'label', 'typ' => 'type', 'povinne' => 'required', 'moznosti' => 'options', 'moznosti_volby' => 'choices', 'moznosti_zaskrtnuti' => 'checkbox_options',
        'cena_za_jednotku' => 'unit_price', 'zaklad' => 'base_price', 'mena' => 'currency', 'kdyz_pole' => 'show_when_field', 'kdyz_hodnota' => 'show_when_value',
        'cena' => 'price', 'obdobi' => 'period', 'popis' => 'description', 'funkce' => 'features', 'tlacitko' => 'button_text', 'odkaz' => 'link', 'zvyraznit' => 'highlighted',
        'stitek' => 'badge', 'x' => 'x', 'y' => 'y', 'datum' => 'date'];

    public const array ITEM_VALUES = ['typ' => ['text' => 'text', 'email' => 'email', 'tel' => 'tel', 'textarea' => 'textarea', 'vyber' => 'select', 'volba' => 'radio',
        'datum' => 'date', 'cislo' => 'number', 'soubor' => 'file', 'kosik' => 'basket', 'krok' => 'step', 'odhad' => 'estimate', 'souhlas' => 'checkbox', 'zaskrtnuti' => 'checkboxes', 'skryte' => 'hidden']];

    private const array ICONS = ['fajfka' => 'check', 'fajfka-kruh' => 'check-circle', 'hvezda' => 'star', 'srdce' => 'heart', 'telefon' => 'phone', 'email' => 'email',
        'misto' => 'place', 'hodiny' => 'clock', 'kalendar' => 'calendar', 'clovek' => 'person', 'lide' => 'people', 'dum' => 'home', 'stit' => 'shield',
        'stit-fajfka' => 'shield-check', 'blesk' => 'bolt', 'list' => 'leaf', 'auto' => 'car', 'klic' => 'key', 'naradi' => 'tools', 'bublina' => 'speech-bubble',
        'svet' => 'globe', 'zamek' => 'lock', 'penize' => 'money', 'graf' => 'chart', 'rust' => 'growth', 'darek' => 'gift', 'fotak' => 'camera', 'dokument' => 'document',
        'sipka' => 'arrow', 'plus' => 'plus', 'info' => 'info', 'pozor' => 'warning', 'medaile' => 'medal', 'kufr' => 'briefcase', 'salek' => 'cup', 'cil' => 'target',
        'raketa' => 'rocket', 'palec' => 'thumbs-up', 'zarovka' => 'bulb', 'github' => 'github', 'rozvrzeni' => 'layout', 'tokeny' => 'tokens', 'chat' => 'chat',
        'clanek' => 'article', 'vrstvy' => 'layers', 'obrazek' => 'image', 'odeslat' => 'send', 'nastaveni' => 'settings'];

    /** Values of choice fields, by the Czech field key. */
    public const array VALUES = [
        'sirka' => ['obsah' => 'content', 'uzka' => 'narrow', 'plna' => 'full'],
        'varianta' => ['primarni' => 'primary', 'sekundarni' => 'secondary', 'obrys' => 'outline', 'odkaz' => 'link'],
        'ikona' => self::ICONS,
        'styl' => ['odrazky' => 'bullets', 'fajfky' => 'checks', 'bez' => 'none', 'nabidka' => 'dropdown', 'rada' => 'row'],
        'tvar' => ['kruh' => 'circle', 'ctverec' => 'square'],
        'razeni' => ['poradi' => 'order', 'nazev' => 'name', 'nejnovejsi' => 'newest', 'pole' => 'field', 'pole_sestupne' => 'field_descending'],
        'obdobi' => ['' => '', 'nadchazejici' => 'upcoming', 'probihajici' => 'current', 'minule' => 'past'],
        'zdroj' => ['polozky' => 'items', 'kategorie' => 'categories'],
        'podle' => ['poradi' => 'order', 'datum' => 'date'],
        'menu' => ['hlavni' => 'main', 'paticka' => 'footer'],
        'zvyrazneni' => ['pozadi' => 'background', 'podtrzeni' => 'underline'],
        'smer' => ['nahoru' => 'up', 'dolu' => 'down'],
        'priorita' => ['' => 'auto', '1' => 'high', '0' => 'lazy'], // the image's loading (3.5); true and false of older builds pass as they are
        'pri_rolovani' => ['pruhledna' => 'transparent', 'zmensit' => 'shrink', 'pruhledna-zmensit' => 'transparent_shrink'],
        'text_nahore' => ['svetly' => 'light', 'tmavy' => 'dark'],
        'rozlozeni' => ['seznam' => 'list', 'panel' => 'sidebar'],
        'cast' => ['cele' => 'whole', 'prvni' => 'first_half', 'druha' => 'second_half'],
        'udaj' => ['adresa' => 'address', 'telefon' => 'phone', 'email' => 'email', 'hodiny' => 'hours', 'otevreno' => 'open_now', 'mapa' => 'map', 'firma' => 'company', 'tiraz' => 'imprint',
            'copyright' => 'copyright', 'nazev' => 'name', 'popis' => 'description', 'text_paticky' => 'footer_text', 'site' => 'social', 'rss' => 'rss'],
    ];

    public const array STATES = ['zaklad' => 'base', 'tablet' => 'tablet', 'mobil' => 'mobile', 'hover' => 'hover', 'aktivni' => 'active', 'hover_tablet' => 'hover_tablet',
        'hover_mobil' => 'hover_mobile', 'aktivni_tablet' => 'active_tablet', 'aktivni_mobil' => 'active_mobile'];

    public const array STYLE = [
        'zobrazeni' => 'display', 'smer' => 'direction', 'zalamovani' => 'wrap', 'sloupce' => 'columns', 'radky' => 'rows', 'oblasti' => 'areas', 'oblast' => 'area',
        'rozpeti_sloupcu' => 'column_span', 'rozpeti_radku' => 'row_span', 'mezera' => 'gap', 'zarovnani' => 'align_items', 'rozmisteni' => 'justify_content',
        'vlastni_zarovnani' => 'align_self', 'poradi' => 'order', 'rust' => 'flex', 'sirka' => 'width', 'max_sirka' => 'max_width', 'vyska' => 'height',
        'min_vyska' => 'min_height', 'pomer_stran' => 'aspect_ratio', 'prizpusobeni' => 'object_fit', 'na_stred' => 'center', 'odsazeni_y' => 'padding_y',
        'odsazeni_x' => 'padding_x', 'okraj_nahore' => 'margin_top', 'okraj_dole' => 'margin_bottom', 'okraj_vlevo' => 'margin_left', 'okraj_vpravo' => 'margin_right',
        'typ_styl' => 'text_style', 'velikost_pisma' => 'font_size', 'tloustka_pisma' => 'font_weight', 'pismo' => 'font', 'zarovnani_textu' => 'text_align',
        'radkovani' => 'line_height', 'velka_pismena' => 'text_transform', 'proklad' => 'letter_spacing', 'max_radek' => 'line_length', 'barva' => 'color',
        'pozadi' => 'background', 'obrazek_pozadi' => 'background_image', 'prechod' => 'gradient', 'paralaxa' => 'background_attachment', 'prekryv' => 'overlay',
        'ramecek' => 'border', 'barva_ramecku' => 'border_color', 'linka_nahore' => 'border_top', 'linka_dole' => 'border_bottom', 'zaobleni' => 'radius',
        'stin' => 'shadow', 'pruhlednost' => 'opacity', 'orez' => 'overflow', 'pozice' => 'position', 'odshora' => 'top', 'zdola' => 'bottom', 'zleva' => 'left',
        'zprava' => 'right', 'posun' => 'translate', 'meritko' => 'scale', 'otoceni' => 'rotate', 'plynule' => 'transition', 'vrstva' => 'z_index', 'animace' => 'animation', 'pohyb' => 'scroll_motion', 'najeti' => 'hover_effect',
    ];

    /** Colour tokens of the design system (the value of colour properties). */
    public const array COLORS = ['primarni' => 'primary', 'primarni-jemna' => 'primary-soft', 'na-primarni' => 'on-primary', 'sekundarni' => 'secondary', 'text' => 'text',
        'tlumeny' => 'muted', 'pozadi' => 'background', 'plocha' => 'surface', 'linka' => 'line', 'bila' => 'white', 'cerna' => 'black'];

    public const array TEXT_STYLES = ['titulek' => 'title', 'nadpis-sekce' => 'section-heading', 'podnadpis' => 'subheading', 'perex' => 'lead', 'text' => 'body',
        'drobny' => 'small', 'nadtitulek' => 'eyebrow'];

    public const array RADII = ['plne' => 'full'];

    /** Style properties whose value is a colour token. */
    private const array COLOR_PROPERTIES = ['barva', 'pozadi', 'prekryv', 'barva_ramecku'];

    public const array FIELD_TYPES = ['text' => 'text', 'inline' => 'inline_text', 'html' => 'html', 'odkaz' => 'link', 'obrazek' => 'image', 'prepinac' => 'boolean',
        'vyber' => 'choice', 'cislo' => 'number', 'radky' => 'lines', 'polozky' => 'items', 'hodnoty' => 'values', 'kod' => 'code', 'textarea' => 'long_text'];

    public const array STYLE_TYPES = ['mezera' => 'space', 'delka' => 'length', 'barva' => 'color', 'krok' => 'step', 'zaobleni' => 'radius', 'stin' => 'shadow',
        'ramecek' => 'border', 'vyber' => 'choice', 'cislo' => 'number', 'obrazek' => 'image', 'sloupce' => 'columns', 'radky' => 'rows', 'oblasti' => 'areas',
        'oblast' => 'area', 'text' => 'text'];

    /* ---------- builds ---------- */

    /** @param array<string, mixed> $build {v, deti} */
    public static function buildToEnglish(array $build): array
    {
        $out = [];
        foreach ($build as $k => $v) {
            $out[$k === 'deti' ? 'children' : $k] = $k === 'deti' && is_array($v) ? array_map(self::elementToEnglish(...), $v) : $v;
        }

        return $out;
    }

    /** @param array<string, mixed> $build {v, children} (Czech keys pass through) */
    public static function buildToCzech(array $build): array
    {
        $out = [];
        foreach ($build as $k => $v) {
            $cs = $k === 'children' ? 'deti' : $k;
            $out[$cs] = $cs === 'deti' && is_array($v) ? array_map(self::elementToCzech(...), $v) : $v;
        }

        return $out;
    }

    public static function elementToEnglish(mixed $element): mixed
    {
        if (!is_array($element)) {
            return $element;
        }
        $type = (string) ($element['typ'] ?? '');
        $out = [];
        foreach ($element as $k => $v) {
            $out[self::NODE[$k] ?? $k] = match ($k) {
                'typ' => is_string($v) ? (self::TYPES[$v] ?? $v) : $v,
                'obsah' => is_array($v) ? self::contentToEnglish($type, $v) : $v,
                'styl' => is_array($v) ? self::styleToEnglish($v) : $v,
                'deti' => is_array($v) ? array_map(self::elementToEnglish(...), $v) : $v,
                'podminky' => is_array($v) ? self::conditions($v, true) : $v,
                default => $v,
            };
        }

        return $out;
    }

    public static function elementToCzech(mixed $element): mixed
    {
        if (!is_array($element)) {
            return $element;
        }
        $out = [];
        foreach ($element as $k => $v) {
            $cs = self::reverse('NODE')[$k] ?? $k;
            $out[$cs] = match ($cs) {
                'typ' => is_string($v) ? (self::reverse('TYPES')[$v] ?? $v) : $v,
                'obsah' => is_array($v) ? self::contentToCzech($v) : $v,
                'styl' => is_array($v) ? self::styleToCzech($v) : $v,
                'deti' => is_array($v) ? array_map(self::elementToCzech(...), $v) : $v,
                'podminky' => is_array($v) ? self::conditions($v, false) : $v,
                default => $v,
            };
        }

        return $out;
    }

    /** @param array<string, mixed> $content content of an element of the (Czech) type */
    public static function contentToEnglish(string $type, array $content): array
    {
        $out = [];
        foreach ($content as $k => $v) {
            $en = self::CONTENT_BY_TYPE[$type][$k] ?? self::CONTENT[$k] ?? $k;
            $out[$en] = self::valueToEnglish((string) $k, $v);
        }

        return $out;
    }

    /** Content keys in English or Czech; the element type is not needed (every English key has one meaning). */
    public static function contentToCzech(array $content): array
    {
        $out = [];
        foreach ($content as $k => $v) {
            $cs = self::reverse('CONTENT')[$k] ?? $k;
            $out[$cs] = self::valueToCzech((string) $cs, $v);
        }

        return $out;
    }

    private static function valueToEnglish(string $key, mixed $v): mixed
    {
        if (is_string($v)) {
            return self::VALUES[$key][$v] ?? $v;
        }
        if (is_array($v) && array_is_list($v) && $key !== 'hodnoty') {
            return array_map(fn (mixed $item): mixed => is_array($item) ? self::itemToEnglish($item) : $item, $v);
        }

        return $v; // component values: keys are the component's own
    }

    private static function valueToCzech(string $key, mixed $v): mixed
    {
        if (is_string($v)) {
            return (string) (array_flip(self::VALUES[$key] ?? [])[$v] ?? $v); // a numeric Czech value ('1') is an int key of the flipped map
        }
        if (is_array($v) && array_is_list($v) && $key !== 'hodnoty') {
            return array_map(fn (mixed $item): mixed => is_array($item) ? self::itemToCzech($item) : $item, $v);
        }

        return $v;
    }

    private static function itemToEnglish(array $item): array
    {
        $out = [];
        foreach ($item as $k => $v) {
            $out[self::ITEMS[$k] ?? $k] = is_string($v) && isset(self::ITEM_VALUES[$k]) ? (self::ITEM_VALUES[$k][$v] ?? $v) : $v;
        }

        return $out;
    }

    private static function itemToCzech(array $item): array
    {
        $out = [];
        foreach ($item as $k => $v) {
            $cs = array_flip(self::ITEMS)[$k] ?? $k;
            $out[$cs] = is_string($v) && isset(self::ITEM_VALUES[$cs]) ? (array_flip(self::ITEM_VALUES[$cs])[$v] ?? $v) : $v;
        }

        return $out;
    }

    /* ---------- style ---------- */

    /** @param array<string, mixed> $style {state: {property: value}} */
    public static function styleToEnglish(array $style): array
    {
        $out = [];
        foreach ($style as $state => $properties) {
            $props = [];
            foreach (is_array($properties) ? $properties : [] as $k => $v) {
                $props[self::STYLE[$k] ?? $k] = is_string($v) ? self::styleValue((string) $k, $v, true) : $v;
            }
            $out[self::STATES[$state] ?? $state] = is_array($properties) ? $props : $properties;
        }

        return $out;
    }

    public static function styleToCzech(array $style): array
    {
        $out = [];
        foreach ($style as $state => $properties) {
            $props = [];
            foreach (is_array($properties) ? $properties : [] as $k => $v) {
                $cs = self::reverse('STYLE')[$k] ?? $k;
                $props[$cs] = is_string($v) ? self::styleValue($cs, $v, false) : $v;
            }
            $out[self::reverse('STATES')[$state] ?? $state] = is_array($properties) ? $props : $properties;
        }

        return $out;
    }

    private static function styleValue(string $czechProperty, string $v, bool $toEnglish): string
    {
        $map = match (true) {
            in_array($czechProperty, self::COLOR_PROPERTIES, true) => self::COLORS,
            $czechProperty === 'typ_styl' => self::TEXT_STYLES,
            $czechProperty === 'zaobleni' => self::RADII,
            default => [],
        };

        return ($toEnglish ? $map : array_flip($map))[$v] ?? $v;
    }

    private static function conditions(array $conditions, bool $toEnglish): array
    {
        $keys = $toEnglish ? self::CONDITIONS : array_flip(self::CONDITIONS);
        $values = $toEnglish ? self::CONDITION_VALUES : array_flip(self::CONDITION_VALUES);
        $parameterKeys = $toEnglish ? self::URL_PARAMETER : array_flip(self::URL_PARAMETER);
        $out = [];
        foreach ($conditions as $k => $v) {
            $cs = $toEnglish ? $k : (array_flip(self::CONDITIONS)[$k] ?? $k);
            if ($cs === 'parametr' && is_array($v)) {
                // {nazev, hodnota} <-> {name, value}; the language codes of "jazyky" are the same on both sides
                $v = array_combine(array_map(fn (string|int $pk): string|int => $parameterKeys[$pk] ?? $pk, array_keys($v)), $v);
            }
            $out[$keys[$k] ?? $k] = is_string($v) ? ($values[$v] ?? $v) : $v;
        }

        return $out;
    }

    /** English => Czech of a map, with the element-specific content keys. @return array<string, string> */
    public static function reverse(string $map): array
    {
        static $cache = [];
        if (!isset($cache[$map])) {
            $flipped = array_flip(constant(self::class . '::' . $map));
            if ($map === 'CONTENT') {
                foreach (self::CONTENT_BY_TYPE as $keys) {
                    $flipped += array_flip($keys);
                }
            }
            $cache[$map] = $flipped;
        }

        return $cache[$map];
    }

    /* ---------- builder_schema ---------- */

    /**
     * builder_schema in English: the overview (one line per element and style property) or, for chosen elements, their full
     * definitions.
     *
     * @param array<string, mixed> $schema Build::schema()
     * @param list<string> $only English or Czech element types for full definitions
     * @return array<string, mixed>
     */
    public static function schema(array $schema, array $only = [], bool $full = false): array
    {
        $only = array_map(fn (string $t): string => self::reverse('TYPES')[$t] ?? $t, $only);
        $admin = fn (string $text): string => Language::runWith('en', fn (): string => t($text), 'admin-');
        $definitions = [];
        $lines = [];
        foreach ($schema['prvky'] as $p) {
            $type = self::TYPES[$p['typ']] ?? $p['typ'];
            if ($full || in_array($p['typ'], $only, true)) {
                $definitions[] = ['type' => $type, 'name' => $admin($p['nazev']), 'description' => $admin($p['popis']), 'container' => $p['kontejner'], 'tags' => $p['znacky'],
                    'fields' => self::fields($p['typ'], (array) $p['vlastnosti'], $admin), 'default_style' => self::styleToEnglish((array) $p['vychozi_styl']) ?: new \stdClass(),
                    'default_children' => array_map(self::elementToEnglish(...), (array) $p['vychozi_deti'])];
            }
            $style = [];
            foreach ((array) $p['vychozi_styl'] as $state => $properties) {
                foreach ((array) $properties as $k => $h) {
                    $style[] = ($state === 'zaklad' ? '' : (self::STATES[$state] ?? $state) . '.') . (self::STYLE[$k] ?? $k) . '=' . (is_string($h) ? self::styleValue($k, $h, true) : json_encode($h));
                }
            }
            $fields = self::fieldLine($p['typ'], (array) $p['vlastnosti']);
            $lines[$type] = $admin($p['nazev']) . ' – ' . $admin($p['popis']) . ($p['kontejner'] ? ' [CONTAINER]' : '') . ' | tags: ' . implode(',', $p['znacky'])
                . ($fields !== '' ? ' | content: ' . $fields : '')
                . ($style !== [] ? ' | style of a new element in the builder: ' . implode(', ', $style) . ' (write it in the JSON yourself, otherwise the element has none)' : '');
        }
        if ($only !== [] && !$full) {
            return ['elements' => $definitions];
        }
        $style = [];
        foreach ($schema['styl'] as $key => $v) {
            $style[self::STYLE[$key] ?? $key] = $v['css'] . ': ' . (isset($v['moznosti'])
                ? implode('|', array_map(fn (string|int $o): string => self::styleValue($key, (string) $o, true), array_keys($v['moznosti'])))
                : (self::STYLE_TYPES[$v['typ']] ?? $v['typ']));
        }
        $tokens = $schema['tokeny'];

        return [
            'version' => $schema['verze'], 'elements' => $full ? $definitions : $lines, 'style' => $style, 'states' => array_map(fn (string $s): string => self::STATES[$s] ?? $s, $schema['stavy']),
            'tokens' => ['colors' => array_values(array_map(fn (string $c): string => self::COLORS[$c] ?? $c, array_keys($tokens['barvy']))), 'spaces' => $tokens['mezery'],
                'steps' => $tokens['kroky'], 'radii' => array_map(fn (string|int $r): string => self::RADII[(string) $r] ?? (string) $r, $tokens['zaobleni']), 'shadows' => $tokens['stiny']],
            'value_types' => [
                'space' => 'token ' . implode('|', $tokens['mezery']) . ' or a length (1.5rem)', 'step' => 'font size: token ' . implode('|', $tokens['kroky']) . ' or a length',
                'color' => 'token (' . implode('|', array_map(fn (string $c): string => self::COLORS[$c] ?? $c, array_keys($tokens['barvy']))) . ') or #hex',
                'radius' => 'token ' . implode('|', array_map(fn (string|int $r): string => self::RADII[(string) $r] ?? (string) $r, $tokens['zaobleni'])) . ' or a length',
                'shadow' => 'token s|m|l, none or “x y blur colour”', 'border' => 'an option or “2px solid colour”', 'length' => 'px, rem, %, vw, fr, auto, min()/max()/clamp()/calc()',
                'columns' => 'a number 1–12, “auto:16rem” (as many as fit) or “2fr 1fr”', 'rows' => 'a number or “auto 1fr”', 'areas' => 'rows separated by /, e.g. “a a / b c”',
            ],
            'node' => '{"id":"(optional, keep it when editing)","type":"…","tag":"(one of the tags; the first is the default)","content":{…},"style":{"base":{…},"tablet":{…},"mobile":{…},"hover":{…}},"classes":["…"],"anchor":"id-for-links","children":[…]}; leave out empty fields and default values',
            'conditions' => 'optional "conditions" of a node: {"signed_in":"yes|no","from":"YYYY-MM-DD","to":"YYYY-MM-DD","languages":["","de"] ("" = the default language),"url_parameter":{"name":"utm_campaign","value":"jaro"}} – the element is rendered only when all of them hold',
            'rules' => [
                'One element = one HTML tag; a section has at most one inner wrapper. Build page content from sections.',
                'Take style from tokens (space “l”, colour “primary”, step “2”); a free value only when no token fits.',
                'Style has the states base, tablet (up to 1023 px), mobile (up to 767 px), hover; editing one state leaves the others.',
                'A repeated look (cards, labels) belongs in a class, not in the style of every element.',
                'Grid columns: a number (“3”), “auto:16rem” (as many as fit) or a ratio (“2fr 1fr”).',
                'The Czech keys of older connections (typ, obsah, styl, deti…) are still accepted.',
            ],
        ];
    }

    /** Full field definitions of an element in English. */
    private static function fields(string $czType, array $properties, callable $admin): array
    {
        $out = [];
        foreach ($properties as $key => $v) {
            $field = ['type' => self::FIELD_TYPES[$v['typ']] ?? $v['typ'], 'label' => $admin((string) ($v['popisek'] ?? ''))];
            if (array_key_exists('vychozi', $v)) {
                $field['default'] = self::valueToEnglish($key, $v['vychozi']);
            }
            if (isset($v['moznosti']) && is_array($v['moznosti'])) {
                $field['options'] = array_values(array_map(fn (string|int $o): string => (string) (self::VALUES[$key][(string) $o] ?? $o), array_is_list($v['moznosti']) ? $v['moznosti'] : array_keys($v['moznosti'])));
            }
            if (isset($v['pole']) && is_array($v['pole'])) {
                $item = [];
                foreach ($v['pole'] as $k => $f) {
                    $item[self::ITEMS[$k] ?? $k] = ['type' => self::FIELD_TYPES[$f['typ']] ?? $f['typ'], 'label' => $admin((string) ($f['popisek'] ?? ''))]
                        + (isset($f['moznosti']) && is_array($f['moznosti']) ? ['options' => array_values(array_map(fn (string|int $o): string => self::ITEM_VALUES[$k][(string) $o] ?? (string) $o, array_keys($f['moznosti'])))] : []);
                }
                $field['item_fields'] = $item;
            }
            $out[self::CONTENT_BY_TYPE[$czType][$key] ?? self::CONTENT[$key] ?? $key] = $field;
        }

        return $out;
    }

    /** Fields of an element on one line: key:type(options, * = default) or key:items[fields]. */
    private static function fieldLine(string $czType, array $properties): string
    {
        $parts = [];
        foreach ($properties as $key => $v) {
            $description = (self::CONTENT_BY_TYPE[$czType][$key] ?? self::CONTENT[$key] ?? $key) . ':' . (self::FIELD_TYPES[$v['typ']] ?? $v['typ']);
            if (isset($v['moznosti']) && is_array($v['moznosti'])) {
                $description .= '(' . implode('|', array_map(fn (string|int $m): string => (string) (self::VALUES[$key][(string) $m] ?? $m) . ((string) $m === (string) ($v['vychozi'] ?? '') ? '*' : ''),
                    array_is_list($v['moznosti']) ? $v['moznosti'] : array_keys($v['moznosti']))) . ')';
            } elseif (isset($v['pole']) && is_array($v['pole'])) {
                $item = [];
                foreach ($v['pole'] as $k => $f) {
                    $item[] = (self::ITEMS[$k] ?? $k) . ':' . (self::FIELD_TYPES[$f['typ']] ?? $f['typ'])
                        . (isset($f['moznosti']) && is_array($f['moznosti']) ? '(' . implode('|', array_map(fn (string|int $o): string => self::ITEM_VALUES[$k][(string) $o] ?? (string) $o, array_keys($f['moznosti']))) . ')' : '');
                }
                $description .= '[' . implode('; ', $item) . ']';
            } elseif (in_array($v['typ'], ['prepinac', 'cislo'], true) && isset($v['vychozi'])) {
                $description .= '=' . var_export($v['vychozi'], true);
            }
            $parts[] = $description;
        }

        return implode('; ', $parts);
    }

    /** Czech element types of builds that are not part of the vocabulary yet (a new element needs an English name). */
    public static function missingTypes(): array
    {
        return array_values(array_diff(array_map(fn (string $c): string => $c::TYPE, Build::ELEMENTS), array_keys(self::TYPES)));
    }
}
