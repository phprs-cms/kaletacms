<?php

declare(strict_types=1);

use Kaleta\Admin\Hubs;
use Kaleta\Admin\MenuGroups;
use Kaleta\Core\Extensions;
use Kaleta\Mcp\Catalog;

/* ---------- 3.48 A (decision 3 and 6): Customers and Pipeline as features, three profiles, the Sales and Addresses hubs, migration 0135 ---------- */

// the catalogue: a fourth column with the lowest profile, the profiles derived from it and cumulative
$a348Profiles = array_keys(Extensions::PROFILES);
$a348Keys = fn (string $profile): array => Extensions::profile(null, $profile);
check('3.48 A1: every feature names a profile or none; the profiles are cumulative and derived from the catalogue (never a key that does not exist)', [
    array_keys(array_filter(Extensions::CATALOG, fn (array $row): bool => !in_array($row[3] ?? null, ['', ...$a348Profiles], true))),
    array_values(array_diff(array_merge(...array_map($a348Keys, $a348Profiles)), array_keys(Extensions::CATALOG))),
    array_diff($a348Keys('website'), $a348Keys('enquiries')) === [] && array_diff($a348Keys('enquiries'), $a348Keys('office')) === [],
    $a348Keys('nonsense')],
    [[], [], true, []]);
check('3.48 A1: the 3.48 snapshot – Website, Website with enquiries (today\'s defaults), Front office adds exactly Customers, the Pipeline and Quotes', [
    $a348Keys('website'), $a348Keys('enquiries') === array_keys(array_filter(Extensions::CATALOG, fn (array $row): bool => $row[2])),
    array_values(array_diff($a348Keys('office'), $a348Keys('enquiries')))],
    [['novinky', 'statistika', 'presmerovani', 'claude'], true, ['customers', 'pipeline', 'quotes']]);
check('3.48 A1: a key that cannot be switched on on a site (unavailable) is left out of the profile; Customers and the Pipeline are off by default', [
    Extensions::profileOf(['a' => ['A', '', false, 'website'], 'b' => ['B', '', false, 'office'], 'hr' => ['HR', '', false, 'office']], 'office', fn (string $k): ?string => $k === 'hr' ? 'needs the vault' : null),
    Extensions::unavailable(null, 'pipeline'), Extensions::CATALOG['customers'][2], Extensions::CATALOG['pipeline'][2]],
    [['a', 'b'], null, false, false]);
check('3.48 A1: the Pipeline needs Customers – enabled() and the closure add them (save() closes the set the same way, smoke 4300)', [
    Extensions::REQUIRES, Extensions::enabled($reportSettings(['extensions' => 'novinky,pipeline'])), Extensions::closed(['pipeline', 'quotes']), Extensions::closed(['customers', 'pipeline']),
    Extensions::isEnabled($reportSettings(['extensions' => '']), 'customers'), Extensions::isEnabled($reportSettings(['extensions' => 'pipeline']), 'customers')],
    [['pipeline' => 'customers'], ['novinky', 'customers', 'pipeline'], ['customers', 'pipeline', 'quotes'], ['customers', 'pipeline'], false, true]);

// A2: the switch – the modules, the tools (every handler of the two traits), the instructions
$a348ToolsOf = function (string $trait): array {
    $names = [];
    foreach ((new ReflectionClass($trait))->getMethods() as $method) {
        if (str_starts_with($method->getName(), 'tool')) {
            $names[] = strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', substr($method->getName(), 4)));
        }
    }

    return $names;
};
$a348Contact = $a348ToolsOf(Kaleta\Mcp\Handlers\ContactTools::class);
$a348Deal = $a348ToolsOf(Kaleta\Mcp\Handlers\DealTools::class);
check('3.48 A2: Customers and the Pipeline are features of their modules; every tool of ContactTools needs customers, every one of DealTools pipeline', [
    Kaleta\Admin\Modules\Contacts::EXTENSION, Kaleta\Admin\Modules\Pipeline::EXTENSION, count($a348Contact), count($a348Deal),
    array_values(array_unique(array_map(Catalog::extension(...), $a348Contact))), array_values(array_unique(array_map(Catalog::extension(...), $a348Deal)))],
    ['customers', 'pipeline', 5, 4, ['customers'], ['pipeline']]);
$a348All = array_map(Kaleta\Mcp\Server::listed(...), Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()));
$a348Listed = fn (string $stored): array => array_column(array_filter($a348All, fn (array $t): bool => ($e = Catalog::extension($t['name'])) === '' || Extensions::isEnabled($reportSettings(['extensions' => $stored]), $e)), 'name');
$a348Json = fn (array $tools): int => strlen((string) json_encode(['tools' => $tools], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
check('3.48 A2: without the features tools/list leaves out the nine tools (Tools::listAll filters by Catalog::extension); with them they are back; the work set stays inside its budget', [
    array_values(array_intersect([...$a348Contact, ...$a348Deal], $a348Listed('novinky,poptavky,claude'))), count(array_intersect([...$a348Contact, ...$a348Deal], $a348Listed('novinky,poptavky,customers,pipeline,claude'))),
    $a348Json(Kaleta\Mcp\Discovery::essentials(array_values(array_filter($a348All, fn (array $t): bool => !in_array(Catalog::extension($t['name']), ['customers', 'pipeline'], true))), 'full', Catalog::listOf('work') ?? [])) <= Catalog::BUDGETS['work']],
    [[], 9, true]);
$a348Instructions = Kaleta\Mcp\Translator::instructions('work', []);
check('3.48 A2: the server instructions name customers and deals only where they are on (INSTRUCTION_FEATURES)', [
    str_contains($a348Instructions, 'list_contacts'), str_contains($a348Instructions, 'list_deals'),
    str_contains(Kaleta\Mcp\Translator::instructions('work', array_intersect_key(Kaleta\Mcp\Translator::INSTRUCTION_FEATURES, ['customers' => 1, 'pipeline' => 1])), 'merge_contacts cannot be taken back'),
    array_keys(array_intersect_key(Kaleta\Mcp\Translator::INSTRUCTION_FEATURES, ['customers' => 1, 'pipeline' => 1]))],
    [false, false, true, ['customers', 'pipeline']]);
check('3.48 A2: a record of a switched-off section is named, never linked (contacts and companies with Customers off, deals with the Pipeline off)', [
    Kaleta\Core\Inbox::switchedOff($reportSettings(['extensions' => 'poptavky']), 'contact'), Kaleta\Core\Inbox::switchedOff($reportSettings(['extensions' => 'poptavky']), 'company'),
    Kaleta\Core\Inbox::switchedOff($reportSettings(['extensions' => 'customers']), 'deal'), Kaleta\Core\Inbox::switchedOff($reportSettings(['extensions' => 'pipeline']), 'deal'),
    Kaleta\Core\Inbox::switchedOff($reportSettings(['extensions' => '-']), 'enquiry')],
    [true, true, true, false, false]);

// A3: the Features screen – a profile is a preselection (keys outside every profile stay as they are) and says what it switches off
$a348Pre = Kaleta\Admin\Modules\Extensions::preselect(null, 'website', ['novinky', 'poptavky', 'customers', 'pipeline', 'bookings', 'statistika', 'claude']);
check('3.48 A3: ?profile=website on a site with enquiries and the CRM – the website keys plus Bookings (in no profile), and Enquiries, Customers, the Pipeline named as what goes', [
    $a348Pre, Kaleta\Admin\Modules\Extensions::preselect(null, 'office', ['novinky', 'jazyky'])['keys'], Kaleta\Admin\Modules\Extensions::preselect(null, 'office', ['novinky'])['off']],
    [['keys' => ['novinky', 'bookings', 'statistika', 'presmerovani', 'claude'], 'off' => ['poptavky', 'customers', 'pipeline']],
        ['novinky', 'poptavky', 'customers', 'pipeline', 'statistika', 'presmerovani', 'jazyky', 'quotes', 'claude'], []]);

// A4: the installer – the five rows of the plan
check('3.48 A4: the installer – a profile, "custom" with ticks (closed under REQUIRES), "custom" with none, an old script with a list, neither', [
    Kaleta\Install\Installer::chosenFeatures('office', ['novinky'], true), Kaleta\Install\Installer::chosenFeatures('custom', ['pipeline', 'claude', 'nonsense'], true),
    Kaleta\Install\Installer::chosenFeatures('custom', [], true), Kaleta\Install\Installer::chosenFeatures('', ['novinky', 'poptavky', 'nonsense'], false),
    Kaleta\Install\Installer::chosenFeatures('', [], false)],
    [Extensions::profile(null, 'office'), ['customers', 'pipeline', 'claude'], [], ['novinky', 'poptavky'], []]);

// A5: the hubs – the menu names, the tabs and their actions, the summed badge
$a348Mods = [];
foreach (Kaleta\Admin\Kernel::MODULES as $a348Class) {
    $a348Mods[$a348Class::IDENT] = $a348Class;
}
$a348Only = fn (array $idents): array => array_intersect_key($a348Mods, array_flip($idents));
check('3.48 A5: the menu name – Pipeline alone, Quotes alone, both "Sales"; Redirects with Broken links "Addresses"; an editor with News only keeps Broken links as its own item', [
    Kaleta\Admin\Modules\Pipeline::menuName($a348Only(['pipeline'])), Kaleta\Admin\Modules\Quotes::menuName($a348Only(['quotes'])), Kaleta\Admin\Modules\Pipeline::menuName($a348Only(['pipeline', 'quotes'])),
    array_column(MenuGroups::groups($a348Only(['quotes'])), 'items'), array_column(MenuGroups::groups($a348Only(['pipeline', 'quotes'])), 'items'),
    Kaleta\Admin\Modules\Redirects::menuName($a348Only(['redirects', 'broken_links'])), Kaleta\Admin\Modules\Redirects::menuName($a348Only(['redirects'])),
    array_column(MenuGroups::groups($a348Only(['news', 'broken_links'])), 'items')],
    ['Pipeline', 'Quotes', 'Sales', [['quotes']], [['pipeline']], 'Addresses', 'Redirects', [['news'], ['broken_links']]]);
check('3.48 A5: the tabs – every screen of the price list highlights "Internal prices", the settings of Quotes the Quotes tab; one row of the hub', [
    Hubs::current(Hubs::TABS['sales'], 'quotes', 'prices'), Hubs::current(Hubs::TABS['sales'], 'quotes', 'prices_import'), Hubs::current(Hubs::TABS['sales'], 'quotes', 'list_save'),
    Hubs::current(Hubs::TABS['sales'], 'quotes', 'settings'), Hubs::current(Hubs::TABS['sales'], 'pipeline', ''), Hubs::current(Hubs::TABS['addresses'], 'broken_links', ''),
    Hubs::current(Hubs::TABS['users'], 'users', 'departments'),
    Kaleta\Admin\Modules\Quotes::HUB, Kaleta\Admin\Modules\Quotes::PARENT, Kaleta\Admin\Modules\BrokenLinks::HUB, Kaleta\Admin\Modules\BrokenLinks::PARENT, Kaleta\Admin\Modules\Redirects::HUB],
    [2, 2, 2, 1, 0, 1, 2, 'sales', 'pipeline', 'addresses', 'redirects', 'addresses']);
check('3.48 A5: the item of a hub counts the screens under it (MenuGroups::members) – the Sales item its deals and quotes, a lone item only itself', [
    MenuGroups::members($a348Only(['pipeline', 'quotes', 'enquiries']), 'pipeline'), MenuGroups::members($a348Only(['quotes']), 'quotes'), MenuGroups::members($a348Only(['redirects', 'broken_links']), 'redirects')],
    [['pipeline', 'quotes'], ['quotes'], ['redirects', 'broken_links']]);

// A6: the data migration – the highest number of the release, run by name; its keys are the catalogue's in its order
$a348Order = (function (): array {
    $source = (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0135-customers-and-pipeline-features.php');
    preg_match('/\$order = \[([^\]]+)\]/', $source, $m);

    return array_map(fn (string $k): string => trim($k, " '"), explode(',', $m[1] ?? ''));
})();
check('3.48 A6: migration 0135 is the data migration with the highest number; it writes down the 3.48 catalogue in its order (the update request of 3.47 runs it with its own classes)', [
    in_array('0135-customers-and-pipeline-features', Kaleta\Core\Migration::DATA, true), max(array_keys(Kaleta\Core\Migration::files())), KALETA_DB_VERSION,
    $a348Order === array_keys(Extensions::CATALOG)],
    [true, 137, 137, true]); // 3.49 A: 0137 (saved replies) is a structure migration – 0135 stays the highest data migration

// A7: the smoke suite writes the feature list only through features() of bootstrap.sh – a raw write would switch the CRM off
// for every later section of the one shared site
$a348Raw = [];
foreach (glob(KALETA_ROOT . '/tools/tests/smoke/[0-9][0-9][0-9][0-9]-*.sh') ?: [] as $a348File) {
    $a348Text = (string) file_get_contents($a348File);
    foreach (["/UPDATE\\s+ka_nastaveni\\s+SET(?:(?!;|\\bSELECT\\b).)*?promenna\\s*=\\s*'extensions'/i", "/\\(\\s*'extensions'\\s*,/", "/DELETE\\s+FROM\\s+ka_nastaveni\\s+WHERE\\s+promenna\\s*(=\\s*'extensions'|IN\\s*\\([^)]*'extensions')/i"] as $a348Pattern) {
        if (preg_match($a348Pattern, $a348Text) === 1) {
            $a348Raw[] = basename($a348File);
            break;
        }
    }
}
check('3.48 A7: no smoke section writes the feature list itself – only features() in bootstrap.sh (which keeps Customers and the Pipeline on)', [
    $a348Raw, str_contains((string) file_get_contents(KALETA_ROOT . '/tools/tests/smoke/bootstrap.sh'), "\nfeatures() {")],
    [[], true]);
