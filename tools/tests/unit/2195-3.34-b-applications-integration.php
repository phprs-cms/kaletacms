<?php

declare(strict_types=1);

use Kaleta\Builder\CollectionSchema;
use Kaleta\Core\Handover;

/* ---------- 3.34 (B): applications in the rest of Kaleta – leavers, My work, Ctrl K, the jobs list, the builder, JobPosting, the report ---------- */
check('3.34 B: a leaver hands applications over like other work – only to someone who may open Applications; the Users list names them', [
    Handover::KINDS['application'] ?? null, Handover::sectionsOf(['page' => 0, 'item' => 0, 'enquiry' => 0, 'application' => 2]),
    Kaleta\Core\Language::runWith('en', fn (): string => Handover::describe(['page' => 1, 'item' => 0, 'enquiry' => 0, 'application' => 2]), 'admin-')],
    [['applications', 'applications', 'applications', 'id', 'assignee_id'], ['applications'], 'pages: 1, open job applications: 2']);
check('[source-pin] 3.34 B: My work – the undecided applications one is responsible for and the new ones nobody is, with the section Applications; MCP my_work keeps them additive and without the name', [
    Kaleta\Core\MyWork::LISTS['applications'] ?? null, Kaleta\Core\MyWork::LISTS['applications_new'] ?? null,
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/WorkTools.php'), "'applications' => 'applications_assigned', 'applications_new' => 'applications_unassigned'"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/my_work/list.php'), "<?php elseif (\$list === 'applications' || \$list === 'applications_new'):")],
    ['applications', 'applications', true, true]);
check('[source-pin] 3.34 B: Ctrl K asks for applications only with the section; the palette, the jobs list and the builder get their data', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php'), "isset(\$modules['applications']) ? ' data-applications=\""),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/admin.js'), "palette.getAttribute('data-applications')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Collections.php'), "isset(\$this->kernel->modules()[\\Kaleta\\Core\\Applications::SECTION])"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/BuilderActions.php'), "'pozice' => \\Kaleta\\Core\\Applications::openPositions(\$db, \$language),"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/stavitel.js'), "options.klic === 'pole' && item.typ === 'vyber'")],
    [true, true, true, true, true]);
$i339Fields = [['klic' => 'location', 'popisek' => 'Location', 'typ' => 'text'], ['klic' => 'workplace', 'popisek' => 'Workplace', 'typ' => 'text']];
$i339Collection = ['schema_org' => '{"typ":"JobPosting","pole":{"jobLocation":"location","jobLocationType":"workplace"},"mena":""}', 'pole' => $i339Fields, 'stavba' => '{"v":1,"deti":[{"id":"a","typ":"formular"}]}'];
$i339Issuer = ['@id' => 'x#firma', 'name' => 'Northfield', 'address' => ['@type' => 'PostalAddress', 'streetAddress' => 'Main 1', 'addressLocality' => 'Brno', 'postalCode' => '60200', 'addressCountry' => 'CZ']];
$i339Remote = CollectionSchema::forItem($i339Collection, ['idp' => 12, 'nazev' => 'Developer', 'datum' => '2026-11-01 10:00:00', 'data' => ['location' => 'Brno', 'workplace' => 'Na dálku']], 'https://x/jobs/dev', 'Code.', '', 'x#firma', $i339Issuer) ?? [];
$i339Office = CollectionSchema::forItem(['stavba' => ''] + $i339Collection, ['idp' => 13, 'nazev' => 'Welder', 'data' => ['location' => 'Ostrava', 'workplace' => 'On site']], 'https://x/jobs/w', 'Weld.', '', 'x#firma', $i339Issuer) ?? [];
check('3.34 B JobPosting: identifier, directApply with a form on the item page, remote work (TELECOMMUTE + the company country), the full address in the company\'s town; elsewhere town and country', [
    $i339Remote['identifier'] ?? null, $i339Remote['directApply'] ?? null, $i339Remote['jobLocationType'] ?? null, $i339Remote['applicantLocationRequirements'] ?? null, $i339Remote['jobLocation']['address'] ?? null,
    isset($i339Office['directApply']), isset($i339Office['jobLocationType']), $i339Office['jobLocation']['address'] ?? null,
    Kaleta\Builder\Presets::get('jobs')['schema']['pole']['jobLocationType'] ?? null],
    [['@type' => 'PropertyValue', 'name' => 'Northfield', 'value' => '12'], true, 'TELECOMMUTE', ['@type' => 'Country', 'name' => 'CZ'],
        ['@type' => 'PostalAddress', 'streetAddress' => 'Main 1', 'addressLocality' => 'Brno', 'postalCode' => '60200', 'addressCountry' => 'CZ'],
        false, false, ['@type' => 'PostalAddress', 'addressLocality' => 'Ostrava', 'addressCountry' => 'CZ'], 'workplace']);
$i339Tile = fn (array $enquiries): array => array_values(array_filter(Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Core\ReportSummary::tiles(['enquiries' => $enquiries], null, []), 'admin-'),
    fn (array $t): bool => $t['label'] === 'Enquiries'))[0] ?? [];
check('3.34 B report: the first month after applications left the enquiries – "counting changed", no arrow down; a later month compares as before', [
    $i339Tile(['total' => 5, 'previous_total' => 9, 'counting_changed' => '2026-11-11'])['tone'] ?? null, str_starts_with($i339Tile(['total' => 5, 'previous_total' => 9, 'counting_changed' => '2026-11-11'])['change'] ?? '', 'counting changed on '),
    $i339Tile(['total' => 5, 'previous_total' => 9])['tone'] ?? null, $i339Tile(['total' => 12, 'previous_total' => 9, 'counting_changed' => '2026-11-11'])['tone'] ?? null],
    ['same', true, 'down', 'up']);
