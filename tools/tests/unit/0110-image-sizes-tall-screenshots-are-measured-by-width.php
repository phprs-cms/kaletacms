<?php

declare(strict_types=1);

use Kaleta\Core\Files;

/* ---------- image sizes: tall screenshots are measured by width, srcset carries the real widths ---------- */
check('Obrazky::pomer: fotka na šířku i na výšku podle delší strany', [Kaleta\Core\Images::ratio(4000, 3000, 2000), Kaleta\Core\Images::ratio(3000, 4000, 2000)], [0.5, 0.5]);
check('Obrazky::pomer: celostránkový snímek podle šířky, výška nejvýš trojnásobek', [Kaleta\Core\Images::ratio(1440, 5000, 2000), round(Kaleta\Core\Images::ratio(1440, 5000, 1200), 3), Kaleta\Core\Images::ratio(1000, 9000, 2000)], [1.0, 0.72, 6000 / 9000]);
$imageFolder = dirname(__DIR__, 3) . '/media/' . date('Y/m');
@mkdir($imageFolder, 0775, true);
$imageTmp = tempnam(sys_get_temp_dir(), 'obr');
$imagePng = imagecreatetruecolor(1440, 5000);
imagepng($imagePng, $imageTmp);
$imageSaved = Kaleta\Core\Images::saveFile($imageTmp, 'celostrankovy-snimek.png');
$imageSrcset = Kaleta\Core\Images::srcset($imageSaved['obr_poloha'], '');
check('Obrazky: vysoký snímek si nechá šířku, srcset má skutečné šířky variant', [$imageSaved['obr_width'], $imageSaved['obr_height'], (bool) preg_match('/-nahled\.png 553w, .*-1200\.png 1037w, .*\.png 1440w$/', $imageSrcset)], [1440, 5000, true]);
Kaleta\Core\Images::delete($imageSaved['obr_poloha'], $imageSaved['nahl_poloha']);
@unlink($imageTmp);

check('Styl::zCss: barva rámečku (i pro stav hover)', Kaleta\Builder\Style::fromCss('border-color', '#F6F4EE'), ['barva_ramecku' => '#F6F4EE']);
check('Styl::zCss: zkratka background jen s barvou', Kaleta\Builder\Style::fromCss('background', '#EFECE5'), Kaleta\Builder\Style::fromCss('background-color', '#EFECE5'));
check('Styl::zCss: background s obrázkem zůstane mimo', Kaleta\Builder\Style::fromCss('background', 'url(a.png) no-repeat'), null);
$buildCheck = Kaleta\Builder\Check::builds(['v' => 1, 'deti' => [['id' => 's', 'typ' => 'sekce', 'deti' => [
    ['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => 'Služby']],
    ['id' => 'b', 'typ' => 'nadpis', 'znacka' => 'h4', 'obsah' => ['text' => 'Detail']],
    ['id' => 'c', 'typ' => 'tlacitko', 'obsah' => ['text' => 'Poptat', 'odkaz' => '#']],
    ['id' => 'd', 'typ' => 'obrazek', 'obsah' => ['src' => 'media/a.jpg', 'alt' => '']],
    ['id' => 'e', 'typ' => 'obrazek', 'obsah' => ['src' => '{{foto}}', 'alt' => '']],
    ['id' => 'f', 'typ' => 'nadpis', 'znacka' => 'p', 'obsah' => ['text' => '01']],
]]]], true);
check('Kontrola stavby: tlačítko bez odkazu, obrázek bez popisu, chybějící h1 a přeskočená úroveň', array_column($buildCheck, 'id'), ['c', 'd', 'a', 'b']);
check('Kontrola stavby: části webu osnovu nadpisů nehlídají', Kaleta\Builder\Check::builds(['v' => 1, 'deti' => [['id' => 'a', 'typ' => 'nadpis', 'znacka' => 'h3', 'obsah' => ['text' => 'Kontakt']]]], false), []);
check('Poptávka: kampaň z utm_* adresy stránky s formulářem', Kaleta\Front\Forms::campaign('https://example.com/akce?utm_source=google&utm_medium=cpc&utm_campaign=jaro&gclid=x&utm_term[]=a', 'https://example.com'), 'utm_source=google&utm_medium=cpc&utm_campaign=jaro');
check('Poptávka: kampaň jen z vlastního webu', Kaleta\Front\Forms::campaign('https://jiny.cz/?utm_source=x', 'https://example.com'), '');
check('Poptávka: kampaň pro člověka', Kaleta\Front\Forms::campaignText('utm_source=google&utm_medium=cpc&utm_campaign=jaro'), 'google / cpc / jaro');
check('Poptávka: více příjemců i skrytá kopie', Kaleta\Front\Forms::recipients('admin@example.com, info@example.com;Info@example.com', 'skryty@example.com admin@example.com', 'web@example.com'), [['admin@example.com', 'info@example.com'], ['skryty@example.com']]);
check('Poptávka: neplatné adresy se vynechají, žádná platná = e-mail webu', Kaleta\Front\Forms::recipients('nekdo, @x', '', 'web@example.com'), [['web@example.com'], []]);
check('Poptávka: bez příjemce i e-mailu webu dostanou zprávu skrytí příjemci', Kaleta\Front\Forms::recipients('', 'a@example.com', ''), [['a@example.com'], []]);
check('Poptávka: bez příjemců nikdo', Kaleta\Front\Forms::recipients('', '', ''), [[], []]);
check('Pošta: seznam adres oddělených čárkou', Kaleta\Core\Mail::addresses('a@example.com, B@example.com,b@example.com'), ['a@example.com', 'B@example.com']);
check('Pošta: jedna neplatná adresa odmítne celý seznam (žádná vložená hlavička)', Kaleta\Core\Mail::addresses("a@example.com, b@example.com\r\nBcc: c@example.com"), []);
// Parity guard: every admin action is a read, has an MCP tool, or is admin only on purpose (with the reason). A new action
// that is none of these fails the test – decide whether Claude can do it too (MCP is the main way to work with a site).
$readOnly = 'read';
$builderParity = [
    'build_ai_section' => 'admin: AI helper of the builder – Claude writes the content itself', 'build_ai_text' => 'admin: AI helper of the builder – Claude writes the content itself',
    'build_class' => 'save_classes', 'build_delete_section' => 'delete_section', 'build_discard' => 'discard_draft', 'build_publish' => 'publish_build',
    'build_restore' => 'restore_build_version', 'build_save' => 'save_build', 'build_save_section' => 'save_section', 'build_section' => 'insert_section',
    'build_share' => 'preview_link', 'build_versions' => $readOnly, 'builder' => $readOnly, 'build_comment_resolve' => 'resolve_draft_comment',
    'build_package' => $readOnly, 'build_choices' => $readOnly, 'build_compare' => $readOnly, 'build_paste' => 'admin: paste from the system clipboard of another Kaleta site – Claude inserts elements with save_build or edit_build and brings classes with save_classes',
];
$settingsParity = ['list' => $readOnly, 'save' => 'update_settings', 'download_backup' => $readOnly, 'backup' => 'admin: backups', 'restore_backup' => 'admin: backups',
    'delete_backup' => 'admin: backups', 'media_backup' => 'admin: backups', 'delete_log' => 'admin: error log', 'check' => 'admin: updates', 'update' => 'admin: updates', 'forget_updates' => 'admin: updates', 'update_major' => 'admin: updates (a major version only on an explicit action, 3.15)',
    'vault_move' => 'admin: the encryption key goes into config.php – never through a connection (3.15, N42)',
    'backup_key' => 'admin: the recovery key of the encrypted off-site backups is shown only to an administrator, never through a connection (3.28, D-W12)',
    'backup_key_download' => 'admin: the recovery key of the encrypted off-site backups is shown only to an administrator, never through a connection (3.28, D-W12)',
    'backup_key_confirm' => 'admin: only the administrator who saved the recovery key can confirm it (3.28, D-W12)',
    'backup_key_new' => 'admin: a new recovery key is the administrator\'s security decision (3.28, D-W12)',
    'backup_key_use' => 'admin: a kept recovery key is put back by the administrator, never through a connection (3.28, D-W12)',
    'new_signing_key' => 'admin: a new signing key withdraws every signed link of the site – a security decision of the administrator (3.19.1, N84)',
    'config_permissions' => 'admin: the file permissions of config.php are the server\'s, never set through a connection (3.19.1, N83)', 'user_ini' => 'admin: a .user.ini with larger upload limits is a file of the server, never written through a connection (3.22, C14)', 'instructions_seen' => 'admin: an administrator has looked at the changed instructions for Claude (3.15, N44)',
    'delete_backup' => 'admin: backups', 'media_backup' => 'admin: backups', 'delete_log' => 'admin: error log', 'check' => 'admin: updates', 'update' => 'admin: updates', 'forget_updates' => 'admin: updates',
    'test_mail' => 'admin: mail server settings', 'domain_check' => 'admin: the domain and mail watch runs on its own once a day', 'test_webhook' => 'admin: webhooks (addresses and the signing secret stay out of MCP)',
    'retry_webhook' => 'admin: webhooks (addresses and the signing secret stay out of MCP)', 'new_webhook_secret' => 'admin: webhooks (addresses and the signing secret stay out of MCP)',
    'firewall_unblock' => 'admin: the firewall is a security setting (2.8) – not over MCP',
    'hours_add' => 'save_hours_exception', 'hours_delete' => 'delete_hours_exception', 'hours_sign' => $readOnly,
    'hours_apply' => 'save_hours_exception', 'hours_discard' => 'delete_hours_exception', // a proposal from a drafts-only connection (3.2)
    'fleet_pair' => 'admin: which console a site reports to is a security decision (2.9)', 'fleet_send' => 'admin: the site reports every hour on its own',
    'fleet_updates' => 'admin: who decides about updates is a security decision (2.9)', 'fleet_unpair' => 'admin: which console a site reports to is a security decision (2.9)',
    'fleet_kit' => 'admin: receiving the console\'s design kit is an opt-in of the site (2.16); site_info reports the version that arrived',
    'fleet_titles' => 'admin: what the site tells its console is the administrator\'s decision (3.16)',
    'report_preview' => $readOnly, 'report_send' => 'admin: the monthly report goes to e-mail addresses that stay out of MCP (2.9) – Claude reads the same numbers with get_stats and get_health',
    'report_view' => 'get_monthly_report', 'report_download' => 'get_monthly_report', // 3.16: the archive – Claude reads the same data and print_url
    'report_rebuild' => 'admin: rebuilding an archived month replaces the stored report with what the data still hold (3.16) – the administrator decides after the warning',
    'cookie_scan' => 'admin: the cookie scan runs daily on its own (2.14); Claude reads the table in processing_record', 'processing_record' => 'processing_record',
    'accessibility_statement' => 'admin: the draft page is the administrator’s step (2.14); Claude reads the text with accessibility_statement',
    'time_zone' => 'admin: the time zone and converting the saved dates are the administrator’s decision (3.12) – update_settings does not change the zone'];
// 3.28: the MCP tools of Customers come with agent A's branch; until both are merged the admin actions name them as admin-only
// (after the merge this falls back to the plain tool names – the lead may inline them)
$contactTool = fn (string $tool): string => in_array($tool, Kaleta\Mcp\Translator::names(), true) ? $tool : 'admin: ' . $tool . ' (the MCP tool of 3.28)';
// 3.31: the MCP tools of the Pipeline come with agent A's branch – the same fallback until both are merged
$dealTool = fn (string $tool): string => in_array($tool, Kaleta\Mcp\Translator::names(), true) ? $tool : 'admin: ' . $tool . ' (the MCP tool of 3.31)';
$parity = [
    'pages' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'export' => $readOnly, 'save' => 'update_page', 'save_text' => 'update_page',
        'delete' => 'trash_page', 'restore' => 'restore_from_trash', 'delete_permanently' => 'admin: the trash empties itself after 30 days',
        'duplicate' => 'admin: a copy of a page – Claude creates the page and saves the build', 'import' => 'admin: upload of a page export file',
        'build_text' => 'admin: back from the builder to a text page', 'restore_version' => 'admin: text revisions of a text page',
        'translations' => 'translation_status', 'bulk' => 'update_page',
        'owner' => 'assign', // 3.24: the responsible person of a published page for someone who may not save its settings
        'comment' => 'admin: the team’s thread on a page (3.26) – a person writes it; Claude gets the comments over MCP with the work tools',
        'task' => 'save_task'], // 3.27: the tasks of a page in its settings – Claude saves and completes them with save_task / complete_task
    'news' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'compare' => $readOnly, 'versions' => $readOnly, 'search_json' => $readOnly,
        'save' => 'update_news', 'save_text' => 'update_news', 'delete' => 'trash_news', 'restore' => 'restore_from_trash', 'delete_permanently' => 'admin: the trash empties itself after 30 days',
        'draft' => 'admin: autosave of the editor', 'duplicate' => 'admin: a copy of a news item – Claude creates a new one', 'links' => 'admin: link check runs on its own',
        'assistant' => 'admin: AI helper – Claude writes the text itself', 'translate' => 'admin: AI translation – Claude translates and uses create_news', 'bulk' => 'update_news',
        'social_save' => 'update_social_draft', 'social_posted' => 'admin: the person who posted it marks it', 'social_suggest' => 'admin: AI helper – Claude polishes the drafts itself with update_social_draft'],
    'broken_links' => ['list' => 'list_broken_links', 'links' => 'admin: link check runs on its own'], // 3.20 (C20): the News screen under Site care; links = "Check again" posting back to it (N320-5)
    'collections' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'preset' => 'create_collection', 'edit' => $readOnly, 'items' => $readOnly, 'item' => $readOnly,
        'publish_page' => 'update_page', // 3.20 (C5): publishing the listing page from the items screen
        'save' => 'update_collection', 'delete' => 'delete_collection', 'save_item' => 'save_collection_item', 'delete_item' => 'delete_collection_item',
        'restore_item' => 'restore_from_trash', 'delete_item_permanently' => 'admin: the trash empties itself after 30 days', 'duplicate_item' => 'admin: a copy of an item – Claude saves a new one',
        'restore_item_version' => 'restore_item_version', 'signature' => 'get_email_signature', 'notice_log' => 'list_notice_log', 'bulk_items' => 'save_collection_item',
        // 3.7: the CSV/JSON import of items – the same rows Claude sends with save_collection_items (dry_run = the preview)
        'import' => $readOnly, 'import_preview' => $readOnly, 'import_upload' => 'save_collection_items', 'import_map' => 'save_collection_items',
        'import_progress' => 'save_collection_items', 'import_delete' => 'admin: removing the record of an import',
        // 3.7: collection categories
        'categories' => 'list_collection_categories', 'category' => $readOnly, 'save_category' => 'save_collection_category', 'delete_category' => 'delete_collection_category',
        'comment' => 'admin: the team’s thread on an item (3.26) – a person writes it; Claude gets the comments over MCP with the work tools',
        'task' => 'save_task'], // 3.27: the tasks of an item in its form
    // 2.15: requests to Claude – staff write them in the admin (not over MCP: a request is what a person asks Claude), Claude reads and answers them
    'requests' => ['list' => 'list_requests', 'new' => $readOnly, 'save' => 'admin: a request is written by a person for Claude – Claude reads it with list_requests', 'detail' => 'list_requests',
        'reply' => 'admin: the requester answers Claude in the request; Claude answers with update_request', 'status' => 'update_request'],
    'enquiries' => ['list' => $readOnly, 'detail' => $readOnly, 'csv' => $readOnly, 'attachment' => $readOnly, 'note' => 'update_enquiry', 'status' => 'update_enquiry', 'triage' => 'update_enquiry', 'testimonial' => 'request_testimonial', 'reply' => 'reply_to_enquiry', 'personal' => 'erase_personal_data',
        'bulk' => 'update_enquiry', 'delete' => 'delete_enquiry', 'settings' => 'admin: how long enquiries are kept',
        'to_application' => 'admin: moving an enquiry with its attachments into Applications (3.34) is a person’s decision about personal data – CVs never pass through Claude', 'anonymise' => 'admin: blanking a person from an enquiry is the owner’s decision about personal data (2.14)',
        'comment' => 'admin: editing or deleting one’s own note (3.26) – notes are added with update_enquiry add_note',
        'task' => 'save_task'], // 3.27: the tasks and follow-ups of an enquiry
    // 3.0: online booking – bookings are personal data (the Bookings section), the set-up is the administrator's
    'bookings' => ['list' => $readOnly, 'detail' => $readOnly, 'new' => $readOnly, 'status' => 'cancel_booking', 'confirm' => 'confirm_booking', 'decline' => 'decline_booking', 'propose' => 'propose_booking_times', 'anonymise' => 'admin: blanking a person from a booking is the owner’s decision about personal data',
        'create' => 'admin: a booking taken by phone is entered by the person who took the call – customers’ personal data never come from Claude', 'services' => $readOnly, 'service_save' => 'save_booking_service',
        'service_delete' => 'admin: removing a service is the administrator’s decision; save_booking_service switches it off', 'staff' => $readOnly, 'staff_edit' => $readOnly, 'staff_save' => 'save_booking_staff', 'book_page' => 'create_page',
        'staff_delete' => 'admin: removing a person is the administrator’s decision; save_booking_staff switches them off', 'off_save' => 'save_booking_staff', 'off_delete' => 'save_booking_staff', 'settings' => 'update_settings'],
    // 3.28: Customers – Claude reads and changes the same records with list_contacts, get_contact, save_contact (a company too),
    // log_activity and merge_contacts; deleting a record, the CSV import and linking an old record by hand stay with a person
    'contacts' => ['list' => $contactTool('list_contacts'), 'companies' => $contactTool('list_contacts'), 'detail' => $contactTool('get_contact'), 'company' => $readOnly,
        'new' => $readOnly, 'company_new' => $readOnly, 'search_json' => $readOnly, 'export' => $readOnly, 'import' => $readOnly,
        'save' => $contactTool('save_contact'), 'company_save' => $contactTool('save_contact'), 'activity' => $contactTool('log_activity'), 'merge' => $contactTool('merge_contacts'),
        'delete' => 'admin: deleting a customer for good is an administrator\'s decision – erase_personal_data removes a person on request',
        'company_delete' => 'admin: deleting a company is an administrator\'s decision; its contacts stay',
        'link' => 'admin: an old enquiry or booking linked by hand – the job "contacts" links them by itself',
        'import_upload' => 'admin: a CSV of customers comes through the admin, never over MCP (personal data in bulk)', 'import_run' => 'admin: a CSV of customers comes through the admin, never over MCP (personal data in bulk)',
        'comment' => 'admin: the team’s thread on a customer or a company – a person writes it; Claude gets the comments over MCP with the work tools',
        'task' => 'save_task'],
    // 3.31: the Pipeline – Claude reads and moves the same deals with list_deals, save_deal, move_deal and pipeline_summary; deleting a
    // deal and changing the stages stay with an administrator
    'pipeline' => ['list' => $dealTool('list_deals'), 'detail' => $dealTool('list_deals'), 'new' => $readOnly, 'search_json' => $readOnly, 'stages' => $readOnly,
        'save' => $dealTool('save_deal'), 'move' => $dealTool('move_deal'), 'from_enquiry' => $dealTool('save_deal'),
        'delete' => 'admin: deleting a deal for good is an administrator\'s decision', 'stages_save' => 'admin: the stages of the pipeline are the administrator\'s set-up',
        'comment' => 'admin: the team’s thread on a deal – a person writes it; Claude gets the comments over MCP with the work tools',
        'task' => 'save_task'],
    // 3.34: job applications (Core\Applications) – Claude reads and changes them with list_applications, get_application,
    // update_application and reply_to_application (3.34 B); the file, deletion, the consent, the settings and the move stay admin
    'applications' => ['list' => 'list_applications', 'detail' => 'get_application', 'attachment' => 'admin: a CV is downloaded by a person with the section, never passed to Claude',
        'status' => 'update_application', 'assign' => 'update_application', 'bulk' => 'update_application', 'reply' => 'reply_to_application',
        'consent' => 'admin: the applicant withdrew the consent for future openings – a person records it', 'delete' => 'admin: deleting an application for good is an administrator\'s decision',
        'comment' => 'admin: the team’s notes on an application – a person writes them; Claude adds one with update_application add_note', 'task' => 'save_task',
        'settings' => $readOnly, 'settings_save' => 'admin: the retention, the templates and the switches of applications are the administrator\'s set-up',
        'search_json' => $readOnly, 'move' => $readOnly, 'move_run' => 'admin: moving enquiries of a form to applications or back is the administrator\'s decision about personal data'],
    'subscribers' => ['list' => $readOnly, 'csv' => $readOnly, 'delete' => 'admin: subscribers’ addresses stay out of MCP', 'sync' => 'admin: mailing service keys', 'retry' => 'admin: mailing service keys'],
    'newsletters' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'preview' => $readOnly, 'save' => 'draft_newsletter', 'test' => 'send_test_newsletter',
        'send' => 'send_newsletter', 'unschedule' => 'send_newsletter', 'delete' => 'delete_newsletter'],
    'categories' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'update_category', 'delete' => 'delete_category'],
    'tags' => ['list' => $readOnly, 'save' => 'admin: tags are set with news items (update_news)', 'delete' => 'admin: tags are set with news items (update_news)'],
    'media' => ['list' => $readOnly, 'listing' => $readOnly, 'upload' => 'upload_file', 'save' => 'update_media', 'save_caption' => 'update_media', 'bulk' => 'delete_media',
        'replace' => 'admin: a new file behind the same address', 'folder' => 'admin: media folders', 'folder_delete' => 'admin: media folders',
        'cleanup' => 'list_media_without_alt', 'save_alts' => 'update_media', 'shrink' => 'admin: re-encoding a stored image in place (2.14) – Claude uploads a smaller file instead',
        'approve' => 'admin: a person approves a file a drafts-only connection staged (3.15, N10) – publishing the content that uses it does it too', 'discard' => 'delete_media'],
    'appearance' => ['list' => $readOnly, 'preview' => $readOnly, 'tokens' => $readOnly, 'save' => 'update_design_system', 'tokens_import' => 'admin: upload of a design tokens file',
        'publish_look' => 'publish_look', 'discard_look' => 'discard_look', 'restore_look' => 'restore_look_version', 'preview_site' => 'preview_link'],
    'parts' => $builderParity + ['list' => $readOnly, 'variant' => $readOnly, 'save_variant' => 'save_part_variant', 'template' => 'save_part_variant',
        'templates' => $readOnly, 'apply_template' => 'apply_part_template'],
    'menu' => ['list' => $readOnly, 'save' => 'save_menu', 'automatic' => 'save_menu'],
    'components' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'save_component', 'delete' => 'delete_component', 'from_element' => 'save_component'],
    'popups' => $builderParity + ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'create' => 'save_popup', 'save' => 'save_popup', 'toggle' => 'save_popup',
        'delete' => 'delete_popup', 'reset' => 'admin: resetting the counters'],
    'users' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'admin: accounts and permissions', 'delete' => 'admin: accounts and permissions', 'password_link' => 'admin: accounts and permissions',
        'reactivate' => 'admin: accounts and permissions', 'revoke_connection' => 'admin: accounts and permissions',
        'handover' => 'admin: handing all of a leaver\'s work over goes with blocking or deleting the account (3.24) – Claude moves one record with assign'],
    'my_work' => ['list' => 'my_work', 'team' => 'list_tasks', 'complete' => 'complete_task', 'snooze' => 'save_task', 'task' => 'save_task', 'reassign' => 'save_task', 'task_edit' => 'save_task', 'take' => 'assign'], // 3.32 (U35): edit a task, take an enquiry; 3.24; 3.27 tasks (task = New task; reassign = a task nobody has, list_tasks assignee:none)
    'roles' => ['list' => $readOnly, 'new' => $readOnly, 'edit' => $readOnly, 'save' => 'admin: accounts and permissions', 'delete' => 'admin: accounts and permissions'],
    'stats' => ['list' => 'get_stats'], 'changelog' => ['list' => 'list_changes', 'sessions' => 'list_agent_sessions', 'undo' => 'undo_agent_session'], 'audit' => ['list' => 'site_audit'],
    'addons' => ['list' => $readOnly, 'toggle' => 'admin: running code from another developer is the administrator’s decision (3.0)', 'page' => 'admin: pages add-ons add to the administration'],
    'redirects' => ['list' => $readOnly, 'save' => 'save_redirect', 'delete' => 'save_redirect', 'import' => $readOnly, 'import_save' => 'save_redirects', 'clear' => 'admin: clearing the list of 404 addresses', 'ignore' => 'ignore_not_found', 'ignore_all' => 'ignore_not_found',
        'settings' => 'update_settings'],
    'transfer' => ['list' => $readOnly, 'preview' => $readOnly, 'download' => $readOnly, 'export' => $readOnly, 'upload' => 'admin: WordPress import', 'select' => 'admin: WordPress import',
        'run' => 'admin: WordPress import', 'progress' => 'admin: WordPress import', 'images' => 'admin: WordPress import', 'publish_menus' => 'publish_look', 'delete_file' => 'admin: WordPress import', 'delete_export' => 'admin: site export',
        'kaleta' => 'admin: moving a whole site into a new installation', 'kaleta_select' => 'admin: moving a whole site into a new installation',
        'kaleta_run' => 'admin: moving a whole site into a new installation', 'kaleta_delete' => 'admin: moving a whole site into a new installation',
        'web_start' => 'import_website', 'web_progress' => 'import_website', 'web_run' => 'import_website', 'web_delete' => 'admin: removing the record of an import',
        'report_start' => 'migration_report', 'report' => 'migration_report', 'report_delete' => 'admin: removing a saved report',
        'starter_remove' => 'trash_page', // 3.33 (M2): the untouched sample pages to the trash – Claude trashes each one
        'source_upload' => 'admin: structured import (3.0) – the export file comes through the admin, not over MCP', 'source_select' => 'admin: structured import (3.0)',
        'source_preview' => $readOnly, 'source_run' => 'admin: structured import (3.0)', 'source_progress' => 'admin: structured import (3.0)',
        'source_images' => 'admin: structured import (3.0)', 'source_delete' => 'admin: structured import (3.0)',
        'source_fetch' => 'admin: structured import (3.0) – the API token is typed in the admin, kept in the session only, never over MCP'],
    'settings' => $settingsParity, 'extensions' => $settingsParity, 'business' => $settingsParity, 'status' => $settingsParity, 'claude_settings' => $settingsParity,
    'facts' => ['list' => 'list_facts', 'edit' => $readOnly, 'save' => 'save_fact', 'delete' => 'delete_fact', 'claims' => 'find_claims'],
    'notebook' => ['list' => 'read_notebook', 'edit' => $readOnly, 'save' => 'write_notebook', 'pin' => 'write_notebook', 'delete' => 'delete_notebook_entry'],
    // 2.17: scheduled runs – what a routine in Claude does on the site and when is the administrator's decision; Claude only gets the due runs and reports them
    'schedules' => ['list' => $readOnly, 'edit' => $readOnly, 'history' => $readOnly, 'save' => 'admin: a schedule is the administrator\'s instruction to a routine (2.17) – Claude gets the due runs with get_due_agent_runs and reports them with report_agent_run',
        'delete' => 'admin: a schedule is the administrator\'s instruction to a routine (2.17)', 'toggle' => 'admin: a schedule is the administrator\'s instruction to a routine (2.17)'],
    'connectors' => ['list' => 'list_connectors', 'save' => 'admin: credentials of outside services never go through Claude', 'connect' => 'admin: an OAuth sign-in needs the administrator in the browser',
        'callback' => 'admin: an OAuth sign-in needs the administrator in the browser', 'disconnect' => 'admin: credentials of outside services never go through Claude',
        'properties' => 'admin: picking the Search Console property belongs to the connection, next to its credentials', 'property' => 'admin: picking the Search Console property belongs to the connection, next to its credentials',
        'gbp_locations' => 'admin: which Business Profile location the site syncs is the administrator’s choice (2.13)', 'gbp_sync' => 'admin: the daily job does it by itself; the button is for the administrator checking the connection',
        'sheet' => 'admin: the sheet of enquiries is created with the administrator\'s Google sign-in (2.13); Claude sees the status in list_connectors'],
    'blueprints' => ['list' => 'get_blueprint', 'apply' => 'apply_blueprint', 'remove' => 'remove_blueprint', 'answers' => 'save_fact', 'export' => 'export_blueprint'],
    // 2.14: whistleblowing reports are for the chosen readers only – deliberately no MCP tool reads, lists or answers them
    'whistleblowing' => ['list' => 'admin: whistleblowing cases never go through Claude', 'settings' => 'admin: who reads whistleblowing reports is a security decision',
        'detail' => 'admin: whistleblowing cases never go through Claude', 'reply' => 'admin: whistleblowing cases never go through Claude',
        'status' => 'admin: whistleblowing cases never go through Claude', 'attachment' => 'admin: whistleblowing cases never go through Claude',
        'footer_link' => 'save_menu'], // 3.20 (C22): one click adds the form to the footer menu – Claude does the same with save_menu
    'fleet' => ['list' => 'fleet_today', 'inbox' => 'fleet_inbox', 'health' => 'fleet_health', 'workload' => 'fleet_health', 'sites' => 'list_sites', 'customers' => 'list_sites', 'detail' => 'get_site',
        'customer' => 'admin: the customers and notes are the owner\'s own bookkeeping on the console (3.17)', 'customer_bulk' => 'admin: the customers and notes are the owner\'s own bookkeeping on the console (3.17)',
        'customer_rename' => 'admin: the customers and notes are the owner\'s own bookkeeping on the console (3.17)', 'pairing_key' => 'admin: pairing a site is a security decision (2.9)', 'ring' => 'admin: the update ring decides when sites install versions',
        'allow' => 'admin: allowing a version on the sites', 'check' => 'admin: the console checks the sites every 5 minutes on its own', 'remove' => 'admin: removing a site from the console',
        'kit' => $readOnly, 'kit_publish' => 'admin: publishing a design kit to a whole fleet is a person\'s decision (2.16); list_sites shows the versions',
        // 3.17: alert rules and the digest decide what leaves the console by e-mail – a person's settings; the reports are read by fleet_report
        'alerts' => $readOnly, 'alerts_save' => 'admin: who gets e-mails from the console and how often is the owner\'s setting (3.17)', 'digest_preview' => $readOnly,
        'reports' => 'fleet_report', 'report_view' => 'fleet_report', 'report_download' => 'fleet_report',
        'report_build' => 'admin: making or rebuilding the fleet reports replaces stored ones – the administrator\'s choice (3.17)',
        // 3.30: a customer's screen reads what list_sites and fleet_plan read; the console tasks are save_task / complete_task
        'customer_detail' => 'fleet_plan', 'task' => 'save_task',
        // 3.30: the inventory, the expiry calendar and the rollout are read by fleet_inventory and fleet_calendar; what they change is the owner's
        'inventory' => 'fleet_inventory', 'inventory_csv' => 'fleet_inventory', 'calendar' => 'fleet_calendar', 'rollout' => 'fleet_inventory',
        'calendar_save' => 'admin: who is reminded of the dates and when is the owner\'s setting (3.30)', 'calendar_feed' => 'admin: a secret calendar link is a person\'s own (3.30)',
        'rollout_save' => 'admin: the delay of each ring decides when sites install versions (3.30)', 'window' => 'admin: the maintenance windows decide when sites install versions (3.30)'],
];
$missingParity = [];
foreach (Kaleta\Admin\Kernel::MODULES as $moduleClass) {
    foreach ((new ReflectionClass($moduleClass))->getMethods() as $method) {
        if (preg_match('/^action[A-Z]/', $method->name)) {
            $action = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method->name, 6)));
            $covered = $parity[$moduleClass::IDENT][$action] ?? null;
            if ($covered === null || ($covered !== $readOnly && !str_starts_with($covered, 'admin: ') && !in_array($covered, Kaleta\Mcp\Translator::names(), true))) {
                $missingParity[] = $moduleClass::IDENT . '.' . $action . ($covered !== null ? ' → unknown tool ' . $covered : '');
            }
        }
    }
}
check('Parity: every admin action is a read, an MCP tool or admin only on purpose', $missingParity, []);
// The builder vocabulary in English at the MCP boundary: every map one to one, nothing missing, every library section there and back
$vocabulary = Kaleta\Mcp\Vocabulary::class;
$notOneToOne = [];
foreach (['NODE', 'CONDITIONS', 'TYPES', 'CONTENT', 'ITEMS', 'STATES', 'STYLE', 'COLORS', 'TEXT_STYLES', 'FIELD_TYPES', 'STYLE_TYPES'] as $map) {
    if (count(array_unique(constant($vocabulary . '::' . $map))) !== count(constant($vocabulary . '::' . $map))) {
        $notOneToOne[] = $map;
    }
}
foreach ($vocabulary::VALUES + ['ITEM typ' => $vocabulary::ITEM_VALUES['typ']] as $key => $values) {
    if (count(array_unique($values)) !== count($values)) {
        $notOneToOne[] = 'VALUES ' . $key;
    }
}
$contentBack = $vocabulary::reverse('CONTENT');
foreach ($vocabulary::CONTENT_BY_TYPE as $keys) {
    foreach ($keys as $en) {
        if (isset($vocabulary::CONTENT[$contentBack[$en]]) && $vocabulary::CONTENT[$contentBack[$en]] === $en && !in_array($en, $keys, true)) {
            $notOneToOne[] = 'CONTENT_BY_TYPE ' . $en;
        }
        if (in_array($en, $vocabulary::CONTENT, true)) {
            $notOneToOne[] = 'CONTENT_BY_TYPE ' . $en . ' is also a general key';
        }
    }
}
check('Vocabulary: every map is one to one', $notOneToOne, []);
$vocabularyMissing = $vocabulary::missingTypes();
foreach (Kaleta\Builder\Build::ELEMENTS as $className) {
    foreach ($className::properties() as $key => $definition) {
        if (!isset($vocabulary::CONTENT[$key]) && !isset($vocabulary::CONTENT_BY_TYPE[$className::TYPE][$key])) {
            $vocabularyMissing[] = $className::TYPE . '.' . $key;
        }
        foreach (array_keys((array) ($definition['pole'] ?? [])) as $itemKey) {
            if (!isset($vocabulary::ITEMS[$itemKey])) {
                $vocabularyMissing[] = $className::TYPE . '.' . $key . '[' . $itemKey . ']';
            }
        }
        if (!isset($vocabulary::FIELD_TYPES[$definition['typ']])) {
            $vocabularyMissing[] = 'field type ' . $definition['typ'];
        }
    }
}
$vocabularyMissing = [...$vocabularyMissing, ...array_diff(array_keys(Kaleta\Builder\Style::PROPERTIES), array_keys($vocabulary::STYLE)),
    ...array_diff(array_keys(Kaleta\Builder\Style::STATUSES), array_keys($vocabulary::STATES)), ...array_diff(array_keys(Kaleta\Builder\DesignSystem::COLOR_TOKENS), array_keys($vocabulary::COLORS)),
    ...array_diff(array_keys(Kaleta\Builder\DesignSystem::TYPOGRAPHY), array_keys($vocabulary::TEXT_STYLES))];
check('Vocabulary: every element type, field, style property, state and token has an English name', array_values(array_unique($vocabularyMissing)), []);
$roundTrip = [];
foreach (Kaleta\Builder\Library::listAll() as $librarySection) {
    $element = Kaleta\Builder\Library::section($librarySection['klic'])['prvek'];
    $english = $vocabulary::elementToEnglish($element);
    if ($vocabulary::elementToCzech($english) !== $element || preg_match('/"(deti|obsah|styl|typ|zaklad|mobil)":/', (string) json_encode($english))) {
        $roundTrip[] = $librarySection['klic'];
    }
}
check('Vocabulary: every library section goes to English (no Czech keys left) and back unchanged', $roundTrip, []);
check('Vocabulary: the Czech form is still accepted on input', $vocabulary::elementToCzech(['typ' => 'nadpis', 'obsah' => ['text' => 'A'], 'styl' => ['mobil' => ['barva' => 'primarni']]]),
    ['typ' => 'nadpis', 'obsah' => ['text' => 'A'], 'styl' => ['mobil' => ['barva' => 'primarni']]]);
check('Vocabulary: English in, Czech stored', $vocabulary::elementToCzech(['type' => 'button', 'content' => ['text' => 'Go', 'variant' => 'outline', 'icon' => 'arrow'], 'style' => ['hover' => ['background' => 'primary-soft', 'radius' => 'full']]]),
    ['typ' => 'tlacitko', 'obsah' => ['text' => 'Go', 'varianta' => 'obrys', 'ikona' => 'sipka'], 'styl' => ['hover' => ['pozadi' => 'primarni-jemna', 'zaobleni' => 'plne']]]);
// Ready-made templates of site parts: each builds without errors, wrappers keep exactly one page content element, headers carry the logo
$templateProblems = [];
foreach (Kaleta\Builder\PartTemplates::LIST as $partType => $partTemplates) {
    foreach (array_keys($partTemplates) as $templateKey) {
        $templateBuild = Kaleta\Builder\PartTemplates::build($partType, $templateKey, 'en', ['newsletter']);
        [, $templateErrors] = Kaleta\Builder\Build::sanitize($templateBuild);
        $json = (string) json_encode($templateBuild);
        $contentCount = preg_match_all('/"typ":"obsah"/', $json);
        if ($templateErrors !== [] || (in_array($partType, ['novinka', 'vypis', 'nenalezeno'], true) ? $contentCount !== 1 : $contentCount !== 0)
            || ($partType === 'hlavicka' && !str_contains($json, '"typ":"logo"'))) {
            $templateProblems[] = $partType . ':' . $templateKey;
        }
    }
}
check('Part templates: valid builds, one page content in wrappers, a logo in headers', $templateProblems, []);
check('Part templates: the newsletter sign-up only with the Newsletter extension', [str_contains((string) json_encode(Kaleta\Builder\PartTemplates::build('paticka', 'sloupce', 'en', [])), '"typ":"newsletter"'),
    array_column(Kaleta\Builder\PartTemplates::forType('vypis', []), 'klic')], [false, ['jednoduchy']]);
// 2.1: every MCP tool once in Mcp\Catalog – its English definition, its parameter types and its method agree with it
$catalogTools = array_keys(Kaleta\Mcp\Catalog::TOOLS);
$toolMethods = array_values(array_filter(array_map(fn (ReflectionMethod $m): string => $m->name, (new ReflectionClass(Kaleta\Mcp\Tools::class))->getMethods()), fn (string $m): bool => preg_match('/^tool[A-Z]/', $m) === 1));
check('2.1: MCP catalog, English definitions, parameter types and methods agree', [
    array_values(array_diff($catalogTools, Kaleta\Mcp\Translator::names())), array_values(array_diff(Kaleta\Mcp\Translator::names(), $catalogTools)),
    array_values(array_filter(array_column(Kaleta\Mcp\Tools::definitions(), 'name'), fn (string $n): bool => Kaleta\Mcp\Catalog::english($n) === null)),
    array_values(array_diff(array_map([Kaleta\Mcp\Catalog::class, 'method'], $catalogTools), $toolMethods)), array_values(array_diff($toolMethods, array_map([Kaleta\Mcp\Catalog::class, 'method'], $catalogTools))),
    count(Kaleta\Mcp\Tools::definitions()), Kaleta\Mcp\Tools::annotations('smaz_stranku'), Kaleta\Mcp\Tools::isWriteTool('site_audit'), Kaleta\Mcp\Catalog::extension('seznam_novinek'),
], [[], [], [], [], [], count($catalogTools), ['title' => 'Move a page to the trash', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false], false, 'novinky']);
// 2.2: what a connection may do – full everything, drafts only reads and drafts, read only reads; never an unknown level
check('2.2: connection access', [Kaleta\Mcp\Catalog::allows('full', 'publish_look'), Kaleta\Mcp\Catalog::allows('drafts', 'save_build'), Kaleta\Mcp\Catalog::allows('drafts', 'create_page'),
    Kaleta\Mcp\Catalog::allows('drafts', 'publish_build'), Kaleta\Mcp\Catalog::allows('drafts', 'update_settings'), Kaleta\Mcp\Catalog::allows('drafts', 'trash_page'),
    Kaleta\Mcp\Catalog::allows('read', 'get_build'), Kaleta\Mcp\Catalog::allows('read', 'save_build'), Kaleta\Mcp\Catalog::allows('read', 'stavba_uloz'), Kaleta\Mcp\Catalog::allows('whatever', 'save_build'),
    Kaleta\Front\OAuth::access('drafts'), Kaleta\Front\OAuth::access('admin'), Kaleta\Mcp\Tools::annotations('save_build')],
    [true, true, true, false, false, false, true, false, false, false, 'drafts', 'read', ['title' => 'Save a draft build', 'readOnlyHint' => false, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false]]);
check('2.2: every drafts-only tool is a write that does not remove anything', array_values(array_filter(array_keys(Kaleta\Mcp\Catalog::TOOLS),
    fn (string $t): bool => Kaleta\Mcp\Catalog::access($t) === 'draft' && (Kaleta\Mcp\Tools::annotations($t)['readOnlyHint'] || Kaleta\Mcp\Tools::annotations($t)['destructiveHint']))), []);
check('2.2: MCP protocol version negotiated', [Kaleta\Mcp\Server::protocol('2025-03-26'), Kaleta\Mcp\Server::protocol('2099-01-01'), Kaleta\Mcp\Server::protocol(null)],
    ['2025-03-26', '2025-06-18', '2025-06-18']);
$promptText = Kaleta\Mcp\Prompts::get('build_page', ['topic' => 'kitchens', 'audience' => 'families'])['messages'][0]['content']['text'];
$promptError = '';
try {
    Kaleta\Mcp\Prompts::get('translate_page', ['page_id' => '3']);
} catch (InvalidArgumentException $e) {
    $promptError = $e->getMessage();
}
check('2.2: MCP prompts and resources', [str_starts_with($promptText, 'Build a new page about kitchens for families.'), str_contains(Kaleta\Mcp\Prompts::get('build_page', ['topic' => 'x'])['messages'][0]['content']['text'], 'about x. First'),
    $promptError, array_column(Kaleta\Mcp\Prompts::listAll(), 'name'), array_column(Kaleta\Mcp\Prompts::resources(), 'uri')],
    [true, true, 'The prompt translate_page needs the argument language.', ['build_page', 'audit_and_fix', 'translate_page', 'write_news', 'migrate_site', 'launch_site', 'weekly_care', 'work_requests', 'scheduled_run', 'review_pending', 'draft_blueprint',
        'set_up_bookings', 'compliance_check', 'add_language_version', 'newsletter_release', 'fleet_fix'], ['kaleta://instructions', 'kaleta://overview']]);
// 2.8: when a job is due, and when an update counts as broken (only with a clear sign – never just because the site cannot reach itself)
check('2.8: Scheduler::isDue', [Kaleta\Core\Scheduler::isDue(null, 300, 1000), Kaleta\Core\Scheduler::isDue(900, 0, 1000), Kaleta\Core\Scheduler::isDue(800, 300, 1000),
    Kaleta\Core\Scheduler::isDue(700, 300, 1000), Kaleta\Core\Scheduler::isDue(1000 - 86400 + 60, 86400, 1000)], [true, true, false, true, false]);
// 2.9: the monthly report – the previous month across the year boundary, the agency or the site in the header, no address from the
// data gets through, the month name in the site's language
use Kaleta\Core\MonthlyReport;
check('2.9: MonthlyReport::previousMonth – January goes to December of the previous year', [MonthlyReport::previousMonth(new DateTimeImmutable('2027-01-15 10:00:00'))->format('Y-m-d H:i'),
    MonthlyReport::previousMonth(new DateTimeImmutable('2026-03-31 23:59:59'))->format('Y-m-d')], ['2026-12-01 00:00', '2026-02-01']);
$reportSettings = static function (array $values): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, $values + ['site_name' => 'Testovací firma', 'site_language' => 'en']);

    return $s;
};
$reportData = ['month' => '2026-09',
    'stats' => ['visits' => 120, 'views' => 300, 'previous_visits' => 100, 'previous_views' => 0, 'pages' => [['path' => '/sluzby', 'n' => 80]], 'sources' => [['site' => 'google.com', 'n' => 40]], 'campaigns' => []],
    'enquiries' => ['total' => 3, 'forms' => [['form' => 'Kontakt jan.novak@visitor.example', 'n' => 3]], 'pages' => [['path' => '/kontakt', 'n' => 3]], 'unanswered' => 2], 'signups' => 1,
    'updates' => [['type' => 'update.applied', 'date' => '2026-09-10 10:00:00', 'message' => 'Version 2.8.0 was installed (from 2.7.0).']],
    'backups' => ['created' => 20, 'failed' => 0, 'last' => '2026-09-30 03:00:00'], 'changes' => ['people' => 12, 'claude' => 7],
    'problems' => [['group' => 'Operation', 'name' => 'Cron', 'state' => 'varovani', 'info' => 'not set up – ask admin@visitor.example']], 'decisions' => ['enquiries' => 2, 'errors' => 0]];
$withAgency = MonthlyReport::render($reportData, $reportSettings(['agency_name' => 'Studio Kaleta', 'agency_email' => 'help@studio.example', 'agency_logo' => 'media/logo.svg']), 'https://example.com/');
$withoutAgency = MonthlyReport::render($reportData, $reportSettings([]), 'https://example.com');
check('2.9: the report carries the agency when it is set, otherwise the site', [str_contains($withAgency['html'], 'Studio Kaleta'), str_contains($withAgency['html'], 'https://example.com/media/logo.svg'), str_contains($withAgency['text'], 'help@studio.example'),
    str_contains($withoutAgency['html'], 'Studio Kaleta'), str_contains($withoutAgency['html'], 'Testovací firma'), str_contains($withoutAgency['html'], '120 (+20 %)')], [true, true, true, false, true, true]);
check('2.9: no e-mail address from the data gets into the report', [str_contains($withAgency['html'] . $withAgency['text'] . $withAgency['subject'], 'visitor.example'), str_contains($withoutAgency['html'], '/sluzby'), str_contains($withoutAgency['text'], 'Kontakt ')], [false, true, true]);
check('2.9: the subject names the month in the site language', [$withoutAgency['subject'], MonthlyReport::render($reportData, $reportSettings(['site_language' => 'cs']), 'https://example.com')['subject'],
    MonthlyReport::render(['month' => '2027-01'], $reportSettings(['site_language' => 'de']), 'https://example.com')['subject']],
    ['Website report – September 2026 – Testovací firma', 'Zpráva o webu – září 2026 – Testovací firma', 'Website-Bericht – Januar 2027 – Testovací firma']);
// 3.16: the archived monthly report – what happened to the site, scrubbed of anything personal, kept 24 months, printable on A4
$report316 = $reportData + ['schema' => 1, 'generated_at' => '2026-10-01 03:00:00', 'version' => '3.16.0', 'update_channel' => 'stable',
    'requests' => ['created' => 3, 'done' => [['title' => 'Change the hours – ask jana@client.example', 'status' => 'done', 'created' => '2026-09-02 10:00:00', 'done' => '2026-09-03 11:00:00']], 'done_count' => 1,
        'open' => [['title' => 'New price list @janinka', 'status' => 'in_progress', 'created' => '2026-09-20 09:00:00', 'done' => '']], 'open_count' => 2],
    'work' => ['areas' => ['pages' => ['people' => 4, 'claude' => 6], 'look' => ['people' => 1, 'claude' => 0]], 'pages_published' => [['title' => 'Services', 'date' => '2026-09-05 10:00:00', 'by' => 'claude']], 'pages_published_count' => 1,
        'news_published' => [['title' => 'We moved', 'date' => '2026-09-10 08:00:00']], 'news_published_count' => 1, 'look_published' => 1],
    'claude' => ['sessions' => 5, 'calls' => 31, 'undone' => 1, 'runs' => 4, 'run_statuses' => ['ok' => 3, 'missed' => 1],
        'run_list' => [['name' => 'Weekly review', 'task' => 'review', 'date' => '2026-09-28 07:10:00', 'status' => 'ok', 'summary' => 'Fixed 3 descriptions; wrote to owner@client.example']]],
    'security' => ['firewall' => ['probe' => 40, 'rate' => 2], 'sign_ins' => 12, 'failed_sign_ins' => 3, 'events' => ['security.account_suspended' => 1]],
    'audit' => ['kinds' => ['description' => 4, 'link' => 1], 'total' => 5, 'previous' => null, 'fixed' => null, 'new' => null],
    'bookings' => null, 'newsletter' => ['signups' => 1, 'campaigns' => [['subject' => 'September news', 'date' => '2026-09-15 09:00:00', 'sent' => 120]]],
    'open' => ['requests' => 2, 'page_drafts' => 1, 'look_draft' => 1]];
$scrubbed316 = MonthlyReport::scrub($report316);
check('3.16: MonthlyReport::scrub – no "@" anywhere in the stored JSON (addresses and handles in titles, summaries, form names), the rest kept',
    [str_contains((string) json_encode($scrubbed316), '@'), $scrubbed316['requests']['done'][0]['title'], $scrubbed316['requests']['open'][0]['title'], $scrubbed316['work']['pages_published'][0]['title']],
    [false, 'Change the hours – ask …', 'New price list …', 'Services']);
check('3.16: ReportArchive keeps 24 months – the oldest kept month, across the year boundary; the job runs hourly before the e-mail', [Kaleta\Core\ReportArchive::oldestKept(new DateTimeImmutable('2026-10-19 12:00')),
    Kaleta\Core\ReportArchive::oldestKept(new DateTimeImmutable('2027-01-01 00:10')), Kaleta\Core\ReportArchive::KEEP_MONTHS, Kaleta\Core\Scheduler::JOBS['report_archive'][0] ?? null,
    array_search('report_archive', array_keys(Kaleta\Core\Scheduler::JOBS), true) < array_search('monthly_report', array_keys(Kaleta\Core\Scheduler::JOBS), true),
    preg_match(Kaleta\Core\ReportArchive::MONTH_PATTERN, '2026-13'), preg_match(Kaleta\Core\ReportArchive::MONTH_PATTERN, '2026-09')], ['2024-10', '2025-01', 24, 3600, true, 0, 1]);
check('3.16: MonthlyReport::area – a person by the admin section, Claude by the tool (Czech or English name)', [MonthlyReport::area('pages', 'save'), MonthlyReport::area('menu', 'save'),
    MonthlyReport::area('claude', 'save_collection_item'), MonthlyReport::area('claude', 'publish_look'), MonthlyReport::area('claude', 'upload_file'), MonthlyReport::area('claude', 'draft_newsletter'),
    MonthlyReport::area('claude', Kaleta\Mcp\Translator::czech('create_page') ?? 'create_page'), MonthlyReport::area('whatever', 'x')], ['pages', 'look', 'collections', 'look', 'media', 'newsletter', 'pages', 'other']);
$sections316 = Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Core\ReportPage::sections($report316), 'admin-');
$sectionKeys316 = array_column($sections316, 'key');
check('3.16: ReportPage::sections – every section with data, in order; bookings off = no section; nothing personal printed (3.26.1: "At a glance" is the visual summary above them)', [$sectionKeys316,
    str_contains((string) json_encode($sections316, JSON_UNESCAPED_UNICODE), '@'), $sections316[0]['blocks'][0]['type'], $sections316[0]['blocks'][0]['rows'][0]],
    [['open', 'requests', 'work', 'claude', 'traffic', 'enquiries', 'newsletter', 'updates', 'backups', 'security', 'audit', 'problems'], false, 'pairs', ['Requests to Claude still open', '2']]);
$view316 = new Kaleta\Core\View([KALETA_SYSTEM . '/views']);
$stored316 = ['month' => '2026-09', 'created_at' => '2026-10-01 03:00:00', 'rebuilt_at' => null, 'data' => $scrubbed316];
$page316 = Kaleta\Core\ReportPage::render($view316, $stored316, $reportSettings(['agency_name' => 'Studio Kaleta', 'agency_logo' => 'media/logo.svg']), 'https://example.com/');
$screen316 = Kaleta\Core\ReportPage::render($view316, array_replace($stored316, ['rebuilt_at' => '2026-10-19 10:00:00']), $reportSettings(['site_language' => 'cs']), 'https://example.com', ['script' => '/image/tisk.js', 'download' => '/x', 'back' => '/y']);
check('3.16: the printable report – A4 with page numbers, the agency header, the month, no script in the downloaded file, nothing personal; on screen the Print button, in the site language',
    [str_contains($page316, 'size: A4'), str_contains($page316, 'counter(page) " / " counter(pages)'), str_contains($page316, 'https://example.com/media/logo.svg'), str_contains($page316, 'Studio Kaleta'),
        str_contains($page316, '<h1>September 2026</h1>'), str_contains($page316, '<script'), str_contains($page316, '@') && preg_match('/@(?!page|media|bottom)/', $page316) === 1, str_contains($page316, 'break-inside: avoid-page'),
        str_contains($screen316, 'data-tisk'), str_contains($screen316, 'září 2026'), str_contains($screen316, 'Zpráva o webu'), str_contains($screen316, 'znovu sestaveno')],
    [true, true, true, true, true, false, false, true, true, true, true, true]);
$mail316 = MonthlyReport::render($report316, $reportSettings([]), 'https://example.com');
check('3.16: the e-mail adds the work on the site and the security counts, and points to Settings → Reports', [str_contains($mail316['html'], 'What it took to run the site'), str_contains($mail316['text'], 'Requests answered: 1'),
    str_contains($mail316['text'], '42 blocked requests, 3 failed sign-ins'), str_contains($mail316['text'], 'Settings → Reports'), str_contains($mail316['html'] . $mail316['text'], 'client.example')], [true, true, true, true, false]);
// 3.16 audit fixes (N316-1, -2, -4, -7, -9): one masker, no run summaries, CSS strings escaped, rebuilt reports labelled, ISO moments
use Kaleta\Core\Redact;
$redactPositive = ['Reply to jan @ firma.cz', 'jan@ firma.cz', 'jan [at] firma [dot] cz', 'jan(at)firma.cz', "jan\u{FF20}firma.cz", "jan\u{FE6B}firma.cz", 'jan&amp;#64;firma.cz',
    '"jan"@firma.cz', 'Call back Mr Novák, novak (at) firma.cz', 'jan at firma dot cz', 'pište na jan (zavináč) firma (tečka) cz', "jan\u{200B}@firma.cz",
    'Jana +420 777 123 456 wants a quote', 'call 777123456', '777-123-456 asap', '(+420) 608 111 222', '00420 777 123 456', 'Tel.: 777 123 456', 'card 4111 1111 1111 1111', "\u{FF17}\u{FF17}\u{FF17} 123 456"];
$redactLeaks = array_filter($redactPositive, static fn (string $t): bool => preg_match('/@|\x{FF20}|\x{FE6B}|firma|\d{3}[ -]?\d{3}|1111/u', Redact::text($t)) === 1);
check('3.16 N316-2: Core\Redact masks e-mail addresses (spaced, written out, full-width, entities, invisible characters) and phone numbers', array_values($redactLeaks), []);
check('3.16 N316-2: Core\Redact – what it leaves in place', array_map(static fn (string $t): string => Redact::text($t), [
    'Price 12 990 Kč', 'Budget 1 250 000 000 Kč', 'Price 15.000,- Kč', 'Meeting 19. 10. 2026 14.30', '2026-10-19 10:00', '2026-10-01T03:00:00+02:00', 'Version 3.16.0', 'Years 2025–2026',
    'Update 3.15.4 → 3.16.0', 'Hours 9:00–17:00', 'Order 12345', 'Look at page 3.2', 'We meet at 10.30', '19.10.2026', 'Tom &amp; Jerry, 25 m²', "Plain\u{202E}text\u{0007}"]),
    ['Price 12 990 Kč', 'Budget 1 250 000 000 Kč', 'Price 15.000,- Kč', 'Meeting 19. 10. 2026 14.30', '2026-10-19 10:00', '2026-10-01T03:00:00+02:00', 'Version 3.16.0', 'Years 2025–2026',
    'Update 3.15.4 → 3.16.0', 'Hours 9:00–17:00', 'Order 12345', 'Look at page 3.2', 'We meet at 10.30', '19.10.2026', 'Tom &amp; Jerry, 25 m²', 'Plaintext']);
check('3.16 N316-2: the heartbeat, the report and the e-mail mask with the same Core\Redact', [Kaleta\Fleet\Heartbeat::cleanTitle('Call jan [at] firma [dot] cz or +420 777 123 456'),
    MonthlyReport::scrub(['t' => 'Call jan [at] firma [dot] cz or +420 777 123 456'])['t'], Kaleta\Fleet\Heartbeat::cleanTitle('jan&amp;#64;firma.cz &amp; co')],
    ['Call [e-mail] or [phone]', 'Call … or …', '[e-mail] & co']);
$legacy316 = MonthlyReport::scrub($report316);
check('3.16 N316-1: a run list with names and summaries (a report stored before the fix) is dropped on every read; runs are counted by task and result',
    [isset($legacy316['claude']['run_list']), $legacy316['claude']['runs'], str_contains((string) json_encode($legacy316), 'Weekly review')], [false, 4, false]);
$runs316 = $report316;
$runs316['claude']['run_tasks'] = ['triage' => ['ok' => 2, 'partial' => 1], 'mystery' => ['failed' => 1]];
$runsSection316 = array_values(array_filter(Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Core\ReportPage::sections($runs316), 'admin-'), static fn (array $s): bool => $s['key'] === 'claude'))[0];
check('3.16 N316-1: the printed report lists scheduled runs by task and result – no name, no summary', [$runsSection316['blocks'][1]['caption'], $runsSection316['blocks'][1]['head'], $runsSection316['blocks'][1]['rows']],
    ['Scheduled runs by task', ['Task', 'Result', 'Runs'], [['Enquiry triage', 'Done', '2'], ['Enquiry triage', 'Partly done', '1'], ['Custom instructions', 'Failed', '1']]]);
check('3.16 N316-4: css_string escapes every control character, the quotes, the backslash and "<"', [css_string("Acme\f} .x::before{content:\"x\"}\n@page{x:'"), css_string("a\\b\x0B\u{2028}</style>"), css_string('Studio Kaleta · September 2026')],
    ['"Acme\\c } .x::before{content:\\22 x\\22 }\\a @page{x:\\27 "', '"a\\5c b\\b \\2028 \\3c /style\\3e "', '"Studio Kaleta · September 2026"']);
$evil316 = Kaleta\Core\ReportPage::render($view316, $stored316, $reportSettings(['site_name' => "Acme\f} .listina::before{content:\"Pay to CZ65\";display:block} @page{x:\"\nnext"]), 'https://example.com');
preg_match('/@bottom-left \{ content: (.*?); font:/', $evil316, $bottom316);
check('3.16 N316-4: a form feed or a line break in the site name stays inside the CSS string of the print page', [preg_match('/[\x00-\x1F]/', $bottom316[1] ?? "\f"), str_contains($bottom316[1] ?? '', 'Acme\c } .listina'), substr_count($bottom316[1] ?? '', '"')],
    [0, true, 2]);
$rebuiltSections316 = Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Core\ReportPage::sections($report316 + ['security' => []], '19 Oct 2026 10:00'), 'admin-');
$byKey316 = array_column($rebuiltSections316, null, 'key');
$notes316 = static fn (array $section): array => array_values(array_map(static fn (array $b): string => $b['text'], array_filter($section['blocks'], static fn (array $b): bool => $b['type'] === 'note')));
check('3.16 N316-7: a rebuilt report says that what waits, the audit, the problems and the version are from the day of the rebuild', [$byKey316['open']['title'],
    in_array('As on 19 Oct 2026 10:00, when the report was rebuilt – not at the end of the month.', $notes316($byKey316['audit']), true),
    in_array('As on 19 Oct 2026 10:00, when the report was rebuilt – not at the end of the month.', $notes316($byKey316['problems']), true), $byKey316['updates']['blocks'][0]['rows'][0][0],
    $sections316[0]['title']], ['Waiting when the report was rebuilt', true, true, 'Kaleta version when the report was rebuilt', 'Waiting at the end of the month']);
$zone316 = date_default_timezone_get();
date_default_timezone_set('Europe/Prague');
$iso316 = $report316;
$iso316['security']['firewall_from'] = '2026-09-02T00:30:00+02:00';
$iso316['work']['pages_published'][0]['date'] = '2026-09-05T22:30:00+00:00';
$isoSections316 = array_column(Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Core\ReportPage::sections($iso316), 'admin-'), null, 'key');
date_default_timezone_set('Europe/London');
$londonSections316 = array_column(Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Core\ReportPage::sections($iso316), 'admin-'), null, 'key');
date_default_timezone_set($zone316);
check('3.16 N316-9: moments stored as ISO 8601 with the offset print in the site zone of the day – a change of the time zone needs no conversion of the JSON',
    [$isoSections316['work']['blocks'][1]['rows'][0][1], $londonSections316['work']['blocks'][1]['rows'][0][1], MonthlyReport::moment('2026-09-05 10:00:00')?->format('H:i'), MonthlyReport::moment('nonsense'), MonthlyReport::moment(null)],
    ['6 Sep 2026', '5 Sep 2026', '10:00', null, null]);
check('3.16 N316-7: the firewall keeps 30 days – a report says from when its firewall counts are complete', in_array(Kaleta\Core\Language::runWith('en', fn (): string => t('The firewall keeps its log for 30 days, so requests blocked before %s are not counted.', format_date('2026-09-02 00:30:00', true)), 'admin-'),
    $notes316($isoSections316['security']), true), true);
// 3.26.1: reports you want to read – the e-mail, the report page and get_monthly_report share Core\ReportVisual blocks and
// Core\ReportSummary: headline tiles, what it took to run the site (counted, never hours or money), uptime only with a console
use Kaleta\Core\ReportSummary;
use Kaleta\Core\ReportVisual;
$care3261 = $report316 + ['care' => ['job_runs' => ['domain_watch' => 90, 'security' => 90], 'checks' => ['domain_watch' => 30, 'security' => 31], 'people_changes' => 12, 'offsite_copies' => 20,
    'links_healed' => 1, 'redirects_auto' => 2, 'comments' => 4, 'spam' => 6, 'enquiries_handled' => 2, 'replies' => 3, 'attention' => ['content.review' => 2, 'backup.failed' => 1, 'whatever.else' => 9]]];
$care3261['stats']['days'] = array_merge(array_fill(0, 29, 4), [8]);
$care3261['stats']['pages'][] = ['path' => '/kontakt', 'n' => 40];
$careOf = ReportSummary::care($care3261);
check('3.26.1: ReportSummary::care – what the site did by itself, Claude and people, the details outside the totals, the decisions by line (unknown events ignored, System status errors counted)',
    [$careOf['automatic'], $careOf['claude'], $careOf['people'], $careOf['notes'], $careOf['attention'], $careOf['totals']],
    [['updates' => 1, 'backups' => 20, 'offsite_copies' => 20, 'checks' => 61, 'links_fixed' => 3, 'spam' => 6, 'accounts_closed' => 1], ['requests' => 1, 'sessions' => 5, 'runs' => 4],
        ['changes' => 12, 'comments' => 4], ['blocked' => 42, 'claude_changes' => 7, 'enquiries_handled' => 2, 'replies' => 3], ['backups' => 1, 'reviews' => 2],
        ['automatic' => 112, 'claude' => 10, 'people' => 16, 'all' => 138, 'attention' => 3]]);
$legacy3261 = ReportSummary::care(['month' => '2026-09', 'updates' => [['type' => 'update.applied'], ['type' => 'update.rolled_back']], 'backups' => ['created' => 30, 'failed' => 2], 'changes' => ['people' => 4, 'claude' => 9], 'decisions' => ['errors' => 1]]);
check('3.26.1: a report stored before 3.26.1 – only what its parts say (no checks, copies, spam or comments – never a guess), the decisions from its failed updates and backups, no Claude group when Claude was off',
    [$legacy3261['automatic'], $legacy3261['claude'], $legacy3261['people'], $legacy3261['notes'], $legacy3261['attention'], $legacy3261['totals']['attention']],
    [['updates' => 1, 'backups' => 30], null, ['changes' => 4], [], ['updates' => 1, 'backups' => 2, 'errors' => 1], 4]);
$tiles3261 = Kaleta\Core\Language::runWith('en', fn (): array => ReportSummary::tiles($care3261 + ['uptime' => ['percent' => 99.954, 'checks' => 8000, 'failed' => 4, 'days' => []]],
    ['enquiries' => ['total' => 6], 'work' => ['pages_published_count' => 2, 'news_published_count' => 1, 'look_published' => 0], 'uptime' => ['percent' => 100]], $careOf), 'admin-');
check('3.26.1: the headline tiles – visits, enquiries, uptime and changes published (four at most), the change against the month before from the data or the archived month',
    array_map(static fn (array $t): string => $t['label'] . '=' . $t['value'] . '|' . ($t['change'] ?? $t['note'] ?? '') . '|' . ($t['tone'] ?? ''), $tiles3261),
    ['Visits=120|▲ 20 %|up', 'Enquiries=3|▼ 50 %|down', 'Uptime=99.95 %|100 % last month|same', 'Changes published=3|= same as last month|same']);
check('3.26.1: tiles of a quiet month without statistics or enquiries – at least three (backups, upkeep tasks); the change from 0',
    Kaleta\Core\Language::runWith('en', fn (): array => [array_column(ReportSummary::tiles(['backups' => ['created' => 4], 'work' => ['pages_published_count' => 1]], ['work' => []], ReportSummary::care(['backups' => ['created' => 4]])), 'label'),
        ReportSummary::change(5, 0), ReportSummary::change(0, 0), ReportSummary::change(5, null)], 'admin-'),
    [['Changes published', 'Backups made', 'Upkeep tasks'], ['change' => '▲ up from 0', 'tone' => 'up'], ['change' => '= same as last month', 'tone' => 'same'], []]);
check('3.26.1: ReportVisual palette – the site accent kept when bars and links read on white, a pale accent darkened to 3 : 1 (bars) and 4.5 : 1 (links), anything else Ink',
    [ReportVisual::paletteFor('#2B5BE3')['accent'], ReportVisual::paletteFor('#2b5be3')['onAccent'], Kaleta\Builder\DesignSystem::contrast(ReportVisual::paletteFor('#ffd400')['accent'], '#ffffff') >= 3.0,
        Kaleta\Builder\DesignSystem::contrast(ReportVisual::paletteFor('#ffd400')['link'], '#ffffff') >= 4.5, ReportVisual::paletteFor('red')['accent'], ReportVisual::paletteFor('#fff')['accent']],
    ['#2b5be3', '#ffffff', true, true, '#121212', '#121212']);
$mail3261 = MonthlyReport::render($care3261, $reportSettings(['design_system' => json_encode(['barvy' => ['primarni' => '#0f766e']])]), 'https://example.com', ['enquiries' => ['total' => 6]]);
$html3261 = $mail3261['html'];
check('3.26.1: the e-mail – the band in the site accent, the opening sentences, tiles, the split of the work and its groups, the decisions; the daily chart and bars from the data',
    [str_contains($html3261, 'background:#0f766e;border-radius:13px 13px 0 0'), str_contains($html3261, 'Keeping Testovací firma running took <strong>138</strong> tasks: <strong>112</strong> done automatically, <strong>10</strong> by Claude and <strong>16</strong> by people.'),
        substr_count($html3261, 'class="kr-tile"'), str_contains($html3261, 'What it took to run the site'), str_contains($html3261, 'Done automatically'), str_contains($html3261, 'Daily checks of the domain, certificate and security'),
        str_contains($html3261, 'The site needed your attention 3 times this month.'), substr_count($html3261, 'valign="bottom" style="height:64px'), str_contains($html3261, 'Most in a day: 8'),
        str_contains($html3261, 'width="50%" style="width:50%;background:#0f766e;height:6px'), str_contains($html3261, 'Open the full report')],
    [true, true, 3, true, true, true, true, 30, true, true, true]);
check('3.26.1: the e-mail is e-mail safe – tables and inline styles, no SVG, script or web font, at most 600 px, light and dark declared with dark rules, explicit backgrounds, under the 102 KB Gmail clips at',
    [str_contains($html3261, '<svg'), str_contains($html3261, '<script'), str_contains($html3261, '@font-face') || str_contains($html3261, 'fonts.googleapis'), str_contains($html3261, 'max-width:600px'),
        str_contains($html3261, '<meta name="color-scheme" content="light dark">'), str_contains($html3261, '@media (prefers-color-scheme:dark){.kr-page{background:#121212!important}'),
        str_contains($html3261, '<body class="kr-page" style="margin:0;padding:0;background:#f6f4ee;">'), preg_match('/<(div|p|span)\b(?![^>]*style=)[^>]*>[^<\s]/', $html3261), strlen($html3261) < 100000,
        str_contains($html3261, '<!--[if mso]>')],
    [false, false, false, true, true, true, true, 0, true, true]);
$text3261 = $mail3261['text'];
check('3.26.1: the text part says everything the HTML says – the opening, the tiles with their change, the work by group and its lines, the decisions, traffic, the link to the full report',
    [str_contains($text3261, "Keeping Testovací firma running took 138 tasks: 112 done automatically, 10 by Claude and 16 by people. It needed your attention 3 times."),
        str_contains($text3261, "AT A GLANCE\n  Visits: 120 (▲ 20 %)\n  Enquiries: 3 (▼ 50 %)"), str_contains($text3261, "  Done automatically: 112\n    Kaleta updates installed: 1\n    Backups made: 20"),
        str_contains($text3261, '    Suspicious requests stopped by the firewall: 42'), str_contains($text3261, "    – Backups that failed: 1"), str_contains($text3261, "TRAFFIC\n  Visits: 120 (+20 %)"),
        str_contains($text3261, 'Open the full report: https://example.com/admin.php?module=settings&tab=reports'), str_contains($text3261, '<')],
    [true, true, true, true, true, true, true, false]);
$words3261 = '/\b(hours?|minutes?|hodin\w*|minut\w*|Stunden?|horas?|price\w*|cost\w*|invoice\w*|money|cena|ceny|Kosten|Preis\w*|precio\w*|EUR|USD|CZK|Kč)\b|[€$]/iu';
$cleanData3261 = array_replace($care3261, ['requests' => ['created' => 1, 'done' => [], 'done_count' => 1, 'open' => [], 'open_count' => 0]]);
$noHours3261 = [];
foreach (['en', 'cs', 'de', 'es'] as $language) {
    $m = MonthlyReport::render($cleanData3261 + ['uptime' => ['percent' => 99.9, 'checks' => 10, 'failed' => 1, 'days' => []]], $reportSettings(['site_language' => $language]), 'https://example.com');
    $page = Kaleta\Core\ReportPage::render($view316, ['month' => '2026-09', 'created_at' => '2026-10-01 03:00:00', 'rebuilt_at' => null, 'data' => $cleanData3261], $reportSettings(['site_language' => $language]), 'https://example.com');
    preg_match_all($words3261, strip_tags(preg_replace('/<style>.*?<\/style>/s', '', $m['html'] . $page)) . $m['text'], $found);
    $noHours3261[$language] = array_values(array_unique($found[0]));
}
check('3.26.1: a summary of what happened – no hours, minutes, prices or money anywhere in the e-mail, its text or the report page, in any language', $noHours3261, ['en' => [], 'cs' => [], 'de' => [], 'es' => []]);
$evilData3261 = array_replace_recursive($care3261, ['stats' => ['pages' => [['path' => '/"><img src=x onerror=alert(1)>', 'n' => 5]]], 'work' => ['news_published' => [['title' => '<script>alert(1)</script> & co', 'date' => '2026-09-10 08:00:00']]],
    'enquiries' => ['forms' => [['form' => "Kontakt' style='x", 'n' => 3]]], 'problems' => [['name' => '<b>Cron</b>', 'info' => 'x', 'state' => 'chyba']]]);
$evil3261 = MonthlyReport::render($evilData3261, $reportSettings(['site_name' => 'Acme <i>&</i> "Co"', 'agency_name' => 'Studio <script>']), 'https://example.com');
$evilPage3261 = Kaleta\Core\ReportPage::render($view316, ['month' => '2026-09', 'created_at' => '2026-10-01 03:00:00', 'rebuilt_at' => null, 'data' => $evilData3261], $reportSettings(['site_name' => 'Acme <i>&</i> "Co"']), 'https://example.com');
check('3.26.1: every text from the data and the settings is escaped in the e-mail and on the page – paths, titles, form names, problems, the site and agency names',
    [str_contains($evil3261['html'] . $evilPage3261, '<img src=x'), str_contains($evil3261['html'] . $evilPage3261, '<script>alert'), str_contains($evil3261['html'], "Kontakt' style"), str_contains($evil3261['html'] . $evilPage3261, '<b>Cron'),
        str_contains($evil3261['html'] . $evilPage3261, '<i>&</i>'), str_contains($evil3261['html'], 'Studio <script>'), str_contains($evil3261['html'], '&lt;script&gt;alert(1)&lt;/script&gt; &amp; co'), str_contains($evil3261['html'], 'Acme &lt;i&gt;&amp;&lt;/i&gt; &quot;Co&quot;')],
    [false, false, false, false, false, false, true, true]);
$uptimeMail3261 = MonthlyReport::render($care3261 + ['uptime' => ['percent' => 99.5, 'checks' => 8640, 'failed' => 43, 'days' => [['date' => '2026-09-01', 'state' => 'up', 'percent' => 100.0]]]], $reportSettings([]), 'https://example.com');
check('3.26.1: uptime only with a console – the tile and the section with the checks when the data has it; without "uptime" (no console) the e-mail has no uptime at all',
    [str_contains($uptimeMail3261['html'], '>Uptime</div>'), str_contains($uptimeMail3261['html'], '99.50 % of the time up – 8,640 checks by the console, 43 of them failed.'), preg_match('/UPTIME\n  [^\n]*99\.50 %/u', $uptimeMail3261['text']) === 1, // the line of Core\UptimeStrip::text once it is there (agent B), until then the report's own
        str_contains($html3261, 'Uptime'), str_contains($text3261, 'UPTIME'), str_contains(MonthlyReport::render($care3261 + ['uptime' => null], $reportSettings([]), 'https://example.com')['html'], 'Uptime')],
    [true, true, true, false, false, false]);
$old3261 = MonthlyReport::render(['month' => '2026-09', 'stats' => ['visits' => 4321], 'enquiries' => ['total' => 17, 'forms' => [['form' => 'Kontakt', 'n' => 17]]], 'requests' => ['done_count' => 3, 'done' => [['title' => 'Secret title 317']]],
    'work' => ['areas' => ['pages' => ['people' => 4, 'claude' => 9]]], 'updates' => [['type' => 'update.applied']], 'backups' => ['created' => 30]], $reportSettings(['site_language' => 'cs']), 'https://example.com');
check('3.26.1: an archived report of 3.16–3.26 with missing parts still renders – in Czech, tiles of what is there, no checks line, no title of a request, the changes of people from its change log by area (N3261-2)',
    [str_contains($old3261['html'], 'Co dal provoz webu za práci'), substr_count($old3261['html'], 'class="kr-tile"'), str_contains($old3261['html'], 'Denní kontroly'), str_contains($old3261['html'] . $old3261['text'], 'Secret title'),
        str_contains($old3261['text'], 'Provoz webu Testovací firma si vyžádal 38 úkonů: 31 proběhlo automaticky, 3 udělal Claude a 4 lidé.')],
    [true, 3, false, false, true]);
$visualPage3261 = Kaleta\Core\ReportPage::render($view316, ['month' => '2026-09', 'created_at' => '2026-10-01 03:00:00', 'rebuilt_at' => null, 'data' => $care3261], $reportSettings(['design_system' => json_encode(['barvy' => ['primarni' => '#9a3412']])]), 'https://example.com/', [], ['enquiries' => ['total' => 6]]);
check('3.26.1: the report page shares the e-mail\'s blocks – the band and bars in the accent, the tiles, what it took to run the site; dark on a dark screen only, never in print; the fleet report has no visual summary',
    [str_contains($visualPage3261, '--akcent: #9a3412;'), substr_count($visualPage3261, 'class="kr-tile"'), str_contains($visualPage3261, 'What it took to run the site'), str_contains($visualPage3261, '<section class="prehled" id="summary"'),
        str_contains($visualPage3261, '@media screen and (prefers-color-scheme: dark)'), str_contains($visualPage3261, '.kr-card{background:#1c1c1b!important'), str_contains($visualPage3261, 'max-width:225px'),
        str_contains(Kaleta\Fleet\Report::render($view316, ['month' => '2026-09', 'customer' => 'X', 'created_at' => '2026-10-01 03:00:00', 'rebuilt_at' => null, 'data' => ['sites' => []]], $reportSettings([]), 'https://console.example'), 'class="kr-tile"')],
    [true, 3, true, true, true, true, true, false]);
check('3.26.1: the off-site copy of a backup is a known event, the decisions the report counts are known events too', [isset(Kaleta\Core\Events::TYPES['backup.copied']),
    array_values(array_diff(array_keys(ReportSummary::ATTENTION_EVENTS), array_keys(Kaleta\Core\Events::TYPES))), array_values(array_diff(array_unique(array_values(ReportSummary::ATTENTION_EVENTS)), array_keys(ReportSummary::ATTENTION)))],
    [true, [], []]);
$ok = [200, 'KALETA-PROBE 9.9.9'];
check('2.8: Updater::probeVerdict', [
    Kaleta\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => [0, ''], 'home' => [0, ''], 'admin' => [0, '']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [500, 'Fatal'], 'admin' => [200, 'x']], '9.9.9') !== null,
    Kaleta\Core\Updater::probeVerdict(['probe' => [200, 'KALETA-PROBE 2.7.0'], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => [500, ''], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9') !== null,
    Kaleta\Core\Updater::probeVerdict(['probe' => $ok, 'home' => [301, ''], 'admin' => [302, '']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => [403, 'Access denied.'], 'home' => [200, 'x'], 'admin' => [200, 'x']], '9.9.9'),
    Kaleta\Core\Updater::probeVerdict(['probe' => [200, 'KALETA-PROBE 2.7.0'], 'home' => [500, 'Fatal'], 'admin' => [200, 'x']], '9.9.9') !== null],
    [null, null, true, null, true, null, null, true]);
// 2.8: the firewall – networks, the address behind Cloudflare only from Cloudflare, countries, the manual list
use Kaleta\Core\Firewall;
check('2.8: Firewall::inList', [Firewall::inList('198.51.100.77', ['198.51.100.0/24']), Firewall::inList('198.51.101.1', ['198.51.100.0/24']), Firewall::inList('203.0.113.7', ['203.0.113.7']),
    Firewall::inList('10.1.2.3', ['10.0.0.0/9']), Firewall::inList('10.200.0.1', ['10.0.0.0/9']), Firewall::inList('2001:db8::1', ['2001:db8::/32']), Firewall::inList('2001:db9::1', ['2001:db8::/32']),
    Firewall::inList('not-an-ip', ['0.0.0.0/8']), Firewall::inList('198.51.100.7', ['2001:db8::/32'])], [true, false, true, true, false, true, false, false, false]);
check('2.8: Firewall::parseList and isValidEntry', [Firewall::parseList("203.0.113.7 # bot\n\n198.51.100.0/24\nnonsense\n10.0.0.0/4\n2001:db8::/32"), Firewall::isValidEntry('300.1.1.1')],
    [[['203.0.113.7', '198.51.100.0/24', '2001:db8::/32'], ['nonsense', '10.0.0.0/4']], false]);
check('2.8: Firewall::visitorIp – Cloudflare only from its addresses', [
    Firewall::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], 'cloudflare'),
    Firewall::visitorIp(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_CF_CONNECTING_IP' => '198.51.100.1'], 'cloudflare'),
    Firewall::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => '203.0.113.9'], ''),
    Firewall::visitorIp(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_CONNECTING_IP' => 'junk'], 'cloudflare')],
    ['203.0.113.9', '203.0.113.50', '172.70.1.2', '172.70.1.2']);
check('2.8: Firewall::country and countries', [Firewall::country(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_IPCOUNTRY' => 'ru'], 'cloudflare'), Firewall::country(['REMOTE_ADDR' => '203.0.113.50', 'HTTP_CF_IPCOUNTRY' => 'RU'], 'cloudflare'),
    Firewall::country(['GEOIP_COUNTRY_CODE' => 'CN'], ''), Firewall::country(['REMOTE_ADDR' => '172.70.1.2', 'HTTP_CF_IPCOUNTRY' => 'XX'], 'cloudflare'), Firewall::countries("ru, CN;\nde x"),
    Firewall::countryBlocked('RU', 'RU, CN'), Firewall::countryBlocked('', 'RU')], ['RU', '', 'CN', '', ['RU', 'CN', 'DE'], true, false]);
check('2.8: Firewall::PROBE_PATHS – probes count, missing images and old WordPress uploads never', array_map(fn (string $p): bool => preg_match(Firewall::PROBE_PATHS, $p) === 1,
    ['wp-login.php', 'xmlrpc.php', '.env', 'app/.git/config', 'phpmyadmin/index.php', 'backup.sql', 'wp-content/uploads/2020/05/foto.jpg', 'image/logo.png', 'robots.txt', 'sitemap.xml', 'o-nas', '.well-known/security.txt']),
    [true, true, true, true, true, true, false, false, false, false, false, false]);
check('2.8: Firewall::isLocal', array_map(Firewall::isLocal(...), ['127.0.0.1', '10.0.0.5', '192.168.1.1', '::1', '203.0.113.7', '2a00:1450::1']), [true, true, true, true, false, false]);
// 2.7: a reveal and a motion while scrolling run together; a hover effect gets its own rule, its motion only without reduced motion
$motion = Kaleta\Builder\Style::css('#a', ['zaklad' => ['animace' => 'ka-zleva', 'pohyb' => 'ka-paralaxa', 'najeti' => 'zvednout']]);
check('2.7: scroll motion and hover effect in the CSS', [str_contains($motion, 'animation: ka-zleva linear both, ka-paralaxa linear both; animation-timeline: view(), view(); animation-range: entry 0% cover 28%, cover 0% cover 100%'),
    str_contains($motion, '@media (prefers-reduced-motion: no-preference) { #a { transition:'), str_contains($motion, '#a:is(:hover, :focus-visible) { translate: 0 -4px;'),
    Kaleta\Builder\Style::css('#b', ['zaklad' => ['animace' => 'none', 'pohyb' => 'ka-nesmysl']])],
    [true, true, true, '']);
// 2.7: the migration report reads what the old page had, counts forms and images of a build, and old form entries are checked
$oldPage = '<html><head><title>Services | Acme</title><meta name="description" content="What we do"></head><body><header><form role="search"><input type="search" name="s"></form></header>'
    . '<main><h1>Services</h1><p>' . str_repeat('We build kitchens and bathrooms. ', 5) . '</p><img src="/a.jpg"><img src="/b.jpg"><img src="/c.jpg"><form action="/contact"><input name="email"><textarea name="m"></textarea></form></main></body></html>';
$searchOnly = '<html><body><main><p>' . str_repeat('Text of the page. ', 8) . '</p></main><form class="search-form"><input name="s"></form></body></html>';
check('2.7: MigrationReport::analyse', [Kaleta\Core\MigrationReport::analyse($oldPage, 'https://old.example/services/'), Kaleta\Core\MigrationReport::analyse($searchOnly, 'https://old.example/')['formular']],
    [['titulek' => 'Services | Acme', 'popis' => 'What we do', 'formular' => true, 'obrazky' => 3, 'formulare' => ['email,m']], false]); // 3.33: the form by its fields
check('2.7: MigrationReport::countElements', Kaleta\Core\MigrationReport::countElements([
    ['typ' => 'sekce', 'deti' => [['typ' => 'obrazek'], ['typ' => 'galerie', 'obsah' => ['fotky' => [['src' => 'a'], ['src' => 'b']]]], ['typ' => 'kontejner', 'deti' => [['typ' => 'formular']]]]],
    ['typ' => 'text', 'obsah' => ['html' => '<p><img src="x"></p>']]]), [1, 4]);
check('2.7: import_enquiries checks each entry', [
    Kaleta\Mcp\Tools::enquiryEntry(['date' => '2025-03-14 09:30', 'form' => 'Contact', 'page' => '/contact', 'fields' => ['Name' => 'Jana', 'E-mail' => 'jana@example.cz', 'Message' => '<b>Hi</b>', 'Empty' => '']]),
    Kaleta\Mcp\Tools::enquiryEntry(['date' => 'yesterday-ish?', 'fields' => ['a' => 'b']]), Kaleta\Mcp\Tools::enquiryEntry(['date' => '2025-01-01', 'fields' => []]),
    Kaleta\Mcp\Tools::enquiryEntry(['date' => '2025-01-01 10:00', 'email' => 'not-an-email', 'fields' => [['label' => 'Phone', 'value' => '777 123 456']]])['email']],
    [['datum' => '2025-03-14 09:30:00', 'formular' => 'Contact', 'stranka' => '/contact', 'email' => 'jana@example.cz', 'data' => [['Name', 'Jana'], ['E-mail', 'jana@example.cz'], ['Message', 'Hi']]],
    'date must be a date and time, e.g. 2025-03-14 09:30', 'fields are empty', '']);
// 2.2: data migrations run by name – the list in Core\Migration is the PHP files
check('2.2: Migration::DATA lists every PHP data migration', Kaleta\Core\Migration::DATA, array_values(array_map(fn (string $f): string => basename($f, '.php'),
    array_filter(Kaleta\Core\Migration::files(), fn (string $f): bool => str_ends_with($f, '.php')))));
// 2.3: the Embed element takes only known services and builds their frame address; image/web.js allows the same
$embed = fn (string $u): ?string => Kaleta\Builder\Elements\Embed::resolve($u)[1] ?? null;
check('2.3: Embed – known services only', [$embed('https://calendly.com/acme/consultation'), $embed('https://docs.google.com/forms/d/e/1FAIpQLSf_x-1/viewform?usp=sf_link'),
    $embed('https://tally.so/r/w7ZyYq'), $embed('https://open.spotify.com/track/4uLU6hMCjMI75M1A2tKUQC?si=x'), $embed('https://soundcloud.com/artist/track-name'),
    $embed('https://evil.example/calendly.com/x'), $embed('javascript:alert(1)'), $embed('https://calendly.com/a/b"onload=x')],
    ['https://calendly.com/acme/consultation?embed_type=Inline&hide_gdpr_banner=1', 'https://docs.google.com/forms/d/e/1FAIpQLSf_x-1/viewform?embedded=true',
    'https://tally.so/embed/w7ZyYq?alignLeft=1&transparentBackground=1', 'https://open.spotify.com/embed/track/4uLU6hMCjMI75M1A2tKUQC',
    'https://w.soundcloud.com/player/?url=https%3A%2F%2Fsoundcloud.com%2Fartist%2Ftrack-name', null, null, null]);
preg_match('/if \(!(\/\^https:.*?\/)\.test\(address\)\)/', (string) file_get_contents(KALETA_ROOT . '/image/web.js'), $webJsAllow);
$jsPattern = '#' . str_replace('\\/', '/', substr($webJsAllow[1] ?? '//', 1, -1)) . '#';
check('2.3: every Embed frame address is allowed in image/web.js', array_keys(array_filter(Kaleta\Builder\Elements\Embed::SERVICES,
    fn (array $s): bool => preg_match($jsPattern, sprintf($s[3], 'x')) !== 1)), []);
// 2.3.1: in a language version, a path that already names its language keeps it (a menu link /cs/funkce, not /cs/cs/funkce)
$urlApp = new Kaleta\Core\App([]);
$urlApp->languagePrefix = 'cs';
check('2.3.1: App::url does not double the language prefix', [$urlApp->url('cs/funkce'), $urlApp->url('/cs/funkce'), $urlApp->url('funkce'), $urlApp->url('cs'), $urlApp->url('image/x.svg'), $urlApp->url('css-tricks')],
    array_map(fn (string $p): string => $urlApp->request->basePath() . $p, ['/cs/funkce', '/cs/funkce', '/cs/funkce', '/cs', '/image/x.svg', '/cs/css-tricks']));
// 2.3.1: every field a settings tab posts is read by the save – a setting, the "remove" box of a secret setting, or one of the
// few the save reads on purpose (a field under an old name was silently dropped: saving General removed the language versions)
$settingsFields = [];
foreach ((new ReflectionClass(Kaleta\Admin\Modules\Settings::class))->getReflectionConstant('FIELDS')->getValue() as $tabFields) {
    foreach ($tabFields as $key => $type) {
        $settingsFields[$key] = true;
        if (str_starts_with($type, 'tajne')) {
            $settingsFields[$key . '_smazat'] = true;
        }
    }
}
$unknownFields = [];
foreach (glob(KALETA_SYSTEM . '/views/admin/settings/*.php') as $view) {
    preg_match_all('/name="([a-z_]+)(?:\[\])?"/', (string) file_get_contents($view), $viewNames);
    foreach (array_unique($viewNames[1]) as $name) {
        if (!isset($settingsFields[$name]) && !in_array($name, ['rozsireni', 'ai_poskytovatel_puvodni', 'novy_token_ulohy', 'novy_token', 'soubor', 'tab', 'id', 'ip', 'pairing_key', 'fleet_updates',
            'exception', 'exception_from', 'exception_to', 'exception_closed', 'exception_hours', 'exception_note', 'exception_notice', 'viewport', 'robots', // viewport, robots: <meta> of the door sign
            'fleet_kit', // 2.16: the console tab's own button (Settings::actionFleetKit)
            'fleet_request_titles', // 3.16: the console tab's own button (Settings::actionFleetTitles)
            'verze', // 3.3.2: the version on the update button – Settings::actionUpdate installs only that one
            'precteno', // 3.15: "I have read what changes" – Settings::actionUpdateMajor installs a major version only with it
            'smtp_ses_region', // 3.9: the Amazon SES region – MailServices::settle() builds the server from it
            'zone', 'choice', // 3.12: the time zone confirmation (Settings::actionTimeZone) – the new zone and convert | keep
            'site_url_confirm', 'soucasne', // 3.35 review M3: a new host of the site address – the confirmation and the fresh proof (Settings::signInChangeRefusal)
            'month', // 3.16: the month of an archived report on its Send and Rebuild buttons (Settings::actionReportSend, actionReportRebuild)
            'general_split', // 3.23 (C12): the marker of the split General form – Settings::actionSave then saves only the General fields
            'module', // 3.23.1 (N-C12): the tab select on a phone is its own GET form (module=settings&tab=…), never posted to the save
            'recovery_key', 'saved', // 3.28 (D-W12): the recovery key for a restore or to put back (never saved as a setting), "I have saved it" of its confirmation
            'screen_collections', 'novy_token_obrazovka'], true)) { // 2.11 screen mode: the collections list is added by fields() from the site's collections, the button makes a new address
            $unknownFields[] = basename($view) . ': ' . $name;
        }
    }
}
check('2.3.1: settings forms post only fields the save reads', $unknownFields, []);
// 2.1: public contracts – MCP tools and parameters, design tokens and builder elements are never removed or changed
// outside the deprecation policy; an addition is recorded with php tools/contracts.php --update
require_once dirname(__DIR__, 2) . '/contracts.php';
check('2.1: public contracts kept (tools/contracts)', kaleta_contract_diff(), ['broken' => [], 'added' => []]);
// 3.8 (Connectors Directory): the contract lets annotations grow only – readOnlyHint and destructiveHint never change, a hint
// may only turn more careful, a title is display only
// 3.8 (Connectors Directory): Claude Code signs in through a loopback redirect on a port that changes every session
// (RFC 8252 §7.3) – the port of a registered loopback address does not have to match; everything else still does
$registered38 = ['https://claude.ai/api/mcp/auth_callback', 'http://127.0.0.1:33418/callback', 'http://localhost:33418/callback?x=1', 'http://[::1]:8080/cb'];
check('3.8 OAuth: a loopback redirect_uri matches with any port, nothing else is loosened', array_map(fn (string $uri): bool => Kaleta\Front\OAuth::redirectAllowed($uri, $registered38), [
    'https://claude.ai/api/mcp/auth_callback', 'http://127.0.0.1:51234/callback', 'http://127.0.0.1/callback', 'http://localhost:9/callback?x=1', 'http://[::1]:1/cb',
    'https://claude.ai:8443/api/mcp/auth_callback', 'http://127.0.0.1:51234/other', 'http://localhost:51234/callback', 'http://127.0.0.1:51234/callback?y=2', 'https://127.0.0.1:51234/callback',
    'http://evil.example:33418/callback', 'http://user@127.0.0.1:5/callback', 'http://127.0.0.1:5/callback#x']),
    [true, true, true, true, true, false, false, false, false, false, false, false, false]);
// N38-4: the port is a plain number 1–65535 – no :0, leading zeros, a sign, an empty port or a control character after it
check('3.8 N38-4: a loopback port must be a plain number, no control characters', array_map(fn (string $uri): bool => Kaleta\Front\OAuth::redirectAllowed($uri, $registered38), [
    'http://127.0.0.1:0/callback', 'http://127.0.0.1:01/callback', 'http://127.0.0.1:+1/callback', 'http://127.0.0.1:/callback', "http://127.0.0.1:5\0/callback",
    'http://127.0.0.1:65536/callback', 'http://127.0.0.1:65535/callback', "http://127.0.0.1:5/call\tback"]), [false, false, false, false, false, false, true, false]);
check('3.8: annotation changes in the contract – only additive or more careful ones pass', [
    kaleta_annotation_diff(['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false], ['title' => 'List pages', 'readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => true]),
    kaleta_annotation_diff(['readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => true], ['readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false]),
    kaleta_annotation_diff(['title' => 'A', 'readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true], ['title' => 'B', 'readOnlyHint' => false, 'destructiveHint' => true]),
    kaleta_annotation_diff(['readOnlyHint' => false, 'destructiveHint' => true], ['readOnlyHint' => false, 'idempotentHint' => true])],
    [['broken' => [], 'added' => ['annotation openWorldHint false → true', 'annotation title "List pages"']],
     ['broken' => ['annotation readOnlyHint changed', 'annotation openWorldHint changed'], 'added' => []],
     ['broken' => [], 'added' => ['annotation title "A" → "B"', 'annotation idempotentHint true → null']],
     ['broken' => ['annotation destructiveHint removed'], 'added' => ['annotation idempotentHint true']]]);
// 3.8: every tool has a short English title, the same in tools/list (top level) and in annotations.title
$titles38 = Kaleta\Mcp\Catalog::TITLES;
check('3.8: every MCP tool has a title – one per tool, unique, at most 40 characters, sentence case', [
    array_values(array_diff(array_keys(Kaleta\Mcp\Catalog::TOOLS), array_keys($titles38))), array_values(array_diff(array_keys($titles38), array_keys(Kaleta\Mcp\Catalog::TOOLS))),
    array_keys(array_filter(array_count_values($titles38), fn (int $n): bool => $n > 1)),
    array_keys(array_filter($titles38, fn (string $t): bool => mb_strlen($t) > 40 || preg_match('/^[A-Z][^.]*[^.\s]$/u', $t) !== 1))], [[], [], [], []]);
$listed38 = array_map(Kaleta\Mcp\Server::listed(...), Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()));
check('3.8: tools/list items lead with name and title, the title equals annotations.title, and nothing of the definition is lost', [
    array_values(array_filter($listed38, fn (array $t): bool => array_slice(array_keys($t), 0, 2) !== ['name', 'title'] || $t['title'] !== $t['annotations']['title'] || $t['title'] !== $titles38[$t['name']])),
    array_values(array_filter(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), fn (array $t): bool => array_diff_key(Kaleta\Mcp\Server::withReason($t), Kaleta\Mcp\Server::listed($t)) !== []))], [[], []]);
// 3.8: the hints by the English name – a Czech alias gets exactly the same annotations (before, the open-world list mixed
// Czech and English names and depended on which name the caller passed)
check('3.8: a Czech alias and the English name have the same annotations', array_values(array_filter(array_keys(Kaleta\Mcp\Catalog::TOOLS),
    fn (string $en): bool => Kaleta\Mcp\Tools::annotations((string) (Kaleta\Mcp\Translator::czech($en) ?? $en)) !== Kaleta\Mcp\Tools::annotations($en))), []);
check('3.8: openWorldHint – exactly the tools that fetch an outside address or e-mail people outside the site', [
    array_keys(array_filter(array_combine(array_keys(Kaleta\Mcp\Catalog::TOOLS), array_map(fn (string $t): bool => Kaleta\Mcp\Tools::annotations($t)['openWorldHint'], array_keys(Kaleta\Mcp\Catalog::TOOLS))))),
    array_map(fn (string $cs): bool => Kaleta\Mcp\Tools::annotations($cs)['openWorldHint'], ['nahraj_soubor', 'importuj_web', 'uloz_polozku_kolekce', 'seznam_medii']),
    array_values(array_diff(Kaleta\Mcp\Catalog::OPEN_WORLD, array_keys(Kaleta\Mcp\Catalog::TOOLS)))],
    [['save_collection_items', 'upload_file', 'import_website', 'migration_report', 'read_old_page', 'import_wordpress', 'request_testimonial', 'reply_to_enquiry', 'cancel_booking', 'confirm_booking', 'decline_booking', 'propose_booking_times', 'send_newsletter', 'reply_to_application'],
     [true, true, false, false], []]);
// the tools on the list really reach outside: each downloads through Core\ImageDownloader / WpImport::download, or e-mails
$handlers38 = implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), [...glob(KALETA_ROOT . '/system/src/Mcp/Handlers/*.php') ?: [], KALETA_ROOT . '/system/src/Mcp/Tools.php']));
$method38 = function (string $tool) use ($handlers38): string {
    $start = strpos($handlers38, 'function ' . Kaleta\Mcp\Catalog::method($tool) . '(');

    return $start === false ? '' : substr($handlers38, $start, (int) (strpos($handlers38, "\n    }\n", $start) ?: strlen($handlers38)) - $start);
};
check('3.8: every open-world tool\'s code downloads, imports or e-mails (or hands it to the class that does)', array_values(array_filter(Kaleta\Mcp\Catalog::OPEN_WORLD,
    fn (string $t): bool => preg_match('/ImageDownloader|WpImport::download|WebImport|MigrationReport|ItemBatch|Mailing::|Testimonials::request|EnquiryReply::send|Applications::reply|Booking::(confirm|decline|cancel|propose)|uploadFile|customer_notified/', $method38($t)) !== 1)), []);
check('3.8: idempotentHint only on write tools of the verified list, never on a read, a send, a publish or an import', [
    array_values(array_diff(Kaleta\Mcp\Catalog::IDEMPOTENT, array_keys(array_filter(Kaleta\Mcp\Catalog::TOOLS, fn (array $t): bool => $t[0] !== 'read')))),
    array_values(array_filter(array_keys(Kaleta\Mcp\Catalog::TOOLS), fn (string $t): bool => isset(Kaleta\Mcp\Tools::annotations($t)['idempotentHint']) !== in_array($t, Kaleta\Mcp\Catalog::IDEMPOTENT, true))),
    array_values(array_intersect(Kaleta\Mcp\Catalog::IDEMPOTENT, ['publish_build', 'publish_look', 'send_newsletter', 'send_test_newsletter', 'import_wordpress', 'import_website', 'edit_build', 'save_section', 'save_hours_exception', 'update_settings', 'create_page', 'create_news', 'upload_file']))],
    [[], [], []]);
// 3.10: tools on demand – an essentials connection lists the core tools, find_tools and the runners it needs
// (Mcp\Discovery); an "all" connection lists every tool exactly as before, never a meta tool
$all310 = array_map(Kaleta\Mcp\Server::listed(...), Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()));
$for310 = fn (string $access): array => array_values(array_filter($all310, fn (array $t): bool => Kaleta\Mcp\Catalog::allows($access, $t['name'])));
$essentials310 = Kaleta\Mcp\Discovery::essentials($all310, 'full');
$json310 = fn (mixed $v): int => strlen((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$metaOf310 = fn (array $list): array => array_values(array_filter(array_column($list, 'name'), fn (string $n): bool => isset(Kaleta\Mcp\Catalog::META[$n])));
check('3.10: the essentials are catalog tools of no extension, once each, in catalog order (undo_agent_session among them), then find_tools and the runners; the list stays under 50 KB', [
    array_values(array_diff(Kaleta\Mcp\Catalog::ESSENTIALS, array_keys(Kaleta\Mcp\Catalog::TOOLS))), array_keys(array_filter(array_count_values(Kaleta\Mcp\Catalog::ESSENTIALS), fn (int $n): bool => $n > 1)),
    array_values(array_filter(Kaleta\Mcp\Catalog::ESSENTIALS, fn (string $t): bool => Kaleta\Mcp\Catalog::extension($t) !== '')),
    array_column($essentials310, 'name') === [...array_values(array_intersect(array_column($all310, 'name'), Kaleta\Mcp\Catalog::ESSENTIALS)), 'find_tools', 'run_read_tool', 'run_tool', 'run_destructive_tool'],
    $json310(['tools' => $essentials310]) <= 50000, $json310(['tools' => $essentials310]) * 3 < $json310(['tools' => $all310]), in_array('undo_agent_session', Kaleta\Mcp\Catalog::ESSENTIALS, true)],
    [[], [], [], true, true, true, true]);
// N310-6: only the runners the connection's access can use – a read-only connection never lists a runner that changes
check('3.10 N310-6: the meta tools of a full, a drafts-only and a read-only essentials connection', [$metaOf310($essentials310),
    $metaOf310(Kaleta\Mcp\Discovery::essentials($for310('drafts'), 'drafts')), $metaOf310(Kaleta\Mcp\Discovery::essentials($for310('read'), 'read'))],
    [['find_tools', 'run_read_tool', 'run_tool', 'run_destructive_tool'], ['find_tools', 'run_read_tool', 'run_tool', 'run_destructive_tool'], ['find_tools', 'run_read_tool']]);
check('3.10: an "all" connection lists every catalog tool and no meta tool (the tools/list of 3.9.2)', [
    array_values(array_diff(array_keys(Kaleta\Mcp\Catalog::TOOLS), array_column($all310, 'name'))), array_values(array_diff(array_column($all310, 'name'), array_keys(Kaleta\Mcp\Catalog::TOOLS))),
    array_values(array_intersect(array_keys(Kaleta\Mcp\Catalog::META), array_column($all310, 'name'))), count($all310) === count(Kaleta\Mcp\Catalog::TOOLS)], [[], [], [], true]);
$meta310 = array_column(array_slice($essentials310, -4), null, 'name');
check('3.10: the meta tools – titles, annotations and schemas; none is a site tool (no method, no Translator entry, no Czech alias)', [
    array_map(fn (array $t): array => $t['annotations'], $meta310), array_keys((array) $meta310['run_read_tool']['inputSchema']['properties']), array_keys((array) $meta310['run_tool']['inputSchema']['properties']),
    array_keys((array) $meta310['run_destructive_tool']['inputSchema']['properties']), $meta310['run_destructive_tool']['inputSchema']['required'],
    Kaleta\Mcp\Catalog::english('run_tool'), Kaleta\Mcp\Translator::czech('find_tools'), method_exists(Kaleta\Mcp\Tools::class, Kaleta\Mcp\Catalog::method('run_destructive_tool')),
    array_values(array_intersect(array_keys(Kaleta\Mcp\Catalog::META), array_keys(Kaleta\Mcp\Catalog::TOOLS))), str_contains($meta310['run_destructive_tool']['description'], 'Confirm the exact action with the user'),
    array_keys(Kaleta\Mcp\Discovery::RUNNERS), Kaleta\Mcp\Catalog::toolSet('essentials'), Kaleta\Mcp\Catalog::toolSet(''), Kaleta\Mcp\Catalog::toolSet('everything')],
    [['find_tools' => ['title' => 'Find more tools', 'readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
      'run_read_tool' => ['title' => 'Run a read-only tool by name', 'readOnlyHint' => true, 'destructiveHint' => false, 'openWorldHint' => false],
      'run_tool' => ['title' => 'Run a tool that saves or changes', 'readOnlyHint' => false, 'destructiveHint' => false, 'openWorldHint' => false],
      'run_destructive_tool' => ['title' => 'Run a destructive or outside tool', 'readOnlyHint' => false, 'destructiveHint' => true, 'openWorldHint' => true]],
     ['name', 'arguments'], ['name', 'arguments', 'reason'], ['name', 'arguments', 'reason'], ['name'], null, null, false, [], true,
     ['run_read_tool', 'run_tool', 'run_destructive_tool'], 'essentials', 'all', 'all']);
// N310-2, N310-4: every tool has exactly one runner, and the runner's annotations hold for it – no read-only runner for an
// open-world read, no non-destructive runner for a destructive or open-world tool
$runnerHolds310 = array_values(array_filter(array_keys(Kaleta\Mcp\Catalog::TOOLS), function (string $t) use ($meta310): bool {
    $runner = $meta310[Kaleta\Mcp\Discovery::runner($t)]['annotations'];
    $own = Kaleta\Mcp\Tools::annotations($t);

    return ($runner['readOnlyHint'] && !$own['readOnlyHint']) || (!$runner['destructiveHint'] && $own['destructiveHint']) || (!$runner['openWorldHint'] && $own['openWorldHint']);
}));
check('3.10 N310-2/N310-4: each runner\'s annotations hold for every tool it runs; open-world reads and destructive tools need run_destructive_tool', [$runnerHolds310,
    array_map(Kaleta\Mcp\Discovery::runner(...), ['list_news', 'migration_report', 'create_news', 'save_redirect', 'upload_file', 'save_collection_items', 'delete_media', 'send_newsletter', 'seznam_novinek']),
    Kaleta\Mcp\Discovery::refusal('run_read_tool', 'migration_report'), Kaleta\Mcp\Discovery::refusal('run_tool', 'list_news')],
    [[], ['run_read_tool', 'run_destructive_tool', 'run_tool', 'run_tool', 'run_destructive_tool', 'run_destructive_tool', 'run_destructive_tool', 'run_destructive_tool', 'run_read_tool'],
     'run_read_tool runs read-only tools that stay inside the site – migration_report reaches outside the site. Use run_destructive_tool for it, after the user confirmed this exact action.',
     'run_tool runs tools that save drafts or change the site without removing, sending or reaching outside it – list_news only reads. Use run_read_tool for it.']);
$find310 = fn (array $arguments, array $inList = Kaleta\Mcp\Catalog::ESSENTIALS): array => Kaleta\Mcp\Discovery::find($all310, $inList, $arguments);
$index310 = $find310([]);
$byNames310 = $find310(['names' => ['create_news', 'list_pages', 'no_such_tool']]);
$query310 = $find310(['query' => 'redirect 404']);
check('3.10: find_tools – the index holds every tool outside the list with access, its runner and one line; names give full definitions (a write tool with its reason); a query its best matches outside the list, at most 8', [
    array_column($index310['tools'], 'name') === array_values(array_diff(array_column($all310, 'name'), Kaleta\Mcp\Catalog::ESSENTIALS)), array_keys($index310['tools'][0]),
    array_values(array_filter($index310['tools'], fn (array $t): bool => mb_strlen($t['about']) > 110 || $t['about'] === '' || preg_match('/\(\d\.\d+\)/', $t['about']) === 1)),
    array_column($byNames310['tools'], 'name'), array_key_exists('reason', (array) $byNames310['tools'][0]['inputSchema']['properties']), array_key_exists('reason', (array) $byNames310['tools'][1]['inputSchema']['properties']), $byNames310['not_available'],
    array_slice(array_column($query310['tools'], 'name'), 0, 3), count($query310['tools']) <= Kaleta\Mcp\Discovery::MAX_DEFINITIONS, array_values(array_intersect(array_column($query310['tools'], 'name'), Kaleta\Mcp\Catalog::ESSENTIALS)),
    count($find310(['names' => array_slice(array_column($all310, 'name'), 0, 30)])['tools']), count($find310(['names' => array_slice(array_column($all310, 'name'), 0, 30)])['more']), array_column($find310(['names' => 'list_news, get_news'])['tools'], 'name'),
    $find310([], array_column($all310, 'name'))['tools'], isset($find310(['query' => 'zzzqqq'])['note']),
    Kaleta\Mcp\Discovery::summary(['name' => 'x', 'title' => 'X', 'description' => 'Lists the trash (2.15). More text.']), Kaleta\Mcp\Discovery::summary(['name' => 'x', 'title' => 'The title', 'description' => '']),
    array_column($byNames310['tools'], 'runner'), array_values(array_filter($index310['tools'], fn (array $t): bool => $t['runner'] !== Kaleta\Mcp\Discovery::runner($t['name'])))],
    [true, ['name', 'access', 'runner', 'about'], [], ['create_news', 'list_pages'], true, false, ['no_such_tool'],
     ['list_redirects', 'save_redirect', 'save_redirects'], true, [], 8, 22, ['list_news', 'get_news'], [], true, 'Lists the trash', 'The title', ['run_tool', 'direct call'], []]);
// N310-8: a query of a few words and a short list of names – more is refused with the limit
$limit310 = function (array $arguments) use ($find310): string {
    try {
        $find310($arguments);

        return 'accepted';
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }
};
check('3.10 N310-8: find_tools refuses a query over 200 characters or 20 words and more than 30 names', [$limit310(['query' => str_repeat('a', 201)]), $limit310(['query' => trim(str_repeat('ab ', 21))]),
    $limit310(['query' => str_repeat('a', 200)]), $limit310(['names' => array_map(fn (int $i): string => 'tool_' . $i, range(1, 31))])],
    ['The find_tools query is too long – at most 200 characters and 20 words, e.g. "redirect 404".', 'The find_tools query is too long – at most 200 characters and 20 words, e.g. "redirect 404".', 'accepted',
     'find_tools takes at most 30 names at a time (31 given) – ask for the tools you need next.']);
// a read-only connection finds only reads: find_tools respects the connection's access like tools/list; a runner the
// connection does not list is a "direct call"
$readOnly310 = $for310('read');
// 3.35 (N104): an open-world read (migration_report) reads the network – a read-only connection no longer has it
check('3.10: find_tools on a read-only connection offers only reads, names a write tool as not available, and (3.35) an open-world read as not available', [
    array_values(array_unique(array_column(Kaleta\Mcp\Discovery::find($readOnly310, Kaleta\Mcp\Catalog::ESSENTIALS, [])['tools'], 'access'))),
    Kaleta\Mcp\Discovery::find($readOnly310, Kaleta\Mcp\Catalog::ESSENTIALS, ['names' => ['create_news']])['not_available'],
    array_column(Kaleta\Mcp\Discovery::find($readOnly310, Kaleta\Mcp\Catalog::ESSENTIALS, ['names' => ['migration_report', 'list_news']], Kaleta\Mcp\Discovery::runners($readOnly310, 'read'))['tools'], 'runner'),
    Kaleta\Mcp\Discovery::find($readOnly310, Kaleta\Mcp\Catalog::ESSENTIALS, ['names' => ['migration_report', 'list_news']], Kaleta\Mcp\Discovery::runners($readOnly310, 'read'))['not_available'] ?? []],
    [['read'], ['create_news'], ['run_read_tool'], ['migration_report']]);
$server310 = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php');
check('3.10: the server instructions of an essentials connection name the runners; a runner checks the class before the call of a direct call; a missing tool_set column falls back to all (N310-9)', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Prompts.php'), "'essentials' => 'YOUR TOOL LIST HOLDS THE ESSENTIALS") /* 3.36: one paragraph per focused tool set */ && str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Prompts.php'), 'run_destructive_tool for deleting, sending or reaching outside the site'),
    (bool) preg_match('/function runTool\(.*?Discovery::runner\(\$name\) !== \$runner.*?return \$this->call\(\$tools, \$name, \$inner\);/s', $server310),
    (bool) preg_match('/\$select\(\'t\.tool_set\'\);\s*\} catch \(\\\\PDOException \$e\) \{.*?42S22.*?\$select\("\'all\'"\)/s', $server310),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Front/OAuth.php'), 'self::insertWithToolSet(')], [true, true, true, 2]);
// MCP in English: every tool has an English name, every fixed message a translation, parameters and results are converted
$mcpSource = (string) file_get_contents(KALETA_ROOT . '/system/src/Mcp/Tools.php');
preg_match_all("/^\s+\['([a-z_]+)', '/m", substr($mcpSource, 0, (int) strpos($mcpSource, 'public function call(')), $mcpTools);
check('MCP: každý nástroj má anglický název', array_values(array_diff($mcpTools[1], Kaleta\Mcp\Translator::czechTools())), []);
preg_match_all("/Exception\('((?:[^'\\\\]|\\\\.)*)'\)/", $mcpSource . file_get_contents(KALETA_ROOT . '/system/src/Builder/Edits.php'), $mcpMessages);
check('MCP: pevná hlášení mají anglický překlad', array_values(array_filter(array_map('stripslashes', $mcpMessages[1]), fn (string $z): bool => Kaleta\Mcp\Translator::message($z) === $z && preg_match('/[ěščřžýáíéůúťďňĚŠČŘŽÝÁÍÉŮÚ]/u', $z) === 1)), []); // tools added in English (newsletters) need none
check('MCP anglicky: parametry, hodnoty a položky menu', Kaleta\Mcp\Translator::arguments('save_menu', ['location' => 'footer', 'items' => [['type' => 'page', 'page_id' => 2, 'children' => [['type' => 'link', 'url' => '/x', 'new_window' => true]]]]]),
    ['umisteni' => 'paticka', 'polozky' => [['typ' => 'stranka', 'ids' => 2, 'deti' => [['typ' => 'odkaz', 'url' => '/x', 'nove_okno' => true]]]]]);
check('MCP anglicky: typy polí kolekce a nastavení', [Kaleta\Mcp\Translator::arguments('create_collection', ['fields' => [['label' => 'Foto', 'type' => 'image']]]), Kaleta\Mcp\Translator::arguments('update_settings', ['settings' => ['site_name_de' => 'X', 'company_email' => 'a@b.c', 'nazev_webu' => 'Y']])],
    [['pole' => [['popisek' => 'Foto', 'typ' => 'obrazek']]], ['nastaveni' => ['site_name_de' => 'X', 'company_email' => 'a@b.c', 'nazev_webu' => 'Y']]]);
check('MCP anglicky: výsledek s anglickými klíči, stavba v anglickém slovníku', Kaleta\Mcp\Translator::result('save_build', ['id' => 3, 'stav' => 'publikováno', 'stavba' => ['v' => 1, 'deti' => [['typ' => 'nadpis', 'stav' => 'x']]], 'kontrola' => [['id' => 'a', 'zprava' => 'z']]]),
    ['id' => 3, 'status' => 'published', 'build' => ['v' => 1, 'children' => [['type' => 'heading', 'stav' => 'x']]], 'check' => [['id' => 'a', 'message' => 'z']]]);
$mcpList = [['name' => 'save_collection_item', 'inputSchema' => ['properties' => ['data' => ['type' => 'object'], 'name' => ['type' => 'string'], 'fields' => ['type' => 'array']]]]];
check('MCP: objekt a pole poslané jako text JSON se rozbalí podle schématu, text zůstane textem', Kaleta\Mcp\Server::extractJson($mcpList, 'save_collection_item', ['data' => '{"a":"b"}', 'name' => '{"x":1}', 'fields' => '[1,2]']),
    ['data' => ['a' => 'b'], 'name' => '{"x":1}', 'fields' => [1, 2]]);
check('MCP: neplatný JSON nebo pole místo objektu se nerozbalí', Kaleta\Mcp\Server::extractJson($mcpList, 'save_collection_item', ['data' => '{nic', 'fields' => '{"a":1}']), ['data' => '{nic', 'fields' => '{"a":1}']);
check('MCP: logická hodnota poslaná jako text („false“ nezveřejní skrytou stránku)', Kaleta\Mcp\Server::extractJson([['name' => 'create_page', 'inputSchema' => ['properties' => ['visible' => ['type' => 'boolean'], 'title' => ['type' => 'string']]]]], 'create_page', ['visible' => 'false', 'title' => 'false']), ['visible' => false, 'title' => 'false']);
check('MCP: logická hodnota „true“ a „1“ jako text', array_values(array_map(fn (string $h): mixed => Kaleta\Mcp\Server::extractJson([['name' => 't', 'inputSchema' => ['properties' => ['v' => ['type' => 'boolean']]]]], 't', ['v' => $h])['v'], ['true', '1', '0', 'ano'])), [true, true, false, 'ano']);
check('MCP: text JSON u parametru typu [array, null] se rozbalí', Kaleta\Mcp\Server::extractJson([['name' => 'save_menu', 'inputSchema' => ['properties' => ['items' => ['type' => ['array', 'null']]]]]], 'save_menu', ['items' => '[{"type":"page"}]']), ['items' => [['type' => 'page']]]);
check('MCP: neznámé parametry se vyjmenují', Kaleta\Mcp\Server::unknownParams($mcpList, 'save_collection_item', ['data' => '{}', 'classes' => [], 'name' => 'x']), ['classes']);
// popups: server rules (places, language, period) and English MCP parameters
$popupWhere = fn (array $x): array => $x + ['ids' => null, 'kolekce' => null, 'novinky' => false, 'jazyk' => 'cs', 'dnes' => '2026-09-25'];
$popupSelected = Kaleta\Builder\Popups::sanitizeRules(['kde' => 'vybrane', 'stranky' => ['4', 'x', 4], 'kolekce' => ['tym', 'Ne platna'], 'novinky' => 1, 'od' => '2026-02-30', 'utm' => 'jaro<b>']);
check('Pop-up: vyčištěná pravidla', [$popupSelected['stranky'], $popupSelected['kolekce'], $popupSelected['novinky'], $popupSelected['od'], $popupSelected['utm'], $popupSelected['zarizeni']], [[4], ['tym'], true, '', 'jarob', 'vse']);
check('Pop-up: vybraná místa – stránka, kolekce, novinky, jinde ne', [
    Kaleta\Builder\Popups::matches($popupSelected, $popupWhere(['ids' => 4])), Kaleta\Builder\Popups::matches($popupSelected, $popupWhere(['kolekce' => 'tym'])),
    Kaleta\Builder\Popups::matches($popupSelected, $popupWhere(['novinky' => true])), Kaleta\Builder\Popups::matches($popupSelected, $popupWhere(['ids' => 5])),
], [true, true, true, false]);
$popupPeriod = Kaleta\Builder\Popups::sanitizeRules(['od' => '2026-10-01', 'do' => '2026-10-31', 'jazyk' => 'en']);
check('Pop-up: období a jazyk platí i pro celý web', [
    Kaleta\Builder\Popups::matches($popupPeriod, $popupWhere(['jazyk' => 'en'])), Kaleta\Builder\Popups::matches($popupPeriod, $popupWhere(['jazyk' => 'en', 'dnes' => '2026-10-15'])),
    Kaleta\Builder\Popups::matches($popupPeriod, $popupWhere(['jazyk' => 'cs', 'dnes' => '2026-10-15'])), Kaleta\Builder\Popups::matches($popupPeriod, $popupWhere(['jazyk' => 'en', 'dnes' => '2026-11-01'])),
], [false, true, false, false]);
$navCss = Kaleta\Builder\Elements\Navigation::baseCss();
check('Kolekce: hodnota v Vlastním HTML je escapovaná, formátovaný text vyčištěný', [
    Kaleta\Builder\Collections::fill('<div title="{{nazev}}">{{nazev}}</div>', 'kod', ['nazev' => ['<img src=x onerror=alert(1)>"', 'text']]),
    Kaleta\Builder\Collections::fill('<div>{{telo}}</div>', 'kod', ['telo' => ['<p>Ahoj</p><img src=x onerror=alert(1)>', 'html']]),
], ['<div title="&lt;img src=x onerror=alert(1)&gt;&quot;">&lt;img src=x onerror=alert(1)&gt;&quot;</div>', '<div><p>Ahoj</p><img src="x"></div>']);
check('Navigace: menu na telefonu se dá posouvat (dlouhé menu se skupinami)', (bool) preg_match('/@media \\(max-width: 767px\\).*?\\.ka-nav-menu\\[popover\\] \\{[^}]*max-height:[^}]*overflow-y: auto/s', $navCss), true);
check('MCP anglicky: pop-up okno – hodnoty a pravidla', Kaleta\Mcp\Translator::arguments('save_popup', ['type' => 'slide_in', 'trigger' => 'exit', 'frequency' => 'until_closed', 'template' => 'lead_magnet',
    'rules' => ['where' => 'selected', 'pages' => [2], 'device' => 'phone', 'campaign' => 'jaro']]),
    ['typ' => 'panel', 'spoustec' => 'odchod', 'cetnost' => 'zavreni', 'vzor' => 'magnet', 'pravidla' => ['kde' => 'vybrane', 'stranky' => [2], 'zarizeni' => 'telefon', 'utm' => 'jaro']]);
check('MCP anglicky: výsledek pop-up okna', Kaleta\Mcp\Translator::result('save_popup', ['id' => 3, 'nazev' => 'X', 'adresa' => 'x', 'typ' => 'lista-dole', 'spoustec' => 'stranky', 'cetnost' => 'dni',
    'pravidla' => ['kde' => 'vse', 'zarizeni' => 'pocitac', 'od' => ''], 'aktivni' => true, 'zobrazeni' => 5]),
    ['id' => 3, 'name' => 'X', 'slug' => 'x', 'type' => 'bottom_bar', 'trigger' => 'pages', 'frequency' => 'days', 'rules' => ['where' => 'all', 'device' => 'desktop', 'from' => ''], 'active' => true, 'views' => 5]);
check('Pop-up: každý vzor z knihovny se sestaví', array_map(fn (string $k): bool => count(Kaleta\Builder\Popups::libraryBuild($k, 'en')['deti']) === 1, array_keys(Kaleta\Builder\Popups::LIBRARY)), array_fill(0, count(Kaleta\Builder\Popups::LIBRARY), true));
check('MCP anglicky: hlášení s proměnnou částí', Kaleta\Mcp\Translator::message('Kategorie „Akce“ neexistuje. Použij nástroj seznam_kategorii.'), 'The category “Akce” does not exist. Use list_categories.');
use Kaleta\Core\Routes;
check('Cesty: systémové adresy v jazyce verze', [Routes::publicPath('novinky/kategorie/akce', 'en', null), Routes::publicPath('novinky/stitek/x', 'de', null), Routes::publicPath('hledani?q=a', 'fr', null),
    Routes::publicPath('novinky/kategorie/akce', 'cs', null), Routes::publicPath('novinky-akce', 'en', null), Routes::publicPath('novinky', 'en', null)],
    ['news/category/akce', 'news/tag/x', 'search?q=a', 'novinky/kategorie/akce', 'novinky-akce', 'news']);
check('Cesty: požadavek na vnitřní cestu a kanonickou podobu', [Routes::internalPath('/news/tag/x', 'en', null), Routes::internalPath('/novinky/x', 'en', null), Routes::internalPath('/news', 'cs', null), Routes::internalPath('/o-nas', 'en', null)],
    [['/novinky/stitek/x', '/news/tag/x'], ['/novinky/x', '/news/x'], ['/novinky', '/novinky'], ['/o-nas', '/o-nas']]);
Routes::setNewsSlug('blog');
check('Cesty: vlastní adresa novinek platí ve všech jazycích', [Routes::publicPath('novinky', 'cs', null), Routes::publicPath('novinky/kategorie/akce', 'cs', null), Routes::publicPath('novinky/kategorie/akce', 'en', null),
    Routes::publicPath('novinky/x.md', 'de', null), Routes::publicPath('hledani', 'en', null), Routes::publicPath('o-nas', 'cs', null)],
    ['blog', 'blog/kategorie/akce', 'blog/category/akce', 'blog/x.md', 'search', 'o-nas']);
check('Cesty: vlastní adresa novinek – požadavek a přesměrování ze starých tvarů', [Routes::internalPath('/blog/x', 'cs', null), Routes::internalPath('/blog/category/x', 'en', null), Routes::internalPath('/novinky/x', 'cs', null), Routes::internalPath('/news', 'en', null), Routes::internalPath('/blogger', 'cs', null)],
    [['/novinky/x', '/blog/x'], ['/novinky/kategorie/x', '/blog/category/x'], ['/novinky/x', '/blog/x'], ['/novinky', '/blog'], ['/blogger', '/blogger']]);
check('Cesty: vlastní adresa novinek koliduje se stránkou', [Routes::isNewsSlug('blog', null), Routes::isNewsSlug('o-nas', null), Routes::isNewsSlug('', null)], [true, false, false]);
$systemUrl = 'This URL is used by the system, choose another one.';
$badSlug = 'Use only lowercase letters without accents, digits and single hyphens (e.g. blog), at most 40 characters.';
check('Cesty: adresa novinek nesmí být systémová cesta ani mít špatný tvar', [Routes::systemSlugError('mcp'), Routes::systemSlugError('en'), Routes::systemSlugError('Blog'), Routes::systemSlugError('a/b'), Routes::systemSlugError('blog'), Routes::systemSlugError('news'), Routes::systemSlugError(''),
    Routes::systemSlugError('a--b'), Routes::systemSlugError(str_repeat('a', 41)), Routes::systemSlugError(str_repeat('a', 40)), Routes::systemSlugError(str_repeat('a', 28) . '!')],
    [$systemUrl, $systemUrl, $badSlug, $badSlug, null, null, null, $badSlug, $badSlug, null, $badSlug]);
// 3.3.5 (PR #11 review): the news slug is rewritten in the Kernel constructor, before OAuth, /mcp, /odber… – every first segment
// the front answers before the pages, the OAuth endpoints and the folders the router protects must be refused, or e.g. "oauth"
// turns /oauth/token into the news and every connected Claude app loses its connection. Read from the code, so a new early
// route that is not added to Routes::NEWS_RESERVED fails here.
$kernelSource = (string) file_get_contents(KALETA_ROOT . '/system/src/Front/Kernel.php');
$handleStart = (int) strpos($kernelSource, 'public function handle(): Response');
$earlyRoutes = substr($kernelSource, $handleStart, (int) strpos($kernelSource, 'SELECT * FROM {stranky} WHERE seo_link', $handleStart) - $handleStart);
$earlySegments = [];
$collectSegments = function (string $source) use (&$earlySegments): void {
    // string literals that start a path: '/x…', '#^/x…', '#^/(x|y)…'
    preg_match_all("~'(?:#\\^)?/\\(?([a-z0-9][a-z0-9|\\\\./-]*)~", $source, $m);
    foreach ($m[1] as $alternatives) {
        foreach (explode('|', $alternatives) as $alternative) {
            $segment = (string) preg_replace('~[/.\\\\].*$~s', '', $alternative);
            if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $segment) === 1) {
                $earlySegments[$segment] = true;
            }
        }
    }
};
$collectSegments($earlyRoutes);
$collectSegments((string) file_get_contents(KALETA_ROOT . '/system/src/Front/OAuth.php'));
// the folders and files the development router (and .htaccess) refuse or serve before index.php
preg_match_all('~\^/\(?([a-z|]+)~', (string) file_get_contents(KALETA_ROOT . '/system/dev-router.php'), $routerFolders);
foreach ($routerFolders[1] as $alternatives) {
    foreach (explode('|', $alternatives) as $segment) {
        $earlySegments[$segment] = true;
    }
}
unset($earlySegments['novinky']); // the news itself
$unreserved = array_keys(array_filter($earlySegments, fn (bool $v, string $segment): bool => Routes::systemSlugError($segment) === null, ARRAY_FILTER_USE_BOTH));
check('Cesty: každá časná cesta webu (Kernel::handle před stránkami, OAuth, router) je pro adresu novinek zakázaná', $unreserved, []);
check('Cesty: čtení časných cest našlo OAuth, MCP, odběr, flotilu i složky', array_values(array_intersect(['oauth', 'mcp', 'odber', 'fleet', 'manifest', 'favicon', 'index', 'storage', 'extensions'], array_keys($earlySegments))),
    ['oauth', 'mcp', 'odber', 'fleet', 'manifest', 'favicon', 'index', 'storage', 'extensions']);
check('Cesty: adresa novinek nesmí být kód jazyka, ani dosud nezapnutého', [Routes::systemSlugError('de'), Routes::systemSlugError('ja'), Routes::systemSlugError('oauth'), Routes::systemSlugError('api')], [$systemUrl, $systemUrl, $systemUrl, $systemUrl]);
// 3.3.5: an earlier slug keeps redirecting (also after a change back to empty); a page or collection that takes it later wins (needs the database)
Routes::setNewsSlug('magazin', ['blog']);
check('Cesty: dřívější adresa novinek přesměruje na současnou', [Routes::internalPath('/blog/x', 'cs', null), Routes::internalPath('/blog', 'en', null), Routes::internalPath('/blog/category/akce', 'en', null), Routes::internalPath('/magazin/x', 'cs', null), Routes::internalPath('/blogger', 'cs', null)],
    [['/novinky/x', '/magazin/x'], ['/novinky', '/magazin'], ['/novinky/kategorie/akce', '/magazin/category/akce'], ['/novinky/x', '/magazin/x'], ['/blogger', '/blogger']]);
Routes::setNewsSlug('', ['magazin', 'blog']);
check('Cesty: po návratu k výchozí adrese vedou dřívější na novinky / news', [Routes::internalPath('/blog/x', 'cs', null), Routes::internalPath('/magazin/x', 'en', null), Routes::internalPath('/novinky/x', 'cs', null)],
    [['/novinky/x', '/novinky/x'], ['/novinky/x', '/news/x'], ['/novinky/x', '/novinky/x']]);
check('Cesty: seznam dřívějších adres novinek', [Routes::rememberSlug('', 'blog', 'magazin'), Routes::rememberSlug('blog', 'magazin', 'blog'), Routes::rememberSlug('blog', 'magazin', ''), Routes::rememberSlug('', '', 'blog'),
    Routes::rememberSlug('x,oauth,Blog,news,x', 'novinky', 'y'), Routes::rememberSlug('a1,a2,a3,a4,a5,a6,a7,a8,a9,a10', 'a0', 'b'), Routes::rememberSlug('blog', 'blog', 'blog')],
    ['blog', 'magazin', 'magazin,blog', '', 'x', 'a0,a1,a2,a3,a4,a5,a6,a7,a8,a9', '']);
Routes::setNewsSlug(null);
// 3.7 (GitHub #17): the system endpoints have English paths next to the Czech ones – both answer forever, neither redirects
// (cron on hosting, links in e-mails already sent, forms on cached pages); new links use the English form
check('3.7: App::url writes the English form of a system endpoint (internal Czech path in, public English out)', array_map(fn (string $p): ?string => Routes::publicSystemPath($p, null),
    ['ulohy?token=x', 'odber?odhlasit=abc', 'odber', 'formular', 'souhlas', 'konverze', 'stav.json?token=x', '_komentar', '_komponenta/5', '_sekce/', 'odberatele', 'formulare', 'o-nas', 'novinky', 'ulohy/x']),
    ['tasks?token=x', 'subscription?odhlasit=abc', 'subscription', 'form', 'consent', 'conversion', 'status.json?token=x', '_comment', '_component/5', '_section/', null, null, null, null, null]);
check('3.7: both forms of every system path reach the same internal route; the Czech one and other paths stay as they are', array_map(fn (string $p): string => Routes::systemPath($p, null),
    ['/tasks', '/ulohy', '/subscription', '/odber', '/form', '/formular', '/consent', '/souhlas', '/conversion', '/konverze', '/status.json', '/stav.json', '/_comment', '/_komentar', '/_component/5', '/_komponenta/5', '/_section/cenik', '/_sekce/cenik', '/form/item', '/tasks.json', '/formx', '/_componentx', '/o-nas']),
    ['/ulohy', '/ulohy', '/odber', '/odber', '/formular', '/formular', '/souhlas', '/souhlas', '/konverze', '/konverze', '/stav.json', '/stav.json', '/_komentar', '/_komentar', '/_komponenta/5', '/_komponenta/5', '/_sekce/cenik', '/_sekce/cenik', '/form/item', '/tasks.json', '/formx', '/_componentx', '/o-nas']);
$systemPaths37 = Routes::SYSTEM_PATHS + Routes::SYSTEM_PREFIXES;
$kernel37 = (string) file_get_contents(KALETA_ROOT . '/system/src/Front/Kernel.php');
check('3.7: every English system path is reserved (news slug, and pages and collections unless it has a dot) and every Czech one keeps its route in the Kernel', [
    array_values(array_filter($systemPaths37, fn (string $en): bool => !str_starts_with($en, '_') && (Routes::systemSlugError(explode('.', $en)[0]) === null || (!str_contains($en, '.') && !in_array($en, Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true))))),
    in_array('odber', Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true), Routes::systemSlugError('tasks'), Routes::systemSlugError('status'),
    array_values(array_filter(array_keys($systemPaths37), fn (string $cz): bool => !str_contains($kernel37, "'/" . $cz . "'") && !str_contains($kernel37, "'#^/" . $cz . '/'))),
], [[], true, $systemUrl, $systemUrl, []]);
// a news slug a site chose before 3.7 stays valid when 3.7 reserved its word – the news keep their address, the endpoint keeps its Czech path
$storedSlugValid = new ReflectionMethod(Routes::class, 'storedSlugValid');
check('3.7: a stored news slug that is now an English system word stays valid, a system path never', [$storedSlugValid->invoke(null, 'tasks'), $storedSlugValid->invoke(null, 'form'), $storedSlugValid->invoke(null, 'blog'), $storedSlugValid->invoke(null, 'mcp'), $storedSlugValid->invoke(null, 'oauth')],
    [true, true, true, false, false]);
// 3.4.2 N34-1: a raw path with two leading slashes (parse_url reads "a" as a host) or a backslash never becomes a Location
// that leaves the site; the shared redirect never sends a protocol-relative address
check('3.4.2 N34-1: no open redirect through the URL form or a protocol-relative Location', [
    array_map(fn (string $m): ?string => Routes::slashRedirect('/a//evil.example/x', '//a//evil.example/x/', $m), ['bez', 's', 'html']),
    array_map(fn (string $m): ?string => Routes::slashRedirect('/x', '/\\evil.example/x/', $m), ['bez', 's', 'html']),
    Routes::slashRedirect('/a//b', '/a//b/', 'bez'),
    Kaleta\Core\Response::redirect('//evil.example/x', 301)->headers['Location'], Kaleta\Core\Response::redirect('/\\evil.example', 301)->headers['Location'],
    Kaleta\Core\Response::redirect('https://example.com/x', 301)->headers['Location'], Kaleta\Core\Response::redirect('/o-nas', 301)->headers['Location'],
], [[null, null, null], [null, null, null], '/a//b', '/evil.example/x', '/evil.example', 'https://example.com/x', '/o-nas']);
$destructiveCalls = (new ReflectionClassConstant(Kaleta\Core\Guardrails::class, 'DESTRUCTIVE_CALLS'))->getValue();
check('3.4.2 N34-2: the booking tools that e-mail the customer stop at the no-destructive guardrail, like decline_booking', [
    $destructiveCalls['confirm_booking'] ?? null, $destructiveCalls['propose_booking_times'] ?? null, Kaleta\Mcp\Catalog::access('decline_booking')], ['', '', 'destructive']);
check('Cesty: tvar adres podle nastavení url_slash', [Routes::slashRedirect('/o-nas', '/o-nas/?a=1', 'bez'), Routes::slashRedirect('/o-nas', '/o-nas', 'bez'), Routes::slashRedirect('/o-nas', '/o-nas?a=1', 's'), Routes::slashRedirect('/o-nas', '/en/o-nas/', 's'),
    Routes::slashRedirect('/o-nas', '/o-nas', 'html'), Routes::slashRedirect('/o-nas', '/o-nas/', 'html'), Routes::slashRedirect('/o-nas.html', '/o-nas.html', 'html'), Routes::slashRedirect('/o-nas.html', '/o-nas.html', 'bez'), Routes::slashRedirect('/o-nas.html', '/o-nas.html', 's'),
    Routes::slashRedirect('/', '/', 's'), Routes::slashRedirect('/rss.xml', '/rss.xml', 's'), Routes::slashRedirect('/api/x', '/api/x', 's'), Routes::slashRedirect('/mcp', '/mcp', 's'), Routes::slashRedirect('/formular', '/formular', 's')],
    ['/o-nas?a=1', null, '/o-nas/?a=1', null, '/o-nas.html', '/o-nas.html', null, '/o-nas', '/o-nas/', null, null, null, null, null]);
// the Claude connection (OAuth discovery), e-mailed links and endpoints never move with url_slash (hard rule: existing connections keep working)
check('Cesty: url_slash nikdy nepřesměruje systémové adresy', array_map(fn (string $p): ?string => Routes::slashRedirect($p, $p, 's') ?? Routes::slashRedirect($p, $p, 'html'),
    ['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp', '/.well-known/oauth-authorization-server', '/.well-known/openid-configuration', '/oauth/token', '/mcp', '/ulohy', '/odber', '/download/abc', '/screen/abc', '/fleet/pair', '/souhlas', '/konverze', '/_booking/choose/abc']),
    array_fill(0, 14, null));
check('Cesty: url_slash platí pro stránky, novinky i hledání', [Routes::pageLike('/o-nas'), Routes::pageLike('/novinky/kategorie/x'), Routes::pageLike('/hledani'), Routes::pageLike('/odberatele'), Routes::pageLike('/novinky/x.md'), Routes::pageLike('/.well-known')],
    [true, true, true, true, false, false]);
// dictionaries of other site languages: only keys of the English dictionary (and English day and month names for dates in words), the same %s and tags
$enDictionary = require KALETA_ROOT . '/system/jazyky/en.php';
// English source texts (1.4.1+) are keys too: their "English translation" is the text itself
$enDictionary += array_combine(array_keys(require KALETA_ROOT . '/system/jazyky/cs.php'), array_keys(require KALETA_ROOT . '/system/jazyky/cs.php'));
$dataNames = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday', 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$brokenDictionaries = [];
foreach ([...(glob(KALETA_ROOT . '/system/jazyky/[a-z][a-z].php') ?: []), KALETA_ROOT . '/system/jazyky/zh-hant.php'] as $file) { // zh-hant (3.12): a regional dictionary of its own
    $code = basename($file, '.php');
    if ($code === 'en') {
        continue;
    }
    $dictionary = require $file;
    if (!isset(Kaleta\Core\Language::AVAILABLE[$code])) {
        $brokenDictionaries[] = $code . ': jazyk není v Jazyk::DOSTUPNE';
    }
    foreach ($dictionary as $key => $translation) {
        if (!isset($enDictionary[$key]) && !in_array($key, $dataNames, true) && $key !== 'datum_format') {
            $brokenDictionaries[] = $code . ': navíc „' . $key . '“';
        } elseif (isset($enDictionary[$key]) && (preg_match_all('/%(?:\d+\$)?[sd]/', $enDictionary[$key]) !== preg_match_all('/%(?:\d+\$)?[sd]/', $translation) || substr_count($enDictionary[$key], '<') !== substr_count($translation, '<'))) {
            $brokenDictionaries[] = $code . ': zástupné znaky nebo značky v „' . $key . '“';
        }
    }
}
check('Slovníky jazyků webu: klíče z en.php, stejné %s a HTML', $brokenDictionaries, []);

