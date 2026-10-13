<?php

declare(strict_types=1);

/**
 * The rename map of the data model (3.10, docs/design/4.0-data-model.md §4): every table of schema.sql (without the prefix)
 * => [its English name, [column => English column]]. One source of truth, read through Core\Names.
 *
 * - Nothing is renamed physically in 3.x. Db::sql() already resolves the English placeholder ({news}) to the table of today
 *   ({novinky} = `ka_novinky`), so code and add-ons may use either name, and a later release can rename the tables
 *   (option C, 4.0) while the last 3.x still finds them under both names.
 * - Tables named in English since 1.5 map to themselves. Every table is listed; tools/unit-tests.php checks that the map
 *   covers schema.sql exactly and that it is a bijection (no English name twice, none equal to another table's name today).
 * - Columns: the identifiers and the most-read columns of the core tables (the design's table). They are not renamed and
 *   not aliased in 3.x – the list is the target of the per-entity repositories (5.0). Every listed column exists today.
 * - MCP result keys stay as Mcp\Translator maps them: they are a public contract of their own.
 *
 * The file has no dependencies, so a data migration of any release may read it.
 */
return [
    // core content
    'stranky' => ['pages', ['ids' => 'id', 'seo_link' => 'slug', 'titulek' => 'title', 'popis' => 'description', 'zobrazit' => 'visible', 'zverejnit_od' => 'publish_at',
        'v_menu' => 'in_menu', 'poradi' => 'position', 'zmeneno' => 'updated_at', 'jazyk' => 'language', 'preklad_z' => 'translation_of', 'nadrazena' => 'parent_id',
        'stavba' => 'build', 'stavba_koncept' => 'build_draft', 'smazano' => 'deleted_at', 'kod_hlavicky' => 'head_code', 'heslo_hash' => 'password_hash']],
    'novinky' => ['news', ['idc' => 'id', 'seo_link' => 'slug', 'titulek' => 'title', 'uvod' => 'intro', 'obrazek' => 'image', 'tema' => 'category_id', 'autor' => 'author_id',
        'datum' => 'published_at', 't_slova' => 'keywords', 'hledani' => 'search_text', 'oznameno' => 'announced_at', 'aktualizovano' => 'revised_at', 'jazyk' => 'language',
        'preklad_z' => 'translation_of', 'smazano' => 'deleted_at']],
    'kategorie' => ['categories', ['idt' => 'id', 'nazev' => 'name', 'seo_link' => 'slug', 'popis' => 'description', 'hodnost' => 'position', 'jazyk' => 'language', 'preklad_z' => 'translation_of']],
    'stitky' => ['tags', ['ids' => 'id', 'nazev' => 'name', 'seo_link' => 'slug', 'jazyk' => 'language']],
    'novinky_stitky' => ['news_tags', ['idc' => 'news_id', 'ids' => 'tag_id']],
    'novinky_revize' => ['news_versions', ['idr' => 'id', 'idc' => 'news_id']],
    'novinky_koncepty' => ['news_drafts', ['idc' => 'news_id']],
    'stranky_revize' => ['page_versions', ['idr' => 'id', 'ids' => 'page_id']],
    'stavba_revize' => ['build_versions', ['idr' => 'id', 'ids' => 'page_id', 'cast' => 'part', 'stavba' => 'build']],
    'media' => ['media', ['ido' => 'id', 'vlastnik' => 'owner_id', 'sekce' => 'folder_id', 'obr_poloha' => 'image_path', 'obr_width' => 'image_width', 'obr_height' => 'image_height',
        'obr_vel' => 'image_size', 'nahl_poloha' => 'thumb_path', 'nahl_width' => 'thumb_width', 'nahl_height' => 'thumb_height', 'ohnisko' => 'focal_point']],
    'media_slozky' => ['media_folders', ['ids' => 'id', 'nazev' => 'name']],
    'media_pouziti' => ['media_usage', ['ido' => 'media_id', 'idc' => 'news_id']],
    'nastaveni' => ['settings', ['promenna' => 'name', 'hodnota' => 'value']],
    'presmerovani' => ['redirects', ['idp' => 'id', 'z_adresy' => 'source', 'na_adresu' => 'target', 'typ' => 'status', 'pocet' => 'hits']],
    // builder
    'casti' => ['site_parts', ['typ' => 'type', 'jazyk' => 'language', 'varianta' => 'variant', 'stranky' => 'page_ids', 'pravidla' => 'rules', 'stavba' => 'build', 'stavba_koncept' => 'build_draft']],
    'tridy' => ['classes', ['nazev' => 'name', 'styl' => 'style']],
    'menu' => ['menus', ['umisteni' => 'location', 'jazyk' => 'language', 'polozky' => 'items']],
    'popupy' => ['popups', ['idpp' => 'id', 'spoustec' => 'trigger', 'cetnost' => 'frequency', 'zobrazeni' => 'views', 'zavreni' => 'closes', 'konverze' => 'conversions']],
    'komponenty' => ['components', ['idm' => 'id', 'vlastnosti' => 'properties']],
    'sekce' => ['library_sections', ['idx' => 'id', 'prvek' => 'element']],
    'kolekce' => ['collections', ['idk' => 'id', 'pole' => 'fields']],
    'kolekce_polozky' => ['collection_items', ['idp' => 'id', 'idk' => 'collection_id', 'data' => 'data', 'jazyk' => 'language']],
    'kolekce_sablony' => ['collection_templates', ['idk' => 'collection_id', 'jazyk' => 'language']],
    'collection_categories' => ['collection_categories', []],
    'collection_category_texts' => ['collection_category_texts', []],
    'collection_item_categories' => ['collection_item_categories', []],
    'collection_category_templates' => ['collection_category_templates', []],
    'look_versions' => ['look_versions', []],
    'draft_comments' => ['draft_comments', []],
    'document_versions' => ['document_versions', []],
    'document_downloads' => ['document_downloads', []],
    // people and access
    'uzivatele' => ['users', ['idu' => 'id', 'jmeno' => 'name', 'blokovat' => 'blocked', 'posledni_login' => 'last_login_at', 'totp_tajemstvi' => 'totp_secret', 'jazyk' => 'language']],
    'uzivatele_prava' => ['user_modules', []],
    'uzivatele_klice' => ['user_passkeys', ['idk' => 'id', 'idu' => 'user_id']],
    'role' => ['roles', ['idr' => 'id', 'nazev' => 'name']],
    'api_tokeny' => ['api_tokens', ['idt' => 'id', 'idu' => 'user_id']],
    'oauth_klienti' => ['oauth_clients', ['idk' => 'id']],
    'oauth_kody' => ['oauth_codes', ['idu' => 'user_id']],
    'oauth_rotated' => ['oauth_rotated', []],
    'kontrola_ip' => ['ip_checks', ['idk' => 'id']],
    'protokol' => ['audit_log', ['idp' => 'id', 'cas' => 'created_at']],
    'souhlasy' => ['consents', ['cas' => 'created_at']],
    // enquiries, mail and subscribers
    'poptavky' => ['enquiries', ['idp' => 'id', 'formular' => 'form_name', 'zdroj' => 'source', 'prvek' => 'element_id', 'stav' => 'status', 'prirazeno' => 'assigned_to']],
    'posta' => ['mail_queue', ['idp' => 'id', 'komu' => 'recipient', 'predmet' => 'subject']],
    'odberatele' => ['subscribers', ['ido' => 'id', 'stav' => 'status', 'potvrzeno' => 'confirmed_at']],
    'odber_fronta' => ['subscriber_queue', ['idf' => 'id']],
    'newsletters' => ['newsletters', []],
    'newsletter_queue' => ['newsletter_queue', []],
    // links, imports and statistics
    'nenalezeno' => ['not_found', ['cesta' => 'path', 'pocet' => 'hits', 'naposledy' => 'last_seen_at']],
    'odkazy_vadne' => ['broken_links', ['ido' => 'id']],
    'import_mapa' => ['import_map', ['zdroj' => 'source', 'typ' => 'type', 'cizi_id' => 'source_id', 'nase_id' => 'target_id']],
    'stat_dny' => ['stats_days', []],
    'stat_navstevnici' => ['stats_visitors', []],
    'stat_novinky' => ['stats_news', []],
    'stat_stranky' => ['stats_pages', []],
    'stat_kampane' => ['stats_campaigns', []],
    'stat_zarizeni' => ['stats_devices', []],
    'stat_konverze' => ['stats_conversions', []],
    'stat_zdroje' => ['stats_sources', []],
    'web_vitals' => ['web_vitals', []],
    'search_stats' => ['search_stats', []],
    // English since 1.5
    'webhook_deliveries' => ['webhook_deliveries', []],
    'events' => ['events', []],
    'jobs' => ['jobs', []],
    'firewall_blocks' => ['firewall_blocks', []],
    'firewall_log' => ['firewall_log', []],
    'fleet_sites' => ['fleet_sites', []],
    'fleet_pairing' => ['fleet_pairing', []],
    'fleet_kits' => ['fleet_kits', []],
    'facts' => ['facts', []],
    'fact_history' => ['fact_history', []],
    'hours_exceptions' => ['hours_exceptions', []],
    'blueprints' => ['blueprints', []],
    'notice_log' => ['notice_log', []],
    'testimonial_requests' => ['testimonial_requests', []],
    'connectors' => ['connectors', []],
    'connector_queue' => ['connector_queue', []],
    'connector_log' => ['connector_log', []],
    'whistleblowing_cases' => ['whistleblowing_cases', []],
    'whistleblowing_messages' => ['whistleblowing_messages', []],
    'social_drafts' => ['social_drafts', []],
    'google_reviews' => ['google_reviews', []],
    'notebook' => ['notebook', []],
    'requests' => ['requests', []],
    'request_messages' => ['request_messages', []],
    'agent_sessions' => ['agent_sessions', []],
    'agent_journal' => ['agent_journal', []],
    'agent_schedules' => ['agent_schedules', []],
    'agent_runs' => ['agent_runs', []],
    'booking_services' => ['booking_services', []],
    'booking_staff' => ['booking_staff', []],
    'booking_staff_services' => ['booking_staff_services', []],
    'booking_hours' => ['booking_hours', []],
    'booking_off' => ['booking_off', []],
    'bookings' => ['bookings', []],
    'booking_proposals' => ['booking_proposals', []],
    // a time zone change that converts the saved moments (3.12)
    'time_zone_conversions' => ['time_zone_conversions', []],
    'time_zone_progress' => ['time_zone_progress', []],
];
