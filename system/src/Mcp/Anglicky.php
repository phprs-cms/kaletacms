<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

/**
 * Anglické rozhraní MCP: názvy nástrojů, parametry, popisy, klíče výsledků, stavy a chybová hlášení v angličtině.
 *
 * Nástroje se implementují jednou (Nastroje, české názvy a parametry); tahle vrstva jen překládá na vstupu a výstupu.
 * České názvy zůstávají jako skryté aliasy (tools/list je nevypisuje) a chovají se jako dřív – napojení Clauda, která
 * si je pamatují, fungují dál. Datový model builderu (JSON stavby, schéma prvků, klíče design systému) zůstává, jak je.
 */
final class Anglicky
{
    /** Společný cíl nástrojů stavby: stránka, část webu (varianta) nebo šablona detailu kolekce. */
    private const array CIL = [
        'id' => ['id', 'Page ID'],
        'part' => ['cast', 'Instead of a page, a site part (administrators only): header | footer | news_item | news_list | not_found – the header, the footer and the wrappers of a news item, the news list and the 404 page'],
        'language' => ['jazyk', 'Language version of the site part or of the collection item template on a multilingual site (empty = default)'],
        'variant' => ['varianta', 'Header or footer variant (key from list_site_parts; empty = the default)'],
        'collection' => ['kolekce', 'Instead of a page, the item page template of a collection (collection slug from list_collections, administrators only); with "language", the template of that language version'],
        'popup' => ['popup', 'Instead of a page, the content of a pop-up window (ID from list_popups, administrators only)'],
    ];

    private const array STRANKA = [
        'title' => ['titulek', 'Page name (shown in the navigation and as the heading)'],
        'content' => ['text', 'Page content as HTML'],
        'slug' => ['adresa', 'Part of the address after the domain; without it, it is made from the title'],
        'description' => ['popis', 'Description for search engines, up to 160 characters'],
        'in_menu' => ['v_menu', 'true = link in the main navigation'],
        'order' => ['poradi', 'Order in the navigation, lower = first'],
        'visible' => ['zobrazit', 'true = the page is visible on the site (only when the user explicitly asks), otherwise hidden'],
        'seo_title' => ['seo_titulek', 'Title for search engines (optional, otherwise the name)'],
        'share_image' => ['obrazek', 'Image for sharing on social networks (path from Media)'],
        'noindex' => ['noindex', 'true = hide the page from search engines'],
        'parent' => ['nadrazena', 'ID of the parent page – the address becomes /parent/page (0 = none)'],
        'language' => ['jazyk', 'Language version of the page on a multilingual site (code such as de; empty = default language)'],
        'translation_of' => ['preklad_z', 'ID of the counterpart in the default language (for a page in another language version) – language switcher and hreflang'],
        'copy_build' => ['kopie_stavby', 'only for a new page with translation_of: the draft starts as a copy of the original’s build – then translate with get_build (texts_only) and edit_build'],
        'publish_at' => ['zverejnit_od', 'Scheduled publishing of a hidden page YYYY-MM-DD HH:MM (only when the user explicitly asks; empty = cancel)'],
    ];

    private const array NOVINKA = [
        'title' => ['titulek', 'News headline'],
        'intro' => ['uvod', 'Intro as HTML (1–2 paragraphs)'],
        'content' => ['text', 'Text as HTML'],
        'category' => ['kategorie', 'Category name or slug'],
        'tags' => ['stitky', 'Tags separated by commas'],
        'seo_title' => ['seo_titulek', 'Title for search engines (optional)'],
        'seo_description' => ['seo_popis', 'Description for search engines, up to 160 characters'],
        'image' => ['obrazek', 'Address of the main image (from list_media)'],
        'image_caption' => ['obrazek_popis', 'Caption of the main image (empty = from the media library)'],
        'faq' => ['faq', 'Questions and answers: a question on one line, the answer below it, an empty line between pairs'],
        'date' => ['datum', 'Publication date YYYY-MM-DD HH:MM; a future date schedules it'],
        'publish' => ['vydat', 'true = publish (only with the publishing permission and when the user explicitly asks), otherwise a draft'],
    ];

    /**
     * Anglický název => [český nástroj, popis, parametry [anglický => [český, popis]]]. Parametr '*cil' = společný cíl stavby.
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private const array NASTROJE = [
        'site_info' => ['info_o_webu', 'Site name, home page, theme, numbers of pages and news, the role of the signed-in user and their permissions.', []],
        'list_pages' => ['seznam_stranek', 'Pages of the site (Home, About, Services, Contact…) with their addresses.', []],
        'get_page' => ['nacti_stranku', 'The whole page including its HTML content.', ['id' => ['id', 'Page ID']]],
        'create_page' => ['vytvor_stranku', 'Creates a page (editors and administrators). Without "visible": true it stays hidden.', ['*stranka']],
        'update_page' => ['uprav_stranku', 'Changes the given fields of a page; the others stay.', ['id' => ['id', 'Page ID'], '*stranka']],
        'get_menu' => ['nacti_menu', 'The site menu (main or footer) for a language version: items with submenus, and whether the main menu is still built automatically from pages “in menu”.',
            ['location' => ['umisteni', 'main (default) | footer'], 'language' => ['jazyk', 'language version (empty = default)']]],
        'save_menu' => ['uloz_menu', 'Saves the whole menu (administrators). Items: {"type":"page","page_id":5,"text":""} (empty text = page name) | {"type":"link","text":"…","url":"https://… or /path","new_window":false} | {"type":"news"} | {"type":"group","text":"Services"} – each may have "children" (one submenu level). null = the main menu is automatic again. The menu has no draft – it changes the site straight away; a hidden page appears in it only once it is visible.',
            ['location' => ['umisteni', 'main | footer'], 'language' => ['jazyk', 'language version (empty = default)'], 'items' => ['polozky', 'menu items']]],
        'builder_schema' => ['stavba_schema', 'How a page is put together in the builder: element types and their fields, style properties, design system tokens (colours, spacing, type), the section library and the shared classes of the site. Load it before you first use the *_build tools. Returns a short overview (one element per line); full definitions of chosen elements through the elements parameter. The build JSON uses the builder’s own (Czech) keys: typ, znacka, obsah, styl, tridy, deti, kotva.',
            ['elements' => ['prvky', 'element types to get the full definition for (field labels, default children), e.g. ["formular","karusel"]'], 'full' => ['uplne', 'true = the whole schema with all labels (large)']]],
        'get_build' => ['stavba_nacti', 'The build of a page or site part (a tree of elements with ids) – the draft in progress, otherwise the published version. Default values are left out. A page without a build returns a build made from its text. '
            . 'With texts_only just the texts and links of elements by id (for translating: send them back as “update” operations in edit_build).',
            ['*cil', 'texts_only' => ['jen_texty', 'true = instead of the build a list texts: [{id, type, content: only text properties and links, attributes}]']]],
        'edit_build' => ['stavba_uprav', 'Partial edits of the draft by element id (ids from get_build) – fix a text, a link or a style without sending the whole build. Operations: '
            . '{"op":"update","id":"…","content":{…},"style":{"mobil":{"mezera":"s"}},"classes":[…]} (content and style merge, a null value removes) | {"op":"replace","id":"…","element":{…}} | {"op":"delete","id":"…"} | '
            . '{"op":"insert","elements":[…],"into":"parent id or null = root","position":0 | "after":"id" | "before":"id"} | {"op":"move","id":"…","into":…,"after":…}. Elements themselves use the build JSON keys (typ, obsah, styl…).',
            ['*cil', 'operations' => ['operace', 'list of operations, applied in order'], 'publish' => ['publikovat', 'true = publish (only when the user explicitly asks)']]],
        'list_classes' => ['seznam_trid', 'Shared classes of the site (card, dark band…) with their style per state and custom CSS. An element gets a class in its "tridy" list.', ['name' => ['nazev', 'only this class (optional)']]],
        'save_classes' => ['uloz_tridy', 'Creates or changes shared classes (administrators) – the change applies to the whole site at once. Write CSS as in a <style> block: rules of one class (.card { … }), '
            . '.card:hover { … } and @media (max-width: 1023px) = tablet, (max-width: 767px) = mobile. Use tokens var(--ka-…), and override tokens inside a class (--ka-barva-text: #fff) for dark bands.',
            ['css' => ['css', 'class rules; they merge with the existing ones – a .card:hover or @media rule alone leaves the base of the class unchanged'],
                'replace' => ['nahradit', 'true = replace the classes in css entirely (base and all states)'], 'delete' => ['smazat', 'names of classes to delete']]],
        'build_from_html' => ['stavba_z_html', 'RECOMMENDED for a new page or sections: write semantic HTML (section/header, h1–h3, p, ul, a, img, figure, blockquote, details) and put the look in a <style> block as rules of one class (.card { … }, .card:hover { … }) with tokens var(--ka-…); '
            . 'breakpoints from desktop down: @media (max-width: 1023px) = tablet, @media (max-width: 767px) = mobile. An element with a class from <style> gets no default style – layout (display:grid, gap) belongs in the class. It is converted to a build and classes; the result says what could not be converted. Saved as a draft.',
            ['html' => ['html', 'HTML of the content (without <html>/<head>); <style> may be inside. Build the header and footer from the logo, navigation and company details elements with save_build – HTML does not convert them.'],
                'id' => ['id', 'Page ID; without it (and without part) a new hidden page is created with the name from title'], 'part' => ['cast', 'Instead of a page, a site part (administrators only)'],
                'language' => self::CIL['language'], 'variant' => self::CIL['variant'], 'collection' => self::CIL['collection'], 'popup' => self::CIL['popup'],
                'title' => ['titulek', 'Name of the new page (when there is no id)'],
                'mode' => ['rezim', 'replace (default) = the whole build from the HTML | append = sections at the end of the existing build'],
                'overwrite_classes' => ['prepsat_tridy', 'true = classes that already exist on the site are overwritten by the <style>; otherwise they stay'],
                'publish' => ['publikovat', 'true = publish straight away (only when the user explicitly asks); otherwise a draft to preview']]],
        'save_build' => ['stavba_uloz', 'Saves the whole build of a page (the tree from get_build with your changes) as a draft. For small edits of content and style of single elements. Returns the cleaned build, errors and the check before publishing.',
            ['*cil', 'build' => ['stavba', '{"v":1,"deti":[…]} according to builder_schema'], 'publish' => ['publikovat', 'true = publish (only when the user explicitly asks)']]],
        'insert_section' => ['vloz_sekci', 'Adds a ready-made section from the library (hero, benefits, services, numbers, testimonials, faq, call to action, news, contact) to the end of the draft of a page or site part.',
            ['*cil', 'section' => ['sekce', 'section key from builder_schema → knihovna']]],
        'publish_build' => ['publikuj_stavbu', 'Publishes the draft build of a page or site part (only when the user explicitly asks). The previous version stays in the history.', ['*cil']],
        'list_build_versions' => ['stavba_verze', 'Published versions of the build of a page or site part (the last 20): version_id, when, who. restore_build_version loads an older one into the draft.', ['*cil']],
        'restore_build_version' => ['obnov_verzi', 'Loads an older published version (version_id from list_build_versions) into the draft – it appears on the site only after publishing.',
            ['*cil', 'version_id' => ['idr', 'version ID from list_build_versions']]],
        'discard_draft' => ['zahod_koncept', 'Discards the draft build – the published version applies again (only when the user explicitly asks; cannot be undone).', ['*cil']],
        'list_popups' => ['seznam_popupu', 'Pop-up windows of the site (administrators): type, trigger, frequency, rules, active, published and counts of views, closes and conversions. Build the content with the *_build tools and the popup parameter.', []],
        'save_popup' => ['uloz_popup', 'Creates a pop-up window (without id; template = ready-made content) or changes its settings (with id) – administrators. A new window is inactive; it can be activated (active: true) only after its build is published, and only when the user explicitly asks.',
            ['id' => ['id', 'window ID – only when changing it'], 'name' => ['nazev', 'Name (screen readers announce it)'],
                'template' => ['vzor', 'Only for a new window: newsletter | lead_magnet | announcement_bar | discount | event | blank'],
                'slug' => ['adresa', 'Address for the link #popup-<slug>'], 'type' => ['typ', 'window | slide_in | top_bar | bottom_bar | fullscreen'],
                'trigger' => ['spoustec', 'time | scroll | exit | idle | pages | click – click = only a link to #popup-<slug> opens it'],
                'value' => ['hodnota', 'Seconds (time, idle), percent of the page (scroll), number of pages in the visit (pages)'],
                'frequency' => ['cetnost', 'session | days | until_closed | until_submitted | always'], 'days' => ['dni', 'Number of days for the days frequency'],
                'rules' => ['pravidla', '{"where":"all|selected","pages":[id],"collections":["slug"],"news":true,"language":"en","from":"YYYY-MM-DD","to":"YYYY-MM-DD","device":"all|desktop|phone","campaign":"text in utm_*","referrer":"part of the address the visitor came from"} – keys you leave out stay'],
                'active' => ['aktivni', 'true = the window shows on the site (published only, only when the user explicitly asks)'], 'order' => ['poradi', 'Order, lower = first']]],
        'list_site_parts' => ['seznam_casti', 'Site parts from the builder (header, footer, wrappers of a news item, the news list and the 404 page) and header and footer variants: key, name, pages they apply to and state (administrators).', []],
        'save_part_variant' => ['uloz_variantu', 'Creates or changes a header or footer variant for selected pages (administrators) – for example a header without the menu for a campaign page. A new one starts as a copy of the default as a draft; '
            . 'then edit it with the *_build tools and the variant parameter and publish it. delete = true removes the variant (the selected pages get the default).',
            ['part' => ['cast', 'header | footer'], 'language' => ['jazyk', 'Language version (empty = default)'], 'variant' => ['varianta', 'key of an existing variant – only to change or delete it'],
                'name' => ['nazev', 'variant name, e.g. Campaign without menu'], 'pages' => ['stranky', 'IDs of the pages the variant applies to'],
                'delete' => ['smazat', 'true = delete the variant (only when the user explicitly asks)']]],
        'update_design_system' => ['uprav_design_system', 'Changes the look of the whole site (administrators): colours, fonts, sizes, width, corner radius – or applies a preset. Values you leave out stay. Returns a colour readability check.',
            ['preset' => ['predvolba', 'firemni | remeslo | pratelsky | elegantni | technologie (optional)'], 'design' => ['ds', 'Changes, e.g. {"barvy":{"primarni":"#0f766e"},"pismo_titulky":"klasicke","zaobleni":"l"} – keys in builder_schema → design_system']]],
        'list_collections' => ['seznam_kolekci', 'Collections of the site (testimonials, team, products…) with their fields and numbers of items. The “kolekce” element (Collection list) puts them on a page; inside it {{key}} is replaced by the item value ({{nazev}}, {{url}} = item page, {{datum}} and your own fields).', []],
        'create_collection' => ['vytvor_kolekci', 'Creates a collection (administrators). Fields: a list of {label, type}; type = text | lines | html | image | link | number | date. The field key is made from the label.',
            ['name' => ['nazev', 'Name, e.g. Testimonials'], 'slug' => ['adresa', 'Address of the collection in URLs (optional, otherwise from the name), e.g. guide'],
                'fields' => ['pole', '[{"label":"Quote","type":"lines"},{"label":"Logo","type":"image"}]'], 'item_pages' => ['detail', 'true = every item has its own page /<collection>/<item>']]],
        'update_collection' => ['uprav_kolekci', 'Changes the name, address, item pages or fields of a collection (administrators). Fields = the whole new list; for existing ones send the "key" too (item values stay), a field without a key is new, a field you leave out disappears from the form.',
            ['collection' => ['kolekce', 'current slug of the collection'], 'name' => ['nazev', 'new name (optional)'], 'slug' => ['adresa', 'new address in URLs (optional)'],
                'item_pages' => ['detail', 'item pages on / off (optional)'], 'fields' => ['pole', '[{"key":"quote","label":"Quote","type":"lines"},{"label":"New field","type":"text"}] (optional)']]],
        'list_collection_items' => ['seznam_polozek_kolekce', 'Items of a collection with their field values, 50 per page (total is returned). Filter: text in the name and values, field=value, language, visible only.',
            ['collection' => ['kolekce', 'collection slug'], 'search' => ['hledat', 'text in the name or field values (optional)'], 'field' => ['pole', 'field key for an exact match (optional)'],
                'value' => ['hodnota', 'field value for an exact match'], 'language' => ['jazyk', 'language version (empty = default; optional)'], 'visible_only' => ['jen_zobrazene', 'only items visible on the site'],
                'page' => ['strana', 'page from 1']]],
        'save_collection_item' => ['uloz_polozku_kolekce', 'Adds an item to a collection, or changes an existing one (with id). Without "visible": true a new item stays hidden.',
            ['collection' => ['kolekce', 'collection slug'], 'id' => ['id', 'item ID – only when changing it'], 'name' => ['nazev', 'item name (required for a new item, when changing only if it changes)'],
                'slug' => ['adresa', 'address of the item in URLs (optional, otherwise from the name), e.g. install'], 'language' => ['jazyk', 'language version of the item on a multilingual site (empty = default); a translation keeps the slug of the original, so the language switcher and hreflang link them'],
                'values' => ['data', 'field values by the keys from list_collections, e.g. {"quote":"…","logo":"media/…"}; fields you leave out stay'],
                'order' => ['poradi', 'Order, lower = first'], 'visible' => ['zobrazit', 'true = the item is on the site (only when the user asks)']]],
        'list_news' => ['seznam_novinek', 'List of news (newest first).',
            ['status' => ['stav', 'all | published | scheduled | drafts'], 'category' => ['kategorie', 'category name or slug'], 'search' => ['hledat', 'text in the headline'], 'limit' => ['limit', '1-50, default 20']]],
        'get_news' => ['nacti_novinku', 'The whole news item including the text and tags.', ['id' => ['id', 'News ID']]],
        'create_news' => ['vytvor_novinku', 'Creates a news item. Without "publish": true it is a draft.', ['*novinka']],
        'update_news' => ['uprav_novinku', 'Changes the given fields of a news item; the others stay. The previous version is saved to the history.', ['id' => ['id', 'News ID'], '*novinka']],
        'list_categories' => ['seznam_kategorii', 'News categories with counts.', []],
        'create_category' => ['vytvor_kategorii', 'Creates a news category (editors and administrators).', ['name' => ['nazev', 'Name'], 'description' => ['popis', 'Description (HTML)']]],
        'list_media' => ['seznam_medii', 'Recently uploaded images and files with addresses and dimensions.', ['limit' => ['limit', '1-50, default 20'], 'search' => ['hledat', 'text in the name (optional)']]],
        'upload_file' => ['nahraj_soubor', 'Uploads a file to Media: an image (JPG, PNG, WebP, GIF – resized, with WebP/AVIF variants), SVG (cleaned), a WOFF2 font for the design system or an attachment (PDF…). '
            . 'Give the url of a public file (https – image, font, PDF; always url for larger files), or data in base64 (at most 12 MB). Returns the path for the image or background image element or for custom fonts.',
            ['filename' => ['nazev', 'file name with extension, e.g. team-london.jpg'], 'data' => ['data', 'file content in base64'], 'url' => ['url', 'https address of the file to download (instead of data)'],
                'alt' => ['popis', 'image description for blind visitors (alt); otherwise from the name']]],
        'preview_link' => ['nahled_odkaz', 'A signed link to the draft preview of a page or site part – anyone can open it without signing in (the user, a colleague, a browser); it is valid only for this target and for a limited time. Search engines do not index it.',
            ['*cil', 'minutes' => ['minut', 'validity in minutes, default 60, at most 10080']]],
        'update_settings' => ['uprav_nastaveni', 'Changes site settings (administrators) – they apply to the site straight away. Keys: site_name, site_description, footer_text, logo, favicon and share_image – the sharing image 1200×630 (path media/… from upload_file or image/…), home_page (ID of the home page), social_facebook|instagram|x|youtube|linkedin (URL), '
            . 'news_per_page, share_buttons, article_outline, related_news_auto (1/0), dark_mode (vypnuto = light only | auto = by device | tmavy = always dark), theme_switcher (1/0 = light/dark switcher for visitors), company details company_name, company_type, company_id, company_vat_id, company_register (commercial register entry), company_representative (who represents the company), company_street, company_city, company_postcode, company_country (CZ), company_phone, company_email (public contact), company_hours (one day per line), company_map, company_gps; site_name_de… for language versions. Without the parameter it returns the current values.',
            ['settings' => ['nastaveni', '{"key":"value"}']]],
        'list_enquiries' => ['seznam_poptavek', 'Enquiries from the site forms (Forms and enquiries extension; only with access to Enquiries), newest first: date, form, page, campaign (utm), e-mail, status and the filled-in fields. They contain personal data – use them only for what the user asks.',
            ['status' => ['stav', 'new | read | resolved | all (default)'], 'search' => ['hledat', 'text in the e-mail or content (optional)'], 'limit' => ['limit', '1-50, default 20']]],
        'list_redirects' => ['seznam_presmerovani', 'Redirects of old addresses (Redirects extension) and the most frequent addresses that ended with a 404 error.', []],
        'save_redirect' => ['uloz_presmerovani', 'Adds or changes a redirect (administrators): from an old path on the site to a new path or https address. Code 301 = permanent (default), 302 = temporary.',
            ['from' => ['z', 'old path, e.g. /docs or /about'], 'to' => ['na', 'new path (/guide) or https://…'], 'code' => ['typ', '301 or 302'], 'delete' => ['smazat', 'true = delete the redirect from the old path']]],
        'trash_page' => ['smaz_stranku', 'Moves a page to the trash (only when the user explicitly asks; editors or administrators). It can be restored for 30 days in the admin. The home page cannot be deleted.', ['id' => ['id', 'Page ID']]],
    ];

    /** Hodnoty parametrů v angličtině => česky (podle českého parametru; u některých nástrojů jen tam). */
    private const array HODNOTY_VSTUPU = [
        'cast' => ['header' => 'hlavicka', 'footer' => 'paticka', 'news_item' => 'novinka', 'news_list' => 'vypis', 'not_found' => 'nenalezeno'],
        'umisteni' => ['main' => 'hlavni', 'footer' => 'paticka'],
        'rezim' => ['replace' => 'nahradit', 'append' => 'pridat'],
        'typ' => ['window' => 'okno', 'slide_in' => 'panel', 'top_bar' => 'lista-nahore', 'bottom_bar' => 'lista-dole', 'fullscreen' => 'cela'],
        'spoustec' => ['time' => 'cas', 'scroll' => 'posun', 'exit' => 'odchod', 'idle' => 'necinnost', 'pages' => 'stranky', 'click' => 'klik'],
        'cetnost' => ['session' => 'relace', 'days' => 'dni', 'until_closed' => 'zavreni', 'until_submitted' => 'odeslani', 'always' => 'vzdy'],
        'vzor' => ['newsletter' => 'newsletter', 'lead_magnet' => 'magnet', 'announcement_bar' => 'lista', 'discount' => 'sleva', 'event' => 'udalost', 'blank' => 'prazdny'],
    ];
    /** Pravidla pop-up okna: anglický klíč => český, a výčtové hodnoty. */
    private const array PRAVIDLA = ['where' => 'kde', 'pages' => 'stranky', 'collections' => 'kolekce', 'news' => 'novinky', 'language' => 'jazyk', 'from' => 'od', 'to' => 'do',
        'device' => 'zarizeni', 'campaign' => 'utm', 'referrer' => 'odkud'];
    private const array HODNOTY_PRAVIDEL = ['kde' => ['all' => 'vse', 'selected' => 'vybrane'], 'zarizeni' => ['all' => 'vse', 'desktop' => 'pocitac', 'phone' => 'telefon']];
    private const array STAV_NOVINEK = ['all' => 'vse', 'published' => 'vydane', 'scheduled' => 'plan', 'drafts' => 'koncepty'];
    private const array STAV_POPTAVEK = ['all' => 'vse', 'new' => 'nove', 'read' => 'prectene', 'resolved' => 'vyrizene'];
    private const array TYPY_POLI = ['text' => 'text', 'lines' => 'radky', 'html' => 'html', 'image' => 'obrazek', 'link' => 'odkaz', 'number' => 'cislo', 'date' => 'datum'];
    private const array POLE_KOLEKCE = ['key' => 'klic', 'label' => 'popisek', 'type' => 'typ'];
    private const array MENU = ['type' => 'typ', 'page_id' => 'ids', 'text' => 'text', 'url' => 'url', 'new_window' => 'nove_okno', 'children' => 'deti'];
    private const array TYPY_MENU = ['page' => 'stranka', 'link' => 'odkaz', 'news' => 'novinky', 'group' => 'skupina'];
    private const array OPERACE = ['op' => 'op', 'id' => 'id', 'content' => 'obsah', 'style' => 'styl', 'classes' => 'tridy', 'element' => 'prvek', 'elements' => 'prvky',
        'into' => 'do', 'position' => 'pozice', 'after' => 'za', 'before' => 'pred'];
    private const array TYPY_OPERACI = ['update' => 'uprav', 'replace' => 'nahrad', 'delete' => 'smaz', 'insert' => 'vloz', 'move' => 'presun'];

    /** Klíče nastavení v angličtině => česky; u názvu a popisu webu i s kódem jazyka (site_name_de => nazev_webu_de). */
    private const array NASTAVENI = [
        'site_name' => 'nazev_webu', 'site_description' => 'popis_webu', 'footer_text' => 'text_paticky', 'logo' => 'logo_webu', 'favicon' => 'favicon', 'share_image' => 'og_obrazek',
        'home_page' => 'titulni_stranka', 'social_facebook' => 'soc_facebook', 'social_instagram' => 'soc_instagram', 'social_x' => 'soc_x', 'social_youtube' => 'soc_youtube',
        'social_linkedin' => 'soc_linkedin', 'news_per_page' => 'pocet_clanku', 'share_buttons' => 'sdileni', 'article_outline' => 'osnova_clanku', 'related_news_auto' => 'souvisejici_auto', 'dark_mode' => 'tmavy_rezim', 'theme_switcher' => 'tmavy_prepinac',
        'company_name' => 'firma_nazev', 'company_type' => 'firma_typ', 'company_id' => 'firma_ico', 'company_vat_id' => 'firma_dic', 'company_register' => 'firma_rejstrik', 'company_representative' => 'firma_zastupce', 'company_street' => 'firma_ulice',
        'company_city' => 'firma_mesto', 'company_postcode' => 'firma_psc', 'company_country' => 'firma_zeme', 'company_phone' => 'firma_telefon', 'company_email' => 'firma_email',
        'company_hours' => 'firma_hodiny', 'company_map' => 'firma_mapa', 'company_gps' => 'firma_gps',
    ];

    /** Klíče výsledků česky => anglicky. */
    private const array KLICE = [
        'nezname_parametry' => 'unknown_parameters', 'id' => 'id', 'ids' => 'page_id', 'idc' => 'id', 'idp' => 'id', 'idr' => 'version_id', 'nazev' => 'name', 'titulek' => 'title', 'adresa' => 'url', 'seo_link' => 'slug',
        'popis' => 'description', 'nahled' => 'preview', 'text' => 'content', 'stranka' => 'page', 'stranky' => 'pages', 'stav' => 'status', 'jazyk' => 'language', 'varianta' => 'variant',
        'cast' => 'part', 'kolekce' => 'collection', 'typ' => 'type', 'pole' => 'fields', 'klic' => 'key', 'popisek' => 'label', 'hodnota' => 'value', 'datum' => 'date',
        'zobrazit' => 'visible', 'zobrazena' => 'visible', 'v_menu' => 'in_menu', 'poradi' => 'order', 'uvodni' => 'home', 'uvodni_stranka' => 'home_page', 'zmeneno' => 'changed',
        'publikovana' => 'published', 'neulozene_zmeny' => 'unsaved_changes', 'prvku' => 'elements', 'chyby' => 'errors', 'kontrola' => 'check', 'zprava' => 'message',
        'stavitel' => 'builder_url', 'uprava_v_administraci' => 'admin_url', 'plati_do' => 'valid_until', 'verze' => 'versions', 'kdy' => 'when', 'kdo' => 'who', 'celkem' => 'total',
        'strana' => 'page', 'stran' => 'pages', 'polozky' => 'items', 'polozek' => 'items', 'detail' => 'item_pages', 'detail_zapnuty' => 'item_pages', 'web' => 'site',
        'verze_kaleta' => 'kaleta_version', 'stranek' => 'pages', 'novinek_vydanych' => 'published_news', 'novinek' => 'news', 'uzivatel' => 'user', 'role' => 'role',
        'smi_vydavat' => 'can_publish', 'smi_upravovat_stranky' => 'can_edit_pages', 'umisteni' => 'location', 'automaticke' => 'automatic', 'na_webu' => 'on_site',
        'ulozeno' => 'saved', 'smazano' => 'deleted', 'hlaseni' => 'notes', 'design_system' => 'design_system', 'citelnost' => 'readability', 'seo_titulek' => 'seo_title',
        'seo_popis' => 'seo_description', 'obrazek' => 'image', 'obrazek_popis' => 'image_caption', 'noindex' => 'noindex', 'nadrazena' => 'parent', 'preklad_z' => 'translation_of',
        'zverejnit_od' => 'publish_at', 'uvod' => 'intro', 'kategorie' => 'category', 'stitky' => 'tags', 'visible' => 'published', 'vydana' => 'published', 'formular' => 'form',
        'email' => 'email', 'kampan' => 'campaign', 'url' => 'url', 'rozmery' => 'size', 'velikost' => 'size', 'soubory' => 'files', 'sablona' => 'theme', 'presmerovani' => 'redirects', 'nenalezeno' => 'not_found', 'z' => 'from', 'na' => 'to', 'pocet' => 'count', 'naposledy' => 'last_seen',
        'cesta' => 'path', 'neplatna_pole' => 'invalid_fields', 'chyby_operaci' => 'operation_errors', 'texty' => 'texts', 'nezname_klice' => 'unknown_keys', 'nastaveni' => 'settings', 'faq' => 'faq', 'data' => 'values', 'stavba' => 'build',
        'vlastnosti' => 'properties', 'deti' => 'children', 'nove_okno' => 'new_window', 'popup' => 'popup', 'aktivni' => 'active',
    ];

    /** Klíče, jejichž hodnoty se nepřekládají: JSON stavby, hodnoty polí položek, design system, hlášení převodu a kontrol. */
    private const array BEZ_PREKLADU = ['stavba', 'data', 'design_system', 'chyby', 'hlaseni', 'citelnost', 'chyby_operaci', 'styl', 'css', 'vlastnosti'];

    /** Výjimky z KLICE podle nástroje (anglický název => [český klíč => anglický]). */
    private const array KLICE_NASTROJE = [
        'list_media' => ['adresa' => 'path', 'obrazek' => 'is_image'],
        'upload_file' => ['adresa' => 'path', 'obrazek' => 'is_image'],
        'list_categories' => ['adresa' => 'slug'],
        'create_category' => ['adresa' => 'slug'],
        'get_page' => ['ids' => 'id'],
        'list_redirects' => ['typ' => 'code'],
        'save_redirect' => ['typ' => 'code'],
    ];

    private const array STAVY = [
        'publikováno' => 'published', 'koncept – na webu se ukáže po publikování' => 'draft – shown on the site after publishing',
        'koncept zahozen – platí publikovaná podoba' => 'draft discarded – the published version applies',
        'uloženo – stavbu varianty uprav stavba_* s parametrem varianta a publikuj; do publikování platí výchozí podoba'
            => 'saved – edit the variant with the *_build tools and the variant parameter, then publish it; until then the default applies',
        'v koši – obnovit jde 30 dní v administraci (Stránky → Koš)' => 'in the trash – it can be restored for 30 days in the admin (Pages → Trash)',
        'varianta smazána – vybrané stránky mají výchozí podobu' => 'variant deleted – the selected pages use the default',
        'zveřejněná' => 'visible', 'skrytá' => 'hidden', 'koncept' => 'draft', 'naplánováno' => 'scheduled', 'vydáno' => 'published',
        'nove' => 'new', 'prectene' => 'read', 'vyrizene' => 'resolved', 'autor' => 'author', 'editor' => 'editor', 'správce' => 'administrator',
    ];
    private const array CASTI = ['hlavicka' => 'header', 'paticka' => 'footer', 'novinka' => 'news_item', 'vypis' => 'news_list', 'nenalezeno' => 'not_found'];

    /** Chybová hlášení nástrojů česky => anglicky; hlášení s proměnnou částí jako vzor (regulární výraz => náhrada). */
    private const array ZPRAVY = [
        'Chybí data souboru v base64 (parametr data), nebo url.' => 'The file data in base64 (the data parameter) or url is missing.',
        'Chybí kategorie.' => 'The category is missing.',
        'Chybí název kategorie.' => 'The category name is missing.',
        'Chybí název kolekce.' => 'The collection name is missing.',
        'Kopie stavby potřebuje preklad_z – ID stránky ve výchozím jazyce, a jazyk překladu.' => 'copy_build needs translation_of – the ID of the page in the default language – and the language of the translation.',
        'Datum nemá platný tvar (RRRR-MM-DD HH:MM).' => 'The date is not valid (YYYY-MM-DD HH:MM).',
        'K novinkám nemáš přístup (role uživatele).' => 'You have no access to news (user role).',
        'Kategorie smí zakládat editor nebo správce.' => 'Only editors and administrators can create categories.',
        'Kolekce neexistuje. Použij nástroj seznam_kolekci.' => 'The collection does not exist. Use list_collections.',
        'Kolekce neexistuje. Použij seznam_kolekci.' => 'The collection does not exist. Use list_collections.',
        'Kolekce smí upravovat editor nebo správce.' => 'Only editors and administrators can change collections.',
        'Menu smí upravovat jen správce.' => 'Only administrators can change the menu.',
        'Nadřazená stránka musí existovat, mít stejný jazyk a nesmí to být tahle stránka ani její podstránka.' => 'The parent page must exist, have the same language and must not be this page or one of its subpages.',
        'Není co publikovat.' => 'There is nothing to publish.',
        'Neplatná adresa položky.' => 'The item address is not valid.',
        'Novinka musí mít titulek.' => 'The news item needs a headline.',
        'Novinka neexistuje nebo k ní uživatel nemá přístup.' => 'The news item does not exist or the user has no access to it.',
        'Novinky jsou na tomto webu vypnuté (Rozšíření).' => 'News is switched off on this site (Extensions).',
        'Nová adresa musí být cesta (/nova) nebo https://… adresa.' => 'The new address must be a path (/new) or an https://… address.',
        'Název souboru musí mít příponu (např. foto.jpg, logo.svg, pismo.woff2).' => 'The file name needs an extension (e.g. photo.jpg, logo.svg, font.woff2).',
        'Parametr stavba musí být objekt {"v":1,"deti":[…]}.' => 'The build parameter must be an object {"v":1,"deti":[…]}.',
        'Pop-up okna smí měnit jen správce webu.' => 'Only the site administrator can change pop-up windows.',
        'Pop-up okno neexistuje. Použij nástroj seznam_popupu.' => 'The pop-up window does not exist. Use list_popups.',
        'Tuto adresu už používá jiné okno.' => 'Another window already uses this address.',
        'Parametr pravidla musí být objekt.' => 'The rules parameter must be an object.',
        'Okno nejdřív publikuj (publikuj_stavbu s parametrem popup) – teprve pak ho jde zapnout.' => 'Publish the window first (publish_build with the popup parameter) – then it can be activated.',
        'Parametr polozky musí být seznam položek menu, nebo null pro automatické menu.' => 'The items parameter must be a list of menu items, or null for the automatic menu.',
        'Parametr data musí být objekt {"klic":"hodnota"} podle polí kolekce.' => 'The data parameter must be an object {"key":"value"} with the collection fields.',
        'Položka musí mít název.' => 'The item needs a name.',
        'Položka v kolekci není. Použij seznam_polozek_kolekce.' => 'The item is not in the collection. Use list_collection_items.',
        'Poptávky smí číst jen uživatel s právem k Poptávkám (rozšíření Formuláře a poptávky musí být zapnuté).' => 'Only users with access to Enquiries can read them (the Forms and enquiries extension must be on).',
        'Publikovat smí jen editor nebo správce; koncept zůstává uložený.' => 'Only editors and administrators can publish; the draft stays saved.',
        'Rozšíření Přesměrování je vypnuté (Rozšíření v administraci).' => 'The Redirects extension is off (Extensions in the admin).',
        'Stahovat jde jen z https adresy.' => 'Files can be downloaded only from an https address.',
        'Stará cesta musí být cesta na tomto webu, např. /stara-stranka.' => 'The old path must be a path on this site, e.g. /old-page.',
        'Stránka musí mít název.' => 'The page needs a name.',
        'Stránka neexistuje. Použij nástroj seznam_stranek.' => 'The page does not exist. Use list_pages.',
        'Stránku smí smazat editor nebo správce.' => 'Only editors and administrators can delete pages.',
        'Stránky smí upravovat editor nebo správce.' => 'Only editors and administrators can change pages.',
        'Styl obsahuje zastaralé spustitelné konstrukce (expression, behavior). Nic se neuložilo.' => 'The style contains obsolete executable constructs (expression, behavior). Nothing was saved.',
        'Tento nástroj smí použít jen správce webu.' => 'Only the site administrator can use this tool.',
        'Uživatel nemá právo vydávat – novinku lze uložit jen jako koncept.' => 'The user cannot publish – the news item can be saved only as a draft.',
        'Varianta musí mít název.' => 'The variant needs a name.',
        'Varianta neexistuje. Použij nástroj seznam_casti.' => 'The variant does not exist. Use list_site_parts.',
        'Varianta neexistuje. Varianty záhlaví a patičky vypíše seznam_casti, založí uloz_variantu.' => 'The variant does not exist. list_site_parts lists header and footer variants, save_part_variant creates one.',
        'Verze neexistuje. Použij nástroj stavba_verze.' => 'The version does not exist. Use list_build_versions.',
        'Vydanou novinku může upravit jen editor nebo správce.' => 'Only editors and administrators can change a published news item.',
        'Zatím není publikovaná verze – není k čemu se vrátit.' => 'There is no published version yet – nothing to go back to.',
        'Zveřejněnou stránku smí upravit jen editor nebo správce.' => 'Only editors and administrators can change a visible page.',
        'Zveřejnění naplánuje jen editor nebo správce.' => 'Only editors and administrators can schedule publishing.',
        'Úvodní stránku smazat nejde – nejdřív nastav jinou (uprav_nastaveni → titulni_stranka).' => 'The home page cannot be deleted – set another one first (update_settings → home_page).',
        'Čas zveřejnění už proběhl – zadej budoucí čas, nebo stránku zveřejni parametrem zobrazit.' => 'The publishing time has passed – give a future time, or make the page visible with the visible parameter.',
        'Části webu (záhlaví, patičku, obálky) smí měnit jen správce webu.' => 'Only the site administrator can change site parts (header, footer, wrappers).',
        'Šablonu detailu kolekce smí měnit jen správce webu.' => 'Only the site administrator can change the item page template of a collection.',
    ];
    private const array VZORY = [
        '/^Adresu „(.*)“ používá systém, zvol jinou\.$/su' => 'The address “$1” is used by the system, choose another.',
        '/^Adresu „(.*)“ už používá systém nebo jiná kolekce\.$/su' => 'The address “$1” is already used by the system or another collection.',
        '/^Jazyková verze „(.*)“ není zapnutá .*$/su' => 'The language version “$1” is not switched on (Extensions → Language versions, languages in Settings).',
        '/^Kategorie „(.*)“ neexistuje\. Použij nástroj seznam_kategorii\.$/su' => 'The category “$1” does not exist. Use list_categories.',
        '/^Neznámá část webu\. Typy: .*$/su' => 'Unknown site part. Parts: header, footer, news_item, news_list, not_found.',
        '/^Neznámý nástroj: (.*)$/su' => 'Unknown tool: $1',
        '/^Neznámý vzor okna\. .*$/su' => 'Unknown pop-up template. Templates: newsletter, lead_magnet, announcement_bar, discount, event, blank.',
        '/^Neplatná hodnota „typ“\. .*$/su' => 'Invalid type. Allowed: window, slide_in, top_bar, bottom_bar, fullscreen.',
        '/^Neplatná hodnota „spoustec“\. .*$/su' => 'Invalid trigger. Allowed: time, scroll, exit, idle, pages, click.',
        '/^Neplatná hodnota „cetnost“\. .*$/su' => 'Invalid frequency. Allowed: session, days, until_closed, until_submitted, always.',
        '/^Předvolba neexistuje: (.*)$/su' => 'The preset does not exist: $1',
        '/^Sekce v knihovně není\. Klíče: (.*)$/su' => 'The section is not in the library. Keys: $1',
        '/^Soubor je větší než (\d+) MB\.$/su' => 'The file is larger than $1 MB.',
        '/^Soubor se nepodařilo stáhnout: (.*)$/su' => 'The file could not be downloaded: $1',
        '/^Stránka s adresou „(.*)“ už existuje\.$/su' => 'A page with the address “$1” already exists.',
        '/^Varianty mají jen záhlaví a patička: .*$/su' => 'Only the header and the footer have variants: header, footer.',
    ];

    /** Hlášení převodu HTML a tříd (Stavitel\ZHtml) – vzor => anglicky. */
    private const array HLASENI = [
        '/^Třída \.(\S+) už na webu je – ponechána beze změny\.$/su' => 'The class .$1 already exists on the site – left unchanged.',
        '/^Třídy bez stylu vynechány: (.*)$/su' => 'Classes without a style were left out: $1',
        '/^Značka <(\w+)> mimo formulář nemá ve stavbě obdobu – vynechána\.$/su' => 'The <$1> tag outside a form has no builder equivalent – left out.',
        '/^Vložené styly \(atribut style\) se nepřevádějí – vzhled patří do tříd v <style>\.$/su' => 'Inline styles (the style attribute) are not converted – the look belongs in classes in <style>.',
        '/^Příliš hluboké vnoření – nejhlubší část převedena jako text\.$/su' => 'Nesting too deep – the deepest part was converted as text.',
        '/^Pole typu (\S+) formulář nepodporuje – vynecháno\.$/su' => 'The form does not support fields of type $1 – left out.',
        '/^Formulář převeden na prvek Formulář: .*$/su' => 'The form was converted to the Form element: it sends to the site’s Enquiries and by e-mail (the action address is not used).',
        '/^Značka <(\w+)> jde vložit jen jako Vlastní HTML, a to smí jen správce webu – vynechána\.$/su' => 'The <$1> tag can only be added as Custom HTML, which only the site administrator may do – left out.',
        '/^Pravidlo @media (.*) se nepřevádí – .*$/su' => 'The @media $1 rule is not converted – the builder has the breakpoints @media (max-width: 1023px) = tablet and (max-width: 767px) = mobile; style from desktop down.',
        '/^V @media se převádějí jen selektory jedné třídy; vynecháno: (.*)$/su' => 'Inside @media only single-class selectors are converted; left out: $1',
        '/^Pravidla (@.*) se nepřevádějí – .*$/su' => 'The $1 rules are not converted – set them in the style of an element or class in the builder.',
        '/^Třída \.(\S+): nepovolená deklarace „(.*)“ vynechána\.$/su' => 'Class .$1: the declaration “$2” is not allowed – left out.',
        '/^Převádějí se jen selektory jedné třídy \(\.karta, \.karta:hover\); vynecháno: (.*)$/su' => 'Only single-class selectors are converted (.card, .card:hover); left out: $1',
        '/^Třída \.(\S+) \((\w+)\): deklaraci „(.*)“ builder ve stavu neumí – vynechána\.$/su' => 'Class .$1 ($2): the builder cannot use the declaration “$3” in a state – left out.',
        '/^Nepovolená deklarace: (.*)$/su' => 'Declaration not allowed: $1',
        '/^Atribut může být jen data-…, aria-…, title, lang, role nebo rel\.$/su' => 'An attribute can only be data-…, aria-…, title, lang, role or rel.',
        '/^Datum podmínky zobrazení musí být ve tvaru RRRR-MM-DD\.$/su' => 'The date of a display condition must be YYYY-MM-DD.',
        '/^Kotvu „(.*)“ už na stránce používá jiný prvek nebo šablona – vynechána\.$/su' => 'The anchor “$1” is already used on the page by another element or the theme – left out.',
        '/^Najednou jde provést nejvýš (\d+) operací – zbytek vynechán\.$/su' => 'At most $1 operations can run at once – the rest was left out.',
        '/^Neplatná hodnota „(.*)“\.$/su' => 'Invalid value “$1”.',
        '/^Neznámá vlastnost stylu\.$/su' => 'Unknown style property.',
        '/^Neznámý breakpoint nebo stav \(povolené: (.*)\)\.$/su' => 'Unknown breakpoint or state (allowed: $1).',
        '/^Neznámý typ prvku „(.*)“ – vynechán\. Typy: (.*)$/su' => 'Unknown element type “$1” – left out. Types: $2',
        '/^Obrázek musí být z Médií \(media\/…\) nebo na adrese https:\/\/\.$/su' => 'An image must come from Media (media/…) or an https:// address.',
        '/^Odkaz může být jen https:\/\/…, mailto:, tel:, #kotva nebo adresa na webu \(\/…\)\.$/su' => 'A link can only be https://…, mailto:, tel:, #anchor or an address on the site (/…).',
        '/^Operace musí být objekt\.$/su' => 'An operation must be an object.',
        '/^Prvek „(.*)“ nemůže obsahovat další prvky – vynechány\.$/su' => 'The “$1” element cannot contain other elements – left out.',
        '/^Prvek „(.*)“ smí vložit jen správce webu – vynechán\.$/su' => 'Only the site administrator can add the “$1” element – left out.',
        '/^Příliš hluboké vnoření – vnořené prvky vynechány\.$/su' => 'Nesting too deep – the nested elements were left out.',
        '/^Stavba musí být objekt \{"v": 1, "deti": \[\.\.\.\]\}\.$/su' => 'The build must be an object {"v": 1, "deti": [...]}.',
        '/^Stavba má víc než (\d+) prvků – zbytek vynechán\.$/su' => 'The build has more than $1 elements – the rest was left out.',
        '/^Chybí "prvek"\.$/su' => 'The "element" is missing.',
        '/^Chybí "prvky" \(pole prvků\) nebo "prvek"\.$/su' => 'The "elements" (a list of elements) or "element" is missing.',
        '/^Neznámá operace \(op\): .*$/su' => 'Unknown operation (op): update | replace | delete | insert | move.',
        '/^Prvek nejde přesunout do sebe sama\.$/su' => 'An element cannot be moved into itself.',
        '/^Prvek „(.*)“ \(za\/pred\) ve stavbě není\.$/su' => 'The element “$1” (after/before) is not in the build.',
        '/^Prvek „(.*)“ ve stavbě není\.(?: Id najdeš ve stavba_nacti\.)?$/su' => 'The element “$1” is not in the build. Element ids are in get_build.',
    ];
    private const array NAZVY_CASTI = ['Záhlaví' => 'Header', 'Patička' => 'Footer', 'Detail novinky' => 'News item', 'Výpis novinek' => 'News list', 'Stránka nenalezena (404)' => 'Page not found (404)'];

    /** @return list<string> anglické názvy nástrojů */
    public static function nazvy(): array
    {
        return array_keys(self::NASTROJE);
    }

    /** @return list<string> české nástroje, které mají anglický název */
    public static function ceske(): array
    {
        return array_column(self::NASTROJE, 0);
    }

    public static function cesky(string $nazev): ?string
    {
        return self::NASTROJE[$nazev][0] ?? null;
    }

    /**
     * Anglické definice nástrojů z českých (typy a povinnost parametrů se převezmou, popisy jsou anglické).
     *
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>}> $ceske
     * @return list<array<string, mixed>>
     */
    public static function seznam(array $ceske): array
    {
        $podleNazvu = array_column($ceske, null, 'name');
        $vysledek = [];
        foreach (self::NASTROJE as $en => [$cs, $popis]) {
            if (!isset($podleNazvu[$cs])) {
                continue; // nástroj vypnutého rozšíření
            }
            $schema = $podleNazvu[$cs]['inputSchema'];
            $vlastnosti = [];
            $povinne = [];
            foreach (self::parametry($en) as $enParam => [$csParam, $popisParam]) {
                if (!isset($schema['properties'][$csParam])) {
                    continue;
                }
                $vlastnosti[$enParam] = ['description' => $popisParam] + $schema['properties'][$csParam];
                $vlastnosti[$enParam]['description'] = $popisParam;
                if (in_array($csParam, $schema['required'] ?? [], true)) {
                    $povinne[] = $enParam;
                }
            }
            $vysledek[] = ['name' => $en, 'description' => $popis,
                'inputSchema' => ['type' => 'object', 'properties' => $vlastnosti === [] ? new \stdClass() : $vlastnosti, 'required' => $povinne]];
        }

        return $vysledek;
    }

    /** @return array<string, array{0: string, 1: string}> parametry nástroje s rozbalenými skupinami */
    private static function parametry(string $nazev): array
    {
        $vysledek = [];
        foreach (self::NASTROJE[$nazev][2] as $klic => $parametr) {
            if (is_int($klic)) {
                $vysledek += match ($parametr) {
                    '*cil' => self::CIL,
                    '*stranka' => self::STRANKA,
                    '*novinka' => self::NOVINKA,
                };
                continue;
            }
            $vysledek[$klic] = $parametr;
        }

        return $vysledek;
    }

    /**
     * Anglické argumenty na české (neznámé klíče zůstanou, jak jsou – české parametry tak fungují i u anglického názvu).
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    public static function argumenty(string $nazev, array $a): array
    {
        $mapa = array_map(fn (array $p): string => $p[0], self::parametry($nazev));
        $vysledek = [];
        foreach ($a as $klic => $hodnota) {
            $cs = $mapa[$klic] ?? $klic;
            $vysledek[$cs] = match (true) {
                isset(self::HODNOTY_VSTUPU[$cs]) && is_string($hodnota) => self::HODNOTY_VSTUPU[$cs][$hodnota] ?? $hodnota,
                $cs === 'stav' && is_string($hodnota) => ($nazev === 'list_news' ? self::STAV_NOVINEK : self::STAV_POPTAVEK)[$hodnota] ?? $hodnota,
                $cs === 'pole' && is_array($hodnota) => array_map(fn (mixed $p): mixed => is_array($p) ? self::poleKolekce($p) : $p, $hodnota),
                $cs === 'polozky' && is_array($hodnota) => array_map(self::polozkaMenu(...), $hodnota),
                $cs === 'operace' && is_array($hodnota) => array_map(self::operace(...), $hodnota),
                $cs === 'nastaveni' && is_array($hodnota) => self::klicNastaveni($hodnota, true),
                $cs === 'pravidla' && is_array($hodnota) => self::pravidla($hodnota, true),
                default => $hodnota,
            };
        }

        return $vysledek;
    }

    private static function poleKolekce(array $p): array
    {
        $vysledek = [];
        foreach ($p as $k => $h) {
            $cs = self::POLE_KOLEKCE[$k] ?? $k;
            $vysledek[$cs] = $cs === 'typ' && is_string($h) ? (self::TYPY_POLI[$h] ?? $h) : $h;
        }

        return $vysledek;
    }

    private static function polozkaMenu(mixed $p): mixed
    {
        if (!is_array($p)) {
            return $p;
        }
        $vysledek = [];
        foreach ($p as $k => $h) {
            $cs = self::MENU[$k] ?? $k;
            $vysledek[$cs] = match ($cs) {
                'typ' => is_string($h) ? (self::TYPY_MENU[$h] ?? $h) : $h,
                'deti' => is_array($h) ? array_map(self::polozkaMenu(...), $h) : $h,
                default => $h,
            };
        }

        return $vysledek;
    }

    private static function operace(mixed $o): mixed
    {
        if (!is_array($o)) {
            return $o;
        }
        $vysledek = [];
        foreach ($o as $k => $h) {
            $cs = self::OPERACE[$k] ?? $k;
            $vysledek[$cs] = $cs === 'op' && is_string($h) ? (self::TYPY_OPERACI[$h] ?? $h) : $h;
        }

        return $vysledek;
    }

    /** Pravidla pop-up okna mezi angličtinou a češtinou (klíče i výčtové hodnoty). @param array<string, mixed> $p */
    private static function pravidla(array $p, bool $naCesky): array
    {
        $klice = $naCesky ? self::PRAVIDLA : array_flip(self::PRAVIDLA);
        $vysledek = [];
        foreach ($p as $k => $h) {
            $cs = $naCesky ? ($klice[$k] ?? $k) : $k;
            if (is_string($h) && isset(self::HODNOTY_PRAVIDEL[$cs])) {
                $h = $naCesky ? (self::HODNOTY_PRAVIDEL[$cs][$h] ?? $h) : (array_search($h, self::HODNOTY_PRAVIDEL[$cs], true) ?: $h);
            }
            $vysledek[$naCesky ? $cs : ($klice[$k] ?? $k)] = $h;
        }

        return $vysledek;
    }

    /** Pop-up okno z výsledku seznam_popupu a uloz_popup anglicky. */
    private static function popupZpet(array $p): array
    {
        $vysledek = [];
        foreach ($p as $k => $h) {
            $vysledek[self::POPUP_KLICE[$k] ?? self::KLICE[$k] ?? $k] = match (true) {
                $k === 'pravidla' && is_array($h) => self::pravidla($h, false),
                isset(self::HODNOTY_VSTUPU[$k]) && is_string($h) => array_search($h, self::HODNOTY_VSTUPU[$k], true) ?: $h,
                default => $h,
            };
        }

        return $vysledek;
    }

    private const array POPUP_KLICE = ['adresa' => 'slug', 'odkaz' => 'link', 'spoustec' => 'trigger', 'cetnost' => 'frequency', 'dni' => 'days', 'pravidla' => 'rules',
        'aktivni' => 'active', 'publikovano' => 'published', 'zmeny' => 'unpublished_changes', 'zobrazeni' => 'views', 'zavreni' => 'closes', 'konverze' => 'conversions', 'nahled' => 'preview'];

    /** @param array<string, mixed> $nastaveni @return array<string, mixed> */
    private static function klicNastaveni(array $nastaveni, bool $naCesky): array
    {
        $mapa = $naCesky ? self::NASTAVENI : array_flip(self::NASTAVENI);
        $vysledek = [];
        foreach ($nastaveni as $k => $h) {
            $k = (string) $k;
            $jazyk = '';
            if (preg_match('/^(site_name|site_description|nazev_webu|popis_webu)_([a-z]{2})$/', $k, $m)) {
                [$k, $jazyk] = [$m[1], '_' . $m[2]];
            }
            $vysledek[($mapa[$k] ?? $k) . $jazyk] = $h;
        }

        return $vysledek;
    }

    /** Výsledek nástroje s anglickými klíči a stavy. */
    public static function vysledek(string $nazev, mixed $v): mixed
    {
        if ($nazev === 'builder_schema' || !is_array($v)) {
            return $v; // schéma popisuje datový model builderu – zůstává, jak je
        }
        if ($nazev === 'list_popups') {
            return array_map(fn (mixed $p): mixed => is_array($p) ? self::popupZpet($p) : $p, $v);
        }
        if ($nazev === 'save_popup') {
            return self::popupZpet($v);
        }
        if (in_array($nazev, ['get_menu', 'save_menu'], true)) {
            $menu = fn (mixed $seznam): array => array_map(fn (mixed $p): mixed => is_array($p) ? self::menuZpet($p) : $p, is_array($seznam) ? $seznam : []);
            $zbytek = array_diff_key($v, ['polozky' => 1, 'na_webu' => 1, 'umisteni' => 1]);

            return ['location' => array_search($v['umisteni'] ?? '', self::HODNOTY_VSTUPU['umisteni'], true) ?: ($v['umisteni'] ?? ''),
                'items' => $menu($v['polozky'] ?? []), 'on_site' => $menu($v['na_webu'] ?? [])] + self::prelozPole($zbytek, []);
        }

        return self::prelozPole($v, self::KLICE_NASTROJE[$nazev] ?? []);
    }

    /** @param array<string, string> $vyjimky */
    private static function prelozPole(array $v, array $vyjimky): array
    {
        $vysledek = [];
        foreach ($v as $k => $h) {
            if (is_int($k)) {
                $vysledek[$k] = is_array($h) ? self::prelozPole($h, $vyjimky) : $h;
                continue;
            }
            $vysledek[$vyjimky[$k] ?? self::KLICE[$k] ?? $k] = match (true) {
                $k === 'nastaveni' && is_array($h) => self::klicNastaveni($h, false),
                in_array($k, ['hlaseni', 'chyby', 'chyby_operaci'], true) && is_array($h) => array_map(fn (mixed $z): mixed => is_string($z) ? self::hlaseni($z) : $z, $h),
                in_array($k, self::BEZ_PREKLADU, true) => $h,
                // texty stavby pro překlad: vnitřek obsahu jsou vlastnosti prvků jako ve stavbě (text, odkaz, html…)
                $k === 'texty' && is_array($h) => array_map(fn (mixed $t): mixed => is_array($t) ? ['id' => $t['id'] ?? '', 'type' => $t['typ'] ?? '']
                    + (isset($t['obsah']) ? ['content' => $t['obsah']] : []) + (isset($t['atributy']) ? ['attributes' => $t['atributy']] : []) : $t, $h),
                $k === 'titulek' && is_string($h) && isset(self::NAZVY_CASTI[$h]) => self::NAZVY_CASTI[$h],
                is_array($h) => self::prelozPole($h, $vyjimky),
                ($k === 'stav' || $k === 'role') && is_string($h) => self::stav($h),
                $k === 'cast' && is_string($h) => self::CASTI[$h] ?? $h,
                $k === 'typ' && is_string($h) => array_search($h, self::TYPY_POLI, true) ?: $h,
                default => $h,
            };
        }

        return $vysledek;
    }

    private static function menuZpet(array $p): array
    {
        $vysledek = [];
        foreach ($p as $k => $h) {
            $en = array_search($k, self::MENU, true) ?: $k;
            $vysledek[$en] = match ($k) {
                'typ' => is_string($h) ? (array_search($h, self::TYPY_MENU, true) ?: $h) : $h,
                'deti' => is_array($h) ? array_map(fn (mixed $d): mixed => is_array($d) ? self::menuZpet($d) : $d, $h) : $h,
                default => $h,
            };
        }

        return $vysledek;
    }

    private static function hlaseni(string $h): string
    {
        foreach (self::HLASENI as $vzor => $nahrada) {
            if (preg_match($vzor, $h)) {
                return (string) preg_replace($vzor, $nahrada, $h);
            }
        }

        return $h;
    }

    private static function stav(string $h): string
    {
        if (isset(self::STAVY[$h])) {
            return self::STAVY[$h];
        }

        return preg_match('/^skrytá, zveřejní se (.+)$/u', $h, $m) ? 'hidden, will be published ' . $m[1] : $h;
    }

    /** Chybové hlášení nástroje anglicky; české názvy nástrojů v textu nahradí anglické. */
    public static function zprava(string $zprava): string
    {
        if (isset(self::ZPRAVY[$zprava])) {
            return self::ZPRAVY[$zprava];
        }
        foreach (self::VZORY as $vzor => $nahrada) {
            if (preg_match($vzor, $zprava)) {
                return (string) preg_replace($vzor, $nahrada, $zprava);
            }
        }

        return self::hlaseni($zprava);
    }

    /** Pokyny serveru pro Clauda (odpověď na initialize) – anglicky, s anglickými názvy nástrojů. */
    public static function pokyny(): string
    {
        return 'A business website on Kaleta. Write texts in the language of the site; pages and news as clean semantic HTML (p, h2, h3, ul, ol, blockquote, a, strong, em, figure/img, table). '
            . 'BUILDING A SITE: (1) site_info and builder_schema (a short overview; full element definitions through the elements parameter). '
            . '(2) The look of the whole site: update_design_system (colours, fonts, sizes); upload a custom font with upload_file (.woff2) and add it to vlastni_pisma. A repeated look (cards, labels, a dark band) belongs in shared classes – save_classes or <style> in build_from_html; a dark band = a class that overrides the tokens (--ka-barva-text, --ka-barva-pozadi, --ka-barva-primarni…) so links and buttons stay readable. '
            . '(3) Pages: create_page (it stays hidden) and build_from_html – semantic HTML by sections + <style> with rules of one class and tokens var(--ka-…), breakpoints @media (max-width: 1023px) and (max-width: 767px), no inline styles; or save_build with JSON according to the schema. Upload images with upload_file. Build the header and footer with save_build and the part parameter. '
            . '(4) Checking: every build write returns preview – a signed link to the draft valid for 60 minutes; open it and check the result, give the user a longer link from preview_link. The check field (when present) lists what the builder would flag before publishing – buttons without links, images without descriptions, the heading outline; fix them before you offer to publish. '
            . '(5) Fixes: edit_build by element id (ids from get_build) – do not send the whole build for one text. (6) Site settings with update_settings, old addresses with save_redirect. The menu (save_menu), design system, classes and settings apply to the site straight away; hidden pages appear in the menu only once they are visible. '
            . '(7) A header or footer only for some pages (a campaign without the menu): save_part_variant, then the *_build tools with the variant parameter; overview with list_site_parts. list_build_versions and restore_build_version bring back an older published version (into the draft). '
            . '(8) Pop-ups (a newsletter sign-up, a download, an announcement bar): save_popup with a template creates one (inactive), build its content with the *_build tools and the popup parameter, set type, trigger, frequency and rules with save_popup; activate it (active: true) only after publishing and only when the user asks. list_popups shows views, closes and conversions. '
            . '(9) Translating into another language version (the admin switches languages on): create_page with language, translation_of and copy_build, then get_build with texts_only and edit_build “update” operations for the texts and links (internal links point to the translated pages); the header and footer with the part and language parameters (they start as a copy of the default language); save_menu with language; a collection item translation with the same slug and language; a collection item template with collection and language. '
            . 'Builds are saved as drafts – publish (publish_build) and make pages visible only when the user explicitly asks. '
            . 'A new news item is a draft; only a user with the publishing permission can publish it, and only when explicitly asked. A new page is hidden until the user explicitly wants it visible. '
            . 'BOUNDARIES: this connection changes only content (pages, news, categories, collections, site parts) and the look (design system, classes). Do not change the system code, themes '
            . 'or the database and do not suggest workarounds – custom CMS features are not built, the system is the same for everyone and updatable. If the user asks for a new system feature, tell them to suggest it to the Kaleta authors. '
            . 'Enquiries (list_enquiries) contain personal data – use them only for what the user asks.';
    }
}
