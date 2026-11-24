<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\Language;
use Kaleta\Mcp\Schema;

/**
 * MCP tools: site and pages (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait PageTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function pageToolsDefinitions(): array
    {
        $page = [
            'titulek' => Schema::text('Název stránky (zobrazí se v navigaci a jako nadpis)'), 'text' => Schema::text('Obsah stránky jako HTML'),
            'adresa' => Schema::text('Část adresy za doménou (seo_link); bez ní vznikne z názvu'), 'popis' => Schema::text('Popis pro vyhledávače, do 160 znaků'),
            'v_menu' => ['type' => 'boolean', 'description' => 'true = odkaz v hlavní navigaci webu'], 'poradi' => Schema::number('Pořadí v navigaci, menší = dřív'),
            'zobrazit' => ['type' => 'boolean', 'description' => 'true = stránka je na webu vidět (jen na výslovný pokyn uživatele), jinak skrytá. Stránka bez textu a bez publikované stavby počká skrytá a ukáže se s prvním publikováním stavby nebo textem'],
            'seo_titulek' => Schema::text('Titulek pro vyhledávače (nepovinné, jinak název)'), 'obrazek' => Schema::text('Obrázek pro sdílení na sociálních sítích (cesta z médií)'),
            'noindex' => ['type' => 'boolean', 'description' => 'true = skrýt stránku před vyhledávači'], 'canonical_url' => Schema::text('Canonical URL: https://… or /path on the site; empty = this page'),
            'nadrazena' => Schema::number('ID nadřazené stránky – adresa bude /nadrazena/stranka (0 = žádná)'),
            'jazyk' => Schema::text('jazyková verze stránky u vícejazyčného webu (kód, např. en; prázdné = výchozí jazyk)'),
            'preklad_z' => Schema::number('ID protějšku ve výchozím jazyce (u stránky jiné jazykové verze) – přepínač jazyků a hreflang'),
            'kopie_stavby' => ['type' => 'boolean', 'description' => 'jen u nové stránky s preklad_z: koncept začne kopií stavby originálu – pro překlad pak stavba_nacti s jen_texty a stavba_uprav'],
            'zverejnit_od' => Schema::text('naplánované zveřejnění skryté stránky RRRR-MM-DD HH:MM (jen na výslovný pokyn uživatele; prázdné = zrušit)'),
            'kod_hlavicky' => Schema::text('not settable through MCP – code for <head> of a page is set in the administration'),
            'valid_until' => Schema::text('True until YYYY-MM-DD (2.10): after this day it hides itself; empty string = always (optional)'), 'review_by' => Schema::text('Review by YYYY-MM-DD (2.10): on this day the site audit and the event content.review ask the user to check it; empty string = none (optional)'),
        ];
        return [
            'info_o_webu' => ['Název webu, úvodní stránka, šablona, počty stránek a novinek, role přihlášeného uživatele a jeho oprávnění.', Schema::object([])],
            'seznam_stranek' => ['Stránky webu (Úvod, O nás, Služby, Kontakt…) s adresami.', Schema::object(['hledat' => Schema::text('slova v názvu, adrese, titulku pro vyhledávače nebo textu (nepovinné)')])],
            'nacti_stranku' => ['Celá stránka včetně HTML obsahu.', Schema::object(['id' => Schema::number('ID stránky')], ['id'])],
            'vytvor_stranku' => ['Založí stránku (editor a správce). Bez "zobrazit": true zůstane skrytá.', Schema::object($page, ['titulek'])],
            'uprav_stranku' => ['Změní zadaná pole stránky; ostatní ponechá.', Schema::object(['id' => Schema::number('ID stránky')] + $page, ['id'])],
            'nacti_menu' => ['Menu webu (hlavní nebo v patičce) pro jazykovou verzi: položky s podmenu a jestli se hlavní menu zatím skládá automaticky ze stránek „v menu“.',
                Schema::object(['umisteni' => Schema::text('hlavni (výchozí) | paticka'), 'jazyk' => Schema::text('jazyková verze (prázdné = výchozí)')])],
            'uloz_menu' => ['Uloží celé menu (správce) do konceptu vzhledu. Položky: {"typ":"stranka","ids":5,"text":""} (prázdný text = název stránky) | {"typ":"odkaz","text":"…","url":"https://… nebo /cesta","nove_okno":false} | {"typ":"novinky"} | {"typ":"skupina","text":"Služby"} – každá může mít "deti" (jedna úroveň podmenu), "ikona" (klíč ze sady prvku Ikona) a "popis" (do 120 znaků, v mega menu pod textem). Skupina uvnitř podmenu může mít vlastní "deti" – v mega menu tvoří sloupec s nadpisem. null = hlavní menu zase automaticky. Skrytá stránka se v menu ukáže až po zveřejnění.',
                Schema::object(['umisteni' => Schema::text('hlavni | paticka'), 'jazyk' => Schema::text('jazyková verze (prázdné = výchozí)'), 'polozky' => ['type' => ['array', 'null'], 'items' => ['type' => 'object'], 'description' => 'položky menu']], ['umisteni', 'polozky'])],
            'list_trash' => ['Pages, news items and collection items in the trash (deleted in the last 30 days, then removed for good), with the date of deletion – what restore_from_trash can bring back.', Schema::object([])],
            'restore_from_trash' => ['Brings a page, news item or collection item back from the trash. It comes back hidden (a news item as a draft) – make it visible only when the user asks.',
                Schema::object(['type' => Schema::text('page | news | collection_item'), 'id' => Schema::number('ID from list_trash')], ['type', 'id'])],
            'translation_status' => ['Translation overview (read-only, 2.14): every page, news item and collection item in the default language against the site\'s other languages – present, missing, or outdated (the original changed after the translation was last saved). A page translation is a page with translation_of (create_page with translation_of and copy_build, then get_build texts_only and edit_build); a news translation a news item in a category of that language; a collection item translation an item with the same slug and language in the same collection (save_collection_item). Empty languages = a single-language site.',
                Schema::object(['status' => Schema::text('missing | outdated | all (default: missing and outdated only)'), 'type' => Schema::text('page | news | collection_item (optional)')])],
            // 3.52 (B1): what a visitor finds with the site search (Core\SiteSearch) – English only
            'search_site' => ['What a visitor finds with the site search (read-only, 3.52): one list ranked by relevance across published pages, collection items with their own page and news of one language version – only what visitors see (no hidden, password-protected or noindex content). Every word of the query, regardless of diacritics; a word matches the start of a word in the text, Czech, Slovak, English and German endings are taken off the query ("koupelna" finds "koupelen"). When nothing has every word, the results with some of them are listed and partial is true. results: type (page | collection_item | news), title, url, snippet (plain text around the matches); total = how many match.',
                Schema::object(['query' => Schema::text('the words to find (at least 2 characters)'), 'language' => Schema::text('language version (code, e.g. en; empty = the default language)'),
                    'limit' => Schema::number('1–50, default 10')], ['query'])],
            'smaz_stranku' => ['Přesune stránku do koše (jen na výslovný pokyn uživatele; editor nebo správce). Z koše jde 30 dní obnovit v administraci. Úvodní stránku smazat nejde.', Schema::object(['id' => Schema::number('ID stránky')], ['id'])],
        ];
    }

    /** site_info (info_o_webu) */
    private function toolSiteInfo(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        // the agent notebook (2.15, Core\Notebook): how many notes colleagues and earlier conversations left, and the pinned titles – read_notebook has them all
        // 3.15 (N71): only for a user with the Notebook section – the pinned titles are notes too
        $notebook = $auth->hasModule('notebook') ? \Kaleta\Core\Notebook::summary($db) : ['count' => 0, 'pinned' => []];
        // 3.51 (C3): the tool set always named; a connection that lists every tool hears what it costs and where to change it
        $connection = ($auth->connection() ?? ['name' => '', 'access' => 'full']) + ['tool_set' => 'all'];
        if ($connection['tool_set'] === 'all') {
            $connection['hint'] = \Kaleta\Mcp\Translator::ALL_TOOLS_HINT;
        }

        return [
            'web' => $siteSettings->get('site_name'), 'adresa' => $this->app->request->origin() . $this->app->url(''), 'popis' => $siteSettings->get('site_description'),
            'uvodni_stranka' => $siteSettings->int('home_page') ?: null, 'verze_kaleta' => KALETA_VERSION,
            'update_channel' => \Kaleta\Core\Updater::channel($siteSettings), // 3.8 (D3): latest (a minor every week) or stable (security fixes only)
            // 3.9: how the site sends e-mail and through which mail service – the name only, never the server's credentials
            'mail' => ['sending' => $siteSettings->get('mail_mode') === 'smtp' && $siteSettings->get('smtp_host') !== '' ? 'smtp' : 'server',
                'service' => \Kaleta\Core\MailServices::name(\Kaleta\Core\MailServices::current($siteSettings)) ?: null],
            'stranek' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE smazano IS NULL'),
            'novinek_vydanych' => (int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= ? AND smazano IS NULL', [\Kaleta\Core\Clock::now()]),
            'uzivatel' => $auth->user()['user'], 'role' => \Kaleta\Core\Auth::TYPES[(int) $auth->user()['admin']], 'smi_vydavat' => $auth->canPublish(),
            'smi_upravovat_stranky' => $auth->hasModule('pages'),
            'can_edit_appearance' => $auth->hasAppearanceArea(), // 3.22 (C9): menu, site parts, pop-ups and components
            // what this connection may do (2.2): full, drafts (reads and drafts, never publishes) or read
            'connection' => $connection,
            // what the site has switched on, so Claude does not guess (extension keys: novinky, poptavky, newsletter…)
            'extensions' => \Kaleta\Core\Extensions::enabled($siteSettings),
            // the whistleblowing channel (2.14): Claude learns only that it is on – no tool reads or lists its cases
            'whistleblowing' => \Kaleta\Core\Whistleblowing::isOn($siteSettings),
            // 3.34: job applications – on, whether the site lets Claude read them (applications_claude) and whether this user
            // may open them; list_applications works only with all three
            'features' => ['applications' => ['enabled' => \Kaleta\Core\Applications::isOn($siteSettings), 'claude' => $siteSettings->bool('applications_claude'),
                'can_open' => $auth->id() > 0 && \Kaleta\Core\Handover::mayOpen($db, $siteSettings, $auth->id(), \Kaleta\Core\Applications::SECTION)]],
            'notebook_count' => $notebook['count'], 'notebook_pinned' => $notebook['pinned'],
            // direction: rtl for Arabic – the site mirrors the layout itself, Claude writes the text in its natural order
            'languages' => ['german_address' => Language::visitorAddress($siteSettings), 'default' => Language::defaults($siteSettings), 'direction' => Language::dir(Language::defaults($siteSettings)),
                'additional' => array_map(fn (string $code): array => ['code' => $code, 'published' => in_array($code, Language::published($siteSettings, $db), true), 'direction' => Language::dir($code)], Language::additional($siteSettings))],
            'cron_last_run_minutes' => $siteSettings->int('tasks_last_run') > 0 ? (int) floor((time() - $siteSettings->int('tasks_last_run')) / 60) : null,
            'look_draft' => \Kaleta\Core\Language::runWith('en', fn (): array => \Kaleta\Core\Look::summary($db, $siteSettings), 'admin-'), // unpublished look changes (publish_look, discard_look), in English like publish_look
        ] + (\Kaleta\Fleet\Link::isPaired($siteSettings) ? [
            // the shared design kit of the fleet console (2.16, Fleet\Kit): which version arrived here as drafts, and when
            'fleet_kit' => ['enabled' => $siteSettings->bool('fleet_kit'), 'version' => $siteSettings->int('fleet_kit_version') ?: null,
                'applied_at' => $siteSettings->int('fleet_kit_applied_at') > 0 ? date('Y-m-d H:i:s', $siteSettings->int('fleet_kit_applied_at')) : null],
        ] : []);
    }

    /** list_pages (seznam_stranek) */
    private function toolListPages(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        $home = $siteSettings->int('home_page');
        $waiting = \Kaleta\Core\ImportLayout::pending($db); // 3.33: imported pages whose build is still the imported text
        // 3.52 (A9): the same search as the admin list of pages (no search = every page)
        [$search, $params] = \Kaleta\Core\Db::likeWords(\Kaleta\Admin\Modules\Pages::SEARCH_COLUMNS, is_scalar($a['hledat'] ?? null) ? (string) $a['hledat'] : '');

        // the URL of a language version has a prefix (/de/…); the translation of the home page is the root of its version (/de/)
        return array_map(fn (array $r): array => ['id' => (int) $r['ids'], 'titulek' => $r['titulek'], 'adresa' => $this->app->request->origin()
            . $this->app->url(($r['jazyk'] !== '' ? $r['jazyk'] . '/' : '') . ((int) $r['ids'] === $home || ($home > 0 && (int) $r['preklad_z'] === $home) ? '' : $r['seo_link'])),
            'uvodni' => (int) $r['ids'] === $home, 'zobrazena' => (bool) $r['zobrazit'], 'v_menu' => (bool) $r['v_menu'], 'jazyk' => $r['jazyk']]
            // 3.24: the responsible person ({id, name}; a removed account is "(former user)"), only when one is set
            + ($r['owner_id'] !== null ? ['owner' => \Kaleta\Core\Handover::person($db, $r['owner_id'])] : [])
            // 3.33: the work queue of a move – an imported page whose layout waits to be rebuilt (clears with the first edit of its build)
            + (isset($waiting[(int) $r['ids']]) ? ['layout_not_rebuilt' => true] : []),
            // without the Pages section (news author) only published pages – it can link to those, it does not see drafts
            $db->all('SELECT ids, titulek, seo_link, zobrazit, v_menu, jazyk, preklad_z, owner_id FROM {stranky} WHERE smazano IS NULL AND ' . $search . $auth->pageListScope() . ' ORDER BY jazyk, poradi, ' . $db->sortText('titulek'), $params));
    }

    /**
     * search_site (3.52, B1): the site search for a visitor of one language version (Core\SiteSearch) – the best results with
     * their public address and a plain-text snippet, how many match, and partial when none has every word.
     */
    private function toolSearchSite(string $name, array $a): mixed
    {
        $q = is_scalar($a['query'] ?? null) ? trim((string) $a['query']) : '';
        if (!\Kaleta\Core\Search::longEnough($q)) {
            throw new \InvalidArgumentException('Give at least two characters to search for (query).');
        }
        $column = is_string($a['language'] ?? null) && $a['language'] !== '' ? Language::column($this->app->settings(), $a['language']) : '';
        $limit = max(1, min(50, (int) ($a['limit'] ?? 10)));
        $found = \Kaleta\Core\SiteSearch::find($this->app, $q, $column);
        $origin = $this->app->request->origin();
        $shown = array_slice($found['results'], 0, $limit);
        $results = array_map(fn (array $r, array $pieces): array => ['type' => $r['type'], 'title' => $r['title'],
            'url' => $origin . ($r['type'] === 'news' ? $this->app->newsItemUrl(substr($r['path'], strlen('novinky/')), $column) : $this->app->url(($column !== '' ? $column . '/' : '') . $r['path']))]
            + (($snippet = \Kaleta\Core\SiteSearch::snippetText($pieces)) !== '' ? ['snippet' => $snippet] : []),
            $shown, \Kaleta\Core\SiteSearch::snippets($this->app, $shown, $found['words']));

        return ['results' => $results, 'total' => $found['total']] + ($found['partial'] ? ['partial' => true] : [])
            + ($results !== [] ? \Kaleta\Mcp\Untrusted::mark(['results']) : []);
    }

    /** get_page (nacti_stranku) */
    private function toolGetPage(string $name, array $a): mixed
    {
        $auth = $this->app->auth();

        $page = $this->page((int) ($a['id'] ?? 0));
        if (!$page['zobrazit'] && !$auth->hasModule('pages')) {
            throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');
        }
        // whether visitors need a password (2.14, Core\PageLock) – never the password or its hash; it is set in the admin
        $page['password_protected'] = $this->app->db()->value('SELECT heslo_hash IS NOT NULL FROM {stranky} WHERE ids = ?', [(int) $page['ids']]) == 1;
        if ($page['obrazek'] === '' && $this->app->settings()->get('share_image') === '') {
            // the picture the site draws for sharing (2.12) – the same title as on the page (the home page has none)
            $title = $page['seo_titulek'] !== '' ? $page['seo_titulek'] : ((int) $page['ids'] === $this->app->settings()->int('home_page') ? '' : $page['titulek']);
            $generated = \Kaleta\Front\ShareImage::url($this->app, \Kaleta\Core\Facts::fillText($title, $this->app));
            if ($generated !== null) {
                $page['share_image_generated'] = $generated;
            }
        }
        // content check of the saved version (2.14, Core\ContentCheck), read-only – the same list the editor shows
        $builds = $this->app->db()->one('SELECT stavba, stavba_koncept FROM {stranky} WHERE ids = ?', [(int) $page['ids']]) ?? [];
        $page['content_check'] = Language::runWith('en', fn (): array => \Kaleta\Core\ContentCheck::forPage($page + $builds), 'admin-');

        return $page;
    }

    /** translation_status (2.14, Core\Translations) */
    private function toolTranslationStatus(string $name, array $a): mixed
    {
        $matrix = \Kaleta\Core\Translations::matrix($this->app);
        $status = in_array($a['status'] ?? '', ['missing', 'outdated', 'all'], true) ? $a['status'] : '';
        $type = in_array($a['type'] ?? '', ['page', 'news', 'collection_item'], true) ? $a['type'] : '';
        $counts = ['missing' => 0, 'outdated' => 0, 'present' => 0];
        $items = [];
        foreach ($matrix['rows'] as $row) {
            foreach ($row['translations'] as $cell) {
                $counts[$cell['status']]++;
            }
            $keep = $status === 'all' ? $row['translations']
                : array_filter($row['translations'], fn (array $c): bool => $status === '' ? $c['status'] !== \Kaleta\Core\Translations::PRESENT : $c['status'] === $status);
            if ($keep === [] || ($type !== '' && $row['type'] !== $type)) {
                continue;
            }
            $items[] = ['type' => $row['type'], 'id' => $row['id'], 'title' => $row['title'], 'changed' => $row['changed']]
                + (isset($row['collection']) ? ['collection' => $row['collection']['seo_link']] : []) + ['translations' => $keep];
        }

        $languages = [Language::defaults($this->app->settings()), ...$matrix['languages']];

        return ['default_language' => Language::defaults($this->app->settings()), 'languages' => $matrix['languages'],
            'directions' => array_combine($languages, array_map(Language::dir(...), $languages)), 'summary' => $counts, 'items' => $items,
            'next' => $matrix['languages'] === [] ? 'The site has a single language; language versions are set with update_settings (additional_languages).'
                : 'Translate only on the user\'s instruction and as drafts: a page with create_page (language, translation_of, copy_build) and edit_build; a news item with create_news in a category of that language; a collection item with save_collection_item (language, the same slug). An outdated translation needs its texts compared with the original.'];
    }

    /** create_page and update_page (vytvor_stranku, uprav_stranku) */
    private function toolCreatePage(string $name, array $a): mixed
    {
        $auth = $this->app->auth();

        if (!$auth->hasModule('pages')) {
            throw new \DomainException('Stránky smí upravovat editor nebo správce.');
        }

        return $this->savePage($name === 'uprav_stranku' ? $this->page((int) ($a['id'] ?? 0)) : null, $a);
    }

    /** update_page: the same as create_page */
    private function toolUpdatePage(string $name, array $a): mixed
    {
        return $this->toolCreatePage($name, $a);
    }

    /** trash_page (smaz_stranku) */
    private function toolTrashPage(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        if (!$auth->canPublish() || !$auth->hasModule('pages')) {
            throw new \DomainException('Stránku smí smazat editor nebo správce.');
        }
        $page = $this->page((int) ($a['id'] ?? 0));
        if ((int) $page['ids'] === $siteSettings->int('home_page')) {
            throw new \DomainException('Úvodní stránku smazat nejde – nejdřív nastav jinou (uprav_nastaveni → titulni_stranka).');
        }
        \Kaleta\Builder\Publisher::guardRemoval($db->value('SELECT stavba FROM {stranky} WHERE ids = ?', [(int) $page['ids']]), $this->app); // 3.50.1 review: an application form needs the section
        $db->run('UPDATE {stranky} SET smazano = ?, zobrazit = 0, show_on_publish = 0 WHERE ids = ? AND smazano IS NULL', [\Kaleta\Core\Clock::now(), (int) $page['ids']]);
        \Kaleta\Core\PageCache::clear();

        return ['id' => (int) $page['ids'], 'stav' => 'v koši – obnovit jde 30 dní v administraci (Stránky → Koš)'];
    }

    /** get_menu and save_menu (nacti_menu, uloz_menu) */
    private function toolGetMenu(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();

        $location = isset(\Kaleta\Core\Menu::LOCATIONS[$a['umisteni'] ?? '']) ? $a['umisteni'] : 'hlavni';
        $menuLanguage = in_array($a['jazyk'] ?? '', Language::additional($siteSettings), true) ? $a['jazyk'] : '';
        if ($name === 'uloz_menu') {
            if (!$auth->hasAppearanceArea()) { // 3.22 (C9): the draft look also for a user with the Appearance area
                throw new \DomainException('The menu can be changed only by an administrator or a user with the Appearance area.');
            }
            // null restores the automatic menu – only when the caller really sent it, not when items are missing or cannot be read
            if (!array_key_exists('polozky', $a) || ($a['polozky'] !== null && !is_array($a['polozky']))) {
                throw new \InvalidArgumentException('Parametr polozky musí být seznam položek menu, nebo null pro automatické menu.');
            }
            \Kaleta\Core\Look::setMenu($siteSettings, $location, $menuLanguage, $a['polozky']); // to the draft look
        }
        [$inDraft, $saved] = \Kaleta\Core\Look::menuForEditing($db, $siteSettings, $location, $menuLanguage);

        return ['umisteni' => $location, 'jazyk' => $menuLanguage, 'automaticke' => $saved === null, 'polozky' => $saved ?? [],
            'look_draft' => $inDraft ? 'the items are in the draft look – visitors see them after publish_look' : null,
            'na_webu' => \Kaleta\Core\Menu::items($this->app, $location, $menuLanguage, $siteSettings->int('home_page')),
            'stranky' => $db->all('SELECT ids, titulek, zobrazit FROM {stranky} WHERE jazyk = ? AND smazano IS NULL ORDER BY poradi, ' . $db->sortText('titulek', $menuLanguage === '' ? null : $menuLanguage), [$menuLanguage])];
    }

    /** save_menu: the same as get_menu */
    private function toolSaveMenu(string $name, array $a): mixed
    {
        return $this->toolGetMenu($name, $a);
    }

    /** list_trash */
    private function toolListTrash(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();

        $out = [];
        if ($auth->hasModule('pages')) {
            $out['pages'] = array_map(fn (array $r): array => ['id' => (int) $r['ids'], 'title' => $r['titulek'], 'deleted_at' => substr((string) $r['smazano'], 0, 16)],
                $db->all('SELECT ids, titulek, smazano FROM {stranky} WHERE smazano IS NOT NULL ORDER BY smazano DESC LIMIT 100'));
        }
        if ($auth->hasModule('news') && \Kaleta\Core\Extensions::isEnabled($this->app->settings(), 'novinky')) {
            $out['news'] = array_map(fn (array $r): array => ['id' => (int) $r['idc'], 'title' => $r['titulek'], 'deleted_at' => substr((string) $r['smazano'], 0, 16)],
                $db->all('SELECT idc, titulek, smazano FROM {novinky} WHERE smazano IS NOT NULL' . ($auth->canPublish() ? '' : ' AND autor = ' . (int) $auth->id()) . ' ORDER BY smazano DESC LIMIT 100'));
        }
        if ($auth->hasModule('collections')) {
            $out['collection_items'] = array_map(fn (array $r): array => ['id' => (int) $r['idp'], 'collection' => $r['kolekce'], 'name' => $r['nazev'], 'deleted_at' => substr((string) $r['smazano'], 0, 16)],
                $db->all('SELECT p.idp, p.nazev, p.smazano, k.seo_link AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.smazano IS NOT NULL ORDER BY p.smazano DESC LIMIT 100'));
        }

        return $out;
    }

    /** restore_from_trash */
    private function toolRestoreFromTrash(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $id = (int) ($a['id'] ?? 0);
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $type = (string) ($a['type'] ?? '');
        $renamed = null; // a page whose slug the news took meanwhile (news_slug) or the system has (3.7) comes back under a free one
        $old = '';
        if ($type === 'page') {
            $need($auth->hasModule('pages'), 'Pages can be restored by editors and administrators.');
            $old = (string) $db->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$id]);
            $ok = $db->run('UPDATE {stranky} SET smazano = NULL WHERE ids = ? AND smazano IS NOT NULL', [$id])->rowCount() > 0;
            $renamed = $ok ? \Kaleta\Admin\Modules\Pages::freeRestoredSlug($db, $id) : null;
        } elseif ($type === 'news') {
            $need($auth->hasModule('news'), 'News items can be restored only by users with the News section.');
            $ok = $db->run('UPDATE {novinky} SET smazano = NULL WHERE idc = ? AND smazano IS NOT NULL' . ($auth->canPublish() ? '' : ' AND autor = ' . (int) $auth->id()), [$id])->rowCount() > 0;
        } elseif ($type === 'collection_item') {
            $need($auth->hasModule('collections'), 'Collection items can be restored only by users with the Collections section.');
            $item = $db->one('SELECT idk FROM {kolekce_polozky} WHERE idp = ? AND smazano IS NOT NULL', [$id]);
            $ok = $item !== null && \Kaleta\Admin\Modules\Collections::restoreItem($db, $id, (int) $item['idk']);
            $renamed = $ok ? \Kaleta\Builder\CollectionCategories::freeRestoredItemSlug($db, $id) : null;
        } else {
            throw new \InvalidArgumentException('type must be page, news or collection_item.');
        }
        if (!$ok) {
            throw new \InvalidArgumentException('It is not in the trash. Use list_trash.');
        }

        return ['restored' => $type, 'id' => $id, 'visible' => false] + ($renamed === null ? [] : match (true) {
            $type === 'collection_item' => ['slug' => $renamed, 'note' => 'A category of the collection has the old address of the item, so the item got a new one.'],
            \Kaleta\Core\Routes::isNewsSlug($old, $db) => ['address' => '/' . $renamed, 'note' => 'The news now uses the old address of the page, so the page got a new one.'],
            default => ['address' => '/' . $renamed, 'note' => 'The system uses the old address /' . $old . ' of the page, so the page got a new one.'],
        });
    }
}
