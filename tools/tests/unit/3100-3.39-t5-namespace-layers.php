<?php

declare(strict_types=1);

/* ---------- 3.39 T5 (audit Q7): which namespace may reference which (docs/design/code-layers.md) ---------- */
// A rule over the whole of system/src, not a pin of one file: every qualified Kaleta\X\… name a file writes is checked
// against the layer table. Today's upward references are frozen below, one line per file with the classes it reaches;
// a new one fails the first check, a moved or removed one fails the second until its line is shortened or deleted.
// Allowed: the namespaces listed for the file's own namespace, and its own. Files directly in system/src count as Core.
const T5_LAYERS = [
    'Compat' => [],
    'Core' => ['Compat', 'Connectors', 'Extension'], // the services Core calls (adapters of outside services, add-on hooks)
    'Connectors' => ['Core', 'Compat'],
    'Extension' => ['Core', 'Compat'],
    'Import' => ['Core', 'Compat', 'Builder'],
    'Builder' => ['Core', 'Compat', 'Connectors', 'Extension', 'Front'],
    'Front' => ['Core', 'Compat', 'Connectors', 'Extension', 'Builder'],
    'Fleet' => ['Core', 'Compat', 'Connectors', 'Extension', 'Builder', 'Front'],
    'Mcp' => ['Core', 'Compat', 'Connectors', 'Extension', 'Builder', 'Front', 'Fleet', 'Import'],
    'Install' => ['Core', 'Compat', 'Builder', 'Front'],
    'Admin' => ['Core', 'Compat', 'Connectors', 'Extension', 'Builder', 'Front', 'Fleet', 'Import', 'Mcp', 'Install'],
];
// frozen on 12 November 2026 (3.39): file under system/src => the classes it references against the table, space separated
const T5_FROZEN = [
    'Builder/CollectionCategories.php' => 'Admin\ChangeLog',
    'Builder/ItemBatch.php' => 'Mcp\Catalog',
    'Builder/ItemImport.php' => 'Admin\ChangeLog',
    'Builder/Presets.php' => 'Admin\ChangeLog Admin\Modules\Pages',
    'Builder/Publisher.php' => 'Admin\Modules\Pages',
    'Core/AgentJournal.php' => 'Admin\ChangeLog Front\Cache',
    'Core/Alerts.php' => 'Fleet\AlertRules',
    'Core/ApplicationMove.php' => 'Admin\ChangeLog',
    'Core/Applications.php' => 'Admin\ChangeLog Admin\Modules\Enquiries Front\EnquiryTopic Front\Forms',
    'Core/Audit.php' => 'Admin\Modules\Roles Builder\Build Builder\Check Builder\CollectionCategories Builder\DesignSystem Builder\FontScripts Builder\SampleContent Front\SiteIdentity',
    'Core/Auth.php' => 'Mcp\Catalog',
    'Core/Blueprint.php' => 'Admin\ChangeLog Builder\Collections Builder\Presets',
    'Core/Booking.php' => 'Admin\ChangeLog Builder\Elements\Booking Front\Cache Front\Company',
    'Core/Calendar.php' => 'Admin\ChangeLog Builder\Collections Builder\Presets Front\Cache',
    'Core/ClaudeInstructions.php' => 'Admin\ChangeLog',
    'Core/Connectors.php' => 'Admin\ChangeLog',
    'Core/Contacts.php' => 'Admin\ChangeLog Admin\Modules\Enquiries',
    'Core/ContentCheck.php' => 'Builder\Build',
    'Core/Conversions.php' => 'Front\Stats',
    'Core/Deals.php' => 'Admin\ChangeLog Admin\Modules\Enquiries',
    'Core/Demo.php' => 'Admin\Kernel Admin\Modules\Settings',
    'Core/Documents.php' => 'Builder\Collections Builder\Presets Front\Stats',
    'Core/DraftAttribution.php' => 'Admin\ChangeLog',
    'Core/EnquiryReply.php' => 'Admin\ChangeLog Admin\Modules\Enquiries Builder\UserSignature',
    'Core/EnquirySheet.php' => 'Admin\ChangeLog',
    'Core/Facts.php' => 'Admin\ChangeLog Builder\Build Front\Cache Front\Company',
    'Core/GoogleBusiness.php' => 'Front\Cache',
    'Core/Guardrails.php' => 'Admin\ChangeLog',
    'Core/Hours.php' => 'Admin\ChangeLog Front\Cache Front\Company',
    'Core/HoursSign.php' => 'Front\Company',
    'Core/ImportPath.php' => 'Admin\Modules\Pages',
    'Core/ImportRecheck.php' => 'Builder\Build Builder\Collections',
    'Core/Inbox.php' => 'Admin\Modules\Enquiries Fleet\ConsoleTasks',
    'Core/InternalLinks.php' => 'Builder\Build',
    'Core/Jobs.php' => 'Admin\ChangeLog Admin\Modules\Enquiries',
    'Core/Links.php' => 'Builder\Build',
    'Core/Look.php' => 'Admin\ChangeLog Builder\DesignSystem Front\Cache Front\SiteIdentity',
    'Core/Mailing.php' => 'Admin\ChangeLog Builder\DesignSystem Front\Company Front\Subscription',
    'Core/MediaHygiene.php' => 'Admin\Modules\Media',
    'Core/MediaStaging.php' => 'Front\Cache',
    'Core/Menu.php' => 'Builder\Icons',
    'Core/MigrationReport.php' => 'Builder\Build Builder\CollectionCategories Builder\Components Builder\Elements\Component',
    'Core/MonthlyReport.php' => 'Fleet\Uptime Front\Stats Mcp\Catalog',
    'Core/MyWork.php' => 'Admin\Modules\Enquiries',
    'Core/Notebook.php' => 'Admin\ChangeLog',
    'Core/Notices.php' => 'Builder\Collections Builder\Presets Front\Cache',
    'Core/Notifications.php' => 'Admin\Modules\Contacts Admin\Modules\Enquiries Builder\CollectionCategories Front\Cache Front\Seo',
    'Core/PendingReview.php' => 'Admin\Modules\News Builder\SiteParts',
    'Core/PersonalData.php' => 'Admin\ChangeLog Admin\Modules\Enquiries',
    'Core/Prices.php' => 'Admin\ChangeLog Builder\Collections Builder\Products',
    'Core/Privacy.php' => 'Admin\ChangeLog Admin\Modules\Enquiries Admin\Modules\Pages Builder\Build Builder\Elements\Form Front\Stats',
    'Core/QuoteDelivery.php' => 'Admin\ChangeLog',
    'Core/Quotes.php' => 'Admin\ChangeLog Admin\Modules\Enquiries',
    'Core/RedirectMatcher.php' => 'Admin\ChangeLog Admin\Modules\Redirects Front\Cache',
    'Core/RedirectRules.php' => 'Admin\Modules\Redirects',
    'Core/Report.php' => 'Front\Forms',
    'Core/ReportArchive.php' => 'Fleet\Uptime',
    'Core/ReportVisual.php' => 'Builder\DesignSystem',
    'Core/Routes.php' => 'Admin\Modules\Pages',
    'Core/Scheduler.php' => 'Admin\Modules\Collections Admin\Modules\News Admin\Modules\Pages Fleet\AlertRules Fleet\Calendar Fleet\Console Fleet\Digest Fleet\History Fleet\Link Fleet\Report Front\OAuth',
    'Core/SecurityHygiene.php' => 'Admin\ChangeLog Front\OAuth',
    'Core/SemanticHtml.php' => 'Builder\HtmlWidgets',
    'Core/SigningKey.php' => 'Admin\ChangeLog',
    'Core/SiteImport.php' => 'Admin\Modules\Pages Admin\Modules\Settings Builder\Build Builder\CollectionCategories Builder\CollectionSchema Builder\Collections Builder\Components Builder\DesignSystem Builder\ModalConversion Builder\Popups Builder\Presets Builder\SiteParts Builder\Style Fleet\Kit Front\Cache',
    'Core/SocialDrafts.php' => 'Front\ShareImage',
    'Core/Testimonials.php' => 'Admin\ChangeLog Admin\Modules\Enquiries Builder\CollectionCategories Builder\Collections Builder\Presets',
    'Core/TimeZoneConversion.php' => 'Admin\ChangeLog',
    'Core/Triage.php' => 'Admin\Modules\Enquiries',
    'Core/Updater.php' => 'Fleet\Link',
    'Core/Validity.php' => 'Admin\ChangeLog Front\Cache',
    'Core/Vault.php' => 'Admin\ChangeLog',
    'Core/WebImport.php' => 'Admin\Modules\Media Admin\Modules\Pages Admin\Modules\Redirects Builder\Build Builder\HtmlConverter Builder\HtmlWidgets',
    'Core/WebVitals.php' => 'Front\Stats',
    'Core/Whistleblowing.php' => 'Admin\ChangeLog Builder\Elements\Form',
    'Core/WpImport.php' => 'Admin\Modules\Media Admin\Modules\Pages Admin\Modules\Redirects Admin\Modules\Tags Builder\Build Builder\CollectionCategories Builder\CollectionSchema Builder\Collections Builder\HtmlConverter Builder\Presets',
    'Fleet/Heartbeat.php' => 'Admin\Modules\Enquiries Admin\Modules\Settings',
    'Fleet/Kit.php' => 'Admin\ChangeLog',
    'Fleet/Link.php' => 'Admin\ChangeLog',
    'Front/Kernel.php' => 'Fleet\Calendar Fleet\Console Fleet\Kit Fleet\Uptime Mcp\Server',
    'Front/OAuth.php' => 'Mcp\Catalog',
    'Import/Batch.php' => 'Admin\Modules\Media Admin\Modules\Pages Admin\Modules\Redirects Admin\Modules\Tags',
    'Mcp/Handlers/BookingTools.php' => 'Admin\ChangeLog',
    'Mcp/Handlers/BuilderTools.php' => 'Admin\Modules\Appearance Admin\Modules\Categories Admin\Modules\Media Admin\Modules\Pages',
    'Mcp/Handlers/CollectionTools.php' => 'Admin\Modules\Categories Admin\Modules\Collections Admin\Modules\Media Admin\Modules\Pages',
    'Mcp/Handlers/ContactTools.php' => 'Admin\ChangeLog',
    'Mcp/Handlers/EnquiryAndPopupTools.php' => 'Admin\Modules\Categories Admin\Modules\Enquiries Admin\Modules\Media Admin\Modules\Pages',
    'Mcp/Handlers/LookTools.php' => 'Admin\Modules\Appearance Admin\Modules\Categories Admin\Modules\Media Admin\Modules\Pages',
    'Mcp/Handlers/MediaTools.php' => 'Admin\Modules\Categories Admin\Modules\Media Admin\Modules\Pages',
    'Mcp/Handlers/MigrationTools.php' => 'Admin\ChangeLog',
    'Mcp/Handlers/NewsTools.php' => 'Admin\ChangeLog Admin\Modules\Categories Admin\Modules\Media Admin\Modules\Pages Admin\Modules\Redirects',
    'Mcp/Handlers/NewsletterTools.php' => 'Admin\Modules\Categories Admin\Modules\Media Admin\Modules\Pages',
    'Mcp/Handlers/PageTools.php' => 'Admin\Modules\Categories Admin\Modules\Collections Admin\Modules\Media Admin\Modules\Pages',
    'Mcp/Handlers/PendingReviewTools.php' => 'Admin\Kernel',
    'Mcp/Handlers/QuoteTools.php' => 'Admin\ChangeLog',
    'Mcp/Handlers/SettingsTools.php' => 'Admin\Modules\Categories Admin\Modules\Media Admin\Modules\Pages Admin\Modules\Redirects Admin\Modules\Settings',
    'Mcp/Handlers/WorkTools.php' => 'Admin\Kernel',
    'Mcp/Server.php' => 'Admin\ChangeLog',
    'Mcp/Tools.php' => 'Admin\Modules\Categories Admin\Modules\Media Admin\Modules\News Admin\Modules\Pages',
];

$t5Src = KALETA_SYSTEM . '/src';
$t5Found = [];
$t5Namespaces = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($t5Src, FilesystemIterator::SKIP_DOTS)) as $t5File) {
    if (!$t5File instanceof SplFileInfo || $t5File->getExtension() !== 'php') {
        continue;
    }
    $t5Rel = substr($t5File->getPathname(), strlen($t5Src) + 1);
    $t5From = str_contains($t5Rel, '/') ? explode('/', $t5Rel)[0] : 'Core';
    $t5Namespaces[$t5From] = true;
    foreach (token_get_all((string) file_get_contents($t5File->getPathname())) as $t5Token) {
        if (is_array($t5Token) && in_array($t5Token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
            && preg_match('/^\\\\?Kaleta\\\\([A-Z]\w*)\\\\(.+)$/', $t5Token[1], $t5Match) === 1
            && $t5Match[1] !== $t5From && !in_array($t5Match[1], T5_LAYERS[$t5From] ?? [], true)) {
            $t5Found[$t5Rel][$t5Match[1] . '\\' . $t5Match[2]] = true;
        }
    }
}
$t5New = [];
$t5Stale = [];
foreach ($t5Found as $t5Rel => $t5Classes) {
    $t5Frozen = array_flip(explode(' ', T5_FROZEN[$t5Rel] ?? ''));
    foreach (array_keys($t5Classes) as $t5Class) {
        if (!isset($t5Frozen[$t5Class])) {
            $t5New[] = $t5Rel . ' → ' . $t5Class;
        }
    }
}
foreach (T5_FROZEN as $t5Rel => $t5List) {
    foreach (explode(' ', $t5List) as $t5Class) {
        if (!isset($t5Found[$t5Rel][$t5Class])) {
            $t5Stale[] = $t5Rel . ' → ' . $t5Class;
        }
    }
}
sort($t5New);
check('3.39 T5 (Q7): no new reference against the layer table (put the code in the lower namespace, or move the class down – see docs/design/code-layers.md)', $t5New, []);
check('3.39 T5 (Q7): the frozen list has no reference that is gone (shorten or delete its line – the list only shrinks)', $t5Stale, []);
check('3.39 T5 (Q7): every namespace under system/src has its row in the layer table, and every row names only known namespaces', [
    array_values(array_diff(array_keys($t5Namespaces), array_keys(T5_LAYERS))),
    array_values(array_diff(array_merge(...array_values(T5_LAYERS)), array_keys(T5_LAYERS))),
], [[], []]);
// the guard itself: a reference the table forbids is found, one it allows is not (the same matching as above)
$t5Probe = fn (string $from, string $name): bool => preg_match('/^\\\\?Kaleta\\\\([A-Z]\w*)\\\\/', $name, $m) === 1 && $m[1] !== $from && !in_array($m[1], T5_LAYERS[$from], true);
check('3.39 T5 (Q7): the table forbids Core → Admin, Front and Builder and allows Admin → Core, Mcp → Front, Core → Connectors', [
    $t5Probe('Core', '\Kaleta\Admin\ChangeLog'), $t5Probe('Core', 'Kaleta\Front\Cache'), $t5Probe('Core', '\Kaleta\Builder\Build'),
    $t5Probe('Admin', '\Kaleta\Core\Db'), $t5Probe('Mcp', 'Kaleta\Front\Cache'), $t5Probe('Core', '\Kaleta\Connectors\Google')], [true, true, true, false, false, false]);
