<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

use Kaleta\Admin\Modules\Media;
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

/**
 * Tools the MCP server offers to Claude. Every tool respects the permissions of the user whose token Claude signs in with:
 * an author works only with their own news and does not publish, an editor with all content, an administrator also with
 * the site layouts.
 *
 * An error meant for Claude (bad input, missing permission) is reported with an InvalidArgumentException / DomainException.
 *
 * Deliberately without a tool: the whistleblowing channel (2.14, Core\Whistleblowing). No tool reads, lists or answers
 * its cases – a report is for the chosen readers only, never for a connected assistant. site_info says whether it is on.
 */
final class Tools
{
    use Handlers\PageTools, Handlers\BuilderTools, Handlers\LookTools, Handlers\CollectionTools, Handlers\NewsTools, Handlers\MediaTools, Handlers\EnquiryAndPopupTools, Handlers\SettingsTools, Handlers\UpkeepTools, Handlers\NewsletterTools, Handlers\MigrationTools, Handlers\HealthTools, Handlers\FleetTools, Handlers\FactTools, Handlers\BlueprintTools, Handlers\NotebookTools, Handlers\RequestTools, Handlers\AgentRunTools, Handlers\BookingTools, Handlers\PendingReviewTools, Handlers\ReportTools, Handlers\WorkTools, Handlers\CommentTools, Handlers\TaskTools, Handlers\ContactTools, Handlers\DealTools, Handlers\ApplicationTools, Handlers\QuoteTools, Handlers\InternalFileTools;

    /** The largest file uploaded via MCP (base64 in one tool call). */
    private const int MAX_UPLOAD = 12 * 1024 * 1024;

    /** What upload_file says about a file from a connection limited to drafts (3.15, N10). */
    public const string STAGED_NOTE = 'Staged: the file is not public yet. It shows in previews and goes public with the draft that uses it when that is published, or when a person approves it in Media (Waiting for approval). Unapproved files are deleted after 30 days.';

    /**
     * Records the last import step created (pages, news items, items, categories, redirects, images – 3.7, N37-26): Mcp\Server
     * counts them against the hourly change limit once the step has run (Core\Guardrails::COUNTED_AFTER).
     */
    public int $recordsCreated = 0;

    public function __construct(private readonly App $app)
    {
    }

    /** @return list<array<string, mixed>> tool definitions for tools/list: the tools of switched-off extensions are left out */
    public function listAll(): array
    {
        return [...array_values(array_filter(self::definitions(), fn (array $n): bool => ($extension = Catalog::extension($n['name'])) === ''
            || \Kaleta\Core\Extensions::isEnabled($this->app->settings(), $extension))), ...\Kaleta\Extension\Registry::get()->toolDefinitions()];
    }

    /**
     * The stored names of all tools in the order tools/list gives them (3.49 P0: the definitions sit next to their handlers,
     * Handlers\<Trait>::<trait>Definitions(); unit 4360 checks that this list and the traits hold the same names once).
     */
    private const array ORDER = [
        'info_o_webu', 'seznam_stranek', 'nacti_stranku', 'vytvor_stranku', 'uprav_stranku', 'nacti_menu', 'uloz_menu', 'stavba_schema',
        'stavba_nacti', 'stavba_uprav', 'seznam_trid', 'uloz_tridy', 'stavba_z_html', 'stavba_uloz', 'vloz_sekci', 'publikuj_stavbu', 'stavba_verze',
        'obnov_verzi', 'zahod_koncept', 'seznam_casti', 'uloz_variantu', 'uprav_design_system', 'seznam_popupu', 'uloz_popup', 'seznam_kolekci',
        'vytvor_kolekci', 'uprav_kolekci', 'seznam_polozek_kolekce', 'uloz_polozku_kolekce', 'seznam_novinek', 'nacti_novinku', 'vytvor_novinku',
        'uprav_novinku', 'seznam_kategorii', 'vytvor_kategorii', 'seznam_medii', 'nahraj_soubor', 'importuj_web', 'nahled_odkaz', 'uprav_nastaveni',
        'seznam_poptavek', 'seznam_presmerovani', 'uloz_presmerovani', 'list_trash', 'restore_from_trash', 'trash_news', 'delete_collection_item',
        'delete_collection', 'update_category', 'delete_category', 'delete_popup', 'list_components', 'save_component', 'delete_component',
        'save_section', 'delete_section', 'update_media', 'delete_media', 'update_enquiry', 'request_testimonial', 'reply_to_enquiry',
        'triage_enquiries', 'find_personal_data', 'erase_personal_data', 'delete_enquiry', 'apply_part_template', 'publish_look', 'discard_look',
        'site_audit', 'get_stats', 'list_changes', 'list_agent_sessions', 'undo_agent_session', 'list_broken_links', 'suggest_internal_links',
        'list_draft_comments', 'resolve_draft_comment', 'ignore_not_found', 'save_redirects', 'save_collection_items', 'list_item_versions',
        'restore_item_version', 'get_email_signature', 'list_look_versions', 'restore_look_version', 'list_newsletters', 'draft_newsletter',
        'send_test_newsletter', 'send_newsletter', 'delete_newsletter', 'migration_report', 'read_old_page', 'layout_queue', 'list_layout_queue',
        'import_enquiries', 'import_wordpress', 'get_health', 'list_events', 'list_facts', 'save_fact', 'delete_fact', 'find_claims', 'list_hours',
        'save_hours_exception', 'list_bookings', 'booking_availability', 'save_booking_service', 'save_booking_staff', 'cancel_booking',
        'confirm_booking', 'decline_booking', 'propose_booking_times', 'processing_record', 'accessibility_statement', 'get_social_drafts',
        'update_social_draft', 'list_connectors', 'get_blueprint', 'apply_blueprint', 'remove_blueprint', 'export_blueprint',
        'list_collection_presets', 'list_notice_log', 'list_collection_categories', 'save_collection_category', 'delete_collection_category',
        'delete_hours_exception', 'list_sites', 'get_site', 'fleet_inbox', 'fleet_report', 'fleet_today', 'fleet_health', 'fleet_plan',
        'fleet_inventory', 'fleet_calendar', 'list_media_without_alt', 'translation_status', 'read_notebook', 'write_notebook',
        'delete_notebook_entry', 'list_pending_review', 'list_monthly_reports', 'get_monthly_report', 'list_requests', 'update_request',
        'get_due_agent_runs', 'report_agent_run', 'my_work', 'assign', 'list_comments', 'add_comment', 'list_colleagues', 'team_summary',
        'list_tasks', 'save_task', 'complete_task', 'list_contacts', 'get_contact', 'save_contact', 'log_activity', 'merge_contacts', 'list_deals',
        'save_deal', 'move_deal', 'pipeline_summary', 'list_applications', 'get_application', 'update_application', 'reply_to_application',
        'list_prices', 'save_price', 'send_quote', 'list_quotes', 'get_quote', 'save_quote', 'mark_quote', 'list_internal_files',
        'get_internal_file', 'save_internal_file', 'smaz_stranku',
    ];

    /**
     * Definitions of all tools (Czech names of the older tools, parameter types); Translator turns them into the English
     * interface. Nothing here depends on the site, so the contract test (tools/contracts/mcp-tools.json) reads them too.
     * 3.49 P0: each trait in Mcp/Handlers holds the rows of its own tools; tools/contracts/mcp-definitions.json records them.
     *
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        $all = [
            ...self::pageToolsDefinitions(),
            ...self::builderToolsDefinitions(),
            ...self::lookToolsDefinitions(),
            ...self::collectionToolsDefinitions(),
            ...self::newsToolsDefinitions(),
            ...self::mediaToolsDefinitions(),
            ...self::enquiryAndPopupToolsDefinitions(),
            ...self::settingsToolsDefinitions(),
            ...self::upkeepToolsDefinitions(),
            ...self::newsletterToolsDefinitions(),
            ...self::migrationToolsDefinitions(),
            ...self::healthToolsDefinitions(),
            ...self::fleetToolsDefinitions(),
            ...self::factToolsDefinitions(),
            ...self::blueprintToolsDefinitions(),
            ...self::notebookToolsDefinitions(),
            ...self::requestToolsDefinitions(),
            ...self::agentRunToolsDefinitions(),
            ...self::bookingToolsDefinitions(),
            ...self::pendingReviewToolsDefinitions(),
            ...self::reportToolsDefinitions(),
            ...self::workToolsDefinitions(),
            ...self::commentToolsDefinitions(),
            ...self::taskToolsDefinitions(),
            ...self::contactToolsDefinitions(),
            ...self::dealToolsDefinitions(),
            ...self::applicationToolsDefinitions(),
            ...self::quoteToolsDefinitions(),
            ...self::internalFileToolsDefinitions(),
        ];

        return array_map(fn (string $name): array => ['name' => $name, 'description' => $all[$name][0], 'inputSchema' => $all[$name][1]], self::ORDER);
    }

    /** @return list<string> Czech names of all tools (including disabled extensions); the tools added in English have only that */
    public function names(): array
    {
        return [...array_map(fn (string $en): string => Translator::czech($en) ?? $en, array_keys(Catalog::TOOLS)),
            ...array_column(\Kaleta\Extension\Registry::get()->toolDefinitions(), 'name')];
    }

    /** Site parts by their English names (MCP) => Czech types. */
    private const array PART_NAMES = ['header' => 'hlavicka', 'footer' => 'paticka', 'news_item' => 'novinka', 'news_list' => 'vypis', 'not_found' => 'nenalezeno'];

    /**
     * MCP annotations of a tool, so a client knows what to confirm with the user: reads, writes, and writes that remove
     * something or cannot be taken back (Catalog). 3.8: the title, idempotentHint on the write tools where it holds, and
     * openWorldHint for the tools that reach outside the site – all by the English name, whichever name is given.
     *
     * @return array{title: string, readOnlyHint: bool, destructiveHint: bool, idempotentHint?: true, openWorldHint: bool}
     */
    public static function annotations(string $name): array
    {
        $english = Catalog::english($name) ?? $name;
        $write = self::isWriteTool($english);

        return ['title' => Catalog::title($english), 'readOnlyHint' => !$write, 'destructiveHint' => Catalog::access($english) === 'destructive']
            + ($write && Catalog::isIdempotent($english) ? ['idempotentHint' => true] : [])
            + ['openWorldHint' => Catalog::isOpenWorld($english)];
    }

    public static function isWriteTool(string $name): bool
    {
        return Catalog::access($name) !== 'read';
    }

    /** @param array<string, mixed> $a */
    public function call(string $name, array $a): mixed
    {
        // an add-on's tool (3.0): the connection's access and the guardrails were checked by Mcp\Server like for any tool;
        // the user's role or section like the built-in tools check theirs (3.3.2)
        $addon = \Kaleta\Extension\Registry::get()->tool($name);
        if ($addon !== null) {
            if (!\Kaleta\Extension\Api::userMay($this->app->auth(), $addon['requires'])) {
                throw new \DomainException('This add-on tool needs ' . (in_array($addon['requires'], \Kaleta\Extension\Api::TOOL_ROLES, true)
                    ? ['author' => 'a signed-in user', 'editor' => 'an editor or an administrator', 'admin' => 'an administrator'][$addon['requires']] : 'access to the ' . $addon['requires'] . ' section') . ' – this connection belongs to a user without it.');
            }

            return ($addon['handler'])($a);
        }
        $english = Catalog::english($name);
        if ($english === null || !method_exists($this, Catalog::method($english))) {
            throw new \InvalidArgumentException('Neznámý nástroj: ' . $name);
        }
        $extension = Catalog::extension($english);
        // the Newsletter tools check the extension together with the user's access (Handlers\Newsletter)
        if ($extension !== '' && $extension !== 'newsletter' && !\Kaleta\Core\Extensions::isEnabled($this->app->settings(), $extension)) {
            // the older news tools answer in Czech as before (Translator turns it into English for English names)
            throw new \DomainException(Translator::czech($english) !== $english ? 'Novinky jsou na tomto webu vypnuté (Funkce).' : 'This tool needs a feature that is switched off on this site (Features).');
        }

        return $this->{Catalog::method($english)}($name, $a);
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function saveNewsItem(?array $previous, array $a): array
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        if ($previous !== null && $previous['visible'] && !$auth->canPublish()) {
            throw new \DomainException('Vydanou novinku může upravit jen editor nebo správce.');
        }
        $data = [];
        foreach (['titulek' => 255, 'uvod' => 0, 'text' => 0, 'faq' => 0, 'seo_titulek' => 255, 'seo_popis' => 320, 'obrazek' => 255, 'obrazek_popis' => 300] as $field => $max) {
            if (array_key_exists($field, $a)) {
                $data[$field] = $max > 0 ? mb_substr((string) $a[$field], 0, $max) : (string) $a[$field];
            }
        }
        if (array_key_exists('canonical_url', $a)) { // 3.22 (C13): https://… or a path on the site, '' = the news item itself
            $data['canonical_url'] = \Kaleta\Core\CanonicalUrl::clean($a['canonical_url']) ?? throw new \InvalidArgumentException(\Kaleta\Core\CanonicalUrl::REFUSAL);
        }
        foreach (['uvod', 'text'] as $field) {
            if (isset($data[$field])) {
                // over a limit of Core\HtmlLimits the call is refused: nothing is saved, the error names the parameter and the limit
                $data[$field] = \Kaleta\Core\Html::forUserOrFail($data[$field], $this->app->auth(), $field === 'uvod' ? 'intro' : 'text');
            }
        }
        if (array_key_exists('kategorie', $a)) {
            $data['tema'] = $this->category((string) $a['kategorie']);
            // the news item takes over the category's language version – just like when saved in the administration
            $data['jazyk'] = (string) $db->value('SELECT jazyk FROM {kategorie} WHERE idt = ?', [$data['tema']]);
            if ($previous !== null && $data['jazyk'] !== $previous['jazyk'] && \Kaleta\Core\Slug::taken($db, 'novinky', (string) $previous['seo_link'], $data['jazyk'], (int) $previous['idc'])) {
                throw new \InvalidArgumentException('A news item with the address „' . $previous['seo_link'] . '“ already exists in that language version.');
            }
        }
        if (!empty($a['datum'])) {
            $ts = strtotime((string) $a['datum']);
            if ($ts === false) {
                throw new \InvalidArgumentException('Datum nemá platný tvar (RRRR-MM-DD HH:MM).');
            }
            $data['datum'] = date('Y-m-d H:i:s', $ts);
        }
        if (array_key_exists('vydat', $a)) {
            if ($a['vydat'] && !$auth->canPublish()) {
                throw new \DomainException('Uživatel nemá právo vydávat – novinku lze uložit jen jako koncept.');
            }
            $data['visible'] = (int) (bool) $a['vydat'];
        }
        $data += self::validityDates($a);
        if (($data['titulek'] ?? $previous['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Novinka musí mít titulek.');
        }
        $data['zmeneno'] = date('Y-m-d H:i:s');

        if ($previous === null) {
            if (!isset($data['tema'])) {
                throw new \InvalidArgumentException('Chybí kategorie.');
            }
            $data += ['uvod' => '', 'text' => '', 'autor' => $auth->id(), 'datum' => date('Y-m-d H:i:s'), 'visible' => 0,
                'seo_link' => $this->availableSlug('novinky', slugify($data['titulek'], 150), (string) ($data['jazyk'] ?? ''))];
            $id = $db->insert('novinky', $data);
            \Kaleta\Core\Slug::fromId($db, 'novinky', $id); // a title with nothing to transliterate: news-<id>, not n-a
        } else {
            $id = (int) $previous['idc'];
            \Kaleta\Admin\Modules\News::version($db, $previous, $auth->id()); // history is pruned the same way as in the administration
            $db->update('novinky', $data, ['idc' => $id]);
        }
        \Kaleta\Core\Search::index($db, $id);
        if (array_key_exists('stitky', $a)) {
            \Kaleta\Admin\Modules\News::tags($db, $id, (string) $a['stitky']);
        }
        $saved = $db->one('SELECT * FROM {novinky} WHERE idc = ?', [$id]);
        Media::recordUsage($db, $id, $saved['obrazek'], $saved['uvod'], $saved['text']);

        return ['id' => $id, 'stav' => !$saved['visible'] ? 'koncept' : (strtotime($saved['datum']) > time() ? 'naplánováno' : 'vydáno')]
            + self::validityOutput($saved) + ['nahled' => $this->app->request->origin() . $this->app->url('novinky/' . $saved['seo_link'] . '?preview=1'),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?module=news&action=edit&id=' . $id)];
    }

    /**
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function savePage(?array $previous, array $a): array
    {
        $db = $this->app->db();
        $data = [];
        foreach (['titulek' => 200, 'text' => 0, 'popis' => 300, 'seo_titulek' => 200, 'obrazek' => 255] as $field => $max) {
            if (array_key_exists($field, $a)) {
                $data[$field] = $max > 0 ? mb_substr((string) $a[$field], 0, $max) : (string) $a[$field];
            }
        }
        if (array_key_exists('canonical_url', $a)) { // 3.22 (C13): https://… or a path on the site, '' = the page itself
            $data['canonical_url'] = \Kaleta\Core\CanonicalUrl::clean($a['canonical_url']) ?? throw new \InvalidArgumentException(\Kaleta\Core\CanonicalUrl::REFUSAL);
        }
        if (array_key_exists('kod_hlavicky', $a)) {
            // code that runs on the site is never written through MCP (2.5.1): the parameter stays in the interface (public contract),
            // the administrator sets the code in the page settings in the administration; removing it is harmless, so an empty value clears it
            if (trim((string) $a['kod_hlavicky']) !== '') {
                throw new \DomainException('Code in the head of a page is set only in the administration (page settings), not through a Claude connection.');
            }
            $data['kod_hlavicky'] = null;
        }
        foreach (['uvod', 'text'] as $field) {
            if (isset($data[$field])) {
                // over a limit of Core\HtmlLimits the call is refused: nothing is saved, the error names the parameter and the limit
                $data[$field] = \Kaleta\Core\Html::forUserOrFail($data[$field], $this->app->auth(), $field);
            }
        }
        foreach (['v_menu', 'zobrazit', 'noindex'] as $field) {
            if (array_key_exists($field, $a)) {
                $data[$field] = (int) (bool) $a[$field];
            }
        }
        if (array_key_exists('poradi', $a)) {
            $data['poradi'] = max(0, min(65535, (int) $a['poradi']));
        }
        if (!$this->app->auth()->canPublish()) {
            // without the publish permission: do not change a published page, keep a new one hidden (same as in the administration)
            if ($previous !== null && $previous['zobrazit']) {
                throw new \DomainException('Zveřejněnou stránku smí upravit jen editor nebo správce.');
            }
            unset($data['zobrazit']);
        }
        if (($data['titulek'] ?? $previous['titulek'] ?? '') === '') {
            throw new \InvalidArgumentException('Stránka musí mít název.');
        }
        $siteSettings = $this->app->settings();
        if (array_key_exists('jazyk', $a)) {
            $data['jazyk'] = Language::column($siteSettings, (string) $a['jazyk']);
            if ($data['jazyk'] === '' && !in_array((string) $a['jazyk'], ['', Language::defaults($siteSettings)], true)) {
                throw new \InvalidArgumentException('Jazyková verze „' . $a['jazyk'] . '“ není zapnutá (Rozšíření → Jazykové verze, jazyky v Nastavení).');
            }
        }
        $language = $data['jazyk'] ?? (string) ($previous['jazyk'] ?? '');
        if (array_key_exists('preklad_z', $a) || array_key_exists('jazyk', $a)) {
            $data['preklad_z'] = $language === '' ? null
                : ($db->value("SELECT ids FROM {stranky} WHERE ids = ? AND jazyk = '' AND ids <> ? AND smazano IS NULL", [(int) ($a['preklad_z'] ?? $previous['preklad_z'] ?? 0), (int) ($previous['ids'] ?? 0)]) ?: null);
        }
        // parent page: the same language, not the page itself nor its subpage (that would create a loop); the slug is
        // /parent/page
        $parent = null;
        $parentChanged = array_key_exists('nadrazena', $a) || array_key_exists('jazyk', $a);
        if ($parentChanged) {
            $parentId = (int) ($a['nadrazena'] ?? $previous['nadrazena'] ?? 0);
            $parent = $parentId > 0 ? $db->one('SELECT ids, seo_link FROM {stranky} WHERE ids = ? AND ids <> ? AND jazyk = ? AND smazano IS NULL', [$parentId, (int) ($previous['ids'] ?? 0), $language]) : null;
            if ($parentId > 0 && ($parent === null || ($previous !== null && str_starts_with($parent['seo_link'] . '/', $previous['seo_link'] . '/')))) {
                throw new \InvalidArgumentException('Nadřazená stránka musí existovat, mít stejný jazyk a nesmí to být tahle stránka ani její podstránka.');
            }
            $data['nadrazena'] = $parent !== null ? (int) $parent['ids'] : null;
        } elseif ($previous !== null && $previous['nadrazena'] !== null) {
            // 3.33 review M1: a parent in the trash is no parent – the address is never built from it
            $parent = \Kaleta\Core\ImportPath::liveParent($db, (int) $previous['nadrazena']);
        }
        if (array_key_exists('zverejnit_od', $a)) {
            $from = strtotime(str_replace('T', ' ', (string) $a['zverejnit_od'])) ?: null;
            if (!$this->app->auth()->canPublish()) {
                throw new \DomainException('Zveřejnění naplánuje jen editor nebo správce.');
            }
            $data['zverejnit_od'] = $from !== null && $from > time() && !($data['zobrazit'] ?? $previous['zobrazit'] ?? 0) ? date('Y-m-d H:i:s', $from) : null;
            if ($from !== null && $from <= time()) {
                throw new \InvalidArgumentException('Čas zveřejnění už proběhl – zadej budoucí čas, nebo stránku zveřejni parametrem zobrazit.');
            }
        }
        if (!empty($data['zobrazit'])) {
            $data['zverejnit_od'] = null; // a published page no longer waits for the schedule
        } elseif (($data['zverejnit_od'] ?? null) !== null) {
            $data['show_on_publish'] = 0; // a publish date takes over from "show with the first content" (3.5, N35-1)
        }
        $data += self::validityDates($a);
        // a page with nothing to show yet is not made visible (3.5, Pages::visibility): its first published build or text
        // shows it – the same end state as before, without an empty page on the site and in the navigation in between
        $stored = $previous !== null ? $db->one('SELECT stavba IS NOT NULL AS built, show_on_publish FROM {stranky} WHERE ids = ?', [(int) $previous['ids']]) : null;
        // the kept wish shows the page only when a publisher's own call gives it content and no publish date waits; text from a
        // connection that may not publish never goes live by itself, so it clears the wish like the admin form does (N35-1, N35-2)
        $schedule = array_key_exists('zverejnit_od', $data) ? $data['zverejnit_od'] : ($previous['zverejnit_od'] ?? null); // null = cancelled now
        $waits = !empty($stored['show_on_publish']) && !isset($data['show_on_publish']) && $schedule === null;
        if (!$this->app->auth()->canPublish()) {
            if ($waits && array_key_exists('text', $data)) {
                $data['show_on_publish'] = 0;
            }
        } elseif (array_key_exists('zobrazit', $data) || ($waits && array_key_exists('text', $data))) {
            $data = Pages::visibility((bool) ($data['zobrazit'] ?? true), (bool) ($previous['zobrazit'] ?? false),
                Pages::hasContent(['stavba' => !empty($stored['built']) ? '1' : null, 'text' => $data['text'] ?? $previous['text'] ?? ''])) + $data;
        }
        if (array_key_exists('adresa', $a) || $previous === null || $parentChanged) {
            $base = ($a['adresa'] ?? '') !== '' ? basename(str_replace('\\', '/', (string) $a['adresa']))
                : ($previous !== null ? basename((string) $previous['seo_link']) : $data['titulek']);
            // 3.33 (M3): an imported nested page whose old parent never came over keeps its directory – unless the call removes a parent
            $prefix = $parent !== null ? $parent['seo_link'] . '/' : ($parentChanged ? '' : \Kaleta\Core\ImportPath::keptPrefix($previous, $db));
            $seo = $prefix . slugify($base, max(20, 118 - strlen($prefix)));
            if ($parent === null && $seo !== ($previous['seo_link'] ?? null) && Pages::slugReserved($seo, $db)) {
                throw new \InvalidArgumentException('Adresu „' . $seo . '“ používá systém, zvol jinou.');
            }
            if (\Kaleta\Core\Slug::taken($db, 'stranky', $seo, $language, (int) ($previous['ids'] ?? 0))) {
                throw new \InvalidArgumentException('Stránka s adresou „' . $seo . '“ už existuje.');
            }
            $data['seo_link'] = $seo;
        }
        $data += Pages::edited($this->app->auth()->id()); // when and who (3.20, C11)
        if ($previous === null) {
            if (!empty($a['kopie_stavby'])) {
                // a translation starts with a copy of the original's build (the draft, otherwise the published one) – the
                // texts are then changed by stavba_uprav by id
                $original = ($data['preklad_z'] ?? null) !== null ? $db->one('SELECT stavba, stavba_koncept FROM {stranky} WHERE ids = ?', [$data['preklad_z']]) : null;
                if ($original === null) {
                    throw new \InvalidArgumentException('Kopie stavby potřebuje preklad_z – ID stránky ve výchozím jazyce, a jazyk překladu.');
                }
                $data['stavba_koncept'] = $original['stavba_koncept'] ?? $original['stavba'];
            }
            $id = $db->insert('stranky', $data + ['text' => '', 'zobrazit' => 0, 'v_menu' => 0]);
            \Kaleta\Core\Slug::fromId($db, 'stranky', $id); // a title with nothing to transliterate: page-<id>, not n-a
            if (!empty($data['v_menu'])) {
                \Kaleta\Core\Menu::setPage($db, $id, $language, true);
            }
        } else {
            $id = (int) $previous['ids'];
            if (($data['titulek'] ?? $previous['titulek']) !== $previous['titulek'] || (string) ($data['text'] ?? $previous['text']) !== (string) $previous['text']) {
                Pages::version($db, $id, $this->app->auth()->id(), $previous['titulek'], (string) $previous['text']); // the previous version into the history
            }
            $db->update('stranky', $data, ['ids' => $id]);
            if (isset($data['seo_link']) && $data['seo_link'] !== $previous['seo_link']) {
                Pages::move($db, $previous['seo_link'], $data['seo_link'], (bool) $previous['zobrazit'], $language);
            }
        }
        $saved = $this->page($id);
        $waiting = (bool) $db->value('SELECT show_on_publish FROM {stranky} WHERE ids = ?', [$id]);

        return ['id' => $id, 'stav' => $saved['zobrazit'] ? 'zveřejněná' : ($saved['zverejnit_od'] !== null ? 'skrytá, zveřejní se ' . substr((string) $saved['zverejnit_od'], 0, 16)
            : ($waiting ? 'skrytá, zveřejní se s obsahem' : 'skrytá'))]
            + self::validityOutput($saved) + ['adresa' => $this->app->request->origin() . $this->app->url(($saved['jazyk'] !== '' ? $saved['jazyk'] . '/' : '') . $saved['seo_link']),
            'uprava_v_administraci' => $this->app->request->origin() . $this->app->url('admin.php?module=pages&action=edit&id=' . $id)];
    }

    /** @return array<string, mixed> */
    private function page(int $id): array
    {
        $page = $this->app->db()->one('SELECT ids, titulek, seo_link, popis, seo_titulek, obrazek, noindex, canonical_url, text, zobrazit, zverejnit_od, v_menu, poradi, jazyk, preklad_z, nadrazena, valid_until, review_by FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$id]);
        if ($page === null) {
            throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');
        }

        return $page;
    }

    /**
     * True until and review by (2.10) from the parameters of a save tool: only what was sent changes; an empty string
     * clears the date, anything else must be a YYYY-MM-DD date.
     *
     * @param array<string, mixed> $a
     * @return array{valid_until?: ?string, review_by?: ?string}
     */
    private static function validityDates(array $a): array
    {
        $out = [];
        foreach (['valid_until', 'review_by'] as $key) {
            if (!array_key_exists($key, $a)) {
                continue;
            }
            $value = is_string($a[$key]) ? trim($a[$key]) : $a[$key];
            if ($value !== '' && $value !== null && !\Kaleta\Core\Validity::isDate($value)) {
                throw new \InvalidArgumentException($key . ' must be a date YYYY-MM-DD, or an empty string to clear it.');
            }
            $out[$key] = \Kaleta\Core\Validity::date($value);
        }

        return $out;
    }

    /** The two dates in a tool result – only when set (the output leaves default values out). @param array<string, mixed> $row */
    private static function validityOutput(array $row): array
    {
        return array_filter(['valid_until' => $row['valid_until'] ?? null, 'review_by' => $row['review_by'] ?? null], fn (mixed $v): bool => $v !== null);
    }

    /** Structured data of a collection from Claude (English or Czech keys) as stored in ka_kolekce.schema_org; null = none. */
    private static function collectionSchema(mixed $input, array $fields): ?string
    {
        $input = is_array($input) ? ['typ' => $input['type'] ?? $input['typ'] ?? '', 'pole' => $input['fields'] ?? $input['pole'] ?? [], 'mena' => $input['currency'] ?? $input['mena'] ?? ''] : null;
        $clean = \Kaleta\Builder\CollectionSchema::sanitize($input, $fields);
        if ($input !== null && $input['typ'] !== '' && $clean === null) {
            throw new \InvalidArgumentException('Unknown structured data type. Use one of: ' . implode(', ', array_keys(\Kaleta\Builder\CollectionSchema::TYPES)) . '.');
        }

        return $clean === null ? null : (string) json_encode($clean, JSON_UNESCAPED_UNICODE);
    }

    /** An element of a build by its id, searched through the whole tree. */
    private function findElement(array $children, string $id): ?array
    {
        foreach ($children as $p) {
            if (($p['id'] ?? null) === $id) {
                return $p;
            }
            if (is_array($p['deti'] ?? null) && ($found = $this->findElement($p['deti'], $id)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * builder_schema for the English interface: the builder vocabulary in English (Mcp\Vocabulary), the section library,
     * saved sections, components, classes and design system tokens.
     *
     * @param array<string, mixed> $schema Build::schema()
     * @param array<string, mixed> $a
     */
    private function englishSchema(array $schema, array $a): array
    {
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        $only = is_array($a['prvky'] ?? null) ? array_values(array_filter($a['prvky'], 'is_string')) : [];
        $out = \Kaleta\Mcp\Vocabulary::schema($schema, $only, !empty($a['uplne']));
        if ($only !== [] && empty($a['uplne'])) {
            return $out;
        }
        $admin = fn (string $text): string => Language::runWith('en', fn (): string => t($text), 'admin-');
        $library = Language::runWith('en', fn (): array => Library::listAll(\Kaleta\Core\Extensions::enabled($siteSettings)), 'admin-'); // names and descriptions from the English source, not from the site language

        return $out + [
            'components' => array_map(fn (array $k): array => ['id' => (string) $k['idm'], 'name' => $k['nazev'], 'properties' => $k['vlastnosti']], \Kaleta\Builder\Components::all($db))
                + ['note' => 'Use: {"type":"component","content":{"component":"<id>","values":{"<key>":"value"}}}; an empty value = the default. Edit a component with the *_build tools and component: <id>.'],
            'site_parts' => ['header' => 'the header of every page', 'footer' => 'the footer of every page', 'news_item' => 'the wrapper of a news item', 'news_list' => 'the wrapper of the news list',
                'not_found' => 'the wrapper of the 404 page', 'note' => 'The elements logo, navigation, company_details and page_content belong only in site parts; a wrapper (news_item, news_list, not_found) must contain exactly one page_content element.'],
            'library' => array_column(array_map(fn (array $k): array => ['key' => $k['klic'], 'description' => $admin($k['nazev']) . ' – ' . $admin($k['popis'])], $library), 'description', 'key'),
            'saved_sections' => array_map(fn (array $r): array => ['id' => (int) $r['idx'], 'name' => $r['nazev']], $db->all('SELECT idx, nazev FROM {sekce} ORDER BY ' . $db->sortText('nazev') . ' LIMIT 200'))
                + ['note' => 'Sections saved in the builder: insert_section with saved_section: <id>.'],
            'part_templates' => array_map(fn (string $type): array => array_column(array_map(fn (array $t): array => ['key' => $t['klic'], 'text' => $admin($t['nazev']) . ' – ' . $admin($t['popis'])],
                \Kaleta\Builder\PartTemplates::forType($type, \Kaleta\Core\Extensions::enabled($siteSettings))), 'text', 'key'), self::PART_NAMES)
                + ['note' => 'apply_part_template puts one into the draft of the part; the look comes from the design system.'],
            'collection_schema' => array_map(fn (array $t): array => array_keys($t[1]), \Kaleta\Builder\CollectionSchema::TYPES)
                + ['note' => 'structured_data of create_collection / update_collection: the type and which field fills each property; name, url, description and image come from the item. An offer needs a price field and currency. FAQPage: the item name is the question, the answer field the answer. LocalBusiness (branches): geo from a location field, openingHours from a text field with one rule per line (Mo-Fr 9-17).'],
            'site_classes' => array_column($db->all('SELECT nazev FROM {tridy} ORDER BY nazev'), 'nazev'),
            'design_system' => DesignSystem::load($siteSettings) + ['presets' => array_map(fn (array $p): string => $admin($p[0]) . ' – ' . $admin($p[1]), DesignSystem::PRESETS),
                'heading_fonts' => array_keys(SiteIdentity::TITLE_FONTS), 'text_fonts' => array_keys(SiteIdentity::TEXT_FONTS),
                'dark_palette' => DesignSystem::darkColors(DesignSystem::load($siteSettings)),
                'note' => 'Keys as update_design_system takes them (barvy = colours, pismo_titulky = heading font, zaobleni = corner radius…). barvy_tmave = dark mode colours (text, pozadi, plocha; primarni and sekundarni only when set – otherwise they are derived from the light ones so they stay readable, see dark_palette; "" returns them to automatic).'],
            'css_tokens' => 'In <style> and custom CSS use var(--ka-barva-primarni|sekundarni|text|tlumeny|pozadi|plocha|linka|primarni-jemna|na-primarni) (primary, secondary, text, muted, background, surface, line, primary-soft, on-primary), var(--ka-mezera-2xs…3xl) for spacing, var(--ka-krok--1…5) for font size, var(--ka-zaobleni), var(--ka-stin-s|m|l), var(--ka-sirka). The same tokens also answer to English names (--ka-color-primary, --ka-space-m, --ka-step-2, --ka-radius, --ka-shadow-m…) for reading; to restyle a section, override the stored names above.',
        ];
    }

    /** @param array<string, mixed> $n */
    private function newsletter(array $n): array
    {
        $output = ['id' => (int) $n['id'], 'subject' => $n['subject'], 'status' => $n['status']];
        foreach (['preheader', 'intro', 'news_mode', 'news_count', 'news_ids', 'button_label', 'button_url', 'language'] as $key) {
            if (array_key_exists($key, $n)) {
                $output[$key] = match ($key) {
                    'news_count' => (int) $n[$key],
                    'news_ids' => array_map('intval', array_filter(explode(',', (string) $n[$key]))),
                    default => $n[$key],
                };
            }
        }
        if ($n['status'] === 'scheduled') {
            $output['scheduled_at'] = substr((string) $n['scheduled_at'], 0, 16);
        }
        if (in_array($n['status'], ['sending', 'sent'], true)) {
            $output += ['recipients' => (int) $n['recipients'], 'sent' => (int) $n['sent_count'], 'failed' => (int) $n['failed_count'],
                'started_at' => substr((string) $n['started_at'], 0, 16), 'finished_at' => $n['finished_at'] !== null ? substr((string) $n['finished_at'], 0, 16) : null];
        }

        return $output + ['admin' => $this->app->request->origin() . $this->app->url('admin.php?module=newsletters&action=edit&id=' . (int) $n['id'])];
    }

    private function popup(array $p, bool $withPreview = false): array
    {
        $output = ['id' => $p['idpp'], 'nazev' => $p['nazev'], 'adresa' => $p['adresa'], 'odkaz' => '#popup-' . $p['adresa'], 'typ' => $p['typ'], 'spoustec' => $p['spoustec'],
            'hodnota' => $p['hodnota'], 'cetnost' => $p['cetnost'], 'dni' => $p['dni'], 'pravidla' => $p['pravidla'], 'aktivni' => (bool) $p['aktivni'],
            'publikovano' => $p['stavba'] !== null, 'zmeny' => $p['stavba_koncept'] !== null && $p['stavba_koncept'] !== $p['stavba'], 'poradi' => $p['poradi'],
            'zobrazeni' => $p['zobrazeni'], 'zavreni' => $p['zavreni'], 'konverze' => $p['konverze']] + self::validityOutput($p)
            + ['stavitel' => $this->app->request->origin() . $this->app->url('admin.php?module=popups&action=builder&id=' . $p['idpp'])];
        if ($withPreview) {
            $output['nahled'] = $this->targetPreviewUrl(['druh' => 'popup', 'radek' => $p], 60);
        }

        return $output;
    }

    /** Creates a popup from a template or changes its settings; only a published one can be enabled. */
    private function savePopup(array $a): array
    {
        $db = $this->app->db();
        $popups = \Kaleta\Builder\Popups::class;
        if (isset($a['id'])) {
            $p = $popups::byId($db, (int) $a['id']) ?? throw new \InvalidArgumentException('Pop-up okno neexistuje. Použij nástroj seznam_popupu.');
        } else {
            $key = (string) ($a['vzor'] ?? 'prazdny');
            $pattern = $popups::LIBRARY[$key] ?? throw new \InvalidArgumentException('Neznámý vzor okna. Vzory: ' . implode(', ', array_keys($popups::LIBRARY)) . '.');
            $name = mb_substr(trim((string) ($a['nazev'] ?? '')), 0, 100) ?: t($pattern[0]);
            $id = $db->insert('popupy', ['nazev' => $name, 'adresa' => $popups::address($db, $name), 'typ' => $pattern[2], 'spoustec' => $pattern[3], 'hodnota' => $pattern[4],
                'pravidla' => (string) json_encode($popups::defaultRules()), 'cetnost' => 'relace', 'dni' => 7, 'aktivni' => 0,
                'stavba_koncept' => Build::toJson($popups::libraryBuild($key, Language::defaults($this->app->settings()))), 'zmeneno' => date('Y-m-d H:i:s')]);
            $p = (array) $popups::byId($db, $id);
        }
        $changes = [];
        if (isset($a['nazev']) && trim((string) $a['nazev']) !== '') {
            $changes['nazev'] = mb_substr(trim((string) $a['nazev']), 0, 100);
        }
        if (isset($a['adresa']) && trim((string) $a['adresa']) !== '') {
            $url = slugify((string) $a['adresa'], 60);
            if (!preg_match($popups::ADDRESS_PATTERN, $url) || $db->value('SELECT idpp FROM {popupy} WHERE adresa = ? AND idpp <> ?', [$url, $p['idpp']]) !== null) {
                throw new \InvalidArgumentException('Tuto adresu už používá jiné okno.');
            }
            $changes['adresa'] = $url;
        }
        foreach (['typ' => $popups::TYPES, 'spoustec' => $popups::TRIGGERS, 'cetnost' => $popups::FREQUENCIES] as $field => $allowed) {
            if (isset($a[$field])) {
                $changes[$field] = isset($allowed[$a[$field]]) ? (string) $a[$field] : throw new \InvalidArgumentException('Neplatná hodnota „' . $field . '“. Povolené: ' . implode(', ', array_keys($allowed)) . '.');
            }
        }
        if (isset($a['hodnota'])) {
            $changes['hodnota'] = max(0, min(3600, (int) $a['hodnota']));
        }
        if (isset($a['dni'])) {
            $changes['dni'] = max(1, min(365, (int) $a['dni']));
        }
        if (isset($a['poradi'])) {
            $changes['poradi'] = max(-9999, min(9999, (int) $a['poradi']));
        }
        if (isset($a['pravidla'])) {
            if (!is_array($a['pravidla'])) {
                throw new \InvalidArgumentException('Parametr pravidla musí být objekt.');
            }
            $changes['pravidla'] = (string) json_encode($popups::sanitizeRules($a['pravidla'] + $p['pravidla']), JSON_UNESCAPED_UNICODE);
        }
        if (array_key_exists('aktivni', $a)) {
            if (!empty($a['aktivni']) && $p['stavba'] === null) {
                throw new \InvalidArgumentException('Okno nejdřív publikuj (publikuj_stavbu s parametrem popup) – teprve pak ho jde zapnout.');
            }
            $changes['aktivni'] = empty($a['aktivni']) ? 0 : 1;
        }
        $changes += self::validityDates($a);
        if ($changes !== []) {
            $db->update('popupy', $changes + ['zmeneno' => date('Y-m-d H:i:s')], ['idpp' => $p['idpp']]);
        }

        return (array) $popups::byId($db, $p['idpp']);
    }

    /**
     * Build target: a page (id; without id and with $create a new hidden page named from „titulek“) or a site part
     * (cast = type, language), which only the administrator can change. A part that does not exist yet is created with a
     * draft from the layout.
     *
     * @return array{druh: string, radek: array<string, mixed>, stavba: ?string, koncept: ?string, jazyk: string, revize: array{ids?: int, cast?: string}}
     */
    private function loadBuildTarget(array $a, bool $create = false): array
    {
        $auth = $this->app->auth();
        $db = $this->app->db();
        $siteSettings = $this->app->settings();
        // 3.22 (C9): pop-ups, components and site parts also for a user with the Appearance area (Auth::hasAppearanceArea)
        if (isset($a['popup']) && (int) $a['popup'] > 0) {
            if (!$auth->hasAppearanceArea()) {
                throw new \DomainException('Pop-up okna smí měnit jen správce webu.');
            }
            $row = \Kaleta\Builder\Popups::byId($db, (int) $a['popup']) ?? throw new \InvalidArgumentException('Pop-up okno neexistuje. Použij nástroj seznam_popupu.');

            return ['druh' => 'popup', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, ''),
                'revize' => ['cast' => 'popup:' . $row['idpp']]];
        }
        if (isset($a['komponenta']) && (int) $a['komponenta'] > 0) {
            if (!$auth->hasAppearanceArea()) {
                throw new \DomainException('Components can be changed only by an administrator or a user with the Appearance area.');
            }
            $row = \Kaleta\Builder\Components::byId($db, (int) $a['komponenta']) ?? throw new \InvalidArgumentException('The component does not exist. Use list_components.');

            return ['druh' => 'komponenta', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, ''),
                'revize' => ['cast' => 'komponenta:' . (int) $row['idm']]];
        }
        if (isset($a['kolekce']) && $a['kolekce'] !== '') {
            if (!$auth->isAdmin()) {
                throw new \DomainException('Šablonu detailu kolekce smí měnit jen správce webu.');
            }
            $row = \Kaleta\Builder\Collections::bySlug($db, (string) $a['kolekce']) ?? throw new \InvalidArgumentException('Kolekce neexistuje. Použij nástroj seznam_kolekci.');
            $language = (string) ($a['jazyk'] ?? '') === Language::defaults($siteSettings) ? '' : (string) ($a['jazyk'] ?? '');
            if ($language !== '' && !in_array($language, Language::additional($siteSettings), true)) {
                throw new \InvalidArgumentException('Jazyková verze „' . $language . '“ není zapnutá (Rozšíření → Jazykové verze, jazyky v Nastavení).');
            }
            // 3.7: the category page template instead of the item template (category_template)
            $row = !empty($a['kategorie_sablona']) ? \Kaleta\Builder\CollectionCategories::template($db, $row, $language) : \Kaleta\Builder\Collections::inLanguage($db, $row, $language);
            if ($row['stavba'] === null && $row['stavba_koncept'] === null) {
                // the template the builder would show until someone edits it (another language starts with a copy of the default)
                $row['stavba_koncept'] = \Kaleta\Builder\Collections::initialTemplateDraft($db, $row);
            }

            return ['druh' => 'kolekce', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, $language),
                'revize' => ['cast' => \Kaleta\Builder\Collections::templateKey($row)]];
        }
        if (isset($a['cast']) && $a['cast'] !== '') {
            if (!$auth->hasAppearanceArea()) {
                throw new \DomainException('Části webu (záhlaví, patičku, obálky) smí měnit jen správce webu.');
            }
            $type = (string) $a['cast'];
            if (!isset(SiteParts::TYPES[$type])) {
                throw new \InvalidArgumentException('Neznámá část webu. Typy: ' . implode(', ', array_keys(SiteParts::TYPES)) . '.');
            }
            $language = in_array($a['jazyk'] ?? '', Language::additional($siteSettings), true) ? (string) $a['jazyk'] : '';
            $variant = (string) ($a['varianta'] ?? '');
            if ($variant !== '') {
                $row = in_array($type, SiteParts::WITH_VARIANTS, true) ? SiteParts::row($db, $type, $language, $variant) : null;
                if ($row === null) {
                    throw new \InvalidArgumentException('Varianta neexistuje. Varianty záhlaví a patičky vypíše seznam_casti, založí uloz_variantu.');
                }
            } else {
                // a part that does not exist yet: the draft the builder would start with – the row is created only on write
                // (reading changes nothing)
                $row = SiteParts::row($db, $type, $language) ?? ['typ' => $type, 'jazyk' => $language, 'varianta' => '', 'nazev' => '', 'stranky' => null, 'stavba' => null,
                    'stavba_koncept' => SiteParts::initialDraft($db, $type, $language, Language::ofContent($siteSettings, $language)), 'zmeneno' => null, 'nova' => true];
            }

            return ['druh' => 'cast', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, $language),
                'revize' => ['cast' => SiteParts::versionKey($type, $language, $variant)]];
        }
        if (!$auth->hasModule('pages')) {
            throw new \DomainException('Stránky smí upravovat editor nebo správce.');
        }
        if (!isset($a['id']) && $create) {
            $a['id'] = $this->savePage(null, ['titulek' => (string) ($a['titulek'] ?? '')])['id'];
        }
        $row = $db->one('SELECT * FROM {stranky} WHERE ids = ? AND smazano IS NULL', [(int) ($a['id'] ?? 0)]) ?? throw new \InvalidArgumentException('Stránka neexistuje. Použij nástroj seznam_stranek.');

        return ['druh' => 'stranka', 'radek' => $row, 'stavba' => $row['stavba'], 'koncept' => $row['stavba_koncept'], 'jazyk' => Language::ofContent($siteSettings, $row['jazyk']),
            'revize' => ['ids' => (int) $row['ids']]];
    }

    /** The target's draft build, otherwise the published one; a text page as a build from its text. */
    private function targetBuild(array $target): array
    {
        return Build::fromJson($target['koncept'] ?? $target['stavba'])
            ?? ($target['druh'] === 'stranka' ? Build::fromText($target['radek']['titulek'], (string) $target['radek']['text']) : ['v' => Build::VERSION, 'deti' => []]);
    }

    /** @return array<string, mixed> */
    private function describeTarget(array $target): array
    {
        return match ($target['druh']) {
            'stranka' => ['id' => (int) $target['radek']['ids'], 'titulek' => $target['radek']['titulek']],
            'kolekce' => (($target['radek']['sablona_druh'] ?? '') === 'kategorie'
                ? ['kolekce' => $target['radek']['seo_link'], 'titulek' => 'Category page: ' . $target['radek']['nazev'], 'category_template' => true]
                : ['kolekce' => $target['radek']['seo_link'], 'titulek' => 'Detail: ' . $target['radek']['nazev'], 'detail_zapnuty' => (bool) $target['radek']['detail']])
                + ($target['radek']['sablona_jazyk'] !== '' ? ['jazyk' => $target['radek']['sablona_jazyk']] : []),
            'popup' => ['popup' => $target['radek']['idpp'], 'titulek' => 'Pop-up: ' . $target['radek']['nazev'], 'aktivni' => (bool) $target['radek']['aktivni']],
            'komponenta' => ['component' => (int) $target['radek']['idm'], 'titulek' => 'Component: ' . $target['radek']['nazev']],
            default => ['cast' => $target['radek']['typ'], 'jazyk' => $target['radek']['jazyk'], 'titulek' => SiteParts::TYPES[$target['radek']['typ']][0]]
                + ($target['radek']['varianta'] !== '' ? ['varianta' => $target['radek']['varianta']] : []),
        };
    }

    /**
     * A build tool asked to publish: only with the publishing permission (an editor or an administrator, and a connection
     * with full access). Checked before anything is saved, so nothing half-done stays behind.
     *
     * @param array<string, mixed> $a
     */
    private function mayPublish(array $a): void
    {
        if (!empty($a['publikovat']) && !$this->app->auth()->canPublish()) {
            throw new \DomainException('Publishing needs an editor or an administrator and a connection with full access. Save the build without publish – it stays a draft for the user to publish.');
        }
    }

    private function publishTarget(array $target): void
    {
        $db = $this->app->db();
        if ($target['druh'] === 'stranka') {
            Publisher::page($this->app, (array) $db->one('SELECT * FROM {stranky} WHERE ids = ?', [$target['radek']['ids']]));
        } elseif ($target['druh'] === 'kolekce') {
            $collection = (array) \Kaleta\Builder\Collections::byId($db, (int) $target['radek']['idk']);
            $row = ($target['radek']['sablona_druh'] ?? '') === 'kategorie' ? \Kaleta\Builder\CollectionCategories::template($db, $collection, $target['radek']['sablona_jazyk'])
                : \Kaleta\Builder\Collections::inLanguage($db, $collection, $target['radek']['sablona_jazyk']);
            // a default template nobody saved is published too (otherwise there would be nothing to publish)
            $row['stavba_koncept'] ??= $target['koncept'];
            Publisher::collection($this->app, $row);
        } elseif ($target['druh'] === 'popup') {
            Publisher::popup($this->app, (array) \Kaleta\Builder\Popups::byId($db, $target['radek']['idpp']));
        } elseif ($target['druh'] === 'komponenta') {
            Publisher::component($this->app, (array) \Kaleta\Builder\Components::byId($db, (int) $target['radek']['idm']));
        } else {
            $this->createSitePart($target['radek']);
            Publisher::part($this->app, (array) SiteParts::row($db, $target['radek']['typ'], $target['radek']['jazyk'], (string) $target['radek']['varianta']));
        }
    }

    /**
     * A site part that loadBuildTarget() only offered (not in the database yet) is created with the default draft before
     * the first write.
     */
    private function createSitePart(array $row): void
    {
        if (!empty($row['nova']) && SiteParts::row($this->app->db(), $row['typ'], $row['jazyk']) === null) {
            $this->app->db()->insert('casti', ['typ' => $row['typ'], 'jazyk' => $row['jazyk'], 'stavba_koncept' => $row['stavba_koncept'], 'zmeneno' => date('Y-m-d H:i:s')]);
        }
    }

    /** Sanitizes and saves the draft (and publishes it if asked); returns what the model needs for further work. */
    private function saveBuild(array $target, array $input, bool $publish, bool $restore = false): array
    {
        $this->mayPublish(['publikovat' => $publish]);
        $db = $this->app->db();
        $before = $target['koncept'] ?? $target['stavba'];
        [$build, $errors] = Build::sanitize($input, $this->app->auth()->canWriteCode(), Build::fromJson($before));
        $r = $target['radek'];
        if ($target['druh'] === 'stranka') {
            $db->update('stranky', ['stavba_koncept' => Build::toJson($build)], ['ids' => $r['ids']]);
            \Kaleta\Core\DraftAttribution::record($this->app, (int) $r['ids'], (string) $r['titulek']); // 3.24 (N-U6): "Draft by Jana via Claude"
        } elseif ($target['druh'] === 'kolekce') {
            \Kaleta\Builder\Collections::writeTemplate($db, $r, ['stavba_koncept' => Build::toJson($build)]);
            $target['koncept'] = Build::toJson($build);
        } elseif ($target['druh'] === 'popup') {
            $db->update('popupy', ['stavba_koncept' => Build::toJson($build)], ['idpp' => $r['idpp']]);
        } elseif ($target['druh'] === 'komponenta') {
            $db->update('komponenty', ['stavba_koncept' => Build::toJson($build)], ['idm' => $r['idm']]);
        } else {
            $this->createSitePart($r);
            $db->update('casti', ['stavba_koncept' => Build::toJson($build)], ['typ' => $r['typ'], 'jazyk' => $r['jazyk'], 'varianta' => $r['varianta']]);
        }
        // 3.25 (U17): draft history, marked as Claude's – a restore keeps the draft it replaces first
        \Kaleta\Builder\DraftHistory::record($this->app, $target['revize'], Build::toJson($build), $before, $restore);
        if ($publish) {
            $this->publishTarget($target);
        }
        $params = match ($target['druh']) {
            'stranka' => 'module=pages&action=builder&id=' . (int) $r['ids'],
            'kolekce' => 'module=collections&action=builder&id=' . (int) $r['idk'] . ($r['sablona_jazyk'] !== '' ? '&language=' . $r['sablona_jazyk'] : '') . (($r['sablona_druh'] ?? '') === 'kategorie' ? '&template=kategorie' : ''),
            'popup' => 'module=popups&action=builder&id=' . (int) $r['idpp'],
            'komponenta' => 'module=components&action=builder&id=' . (int) $r['idm'],
            default => 'module=parts&action=builder&type=' . $r['typ'] . '&language=' . $r['jazyk'],
        };

        return $this->describeTarget($target) + ['stav' => $publish ? 'publikováno' : 'koncept – na webu se ukáže po publikování', 'prvku' => $this->countElements($build['deti']),
            'chyby' => $errors, 'nahled' => $publish ? $this->targetUrl($target) : $this->targetPreviewUrl($target, 60),
            'stavitel' => $this->app->request->origin() . $this->app->url('admin.php?' . $params)] + $this->checkTarget($target, $build);
    }

    /**
     * Check before publishing as in the builder (sample content, buttons without a link, images without alt text, the
     * page's heading outline); nothing when there are no findings.
     */
    private function checkTarget(array $target, array $build): array
    {
        // an item template checks its {{fields}} against its collection; a category template has other placeholders
        $collection = $target['druh'] === 'kolekce' && ($target['radek']['sablona_druh'] ?? '') !== 'kategorie'
            ? \Kaleta\Builder\Collections::byId($this->app->db(), (int) $target['radek']['idk']) : null;
        // 3.11 (UX310-1): sample content of a starter site or a template first – placeholders and sample texts to replace
        $findings = array_slice([...($target['druh'] !== 'kolekce' ? \Kaleta\Builder\SampleContent::findings($build) : []),
            ...\Kaleta\Builder\Check::builds($build, $target['druh'] === 'stranka'),
            ...\Kaleta\Builder\Check::collections($this->app->db(), $build, $collection),
            ...\Kaleta\Builder\Check::formLimits($build, \Kaleta\Core\Files::limit(), \Kaleta\Core\Files::postLimit())], 0, \Kaleta\Builder\Check::MAX); // 3.47

        return $findings === [] ? [] : ['kontrola' => $findings];
    }

    /**
     * Signed link to the target's draft (valid only for this target and for a limited time). With $comments the key allows
     * comments on the draft (2.15, Core\DraftComments) – page drafts only, the other targets have no comment widget.
     */
    private function targetPreviewUrl(array $target, int $minutes, bool $comments = false): string
    {
        $r = $target['radek'];
        if ($target['druh'] === 'komponenta') {
            return $this->targetUrl($target); // the component canvas: for a signed-in administrator only
        }
        $signature = match ($target['druh']) {
            'stranka' => 'stranka:' . (int) $r['ids'],
            'kolekce' => \Kaleta\Builder\Collections::templateKey($r),
            'popup' => 'popup:' . (int) $r['idpp'],
            default => 'cast:' . $r['typ'] . ':' . $r['jazyk'] . ($r['varianta'] !== '' ? ':' . $r['varianta'] : ''), // a link to the header would not show the variant's draft
        };
        $key = \Kaleta\Core\Preview::key($this->app->db(), $this->app->settings(), $signature, $minutes, $comments && $target['druh'] === 'stranka');
        if ($target['druh'] === 'popup') {
            return $this->targetUrl($target) . '?build=koncept&preview_key=' . $key;
        }

        $variant = $target['druh'] === 'cast' && $r['varianta'] !== '' ? 'variant=' . rawurlencode($r['varianta']) . '&' : '';

        return $this->targetUrl($target) . '?' . ($target['druh'] === 'cast' ? 'part=' . $r['typ'] . '&' : '') . $variant . 'build=koncept&preview_key=' . $key;
    }

    /**
     * Public URL where the target is visible (for a site part the home page, news item detail, listing, 404; for a
     * collection the first item).
     */
    private function targetUrl(array $target): string
    {
        $r = $target['radek'];
        if ($target['druh'] === 'popup') {
            return $this->app->request->origin() . $this->app->url('_popup/' . (int) $r['idpp']); // the popup draft over an empty site page
        }
        if ($target['druh'] === 'komponenta') {
            return $this->app->request->origin() . $this->app->url('_komponenta/' . (int) $r['idm']);
        }
        if ($target['druh'] === 'kolekce' && ($r['sablona_druh'] ?? '') === 'kategorie') {
            // 3.7: the category template on the first top-level category of its language, without one on sample values
            $language = $r['sablona_jazyk'];
            $first = array_values(array_filter(\Kaleta\Builder\CollectionCategories::tree($this->app->db(), (int) $r['idk'], $language), fn (array $c): bool => $c['parent_id'] === null))[0] ?? null;

            return $this->app->request->origin() . $this->app->url(($language !== '' ? $language . '/' : '') . $r['seo_link'] . '/' . ($first !== null ? $first['slug'] : '_kategorie'));
        }
        if ($target['druh'] === 'kolekce') {
            $language = $r['sablona_jazyk'];
            $item = $this->app->db()->value('SELECT seo_link FROM {kolekce_polozky} WHERE idk = ? AND jazyk = ? AND smazano IS NULL ORDER BY zobrazit DESC, poradi, idp LIMIT 1', [$r['idk'], $language]);

            return $this->app->request->origin() . $this->app->url(($language !== '' ? $language . '/' : '') . $r['seo_link'] . '/' . ($item ?? '_ukazka'));
        }
        if ($target['druh'] === 'stranka') {
            $home = $this->app->settings()->int('home_page') === (int) $r['ids'];
            $path = $home ? '' : $r['seo_link'];
        } elseif ($r['varianta'] !== '') {
            // the variant is shown on the first page it applies to
            $ids = (int) ((json_decode((string) $r['stranky'], true) ?: [])[0] ?? 0);
            $path = (string) $this->app->db()->value('SELECT seo_link FROM {stranky} WHERE ids = ?', [$ids]);
        } else {
            $path = match ($r['typ']) {
                'novinka', 'vypis' => 'novinky',
                'nenalezeno' => 'tahle-stranka-neexistuje',
                default => '',
            };
        }

        return $this->app->request->origin() . $this->app->url(($r['jazyk'] !== '' ? $r['jazyk'] . '/' : '') . $path);
    }

    private function countElements(array $children): int
    {
        return array_sum(array_map(fn (array $p): int => 1 + $this->countElements($p['deti'] ?? []), $children));
    }


    /** Media file for MCP output: URL for the build (media/…), dimensions and whether it is an image. */
    private function medium(array $o): array
    {
        return ['id' => (int) $o['ido'], 'nazev' => $o['nazev'], 'adresa' => $o['obr_poloha'], 'url' => $this->app->request->origin() . $this->app->url($o['obr_poloha']),
            'obrazek' => $o['nahl_poloha'] !== '', 'rozmery' => $o['nahl_poloha'] !== '' ? $o['obr_width'] . '×' . $o['obr_height'] : null]
            + (($o['staged_at'] ?? null) !== null ? ['staged' => true] : []); // 3.15: waiting for approval (Core\MediaStaging)
    }


    /** @return array<string, mixed> */
    private function uploadFile(array $a): array
    {
        $displayName = basename(str_replace('\\', '/', trim((string) ($a['nazev'] ?? ''))));
        $extension = strtolower(pathinfo($displayName, PATHINFO_EXTENSION));
        if ($extension === '') {
            throw new \InvalidArgumentException('Název souboru musí mít příponu (např. foto.jpg, logo.svg, pismo.woff2).');
        }
        if (is_string($a['url'] ?? null) && $a['url'] !== '') {
            if (!\Kaleta\Mcp\Catalog::mayFetch($this->app->auth()->connection())) {
                throw new \DomainException(\Kaleta\Mcp\Catalog::FETCH_REFUSAL); // 3.35 (N104): only a full connection downloads
            }
            $url = trim($a['url']);
            if (!str_starts_with(strtolower($url), 'https://')) {
                throw new \InvalidArgumentException('Stahovat jde jen z https adresy.');
            }
            try {
                // the "old site" of the downloader is the address itself, so its domain rule only keeps redirects on the host
                // Claude named (S5, documented in 3.15): what protects the site is https only, a public address and the
                // connection pinned to it on every hop, and the size and time limits
                $content = (new \Kaleta\Core\ImageDownloader($url))->download($url, false);
            } catch (\RuntimeException $e) {
                throw new \InvalidArgumentException('Soubor se nepodařilo stáhnout: ' . $e->getMessage());
            }
        } else {
            $content = base64_decode(preg_replace('#^data:[^,]*,#', '', (string) ($a['data'] ?? '')) ?? '', true);
            if ($content === false || $content === '') {
                throw new \InvalidArgumentException('Chybí data souboru v base64 (parametr data), nebo url.');
            }
        }
        if (strlen($content) > self::MAX_UPLOAD) {
            throw new \InvalidArgumentException('Soubor je větší než ' . (self::MAX_UPLOAD >> 20) . ' MB.');
        }
        if ($extension === 'xml') {
            return $this->uploadWordpressExport($content, $displayName); // 3.6: never into Media – kept privately for import_wordpress
        }
        // 3.15 (N10): from a connection limited to drafts the file waits outside the web root until it is approved
        $staged = \Kaleta\Core\MediaStaging::appliesTo($this->app->auth());
        $temporary = tempnam(sys_get_temp_dir(), 'kaleta-mcp-');
        file_put_contents($temporary, $content);
        try {
            $data = match (true) {
                $extension === 'svg' => Media::saveSvgContent($content, $displayName, $staged),
                \Kaleta\Core\Files::isAttachment($displayName) => \Kaleta\Core\Files::saveFile($temporary, $displayName, $staged),
                default => \Kaleta\Core\Images::saveFile($temporary, $displayName, $staged),
            };
        } catch (\RuntimeException $e) {
            throw new \InvalidArgumentException($e->getMessage());
        } finally {
            @unlink($temporary);
        }
        if (is_string($a['popis'] ?? null) && trim($a['popis']) !== '') {
            $data['nazev'] = mb_substr(trim($a['popis']), 0, 150);
        }
        $now = date('Y-m-d H:i:s');
        $data['ido'] = $this->app->db()->insert('media', $data + ['vlastnik' => $this->app->auth()->id(), 'sekce' => null, 'datum' => $now, 'staged_at' => $staged ? $now : null]);

        return $this->medium($data + ['staged_at' => $staged ? $now : null]) + ['pouziti' => match (true) {
            $extension === 'woff2' || $extension === 'woff' => 'uprav_design_system {"ds":{"vlastni_pisma":[{"nazev":"…","soubor":"' . $data['obr_poloha'] . '"}],"pismo_titulky":"vlastni-1"}}',
            $data['nahl_poloha'] !== '' => 'prvek obrazek {"src":"' . $data['obr_poloha'] . '"} nebo styl obrazek_pozadi',
            default => 'odkaz na soubor: /' . $data['obr_poloha'],
        }] + ($staged ? ['note' => self::STAGED_NOTE] : []);
    }

    /** @return array<string, mixed> */
    /** A free collection slug: not a system path, a language code or the slug of another collection. */
    private function availableCollectionSlug(string $given, int $idk): string
    {
        $seo = slugify($given, 110);
        if ($idk > 0 && $seo !== '' && $this->app->db()->value('SELECT 1 FROM {kolekce} WHERE idk = ? AND seo_link = ?', [$idk, $seo]) !== null) {
            return $seo; // an unchanged slug stays, also one a later release reserved (3.7)
        }
        if ($seo === '' || in_array($seo, Pages::RESERVED_SLUGS, true) || isset(Language::AVAILABLE[$seo]) || \Kaleta\Core\Routes::isNewsSlug($seo, $this->app->db())
            || $this->app->db()->value('SELECT idk FROM {kolekce} WHERE seo_link = ? AND idk <> ?', [$seo, $idk]) !== null) {
            throw new \InvalidArgumentException('Adresu „' . $seo . '“ už používá systém nebo jiná kolekce.');
        }

        return $seo;
    }

    private function collection(string $seo): array
    {
        return Collections::bySlug($this->app->db(), $seo) ?? throw new \InvalidArgumentException('Kolekce neexistuje. Použij seznam_kolekci.');
    }

    /** @return array<string, mixed> news item the user has access to */
    private function newsItem(int $id): array
    {
        $newsItem = $this->app->db()->one('SELECT * FROM {novinky} WHERE idc = ? AND smazano IS NULL', [$id]);
        $authors = $this->app->auth()->managedAuthors();
        if ($newsItem === null || ($authors !== null && !in_array((int) $newsItem['autor'], $authors, true))) {
            throw new \InvalidArgumentException('Novinka neexistuje nebo k ní uživatel nemá přístup.');
        }

        return $newsItem;
    }

    private function category(string $nameOrSlug): int
    {
        // with slugs per language (3.9) two versions may share a slug: the default language's category wins, as before
        $idt = $this->app->db()->value("SELECT idt FROM {kategorie} WHERE seo_link = ? OR nazev = ? ORDER BY jazyk = '' DESC, idt LIMIT 1", [$nameOrSlug, $nameOrSlug]);
        if ($idt === null) {
            throw new \InvalidArgumentException('Kategorie „' . $nameOrSlug . '“ neexistuje. Použij nástroj seznam_kategorii.');
        }

        return (int) $idt;
    }

    /** A free slug of a news item or category in its language version (per language only with slugs_per_language, Core\Slug). */
    private function availableSlug(string $table, string $seo, string $language = ''): string
    {
        return \Kaleta\Core\Slug::makeUnique($seo, fn (string $a): bool => \Kaleta\Core\Slug::taken($this->app->db(), $table, $a, $language), $table === 'novinky' ? 160 : 120);
    }
}
