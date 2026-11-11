<?php

declare(strict_types=1);

use Kaleta\Core\MonthlyReport;

/* ---------- 3.29.1 review N3291-1: no timing oracle for user names and e-mails – the dummy hash has this PHP's cost ---------- */
$dummy3291 = (new ReflectionMethod(Kaleta\Core\Auth::class, 'dummyHash'))->invoke(null);
$costOf3291 = static fn (string $hash): int => (int) (password_get_info($hash)['options']['cost'] ?? 0);
$defaultCost3291 = $costOf3291(password_hash('cost-probe-3291', PASSWORD_DEFAULT));
check('3.29.1 review N3291-1: a name with no account is checked against a dummy hash of the cost this PHP gives new passwords; a wrong password for an older, cheaper hash is padded to the same work', [
    $costOf3291($dummy3291) === $defaultCost3291, password_verify('anything', $dummy3291),
    Kaleta\Core\Auth::paddingChecks(password_hash('x-3291', PASSWORD_BCRYPT, ['cost' => 10])), Kaleta\Core\Auth::paddingChecks(password_hash('x-3291', PASSWORD_DEFAULT)),
    Kaleta\Core\Auth::paddingChecks('not a hash'),
    // work in units of a cost-10 check: the dummy's against an older cost-10 password's plus its padding
    2 ** ($costOf3291($dummy3291) - 10) === 1 + Kaleta\Core\Auth::paddingChecks(password_hash('x-3291', PASSWORD_BCRYPT, ['cost' => 10])),
    str_contains($authSource, 'for ($i = self::paddingChecks((string) $user[\'password\']); $i > 0; $i--) {')],
    [true, false, $defaultCost3291 === 12 ? 3 : 0, 0, 0, true, true]);
// 3.31 (agent A): the lean pipeline – deals, stages, money as numbers with a currency, retention, MCP, the monthly report
$d331 = Kaleta\Core\Deals::class;
check('3.31: the stages – the defaults (labels translated in the UI language), a custom pipeline as stored (its labels as typed), a damaged or invalid one falls back to the defaults', [
    Kaleta\Core\Language::runWith('en', fn (): array => array_map(fn (array $s): string => $s['key'] . ':' . $s['kind'] . ':' . $s['label'], $d331::parseStages('')), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): array => array_column($d331::parseStages(''), 'label'), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): array => array_map(fn (array $s): string => $s['key'] . ':' . $s['label'] . ':' . (int) $s['custom'],
        $d331::parseStages('[{"key":"new","label":"","kind":"open"},{"key":"demo","label":"Demo booked","kind":"open"},{"key":"won","label":"Signed","kind":"won"},{"key":"lost","label":"","kind":"lost"}]')), 'admin-'),
    array_column($d331::parseStages('{broken'), 'key'), array_column($d331::parseStages('[{"key":"a","label":"A","kind":"open"},{"key":"b","label":"B","kind":"open"},{"key":"c","label":"C","kind":"lost"}]'), 'key'),
    $d331::DEFAULT_STAGES['won'][1], Kaleta\Core\Settings::DEFAULTS['pipeline_stages'] ?? null,
], [['new:open:New', 'qualified:open:Qualified', 'proposal:open:Proposal', 'won:won:Won', 'lost:lost:Lost'], ['Nový', 'Kvalifikovaný', 'Nabídka', 'Vyhraný', 'Ztracený'],
    ['new:Nový:0', 'demo:Demo booked:1', 'won:Signed:1', 'lost:Ztracený:0'], ['new', 'qualified', 'proposal', 'won', 'lost'], ['new', 'qualified', 'proposal', 'won', 'lost'], 'won', '']);
$d331Refuse = function (callable $fn): string {
    try {
        return (string) json_encode($fn());
    } catch (DomainException $e) {
        return 'refused: ' . $e->getMessage();
    }
};
check('3.31: a value is a non-negative number with at most two decimals (spaces, a decimal comma and thousands separators of both kinds read), none when empty; a currency from the fixed ISO list', [
    array_map($d331::value(...), ['12 500,50', '12.500,50', '12,500.50', '12500.5', '0', '7', ' ', '', null]),
    Kaleta\Core\Language::runWith('en', fn (): array => array_map(fn (mixed $v): string => $d331Refuse(fn () => $d331::value($v)), ['-5', 'abc', '1.234', '99999999999', true]), 'admin-'),
    array_map($d331::currency(...), ['eur', ' CZK ', '']), Kaleta\Core\Language::runWith('en', fn (): string => $d331Refuse(fn () => $d331::currency('XYZ')), 'admin-'),
    in_array('CZK', $d331::CURRENCIES, true) && in_array('EUR', $d331::CURRENCIES, true) && count($d331::CURRENCIES) === count(array_unique($d331::CURRENCIES)),
    $d331::defaultCurrency($reportSettings(['site_language' => 'cs'])), $d331::defaultCurrency($reportSettings([])),
], [['12500.50', '12500.50', '12500.50', '12500.50', '0.00', '7.00', null, null, null],
    ['refused: The value cannot be negative.', 'refused: The value must be a number.', 'refused: The value must be a number.', 'refused: The value is too large.', 'refused: The value must be a number.'],
    ['EUR', 'CZK', ''], 'refused: “XYZ” is not a currency Kaleta offers.', true, 'CZK', 'EUR']);
// 3.32 (U29): one money formatter – thousands separated, ".00" left out, sign or code by the admin language; the default
// currency is a setting, automatic = company country → the deals' most used currency → site language (3.32 review: the time
// zone is no evidence – the installer sets it from its language – and an existing site keeps the currency its deals use)
$m332 = fn (string $lang): array => Kaleta\Core\Language::runWith($lang, fn (): array => [Kaleta\Core\Deals::formatValue('18000.00', 'GBP'), Kaleta\Core\Deals::formatValue('10800.5', 'EUR'),
    Kaleta\Core\Deals::formatValue('1250000', 'CZK'), Kaleta\Core\Deals::formatValue('99.99', 'USD'), Kaleta\Core\Deals::formatValue(null, 'EUR')], 'admin-');
$a332Settings = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php');
preg_match("/CURRENCY_CHOICES = '([^']+)'/", $a332Settings, $a332Choices);
check('3.32 (U29): deal values read as money in each admin language; the default currency is chosen or derived; the board totals use the same rule', [
    $m332('en'), $m332('cs'), $m332('de'), $m332('es'),
    Kaleta\Core\Deals::defaultCurrency($reportSettings(['default_currency' => 'SEK', 'company_country' => 'GB'])),
    Kaleta\Core\Deals::defaultCurrency($reportSettings(['default_currency' => 'XXX', 'company_country' => 'gb'])),
    Kaleta\Core\Deals::defaultCurrency($reportSettings(['time_zone' => 'Europe/London'])),
    Kaleta\Core\Deals::defaultCurrency($reportSettings(['time_zone' => 'Europe/Warsaw', 'site_language' => 'cs'])),
    Kaleta\Core\Deals::defaultCurrency($reportSettings(['time_zone' => 'Europe/Prague', 'site_language' => 'en', 'company_country' => 'cz'])),
    Kaleta\Core\Deals::mostUsedCurrency($reportSettings([])),
    explode('|', $a332Choices[1] ?? '') === Kaleta\Core\Deals::CURRENCIES, Kaleta\Core\Settings::DEFAULTS['default_currency'] ?? null,
    array_values(array_diff(array_unique(Kaleta\Core\Deals::COUNTRY_CURRENCIES), Kaleta\Core\Deals::CURRENCIES)),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/admin.js'), "board.getAttribute('data-money')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/pipeline/board.php'), 'data-money="<?= e((string) json_encode(Deals::moneyFormat()'),
], [["£18,000", "€10,800.50", "CZK\u{00A0}1,250,000", '$99.99', ''], ["18\u{00A0}000\u{00A0}£", "10\u{00A0}800,50\u{00A0}€", "1\u{00A0}250\u{00A0}000\u{00A0}Kč", "99,99\u{00A0}$", ''],
    ["18.000\u{00A0}£", "10.800,50\u{00A0}€", "1.250.000\u{00A0}CZK", "99,99\u{00A0}$", ''], ["18.000\u{00A0}£", "10.800,50\u{00A0}€", "1.250.000\u{00A0}CZK", "99,99\u{00A0}$", ''],
    'SEK', 'GBP', 'EUR', 'CZK', 'CZK', '', true, '', [], true, true]);
// 3.32 (U35): row actions 32 px (40 px on touch and phone), the task title a link, Edit and Take it in My work
$u35Css = (string) file_get_contents(KALETA_ROOT . '/image/admin.css');
$u35View = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/my_work/list.php');
check('3.32 (U35): list row buttons are 32/40 px; My work links the task title, edits a task (a form outside the Done form) and takes an enquiry', [
    (bool) preg_match('/table\.vypis button\.navigace,\s*table\.vypis a\.navigace,[^{]*\{[^}]*min-height: 32px;/', $u35Css),
    (bool) preg_match('/@media \(pointer: coarse\), \(max-width: 640px\) \{\s*table\.vypis button\.navigace,[^{]*\{ min-height: 40px;/', $u35Css),
    str_contains($u35Css, 'table.vypis button.navigace {' . "\n\tpadding: 2px 8px;"),
    str_contains($u35View, 'class="moje-ukol-nazev"'), substr_count($u35View, 'form="<?= e($editForm) ?>"'),
    strpos($u35View, "action=\"<?= e(\$module->url('task_edit')) ?>\"") > strpos($u35View, '</form>'),
    str_contains($u35View, "\$module->url('take')"),
    method_exists(Kaleta\Admin\Modules\MyWork::class, 'actionTaskEdit'), method_exists(Kaleta\Admin\Modules\MyWork::class, 'actionTake'),
], [true, true, false, true, 5, true, true, true, true]);
// 3.32 (U36, A2): Workspace-only people land on My work; the look bar only on the dashboard and the Appearance screens
$u36 = fn (bool $admin, array $idents): bool => Kaleta\Admin\Kernel::landsOnMyWork($admin, array_intersect_key(array_column(array_map(fn (string $c): array => [$c::IDENT, $c], Kaleta\Admin\Kernel::MODULES), 1, 0), array_flip($idents)));
check('3.32 (U36, A2): after sign-in only a person without content sections lands on My work; the look bar shows on the dashboard and the Appearance group', [
    $u36(false, ['my_work', 'media', 'enquiries', 'contacts', 'pipeline']), $u36(false, ['my_work', 'media', 'enquiries', 'pages']), $u36(false, ['my_work', 'media', 'news']),
    $u36(true, ['my_work', 'media', 'enquiries']), $u36(false, ['media', 'enquiries']),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php'), "\$lookHere = \$onDashboard || (isset(\$modules[\$active]) && \$modules[\$active]::GROUP === 'Appearance');"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php'), "self::landsOnMyWork(\$this->app->auth()->isAdmin(), \$this->modules()) ? 'admin.php?module=my_work' : 'admin.php'"),
], [true, false, false, false, false, true, true]);
// 3.32 (U37): the dashboard leads with the site's numbers; Connect Claude, First steps and the examples fold to one line
$u37Dash = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/dashboard.php');
$u37Connect = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/claude_connect.php');
check('3.32 (U37): the tiles come before the onboarding blocks, which are details (Connect Claude open for administrators, First steps while a step is left)', [
    strpos($u37Dash, '<div class="dlazdice">') < strpos($u37Dash, "render('admin/claude_connect'"), strpos($u37Dash, "render('admin/pending_review'") < strpos($u37Dash, '<div class="dlazdice">'),
    str_contains($u37Dash, "<?= \$finished < count(\$firstSteps) ? ' open' : '' ?>"), str_contains($u37Connect, "<?= \$connect['admin'] ? ' open' : '' ?>"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/ask_claude.php'), '<details class="ask-claude-priklady-obal"><summary>'),
], [true, true, true, true, true]);
// 3.32 (U46, U47, U49, U50): scrolling table wrappers become focusable regions, the cookie table is a listing, yes/no boxes
// are named by their row, the charts are groups of bars that are images
$a11yJs = (string) file_get_contents(KALETA_ROOT . '/image/admin.js');
check('3.32 (U46, U47, U49, U50): focusable scrollers, the cookie listing, named boxes, chart roles', [
    str_contains($a11yJs, "box.setAttribute('tabindex', '0');") && str_contains($a11yJs, "box.setAttribute('role', 'region');") && strpos($a11yJs, 'var markScrollers') > strpos($a11yJs, "tab.classList.add('vypis-karty');"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/cookies.php'), "<div class=\"tab-obal\">\n<table class=\"vypis cookies-tabulka\">"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/list.php'), "<input type=\"checkbox\" aria-labelledby=\"' . e(\$key) . '-popisek\" name=\"' . e(\$key) . '\" value=\"1\"'"),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/views/admin/fleet/alerts.php'), '-popisek"'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/stats/list.php'), 'role="group" aria-label="<?= e(t(\'Bar chart of page views by day\')) ?>" aria-describedby="graf-souhrn"'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/views/admin/dashboard.php'), 'role="img"'),
], [true, true, true, 4, true, 1]);
// 3.32 (U48): System status lists the problems first and folds the all-green groups; every check stays
$u48 = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/settings/health.php');
check('3.32 (U48): System status – problems first with links to their rows, a group open only with a problem, every check a row', [
    strpos($u48, 'data-status-problems') < strpos($u48, 'data-status-group'), str_contains($u48, "<details class=\"stav-skupina\" data-status-group<?= \$bad > 0 ? ' open' : '' ?>>"),
    str_contains($u48, '<a href="#stav-<?= (int) $i ?>">') && str_contains($u48, '<tr id="stav-<?= (int) $i ?>"'), str_contains($u48, 'foreach ($checks as $i => $k) {'),
], [true, true, true, true]);
$d331Kinds = ['new' => 'open', 'qualified' => 'open', 'won' => 'won', 'lost' => 'lost', 'lost2' => 'lost'];
check('3.31: a move is created, moved, won, lost or reopened (from a closing stage to an open one); the owner filter takes mine, none, an id', [
    $d331::event(null, 'new', $d331Kinds), $d331::event('new', 'qualified', $d331Kinds), $d331::event('qualified', 'won', $d331Kinds), $d331::event('won', 'lost', $d331Kinds),
    $d331::event('lost', 'new', $d331Kinds), $d331::event('lost', 'lost2', $d331Kinds),
    $d331::ownerFilter('mine', 7), $d331::ownerFilter('mine', 0), $d331::ownerFilter('none', 7), $d331::ownerFilter('3', 7), $d331::ownerFilter(0, 7), $d331::ownerFilter(-1, 7), $d331::STATUSES,
], ['created', 'moved', 'won', 'lost', 'reopened', 'lost', 7, -1, -1, 3, 0, -1, ['open', 'closed', 'won', 'lost', 'all']]);
// 3.31.1: save_deal with enquiry honours stage – createFromEnquiry passes it to save() and refuses a stage that is not open
// before it links the enquiry's contact (the same refusal as a new deal without an enquiry)
$a3311From = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Deals.php');
$a3311FromBody = substr($a3311From, (int) strpos($a3311From, 'public static function createFromEnquiry('), 2600);
check('3.31.1: createFromEnquiry takes stage (an open one, checked before the contact is linked, the refusal of save()); the tool says so; the parameter stays', [
    str_contains($a3311FromBody, "array_flip(['title', 'owner_id', 'value', 'currency', 'expected_close', 'stage', 'contact_id', 'company_id'])" /* 3.32: and the customer of the prefilled form */),
    strpos($a3311FromBody, "throw new \\DomainException(t('A new deal starts in an open stage.'));") < strpos($a3311FromBody, 'Contacts::link($app'),
    substr_count($a3311From, "t('A new deal starts in an open stage.')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php'), "'stage' => \$text('a new deal only (also with enquiry): the open stage it starts in"),
    json_decode((string) file_get_contents(KALETA_ROOT . '/tools/contracts/mcp-tools.json'), true)['save_deal']['parameters']['stage'] ?? null],
    [true, true, 2, true, 'string']);
check('3.31: a deal\'s title names the person when it has every word of their name or their address – such a title loses it when the person is erased', [
    $d331::names('Olga Nováková – yearly contract', 'Olga Nováková', 'olga@x.cz'), $d331::names('Yearly contract for NOVÁKOVÁ Olga', 'Olga Nováková', ''), $d331::names('Website for Acme', 'Olga Nováková', 'olga@acme.cz'),
    $d331::names('Offer sent to olga@acme.cz', 'Olga', 'olga@acme.cz'), $d331::names('Olga\'s website', 'Olga Nováková', ''), $d331::names('Anything', '', ''), $d331::names('Jo Li deal', 'Jo Li', ''),
    $d331::names('Anything', '', 'not-an-address'), $d331::names('Information pack', '', 'info@acme.cz'),
], [true, true, false, true, false, false, false, false, false]);
$d331Src = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Deals.php');
check('3.31: won and lost close a deal, lost needs a reason, reopening clears it; one open deal per enquiry; every move is in the history; delete is for administrators', [
    str_contains($d331Src, "\$closedAt = \$to['kind'] === 'open' ? null :"), str_contains($d331Src, "if (!isset(self::LOST_REASONS[\$reason])) {"),
    str_contains($d331Src, "['stage' => \$to['key'], 'closed_at' => \$closedAt, 'lost_reason' => \$reason, 'lost_note' => \$note"), substr_count($d331Src, "\$db->insert('deal_stage_history',"),
    substr_count($d331Src, '$open = self::openForEnquiry($db, '), str_contains($d331Src, "if (!\$app->auth()->isAdmin()) {\n            throw new \\DomainException(t('Only an administrator can delete a deal.'));"),
    array_keys($d331::LOST_REASONS), $d331::SECTION, Kaleta\Core\Assignment::KINDS['deal'] ?? null,
], [true, true, true, 2, 2, true, ['price', 'competitor', 'timing', 'no_response', 'not_a_fit', 'other'], 'pipeline', 'pipeline']);
check('3.31 (D-W6): an enquiry of a deal is kept while the deal is open and for the retention after it closed; a customer with an open deal is never anonymised; an erased or anonymised customer\'s deals stay without the person', [
    str_contains($d331Src, "\$live = '(d.closed_at IS NULL' . (\$months > 0 ? ' OR d.closed_at >= ?' : '') . ')';"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php'), '$held = \Kaleta\Core\Contacts::heldByDeals($db, $expired, $months);'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Contacts.php'), "return Deals::hasOpenDeal(\$db, \$contactId);"),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Contacts.php'), 'Deals::unlinkContacts($db, '),
    str_contains($d331Src, "['contact_id' => null, 'title' => \$title, 'lost_note' => null, 'updated_at' => Clock::now()]"),
    Kaleta\Core\Contacts::heldByDeals(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''), []),
], [true, true, true, 2, true, []]);
$d331Schema = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
$d331Migration = (string) @file_get_contents(KALETA_SYSTEM . '/sql/migrace/0117-pipeline.sql');
$d331Columns = fn (string $sql, string $table): array => preg_match('/CREATE TABLE ' . $table . ' \((.+?)\) ENGINE/s', $sql, $m) ? array_values(array_filter(array_map(fn (string $l): string => preg_match('/^\s*([a-z_]+)\s+(INT|VARCHAR|DATETIME|DATE|TEXT|DECIMAL|CHAR)\b/', $l, $c) ? $c[1] : '', explode("\n", $m[1])))) : [];
check('3.31: ka_deals and ka_deal_stage_history alike in schema.sql and migration 0117 (indexes for stage, owner, contact, company, enquiry; no foreign keys), the names map, the moments and the civil day, the section granted with Customers, DB version 117, never journaled', [
    $d331Columns($d331Schema, 'ka_deals'), $d331Columns($d331Schema, 'ka_deals') === $d331Columns($d331Migration, 'ka_deals'),
    $d331Columns($d331Schema, 'ka_deal_stage_history') === $d331Columns($d331Migration, 'ka_deal_stage_history') && $d331Columns($d331Schema, 'ka_deal_stage_history') !== [],
    preg_match_all('/KEY ix_deals_(stage|owner|contact|company|enquiry) /', $d331Migration), preg_match_all('/KEY ix_deals_(stage|owner|contact|company|enquiry) /', $d331Schema), str_contains($d331Migration, 'FOREIGN KEY'),
    str_contains($d331Schema, 'value             DECIMAL(12,2) NULL') && str_contains($d331Schema, 'currency          CHAR(3) CHARACTER SET ascii NULL'),
    array_map(Kaleta\Core\Names::english(...), ['deals', 'deal_stage_history']), Kaleta\Core\TimeZoneConversion::INSTANTS['deals'] ?? null, Kaleta\Core\TimeZoneConversion::INSTANTS['deal_stage_history'] ?? null,
    Kaleta\Core\TimeZoneConversion::CIVIL['deals'] ?? null, KALETA_DB_VERSION >= 117,
    str_contains($d331Migration, "SELECT p.fk_id_user, 'pipeline' FROM (SELECT fk_id_user FROM ka_uzivatele_prava WHERE ident_modulu = 'contacts') AS p;")
        && str_contains($d331Migration, "UPDATE ka_role SET moduly = CONCAT(moduly, ',pipeline') WHERE FIND_IN_SET('contacts', moduly) > 0 AND FIND_IN_SET('pipeline', moduly) = 0;"),
    array_map(fn (string $t): bool => in_array($t, Kaleta\Core\AgentJournal::PERSONAL_TABLES, true) && !Kaleta\Core\AgentJournal::journaled($t), ['deals', 'deal_stage_history']),
], [['id', 'title', 'contact_id', 'company_id', 'owner_id', 'stage', 'value', 'currency', 'source_enquiry_id', 'expected_close', 'closed_at', 'lost_reason', 'lost_note', 'created_by', 'created_at', 'updated_at'],
    true, true, 5, 5, false, true, ['deals', 'deal_stage_history'], ['closed_at', 'created_at', 'updated_at'], ['at'], ['expected_close'], true, true, [true, true]]);
$d331Mail = Kaleta\Core\Assignment::mail($reportSettings([]), ['jazyk' => 'en', 'register' => ''], 'deal', ['id' => 12, 'title' => '#12', 'path' => ''], 'Jana', 'https://x/1');
check('3.31: the bell and the assignment e-mail name a deal by its number only (its title may name a customer); the hand-over counts deals and needs the pipeline; My work lists them', [
    Kaleta\Core\Inbox::parse('deal:3'), Kaleta\Core\Inbox::TARGETS['deal'] ?? null, Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Inbox::where(['kind' => 'deal', 'id' => 4, 'title' => '#4']), 'admin-'),
    $d331Mail[0], str_contains($d331Mail[1], 'deal #12'), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Assignment.php'), "['id' => \$id, 'title' => '#' . \$id, 'path' => Deals::adminPath(\$id)]"),
    Kaleta\Core\Handover::sectionsOf(['page' => 0, 'item' => 0, 'enquiry' => 0, 'deal' => 2]), Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Handover::describe(['page' => 0, 'item' => 0, 'enquiry' => 0, 'deal' => 2]), 'admin-'),
    Kaleta\Core\MyWork::LISTS['deals'] ?? null, $d331::adminPath(5),
], [['deal', 3], 'deal', 'Deal #4', 'You are responsible for the deal #12', true, true, ['pipeline'], 'deals: 2', 'pipeline', 'admin.php?module=pipeline&action=detail&id=5']);
$d331Tools = ['list_deals', 'save_deal', 'move_deal', 'pipeline_summary'];
$d331Defs = array_column(Kaleta\Mcp\Tools::definitions(), null, 'name');
$d331Handler = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/DealTools.php');
check('3.31: the pipeline tools – English on both sides, in the work set and never in the essentials, site-neutral, move_deal idempotent, no delete over MCP, titled, the access check in every handler, the writes say they are not undoable', [
    array_map(fn (string $t): ?array => Kaleta\Mcp\Catalog::TOOLS[$t] ?? null, $d331Tools), array_map(Kaleta\Mcp\Translator::czech(...), $d331Tools),
    array_values(array_intersect($d331Tools, Kaleta\Mcp\Catalog::ESSENTIALS)), array_values(array_intersect($d331Tools, Kaleta\Mcp\Catalog::WORK)),
    array_map(Kaleta\Mcp\Catalog::isSiteNeutral(...), ['save_deal', 'move_deal']), Kaleta\Mcp\Catalog::isIdempotent('move_deal'), Kaleta\Mcp\Catalog::isIdempotent('save_deal'),
    array_values(array_filter(array_keys(Kaleta\Mcp\Catalog::TOOLS), fn (string $t): bool => str_contains($t, 'deal') && str_starts_with($t, 'delete'))),
    array_map(fn (string $t): bool => isset(Kaleta\Mcp\Catalog::TITLES[$t]), $d331Tools), substr_count($d331Handler, '$this->pipelineOpen();'),
    array_map(fn (string $t): bool => str_contains($d331Defs[$t]['description'] ?? '', 'NOT UNDOABLE'), ['save_deal', 'move_deal']), $d331Defs['move_deal']['inputSchema']['required'] ?? null,
    Kaleta\Mcp\Catalog::allows('drafts', 'save_deal'), Kaleta\Mcp\Tools::annotations('pipeline_summary')['readOnlyHint'],
], [[['read', ''], ['write', ''], ['write', ''], ['read', '']], $d331Tools, [], $d331Tools, [true, true], true, false, [], [true, true, true, true], 4, [true, true], ['id', 'stage'], false, true]);
$d331Report = Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Core\ReportPage::sections(['month' => '2026-10', 'pipeline' => ['new' => 4, 'won' => 1, 'lost' => 2,
    'stages' => [['key' => 'new', 'label' => 'New', 'n' => 4], ['key' => 'qualified', 'label' => 'Qualified', 'n' => 0], ['key' => 'won', 'label' => 'Won', 'n' => 1]]]]), 'admin-');
$d331Section = array_values(array_filter($d331Report, fn (array $s): bool => $s['key'] === 'pipeline'))[0] ?? null;
check('3.31 (D-W7): the monthly report counts the pipeline – new, won and lost deals and the stages reached (those with deals) – never a value; none without deals', [
    $d331Section['title'] ?? null, $d331Section['blocks'][0]['rows'] ?? null, $d331Section['blocks'][1]['rows'] ?? null,
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/MonthlyReport.php'), "'pipeline' => \$section(fn (): ?array => self::pipeline(\$app, \$from, \$to)),"),
    preg_match('/function monthCounts.*?\n    }\n/s', $d331Src, $d331Fn) === 1 && !str_contains($d331Fn[0], 'SUM(') && !str_contains($d331Fn[0], 'currency'),
    array_values(array_filter(Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Core\ReportPage::sections(['month' => '2026-10']), 'admin-'), fn (array $s): bool => $s['key'] === 'pipeline')),
    array_map(fn (string $part): bool => str_contains(($d331Mail2 ??= MonthlyReport::render($reportData + ['pipeline' => ['new' => 4, 'won' => 1, 'lost' => 2, 'stages' => []]], $reportSettings([]), 'https://example.com'))['text'], $part),
        ["PIPELINE\n  New deals: 4\n  Deals won: 1\n  Deals lost: 2"]), str_contains(MonthlyReport::render($reportData, $reportSettings([]), 'https://example.com')['text'], 'PIPELINE'),
], ['Pipeline', [['New deals', '4'], ['Deals won', '1'], ['Deals lost', '2']], [['New', '4'], ['Won', '1']], true, true, [], [true], false]);
preg_match_all("/\\bt\\('((?:[^'\\\\]|\\\\.)*)'/", $d331Src, $d331Texts);
$d331Strings = array_values(array_unique([...array_map('stripslashes', $d331Texts[1]), ...array_column($d331::DEFAULT_STAGES, 0), ...array_values($d331::LOST_REASONS),
    'You are responsible for the deal #%s', '%s made you the responsible person for the deal #%s.', 'It is listed in My work and under Mine in the pipeline. It does not change who may open it.',
    'Deal #%s', 'deals: %d', 'Pipeline', 'New deals', 'Deals won', 'Deals lost', 'Deals that reached each stage', 'Stage', 'Deals']));
check('3.31: the texts of the pipeline are in every admin language; the du overlay addresses the person informally', [
    count($d331Strings) > 40, array_map(fn (string $code): array => array_values(array_diff($d331Strings, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es']),
    (require KALETA_SYSTEM . '/jazyky/admin-de-du.php')['Give the deal a title.'] ?? null,
], [true, [[], [], []], 'Gib dem Deal einen Titel.']);

// 3.31 (agent B): the Pipeline – the module (Workspace, right after Customers, core, site-neutral, in the role presets next to
// Customers), its filter, the board's columns and exact sums, the stage editor's rows, deal threads and tasks, the timeline
// kind, the palette, the boxes on an enquiry, a customer and a company, My work, the move script and escaping in the views
$b331 = Kaleta\Admin\Modules\Pipeline::class;
$b331Modules = array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES);
$b331Icons = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/icons.php');
$b331Post = fn (string $action): bool => Kaleta\Admin\Kernel::clearsPageCache(new Kaleta\Core\Request(['module' => 'pipeline', 'action' => $action], ['id' => '1'], ['REQUEST_METHOD' => 'POST']));
$b331Preset = fn (string $key): int => array_search('pipeline', Kaleta\Admin\Modules\Roles::PRESETS[$key][3], true) - array_search('contacts', Kaleta\Admin\Modules\Roles::PRESETS[$key][3], true);
check('3.31 (B): the Pipeline – IDENT pipeline (= Core\Deals::SECTION), in Workspace right after Customers, core, site-neutral, comments and tasks, an icon, next to Customers in the presets, named in cs/de/es', [
    $b331::IDENT, Kaleta\Core\Deals::SECTION, $b331::NAME, $b331::GROUP, $b331::EXTENSION, $b331::ADMIN_ONLY, array_search('pipeline', $b331Modules, true) - array_search('contacts', $b331Modules, true),
    in_array('pipeline', Kaleta\Admin\Kernel::SITE_NEUTRAL_MODULES, true), $b331Post('move'), $b331Post('stages_save'), $b331Post('delete'),
    [in_array(Kaleta\Admin\CommentActions::class, class_uses($b331), true), in_array(Kaleta\Admin\TaskActions::class, class_uses($b331), true)],
    str_contains($b331Icons, "'" . $b331::ICON . "' =>"), $b331Preset('client'), $b331Preset('office'), isset(Kaleta\Admin\Guide::MODULES['pipeline']),
    array_map(fn (string $code): string => (require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['Pipeline'] ?? '', ['cs', 'de', 'es'])],
    ['pipeline', 'pipeline', 'Pipeline', 'Workspace', '', false, 1, true, false, false, false, [true, true], true, 1, 1, true, ['Obchody', 'Vertriebspipeline', 'Embudo de ventas']]);
check('3.31 (B): the filter of the board and the list – unknown values fall back, a word is cut; the query leaves the defaults out', [
    $b331::filter([]), $b331::filter(['owner' => 'mine', 'contact' => '12', 'company' => 'x', 'search' => '  Web ', 'status' => 'won', 'view' => 'list']),
    $b331::filter(['owner' => '-1', 'status' => 'nonsense', 'view' => 'kanban', 'contact' => '-3']), $b331::filter(['owner' => '7'])['owner'], mb_strlen($b331::filter(['search' => str_repeat('a', 300)])['search']),
    $b331::query($b331::filter(['owner' => 'none', 'status' => 'won'])), $b331::query($b331::filter(['view' => 'list', 'status' => 'open', 'company' => '4']))],
    [['owner' => '', 'contact' => 0, 'company' => 0, 'search' => '', 'status' => 'open', 'view' => 'board'],
        ['owner' => 'mine', 'contact' => 12, 'company' => 0, 'search' => 'Web', 'status' => 'won', 'view' => 'list'],
        ['owner' => '', 'contact' => 0, 'company' => 0, 'search' => '', 'status' => 'open', 'view' => 'board'], '7', 100,
        ['owner' => 'none'], ['view' => 'list', 'company' => 4]]);
$b331Deal = fn (int $id, string $stage, ?string $value, ?string $currency, ?string $closed = null): array => ['id' => $id, 'stage' => $stage, 'value' => $value, 'currency' => $currency, 'closed_at' => $closed];
$b331Columns = $b331::columns(Kaleta\Core\Deals::parseStages(''), [$b331Deal(1, 'new', '12500.50', 'CZK'), $b331Deal(2, 'new', '0.70', 'CZK'), $b331Deal(3, 'new', '99.99', 'EUR'), $b331Deal(4, 'new', null, null),
    $b331Deal(5, 'won', '100.00', 'EUR', '2026-11-02 10:00:00'), $b331Deal(6, 'lost', '5.00', 'EUR', '2026-10-30 10:00:00'), $b331Deal(7, 'gone', '1.00', 'EUR')], '2026-11-01 00:00:00');
check('3.31 (B): the board – a column per stage in order; open stages sum the values per currency exactly (never across currencies); won and lost hold this month only; an unknown stage is left out', [
    array_column($b331Columns, 'key'), array_column($b331Columns, 'count'), $b331Columns[0]['sums'], $b331Columns[3]['sums'], array_column($b331Columns[3]['deals'], 'id'),
    $b331::sums(array_fill(0, 1000, ['value' => '0.10', 'currency' => 'EUR'])), $b331::sums([['value' => '9999999999.99', 'currency' => 'USD'], ['value' => '0.01', 'currency' => 'USD']])],
    [['new', 'qualified', 'proposal', 'won', 'lost'], [4, 0, 0, 1, 0], ['CZK' => '12501.20', 'EUR' => '99.99'], [], [5], ['EUR' => '100.00'], ['USD' => '10000000000.00']]);
check('3.31 (B): the stage editor – rows in the order of their numbers (unnumbered after, ties as in the form), removed and empty rows left out, an unknown kind is open, a label is cut', [
    $b331::stageRows(['new', 'won', '', 'lost', '', 'qualified'], ['New', 'Won', 'Demo', 'Lost', '', 'Qualified'], ['open', 'won', 'nonsense', 'lost', 'open', 'open'], ['1', '9', '', '5', '', '1'], ['5']),
    mb_strlen($b331::stageRows([''], [str_repeat('x', 100)], ['open'], ['1'], [])[0]['label'])],
    [[['key' => 'new', 'label' => 'New', 'kind' => 'open'], ['key' => 'lost', 'label' => 'Lost', 'kind' => 'lost'], ['key' => 'won', 'label' => 'Won', 'kind' => 'won'], ['key' => '', 'label' => 'Demo', 'kind' => 'open']],
        Kaleta\Core\Deals::LABEL_MAX]);
// 3.31.1: a stage with deals keeps its kind – saveStages compares the kinds of the stages that stay (kindChanges) and refuses
$a3311Stages = Kaleta\Core\Deals::parseStages('');
$a3311Deals = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Deals.php');
check('3.31.1: kindChanges – the stages that stay with another kind (a removed or a new stage is not one); saveStages refuses them while deals are in them, naming how many', [
    Kaleta\Core\Deals::kindChanges($a3311Stages, $a3311Stages),
    Kaleta\Core\Deals::kindChanges($a3311Stages, [['key' => 'new', 'kind' => 'open'], ['key' => 'proposal', 'kind' => 'lost'], ['key' => 'won', 'kind' => 'open'], ['key' => 'fresh', 'kind' => 'won'], ['key' => 'lost', 'kind' => 'lost']]),
    str_contains($a3311Deals, '$rekinded = self::kindChanges($current, $clean);'),
    strpos($a3311Deals, '$rekinded = self::kindChanges(') < strpos($a3311Deals, "\$app->settings()->set('pipeline_stages'"),
    array_map(fn (string $code): bool => isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['The kind of the stage “%s” cannot change while deals are in it (%s) – move them to another stage first. It can change only when all its deals already fit the new kind (all open, all won or all lost).']), ['cs', 'de', 'es', 'de-du'])],
    [[], ['proposal', 'won'], true, true, [true, true, true, true]]);
// review of 3.31.1: a kind change is allowed when every deal of the stage already fits the new kind – the repair of a stage
// whose kind changed under 3.31.0 – and refused, with the hint, when one does not
$a3311Fit = fn (int $open, int $won, int $lost, string $kind): bool => Kaleta\Core\Deals::dealsFitKind(['n' => $open + $won + $lost, 'open' => $open, 'won' => $won, 'lost' => $lost], $kind);
check('3.31.1 (review): dealsFitKind – open = all deals open, won = all closed without a lost reason, lost = all closed with one; saveStages refuses only a stage whose deals do not fit', [
    $a3311Fit(3, 0, 0, 'open'), $a3311Fit(0, 2, 0, 'won'), $a3311Fit(0, 0, 2, 'lost'), $a3311Fit(0, 0, 0, 'lost'),
    $a3311Fit(2, 1, 0, 'open'), $a3311Fit(0, 2, 1, 'won'), $a3311Fit(0, 1, 1, 'lost'), $a3311Fit(1, 0, 0, 'won'), $a3311Fit(0, 1, 0, 'nonsense'),
    str_contains($a3311Deals, 'if (isset($counts[$key]) && !self::dealsFitKind($counts[$key], $kinds[$key])) {')],
    [true, true, true, true, false, false, false, false, false, true]);
check('3.31 (B): a deal has the team\'s thread and tasks (deal:N, section Pipeline) – personal, never journaled; the timeline shows a deal with an icon and a label; "Deal #N" in My work', [
    Kaleta\Core\Comments::parseTarget('deal:3'), Kaleta\Core\Comments::deal(5), Kaleta\Core\Comments::KINDS['deal'] ?? null, Kaleta\Core\Comments::parseTarget('deal:0'),
    Kaleta\Core\Tasks::cleanTarget('deal:4'), Kaleta\Core\Tasks::personal('deal:4'), Kaleta\Core\Assignment::KINDS['deal'],
    Kaleta\Admin\Modules\Contacts::KINDS['deal'] ?? null, str_contains($b331Icons, "'obchody' =>"),
    array_map(fn (string $code): bool => isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['Deal'], (require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['Open the deal']), ['cs', 'de', 'es']),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/contacts/timeline.php'), "'deal' => 'Open the deal'"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/my_work/list.php'), "'deal' => t('Deal #%s'")],
    [['type' => 'deal', 'id' => 3], 'deal:5', 'comment', null, 'deal:4', true, 'pipeline', ['obchody', 'Deal'], true, [true, true, true], true, true]);
// 3.31.1: without the Pipeline a customer's or company's timeline (admin and MCP get_contact) shows a deal only as "A deal"
$a3311Timeline = [['at' => '2026-11-09 10:00:00', 'kind' => 'deal', 'title' => 'Olga Nováková – yearly contract', 'status' => 'won', 'text' => 'Signed', 'by' => 'Jana',
    'ref' => 'deal:7', 'path' => 'admin.php?module=pipeline&action=detail&id=7', 'contact' => 'Olga', 'contact_id' => 3],
    ['at' => '2026-11-08 10:00:00', 'kind' => 'enquiry', 'title' => 'Contact form', 'text' => 'Website', 'status' => 'new', 'ref' => 'enquiry:4', 'path' => 'admin.php?module=enquiries&action=detail&id=4']];
check('3.31.1: Deals::neutralTimeline – a deal entry keeps when, its number and the contact only (no title, stage, status, who or link); other entries stay; used by the customer, the company and get_contact', [
    Kaleta\Core\Deals::neutralTimeline($a3311Timeline), Kaleta\Core\Deals::neutralTimeline([]),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Contacts.php'), '$this->dealsShown('),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Contacts.php'), "return isset(\$this->kernel->modules()[Pipeline::IDENT]) ? \$timeline : \\Kaleta\\Core\\Deals::neutralTimeline(\$timeline);"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/ContactTools.php'), "if (\$timeline !== [] && !\\Kaleta\\Core\\Deals::mayOpen(\$db, \$this->app->settings(), \$this->app->auth()->id())) {"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/contacts/timeline.php'), "\$label = 'A deal';"),
    array_map(fn (string $code): bool => isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['A deal']), ['cs', 'de', 'es'])],
    [[['at' => '2026-11-09 10:00:00', 'kind' => 'deal', 'ref' => 'deal:7', 'contact' => 'Olga', 'contact_id' => 3, 'title' => ''], $a3311Timeline[1]], [], 2, true, true, true, [true, true, true]]);
$b331Layout = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php');
$b331Script = (string) file_get_contents(KALETA_ROOT . '/image/admin.js');
$b331Board = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/pipeline/board.php');
check('3.31 (B): the palette searches deals only for whoever has the Pipeline; the board script posts the move form as JSON, announces moves and lets a lost column ask for the reason; the boxes on an enquiry, a customer and a company', [
    str_contains($b331Layout, "isset(\$modules['pipeline']) ? ' data-deals=\"'"), str_contains($b331Script, "palette.getAttribute('data-deals')"), str_contains($b331Script, "T('deal')"),
    str_contains($b331Script, "document.querySelector('[data-pipeline-board]')"), str_contains($b331Script, "data.set('format', 'json')"), str_contains($b331Board, 'aria-live="polite" data-pipeline-announce'),
    str_contains($b331Script, "col.getAttribute('data-kind') === 'lost'"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/enquiries/detail.php'), "render('admin/deal_box'"),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/views/admin/contacts/detail.php') . (string) file_get_contents(KALETA_SYSTEM . '/views/admin/contacts/company.php'), "render('admin/pipeline/deals_box'"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/admin.css'), '.obchod-presun:has(select[name="stage"] option[data-kind="lost"]:checked:not([data-current])) .obchod-duvod'),
    array_map(fn (string $code): string => (string) json_decode((string) (preg_match('/^\t"deal": ("[^"]*")/m', (string) file_get_contents(KALETA_ROOT . '/image/jazyky/admin-' . $code . '.js'), $m) ? $m[1] : '""')), ['cs', 'de', 'es'])],
    [true, true, true, true, true, true, true, true, 2, true, ['obchod', 'Deal', 'oportunidad']]);
$b331View = new Kaleta\Core\View([KALETA_SYSTEM . '/views']);
$b331App = new Kaleta\Core\App([]);
$b331Url = new class { /** @param array<string, scalar> $p */ public function url(string $a = '', array $p = []): string { return 'admin.php?' . http_build_query(['module' => 'pipeline'] + ($a !== '' ? ['action' => $a] : []) + $p); } };
$b331Evil = '<script>alert(1)</script>';
$b331Row = ['id' => 7, 'title' => $b331Evil, 'contact_id' => 3, 'contact_name' => $b331Evil, 'contact_email' => 'a@example.com', 'company_id' => 2, 'company_name' => $b331Evil, 'owner_id' => null,
    'stage' => 'lost', 'value' => '10.00', 'currency' => 'EUR', 'expected_close' => '2026-01-01', 'closed_at' => null, 'lost_reason' => 'price', 'lost_note' => $b331Evil, 'created_by' => null,
    'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:00:00', 'source_enquiry_id' => null];
$b331Card = Kaleta\Core\Language::runWith('en', fn (): string => $b331View->render('admin/pipeline/card', ['app' => $b331App, 'module' => $b331Url, 'csrf' => '', 'd' => $b331Row,
    'stages' => Kaleta\Core\Deals::parseStages(''), 'owners' => [], 'return' => 'module=pipeline&x="><b>', 'today' => '2026-11-03', 'canContacts' => true]), 'admin-');
$b331Box = Kaleta\Core\Language::runWith('en', fn (): string => $b331View->render('admin/pipeline/deals_box', ['box' => ['deals' => [$b331Row + ['url' => '/admin.php?module=pipeline&action=detail&id=7']],
    'labels' => ['lost' => $b331Evil], 'kinds' => ['lost' => 'lost'], 'today' => '2026-11-03', 'newUrl' => '/n', 'allUrl' => '/a']]), 'admin-');
check('3.31 (B): the views escape a deal\'s title, customer, company and stage; a card moves without JavaScript (its stage select marks the current one); an overdue card is marked', [
    str_contains($b331Card . $b331Box, '<script>'), str_contains($b331Card, '&lt;script&gt;alert(1)&lt;/script&gt;'), str_contains($b331Card, 'value="module=pipeline&amp;x=&quot;&gt;&lt;b&gt;"'),
    str_contains($b331Card, '<option value="lost" data-kind="lost" selected data-current>'), str_contains($b331Card, 'name="lost_reason"'), str_contains($b331Card, 'obchod-po-terminu'),
    str_contains($b331Box, 'stitek stitek-chyba">&lt;script&gt;')],
    [false, true, true, true, true, true, true]);

// 3.31 merge with 3.30: every target kind (a deal and the console's site and customer alike) parses, is cleaned and swept as a
// task target, opens through an assignment kind, and has its own label in both task lists of My work (Mine and Team)
$m331Kinds = array_keys(Kaleta\Core\Inbox::TARGETS);
$m331Views = array_map(fn (string $v): string => (string) file_get_contents(KALETA_SYSTEM . '/views/admin/my_work/' . $v . '.php'), ['list', 'team']);
$m331Sweep = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Tasks.php');
check('3.31: every target kind parses, is a task target, is swept, has an assignment kind and a label in My work (Mine, Team)', [
    $m331Kinds, array_values(array_filter($m331Kinds, fn (string $k): bool => Kaleta\Core\Inbox::parse($k . ':7') !== [$k, 7] || Kaleta\Core\Tasks::cleanTarget($k . ':7') !== $k . ':7'
        || !isset(Kaleta\Core\Assignment::KINDS[$k]) || !str_contains($m331Sweep, "'" . $k . "' => ['")
        || ($k !== 'item' && array_filter($m331Views, fn (string $v): bool => !str_contains($v, "    '" . $k . "' => t(")) !== []))),
    Kaleta\Core\Assignment::KINDS['deal'], Kaleta\Core\Assignment::ADMIN_SECTIONS],
    [['enquiry', 'page', 'item', 'contact', 'company', 'fleet_site', 'fleet_customer', 'deal', 'application'], [], 'pipeline', ['fleet']]); // 3.34: job applications

// 3.31: comments on the deals of an erased or anonymised customer follow the rule of the comments on the customer itself
$p331Contacts = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Contacts.php');
$p331Deals = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Deals.php');
$p331Data = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/PersonalData.php');
check('3.31: an erased customer\'s deals lose the comments, an anonymised one\'s keep them blanked (Comments::anonymiseTargets); the personal data file carries them', [
    str_contains($p331Contacts, 'Deals::unlinkContacts($db, [$id], true);'), str_contains($p331Contacts, 'Deals::unlinkContacts($db, $chunk);'),
    str_contains($p331Deals, "self::forgetComments(\$db, array_map(fn (array \$r): string => Comments::deal((int) \$r['id']), \$rows), \$erase);"),
    str_contains($p331Deals, "Comments::anonymiseTargets(\$db, \$targets);"), str_contains($p331Deals, "\$db->run('DELETE FROM {comments} WHERE target IN ('"),
    substr_count($p331Data, 'Comments::deal((int) $d[\'id\'])'), (new ReflectionMethod(Kaleta\Core\Deals::class, 'unlinkContacts'))->getParameters()[2]->getDefaultValue()],
    [true, true, true, true, true, 2, false]);

// 3.31 (code scanning #44): the moved-field redirect of Settings goes only to a same-origin http(s) path, never "//host"
$x331Js = (string) file_get_contents(KALETA_ROOT . '/image/admin.js');
check('3.31: the moved-field redirect checks the origin and the protocol and keeps only the path and query', [
    str_contains($x331Js, "movedUrl.origin === location.origin && (movedUrl.protocol === 'https:' || movedUrl.protocol === 'http:')"),
    str_contains($x331Js, "location.replace(movedUrl.pathname.replace(/^\\/+/, '/') + movedUrl.search + '#' + encodeURIComponent(movedId));"),
    str_contains($x331Js, "location.replace(movedTo")], [true, true, false]);

// 3.31 (code scanning #45): the dummy hashes are valid bcrypt hashes of cost 12 and 10, and no bcrypt literal stands in Auth.php
$h331 = new ReflectionClass(Kaleta\Core\Auth::class);
$h331Info = fn (string $name): array => [password_get_info((string) $h331->getConstant($name))['algoName'], password_get_info((string) $h331->getConstant($name))['options']['cost'] ?? 0, strlen((string) $h331->getConstant($name))];
check('3.31: the dummy hashes stay valid bcrypt of cost 12 and 10 (60 characters) and no bcrypt hash literal is left in Auth.php', [
    $h331Info('DUMMY_HASH'), $h331Info('DUMMY_HASH_10'), password_verify('anything', (string) $h331->getConstant('DUMMY_HASH_10')),
    preg_match('/\$2[aby]?\$\d+\$[.\/A-Za-z0-9]{53}/', (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Auth.php'))],
    [['bcrypt', 12, 60], ['bcrypt', 10, 60], false, 0]);

// 3.31: a fleet expiry reminder whose e-mail fails is retried by the mail queue (Mail::send keeps it), never re-sent by the job
$f331 = (string) file_get_contents(KALETA_SYSTEM . '/src/Fleet/Calendar.php');
$f331Send = new ReflectionMethod(Kaleta\Core\Mail::class, 'send');
check('3.31: Calendar::run sends each reminder e-mail with Mail::send and its queue (a failure waits for the job "mail"); the reminder is recorded first', [
    substr_count($f331, 'Mail::send('), str_contains($f331, '$queued += Mail::send($s, $recipient, $subject, $text) ? 0 : 1;'),
    $f331Send->getParameters()[6]->getName(), $f331Send->getParameters()[6]->getDefaultValue(),
    strpos($f331, "INSERT IGNORE INTO {fleet_expiry_reminders}") < strpos($f331, '$queued += Mail::send(')],
    [1, true, 'queueOnFailure', true, true]);

