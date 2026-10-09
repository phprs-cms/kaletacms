<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

/**
 * English MCP interface: tool names, parameters, descriptions, result keys, statuses and error messages in English.
 *
 * Tools are implemented once (Tools, Czech names and parameters); this layer only translates on input and output.
 * Czech names remain as hidden aliases (tools/list does not list them) and behave as before – Claude connections that
 * remember them keep working. The builder data model (build JSON, element schema, design system keys) stays as it is.
 */
final class Translator
{
    /** Shared target of the build tools: page, site part (variant) or collection item template. */
    private const array TARGET = [
        'id' => ['id', 'Page ID'],
        'part' => ['cast', 'Instead of a page, a site part (administrators only): header | footer | news_item | news_list | not_found – the header, the footer and the wrappers of a news item, the news list and the 404 page'],
        'language' => ['jazyk', 'Language version of the site part or of the collection item template on a multilingual site (empty = default)'],
        'variant' => ['varianta', 'Header or footer variant (key from list_site_parts; empty = the default)'],
        'collection' => ['kolekce', 'Instead of a page, the item page template of a collection (collection slug from list_collections, administrators only); with "language", the template of that language version'],
        'category_template' => ['kategorie_sablona', 'With collection: the template of the collection\'s category pages instead of its item pages (3.7) – {{nazev}}, {{popis}}, {{obrazek}}, {{pocet}} fill in; a Collection list with category "*" lists the category\'s items (source items) or its subcategories (source categories)'],
        'popup' => ['popup', 'Instead of a page, the content of a pop-up window (ID from list_popups, administrators only)'],
        'component' => ['komponenta', 'Instead of a page, the build of a component (ID from list_components, administrators only) – a change shows everywhere it is used'],
    ];

    private const array PAGE = [
        'title' => ['titulek', 'Page name (shown in the navigation and as the heading)'],
        'content' => ['text', 'Page content as HTML'],
        'slug' => ['adresa', 'Part of the address after the domain; without it, it is made from the title'],
        'description' => ['popis', 'Description for search engines, up to 160 characters'],
        'in_menu' => ['v_menu', 'true = link in the main navigation'],
        'order' => ['poradi', 'Order in the navigation, lower = first'],
        'visible' => ['zobrazit', 'true = the page is visible on the site (only when the user explicitly asks), otherwise hidden. A page with no text and no published build waits hidden and shows with its first publish_build or text'],
        'seo_title' => ['seo_titulek', 'Title for search engines (optional, otherwise the name)'],
        'share_image' => ['obrazek', 'Image for sharing on social networks (path from Media)'],
        'noindex' => ['noindex', 'true = hide the page from search engines'],
        'parent' => ['nadrazena', 'ID of the parent page – the address becomes /parent/page (0 = none)'],
        'language' => ['jazyk', 'Language version of the page on a multilingual site (code such as de; empty = default language)'],
        'translation_of' => ['preklad_z', 'ID of the counterpart in the default language (for a page in another language version) – language switcher and hreflang'],
        'copy_build' => ['kopie_stavby', 'only for a new page with translation_of: the draft starts as a copy of the original’s build – then translate with get_build (texts_only) and edit_build'],
        'publish_at' => ['zverejnit_od', 'Scheduled publishing of a hidden page YYYY-MM-DD HH:MM (only when the user explicitly asks; empty = cancel)'],
        'head_code' => ['kod_hlavicky', 'Not settable through MCP (since 2.5.1): code for <head> of a page is set by the administrator in the administration – tell the user where'],
        'valid_until' => ['valid_until', 'True until YYYY-MM-DD (2.10): after this day it hides itself on its own (the change is logged and recorded as the event content.expired); an empty string = always (optional)'],
        'review_by' => ['review_by', 'Review by YYYY-MM-DD (2.10): on this day the site audit (kind review) and the event content.review ask the user to check it; an empty string = none (optional)'],
    ];

    private const array NEWS_ITEM = [
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
        'valid_until' => ['valid_until', 'True until YYYY-MM-DD (2.10): after this day it hides itself on its own (the change is logged and recorded as the event content.expired); an empty string = always (optional)'],
        'review_by' => ['review_by', 'Review by YYYY-MM-DD (2.10): on this day the site audit (kind review) and the event content.review ask the user to check it; an empty string = none (optional)'],
    ];

    /**
     * English name => [Czech tool, description, parameters [English => [Czech, description]]]. Parameter '*cil' = shared
     * build target.
     *
     * @var array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private const array TOOLS = [
        'site_info' => ['info_o_webu', 'Site name, home page, theme, numbers of pages and news, the role of the signed-in user and their permissions.', []],
        'list_pages' => ['seznam_stranek', 'Pages of the site (Home, About, Services, Contact…) with their addresses.', []],
        'get_page' => ['nacti_stranku', 'The whole page including its HTML content.', ['id' => ['id', 'Page ID']]],
        'create_page' => ['vytvor_stranku', 'Creates a page (editors and administrators). Without "visible": true it stays hidden.', ['*stranka']],
        'update_page' => ['uprav_stranku', 'Changes the given fields of a page; the others stay.', ['id' => ['id', 'Page ID'], '*stranka']],
        'get_menu' => ['nacti_menu', 'The site menu (main or footer) for a language version: items with submenus, and whether the main menu is still built automatically from pages “in menu”.',
            ['location' => ['umisteni', 'main (default) | footer'], 'language' => ['jazyk', 'language version (empty = default)']]],
        'save_menu' => ['uloz_menu', 'Saves the whole menu (administrators) into the draft look – visitors see it after publish_look. Items: {"type":"page","page_id":5,"text":""} (empty text = page name) | {"type":"link","text":"…","url":"https://… or /path","new_window":false} | {"type":"news"} | {"type":"group","text":"Services"} – each may have "children" (one submenu level), an "icon" (a name from the Icon element, e.g. "phone") and a "description" (up to 120 characters, shown under the label in a mega menu). A group inside a submenu may have its own "children": in a mega menu (Navigation element, mega_menu: true) it is a column with the group text as its heading. null = the main menu is automatic again. A hidden page appears in the menu only once it is visible.',
            ['location' => ['umisteni', 'main | footer'], 'language' => ['jazyk', 'language version (empty = default)'], 'items' => ['polozky', 'menu items']]],
        'builder_schema' => ['stavba_schema', 'How a page is put together in the builder: element types and their fields, style properties, design system tokens (colours, spacing, type), the section library and the shared classes of the site. Load it before you first use the *_build tools. Returns a short overview (one element per line); full definitions of chosen elements through the elements parameter. The build JSON uses the builder’s own (Czech) keys: typ, znacka, obsah, styl, tridy, deti, kotva.',
            ['elements' => ['prvky', 'element types to get the full definition for (field labels, default children), e.g. ["form","carousel"]'], 'full' => ['uplne', 'true = the whole schema with all labels (large)']]],
        'get_build' => ['stavba_nacti', 'The build of a page or site part (a tree of elements with ids) – the draft in progress, otherwise the published version. Default values are left out. A page without a build returns a build made from its text. '
            . 'With texts_only just the texts and links of elements by id (for translating: send them back as “update” operations in edit_build).',
            ['*cil', 'texts_only' => ['jen_texty', 'true = instead of the build a list texts: [{id, type, content: only text properties and links, attributes}]']]],
        'edit_build' => ['stavba_uprav', 'Partial edits of the draft by element id (ids from get_build) – fix a text, a link or a style without sending the whole build. Operations: '
            . '{"op":"update","id":"…","content":{…},"style":{"mobile":{"gap":"s"}},"classes":[…]} (content and style merge, a null value removes) | {"op":"replace","id":"…","element":{…}} | {"op":"delete","id":"…"} | '
            . '{"op":"insert","elements":[…],"into":"parent id or null = root","position":0 | "after":"id" | "before":"id"} | {"op":"move","id":"…","into":…,"after":…}. Elements use the build JSON keys of builder_schema (type, content, style, children…).',
            ['*cil', 'operations' => ['operace', 'list of operations, applied in order'], 'publish' => ['publikovat', 'true = publish (only when the user explicitly asks)']]],
        'list_classes' => ['seznam_trid', 'Shared classes of the site (card, dark band…) with their style per state and custom CSS. An element gets a class in its "tridy" list.', ['name' => ['nazev', 'only this class (optional)']]],
        'save_classes' => ['uloz_tridy', 'Creates or changes shared classes (administrators). A new class applies at once; a change or deletion of an existing one goes to the draft look (publish_look). Write CSS as in a <style> block: rules of one class (.card { … }), '
            . '.card:hover { … } and @media (max-width: 1023px) = tablet, (max-width: 767px) = mobile. Use tokens var(--ka-…), and override tokens inside a class (--ka-barva-text: #fff) for dark bands.',
            ['css' => ['css', 'class rules; they merge with the existing ones – a .card:hover or @media rule alone leaves the base of the class unchanged'],
                'replace' => ['nahradit', 'true = replace the classes in css entirely (base and all states)'], 'delete' => ['smazat', 'names of classes to delete']]],
        'build_from_html' => ['stavba_z_html', 'RECOMMENDED for a new page or sections: write semantic HTML (section/header, h1–h3, p, ul, a, img, figure, blockquote, details) and put the look in a <style> block as rules of one class (.card { … }, .card:hover { … }) with tokens var(--ka-…); '
            . 'breakpoints from desktop down: @media (max-width: 1023px) = tablet, @media (max-width: 767px) = mobile. An element with a class from <style> gets no default style – layout (display:grid, gap) belongs in the class. It is converted to a build and classes; the result says what could not be converted. Saved as a draft.',
            ['html' => ['html', 'HTML of the content (without <html>/<head>); <style> may be inside. Build the header and footer from the logo, navigation and company details elements with save_build – HTML does not convert them.'],
                'id' => ['id', 'Page ID; without it (and without part) a new hidden page is created with the name from title'], 'part' => ['cast', 'Instead of a page, a site part (administrators only)'],
                'language' => self::TARGET['language'], 'variant' => self::TARGET['variant'], 'collection' => self::TARGET['collection'], 'popup' => self::TARGET['popup'],
                'title' => ['titulek', 'Name of the new page (when there is no id)'],
                'mode' => ['rezim', 'replace (default) = the whole build from the HTML | append = sections at the end of the existing build'],
                'overwrite_classes' => ['prepsat_tridy', 'true = classes that already exist on the site are overwritten by the <style>; otherwise they stay'],
                'publish' => ['publikovat', 'true = publish straight away (only when the user explicitly asks); otherwise a draft to preview']]],
        'save_build' => ['stavba_uloz', 'Saves the whole build of a page (the tree from get_build with your changes) as a draft. For small edits of content and style of single elements. Returns the cleaned build, errors and the check before publishing.',
            ['*cil', 'build' => ['stavba', '{"v":1,"children":[…]} according to builder_schema'], 'publish' => ['publikovat', 'true = publish (only when the user explicitly asks)']]],
        'insert_section' => ['vloz_sekci', 'Adds a ready-made section from the library (hero, benefits, services, numbers, testimonials, faq, call to action, news, contact) to the end of the draft of a page or site part.',
            ['*cil', 'section' => ['sekce', 'section key from builder_schema → knihovna'], 'saved_section' => ['saved_section', 'instead of a library section, a section saved in the builder (id from builder_schema → saved_sections)']]],
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
                'active' => ['aktivni', 'true = the window shows on the site (published only, only when the user explicitly asks)'], 'order' => ['poradi', 'Order, lower = first'],
                'valid_until' => ['valid_until', 'True until YYYY-MM-DD (2.10): after this day it hides itself on its own (the change is logged and recorded as the event content.expired); an empty string = always (optional)'], 'review_by' => ['review_by', 'Review by YYYY-MM-DD (2.10): on this day the site audit (kind review) and the event content.review ask the user to check it; an empty string = none (optional)']]],
        'list_site_parts' => ['seznam_casti', 'Site parts from the builder (header, footer, wrappers of a news item, the news list and the 404 page) and header and footer variants: key, name, pages they apply to and state (administrators).', []],
        'save_part_variant' => ['uloz_variantu', 'Creates or changes a header or footer variant for selected pages (administrators) – for example a header without the menu for a campaign page. A new one starts as a copy of the default as a draft; '
            . 'then edit it with the *_build tools and the variant parameter and publish it. Besides pages it can take a kind of content (3.6): news_items, news_list, collections, under_pages – a listed page always wins, otherwise the first variant by key whose choice fits. delete = true removes the variant (the selected pages get the default).',
            ['part' => ['cast', 'header | footer'], 'language' => ['jazyk', 'Language version (empty = default)'], 'variant' => ['varianta', 'key of an existing variant – only to change or delete it'],
                'name' => ['nazev', 'variant name, e.g. Campaign without menu'], 'pages' => ['stranky', 'IDs of the pages the variant applies to (they always get this variant)'],
                'news_items' => ['novinky', 'true = also on news item pages (3.6; leave out to keep the current choice)'],
                'news_list' => ['vypis', 'true = also on the news list, categories, tags and search (3.6)'],
                'collections' => ['kolekce', 'slugs of collections whose item pages get the variant (3.6, from list_collections)'],
                'under_pages' => ['nadrazene', 'IDs of parent pages – every page under them (at any depth) gets the variant (3.6)'],
                'delete' => ['smazat', 'true = delete the variant (only when the user explicitly asks)']]],
        'update_design_system' => ['uprav_design_system', 'Changes the look of the whole site (administrators) in the draft look: colours, fonts, sizes, width, corner radius – or applies a preset. Values you leave out stay. Returns a colour readability check and a whole-site preview link; publish_look publishes it.',
            ['preset' => ['predvolba', 'firemni | remeslo | pratelsky | elegantni | technologie (optional)'], 'design' => ['ds', 'Changes, e.g. {"barvy":{"primarni":"#0f766e"},"pismo_titulky":"klasicke","zaobleni":"l"} – keys in builder_schema → design_system']]],
        'list_collections' => ['seznam_kolekci', 'Collections of the site (testimonials, team, products…) with their fields and numbers of items. The “kolekce” element (Collection list) puts them on a page; inside it {{key}} is replaced by the item value ({{nazev}}, {{url}} = item page, {{datum}} and your own fields).', []],
        'create_collection' => ['vytvor_kolekci', 'Creates a collection (administrators). Fields: a list of {label, type}; type = text | lines | html | image | link | number | date | datetime | file | location | choice | item. datetime = "YYYY-MM-DD HH:MM" or a whole day "YYYY-MM-DD" ({{key}} for visitors, {{key_iso}} as stored); file = a file from Media ({{key}} its address, {{key_name}} its file name); location = "latitude, longitude"; choice needs "options": ["…", "…"]. Ready-made collections: list_collection_presets. An item field links to an item of another collection (2.10): {"label":"Branch","type":"item","collection":"branches"} – the value is the address of the linked item; in templates {{key}} = its name, {{key_url}} = its page, and a Collection list filtered by the field with the value {{seo}} on the linked item\'s page lists everything linked to it. The field key is made from the label.',
            ['name' => ['nazev', 'Name, e.g. Testimonials'], 'slug' => ['adresa', 'Address of the collection in URLs (optional, otherwise from the name), e.g. guide'],
                'fields' => ['pole', '[{"label":"Quote","type":"lines"},{"label":"Logo","type":"image"}]'], 'item_pages' => ['detail', 'true = every item has its own page /<collection>/<item>'],
                'structured_data' => ['schema_org', 'schema.org type of item pages: {"type":"Service|Person|Product|Event|FAQPage","fields":{"property":"field key"},"currency":"EUR"} – properties per type in builder_schema collection_schema; {"type":""} = none'],
                'redirect_hidden_to' => ['presmerovat_skryte', 'where the page of a hidden or deleted item leads (2.10): /team or https://… (optional; empty = page not found)'],
                'preset' => ['preset', 'a ready-made collection (2.11): its key from list_collection_presets (people, events, jobs…) – fields, item pages, structured data and what keeps it current come with it. With a preset only name is used.']]],
        'update_collection' => ['uprav_kolekci', 'Changes the name, address, item pages or fields of a collection (administrators). Fields = the whole new list; for existing ones send the "key" too (item values stay), a field without a key is new, a field you leave out disappears from the form.',
            ['collection' => ['kolekce', 'current slug of the collection'], 'name' => ['nazev', 'new name (optional)'], 'slug' => ['adresa', 'new address in URLs (optional)'],
                'item_pages' => ['detail', 'item pages on / off (optional)'], 'fields' => ['pole', '[{"key":"quote","label":"Quote","type":"lines"},{"label":"New field","type":"text"}] (optional)'],
                'structured_data' => ['schema_org', 'schema.org type of item pages (optional): {"type":"Service|Person|Product|Event|FAQPage","fields":{"property":"field key"},"currency":"EUR"}; {"type":""} = none'],
                'redirect_hidden_to' => ['presmerovat_skryte', 'where the page of a hidden or deleted item leads (2.10): /team or https://…; empty = page not found (optional)']]],
        'list_collection_items' => ['seznam_polozek_kolekce', 'Items of a collection with their field values, 50 per page (total is returned). Filter: text in the name and values, field=value, language, visible only. In a document library (preset documents, 2.11) every item also carries downloads {last_30_days, total} and latest_url – the stable address of its current file.',
            ['collection' => ['kolekce', 'collection slug'], 'search' => ['hledat', 'text in the name or field values (optional)'], 'field' => ['pole', 'field key for an exact match (optional)'],
                'value' => ['hodnota', 'field value for an exact match'], 'language' => ['jazyk', 'language version (empty = default; optional)'], 'visible_only' => ['jen_zobrazene', 'only items visible on the site'],
                'page' => ['strana', 'page from 1'], 'category' => ['kategorie', 'only items in the category with this slug, its subcategories included (3.7; optional)']]],
        'save_collection_item' => ['uloz_polozku_kolekce', 'Adds an item to a collection, or changes an existing one (with id). Without "visible": true a new item stays hidden. A drafts-only connection creates hidden items and changes hidden ones only – never a visible item, visible or publish_at (3.2).',
            ['collection' => ['kolekce', 'collection slug'], 'id' => ['id', 'item ID – only when changing it'], 'name' => ['nazev', 'item name (required for a new item, when changing only if it changes)'],
                'slug' => ['adresa', 'address of the item in URLs (optional, otherwise from the name), e.g. install'], 'language' => ['jazyk', 'language version of the item on a multilingual site (empty = default); a translation keeps the slug of the original, so the language switcher and hreflang link them'],
                'values' => ['data', 'field values by the keys from list_collections, e.g. {"quote":"…","logo":"media/…"}; fields you leave out stay'],
                'order' => ['poradi', 'Order, lower = first'], 'visible' => ['zobrazit', 'true = the item is on the site (only when the user asks)'],
                'seo_title' => ['seo_titulek', 'title for search engines (optional, otherwise the name)'], 'description' => ['popis', 'description for search engines, up to 160 characters (optional, otherwise from the first longer text field)'],
                'share_image' => ['obrazek', 'image for sharing on social networks (path from Media; optional, otherwise the first image field)'],
                'noindex' => ['noindex', 'true = keep the item page out of search engines, the sitemap, llms.txt and site search'],
                'publish_at' => ['zverejnit_od', 'scheduled publishing of a hidden item YYYY-MM-DD HH:MM (only when the user explicitly asks; empty = cancel)'],
                'valid_until' => ['valid_until', 'True until YYYY-MM-DD (2.10): after this day it hides itself on its own (the change is logged and recorded as the event content.expired); an empty string = always (optional)'], 'review_by' => ['review_by', 'Review by YYYY-MM-DD (2.10): on this day the site audit (kind review) and the event content.review ask the user to check it; an empty string = none (optional)'],
                'categories' => ['kategorie', 'category slugs of the collection (3.7, list_collection_categories) – the whole new list; [] = none; leave it out to keep them']]],
        'list_news' => ['seznam_novinek', 'List of news (newest first).',
            ['status' => ['stav', 'all | published | scheduled | drafts'], 'category' => ['kategorie', 'category name or slug'], 'search' => ['hledat', 'text in the headline'], 'limit' => ['limit', '1-50, default 20']]],
        'get_news' => ['nacti_novinku', 'The whole news item including the text and tags.', ['id' => ['id', 'News ID']]],
        'create_news' => ['vytvor_novinku', 'Creates a news item. Without "publish": true it is a draft.', ['*novinka']],
        'update_news' => ['uprav_novinku', 'Changes the given fields of a news item; the others stay. The previous version is saved to the history.', ['id' => ['id', 'News ID'], '*novinka']],
        'list_categories' => ['seznam_kategorii', 'News categories with counts.', []],
        'create_category' => ['vytvor_kategorii', 'Creates a news category (editors and administrators).', ['name' => ['nazev', 'Name'], 'description' => ['popis', 'Description (HTML)']]],
        'import_website' => ['importuj_web', 'Imports a site from another platform by its address (administrators, 2.6): pages are found in the sitemap or along links and become hidden builder pages (articles become news), images go to Media and old addresses redirect. '
            . 'One call = one batch (about 15 s). Start with url, then call again with import_id – first the pages are found; in the phase "preview" show the user what was found and only on their instruction send confirm: true. Keep calling until the phase is "done". '
            . 'Against the site owner\'s hourly change limit for Claude (if set) each call counts the records it created (pages, news items, images, redirects) – known only after the step, so a step can go over the limit and the next one is refused; then stop and tell the user what is left.',
            ['url' => ['adresa', 'address of the site, e.g. https://www.example.com (only for a new import)'], 'import_id' => ['import', 'id of a running import (from the previous call)'],
                'confirm' => ['potvrdit', 'true = import the pages found (only in the phase "preview", on the user\'s instruction)'], 'language' => ['jazyk', 'language version of the site (code, e.g. de; otherwise the main language)'],
                'images' => ['obrazky', 'download images into Media (default true)'], 'redirects' => ['presmerovani', 'redirect the old addresses (default true)'], 'news' => ['novinky', 'articles as news (default true)']]],
        'list_media' => ['seznam_medii', 'Recently uploaded images and files with addresses and dimensions.', ['limit' => ['limit', '1-50, default 20'], 'search' => ['hledat', 'text in the name (optional)']]],
        'upload_file' => ['nahraj_soubor', 'Uploads a file to Media: an image (JPG, PNG, WebP, GIF – resized, with WebP/AVIF variants), SVG (cleaned), a WOFF2 font for the design system or an attachment (PDF…). '
            . 'Give the url of a public file (https – image, font, PDF; always url for larger files), or data in base64 (at most 12 MB). Returns the path for the image or background image element or for custom fonts. '
            . 'A WordPress export (a file name ending .xml, administrators) does not go to Media: it is kept privately for import_wordpress, which the answer names.',
            ['filename' => ['nazev', 'file name with extension, e.g. team-london.jpg'], 'data' => ['data', 'file content in base64'], 'url' => ['url', 'https address of the file to download (instead of data)'],
                'alt' => ['popis', 'image description for blind visitors (alt); otherwise from the name']]],
        'preview_link' => ['nahled_odkaz', 'A signed link to the draft preview of a page or site part – anyone can open it without signing in (the user, a colleague, a browser); it is valid only for this target and for a limited time. Search engines do not index it.',
            ['*cil', 'minutes' => ['minut', 'validity in minutes, default 60, at most 10080'], 'site' => ['web', 'true = the whole site with every draft and the draft look (links on it keep the preview while browsing)'],
                'comments' => ['komentare', 'true = whoever opens the link can click an element and write a comment with their name (page drafts only; read them with list_draft_comments)']]],
        'update_settings' => ['uprav_nastaveni', 'Changes site settings (administrators) – they apply to the site straight away. Keys: site_name, site_description, footer_text, logo, favicon and share_image – the sharing image 1200×630 (path media/… from upload_file or image/…), home_page (ID of the home page), news_slug (the first part of the news URLs in every language, e.g. blog gives /blog/…; empty = the default novinky or news; the old URLs redirect), social_facebook|instagram|x|youtube|linkedin (URL), '
            . 'news_per_page, share_buttons, article_outline, related_news_auto (1/0), dark_mode (vypnuto = light only | auto = by device | tmavy = always dark), theme_switcher (1/0 = light/dark switcher for visitors), german_register (formal = Sie | informal = du: the form of address of the German texts for visitors), company details company_name, company_type, company_id, company_vat_id, company_register (commercial register entry), company_representative (who represents the company), company_street, company_city, company_postcode, company_country (CZ), company_phone, company_email (public contact), company_hours (one day per line), company_map, company_gps; site_name_de… for language versions. '
            . 'Since 2.2 also: extensions (the list of switched-on extensions, e.g. ["novinky","poptavky","claude"] – claude must stay), additional_languages (further language versions, e.g. ["de","cs"]), '
            . 'indexing, schema_org, llms_txt, markdown_news, indexnow (1/0), ai_crawlers (povolit | zakazat), url_slash (bez | s | html), robots_extra, verification_google, verification_bing, cookies_mode (zadna | vestavena | externi), cookies_text, cookies_policy_url (cookies_text_de, cookies_policy_url_de… = the cookie bar of a language version; empty = as in the default language), cookies_log (1/0), cookies_log_months, '
            . 'stats (1/0), ga4_id, plausible_domain, security_contact, claude_instructions, captcha_provider (hcaptcha | recaptcha | turnstile | empty), captcha_site_key, captcha_fail_open (1/0) – the CAPTCHA secret key is set only in the administration. Online booking: booking_lead_hours, booking_horizon_days, booking_cancel_hours, booking_reminder_hours, booking_hold_hours (how long a request for a service that requires confirmation holds its time), booking_pending_thanks, booking_pending_mail, booking_declined_mail (own texts, empty = the built-in one; {name} = the customer\'s name). Code that runs on the site (head_code, marketing_code, cookies_external_code) and the script hosts gtm_id, matomo_url and matomo_id (since 3.3.2) are set only in the administration. '
            . 'Screen mode (2.11, a TV in the reception rotating slides): screen_mode (1/0), screen_seconds (5–60 per slide), screen_collections (list of collection addresses), screen_news, screen_hours, screen_clock (1/0); the result has them under "screen" – the secret address is shown only in the administration (Settings → General). Without the parameter it returns the current values.',
            ['settings' => ['nastaveni', '{"key":"value"}']]],
        'list_enquiries' => ['seznam_poptavek', 'Enquiries from the site forms (Forms and enquiries extension; only with access to Enquiries), newest first: date, form, page, what it was about (about: the collection item, page or pop-up the form was on), campaign (utm), e-mail, status and the filled-in fields. They contain personal data – use them only for what the user asks.',
            ['status' => ['stav', 'new | read | resolved | all (default)'], 'search' => ['hledat', 'text in the e-mail or content (optional)'], 'limit' => ['limit', '1-50, default 20'],
                'category' => ['kategorie', 'sales | support | job | supplier | spam | other | unsorted (optional, 2.12; without it spam is left out)']]],
        'list_redirects' => ['seznam_presmerovani', 'Redirects of old addresses (Redirects extension; auto_score = created by the site itself from a missing address with this confidence, null = by hand) and the most frequent addresses that ended with a 404 error, each with the page the visitor most likely meant (suggestion, score 0–100; 2.14) – save_redirect accepts it when the user agrees. Redirects by themselves: settings redirect_auto and redirect_auto_threshold.', []],
        'save_redirect' => ['uloz_presmerovani', 'Adds or changes a redirect (administrators): from an old path on the site to a new path or https address. Code 301 = permanent (default), 302 = temporary.',
            ['from' => ['z', 'old path, e.g. /docs or /about'], 'to' => ['na', 'new path (/guide) or https://…'], 'code' => ['typ', '301 or 302'], 'delete' => ['smazat', 'true = delete the redirect from the old path']]],
        'trash_page' => ['smaz_stranku', 'Moves a page to the trash (only when the user explicitly asks; editors or administrators). It can be restored for 30 days in the admin. The home page cannot be deleted.', ['id' => ['id', 'Page ID']]],
        // tools added after 1.1 are English only (the name, parameters and results are the same on both sides)
        'list_trash' => ['list_trash', '', []],
        'restore_from_trash' => ['restore_from_trash', '', []],
        'trash_news' => ['trash_news', '', []],
        'delete_collection_item' => ['delete_collection_item', '', []],
        'delete_collection' => ['delete_collection', '', []],
        'update_category' => ['update_category', '', []],
        'delete_category' => ['delete_category', '', []],
        'delete_popup' => ['delete_popup', '', []],
        'list_components' => ['list_components', '', []],
        'save_component' => ['save_component', '', []],
        'delete_component' => ['delete_component', '', []],
        'delete_section' => ['delete_section', '', []],
        'update_media' => ['update_media', '', []],
        'delete_media' => ['delete_media', '', []],
        'update_enquiry' => ['update_enquiry', '', []],
        'triage_enquiries' => ['triage_enquiries', '', []],
        'request_testimonial' => ['request_testimonial', '', []],
        'find_personal_data' => ['find_personal_data', '', []],
        'list_agent_sessions' => ['list_agent_sessions', '', []],
        'undo_agent_session' => ['undo_agent_session', '', []],
        'erase_personal_data' => ['erase_personal_data', '', []],
        'delete_enquiry' => ['delete_enquiry', '', []],
        // save_section takes the shared build target (page, part, collection template, pop-up, component)
        'save_section' => ['save_section', '', ['*cil', 'element' => ['element', 'element id from get_build'], 'name' => ['name', 'name of the saved section']]],
        'apply_part_template' => ['apply_part_template', '', []],
        'publish_look' => ['publish_look', '', []],
        'discard_look' => ['discard_look', '', []],
        'list_look_versions' => ['list_look_versions', '', []],
        'list_item_versions' => ['list_item_versions', '', []],
        'site_audit' => ['site_audit', '', []],
        'list_changes' => ['list_changes', '', []],
        'get_stats' => ['get_stats', '', []],
        'ignore_not_found' => ['ignore_not_found', '', []],
        'save_redirects' => ['save_redirects', '', []],
        'save_collection_items' => ['save_collection_items', '', []],
        'list_broken_links' => ['list_broken_links', '', []],
        'suggest_internal_links' => ['suggest_internal_links', '', []],
        'restore_item_version' => ['restore_item_version', '', []],
        'get_email_signature' => ['get_email_signature', '', []],
        'restore_look_version' => ['restore_look_version', '', []],
        'list_newsletters' => ['list_newsletters', '', []],
        'draft_newsletter' => ['draft_newsletter', '', []],
        'send_test_newsletter' => ['send_test_newsletter', '', []],
        'send_newsletter' => ['send_newsletter', '', []],
        'delete_newsletter' => ['delete_newsletter', '', []],
        'migration_report' => ['migration_report', '', []],
        'import_enquiries' => ['import_enquiries', '', []],
        'import_wordpress' => ['import_wordpress', '', []],
        'get_health' => ['get_health', '', []],
        'list_events' => ['list_events', '', []],
        'list_facts' => ['list_facts', '', []],
        'save_fact' => ['save_fact', '', []],
        'delete_fact' => ['delete_fact', '', []],
        'find_claims' => ['find_claims', '', []],
        'list_hours' => ['list_hours', '', []],
        'list_collection_presets' => ['list_collection_presets', '', []],
        'get_blueprint' => ['get_blueprint', '', []],
        'list_connectors' => ['list_connectors', '', []],
        'processing_record' => ['processing_record', '', []],
        'accessibility_statement' => ['accessibility_statement', '', []],
        'get_social_drafts' => ['get_social_drafts', '', []],
        'read_notebook' => ['read_notebook', '', []],
        'write_notebook' => ['write_notebook', '', []],
        'delete_notebook_entry' => ['delete_notebook_entry', '', []],
        'update_social_draft' => ['update_social_draft', '', []],
        'apply_blueprint' => ['apply_blueprint', '', []],
        'remove_blueprint' => ['remove_blueprint', '', []],
        'export_blueprint' => ['export_blueprint', '', []],
        'list_notice_log' => ['list_notice_log', '', []],
        'list_collection_categories' => ['list_collection_categories', '', []],
        'save_collection_category' => ['save_collection_category', '', []],
        'delete_collection_category' => ['delete_collection_category', '', []],
        'save_hours_exception' => ['save_hours_exception', '', []],
        'delete_hours_exception' => ['delete_hours_exception', '', []],
        'list_bookings' => ['list_bookings', '', []],
        'booking_availability' => ['booking_availability', '', []],
        'save_booking_service' => ['save_booking_service', '', []],
        'save_booking_staff' => ['save_booking_staff', '', []],
        'cancel_booking' => ['cancel_booking', '', []],
        'confirm_booking' => ['confirm_booking', '', []],
        'decline_booking' => ['decline_booking', '', []],
        'propose_booking_times' => ['propose_booking_times', '', []],
        'list_sites' => ['list_sites', '', []],
        'get_site' => ['get_site', '', []],
        'list_media_without_alt' => ['list_media_without_alt', '', []],
        'translation_status' => ['translation_status', '', []],
        'list_requests' => ['list_requests', '', []],
        'list_pending_review' => ['list_pending_review', '', []],
        'update_request' => ['update_request', '', []],
        'get_due_agent_runs' => ['get_due_agent_runs', '', []],
        'report_agent_run' => ['report_agent_run', '', []],
        'list_draft_comments' => ['list_draft_comments', '', []],
        'resolve_draft_comment' => ['resolve_draft_comment', '', []],
    ];

    /** Parameter values in English => Czech (by the Czech parameter; for some tools only there). */
    private const array INPUT_VALUES = [
        'cast' => ['header' => 'hlavicka', 'footer' => 'paticka', 'news_item' => 'novinka', 'news_list' => 'vypis', 'not_found' => 'nenalezeno'],
        'umisteni' => ['main' => 'hlavni', 'footer' => 'paticka'],
        'rezim' => ['replace' => 'nahradit', 'append' => 'pridat'],
        'typ' => ['window' => 'okno', 'slide_in' => 'panel', 'top_bar' => 'lista-nahore', 'bottom_bar' => 'lista-dole', 'fullscreen' => 'cela'],
        'spoustec' => ['time' => 'cas', 'scroll' => 'posun', 'exit' => 'odchod', 'idle' => 'necinnost', 'pages' => 'stranky', 'click' => 'klik'],
        'cetnost' => ['session' => 'relace', 'days' => 'dni', 'until_closed' => 'zavreni', 'until_submitted' => 'odeslani', 'always' => 'vzdy'],
        'vzor' => ['newsletter' => 'newsletter', 'lead_magnet' => 'magnet', 'announcement_bar' => 'lista', 'discount' => 'sleva', 'event' => 'udalost', 'blank' => 'prazdny'],
    ];
    /** Popup rules: English key => Czech, and enum values. */
    private const array RULES = ['where' => 'kde', 'pages' => 'stranky', 'collections' => 'kolekce', 'news' => 'novinky', 'language' => 'jazyk', 'from' => 'od', 'to' => 'do',
        'device' => 'zarizeni', 'campaign' => 'utm', 'referrer' => 'odkud'];
    private const array RULE_VALUES = ['kde' => ['all' => 'vse', 'selected' => 'vybrane'], 'zarizeni' => ['all' => 'vse', 'desktop' => 'pocitac', 'phone' => 'telefon']];
    private const array NEWS_STATUSES = ['all' => 'vse', 'published' => 'vydane', 'scheduled' => 'plan', 'drafts' => 'koncepty'];
    private const array ENQUIRY_STATUSES = ['all' => 'vse', 'new' => 'nove', 'read' => 'prectene', 'resolved' => 'vyrizene'];
    private const array FIELD_TYPES = ['text' => 'text', 'lines' => 'radky', 'html' => 'html', 'image' => 'obrazek', 'link' => 'odkaz', 'number' => 'cislo', 'date' => 'datum', 'item' => 'polozka',
        'datetime' => 'termin', 'file' => 'soubor', 'location' => 'poloha', 'choice' => 'volba'];
    private const array COLLECTION_FIELD_KEYS = ['key' => 'klic', 'label' => 'popisek', 'type' => 'typ', 'collection' => 'kolekce', 'options' => 'moznosti'];
    private const array MENU = ['type' => 'typ', 'page_id' => 'ids', 'text' => 'text', 'url' => 'url', 'new_window' => 'nove_okno', 'icon' => 'ikona', 'description' => 'popis', 'children' => 'deti'];
    private const array MENU_ITEM_TYPES = ['page' => 'stranka', 'link' => 'odkaz', 'news' => 'novinky', 'group' => 'skupina'];
    private const array OPERATION_KEYS = ['op' => 'op', 'id' => 'id', 'content' => 'obsah', 'style' => 'styl', 'classes' => 'tridy', 'element' => 'prvek', 'elements' => 'prvky',
        'into' => 'do', 'position' => 'pozice', 'after' => 'za', 'before' => 'pred'];
    private const array OPERATION_TYPES = ['update' => 'uprav', 'replace' => 'nahrad', 'delete' => 'smaz', 'insert' => 'vloz', 'move' => 'presun'];

    /** Settings keys English => Czech; for site name and description also with a language code (site_name_de => nazev_webu_de). */
    private const array SETTINGS = [
        'site_name' => 'site_name', 'site_description' => 'site_description', 'footer_text' => 'footer_text', 'logo' => 'logo', 'favicon' => 'favicon', 'share_image' => 'share_image',
        'home_page' => 'home_page', 'social_facebook' => 'social_facebook', 'social_instagram' => 'social_instagram', 'social_x' => 'social_x', 'social_youtube' => 'social_youtube',
        'social_linkedin' => 'social_linkedin', 'news_per_page' => 'news_per_page', 'share_buttons' => 'share_buttons', 'article_outline' => 'article_outline', 'related_news_auto' => 'related_news_auto', 'dark_mode' => 'dark_mode', 'theme_switcher' => 'theme_switcher',
        'company_name' => 'company_name', 'company_type' => 'company_type', 'company_id' => 'company_id', 'company_vat_id' => 'company_vat_id', 'company_register' => 'company_register', 'company_representative' => 'company_representative', 'company_street' => 'company_street',
        'company_city' => 'company_city', 'company_postcode' => 'company_postcode', 'company_country' => 'company_country', 'company_phone' => 'company_phone', 'company_email' => 'company_email',
        'company_hours' => 'company_hours', 'company_map' => 'company_map', 'company_gps' => 'company_gps',
    ];

    /** Result keys Czech => English. */
    private const array KEYS = [
        'nezname_parametry' => 'unknown_parameters', 'id' => 'id', 'ids' => 'page_id', 'idc' => 'id', 'idp' => 'id', 'idr' => 'version_id', 'nazev' => 'name', 'titulek' => 'title', 'adresa' => 'url', 'seo_link' => 'slug',
        'popis' => 'description', 'nahled' => 'preview', 'text' => 'content', 'stranka' => 'page', 'stranky' => 'pages', 'stav' => 'status', 'jazyk' => 'language', 'varianta' => 'variant',
        'cast' => 'part', 'kolekce' => 'collection', 'typ' => 'type', 'pole' => 'fields', 'klic' => 'key', 'popisek' => 'label', 'hodnota' => 'value', 'datum' => 'date',
        'zobrazit' => 'visible', 'zobrazena' => 'visible', 'v_menu' => 'in_menu', 'poradi' => 'order', 'uvodni' => 'home', 'uvodni_stranka' => 'home_page', 'zmeneno' => 'changed',
        'publikovana' => 'published', 'neulozene_zmeny' => 'unsaved_changes', 'prvku' => 'elements', 'chyby' => 'errors', 'kontrola' => 'check', 'zprava' => 'message',
        'stavitel' => 'builder_url', 'uprava_v_administraci' => 'admin_url', 'plati_do' => 'valid_until', 'verze' => 'versions', 'kdy' => 'when', 'kdo' => 'who', 'celkem' => 'total',
        'strana' => 'page', 'stran' => 'pages', 'polozky' => 'items', 'polozek' => 'items', 'detail' => 'item_pages', 'detail_zapnuty' => 'item_pages', 'presmerovat_skryte' => 'redirect_hidden_to', 'web' => 'site',
        'verze_kaleta' => 'kaleta_version', 'stranek' => 'pages', 'novinek_vydanych' => 'published_news', 'novinek' => 'news', 'uzivatel' => 'user', 'role' => 'role',
        'smi_vydavat' => 'can_publish', 'smi_upravovat_stranky' => 'can_edit_pages', 'umisteni' => 'location', 'automaticke' => 'automatic', 'na_webu' => 'on_site',
        'ulozeno' => 'saved', 'smazano' => 'deleted', 'hlaseni' => 'notes', 'design_system' => 'design_system', 'citelnost' => 'readability', 'citelnost_tmave' => 'readability_dark', 'tmava_paleta' => 'dark_palette', 'seo_titulek' => 'seo_title',
        'seo_popis' => 'seo_description', 'obrazek' => 'image', 'obrazek_popis' => 'image_caption', 'noindex' => 'noindex', 'nadrazena' => 'parent', 'preklad_z' => 'translation_of',
        'zverejnit_od' => 'publish_at', 'uvod' => 'intro', 'kategorie' => 'category', 'stitky' => 'tags', 'visible' => 'published', 'vydana' => 'published', 'formular' => 'form',
        'email' => 'email', 'kampan' => 'campaign', 'url' => 'url', 'rozmery' => 'size', 'velikost' => 'size', 'soubory' => 'files', 'sablona' => 'theme', 'presmerovani' => 'redirects', 'nenalezeno' => 'not_found', 'z' => 'from', 'na' => 'to', 'pocet' => 'count', 'naposledy' => 'last_seen',
        'cesta' => 'path', 'neplatna_pole' => 'invalid_fields', 'chyby_operaci' => 'operation_errors', 'texty' => 'texts', 'nezname_klice' => 'unknown_keys', 'nastaveni' => 'settings', 'faq' => 'faq', 'data' => 'values', 'stavba' => 'build',
        'vlastnosti' => 'properties', 'deti' => 'children', 'nove_okno' => 'new_window', 'popup' => 'popup', 'aktivni' => 'active',
    ];

    /** Keys whose values are not translated: build JSON, item field values, design system, conversion and check messages. */
    private const array UNTRANSLATED = ['data', 'design_system', 'chyby', 'hlaseni', 'citelnost', 'citelnost_tmave', 'tmava_paleta', 'chyby_operaci', 'styl', 'css', 'vlastnosti'];

    /** Exceptions from KEYS per tool (English name => [Czech key => English]). */
    private const array TOOL_KEYS = [
        'list_media' => ['adresa' => 'path', 'obrazek' => 'is_image'],
        'upload_file' => ['adresa' => 'path', 'obrazek' => 'is_image'],
        'list_categories' => ['adresa' => 'slug'],
        'create_category' => ['adresa' => 'slug'],
        'get_page' => ['ids' => 'id'],
        'list_redirects' => ['typ' => 'code'],
        'save_redirect' => ['typ' => 'code'],
        // 3.6: kinds of content of a header or footer variant
        'save_part_variant' => ['novinky' => 'news_items', 'vypis' => 'news_list', 'kolekce' => 'collections', 'nadrazene' => 'under_pages'],
        'list_site_parts' => ['novinky' => 'news_items', 'vypis' => 'news_list', 'kolekce' => 'collections', 'nadrazene' => 'under_pages'],
        // 3.7: an item's categories (slugs)
        'save_collection_item' => ['kategorie' => 'categories', 'nezname_kategorie' => 'unknown_categories'],
        'list_collection_items' => ['kategorie' => 'categories'],
    ];

    private const array STATUSES = [
        'publikováno' => 'published', 'koncept – na webu se ukáže po publikování' => 'draft – shown on the site after publishing',
        'koncept zahozen – platí publikovaná podoba' => 'draft discarded – the published version applies',
        'uloženo – stavbu varianty uprav stavba_* s parametrem varianta a publikuj; do publikování platí výchozí podoba'
            => 'saved – edit the variant with the *_build tools and the variant parameter, then publish it; until then the default applies',
        'v koši – obnovit jde 30 dní v administraci (Stránky → Koš)' => 'in the trash – it can be restored for 30 days in the admin (Pages → Trash)',
        'varianta smazána – vybrané stránky mají výchozí podobu' => 'variant deleted – the selected pages use the default',
        'skrytá, zveřejní se s obsahem' => 'hidden until it has content – it becomes visible when its build is published (publish_build) or it gets text',
        'zveřejněná' => 'visible', 'skrytá' => 'hidden', 'koncept' => 'draft', 'naplánováno' => 'scheduled', 'vydáno' => 'published',
        'nove' => 'new', 'prectene' => 'read', 'vyrizene' => 'resolved', 'autor' => 'author', 'editor' => 'editor', 'správce' => 'administrator',
    ];
    private const array SITE_PARTS = ['hlavicka' => 'header', 'paticka' => 'footer', 'novinka' => 'news_item', 'vypis' => 'news_list', 'nenalezeno' => 'not_found'];

    /** Tool error messages Czech => English; messages with a variable part as a pattern (regular expression => replacement). */
    private const array MESSAGES = [
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
        'Novinky jsou na tomto webu vypnuté (Funkce).' => 'News is switched off on this site (Features).',
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
        'Přesměrování smí spravovat jen role se sekcí Přesměrování.' => 'Only roles with the Redirects section can manage redirects.',
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
        // update_settings: the errors per setting
        'Neplatná hodnota.' => 'Invalid value.',
        'Tohle nastavení přes MCP měnit nejde (jen v administraci).' => 'This setting cannot be changed through the Claude connection (only in the administration).',
        'Úvodní stránkou může být jen zveřejněná stránka.' => 'Only a visible page can be the home page.',
        'Cesta k souboru z Médií (media/…) nebo ze systému (image/…); ikona musí jít převést na PNG.' => 'A path to a file in Media (media/…) or in the system (image/…); the icon must be convertible to PNG.',
    ];
    private const array MESSAGE_PATTERNS = [
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

    /** Messages of the HTML and class conversion (Builder\HtmlConverter) – pattern => English. */
    private const array NOTICES = [
        '/^Třída \.(\S+) už na webu je – ponechána beze změny \(prepsat_tridy: true ji přepíše\)\.$/su' => 'The class .$1 already exists on the site – left unchanged (overwrite_classes: true replaces it).',
        '/^Třída \.(\S+) už na webu je – ponechána beze změny\.$/su' => 'The class .$1 already exists on the site – left unchanged.',
        '/^Třídy bez stylu vynechány: (.*)$/su' => 'Classes without a style were left out: $1',
        '/^Značka <(\w+)> mimo formulář nemá ve stavbě obdobu – vynechána\.$/su' => 'The <$1> tag outside a form has no builder equivalent – left out.',
        '/^Vložené styly \(atribut style\) se nepřevádějí – vzhled patří do tříd v <style>\.$/su' => 'Inline styles (the style attribute) are not converted – the look belongs in classes in <style>.',
        '/^Příliš hluboké vnoření – nejhlubší část převedena jako text\.$/su' => 'Nesting too deep – the deepest part was converted as text.',
        // Builder\Build::limitNote() – a field over a limit of Core\HtmlLimits (3.8)
        '/^Kód má (\d+) bajtů, nejvýš smí mít (\d+) – obsah pole vynechán\.$/su' => 'The markup is $1 bytes long; the limit is $2 – the field was left empty.',
        '/^Kód je vnořený do (\d+) úrovní, nejvýš smí do (\d+) – obsah pole vynechán\.$/su' => 'The markup is nested $1 levels deep; the limit is $2 – the field was left empty.',
        '/^Prvek v kódu má (\d+) atributů, nejvýš smí mít (\d+) – obsah pole vynechán\.$/su' => 'An element in the markup has $1 attributes; the limit is $2 – the field was left empty.',
        '/^Kód má (\d+) prvků, nejvýš smí mít (\d+) – obsah pole vynechán\.$/su' => 'The markup has $1 elements; the limit is $2 – the field was left empty.',
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
        '/^Jazyk podmínky zobrazení musí být kód jazykové verze webu \(„“ = výchozí jazyk\)\.$/su' => 'The language of a display condition must be the code of a language version of the site ("" = the default language).',
        '/^Název parametru adresy: 1–40 znaků, písmena bez diakritiky, číslice, _ a -\.$/su' => 'URL parameter name: 1–40 characters, letters without accents, digits, _ and -.',
        '/^Hodnota parametru adresy: 1–80 znaků, písmena bez diakritiky, číslice, _ \. a -\.$/su' => 'URL parameter value: 1–80 characters, letters without accents, digits, _ . and -.',
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
    private const array PART_NAMES = ['Záhlaví' => 'Header', 'Patička' => 'Footer', 'Detail novinky' => 'News item', 'Výpis novinek' => 'News list', 'Stránka nenalezena (404)' => 'Page not found (404)'];

    /** @return list<string> English tool names */
    public static function names(): array
    {
        return array_keys(self::TOOLS);
    }

    /** @return list<string> Czech tools that have an English name */
    public static function czechTools(): array
    {
        return array_column(self::TOOLS, 0);
    }

    public static function czech(string $name): ?string
    {
        return self::TOOLS[$name][0] ?? null;
    }

    /**
     * English tool definitions from the Czech ones (parameter types and required flags are taken over, descriptions are English).
     *
     * @param list<array{name: string, description: string, inputSchema: array<string, mixed>}> $czechTools
     * @return list<array<string, mixed>>
     */
    public static function listAll(array $czechTools): array
    {
        $byName = array_column($czechTools, null, 'name');
        $result = [];
        foreach (self::TOOLS as $en => [$cs, $description]) {
            if (!isset($byName[$cs])) {
                continue; // tool of a disabled extension
            }
            if ($en === $cs && self::TOOLS[$en][2] === []) {
                $result[] = $byName[$cs]; // English only: the definition as it is
                continue;
            }
            $description = $description !== '' ? $description : $byName[$cs]['description'];
            $schema = $byName[$cs]['inputSchema'];
            $properties = [];
            $required = [];
            foreach (self::parameters($en) as $enParam => [$csParam, $paramDescription]) {
                if (!isset($schema['properties'][$csParam])) {
                    continue;
                }
                $properties[$enParam] = ['description' => $paramDescription] + $schema['properties'][$csParam];
                $properties[$enParam]['description'] = $paramDescription;
                if (in_array($csParam, $schema['required'] ?? [], true)) {
                    $required[] = $enParam;
                }
            }
            $result[] = ['name' => $en, 'description' => $description,
                'inputSchema' => ['type' => 'object', 'properties' => $properties === [] ? new \stdClass() : $properties, 'required' => $required]];
        }

        return $result;
    }

    /** @return array<string, array{0: string, 1: string}> tool parameters with groups expanded */
    private static function parameters(string $name): array
    {
        $result = [];
        foreach (self::TOOLS[$name][2] as $key => $parameter) {
            if (is_int($key)) {
                $result += match ($parameter) {
                    '*cil' => self::TARGET,
                    '*stranka' => self::PAGE,
                    '*novinka' => self::NEWS_ITEM,
                };
                continue;
            }
            $result[$key] = $parameter;
        }

        return $result;
    }

    /**
     * English arguments to Czech (unknown keys stay as they are – so Czech parameters also work with the English name).
     *
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    public static function arguments(string $name, array $a): array
    {
        $mapping = array_map(fn (array $p): string => $p[0], self::parameters($name));
        $result = [];
        foreach ($a as $key => $value) {
            $cs = $mapping[$key] ?? $key;
            $result[$cs] = match (true) {
                isset(self::INPUT_VALUES[$cs]) && is_string($value) => self::INPUT_VALUES[$cs][$value] ?? $value,
                $cs === 'stav' && is_string($value) => ($name === 'list_news' ? self::NEWS_STATUSES : self::ENQUIRY_STATUSES)[$value] ?? $value,
                $cs === 'pole' && is_array($value) => array_map(fn (mixed $p): mixed => is_array($p) ? self::collectionFieldToCzech($p) : $p, $value),
                $cs === 'polozky' && is_array($value) => array_map(self::menuItemToCzech(...), $value),
                $cs === 'operace' && is_array($value) => array_map(self::operationToCzech(...), $value),
                $cs === 'nastaveni' && is_array($value) => self::settingsKeys($value, true),
                $cs === 'pravidla' && is_array($value) => self::rules($value, true),
                $cs === 'stavba' && is_array($value) => Vocabulary::buildToCzech($value),
                default => $value,
            };
        }
        if ($name === 'builder_schema') {
            $result['_english'] = true; // the tool answers in the English builder vocabulary
        }

        return $result;
    }

    private static function collectionFieldToCzech(array $p): array
    {
        $result = [];
        foreach ($p as $k => $h) {
            $cs = self::COLLECTION_FIELD_KEYS[$k] ?? $k;
            $result[$cs] = $cs === 'typ' && is_string($h) ? (self::FIELD_TYPES[$h] ?? $h) : $h;
        }

        return $result;
    }

    private static function menuItemToCzech(mixed $p): mixed
    {
        if (!is_array($p)) {
            return $p;
        }
        $result = [];
        foreach ($p as $k => $h) {
            $cs = self::MENU[$k] ?? $k;
            $result[$cs] = match ($cs) {
                'typ' => is_string($h) ? (self::MENU_ITEM_TYPES[$h] ?? $h) : $h,
                'ikona' => is_string($h) ? (array_flip(Vocabulary::VALUES['ikona'])[$h] ?? $h) : $h, // the English icon name as in the Icon element
                'deti' => is_array($h) ? array_map(self::menuItemToCzech(...), $h) : $h,
                default => $h,
            };
        }

        return $result;
    }

    private static function operationToCzech(mixed $o): mixed
    {
        if (!is_array($o)) {
            return $o;
        }
        $result = [];
        foreach ($o as $k => $h) {
            $cs = self::OPERATION_KEYS[$k] ?? $k;
            $result[$cs] = match (true) {
                $cs === 'op' && is_string($h) => self::OPERATION_TYPES[$h] ?? $h,
                $cs === 'obsah' && is_array($h) => Vocabulary::contentToCzech($h),
                $cs === 'styl' && is_array($h) => Vocabulary::styleToCzech($h),
                $cs === 'prvek' => Vocabulary::elementToCzech($h),
                $cs === 'prvky' && is_array($h) => array_map(Vocabulary::elementToCzech(...), $h),
                default => $h,
            };
        }

        return $result;
    }

    /** Popup rules between English and Czech (keys and enum values). @param array<string, mixed> $p */
    private static function rules(array $p, bool $toCzech): array
    {
        $keys = $toCzech ? self::RULES : array_flip(self::RULES);
        $result = [];
        foreach ($p as $k => $h) {
            $cs = $toCzech ? ($keys[$k] ?? $k) : $k;
            if (is_string($h) && isset(self::RULE_VALUES[$cs])) {
                $h = $toCzech ? (self::RULE_VALUES[$cs][$h] ?? $h) : (array_search($h, self::RULE_VALUES[$cs], true) ?: $h);
            }
            $result[$toCzech ? $cs : ($keys[$k] ?? $k)] = $h;
        }

        return $result;
    }

    /** Popup from the result of seznam_popupu and uloz_popup in English. */
    private static function popupToEnglish(array $p): array
    {
        $result = [];
        foreach ($p as $k => $h) {
            $result[self::POPUP_KEYS[$k] ?? self::KEYS[$k] ?? $k] = match (true) {
                $k === 'pravidla' && is_array($h) => self::rules($h, false),
                isset(self::INPUT_VALUES[$k]) && is_string($h) => array_search($h, self::INPUT_VALUES[$k], true) ?: $h,
                default => $h,
            };
        }

        return $result;
    }

    private const array POPUP_KEYS = ['adresa' => 'slug', 'odkaz' => 'link', 'spoustec' => 'trigger', 'cetnost' => 'frequency', 'dni' => 'days', 'pravidla' => 'rules',
        'aktivni' => 'active', 'publikovano' => 'published', 'zmeny' => 'unpublished_changes', 'zobrazeni' => 'views', 'zavreni' => 'closes', 'konverze' => 'conversions', 'nahled' => 'preview'];

    /** @param array<string, mixed> $settings @return array<string, mixed> */
    private static function settingsKeys(array $settings, bool $toCzech): array
    {
        $mapping = $toCzech ? self::SETTINGS : array_flip(self::SETTINGS);
        $result = [];
        foreach ($settings as $k => $h) {
            $k = (string) $k;
            $language = '';
            if (($m = \Kaleta\Core\Language::settingKey($k, ['site_name', 'site_description', 'nazev_webu', 'popis_webu'])) !== null) {
                [$k, $language] = [$m[0], '_' . $m[1]];
            }
            $result[($mapping[$k] ?? $k) . $language] = $h;
        }

        return $result;
    }

    /** Tool result with English keys and statuses. */
    public static function result(string $name, mixed $v): mixed
    {
        if ($name === 'builder_schema' || !is_array($v) || (self::TOOLS[$name][0] ?? '') === $name) {
            return $v; // the schema describes the builder data model – it stays as it is
        }
        if ($name === 'list_popups') {
            return array_map(fn (mixed $p): mixed => is_array($p) ? self::popupToEnglish($p) : $p, $v);
        }
        if ($name === 'save_popup') {
            return self::popupToEnglish($v);
        }
        if ($name === 'list_classes') {
            return array_map(fn (mixed $c): mixed => is_array($c) ? ['name' => $c['nazev'] ?? '', 'style' => is_array($c['styl'] ?? null) ? (Vocabulary::styleToEnglish($c['styl']) ?: new \stdClass()) : $c['styl'] ?? null,
                'css' => $c['css'] ?? ''] + (isset($c['draft']) ? ['draft' => true] : []) : $c, $v);
        }
        if (in_array($name, ['get_menu', 'save_menu'], true)) {
            $menu = fn (mixed $items): array => array_map(fn (mixed $p): mixed => is_array($p) ? self::menuItemToEnglish($p) : $p, is_array($items) ? $items : []);
            $rest = array_diff_key($v, ['polozky' => 1, 'na_webu' => 1, 'umisteni' => 1]);

            return ['location' => array_search($v['umisteni'] ?? '', self::INPUT_VALUES['umisteni'], true) ?: ($v['umisteni'] ?? ''),
                'items' => $menu($v['polozky'] ?? []), 'on_site' => $menu($v['na_webu'] ?? [])] + self::translateArray($rest, []);
        }

        return self::translateArray($v, self::TOOL_KEYS[$name] ?? []);
    }

    /** @param array<string, string> $overrides */
    private static function translateArray(array $v, array $overrides): array
    {
        $result = [];
        foreach ($v as $k => $h) {
            if (is_int($k)) {
                $result[$k] = is_array($h) ? self::translateArray($h, $overrides) : $h;
                continue;
            }
            $result[$overrides[$k] ?? self::KEYS[$k] ?? $k] = match (true) {
                $k === 'nastaveni' && is_array($h) => self::settingsKeys($h, false),
                in_array($k, ['hlaseni', 'chyby', 'chyby_operaci'], true) && is_array($h) => array_map(fn (mixed $z): mixed => is_string($z) ? self::message($z) : $z, $h),
                in_array($k, self::UNTRANSLATED, true) => $h,
                // build texts for translation: the inside of the content are element properties as in the build (text, odkaz, html…)
                $k === 'texty' && is_array($h) => array_map(fn (mixed $t): mixed => is_array($t) ? ['id' => $t['id'] ?? '', 'type' => Vocabulary::TYPES[$t['typ'] ?? ''] ?? ($t['typ'] ?? '')]
                    + (isset($t['obsah']) ? ['content' => Vocabulary::contentToEnglish((string) ($t['typ'] ?? ''), (array) $t['obsah'])] : []) + (isset($t['atributy']) ? ['attributes' => $t['atributy']] : []) : $t, $h),
                // the build in the English vocabulary (stored builds keep their Czech keys)
                $k === 'stavba' && is_array($h) => Vocabulary::buildToEnglish($h),
                $k === 'titulek' && is_string($h) && isset(self::PART_NAMES[$h]) => self::PART_NAMES[$h],
                is_array($h) => self::translateArray($h, $overrides),
                ($k === 'stav' || $k === 'role') && is_string($h) => self::state($h),
                $k === 'cast' && is_string($h) => self::SITE_PARTS[$h] ?? $h,
                $k === 'typ' && is_string($h) => array_search($h, self::FIELD_TYPES, true) ?: $h,
                default => $h,
            };
        }

        return $result;
    }

    /**
     * Stored menu items in the English shape save_menu takes (3.6, import_wordpress hands back menus it did not place).
     *
     * @param list<array<string, mixed>> $items
     * @return list<array<string, mixed>>
     */
    public static function menuItemsToEnglish(array $items): array
    {
        return array_map(self::menuItemToEnglish(...), $items);
    }

    private static function menuItemToEnglish(array $p): array
    {
        $result = [];
        foreach ($p as $k => $h) {
            $en = array_search($k, self::MENU, true) ?: $k;
            $result[$en] = match ($k) {
                'typ' => is_string($h) ? (array_search($h, self::MENU_ITEM_TYPES, true) ?: $h) : $h,
                'ikona' => is_string($h) ? (Vocabulary::VALUES['ikona'][$h] ?? $h) : $h,
                'deti' => is_array($h) ? array_map(fn (mixed $d): mixed => is_array($d) ? self::menuItemToEnglish($d) : $d, $h) : $h,
                default => $h,
            };
        }

        return $result;
    }

    private static function messages(string $h): string
    {
        foreach (self::NOTICES as $pattern => $replacement) {
            if (preg_match($pattern, $h)) {
                return (string) preg_replace($pattern, $replacement, $h);
            }
        }

        return $h;
    }

    private static function state(string $h): string
    {
        if (isset(self::STATUSES[$h])) {
            return self::STATUSES[$h];
        }

        return preg_match('/^skrytá, zveřejní se (.+)$/u', $h, $m) ? 'hidden, will be published ' . $m[1] : $h;
    }

    /** Tool error message in English; Czech tool names in the text are replaced by the English ones. */
    public static function message(string $message): string
    {
        if (isset(self::MESSAGES[$message])) {
            return self::MESSAGES[$message];
        }
        foreach (self::MESSAGE_PATTERNS as $pattern => $replacement) {
            if (preg_match($pattern, $message)) {
                return (string) preg_replace($pattern, $replacement, $message);
            }
        }

        return self::messages($message);
    }

    /** Server instructions for Claude (response to initialize) – in English, with English tool names. */
    public static function instructions(): string
    {
        return 'A business website on Kaleta. Write texts in the language of the site; pages and news as clean semantic HTML (p, h2, h3, ul, ol, blockquote, a, strong, em, figure/img, table). '
            . 'BUILDING A SITE: (1) site_info and builder_schema (a short overview; full element definitions through the elements parameter). '
            . '(2) The look of the whole site: update_design_system (colours, fonts, sizes); upload a custom font with upload_file (.woff2) and add it to vlastni_pisma. A repeated look (cards, labels, a dark band) belongs in shared classes – save_classes or <style> in build_from_html; a dark band = a class that overrides the tokens (--ka-barva-text, --ka-barva-pozadi, --ka-barva-primarni…) so links and buttons stay readable. '
            . '(3) Pages: create_page (it stays hidden) and build_from_html – semantic HTML by sections + <style> with rules of one class and tokens var(--ka-…), breakpoints @media (max-width: 1023px) and (max-width: 767px), no inline styles; or save_build with JSON according to the schema. Upload images with upload_file. Build the header and footer with save_build and the part parameter. '
            . '(4) Checking: every build write returns preview – a signed link to the draft valid for 60 minutes; open it and check the result, give the user a longer link from preview_link. The check field (when present) lists what the builder would flag before publishing – buttons without links, images without descriptions, the heading outline; fix them before you offer to publish. '
            . '(5) Fixes: edit_build by element id (ids from get_build) – do not send the whole build for one text. (6) Site settings with update_settings, old addresses with save_redirect. The menu (save_menu), the design system and changes of existing classes go to the draft look: check the whole site with preview_link site: true and publish them with publish_look only when the user asks (a new class and the settings apply straight away); hidden pages appear in the menu only once they are visible. '
            . '(7) A header or footer: start from a ready-made template with apply_part_template (builder_schema → part_templates) and adjust it; only for some pages (a campaign without the menu): save_part_variant, then the *_build tools with the variant parameter; overview with list_site_parts. list_build_versions and restore_build_version bring back an older published version (into the draft). '
            . '(8) Pop-ups (a newsletter sign-up, a download, an announcement bar): save_popup with a template creates one (inactive), build its content with the *_build tools and the popup parameter, set type, trigger, frequency and rules with save_popup; activate it (active: true) only after publishing and only when the user asks. list_popups shows views, closes and conversions. '
            . '(9) Translating into another language version (the admin switches languages on): create_page with language, translation_of and copy_build, then get_build with texts_only and edit_build “update” operations for the texts and links (internal links point to the translated pages); the header and footer with the part and language parameters (they start as a copy of the default language); save_menu with language; a collection item translation with the same slug and language; a collection item template with collection and language. '
            . '(10) Newsletters (Newsletter extension): draft_newsletter writes one e-mail styled by the design system – subject, introduction, the latest or chosen news items and a button; check the returned text, send_test_newsletter sends it to the user, and send_newsletter goes to all subscribers only when the user explicitly asks (it cannot be taken back). '
            . '(11) Cleaning up: pages, news items and collection items go to the trash (trash_page, trash_news, delete_collection_item) and come back with restore_from_trash for 30 days (list_trash). Other deletes (collections, categories, pop-ups, components, saved sections, media, enquiries) are final – use them only when the user explicitly asks. A repeated block belongs in a component (save_component, then the *_build tools with component); a section the user saved in the builder is in builder_schema → saved_sections. '
            . '(12) Moving a site from another platform (2.7): the prompt migrate_site describes the whole move; import_wordpress (a WordPress export, 3.6 – everything hidden, menus into the draft look) or import_website bring the content, import_enquiries the old form entries, and migration_report checks every old address and what got lost before the domain is switched. '
            . '(13) Looking after the site (2.8): get_health first when something seems wrong, list_events for what happened since you last looked (keep next_since_id). '
            . '(14) A fleet console (2.9, when list_sites exists): list_sites shows the other sites that report here, the ones needing attention first; get_site their last report. It only reads – changes on a site go through that site\'s own connection. '
            . '(15) Business facts (2.10): numbers and details the site states in several places (founded, projects, price from, warranty) belong in facts – list_facts, save_fact – and in content as {{fact.key}} (also tel:{{fact.company_phone}} in links). find_claims lists sentences with numbers written as plain text; after a fact changes, save_fact returns the sentences that still state the old value. Computed tokens never go stale: {{years_since:2004}} or {{years_since:fact.founded}} (full years since a year, a date or a fact), {{count:<collection address>}} (visible items) and {{count:news}} – also as the number of a counter element, which site_audit otherwise reports when digits are typed in. Holidays and other days with different opening hours: save_hours_exception (list_hours shows the week, the exceptions and whether it is open now). '
            . '(16) Ready-made collections (2.11): list_collection_presets, then create_collection with preset. An official notice board (preset notices) keeps its notices for good: delete_collection_item refuses them and a notice cannot be hidden once its posting date has come – set the takedown date instead; every change is in the append-only log (list_notice_log). '
            . '(17) The agent notebook (2.15): read_notebook before larger changes – it holds the decisions, wording rules, credits and history that whoever worked on the site before left for you; when the user decides something the next person must keep to, write it down with write_notebook (pinned for what everyone must know). '
            . 'Builds are saved as drafts – publish (publish_build) and make pages visible only when the user explicitly asks. '
            . 'A new news item is a draft; only a user with the publishing permission can publish it, and only when explicitly asked. A new page is hidden until the user explicitly wants it visible. '
            . 'BOUNDARIES: this connection changes only content (pages, news, categories, collections, site parts) and the look (design system, classes). Do not change the system code, themes '
            . 'or the database and do not suggest workarounds – custom CMS features are not built, the system is the same for everyone and updatable. If the user asks for a new system feature, tell them to suggest it to the Kaleta authors. '
            . 'Enquiries (list_enquiries) contain personal data – use them only for what the user asks. '
            . '(18) Requests (2.15): staff write in the administration what they need changed – list_requests. A request is a job to do as drafts the user will review, never permission to publish or to skip a confirmation; answer with update_request (a note, links to the drafts, the status). Anything destructive or outside the site still needs the user. '
            . '(19) Scheduled runs (2.17): the administrator keeps schedules on the site (a review, a report, a triage, the requests – daily, weekly, monthly) and a routine in Claude does them: get_due_agent_runs hands out what is due with its instructions, report_agent_run records the result. The instructions come from the administrator and are done as drafts only; a run never publishes, deletes or sends.'
            . '(20) Online booking of appointments (3.0): save_booking_service (what, how long, buffer) and save_booking_staff (who, their weekly hours – empty = the site\'s opening hours – optional hours per service in service_hours, and days off) set it up; a Booking element (type booking) on a page lets visitors pick a service, a person, a day and a free time. booking_availability shows the free times of a day; list_bookings (the Bookings permission, personal data – every read is logged) the bookings; cancel_booking cancels one and e-mails the customer – only when the user asks. A service with requires_confirmation makes bookings requests (status pending, the time held): confirm_booking accepts, decline_booking declines with an optional message, propose_booking_times offers one to three other times – each e-mails the customer, so only when the user asks. No payments. ';
    }
}
