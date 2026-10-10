<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

/**
 * Every MCP tool once (2.1): its English name => [access, the extension it needs ('' = none)].
 *
 * Access is what an MCP client should confirm with the user: "read" changes nothing, "draft" only saves drafts (a build,
 * a hidden page, a draft look, a news draft – publishing it needs the publishing permission – and since 3.2 a hidden
 * collection item, a proposed exception to the opening hours, an enquiry triage and a notebook note; the tool itself
 * refuses a drafts-only connection anything more, Auth::draftsOnly), "write" changes the site,
 * and "destructive" removes or overwrites something the user may want back, or cannot be taken back (sending). A
 * connection limited to drafts (2.2) may use "read" and "draft" tools and never publishes; a read-only one only "read". The
 * annotations, the change log, the extension gate and tools/list all come from here. The tool itself is the method
 * Tools::tool<Name> (toolSiteInfo for site_info), its English definition is in Translator and its parameter types in
 * Tools::definitions(); tools/unit-tests.php checks that all four agree.
 */
final class Catalog
{
    /** @var array<string, array{0: 'read'|'draft'|'write'|'destructive', 1: string}> */
    public const array TOOLS = [
        // Site and pages
        'site_info' => ['read', ''],
        'list_pages' => ['read', ''],
        'get_page' => ['read', ''],
        'create_page' => ['draft', ''],
        'update_page' => ['draft', ''],
        'trash_page' => ['destructive', ''],
        'get_menu' => ['read', ''],
        'save_menu' => ['draft', ''],
        'list_trash' => ['read', ''],
        'restore_from_trash' => ['write', ''],
        // Builder
        'builder_schema' => ['read', ''],
        'get_build' => ['read', ''],
        'edit_build' => ['draft', ''],
        'build_from_html' => ['draft', ''],
        'save_build' => ['draft', ''],
        'insert_section' => ['draft', ''],
        'publish_build' => ['write', ''],
        'list_build_versions' => ['read', ''],
        'restore_build_version' => ['destructive', ''],
        'discard_draft' => ['destructive', ''],
        'save_section' => ['draft', ''],
        'delete_section' => ['destructive', ''],
        'list_components' => ['read', ''],
        'save_component' => ['write', ''],
        'delete_component' => ['destructive', ''],
        'list_site_parts' => ['read', ''],
        'save_part_variant' => ['write', ''],
        'apply_part_template' => ['draft', ''],
        'preview_link' => ['read', ''],
        // Comments on drafts (2.15)
        'list_draft_comments' => ['read', ''],
        'resolve_draft_comment' => ['write', ''],
        // Look
        'list_classes' => ['read', ''],
        'save_classes' => ['draft', ''],
        'update_design_system' => ['draft', ''],
        'publish_look' => ['destructive', ''],
        'discard_look' => ['destructive', ''],
        'list_look_versions' => ['read', ''],
        'restore_look_version' => ['draft', ''],
        // Collections
        'list_collections' => ['read', ''],
        'create_collection' => ['write', ''],
        'update_collection' => ['write', ''],
        'delete_collection' => ['destructive', ''],
        'list_collection_items' => ['read', ''],
        // 3.2: a drafts-only connection saves hidden items only (Handlers\CollectionTools)
        'save_collection_item' => ['draft', ''],
        // 3.7: many items at once with the same rules – a drafts-only connection saves hidden items only (Builder\ItemBatch)
        'save_collection_items' => ['draft', ''],
        'delete_collection_item' => ['destructive', ''],
        'list_item_versions' => ['read', ''],
        'restore_item_version' => ['write', ''],
        'get_email_signature' => ['read', ''],
        // 3.7: collection categories – a drafts-only connection saves hidden categories only (Handlers\CollectionTools)
        'list_collection_categories' => ['read', ''],
        'save_collection_category' => ['draft', ''],
        'delete_collection_category' => ['destructive', ''],
        // News
        'list_news' => ['read', 'novinky'],
        'get_news' => ['read', 'novinky'],
        'create_news' => ['draft', 'novinky'],
        'update_news' => ['draft', 'novinky'],
        'trash_news' => ['destructive', 'novinky'],
        'list_categories' => ['read', 'novinky'],
        'create_category' => ['write', 'novinky'],
        'update_category' => ['write', 'novinky'],
        'delete_category' => ['destructive', 'novinky'],
        // Media
        'list_media' => ['read', ''],
        'upload_file' => ['draft', ''],
        'import_website' => ['write', ''],
        'update_media' => ['write', ''],
        'delete_media' => ['destructive', ''],
        'list_media_without_alt' => ['read', ''],
        // Enquiries and pop-ups
        'list_enquiries' => ['read', ''],
        // 3.2: a drafts-only connection saves only the triage – a suggestion a person's sorting overrides
        'update_enquiry' => ['draft', 'poptavky'],
        'delete_enquiry' => ['destructive', 'poptavky'],
        'find_personal_data' => ['read', 'poptavky'],
        'erase_personal_data' => ['destructive', 'poptavky'],
        'import_enquiries' => ['write', 'poptavky'],
        'list_popups' => ['read', ''],
        'save_popup' => ['write', ''],
        'delete_popup' => ['destructive', ''],
        // Settings, redirects and audit
        'update_settings' => ['write', ''],
        'list_redirects' => ['read', ''],
        'save_redirect' => ['write', ''],
        'ignore_not_found' => ['write', 'presmerovani'],
        'save_redirects' => ['write', 'presmerovani'],
        'site_audit' => ['read', ''],
        'list_broken_links' => ['read', ''],
        'suggest_internal_links' => ['read', ''],
        'list_agent_sessions' => ['read', ''],
        'undo_agent_session' => ['destructive', ''],
        'list_changes' => ['read', ''],
        'get_stats' => ['read', ''],
        // Moving a site (2.7)
        'migration_report' => ['read', ''],
        // 3.6: the WordPress export import – everything arrives hidden and menus go to the draft look, but it creates
        // records and redirects, so it is not for a drafts-only connection
        'import_wordpress' => ['write', ''],
        // A site that runs itself (2.8)
        'get_health' => ['read', ''],
        'list_events' => ['read', ''],
        'list_facts' => ['read', ''],
        'save_fact' => ['write', ''],
        'delete_fact' => ['destructive', ''],
        'find_claims' => ['read', ''],
        'list_hours' => ['read', ''],
        'list_collection_presets' => ['read', ''],
        'triage_enquiries' => ['read', 'poptavky'],
        'request_testimonial' => ['write', 'poptavky'],
        'get_blueprint' => ['read', ''],
        'list_connectors' => ['read', ''],
        'processing_record' => ['read', ''],
        'accessibility_statement' => ['read', ''],
        'get_social_drafts' => ['read', 'novinky'],
        // Agent notebook (2.15)
        'read_notebook' => ['read', ''],
        'write_notebook' => ['draft', ''], // 3.2: internal notes, never shown on the site
        'delete_notebook_entry' => ['destructive', ''],
        'update_social_draft' => ['write', 'novinky'],
        'apply_blueprint' => ['write', ''],
        'remove_blueprint' => ['destructive', ''],
        'export_blueprint' => ['read', ''],
        'list_notice_log' => ['read', ''],
        // 3.2: from a drafts-only connection a PROPOSED exception the site ignores until a person applies it
        'save_hours_exception' => ['draft', ''],
        'delete_hours_exception' => ['destructive', ''],
        // Online booking (3.0)
        'list_bookings' => ['read', ''],
        'booking_availability' => ['read', ''],
        'save_booking_service' => ['write', ''],
        'save_booking_staff' => ['write', ''],
        'cancel_booking' => ['destructive', ''],
        'confirm_booking' => ['write', ''],
        'decline_booking' => ['destructive', ''],
        'propose_booking_times' => ['write', ''],
        'list_sites' => ['read', 'fleet'],
        'get_site' => ['read', 'fleet'],
        // Requests to Claude (2.15): update_request changes nothing on the site – a note and a status on a request, whose
        // work is done as drafts – so a drafts-only connection may answer the requests it works on
        'list_requests' => ['read', ''],
        // Waiting for you (3.2): drafts and proposals that wait for a person
        'list_pending_review' => ['read', ''],
        'update_request' => ['draft', ''],
        // Scheduled runs (2.17): a routine in Claude asks what is due and reports what it did as drafts – report_agent_run
        // only records the run, so a drafts-only connection (the one the runs are meant for) may call both
        'get_due_agent_runs' => ['read', ''],
        'report_agent_run' => ['draft', ''],
        // Content hygiene (2.14)
        'translation_status' => ['read', ''],
        // Newsletter
        'list_newsletters' => ['read', 'newsletter'],
        'draft_newsletter' => ['draft', 'newsletter'],
        'send_test_newsletter' => ['write', 'newsletter'],
        'send_newsletter' => ['destructive', 'newsletter'],
        'delete_newsletter' => ['destructive', 'newsletter'],
    ];

    /**
     * A short human-readable English title of every tool (3.8): tools/list sends it as the tool's `title` and as
     * `annotations.title` (MCP 2025-06-18), the Connectors Directory requires one for every tool. Unique, at most 40
     * characters, sentence case (tools/unit-tests.php checks it).
     *
     * @var array<string, string>
     */
    public const array TITLES = [
        'site_info' => 'Get site info',
        'list_pages' => 'List pages',
        'get_page' => 'Get a page',
        'create_page' => 'Create a page',
        'update_page' => 'Update a page',
        'trash_page' => 'Move a page to the trash',
        'get_menu' => 'Get a menu',
        'save_menu' => 'Save a menu to the draft look',
        'list_trash' => 'List the trash',
        'restore_from_trash' => 'Restore from the trash',
        'builder_schema' => 'Get the builder schema',
        'get_build' => 'Get a build',
        'edit_build' => 'Edit a draft build',
        'build_from_html' => 'Build from HTML',
        'save_build' => 'Save a draft build',
        'insert_section' => 'Insert a library section',
        'publish_build' => 'Publish a build',
        'list_build_versions' => 'List build versions',
        'restore_build_version' => 'Restore a build version',
        'discard_draft' => 'Discard a draft build',
        'save_section' => 'Save a reusable section',
        'delete_section' => 'Delete a saved section',
        'list_components' => 'List components',
        'save_component' => 'Save a component',
        'delete_component' => 'Delete a component',
        'list_site_parts' => 'List site parts',
        'save_part_variant' => 'Save a header or footer variant',
        'apply_part_template' => 'Apply a site part template',
        'preview_link' => 'Get a draft preview link',
        'list_draft_comments' => 'List draft comments',
        'resolve_draft_comment' => 'Resolve a draft comment',
        'list_classes' => 'List shared classes',
        'save_classes' => 'Save shared classes',
        'update_design_system' => 'Update the design system',
        'publish_look' => 'Publish the draft look',
        'discard_look' => 'Discard the draft look',
        'list_look_versions' => 'List look versions',
        'restore_look_version' => 'Restore a look version',
        'list_collections' => 'List collections',
        'create_collection' => 'Create a collection',
        'update_collection' => 'Update a collection',
        'delete_collection' => 'Delete a collection',
        'list_collection_items' => 'List collection items',
        'save_collection_item' => 'Save a collection item',
        'save_collection_items' => 'Save many collection items',
        'delete_collection_item' => 'Delete a collection item',
        'list_item_versions' => 'List item versions',
        'restore_item_version' => 'Restore an item version',
        'get_email_signature' => 'Get an e-mail signature',
        'list_collection_categories' => 'List collection categories',
        'save_collection_category' => 'Save a collection category',
        'delete_collection_category' => 'Delete a collection category',
        'list_news' => 'List news',
        'get_news' => 'Get a news item',
        'create_news' => 'Create a news item',
        'update_news' => 'Update a news item',
        'trash_news' => 'Move a news item to the trash',
        'list_categories' => 'List news categories',
        'create_category' => 'Create a news category',
        'update_category' => 'Update a news category',
        'delete_category' => 'Delete a news category',
        'list_media' => 'List media',
        'upload_file' => 'Upload a file',
        'import_website' => 'Import a website by its address',
        'update_media' => 'Update a media file',
        'delete_media' => 'Delete a media file',
        'list_media_without_alt' => 'List images without alt text',
        'list_enquiries' => 'List enquiries',
        'update_enquiry' => 'Update an enquiry',
        'delete_enquiry' => 'Delete an enquiry',
        'find_personal_data' => 'Find personal data',
        'erase_personal_data' => 'Erase personal data',
        'import_enquiries' => 'Import old enquiries',
        'list_popups' => 'List pop-ups',
        'save_popup' => 'Save a pop-up',
        'delete_popup' => 'Delete a pop-up',
        'update_settings' => 'Update settings',
        'list_redirects' => 'List redirects',
        'save_redirect' => 'Save a redirect',
        'ignore_not_found' => 'Ignore not-found addresses',
        'save_redirects' => 'Save many redirects',
        'site_audit' => 'Audit the site',
        'list_broken_links' => 'List broken links',
        'suggest_internal_links' => 'Suggest internal links',
        'list_agent_sessions' => 'List Claude sessions',
        'undo_agent_session' => 'Undo a Claude session',
        'list_changes' => 'List the change log',
        'get_stats' => 'Get visitor statistics',
        'migration_report' => 'Report on a site migration',
        'import_wordpress' => 'Import a WordPress export',
        'get_health' => 'Get site health',
        'list_events' => 'List site events',
        'list_facts' => 'List business facts',
        'save_fact' => 'Save a business fact',
        'delete_fact' => 'Delete a business fact',
        'find_claims' => 'Find claims in content',
        'list_hours' => 'List opening hours',
        'list_collection_presets' => 'List collection presets',
        'triage_enquiries' => 'List enquiries to sort',
        'request_testimonial' => 'Request a testimonial',
        'get_blueprint' => 'Get the industry blueprint',
        'list_connectors' => 'List integrations',
        'processing_record' => 'Get the record of processing',
        'accessibility_statement' => 'Get the accessibility statement',
        'get_social_drafts' => 'Get social post drafts',
        'read_notebook' => 'Read the notebook',
        'write_notebook' => 'Write to the notebook',
        'delete_notebook_entry' => 'Delete a notebook entry',
        'update_social_draft' => 'Update a social post draft',
        'apply_blueprint' => 'Apply an industry blueprint',
        'remove_blueprint' => 'Remove an industry blueprint',
        'export_blueprint' => 'Export the site as a blueprint',
        'list_notice_log' => 'List the notice board log',
        'save_hours_exception' => 'Save an opening hours exception',
        'delete_hours_exception' => 'Delete an opening hours exception',
        'list_bookings' => 'List bookings',
        'booking_availability' => 'Get booking availability',
        'save_booking_service' => 'Save a booking service',
        'save_booking_staff' => 'Save a booking staff member',
        'cancel_booking' => 'Cancel a booking',
        'confirm_booking' => 'Confirm a booking',
        'decline_booking' => 'Decline a booking',
        'propose_booking_times' => 'Propose other booking times',
        'list_sites' => 'List fleet sites',
        'get_site' => 'Get a fleet site',
        'list_requests' => 'List requests to Claude',
        'list_pending_review' => 'List what waits for review',
        'update_request' => 'Answer a request',
        'get_due_agent_runs' => 'Get due scheduled runs',
        'report_agent_run' => 'Report a scheduled run',
        'translation_status' => 'Get translation status',
        'list_newsletters' => 'List newsletters',
        'draft_newsletter' => 'Draft a newsletter',
        'send_test_newsletter' => 'Send a test newsletter to yourself',
        'send_newsletter' => 'Send a newsletter',
        'delete_newsletter' => 'Delete a newsletter',
    ];

    /**
     * Tools that reach outside the site during the call (3.8, MCP openWorldHint), by their English names. Verified in the
     * code: a download from an address the caller gives (upload_file with url, import_website, migration_report,
     * import_wordpress with url and its images, save_collection_items with media by URL – Core\ImageDownloader), and an
     * e-mail to people outside the site's users (subscribers, customers of an enquiry or a booking). Not on the list:
     * deliveries the site owner set up that run later in the background (webhooks, IndexNow, the Business Profile and CRM
     * integrations – Core\Connectors queue), the link check (a background job; list_broken_links reads its results),
     * list_connectors (status from the database) and e-mails to the site's own users (update_request, send_test_newsletter).
     */
    public const array OPEN_WORLD = ['upload_file', 'import_website', 'migration_report', 'import_wordpress', 'save_collection_items', 'send_newsletter',
        'request_testimonial', 'cancel_booking', 'confirm_booking', 'decline_booking', 'propose_booking_times'];

    /**
     * Write tools that a repeated call with the same arguments changes nothing more (3.8, MCP idempotentHint), verified in
     * the code: setters that overwrite (a draft, a field, a key – an unchanged build or item stores no new version,
     * Builder\Publisher::version), soft deletes that never become hard ones and hard deletes (a repeat finds nothing), and
     * booking decisions that hold only for one status (no second e-mail). Not on the list: tools that may create a new
     * record (save_* without an id, save_section, edit_build inserting elements), queue a delivery on every call
     * (update_settings with company hours, save_hours_exception) or that publish, send or import.
     */
    public const array IDEMPOTENT = ['update_page', 'trash_page', 'save_menu', 'restore_from_trash', 'save_build', 'restore_build_version', 'discard_draft',
        'delete_section', 'delete_component', 'resolve_draft_comment', 'update_design_system', 'discard_look', 'restore_look_version', 'update_collection',
        'delete_collection', 'delete_collection_item', 'restore_item_version', 'save_collection_category', 'delete_collection_category', 'trash_news',
        'update_category', 'delete_category', 'update_media', 'delete_media', 'update_enquiry', 'delete_enquiry', 'erase_personal_data', 'delete_popup',
        'save_redirect', 'ignore_not_found', 'save_redirects', 'save_fact', 'delete_fact', 'delete_notebook_entry', 'update_social_draft', 'remove_blueprint',
        'delete_hours_exception', 'cancel_booking', 'confirm_booking', 'decline_booking', 'delete_newsletter'];

    /**
     * The tool set of a connection (3.10): "all" lists every tool the connection may use (as before 3.10); "essentials"
     * lists ESSENTIALS and the two META tools, which reach the rest on demand – a much shorter tools/list in every
     * conversation. Existing connections keep "all"; the choice is on the connection row like its access.
     */
    public const array TOOL_SETS = ['all', 'essentials'];

    /**
     * The core tools an essentials connection lists (3.10): pages, the builder, the menu and the look, collections, media,
     * requests and enquiries, facts, the audit and the change log – what the server instructions, the prompts and the
     * plugin skills reach for first. tools/list of these plus META stays under 50 KB (tools/unit-tests.php checks it).
     */
    public const array ESSENTIALS = ['site_info', 'list_pages', 'get_page', 'create_page', 'update_page', 'get_menu', 'save_menu', 'builder_schema', 'get_build',
        'edit_build', 'build_from_html', 'save_build', 'insert_section', 'publish_build', 'preview_link', 'list_collections', 'list_collection_items',
        'save_collection_item', 'list_media', 'upload_file', 'update_media', 'list_requests', 'update_request', 'list_enquiries', 'list_facts', 'save_fact',
        'update_design_system', 'publish_look', 'site_audit', 'get_health', 'list_changes', 'read_notebook', 'list_pending_review'];

    /**
     * The meta tools (3.10): English name => [access, title, open world]. They are listed only for an essentials connection
     * but callable on any; they are not site tools – no Tools method, no Translator entry – and Mcp\Server runs them itself.
     * run_tool can run anything the connection may, so its annotations say so; the inner tool is checked like a direct call.
     *
     * @var array<string, array{0: 'read'|'destructive', 1: string, 2: bool}>
     */
    public const array META = [
        'find_tools' => ['read', 'Find more tools', false],
        'run_tool' => ['destructive', 'Run a tool by name', true],
    ];

    /** A known tool set; anything else (an old row, a form from before 3.10) is "all", as it was. */
    public static function toolSet(string $set): string
    {
        return in_array($set, self::TOOL_SETS, true) ? $set : 'all';
    }

    /** The title of a tool by either name (3.8): Kaleta's own from TITLES, an add-on's from its declaration. */
    public static function title(string $name): string
    {
        $english = self::english($name) ?? $name;

        return self::TITLES[$english] ?? self::META[$english][1] ?? \Kaleta\Extension\Registry::get()->tool($english)['title'] ?? ucfirst(str_replace('_', ' ', $english));
    }

    /** Does the tool reach outside the site (3.8, OPEN_WORLD; an add-on's tool as it declares)? */
    public static function isOpenWorld(string $name): bool
    {
        $english = self::english($name) ?? $name;

        return in_array($english, self::OPEN_WORLD, true) || (self::META[$english][2] ?? false) || (\Kaleta\Extension\Registry::get()->tool($english)['openWorld'] ?? false);
    }

    /** Is the write tool idempotent (3.8, IDEMPOTENT; an add-on's tool as it declares)? */
    public static function isIdempotent(string $name): bool
    {
        $english = self::english($name) ?? $name;

        return in_array($english, self::IDEMPOTENT, true) || (\Kaleta\Extension\Registry::get()->tool($english)['idempotent'] ?? false);
    }

    /** The English name of a tool given by either name (Czech names are hidden aliases of the older tools). */
    public static function english(string $name): ?string
    {
        if (isset(self::TOOLS[$name]) || \Kaleta\Extension\Registry::get()->tool($name) !== null) {
            return $name; // an add-on's tool (3.0) has only its English name
        }
        $english = array_search($name, array_combine(array_keys(self::TOOLS), array_map(fn (string $en): string => Translator::czech($en) ?? $en, array_keys(self::TOOLS))), true);

        return is_string($english) ? $english : null;
    }

    public static function access(string $name): string
    {
        if (isset(self::META[$name])) {
            return self::META[$name][0];
        }
        $addon = \Kaleta\Extension\Registry::get()->tool($name);
        if ($addon !== null) {
            return $addon['access'];
        }

        return self::TOOLS[self::english($name) ?? ''][0] ?? 'read';
    }

    public static function extension(string $name): string
    {
        return self::TOOLS[self::english($name) ?? ''][1] ?? '';
    }

    /** Connection access levels (2.2) => the tool access they allow. */
    public const array CONNECTION_ACCESS = ['full' => ['read', 'draft', 'write', 'destructive'], 'drafts' => ['read', 'draft'], 'read' => ['read']];

    /** May a connection with this access run the tool? */
    public static function allows(string $connectionAccess, string $name): bool
    {
        return in_array(self::access($name), self::CONNECTION_ACCESS[$connectionAccess] ?? self::CONNECTION_ACCESS['read'], true);
    }

    /** The method of Tools that runs the tool: site_info => toolSiteInfo. */
    public static function method(string $english): string
    {
        return 'tool' . str_replace('_', '', ucwords($english, '_'));
    }
}
