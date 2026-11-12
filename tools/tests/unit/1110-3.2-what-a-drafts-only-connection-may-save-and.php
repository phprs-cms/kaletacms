<?php

declare(strict_types=1);

/* ---------- 3.2: what a drafts-only connection may save, and Waiting for you ---------- */
$adminCs = require KALETA_SYSTEM . '/jazyky/admin-cs.php';
$adminDe = require KALETA_SYSTEM . '/jazyky/admin-de.php';
check('[source-pin] 3.2: a drafts-only connection may save hidden items, proposed hours, triage and notes – not facts, not deletes; list_pending_review is a read', [
    array_map(fn (string $tool): bool => Kaleta\Mcp\Catalog::allows('drafts', $tool), ['save_collection_item', 'save_hours_exception', 'update_enquiry', 'write_notebook', 'list_pending_review', 'save_fact', 'delete_hours_exception', 'delete_notebook_entry', 'delete_collection_item', 'restore_item_version']),
    Kaleta\Mcp\Catalog::allows('read', 'list_pending_review'), Kaleta\Mcp\Catalog::allows('read', 'save_collection_item'),
    // each of the four refuses a drafts-only connection what is more than a draft (the handler asks Auth::draftsOnly)
    array_map(fn (string $file): bool => str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/' . $file . '.php'), 'draftsOnly()'), ['CollectionTools', 'FactTools', 'EnquiryAndPopupTools']),
    str_contains(Kaleta\Mcp\Prompts::get('review_pending', [])['messages'][0]['content']['text'], 'list_pending_review')],
    [[true, true, true, true, true, false, false, false, false, false], true, false, [true, true, true], true]);
check('[source-pin] 3.2: Waiting for you – every kind opens an admin section that exists, its label is translated (cs, de), and the migration adds the proposed column', [
    array_values(array_diff(array_column(Kaleta\Core\PendingReview::KINDS, 1), array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES))),
    array_values(array_filter(array_column(Kaleta\Core\PendingReview::KINDS, 0), fn (string $label): bool => !isset($adminCs[$label], $adminDe[$label]))),
    KALETA_DB_VERSION >= 72 && str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0072-proposed-hours.sql'), 'ADD COLUMN proposed')
        && str_contains((string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql'), 'proposed    TINYINT(1)'),
    // the site, the export and the door sign read only applied exceptions; proposals have their own list
    (bool) preg_match("/hours_exceptions} WHERE proposed = 0/", (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Hours.php')),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/SiteExport.php'), 'AND proposed = 0')],
    [[], [], true, true, true]);
