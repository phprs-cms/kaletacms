<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Admin\Modules\Media;
use Kaleta\Admin\Modules\Categories;
use Kaleta\Admin\Modules\Pages;
use Kaleta\Core\App;
use Kaleta\Core\Language;
use Kaleta\Front\SiteIdentity;
use Kaleta\Builder\SiteParts;
use Kaleta\Builder\DesignSystem;
use Kaleta\Builder\Library;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Publisher;
use Kaleta\Builder\Build;
use Kaleta\Builder\HtmlConverter;
use Kaleta\Mcp\Schema;

/**
 * MCP tools: settings, redirects and audit (one method per tool, see Mcp\Catalog). Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait SettingsTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function settingsToolsDefinitions(): array
    {
        return [
            'uprav_nastaveni' => ['Změní nastavení webu (správce) – hned se projeví na webu; logo a favicon jdou do konceptu vzhledu (publish_look). Klíče: nazev_webu, popis_webu, text_paticky, logo_webu, favicon a og_obrazek – obrázek pro sdílení 1200×630 (cesta media/… z nahraj_soubor nebo image/…), titulni_stranka (ID úvodní stránky), soc_facebook|instagram|x|youtube|linkedin (URL), '
                . 'pocet_clanku, sdileni, osnova_clanku, souvisejici_auto (1/0), tmavy_rezim (vypnuto | auto = podle zařízení | tmavy = vždy tmavý), tmavy_prepinac (1/0 = přepínač vzhledu pro návštěvníky), údaje firmy firma_nazev, firma_typ, firma_ico, firma_dic, firma_rejstrik (zápis v rejstříku), firma_zastupce (kdo firmu zastupuje), firma_ulice, firma_mesto, firma_psc, firma_zeme (CZ), firma_telefon, firma_hodiny (den na řádek), firma_mapa, firma_gps; nazev_webu_en… pro jazykové verze. Bez parametru vrátí současné hodnoty.',
                Schema::object(['nastaveni' => ['type' => 'object', 'description' => '{"klic":"hodnota"}']])],
            'seznam_presmerovani' => ['Přesměrování starých adres (rozšíření Přesměrování) a nejčastější adresy, které skončily chybou 404.', Schema::object([])],
            'uloz_presmerovani' => ['Přidá nebo změní přesměrování (správce): ze staré cesty na webu na novou cestu nebo https adresu. Typ 301 = natrvalo (výchozí), 302 = dočasně.',
                Schema::object(['z' => Schema::text('stará cesta, např. /docs nebo /o-nas'), 'na' => Schema::text('nová cesta (/guide) nebo https://…'), 'typ' => Schema::number('301 nebo 302'), 'smazat' => ['type' => 'boolean', 'description' => 'true = přesměrování ze staré cesty smazat']], ['z'])],
            'site_audit' => ['Site audit (read-only). sample: placeholders in square brackets, sample testimonials, figures and texts still on published pages – replace them with the owner\'s real content or delete them, never with invented ones. link: links to missing pages, broken external links (list_broken_links). orphan: pages nothing links to (suggest_internal_links). description, title (duplicates), menu (items to hidden pages), build (buttons without a link, images without alt, heading outline), not_found (frequent 404s without a redirect). Each finding has its target (page / collection+item / part / component / popup / news / menu / redirect_from) and, for builds, the element id – fix it with the usual tools, then run the audit again. accessibility (EAA, WCAG 2.2 AA): design system contrast, link and button texts that do not say where they lead, images without alt, empty links, tables without header cells, frames without a title, no accessibility statement. handover: SMTP tested, off-site backups, company details, indexing, icon, tracking without consent, security contact, two-step sign-in, accounts unused for 90 days and Claude connections for 60, automatic suspension, client account, report recipient, guardrails, agency contact, Notebook, cron, SPF/DMARC, certificate and domain expiry; handover_checklist = every row with done; move (on a site with imported pages or a migration report) = where the move stands: pages imported and published, imported pages not reworked yet, sample pages left, redirects, the newest migration report (errors, lost forms, addresses on sample pages) and its rows with done. speed: pages whose real-user p75 LCP got worse by over a quarter against the previous 30 days (get_stats → web_vitals). review: pages, news, collection items and pop-ups whose review_by day has come (valid_until and review_by of create_page, update_page, create_news, update_news, save_collection_item, save_popup). job: a visible job without a closing date – search engines need validThrough and it never hides itself (valid_until of save_collection_item).',
                Schema::object(['kind' => Schema::text('only one kind: link | orphan | menu | description | title | build | review | job | document | accessibility | not_found | speed | fact | blueprint | handover (optional)')])],
            'get_stats' => ['What is working (read-only; users with Statistics): visits, page views, devices, campaigns (utm) and the sites visitors come from, and the leads – enquiries and newsletter sign-ups – by page, first page of the visit, campaign and referring site, with conversion rates; pop-up views and conversions; most read news; real-user speed (web_vitals: p75 of LCP in ms, CLS and INP in ms per page from visitors’ browsers, rated good | needs_improvement | poor by Google’s thresholds); contact clicks (contact_clicks: calls, emails and whatsapp – clicks on phone numbers, e-mail addresses and WhatsApp links – in total and by_page, each counted once per visitor, page and day without cookies; the same numbers are in pages as calls, emails, whatsapp); search engines (search: per engine google and bing – null until the site is connected in Administration → Connections and the daily job ran – the latest 28-day snapshot of the period: day, queries and pages with clicks, impressions, ctr in per cent and the average position, and for Google the sitemaps with submitted and indexed counts). Counts only, no personal data.',
                Schema::object(['days' => Schema::number('period: 7, 30 (default), 90 or 365 days')])],
            'list_changes' => ['The change log (administrators; read-only): who changed what and when – people in the admin and Claude, with the connection a change came through and the reason Claude gave (2.15). Newest first; the log keeps six months.',
                Schema::object(['by' => Schema::text('people | claude (optional; default both)'), 'limit' => Schema::number('how many, 1–200, default 50'), 'since' => Schema::text('only changes from this day on, YYYY-MM-DD (optional)')])],
            'list_agent_sessions' => ['Claude sessions (administrators; read-only, 2.17): the changes one Claude connection made in a row – when, which connection, how many rows, which tools, whether undone. A session can be undone as a whole with undo_agent_session.',
                Schema::object(['limit' => Schema::number('1–100, default 20')])],
            'undo_agent_session' => ['Undoes everything one Claude session changed (administrators, 2.17) – only when the user explicitly asks: pages, builds and drafts, news, items, menus, settings and the look go back to how they were before the session. Rows someone changed since are left alone and listed unless force=true; writes undo cannot follow are listed too. confirm=true is required.',
                Schema::object(['id' => Schema::number('session id from list_agent_sessions'), 'confirm' => ['type' => 'boolean', 'description' => 'true = the user asked to undo this session'], 'force' => ['type' => 'boolean', 'description' => 'true = also overwrite rows changed after the session (only when the user says so)']], ['id', 'confirm'])],
            'ignore_not_found' => ['Ignores addresses that ended with 404 (from list_redirects → not_found): a bot probe or an address nothing replaces. They leave the list and the start-screen warning for good. Redirect real old addresses with save_redirect instead. Only when the user asks.',
                Schema::object(['paths' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'addresses to ignore, e.g. ["/old-page"]'], 'all' => ['type' => 'boolean', 'description' => 'true = all addresses waiting now']])],
            'save_redirects' => ['Adds or changes many redirects at once (administrators, 3.6) – the old addresses of a site being moved, the rules of its SEO or redirect plugin. Each one is {"from":"/old","to":"/new","code":301}; the target is a path or an https://… address, code 301 (permanent, default) or 302; code 410 has no target – the address answers 410 Gone, so search engines drop it (spam addresses of a hacked site). '
                . 'A pattern keeps the rest of the address with an asterisk: {"from":"/blog/*","to":"/news/*"} sends /blog/2019/post to /news/2019/post (at most 3 asterisks, no regular expressions). An exact redirect always wins over a pattern, the longest fixed beginning wins among patterns, and a rule that would make a loop is refused. '
                . 'Every row is checked on its own and the result says per row: added, changed, unchanged or refused with the reason. dry_run: true only checks and saves nothing – use it to show the user what would change. At most ' . \Kaleta\Core\RedirectRules::MAX_BATCH . ' per call; save_redirect stays for one redirect and for deleting.',
                Schema::object(['redirects' => ['type' => 'array', 'items' => ['type' => 'object'], 'description' => '[{"from":"/old-page","to":"/new-page","code":301},{"from":"/blog/*","to":"/news/*"}]'],
                    'dry_run' => ['type' => 'boolean', 'description' => 'true = only check the rows and say what would happen, save nothing']], ['redirects'])],
            'processing_record' => ['Record of processing (read-only, administrators, 2.14): a GDPR Art. 30 style record assembled from what the site is configured to do – the forms and their fields, enquiries and job applications with their retention, the newsletter, the statistics, connected services, mail, backups, the AI assistant, the spam check, the cookies and storage, security. Markdown. A template for the owner to review and complete, never legal advice – say so when you hand it over.',
                Schema::object([])],
            'accessibility_statement' => ['Accessibility statement (read-only, 2.14): the statement text filled from the site audit run now – the standard (EN 301 549 / WCAG 2.1 AA), the status (fully or partially compliant by the accessibility findings), the known barriers, the contact and the date – and whether the site already has it as a page (Settings → Privacy and cookies creates or updates it as a hidden draft page). A template the owner reviews before publishing; fix the listed barriers first (site_audit kind accessibility), then regenerate.',
                Schema::object([])],
        ];
    }

    /** update_settings (uprav_nastaveni) */
    private function toolUpdateSettings(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        $adminOnly();
        $instructionsBefore = $siteSettings->get('claude_instructions'); // 3.15 (N44): a change goes to the change log and the dashboard
        $changes = is_array($a['nastaveni'] ?? null) ? $a['nastaveni'] : [];
        $stored = [];
        $errors = [];
        $inLookDraft = [];
        foreach ($changes as $key => $value) {
            // keys of 1.4.0 and older still work (nazev_webu, firma_email, nazev_webu_de…)
            $key = (string) $key;
            $key = \Kaleta\Core\OldSettingsKeys::current($key); // keys of 1.4.0 and older keep working over MCP
            $value = \Kaleta\Mcp\Translator::settingValue($key, $value); // 3.45: English values (dark, allow, built_in…) besides the Czech ones
            if ($key === 'extensions' || $key === 'additional_languages') {
                // lists (2.2): extensions switched on, further language versions of the site
                $list = array_values(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : explode(',', (string) $value))));
                if ($key === 'extensions') {
                    $unknown = array_diff($list, array_keys(\Kaleta\Core\Extensions::CATALOG));
                    if ($unknown !== [] || !in_array('claude', $list, true)) {
                        $errors[$key] = $unknown !== [] ? 'Unknown extensions: ' . implode(', ', $unknown) . '. Known: ' . implode(', ', array_keys(\Kaleta\Core\Extensions::CATALOG)) . '.'
                            : 'The Claude connection cannot switch itself off – keep "claude" in the list (the user can switch it off in the admin).';
                        continue;
                    }
                    \Kaleta\Core\Extensions::save($siteSettings, $list);
                    $stored[$key] = \Kaleta\Core\Extensions::enabled($siteSettings);
                } else {
                    $codes = explode('|', \Kaleta\Core\Language::CODES);
                    $unknown = array_diff($list, $codes);
                    if ($unknown !== [] || in_array(\Kaleta\Core\Language::defaults($siteSettings), $list, true)) {
                        $errors[$key] = $unknown !== [] ? 'Unknown language codes: ' . implode(', ', $unknown) . '.' : 'The default language of the site is not a further language version.';
                        continue;
                    }
                    $siteSettings->set($key, implode(',', array_unique($list)));
                    $stored[$key] = array_values(array_unique($list));
                }
                continue;
            }
            if ($key === 'screen_collections') {
                // screen mode (2.11): the collections the screen shows – only ones that exist
                $list = array_values(array_unique(array_filter(array_map('trim', is_array($value) ? array_map('strval', $value) : explode(',', (string) $value)))));
                $known = array_map('strval', array_column($db->all('SELECT seo_link FROM {kolekce}'), 'seo_link'));
                $unknown = array_diff($list, $known);
                if ($unknown !== []) {
                    $errors[$key] = 'Unknown collections: ' . implode(', ', $unknown) . ' (list_collections).';
                    continue;
                }
                $siteSettings->set($key, implode(',', $list));
                $stored[$key] = $list;
                continue;
            }
            if (in_array($key, ['logo', 'favicon', 'share_image'], true)) {
                // logo and icon: a file from Media (nahraj_soubor) or from the system (image/…); empty = no logo / icon
                $path = ltrim(trim((string) $value), '/');
                $ok = $path === '' || (preg_match('#^(media|image)/[A-Za-z0-9/_.-]{1,200}\.(svg|png|webp|jpe?g|avif)$#D', $path) && !str_contains($path, '..') && is_file(KALETA_ROOT . '/' . $path));
                if (!$ok) {
                    $errors[$key] = 'Cesta k souboru z Médií (media/…) nebo ze systému (image/…); ikona musí jít převést na PNG.';
                    continue;
                }
                if ($key !== 'share_image') {
                    // 3.12 (UX310-11): the logo and the icon go to the draft look, as in Site appearance – publish_look puts
                    // them live (and prepares the icon's PNG sizes)
                    if (\Kaleta\Core\Look::setIdentity($siteSettings, $key, $path)) {
                        $inLookDraft[] = $key;
                    }
                    $stored[$key] = $path;
                    continue;
                }
                $siteSettings->set($key, $path);
                $stored[$key] = $path;
                continue;
            }
            // 3.45 review L8: a yes/no setting refuses a word it does not know instead of storing it as off
            if (is_scalar($value) && !is_bool($value) && \Kaleta\Core\Settings::claudeMaySet($key)
                && ($key === 'stats' ? !in_array(strtolower(trim((string) $value)), [...\Kaleta\Admin\Modules\Settings::SWITCH_ON, ...\Kaleta\Admin\Modules\Settings::SWITCH_OFF], true)
                    : \Kaleta\Admin\Modules\Settings::strictSwitchRefuses($key, (string) $value))) {
                $errors[$key] = 'A yes/no setting takes 1 or 0 (also true/false, yes/no, on/off).';
                continue;
            }
            if ($key === 'stats' && is_scalar($value)) {
                // 3.2: the Statistics feature is the only switch – the old setting keeps working and switches the feature
                $on = is_bool($value) ? $value : in_array(strtolower(trim((string) $value)), \Kaleta\Admin\Modules\Settings::SWITCH_ON, true);
                $list = array_values(array_diff(\Kaleta\Core\Extensions::enabled($siteSettings), ['statistika']));
                \Kaleta\Core\Extensions::save($siteSettings, $on ? [...$list, 'statistika'] : $list);
                $stored[$key] = $on ? '1' : '0';
                continue;
            }
            // booking settings have no type in the settings form: they are checked like the Bookings settings form does
            $clean = \Kaleta\Core\Settings::claudeMaySet($key) && is_scalar($value)
                ? (str_starts_with($key, 'booking_') ? \Kaleta\Core\Booking::settingValue($key, (string) $value) : \Kaleta\Admin\Modules\Settings::verifyValue($key, is_bool($value) ? ($value ? '1' : '0') : (string) $value))
                : null;
            if ($clean !== null && $key === 'home_page' && (int) $clean > 0
                && $db->value('SELECT ids FROM {stranky} WHERE ids = ? AND zobrazit = 1 AND smazano IS NULL', [(int) $clean]) === null) {
                $errors[$key] = 'Úvodní stránkou může být jen zveřejněná stránka.';
                continue;
            }
            if ($key === 'news_slug' && is_scalar($value) && ($slugError = \Kaleta\Core\Routes::slugError(trim((string) $value), $db)) !== null) {
                // before the generic check, so a badly formed slug gets the reason, not just "invalid value"
                $errors[$key] = $slugError;
                continue;
            }
            if (in_array($key, ['head_code', 'marketing_code', 'cookies_external_code'], true)) {
                $errors[$key] = 'Code that runs on the site is set only in the administration (Settings), not through a Claude connection.';
                continue;
            }
            if (in_array($key, ['gtm_id', 'matomo_url', 'matomo_id'], true)) {
                // 3.3.2: a GTM container or a Matomo host runs whatever script its owner configures – the administrator sets them
                $errors[$key] = 'Google Tag Manager and Matomo load script chosen by whoever runs them, so they are set only in the administration (Settings → Analytics), not through a Claude connection. ga4_id and plausible_domain can be set here.';
                continue;
            }
            if ($clean === null) {
                $errors[$key] = \Kaleta\Core\Settings::claudeMaySet($key) ? 'Neplatná hodnota.' : 'Tohle nastavení přes MCP měnit nejde (jen v administraci).';
                continue;
            }
            if (\Kaleta\Core\Retention::keyOf($key) !== null) {
                \Kaleta\Core\Retention::saveUnconfirmed($this->app, 'settings', [$key => $clean]); // 3.48 B: a shorter period goes to the change log with both values
            } else {
                $siteSettings->set($key, $clean);
            }
            $stored[$key] = $clean;
        }
        if ($stored !== []) {
            \Kaleta\Core\PageCache::clear();
        }
        \Kaleta\Core\ClaudeInstructions::changed($this->app, $instructionsBefore, $siteSettings->get('claude_instructions'));
        if (isset($stored['company_hours'])) {
            \Kaleta\Core\GoogleBusiness::hoursChanged($this->app); // the Business Profile gets the new week (2.13)
        }
        if (($stored['screen_mode'] ?? '') === '1') {
            \Kaleta\Front\Screen::ensureSecret($siteSettings); // the address exists as soon as the mode is on – the administrator finds it in Settings → General
        }
        $current = [];
        foreach (['site_name', 'site_description', 'footer_text', 'logo', 'favicon', 'home_page', 'news_slug', 'news_title', 'social_facebook', 'social_instagram', 'social_x', 'social_youtube', 'social_linkedin', 'news_per_page',
            'share_image', 'company_name', 'company_type', 'company_id', 'company_vat_id', 'company_register', 'company_representative', 'company_street', 'company_city', 'company_postcode', 'company_country', 'company_phone', 'company_email', 'company_hours', 'company_map', 'company_gps', 'dark_mode', 'theme_switcher',
            'indexing', 'schema_org', 'llms_txt', 'markdown_news', 'indexnow', 'ai_crawlers', 'cookies_mode', 'cookies_log', 'stats', 'security_contact'] as $key) {
            $current[$key] = $siteSettings->get($key);
        }
        $current['stats'] = \Kaleta\Front\Stats::enabled($siteSettings) ? '1' : '0'; // the Statistics feature (3.2)
        $current += ['extensions' => \Kaleta\Core\Extensions::enabled($siteSettings), 'additional_languages' => \Kaleta\Core\Language::additional($siteSettings),
            'claude_instructions' => $siteSettings->get('claude_instructions'),
            'screen' => \Kaleta\Front\Screen::settings($siteSettings)]; // on, seconds, collections, news, hours, clock – never the secret address

        // the logo and the icon in the draft look (3.12): what visitors see is still the published value in nastaveni
        $draft = $inLookDraft !== [] ? ['look_draft' => \Kaleta\Core\Language::runWith('en', fn (): array => \Kaleta\Core\Look::summary($db, $siteSettings), 'admin-'),
            'stav' => implode(', ', $inLookDraft) . ' in the draft look – visitors see it after publish_look'] : [];

        return ['ulozeno' => $stored ?: new \stdClass(), 'chyby' => $errors ?: new \stdClass(), 'nastaveni' => $current] + $draft;
    }

    /** list_redirects and save_redirect (seznam_presmerovani, uloz_presmerovani) */
    private function toolListRedirects(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $adminOnly = function () use ($auth): void {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Tento nástroj smí použít jen správce webu.');
            }
        };

        if (!\Kaleta\Core\Extensions::isEnabled($siteSettings, 'presmerovani')) {
            throw new \DomainException('Rozšíření Přesměrování je vypnuté (Rozšíření v administraci).');
        }
        if (!$auth->hasModule('redirects')) {
            throw new \DomainException('Přesměrování smí spravovat jen role se sekcí Přesměrování.');
        }
        if ($name === 'uloz_presmerovani') {
            $adminOnly();
            $z = trim((string) parse_url((string) ($a['z'] ?? ''), PHP_URL_PATH), '/ ');
            $commandName = trim((string) ($a['na'] ?? ''));
            if ($z === '' || !preg_match('#^[A-Za-z0-9/._~%-]{1,250}$#D', $z)) {
                throw new \InvalidArgumentException('Stará cesta musí být cesta na tomto webu, např. /stara-stranka.');
            }
            if (!empty($a['smazat'])) {
                $db->delete('presmerovani', ['z_adresy' => $z]);
            } else {
                if (!preg_match('#^https?://[^\s]{3,240}$#iD', $commandName) && !preg_match('#^/?[^\s:]{0,250}$#D', $commandName)) {
                    throw new \InvalidArgumentException('Nová adresa musí být cesta (/nova) nebo https://… adresa.');
                }
                $commandName = preg_match('#^https?://#i', $commandName) ? $commandName : trim($commandName, '/');
                if (($twin = \Kaleta\Admin\Modules\Redirects::twin($db, $z)) !== null) { // N319-2: never retargets the other row
                    throw new \InvalidArgumentException(sprintf(\Kaleta\Admin\Modules\Redirects::TWIN, '/' . $twin['z_adresy']) . ' Call save_redirect with "from": "/' . $twin['z_adresy'] . '".');
                }
                \Kaleta\Admin\Modules\Redirects::add($db, $z, $commandName);
                $db->run('UPDATE {presmerovani} SET typ = ? WHERE z_adresy = ?', [(int) ($a['typ'] ?? 301) === 302 ? 302 : 301, $z]);
                $db->delete('nenalezeno', ['cesta' => $z]);
            }
            \Kaleta\Core\PageCache::clear();
        }

        // every missing address carries the page the visitor most likely meant (2.14, Core\RedirectMatcher)
        $pending = \Kaleta\Core\NotFound::pending($this->app, 60, 30);
        $suggestions = \Kaleta\Core\RedirectMatcher::suggestions($this->app, $pending);

        return ['presmerovani' => array_map(fn (array $r): array => $r + ['auto_score' => $r['auto_score'] !== null ? (int) $r['auto_score'] : null], $db->all('SELECT z_adresy AS z, na_adresu AS na, typ, pocet, auto_score FROM {presmerovani} ORDER BY z_adresy LIMIT 500')),
            'nenalezeno' => array_map(fn (array $n): array => $n + ['suggestion' => isset($suggestions[$n['cesta']]) ? '/' . $suggestions[$n['cesta']]['to'] : null, 'score' => $suggestions[$n['cesta']]['score'] ?? null], $pending),
            'auto' => ['on' => $siteSettings->bool('redirect_auto'), 'threshold' => \Kaleta\Core\RedirectMatcher::threshold($siteSettings)]];
    }

    /** save_redirect: the same as list_redirects */
    private function toolSaveRedirect(string $name, array $a): mixed
    {
        return $this->toolListRedirects($name, $a);
    }

    /**
     * save_redirects (3.6): many redirects in one call, exact ones and patterns, every row checked on its own
     * (Core\RedirectRules – the same as the CSV import in the admin); dry_run only says what would happen.
     */
    private function toolSaveRedirects(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() || !$auth->hasModule('redirects')) {
            throw new \DomainException('Redirects are managed by administrators.');
        }
        $rows = is_array($a['redirects'] ?? null) ? array_values($a['redirects']) : [];
        if ($rows === [] || count($rows) > \Kaleta\Core\RedirectRules::MAX_BATCH) {
            throw new \InvalidArgumentException('Send 1–' . \Kaleta\Core\RedirectRules::MAX_BATCH . ' redirects: [{"from":"/old","to":"/new","code":301}].');
        }
        $text = fn (mixed $v): string => is_string($v) ? $v : (is_int($v) ? (string) $v : '');
        $input = array_map(fn (mixed $r): array => is_array($r)
            ? ['from' => $text($r['from'] ?? null), 'to' => $text($r['to'] ?? null), 'code' => $text($r['code'] ?? null)]
            : ['from' => '', 'to' => '', 'refused' => 'Each redirect is an object with from, to and code.'], $rows);
        $dryRun = !empty($a['dry_run']);
        $results = $dryRun ? \Kaleta\Core\RedirectRules::plan($this->app->db(), $input) : \Kaleta\Core\RedirectRules::save($this->app->db(), $input);
        if (!$dryRun) {
            \Kaleta\Core\PageCache::clear();
        }
        $counts = array_count_values(array_column($results, 'status')) + ['added' => 0, 'changed' => 0, 'unchanged' => 0, 'refused' => 0];

        return ['dry_run' => $dryRun, 'added' => $counts['added'], 'changed' => $counts['changed'], 'unchanged' => $counts['unchanged'], 'refused' => $counts['refused'],
            'results' => array_map(fn (array $r): array => array_filter($r, fn (mixed $v, string $k): bool => $k !== 'reason' || $v !== '', ARRAY_FILTER_USE_BOTH), $results)];
    }

    /** ignore_not_found */
    private function toolIgnoreNotFound(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin(), 'Addresses not found are handled by an administrator.');
        $paths = is_array($a['paths'] ?? null) ? array_values(array_filter($a['paths'], 'is_string')) : [];
        $need($paths !== [] || !empty($a['all']), 'Send paths, or all: true.');

        return ['ignored' => \Kaleta\Core\NotFound::ignore($this->app, !empty($a['all']) ? null : $paths),
            'not_found' => array_map(fn (array $n): array => ['path' => $n['cesta'], 'count' => $n['pocet'], 'last_seen' => $n['naposledy']], \Kaleta\Core\NotFound::pending($this->app, 60, 30))];
    }

    /** site_audit */
    private function toolSiteAudit(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        $need = function (bool $allowed, string $message): void {
            if (!$allowed) {
                throw new \DomainException($message);
            }
        };

        $need($auth->isAdmin() || $auth->hasModule('pages'), 'The site audit is for administrators and editors of pages.');
        // the findings in English, as Site audit in an English administration
        [$findings, $checklist, $move] = \Kaleta\Core\Language::runWith('en', function (): array {
            $audit = new \Kaleta\Core\Audit($this->app);

            return [$audit->run(), $audit->handoverChecklist(), \Kaleta\Core\MoveStatus::summary($this->app)];
        }, 'admin-');
        $kind = is_string($a['kind'] ?? null) ? $a['kind'] : '';
        if ($kind !== '') {
            $findings = array_values(array_filter($findings, fn (array $f): bool => $f['kind'] === $kind));
        }
        // 3.44 (N128): a frequent 404 is an address any visitor can request – its finding marks where it was and its target
        $findings = array_map(fn (array $f): array => $f['kind'] === 'not_found' ? $f + \Kaleta\Mcp\Untrusted::mark(['where', 'target']) : $f, $findings);
        $counts = array_count_values(array_column($findings, 'kind'));
        $out = ['total' => count($findings), 'by_kind' => $counts ?: new \stdClass(), 'findings' => $findings];
        if ($kind === '' || $kind === 'handover') {
            // 3.23 (C17, additive): the hand-over checklist with its progress – every row, done or not, and where it is set
            $out['handover_checklist'] = ['done' => $checklist['done'], 'total' => $checklist['total'], 'items' => array_map(fn (array $i): array => ['key' => $i['key'], 'label' => $i['label'], 'done' => $i['done'], 'edit' => $i['edit']]
                + ($i['for_client'] ? ['for_client' => true] : []), $checklist['items'])];
            if ($move !== null) {
                // 3.33 (M7, additive): where a move to Kaleta stands – counts, the newest migration report and the rows with their links
                $out['move'] = array_diff_key($move, ['rows' => 1]) + ['rows' => array_map(fn (array $r): array => ['key' => $r['key'], 'label' => $r['label'], 'done' => $r['done'],
                    'edit' => $this->app->request->origin() . $this->app->url($r['edit'])], $move['rows'])];
            }
        }

        return $out;
    }

    /** get_stats (2.3): the same report as the Statistics screen */
    private function toolGetStats(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() && !$auth->hasModule('stats')) {
            throw new \DomainException('Statistics are for administrators and users with access to Statistics.');
        }

        // 3.29 (additive): visits and page views are those a visitor's browser confirmed (Front\Stats::hit); confirmed_since is
        // the day that started when older numbers exist – those include some bots and the team's own visits
        $since = \Kaleta\Front\Stats::confirmedSince($this->app->settings(), $this->app->db());
        $report = \Kaleta\Core\Report::build($this->app->db(), (int) ($a['days'] ?? 30));
        // 3.29.1 (additive): the period reaches across the switch (Front\Stats::spansSwitch) – say so, so a drop is not reported as one
        $first = (string) ($report['days'][0]['day'] ?? '');
        $last = (string) ($report['days'][count($report['days']) - 1]['day'] ?? '');

        return $report + ['statistics_on' => \Kaleta\Front\Stats::enabled($this->app->settings()), 'counting' => 'browser_confirmed']
            + \Kaleta\Mcp\Untrusted::mark(['landing_pages', 'campaigns', 'referrers', 'referrers_of_enquiries']) // 3.35 (N105): addresses and campaign names come from visitors' links
            + ($since !== null ? ['confirmed_since' => $since] : [])
            + (\Kaleta\Front\Stats::spansSwitch($since, $first, $last) ? ['counting_note' => 'Counting changed on ' . $since . ': from that day only visits confirmed by a visitor\'s browser count, the days before include some bots, link previews and the team\'s own visits. Fewer visits after it are fewer bots, not fewer people – do not report it as a decline.'] : []);
    }

    /** list_changes (2.2): the change log, people and Claude told apart */
    private function toolListChanges(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The change log is for administrators.');
        }
        $conditions = ['1 = 1'];
        $params = [];
        if (in_array($a['by'] ?? '', ['people', 'claude'], true)) {
            $conditions[] = $a['by'] === 'claude' ? "via <> ''" : "via = ''";
        }
        if (is_string($a['since'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $a['since'])) {
            $conditions[] = 'cas >= ?';
            $params[] = $a['since'] . ' 00:00:00';
        }
        $limit = max(1, min(200, (int) ($a['limit'] ?? 50)));

        return ['changes' => array_map(fn (array $r): array => ['when' => substr((string) $r['cas'], 0, 16), 'who' => $r['jmeno'], 'claude_connection' => $r['via'] !== '' ? $r['via'] : null,
            'where' => $r['modul'], 'action' => $r['akce'], 'detail' => $r['popis']] + ($r['duvod'] !== '' ? ['reason' => $r['duvod']] : []),
            $this->app->db()->all('SELECT cas, jmeno, via, modul, akce, popis, duvod FROM {protokol} WHERE ' . implode(' AND ', $conditions) . ' ORDER BY idp DESC LIMIT ' . $limit, $params))];
    }

    /** list_agent_sessions (2.17, Core\AgentJournal) */
    private function toolListAgentSessions(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Claude sessions are for administrators.');
        }

        return ['sessions' => array_map(fn (array $r): array => ['id' => (int) $r['id'], 'connection' => $r['connection'], 'started' => substr((string) $r['started_at'], 0, 16),
            'last' => substr((string) $r['last_at'], 0, 16), 'rows_changed' => (int) $r['rows_changed'], 'untracked_writes' => (int) $r['rows_untracked'], 'tools' => (string) ($r['tools'] ?? ''),
            'undone' => $r['undone_at'] !== null ? substr((string) $r['undone_at'], 0, 16) : null], \Kaleta\Core\AgentJournal::sessions($this->app->db(), max(1, min(100, (int) ($a['limit'] ?? 20)))))];
    }

    /** undo_agent_session (2.17) */
    private function toolUndoAgentSession(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('Only an administrator can undo a Claude session.');
        }
        if (($a['confirm'] ?? false) !== true) {
            throw new \InvalidArgumentException('Undoing needs confirm=true – only when the user asked for it.');
        }

        return \Kaleta\Core\AgentJournal::undo($this->app, (int) ($a['id'] ?? 0), ($a['force'] ?? false) === true)
            + ['note' => 'Tell the user what was put back, and list any rows left because they changed since (conflicts) and writes undo could not follow (untracked).'];
    }

    /** processing_record (2.14, Core\Privacy) */
    private function toolProcessingRecord(string $name, array $a): mixed
    {
        if (!$this->app->auth()->isAdmin()) {
            throw new \DomainException('The record of processing is for administrators – it describes the whole site.');
        }
        $sections = \Kaleta\Core\Privacy::processingRecord($this->app);

        return ['markdown' => \Kaleta\Core\Privacy::markdown($sections, t('Record of processing')), 'sections' => count($sections),
            'retention' => \Kaleta\Core\Retention::mcpRows($this->app->settings()), // 3.48 B (additive): {key, label, value, unit, zero, editable, feature_on}
            'note' => 'A template assembled from the configuration, not legal advice: the owner reviews it, adds what the site does not know (paper files, other systems) and keeps it with their documentation. Printable in Settings → Privacy and cookies → Record of processing.'];
    }

    /** accessibility_statement (2.14, Core\Privacy) */
    private function toolAccessibilityStatement(string $name, array $a): mixed
    {
        $statement = \Kaleta\Core\Privacy::accessibilityStatement($this->app);
        $pageId = $this->app->settings()->int('accessibility_statement_page');
        $page = $pageId > 0 ? $this->app->db()->one('SELECT ids, seo_link, zobrazit FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$pageId]) : null;

        return ['title' => $statement['title'], 'status' => $statement['status'] === 'full' ? 'fully_compliant' : 'partially_compliant', 'standard' => 'EN 301 549 / WCAG 2.1 AA',
            'barriers' => $statement['findings'], 'date' => $statement['date'], 'text' => $statement['text'],
            'page' => $page !== null ? ['id' => (int) $page['ids'], 'slug' => $page['seo_link'], 'published' => (int) $page['zobrazit'] === 1] : null,
            'next' => $statement['findings'] !== [] ? 'Fix the barriers (site_audit kind accessibility), then let the administrator regenerate the draft in Settings → Privacy and cookies. A template, not legal advice.'
                : ($page === null ? 'The administrator creates the page in Settings → Privacy and cookies (a hidden draft to review and publish). A template, not legal advice.' : 'Review the page with the user before publishing it (publish_build). A template, not legal advice.')];
    }
}
