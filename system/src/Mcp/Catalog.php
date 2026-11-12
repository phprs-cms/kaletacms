<?php

declare(strict_types=1);

namespace Kaleta\Mcp;

/**
 * Every MCP tool once (2.1): its English name => [access, the extension it needs ('' = none)].
 *
 * Access is what an MCP client should confirm with the user: "read" changes nothing, "draft" only saves drafts (a build,
 * a hidden page, a draft look, a news draft – publishing it needs the publishing permission – and since 3.2 a hidden
 * collection item, a proposed exception to the opening hours, an enquiry triage, a notebook note and – 3.26 – a team comment
 * without @mentions; the tool itself
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
        // Moving a site (2.7). Stays 'read' (3.15, N315-9; the class and annotations are part of the MCP contract): it changes
        // nothing on the site – its outbound fetches are IP-pinned reads of the old public site (ImageDownloader, OPEN_WORLD
        // sends it through run_destructive_tool) and its state files hold only the report; administrators only
        'migration_report' => ['read', ''],
        // 3.33: an old page as section HTML with its colours and fonts – changes nothing here (IP-pinned reads of the old public
        // site, like migration_report); administrators whose connection may use import_website (Handlers\MigrationTools)
        'read_old_page' => ['read', ''],
        // 3.36: the work queue of a move – lists the imported pages whose layout waits and takes pages off it (Core\ImportLayout);
        // the mark lives in ka_import_mapa, never on the page, so it is a draft-class tool and site-neutral
        'layout_queue' => ['draft', ''],
        // 3.36 review L2: the same queue read only – on any connection, with no change-log row (layout_queue ticks pages off)
        'list_layout_queue' => ['read', ''],
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
        // 3.22 (C10): a real e-mail to the customer of an enquiry – it cannot be taken back, so a full-access connection, confirm=true
        'reply_to_enquiry' => ['destructive', 'poptavky'],
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
        'fleet_inbox' => ['read', 'fleet'],
        'fleet_report' => ['read', 'fleet'], // 3.17: the monthly report per customer (Fleet\Report)
        'fleet_today' => ['read', 'fleet'], // 3.29: what needs attention today (Fleet\Checks::today)
        'fleet_health' => ['read', 'fleet'], // 3.29: the health matrix and the workload counts
        'fleet_plan' => ['read', 'fleet'], // 3.30: what to fix on which site and through which connector (Fleet\Plan)
        'fleet_inventory' => ['read', 'fleet'], // 3.30: versions, PHP, database, server, features of every site (Fleet\Inventory) and the rollout
        'fleet_calendar' => ['read', 'fleet'], // 3.30: certificate and domain dates, recovery keys (Fleet\Calendar)
        // Requests to Claude (2.15): update_request changes nothing on the site – a note and a status on a request, whose
        // work is done as drafts – so a drafts-only connection may answer the requests it works on
        'list_requests' => ['read', ''],
        // Waiting for you (3.2): drafts and proposals that wait for a person
        'list_pending_review' => ['read', ''],
        // The archive of the monthly reports (3.16): administrators only (Handlers\ReportTools)
        'list_monthly_reports' => ['read', ''],
        'get_monthly_report' => ['read', ''],
        'update_request' => ['draft', ''],
        // Scheduled runs (2.17): a routine in Claude asks what is due and reports what it did as drafts – report_agent_run
        // only records the run, so a drafts-only connection (the one the runs are meant for) may call both.
        // get_due_agent_runs keeps 'read' and readOnlyHint (3.15, N315-9; the contract): it changes no content – it marks
        // runs as handed out – and refuses read-only connections itself (Handlers\AgentRunTools), which could not report them
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
        // The team's work (3.24): what waits for the connected user, and who is responsible – never an access limit
        'my_work' => ['read', ''],
        'list_colleagues' => ['read', ''], // 3.37: who works here (Core\Colleagues)
        'assign' => ['write', ''],
        // Comment threads of the team (3.26): an internal comment on an enquiry, a page or an item – never shown on the site, so
        // a drafts-only connection may write one too, without @mentions (they e-mail colleagues; Handlers\CommentTools)
        'list_comments' => ['read', ''],
        'add_comment' => ['draft', ''],
        // Tasks and follow-ups (3.27, Core\Tasks): the team's to-dos – never shown on the site
        'list_tasks' => ['read', ''],
        'save_task' => ['write', ''],
        'complete_task' => ['write', ''],
        // Contacts and companies (3.28, Core\Contacts): the customer memory of the site – personal data, never shown on the
        // site and never journaled for undo (AgentJournal::PERSONAL_TABLES); merging two contacts cannot be taken back
        'list_contacts' => ['read', ''],
        'get_contact' => ['read', ''],
        'save_contact' => ['write', ''],
        'log_activity' => ['write', ''],
        'merge_contacts' => ['destructive', ''],
        // The lean pipeline (3.31, Core\Deals): deals point to customers – never shown on the site and never journaled for
        // undo; deleting a deal stays in the administration (administrators)
        'list_deals' => ['read', ''],
        'save_deal' => ['write', ''],
        'move_deal' => ['write', ''],
        'pipeline_summary' => ['read', ''],
        // Job applications (3.34, Core\Applications): personal data of applicants for people with the section Applications, only
        // while the site lets Claude read them (applications_claude); a CV is never passed; never journaled for undo
        'list_applications' => ['read', 'applications'],
        'get_application' => ['read', 'applications'],
        'update_application' => ['draft', 'applications'],
        'reply_to_application' => ['destructive', 'applications'],
        // Internal prices (3.38, Core\Prices): business secrets for people with the section Quotes – no other tool returns a price;
        // never journaled for undo (save_price returns the previous values)
        'list_prices' => ['read', 'quotes'],
        'save_price' => ['write', 'quotes'],
        // Quotes (3.38 B, Core\Quotes): customer data and prices for people with the section Quotes; save_quote saves drafts
        // only (a draft never leaves the site, so a drafts-only connection may), mark_quote changes a status; never journaled
        'list_quotes' => ['read', 'quotes'],
        'get_quote' => ['read', 'quotes'],
        'save_quote' => ['draft', 'quotes'],
        'mark_quote' => ['write', 'quotes'],
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
        'read_old_page' => 'Read an old page with its look',
        'layout_queue' => 'List and tick off layouts to rebuild',
        'list_layout_queue' => 'List layouts to rebuild',
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
        'reply_to_enquiry' => 'Send a reply to an enquiry',
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
        'fleet_inbox' => 'List what waits across the fleet',
        'fleet_report' => 'Get a monthly fleet report',
        'fleet_today' => 'List what needs attention today',
        'fleet_health' => 'Get the health matrix of the fleet',
        'fleet_plan' => 'Plan fixes across the fleet',
        'fleet_inventory' => 'List the inventory of the fleet',
        'fleet_calendar' => 'List the expiry dates of the fleet',
        'list_requests' => 'List requests to Claude',
        'list_pending_review' => 'List what waits for review',
        'list_monthly_reports' => 'List archived monthly reports',
        'get_monthly_report' => 'Get a monthly report',
        'update_request' => 'Answer a request',
        'get_due_agent_runs' => 'Get due scheduled runs',
        'report_agent_run' => 'Report a scheduled run',
        'translation_status' => 'Get translation status',
        'list_newsletters' => 'List newsletters',
        'draft_newsletter' => 'Draft a newsletter',
        'send_test_newsletter' => 'Send a test newsletter to yourself',
        'send_newsletter' => 'Send a newsletter',
        'delete_newsletter' => 'Delete a newsletter',
        'my_work' => 'Get my work',
        'list_colleagues' => 'List colleagues',
        'assign' => 'Assign a responsible person',
        'list_comments' => 'List the comments of a record',
        'add_comment' => 'Add a comment',
        'list_tasks' => 'List tasks',
        'save_task' => 'Create or change a task',
        'complete_task' => 'Complete a task',
        'list_contacts' => 'List contacts',
        'get_contact' => 'Get a contact with its timeline',
        'save_contact' => 'Create or change a contact',
        'log_activity' => 'Log a call, meeting or note',
        'merge_contacts' => 'Merge two contacts',
        'list_deals' => 'List deals',
        'save_deal' => 'Create or change a deal',
        'move_deal' => 'Move a deal to another stage',
        'pipeline_summary' => 'Get the pipeline summary',
        'list_applications' => 'List job applications',
        'get_application' => 'Get a job application',
        'update_application' => 'Update a job application',
        'reply_to_application' => 'Send a reply to a job applicant',
        'list_prices' => 'List internal prices',
        'save_price' => 'Save internal prices',
        'list_quotes' => 'List quotes',
        'get_quote' => 'Get a quote',
        'save_quote' => 'Create or change a draft quote',
        'mark_quote' => 'Mark a quote accepted or declined',
    ];

    /**
     * Tools that reach outside the site during the call (3.8, MCP openWorldHint), by their English names. Verified in the
     * code: a download from an address the caller gives (upload_file with url, import_website, migration_report, read_old_page,
     * import_wordpress with url and its images, save_collection_items with media by URL – Core\ImageDownloader), and an
     * e-mail to people outside the site's users (subscribers, customers of an enquiry or a booking). Not on the list:
     * deliveries the site owner set up that run later in the background (webhooks, IndexNow, the Business Profile and CRM
     * integrations – Core\Connectors queue), the link check (a background job; list_broken_links reads its results),
     * list_connectors (status from the database) and e-mails to the site's own users (update_request, send_test_newsletter).
     */
    public const array OPEN_WORLD = ['upload_file', 'import_website', 'migration_report', 'read_old_page', 'import_wordpress', 'save_collection_items', 'send_newsletter',
        'request_testimonial', 'reply_to_enquiry', 'cancel_booking', 'confirm_booking', 'decline_booking', 'propose_booking_times', 'reply_to_application'];

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
        'delete_hours_exception', 'cancel_booking', 'confirm_booking', 'decline_booking', 'delete_newsletter', 'assign', 'complete_task', 'move_deal', 'update_application', 'layout_queue', 'save_price', 'mark_quote'];

    /**
     * Write tools that never change what visitors see (3.24, T1): Mcp\Server keeps the page cache after them, every other
     * write tool clears it. Enquiries and their replies, testimonial requests, the notebook, requests to Claude and the
     * runs of its schedules, comments on drafts, the saved-sections library, the list of addresses not found, social post
     * drafts and newsletters (an e-mail, no page on the site), and the team's comments (3.26). Not here, so they clear: everything that saves content,
     * builds, the look, settings, media, bookings (their free times are on the page) and erase_personal_data (it deletes
     * bookings). tools/unit-tests.php classifies every write tool, so a new one has to choose; an add-on's tool clears.
     */
    public const array SITE_NEUTRAL = ['assign', 'update_enquiry', 'delete_enquiry', 'import_enquiries', 'request_testimonial', 'reply_to_enquiry', 'write_notebook',
        'delete_notebook_entry', 'update_request', 'report_agent_run', 'resolve_draft_comment', 'save_section', 'delete_section', 'ignore_not_found',
        'update_social_draft', 'draft_newsletter', 'send_test_newsletter', 'send_newsletter', 'delete_newsletter', 'add_comment', 'save_task', 'complete_task',
        'save_contact', 'log_activity', 'merge_contacts', 'save_deal', 'move_deal', 'update_application', 'reply_to_application', 'layout_queue', 'save_price', 'save_quote', 'mark_quote'];

    /**
     * 3.36 review L2: write tools whose call without this argument only reads – then Mcp\Server writes no change-log row,
     * counts nothing against the hourly limit and opens no undo journal (layout_queue without done lists the queue).
     */
    public const array READS_WITHOUT = ['layout_queue' => 'done'];

    /**
     * Does this call of a tool only read (READS_WITHOUT: the argument is missing, null or an empty list)?
     *
     * @param array<string, mixed> $arguments
     */
    public static function readsOnly(string $name, array $arguments): bool
    {
        $key = self::READS_WITHOUT[self::english($name) ?? ''] ?? null;

        return $key !== null && in_array($arguments[$key] ?? null, [null, []], true);
    }

    /** May the page cache stay after this write tool (by either name)? */
    public static function isSiteNeutral(string $name): bool
    {
        return in_array(self::english($name) ?? '', self::SITE_NEUTRAL, true);
    }

    /**
     * The tool set of a connection (3.10): "all" lists every tool the connection may use (as before 3.10); "essentials"
     * lists ESSENTIALS and the META tools, which reach the rest on demand – a much shorter tools/list in every
     * conversation; "work" (3.26, owner decision D-W10) lists WORK and the META tools – for an employee's own Claude: their
     * work, enquiries, comments and requests, with the builder a find_tools away; "migration" (3.36) lists MIGRATION – moving
     * a site; "fleet" (3.36) lists FLEET – the fleet console, offered only where the fleet extension is on (SET_NEEDS).
     * Existing connections keep what they have (a row without a known set is "all"); new Claude connections start on the
     * essentials (the consent screen preselects them). The choice is on the connection row like its access and only decides
     * what tools/list shows first – never what the connection may run (Catalog::allows and the user's role decide that).
     *
     * 3.36 THE RULE: every tool of TOOLS is declared in at least one of ESSENTIALS, WORK, MIGRATION and FLEET, or in
     * ON_DEMAND (listed only by "all", one find_tools away everywhere else) – never in ON_DEMAND and a focused set at once.
     * A new tool goes where the people who need it first will look; ESSENTIALS take no new tool (the owner's rule). Each
     * focused set's tools/list stays within its BUDGETS bytes on a full connection with every feature on.
     * tools/unit-tests.php fails a tool without a set and a set over its budget (tools/tests/unit/2550-…).
     */
    public const array TOOL_SETS = ['all', 'essentials', 'work', 'migration', 'fleet'];

    /** 3.36: the most bytes of a focused set's tools/list (its tools plus META, full access, every feature on). */
    public const array BUDGETS = ['essentials' => 49000, 'work' => 49000, 'migration' => 49000, 'fleet' => 49000];

    /** 3.36: a tool set offered only where its extension is on (a stored one keeps working anywhere – it only lists less). */
    public const array SET_NEEDS = ['fleet' => 'fleet'];

    /**
     * The core tools an essentials connection lists (3.10): pages, the builder, the menu and the look, collections, media,
     * requests and enquiries, facts, the audit, the change log and undo_agent_session (the safety net of a Claude session)
     * – what the server instructions, the prompts and the plugin skills reach for first. tools/list of these plus META
     * stays under 50 KB (tools/unit-tests.php checks it); every other tool is one runner away (Discovery::runner).
     */
    public const array ESSENTIALS = ['site_info', 'list_pages', 'get_page', 'create_page', 'update_page', 'get_menu', 'save_menu', 'builder_schema', 'get_build',
        'edit_build', 'build_from_html', 'save_build', 'insert_section', 'publish_build', 'preview_link', 'list_collections', 'list_collection_items',
        'save_collection_item', 'list_media', 'upload_file', 'update_media', 'list_requests', 'update_request', 'list_enquiries', 'list_facts', 'save_fact',
        'update_design_system', 'publish_look', 'site_audit', 'list_changes', 'undo_agent_session', 'read_notebook'];

    /**
     * The tools a "work" connection lists (3.26, T12 of the workspace architecture): what an employee does with their own
     * Claude – what waits for them, enquiries and their replies, the team's comments, who is responsible, their e-mail
     * signature, requests to Claude, the notebook, reading pages and a draft preview, collection items. Like the essentials,
     * every other tool is one find_tools away; the list plus META stays under 49,000 bytes (tools/unit-tests.php).
     */
    public const array WORK = ['site_info', 'my_work', 'list_enquiries', 'update_enquiry', 'triage_enquiries', 'reply_to_enquiry', 'list_comments', 'add_comment',
        'assign', 'get_email_signature', 'list_colleagues', 'list_requests', 'update_request', 'read_notebook', 'write_notebook', 'list_pages', 'get_page', 'preview_link',
        'list_collections', 'list_collection_items', 'save_collection_item', 'list_tasks', 'save_task', 'complete_task', 'list_contacts', 'get_contact', 'save_contact',
        'log_activity', 'merge_contacts', 'list_deals', 'save_deal', 'move_deal', 'pipeline_summary', 'list_applications', 'get_application', 'update_application', 'reply_to_application'];

    /**
     * The tools a "migration" connection lists (3.36): moving a site from another platform – the imports, read_old_page and
     * the builder to rebuild the layout, redirects, media, the menu and the look, collections, the migration report and the
     * audit, the notebook and the safety net (collections, classes, save_build and notes are a find_tools away – the budget).
     * read_old_page and migration_report stay full-connection tools (NEEDS).
     */
    public const array MIGRATION = ['site_info', 'read_notebook', 'list_pages', 'get_page', 'create_page', 'update_page', 'get_menu', 'save_menu', 'builder_schema',
        'get_build', 'edit_build', 'build_from_html', 'preview_link', 'list_collections', 'list_media', 'upload_file', 'import_website', 'update_design_system',
        'list_redirects', 'save_redirect', 'save_redirects', 'site_audit', 'migration_report', 'read_old_page', 'import_wordpress', 'import_enquiries',
        'list_changes', 'undo_agent_session', 'layout_queue', 'list_layout_queue',
    ];

    /**
     * The tools a "fleet" connection lists (3.36): the fleet console – the sites that report here, what waits across them,
     * today, health, inventory, the calendar, the plan and the monthly reports – with this site's own health, events, work,
     * tasks, comments, requests and notebook. It only reads the other sites; changes go through each site's own connection.
     */
    public const array FLEET = ['site_info', 'list_sites', 'get_site', 'fleet_inbox', 'fleet_report', 'fleet_today', 'fleet_health', 'fleet_plan', 'fleet_inventory',
        'fleet_calendar', 'get_health', 'list_events', 'read_notebook', 'write_notebook', 'my_work', 'list_tasks', 'save_task', 'complete_task', 'list_comments',
        'add_comment', 'list_requests', 'update_request', 'list_monthly_reports', 'get_monthly_report'];

    /**
     * 3.36: the tools listed only by "all" – every focused set reaches them with find_tools and a runner. Declared here so
     * that each tool says where it belongs (the rule above); a tool may move to a focused set later.
     */
    public const array ON_DEMAND = ['trash_page', 'list_trash', 'restore_from_trash', 'list_build_versions', 'restore_build_version', 'discard_draft', 'save_section', 'delete_section',
        'list_components', 'save_component', 'delete_component', 'list_site_parts', 'save_part_variant', 'apply_part_template', 'list_draft_comments',
        'resolve_draft_comment', 'list_classes', 'save_classes', 'discard_look', 'list_look_versions', 'restore_look_version', 'create_collection',
        'update_collection', 'delete_collection', 'save_collection_items', 'delete_collection_item', 'list_item_versions', 'restore_item_version',
        'list_collection_categories', 'save_collection_category', 'delete_collection_category', 'list_news', 'get_news', 'create_news', 'update_news',
        'trash_news', 'list_categories', 'create_category', 'update_category', 'delete_category', 'delete_media', 'list_media_without_alt', 'delete_enquiry',
        'find_personal_data', 'erase_personal_data', 'list_popups', 'save_popup', 'delete_popup', 'update_settings', 'ignore_not_found', 'list_broken_links',
        'suggest_internal_links', 'list_agent_sessions', 'get_stats', 'delete_fact', 'find_claims', 'list_hours', 'list_collection_presets',
        'request_testimonial', 'get_blueprint', 'list_connectors', 'processing_record', 'accessibility_statement', 'get_social_drafts',
        'delete_notebook_entry', 'update_social_draft', 'apply_blueprint', 'remove_blueprint', 'export_blueprint', 'list_notice_log', 'save_hours_exception',
        'delete_hours_exception', 'list_bookings', 'booking_availability', 'save_booking_service', 'save_booking_staff', 'cancel_booking', 'confirm_booking',
        'decline_booking', 'propose_booking_times', 'list_pending_review', 'get_due_agent_runs', 'report_agent_run', 'translation_status', 'list_newsletters',
        'draft_newsletter', 'send_test_newsletter', 'send_newsletter', 'delete_newsletter',
        'list_prices', 'save_price', // 3.38: internal prices – one find_tools away (design A8: outside the essentials and the work set)
        'list_quotes', 'get_quote', 'save_quote', 'mark_quote']; // 3.38 B: quotes – the work set has no room left under its budget (and stays unchanged)

    /**
     * The focused sets that list a tool by its English name (3.36): [] for a tool of ON_DEMAND (or an add-on's tool).
     *
     * @return list<string>
     */
    public static function setsOf(string $tool): array
    {
        $sets = [];
        foreach (self::TOOL_SETS as $set) {
            if (in_array($tool, self::listOf($set) ?? [], true)) {
                $sets[] = $set;
            }
        }

        return $sets;
    }

    /**
     * The tool sets a person may choose for a connection on this site (3.36): every set, without a set whose extension is
     * off (SET_NEEDS) – unless it is the connection's current one, which stays shown. Without settings: no such set.
     *
     * @return list<string>
     */
    public static function offered(?\Kaleta\Core\Settings $settings, string $current = ''): array
    {
        $offered = [];
        foreach (self::TOOL_SETS as $set) {
            if ($set === $current || !isset(self::SET_NEEDS[$set]) || ($settings !== null && \Kaleta\Core\Extensions::isEnabled($settings, self::SET_NEEDS[$set]))) {
                $offered[] = $set;
            }
        }

        return $offered;
    }

    /**
     * The tools a tool set lists before the META tools: null = every tool (all).
     *
     * @return list<string>|null
     */
    public static function listOf(string $set): ?array
    {
        return match (self::toolSet($set)) {
            'essentials' => self::ESSENTIALS,
            'work' => self::WORK,
            'migration' => self::MIGRATION,
            'fleet' => self::FLEET,
            default => null,
        };
    }

    /**
     * The meta tools (3.10): English name => [access, title, open world]. They are listed only for an essentials connection
     * but callable on any; they are not site tools – no Tools method, no Translator entry – and Mcp\Server runs them itself.
     * Three runners, each with annotations that hold for every tool it runs (3.10 audit, N310-2 and N310-4), so a client's
     * "always allow" on one never covers a more dangerous class: run_read_tool reads inside the site, run_tool saves drafts
     * or changes the site without removing, sending or reaching outside, run_destructive_tool takes the rest (destructive or
     * open-world). Each refuses the other classes (Discovery::runner); the inner tool is checked like a direct call.
     *
     * @var array<string, array{0: 'read'|'draft'|'destructive', 1: string, 2: bool}>
     */
    public const array META = [
        'find_tools' => ['read', 'Find more tools', false],
        'run_read_tool' => ['read', 'Run a read-only tool by name', false],
        'run_tool' => ['draft', 'Run a tool that saves or changes', false],
        'run_destructive_tool' => ['destructive', 'Run a destructive or outside tool', true],
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

    /**
     * 3.33 review L8: a tool that a connection may run (and sees in tools/list) only when it may run another one too –
     * read_old_page reads an old site the way import_website does, so a drafts-only or read-only connection never sees it.
     */
    public const array NEEDS = ['read_old_page' => 'import_website', 'migration_report' => 'import_website'];

    /**
     * What a connection hears when it calls a tool of NEEDS it may not run (the same words as the tool's own refusal).
     * 3.35 (N104): migration_report reads the old site like read_old_page, so it is for full connections too.
     */
    public const array NEEDS_REFUSAL = ['read_old_page' => 'Old pages are read by an administrator whose connection may import a website (full access).',
        'migration_report' => 'The migration report reads the old site, so it runs only over a connection with full access.'];

    /**
     * 3.35 (N104): the network rule per connection level – only a full connection makes the site download an address the
     * caller gives. A drafts-only or read-only connection never does: upload_file takes the file content (data) but no
     * url, save_collection_items reuses media downloaded before but downloads nothing new, migration_report and
     * read_old_page are hidden (NEEDS), import_website and import_wordpress are write tools anyway, and an add-on's tool
     * that declares openWorld is not offered to it (allows()). Without a connection (the admin, a background job) the
     * site downloads as before.
     *
     * @param array{access: string}|null $connection Auth::connection()
     */
    public static function mayFetch(?array $connection): bool
    {
        return ($connection['access'] ?? 'full') === 'full';
    }

    /** 3.35 (N104): what a drafts-only or read-only connection hears when it asks the site to download an address. */
    public const string FETCH_REFUSAL = 'This connection does not download addresses – only a connection with full access does. Send the file content in base64 (data) instead, or ask the user to connect Claude with full access.';

    /** May a connection with this access run the tool? */
    public static function allows(string $connectionAccess, string $name): bool
    {
        foreach (self::NEEDS as $tool => $needed) {
            if (($name === $tool || $name === Translator::czech($tool)) && !self::allows($connectionAccess, $needed)) {
                return false;
            }
        }
        // 3.35 (N104): an add-on's tool that reaches outside the site may download an address – full connections only
        if ($connectionAccess !== 'full' && !isset(self::META[$name]) && (\Kaleta\Extension\Registry::get()->tool($name)['openWorld'] ?? false)) {
            return false;
        }

        return in_array(self::access($name), self::CONNECTION_ACCESS[$connectionAccess] ?? self::CONNECTION_ACCESS['read'], true);
    }

    /** The method of Tools that runs the tool: site_info => toolSiteInfo. */
    public static function method(string $english): string
    {
        return 'tool' . str_replace('_', '', ucwords($english, '_'));
    }
}
