<?php

declare(strict_types=1);

use Kaleta\Core\MonthlyReport;
use Kaleta\Core\ReportSummary;
use Kaleta\Fleet\Digest;
use Kaleta\Fleet\Report;

/* ---------- 3.23.1: text sweep, honest signature hint, phone fixes (N-C4–N-C7, N-C10–N-C12, N-C15, C4, C18) ---------- */
$v3231 = fn (string $view): string => (string) file_get_contents(KALETA_SYSTEM . '/views/admin/' . $view);
$paths3231 = '(Site care → Broken links) (Statistics → Campaigns) (My account → Claude connection) (My account → My e-mail signature)';
$all3231 = ['broken_links', 'stats'];
check('3.23.1 (C4, N-C5, N-C6): the last plain menu paths are links – in English, Czech, German and Spanish, only with access', [
    substr_count(Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Admin\MenuPaths::links('/admin.php', $paths3231, $all3231), 'admin-'), '<a '),
    Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Admin\MenuPaths::links('/admin.php', t('In the background, one news item every five minutes. The result is in Site care → Broken links.'), $all3231), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): int => substr_count(Kaleta\Admin\MenuPaths::links('/admin.php', t('Connect Claude to this site with a drafts-only token or connection: My account → Claude connection, access “drafts only”. A drafts-only connection can read the site and save drafts, and the two tools the routine needs (get_due_agent_runs, report_agent_run) – it cannot publish, delete or send.'), []), 'action=account#claude'), 'admin-'),
    Kaleta\Core\Language::runWith('de', fn (): int => substr_count(Kaleta\Admin\MenuPaths::links('/admin.php', t('Sent from the site’s address; when the customer answers, it comes to you (%s). You have no e-mail signature yet, so the reply goes without one – see My account → My e-mail signature.', 'a@b.example'), []), 'tab=signature'), 'admin-'),
    Kaleta\Core\Language::runWith('es', fn (): int => substr_count(Kaleta\Admin\MenuPaths::links('/admin.php', t('The first page of a visit, its campaign and the site that sent the visitor go with enquiries and newsletter sign-ups (Statistics → Campaigns). Only for visitors who allow marketing in the cookie bar – turning it on adds that choice to the bar.') . ' ' . t('Link checking is off – switch on “Look for broken links” in Settings → General.'), ['stats', 'settings']), '<a '), 'admin-'),
    Kaleta\Core\Language::runWith('en', fn (): int => substr_count(Kaleta\Admin\MenuPaths::links('/admin.php', $paths3231, []), '<a '), 'admin-'),
], [4, 'Na pozadí, jedna novinka za pět minut. Výsledek je v <a href="/admin.php?module=broken_links">Péče o web → Nefunkční odkazy</a>.', 1, 1, 2, 2]);
// the stale or contradictory texts are gone from the views; the signature tab opens the signature guide
$stale3231 = ['is in News → Broken links', 'Settings → General → More options', 'Statistics → Campaigns and pages', 'News is always at', 'Requests on the console', 'Settings and connections', 'come from the design system', 'come from your design system', 'to the design system,', 'look to the design system'];
$views3231 = implode("\n", array_map(fn (string $f): string => (string) file_get_contents($f), [...glob(KALETA_SYSTEM . '/views/admin/*.php'), ...glob(KALETA_SYSTEM . '/views/admin/*/*.php')]))
    . (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Hubs.php') . (string) file_get_contents(KALETA_SYSTEM . '/src/Core/AgentSchedules.php');
check('[source-pin] 3.23.1 (N-C6, N-C7, N-C11): no stale path or "design system" in owner copy; the signature tab has its guide, the details keep theirs', [
    array_values(array_filter($stale3231, fn (string $x): bool => str_contains($views3231, $x))),
    str_contains($views3231, "'Requests from staff'"),
    Kaleta\Admin\Guide::forScreen('account', '', 'signature', 'en'), Kaleta\Admin\Guide::forScreen('account', '', '', 'cs'),
    Kaleta\Admin\Hubs::class !== '' && str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Hubs.php'), "['claude_settings', '', 'Claude settings']"),
], [[], false, 'https://kaletacms.com/guide/email#your-e-mail-signature', 'https://kaletacms.com/cs/guide/claude-connect', true]);
check('[source-pin] 3.23.1 (N-C5, N-C10, N-C15): the reply form promises a signature only to who has one; one testimonial sentence; the empty Pages filter speaks of languages only with them', [
    str_contains($v3231('enquiries/detail.php'), '$hasSignature =>') && str_contains($v3231('enquiries/detail.php'), 'data-reply-no-signature'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php'), "'hasSignature' => \\Kaleta\\Core\\EnquiryReply::signature("),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/EnquiryReply.php'), 'UserSignature::forUser'), // 3.37: the Team record or the fallback
    str_contains($v3231('enquiries/detail.php'), 'Nothing is published until you publish it.') && !str_contains($v3231('enquiries/detail.php'), 'an administrator creates that collection'),
    str_contains($v3231('pages/list.php'), "(\$siteLanguages ?? []) === [] ? 'Try another tab.' : 'Try another tab or language.'"),
    Kaleta\Core\Language::runWith('cs', fn (): string => t('Try another tab.') . '|' . t('Ask Claude requests'), 'admin-'),
], [true, true, 1, true, true, 'Zkuste jinou záložku.|Požadavky ze Zeptejte se Clauda']);
$css3231 = (string) file_get_contents(KALETA_ROOT . '/image/admin.css');
check('3.23.1 (N-C4, N-C12): status cells wrap on a phone; below 600 px the Settings tabs are a select of the same tabs that opens the chosen one', [
    substr_count($v3231('settings/health.php'), '<table class="vypis vypis-stav">'),
    str_contains($css3231, 'table.vypis-stav th, table.vypis-stav td { padding: 10px 12px; white-space: normal; overflow-wrap: anywhere; }'),
    (bool) preg_match('/<form class="zalozky-vyber" method="get".*?><input type="hidden" name="module" value="settings">.*<select id="zalozka-vyber" name="tab" data-odeslat-pri-zmene>.*foreach \(\$navigation as \$key => \$name\)/s', $v3231('settings/list.php')),
    str_contains($css3231, '.zalozky:has(+ .zalozky-vyber) { display: none; }') && str_contains($css3231, "@media (max-width: 600px) {\n\t.zalozky-vyber { display: flex;"),
], [2, true, true, true]);

// 3.23.1 (N-C1): the ready-made roles' names and descriptions fit the role form (60 and 200 characters) in every admin language
check('3.23.1 (N-C1): ready-made role names and descriptions fit the form in every admin language', array_values(array_filter(array_map(fn (string $code): string => Kaleta\Core\Language::runWith($code,
    fn (): string => implode(',', array_keys(array_filter(Kaleta\Admin\Modules\Roles::PRESETS, fn (array $p): bool => mb_strlen(t($p[0])) > 60 || mb_strlen(t($p[1])) > 200))), 'admin-'), array_keys(Kaleta\Core\Language::ADMIN_LANGUAGES)))), []);
// 3.23.1 (N-C2): the e-mail about a comment on a draft goes out in the recipient's admin language, else in the site's – never
// a fixed Czech; no other caller of Mail::send falls back to a hard-coded 'cs'
$nc2 = fn (array $site, string $language): array => Kaleta\Core\DraftComments::mail($reportSettings($site), ['jazyk' => $language, 'register' => ''], 'Home', 'Hannah', 'Nice', 'https://example.com/b');
check('3.23.1 (N-C2): a user with no language on an English site gets the draft-comment mail in English; a chosen language wins; a Czech site writes Czech', [
    $nc2(['site_language' => 'en'], '')[0], str_starts_with($nc2(['site_language' => 'en'], '')[1], 'Hannah commented on the draft of the page “Home”'),
    $nc2(['site_language' => 'en'], 'de')[0] !== $nc2(['site_language' => 'en'], '')[0], $nc2(['site_language' => 'cs'], '')[0] === $nc2(['site_language' => 'en'], 'cs')[0],
    $nc2(['site_language' => 'cs'], '')[0] !== 'New comment on the draft of “Home”', Kaleta\Core\Language::code(),
], ['New comment on the draft of “Home”', true, true, true, true, Kaleta\Core\Language::code()]);
$nc2Callers = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/src', FilesystemIterator::SKIP_DOTS)) as $nc2File) {
    if (str_ends_with((string) $nc2File, '.php') && preg_match("/runWith\\([^;]*(\\?:|: )\\s*'cs'/", (string) file_get_contents((string) $nc2File))) {
        $nc2Callers[] = basename((string) $nc2File);
    }
}
check('[source-pin] 3.23.1 (N-C2): no Language::runWith(… ?: \'cs\') left; a new user stores the admin language of whoever creates them', [$nc2Callers,
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Users.php'), "\$this->db->insert('uzivatele', \$data + ['jazyk' => \\Kaleta\\Core\\Language::code()])")], [[], true]);
// 3.23.1 (N-C3): one change-log row per action – the generic row of the admin is left out when the module wrote its own, and
// carries the record number of the form when it has one
$nc3 = fn (array $post): int => Kaleta\Admin\Kernel::recordId(new Kaleta\Core\Request([], $post, []));
check('3.23.1 (N-C3): the record of an admin form – id, idp… in that order, positive numbers only', [$nc3(['id' => '7']), $nc3(['idp' => '3', 'titulek' => 'X']), $nc3(['id' => '0', 'idr' => '5']), $nc3(['id' => 'x']), $nc3([])], [7, 3, 5, 0, 0]);
check('[source-pin] 3.23.1 (N-C3): ChangeLog::wrote knows the areas written in this request; the Kernel forgets them before each action and skips its row after one or after a refusal (an error message)', [
    Kaleta\Admin\ChangeLog::wrote('nc3-area'),
    (bool) preg_match('/ChangeLog::forget\(\);.*\$response = \(new \$class\(\$this\)\)->handle.*!ChangeLog::wrote\(\$ident, \.\.\.self::LOGS_AS\[\$ident\] \?\? \[\]\)\s*&& \(ChangeLog::isChangedAnyway\(\) \|\| !in_array\(\'chyba\', array_slice\(\$app->session->flashTypes\(\), \$flashes\), true\)\)/s', (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php')),
], [false, true]);

// N3231-1 (review of 3.23.1): an action that saved although it ends with an error message ("The settings are saved, but …",
// an item saved with fields left empty, a backup whose off-site copy failed) still gets its row – the refusal rule must not hide it
Kaleta\Admin\ChangeLog::forget();
$n32311 = [Kaleta\Admin\ChangeLog::isChangedAnyway()];
Kaleta\Admin\ChangeLog::changedAnyway();
$n32311[] = Kaleta\Admin\ChangeLog::isChangedAnyway();
Kaleta\Admin\ChangeLog::forget();
$n32311[] = Kaleta\Admin\ChangeLog::isChangedAnyway();
check('[source-pin] 3.23.1 review (N3231-1): a partial save marks the change, the Kernel forgets the mark before the next action; every "saved, but" error marks it', [...$n32311,
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Settings.php'), 'ChangeLog::changedAnyway();'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Collections.php'), "ChangeLog::changedAnyway(); // the item is saved"),
], [false, true, false, 5, 1]); // 3.28: a backup whose database copy waits for the recovery key is a fifth "saved, but"

// N3231-2 (review of 3.23.1): a module whose action logs under another area (Business details → the 'settings' rows of Core\Hours,
// Menu and Transfer → 'appearance' of Core\Look, Settings and Collections → 'pages' of the statement and the listing page) writes one row, not two
$n32312 = new ReflectionClassConstant(Kaleta\Admin\Kernel::class, 'LOGS_AS');
Kaleta\Admin\ChangeLog::forget();
$n32312Before = Kaleta\Admin\ChangeLog::wrote('business', 'settings');
(new ReflectionProperty(Kaleta\Admin\ChangeLog::class, 'written'))->setValue(null, ['settings' => true]); // what write() remembers of a 'settings' row
check('[source-pin] 3.23.1 review (N3231-2): the areas a module logs under; wrote() takes several areas', [$n32312->getValue(), $n32312Before,
    Kaleta\Admin\ChangeLog::wrote('business', ...$n32312->getValue()['business']), Kaleta\Admin\ChangeLog::wrote('business'),
    array_values(array_diff(array_keys($n32312->getValue()), array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES))),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Hours.php'), "ChangeLog::write(\$app, 'settings', 'hours_exception_delete'"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Privacy.php'), "ChangeLog::write(\$app, 'pages', 'accessibility_statement'"),
], [['business' => ['settings'], 'menu' => ['appearance'], 'transfer' => ['appearance'], 'settings' => ['pages'], 'collections' => ['pages']], false, true, false, [], true, true]);
Kaleta\Admin\ChangeLog::forget();

// ---- 3.24 "Team-ready website", agent B: responsible people, Mine, the assignment e-mail, review reminders, draft attribution ----
// the assignment e-mail: the recipient's admin language (the site's when none), the form name and a link – never the enquiry's fields
$a324 = fn (string $kind, array $target, string $language, array $site = ['site_language' => 'en']): array => Kaleta\Core\Assignment::mail($reportSettings($site), ['jazyk' => $language, 'register' => ''], $kind, $target, 'Jana', 'https://example.com/admin.php?x');
$a324Enquiry = $a324('enquiry', ['id' => 42, 'title' => 'Contact', 'path' => ''], '');
$a324Page = $a324('page', ['id' => 7, 'title' => 'Services', 'path' => ''], 'cs');
$a324Item = $a324('item', ['id' => 9, 'title' => 'Oak table', 'collection' => 'Products', 'path' => ''], 'de', ['site_language' => 'cs']);
check('3.24 (B): the assignment e-mail – English by the site, Czech and German by the user; the form name, the title, the link; no enquiry content', [
    $a324Enquiry[0], str_starts_with($a324Enquiry[1], 'Jana assigned you the enquiry #42 from the form “Contact”.'), str_ends_with($a324Enquiry[1], "https://example.com/admin.php?x\n"),
    $a324Page[0], str_contains($a324Page[1], 'Mine') || str_contains($a324Page[1], 'Moje'), $a324Item[0], Kaleta\Core\Language::code() === Kaleta\Core\Language::code()],
    ['An enquiry from the form “Contact” is assigned to you', true, true, 'Odpovídáte za stránku „Services“', true, 'Sie sind für „Oak table“ in Products verantwortlich', true]);
$a324App = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor(); // never reaches the database: refused before it
check('3.24 (B): no assignment e-mail to oneself, to nobody, or for an unknown kind', [
    Kaleta\Core\Assignment::notify($a324App, 'enquiry', 1, 5, 5), Kaleta\Core\Assignment::notify($a324App, 'page', 1, 0, 5), Kaleta\Core\Assignment::notify($a324App, 'news', 1, 6, 5),
    array_keys(Kaleta\Core\Assignment::KINDS), array_values(Kaleta\Core\Assignment::KINDS)], [false, false, false, ['enquiry', 'page', 'item', 'contact', 'company', 'fleet_site', 'fleet_customer', 'deal', 'application', 'quote'], ['enquiries', 'pages', 'collections', 'contacts', 'contacts', 'fleet', 'fleet', 'pipeline', 'applications', 'quotes']]); // 3.28: customers and companies; 3.30: the console's sites and customers (tasks only); 3.31: deals; 3.34: job applications; 3.40: quotes (follow-up tasks)
// the review reminder: one e-mail per person and day at most, an item once per review date; people without an owner never
$r324 = [['kind' => 'page', 'id' => 1, 'review_by' => '2026-11-01', 'owner_id' => 3], ['kind' => 'collection_item', 'id' => 1, 'review_by' => '2026-11-02', 'owner_id' => 3],
    ['kind' => 'page', 'id' => 2, 'review_by' => '2026-11-02', 'owner_id' => 4], ['kind' => 'page', 'id' => 5, 'review_by' => '2026-11-02', 'owner_id' => null]];
$r324Keys = fn (array $byOwner): array => array_map(fn (array $rows): array => array_map(fn (array $r): string => $r['kind'] . '|' . $r['id'], $rows), $byOwner);
check('3.24 (B): review reminders – grouped by the responsible person, without what was mailed for that date, without who got one today', [
    $r324Keys(Kaleta\Core\Validity::remindersToSend($r324, [], [])),
    $r324Keys(Kaleta\Core\Validity::remindersToSend($r324, ['page|1|2026-11-01' => true], [])),
    $r324Keys(Kaleta\Core\Validity::remindersToSend($r324, ['page|1|2026-10-01' => true], [4 => true])),
    Kaleta\Core\Validity::OWNED], [[3 => ['page|1', 'collection_item|1'], 4 => ['page|2']], [3 => ['collection_item|1'], 4 => ['page|2']], [3 => ['page|1', 'collection_item|1']], ['page' => 'page', 'collection_item' => 'item']]);
$validity324 = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Validity.php');
check('[source-pin] 3.24 (B): the hourly validity job e-mails the responsible people (the admin language) and records content.review_mailed without the address', [
    str_contains($validity324, '$mailed = $owned !== [] ? self::remindOwners($app, $owned, $today) : 0;'), str_contains($validity324, "Mail::send(\$s, (string) \$user['email'], \$subject, \$body);"),
    str_contains($validity324, "'admin-', Language::normalizeRegister((string) \$user['register'])"), isset(Kaleta\Core\Events::TYPES['content.review_mailed']),
    str_contains($validity324, "['user' => \$owner, 'day' => \$today, 'items' =>")], [true, true, true, true, true]);
// draft attribution (N-U6): "Draft by Jana, 21:05" – the time alone today, the date before; recorded by the builder and by MCP
$d324Today = Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\DraftAttribution::label(['by' => 'Jana', 'at' => date('Y-m-d') . ' 21:05:09']), 'admin-');
$d324Before = Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\DraftAttribution::label(['by' => 'Jana', 'at' => '2026-01-05 08:00:00']), 'admin-');
check('[source-pin] 3.24 (B, N-U6): the draft label, and who records a draft: the page builder\'s saveDraft and MCP saveBuild – never another builder target', [
    $d324Today, str_starts_with($d324Before, 'Draft by Jana, ') && str_ends_with($d324Before, ' 08:00') && $d324Before !== 'Draft by Jana, 08:00',
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Pages.php'), 'DraftAttribution::record('),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php'), 'DraftAttribution::record('),
    substr_count(implode('', array_map(fn (string $m): string => (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/' . $m . '.php'), ['Collections', 'SiteParts', 'Popups', 'Components'])), 'DraftAttribution'),
    Kaleta\Core\DraftAttribution::ACTION, Kaleta\Core\Language::runWith('cs', fn (): string => t('draft edited'), 'admin-')],
    ['Draft by Jana, 21:05', true, 1, 1, 0, 'draft edited', 'úprava konceptu']);
$enquiries324 = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php');
$kernel324 = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php');
check('[source-pin] 3.24 (B): Handled by e-mails the new person only when it changed; one rule for who can be chosen; the dashboard tile of open enquiries assigned to me', [
    str_contains($enquiries324, "if (\$after !== null && \$after !== (\$before === null ? null : (int) \$before)) {"),
    str_contains($enquiries324, "Assignment::notify(\$this->app, 'enquiry', \$idp, \$after, \$this->app->auth()->id());"),
    str_contains($enquiries324, "return \\Kaleta\\Core\\Assignment::candidates(\$this->db, 'enquiry', \$assignee);"),
    str_contains($enquiries324, "WHERE prirazeno = ? AND stav < 2 AND ' . self::NOT_SPAM"),
    str_contains($kernel324, "'Assigned to me' => isset(\$modules['enquiries']) ? [Modules\\Enquiries::countAssigned(\$db, \$this->app->auth()->id()), 'admin.php?module=enquiries&status=moje'] : null,"),
    // N324-3: the My work tile leaves out the enquiries the tiles "Assigned to me" and "New enquiries" count
    str_contains($kernel324, "MyWork::count(\$this->app, \$modules, \$this->app->auth()->id(), ['assigned', 'unassigned'])"),
], [true, true, true, true, true, true]);
$pages324 = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Pages.php');
$collections324 = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Collections.php');
check('[source-pin] 3.24 (B): the responsible person is written only from a form that has the field, through Assignment::fromForm; a copy is the copier\'s only when the original had one; the page export never carries it', [
    substr_count($pages324, "if (array_key_exists('owner_id', \$_POST)) {"), substr_count($collections324, "if (array_key_exists('owner_id', \$_POST)) {"),
    substr_count($pages324 . $collections324, 'Assignment::fromForm('), str_contains($pages324, "\$copy['owner_id'] = \$page['owner_id'] !== null && \$this->app->auth()->id() > 0 ? \$this->app->auth()->id() : null;"),
    str_contains($collections324, "'owner_id' => \$p['owner_id'] !== null && \$this->app->auth()->id() > 0 ? \$this->app->auth()->id() : null];"),
    preg_match('/kaleta-stranka[^\n]*owner/', $pages324), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/SiteImport.php'), 'owner_id'),
    Kaleta\Admin\Modules\Pages::MINE, isset(Kaleta\Admin\Modules\Pages::STATUSES[Kaleta\Admin\Modules\Pages::MINE])], [1, 1, 3, true, true, 0, false, 'mine', false]);
check('3.24 (B): the new admin texts are translated in every admin language', array_values(array_filter(array_map(fn (string $code): string => Kaleta\Core\Language::runWith($code, fn (): string =>
    implode(',', array_filter(['Responsible person', '(former user)', 'Assigned to me', 'Draft by %s, %s', 'draft edited', 'Save the responsible person', 'Review by today: “%s”'], fn (string $k): bool => t($k) === $k)), 'admin-'),
    array_diff(array_keys(Kaleta\Core\Language::ADMIN_LANGUAGES), ['en'])))), []);
// 3.24 (T1): the page cache stays while the team works – every admin section and every MCP write tool is classified as
// changing the site (its POST or call clears the cache) or site-neutral (it keeps it); a new one without a class fails here
$t324Idents = array_map(fn (string $class): string => $class::IDENT, Kaleta\Admin\Kernel::MODULES);
$t324Changing = Kaleta\Admin\Kernel::SITE_CHANGING_MODULES;
$t324Neutral = Kaleta\Admin\Kernel::SITE_NEUTRAL_MODULES;
$t324Classes = array_combine($t324Idents, Kaleta\Admin\Kernel::MODULES);
$t324MissingActions = [];
foreach (Kaleta\Admin\Kernel::SITE_NEUTRAL_ACTIONS as $t324Module => $t324Actions) {
    foreach ($t324Actions as $t324Action) {
        if ($t324Module !== '' && !method_exists($t324Classes[$t324Module] ?? Kaleta\Admin\Module::class, 'action' . str_replace('_', '', ucwords($t324Action, '_')))) {
            $t324MissingActions[] = $t324Module . ':' . $t324Action;
        }
    }
}
check('3.24 T1: every admin section is classified once – changing the site or site-neutral; no unknown section in either list; the site-neutral actions are real actions of a site-changing section', [
    array_values(array_diff($t324Idents, $t324Changing, $t324Neutral)), array_values(array_intersect($t324Changing, $t324Neutral)),
    array_values(array_diff([...$t324Changing, ...$t324Neutral], $t324Idents)), count($t324Changing) + count($t324Neutral) === count($t324Idents),
    array_values(array_diff(array_keys(Kaleta\Admin\Kernel::SITE_NEUTRAL_ACTIONS), ['', ...$t324Changing])), $t324MissingActions,
    // a section that may put waiting files on the site changes it – except Newsletters, whose images go out in an e-mail
    array_values(array_diff(Kaleta\Admin\Kernel::PUBLISHING_MODULES, $t324Changing)),
    array_values(array_diff(Kaleta\Admin\Kernel::SITE_NEUTRAL_TABS, array_keys(Kaleta\Admin\Modules\Settings::TABS))),
], [[], [], [], true, [], [], ['newsletters'], []]);
check('3.24 T1: the site-neutral admin sections – the team\'s work that never reaches the site',
    $t324Neutral, ['my_work', 'enquiries', 'subscribers', 'stats', 'requests', 'schedules', 'notebook', 'audit', 'broken_links', 'roles', 'newsletters', 'fleet', 'contacts', 'pipeline', 'applications', 'quotes']); // 3.28: Customers; 3.31: Pipeline; 3.34: Applications; 3.38: Quotes
$t324Post = fn (string $module, string $action, array $post = []): bool => Kaleta\Admin\Kernel::clearsPageCache(new Kaleta\Core\Request(['module' => $module, 'action' => $action], $post, ['REQUEST_METHOD' => 'POST']));
check('3.24 T1: Kernel::clearsPageCache – enquiries, notes, requests, the notebook, roles and drafts keep the cache; content, the look, Settings tabs visitors see, a new signing key, updates, the author box and anything unknown clear it', [
    $t324Post('enquiries', 'note'), $t324Post('enquiries', 'reply'), $t324Post('enquiries', 'triage'), $t324Post('enquiries', 'bulk'), $t324Post('requests', 'save'),
    $t324Post('notebook', 'save'), $t324Post('roles', 'save'), $t324Post('subscribers', 'delete'), $t324Post('pages', 'build_save'), $t324Post('pages', 'draft'),
    $t324Post('pages', 'build_comment_resolve'), $t324Post('users', 'reactivate'), $t324Post('bookings', 'anonymise'), $t324Post('settings', 'save', ['tab' => 'mail']),
    $t324Post('status', 'save'), $t324Post('claude_settings', 'save'), $t324Post('', 'account', ['co' => 'heslo']), $t324Post('', 'logout'),
    $t324Post('pages', 'save'), $t324Post('pages', 'build_publish'), $t324Post('news', 'save'), $t324Post('collections', 'save_item'), $t324Post('menu', 'save'),
    $t324Post('appearance', 'publish_look'), $t324Post('parts', 'build_publish'), $t324Post('popups', 'save'), $t324Post('components', 'save'), $t324Post('media', 'save'),
    $t324Post('settings', 'save', ['tab' => 'general']), $t324Post('settings', 'save', ['tab' => 'cookies']), $t324Post('settings', 'save'), $t324Post('settings', 'new_signing_key'),
    $t324Post('settings', 'time_zone'), $t324Post('status', 'update'), $t324Post('claude_settings', 'restore_backup'), $t324Post('users', 'save'), $t324Post('users', 'delete'),
    $t324Post('bookings', 'confirm'), $t324Post('whistleblowing', 'footer_link'), $t324Post('redirects', 'save'), $t324Post('', 'account', ['co' => 'profil']),
    $t324Post('addons', 'page'), $t324Post('my_unknown_section', 'save'), $t324Post('', 'unknown'),
    $t324Post('settings', 'cookie_scan'), // N324-1: the scanned cookies are rows of {{cookie_table}} on the cookie policy page
], [false, false, false, false, false, false, false, false, false, false, false, false, false, false, false, false, false, false,
    true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true, true]);
// MCP: the write tools that change the site, listed here so a new write tool must be added to this list or to Catalog::SITE_NEUTRAL
$t324Writes = array_keys(array_filter(Kaleta\Mcp\Catalog::TOOLS, fn (array $t): bool => $t[0] !== 'read'));
$t324ChangingTools = ['create_page', 'update_page', 'trash_page', 'save_menu', 'restore_from_trash', 'edit_build', 'build_from_html', 'save_build', 'insert_section',
    'publish_build', 'restore_build_version', 'discard_draft', 'save_component', 'delete_component', 'save_part_variant', 'apply_part_template', 'save_classes',
    'update_design_system', 'publish_look', 'discard_look', 'restore_look_version', 'create_collection', 'update_collection', 'delete_collection', 'save_collection_item',
    'save_collection_items', 'delete_collection_item', 'restore_item_version', 'save_collection_category', 'delete_collection_category', 'create_news', 'update_news',
    'trash_news', 'create_category', 'update_category', 'delete_category', 'upload_file', 'import_website', 'update_media', 'delete_media', 'erase_personal_data',
    'save_popup', 'delete_popup', 'update_settings', 'save_redirect', 'save_redirects', 'undo_agent_session', 'import_wordpress', 'save_fact', 'delete_fact',
    'apply_blueprint', 'remove_blueprint', 'save_hours_exception', 'delete_hours_exception', 'save_booking_service', 'save_booking_staff', 'cancel_booking',
    'confirm_booking', 'decline_booking', 'propose_booking_times'];
check('3.24 T1: every MCP write tool is classified once – it changes the site (clears the page cache) or is in Catalog::SITE_NEUTRAL; SITE_NEUTRAL holds only write tools', [
    array_values(array_diff($t324Writes, $t324ChangingTools, Kaleta\Mcp\Catalog::SITE_NEUTRAL)), array_values(array_intersect($t324ChangingTools, Kaleta\Mcp\Catalog::SITE_NEUTRAL)),
    array_values(array_diff(Kaleta\Mcp\Catalog::SITE_NEUTRAL, $t324Writes)), array_values(array_diff($t324ChangingTools, $t324Writes)),
], [[], [], [], []]);
check('[source-pin] 3.24 T1: Catalog::isSiteNeutral – by the English or the Czech name; content tools, an unknown tool and the meta runners are not', [
    Kaleta\Mcp\Catalog::isSiteNeutral('update_enquiry'), Kaleta\Mcp\Catalog::isSiteNeutral('write_notebook'), Kaleta\Mcp\Catalog::isSiteNeutral('update_request'),
    Kaleta\Mcp\Catalog::isSiteNeutral((string) Kaleta\Mcp\Translator::czech('resolve_draft_comment')), Kaleta\Mcp\Catalog::isSiteNeutral('save_build'),
    Kaleta\Mcp\Catalog::isSiteNeutral((string) Kaleta\Mcp\Translator::czech('save_build')), Kaleta\Mcp\Catalog::isSiteNeutral('confirm_booking'),
    Kaleta\Mcp\Catalog::isSiteNeutral('ext_shop_save'), Kaleta\Mcp\Catalog::isSiteNeutral('run_tool'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php'), "if (!Catalog::isSiteNeutral(\$tool)) {\n                    \\Kaleta\\Front\\Cache::clear();"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php'), '$app->auth()->user() !== null && self::clearsPageCache($request)'),
], [true, true, true, true, false, false, false, false, false, true, true]);


// 3.24: My work, handing work over and assign – core, for every signed-in user, never an access limit
$w324Idents = array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES);
$w324Db = new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''); // never connects: "me" and "none" ask nothing
check('3.24: My work is a core module for every signed-in user – no role section, no default right, a guide link; its lists and the hand-over kinds name real sections', [
    Kaleta\Admin\Modules\MyWork::FOR_ALL_USERS, Kaleta\Admin\Modules\MyWork::EXTENSION, isset(Kaleta\Admin\Modules\Roles::configurable()['my_work']),
    in_array('my_work', Kaleta\Admin\Modules\Users::defaultModules(Kaleta\Core\Auth::EDITOR), true), isset(Kaleta\Admin\Guide::MODULES['my_work']),
    array_values(array_diff(array_values(Kaleta\Core\MyWork::LISTS), $w324Idents)), array_values(array_diff(array_column(Kaleta\Core\Handover::KINDS, 0), $w324Idents)),
    Kaleta\Core\Handover::KINDS['enquiry'][1] === Kaleta\Admin\Modules\Enquiries::EXTENSION, Kaleta\Admin\Kernel::MODULES[0],
], [true, '', false, false, true, [], [], true, Kaleta\Admin\Modules\MyWork::class]);
check('3.24: my_work (read) and assign (write, idempotent, inside the site) – English on both sides of the Translator, never in the essentials', [
    Kaleta\Mcp\Catalog::TOOLS['my_work'] ?? null, Kaleta\Mcp\Catalog::TOOLS['assign'] ?? null, Kaleta\Mcp\Translator::czech('my_work'), Kaleta\Mcp\Translator::czech('assign'),
    array_values(array_intersect(['my_work', 'assign'], Kaleta\Mcp\Catalog::ESSENTIALS)), Kaleta\Mcp\Catalog::isIdempotent('assign'), Kaleta\Mcp\Catalog::isOpenWorld('assign'),
    Kaleta\Mcp\Tools::annotations('my_work')['readOnlyHint'], Kaleta\Mcp\Catalog::allows('drafts', 'assign'),
    array_keys(array_column(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), 'inputSchema', 'name')['update_enquiry']['properties'] ?? []) === array_keys(array_column(Kaleta\Mcp\Tools::definitions(), 'inputSchema', 'name')['update_enquiry']['properties']),
    isset(array_column(Kaleta\Mcp\Tools::definitions(), 'inputSchema', 'name')['update_enquiry']['properties']['assignee']),
], [['read', ''], ['write', ''], 'my_work', 'assign', [], true, false, true, false, true, true]);
check('3.24: Handover – counted labels (no plural forms), the sections a recipient needs, "me" and "none" without a lookup', [
    Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Handover::describe(['page' => 2, 'item' => 0, 'enquiry' => 1]), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => Kaleta\Core\Handover::describe(['page' => 1, 'item' => 3, 'enquiry' => 0]), 'admin-'),
    Kaleta\Core\Handover::describe(['page' => 0, 'item' => 0, 'enquiry' => 0]), Kaleta\Core\Handover::sectionsOf(['page' => 2, 'item' => 0, 'enquiry' => 1]),
    Kaleta\Core\Handover::resolve($w324Db, 'none', 7), Kaleta\Core\Handover::resolve($w324Db, '', 7), Kaleta\Core\Handover::resolve($w324Db, 'Me', 7),
    (function () use ($w324Db): string { try { Kaleta\Core\Handover::resolve($w324Db, ['x'], 7); return 'none'; } catch (\Throwable $e) { return 'refused'; } })(),
], ['pages: 2, open enquiries: 1', 'stránky: 1, položky kolekcí: 3', '', ['pages', 'enquiries'], null, null, 7, 'none']);
check('[source-pin] 3.24: MCP assign and update_enquiry send the assignment e-mail through Handover::notify → Assignment::notify (one rule for who may be assigned) and the menu puts My work first only for non-administrators', [
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Handover.php'), 'Assignment::notify($app, $kind, $id, $userId, $app->auth()->id());')
    + substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Handover.php'), 'Assignment::allowed($db, $kind, $userId)') - 1,
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php'), '$myWorkFirst = !$app->auth()->isAdmin();'),
], [1, true]);

// N324-2: the background jobs after an admin answer are Kaleta's own work – the request forgets the signed-in person first
$n3242Auth = (new ReflectionClass(Kaleta\Core\Auth::class))->newInstanceWithoutConstructor();
(fn () => $this->user = ['idu' => 7, 'admin' => Kaleta\Core\Auth::ADMIN, 'jmeno' => 'Jana'])->call($n3242Auth);
$n3242Auth->useConnection('Claude', 'full');
$n3242Before = [$n3242Auth->id(), $n3242Auth->isAdmin()];
$n3242Auth->forgetForBackground();
$n3242Notifications = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Notifications.php');
check('[source-pin] N324-2: Auth::forgetForBackground – nobody is signed in for the rest of the request (no connection either); afterAdminResponse calls it before the jobs', [
    $n3242Before, $n3242Auth->user(), $n3242Auth->id(), $n3242Auth->isAdmin(), $n3242Auth->connection(),
    strpos($n3242Notifications, '$app->auth()->forgetForBackground();') !== false && strpos($n3242Notifications, '$app->auth()->forgetForBackground();') < strpos($n3242Notifications, 'fn () => self::runInBackground($app)'),
], [[7, true], null, 0, false, null, true]);

// N324-5: the menu badge, the "Assigned to me" tile and My work count the same enquiries – open, spam left out
$n3245 = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/MyWork.php');
check('[source-pin] N324-5: My work and its menu badge leave spam out, as the Enquiries list and the dashboard tile do', [
    str_contains($n3245, "' AND ' . \\Kaleta\\Admin\\Modules\\Enquiries::NOT_SPAM"), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/MyWork.php'), 'return $count + Enquiries::countAssigned($app->db(), $app->auth()->id());'),
], [true, true]);

// 3.25 (A): internal notes as a history (Core\Comments), the conversation of an enquiry (Core\EnquiryThread), the reply
// quoting the customer's message and the default subject (CPO #7)
$a325Data = [['Jméno', 'Hana Nová'], ['E-mail', 'hana@example.cz'], ['Telefon', '+420 777 000 111'], ['Zpráva', "Dobrý den,\nkolik stojí okna do kuchyně?"], ['Příloha', 'plan.pdf', '2026/11/0123456789abcdef01234567.pdf']];
$a325Enquiry = ['idp' => 5, 'datum' => '2026-11-02 09:00:00', 'email' => 'hana@example.cz', 'formular' => 'Kontakt', 'tema' => '', 'data' => json_encode($a325Data, JSON_UNESCAPED_UNICODE)];
$a325Thread = Kaleta\Core\EnquiryThread::entries($a325Enquiry,
    [['at' => '2026-11-02 11:00:00', 'by' => 'Alex', 'text' => 'Reply'], ['at' => '2026-11-02 09:00:00', 'by' => 'Alex', 'text' => 'Same minute']],
    [['at' => '2026-11-02 10:00:00', 'by' => 'Jana', 'text' => 'Call her'], ['at' => '2026-11-03 08:00:00', 'by' => 'Jana', 'text' => 'Done']]);
$a325Quote = Kaleta\Core\EnquiryReply::quote($a325Enquiry);
check('3.25 (A): the customer\'s message is the longest sentence-like field (not an attachment, the address or one word); the thread is in the order of time with the customer first at the same time', [
    Kaleta\Core\EnquiryThread::message($a325Data, 'hana@example.cz'), Kaleta\Core\EnquiryThread::message([['Jméno', 'Jan'], ['Telefon', '777000111'], ['E-mail', 'a.very.long.address@example.com']], 'x@example.com'),
    Kaleta\Core\EnquiryThread::message([['Zpráva', 'Call me back please']]), Kaleta\Core\EnquiryThread::message(['x', ['a'], ['b', 7]]),
    array_map(fn (array $e): string => $e['type'] . ':' . ($e['entry']['text'] ?? ''), $a325Thread),
], ["Dobrý den,\nkolik stojí okna do kuchyně?", '', 'Call me back please', '',
    ["customer:Dobrý den,\nkolik stojí okna do kuchyně?", 'reply:Same minute', 'note:Call her', 'reply:Reply', 'note:Done']]);
check('[source-pin] 3.25 (A, CPO #7): the default subject is Re: the topic, else the first 60 characters of the message, else the form; the quote is "> " lines under a line naming who wrote', [
    Kaleta\Core\EnquiryReply::subject(['tema' => "Okna –\n kuchyň", 'formular' => 'Kontakt'] + $a325Enquiry),
    Kaleta\Core\EnquiryReply::subject($a325Enquiry),
    Kaleta\Core\EnquiryReply::subject(['data' => json_encode([['Zpráva', str_repeat('slovo ', 20)]])] + $a325Enquiry),
    Kaleta\Core\EnquiryReply::subject(['data' => '[]', 'formular' => 'Kontakt'] + $a325Enquiry),
    array_slice(explode("\n", $a325Quote), 1), str_contains(explode("\n", $a325Quote)[0], 'hana@example.cz'),
    Kaleta\Core\EnquiryReply::quote(['data' => '[["Telefon","777"]]'] + $a325Enquiry),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/EnquiryReply.php'), "\$plain = \$text . (\$signature !== null && \$signature['text'] !== '' ? \"\\n\\n-- \\n\" . \$signature['text'] : '') . (\$quote !== '' ? \"\\n\\n\" . \$quote : '');"),
], ['Re: Okna – kuchyň', 'Re: Dobrý den, kolik stojí okna do kuchyně?', 'Re: ' . rtrim(mb_substr(trim(str_repeat('slovo ', 20)), 0, 60)) . '…', 'Re: Kontakt',
    ['> Dobrý den,', '> kolik stojí okna do kuchyně?'], true, '', true]);
check('[source-pin] 3.25 (A): a note is plain text kept as written (control characters out, line breaks kept, capped); enquiry notes are personal data never journaled, kept on every delete path; Enquiries sits in the Workspace group', [
    Kaleta\Core\Comments::clean("  a\r\nb\x07\tc  "), Kaleta\Core\Comments::clean(" \n "), mb_strlen(Kaleta\Core\Comments::clean(str_repeat('é', 6000))), Kaleta\Core\Comments::enquiry(12),
    in_array('comments', Kaleta\Core\AgentJournal::PERSONAL_TABLES, true), Kaleta\Core\AgentJournal::journaled('comments'),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php'), 'Comments::forgetEnquiries('),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Jobs.php'), 'Comments::forgetEnquiries('), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/PersonalData.php'), 'Comments::forgetEnquiries('),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/EnquiryAndPopupTools.php'), 'Comments::forgetEnquiries($db, [$id]);'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Privacy.php'), "Comments::anonymiseEnquiries(\$db, array_map('intval', array_column(\$rows, 'idp')));"),
    Kaleta\Admin\Modules\Enquiries::GROUP,
], ["a\nb\tc", '', 5000, 'enquiry:12', true, false, 3, true, true, true, true, 'Workspace']);

// 3.25 (C21, U17): the builder's draft history next to the published versions, and the per-section text compare
$c325Section = fn (string $id, string $heading, string $text, array $style = []): array => ['id' => $id, 'typ' => 'sekce', 'znacka' => 'section', 'obsah' => [], 'styl' => $style, 'deti' => [
    ['id' => $id . 'h', 'typ' => 'nadpis', 'znacka' => 'h2', 'obsah' => ['text' => $heading]], ['id' => $id . 't', 'typ' => 'text', 'znacka' => 'div', 'obsah' => ['html' => '<p>' . $text . '</p>']]]];
$c325Old = json_encode(['v' => 1, 'deti' => [$c325Section('a1', 'Prices', 'From 100 EUR a month.'), $c325Section('b2', 'Team', 'Five people.'), $c325Section('c3', 'Old offer', 'Gone soon.'), $c325Section('d4', 'Contact', 'Call us.')]]);
$c325New = json_encode(['v' => 1, 'deti' => [$c325Section('a1', 'Prices', 'From 120 EUR a month.'), $c325Section('b2', 'Team', 'Five people.'), $c325Section('e5', 'Reviews', 'Clients love it.'),
    $c325Section('d4', 'Contact', 'Call us.', ['zaklad' => ['pozadi' => 'primarni']])]]);
$c325 = Kaleta\Builder\DraftHistory::compare($c325Old, $c325New);
check('3.25 (C21): compare per section – matched by element id, changed text through Core\Diff, added, removed, unchanged and layout-only sections', [
    array_map(fn (array $s): string => $s['nazev'] . ':' . $s['stav'], $c325['sekce']),
    str_contains($c325['sekce'][0]['html'], '<del>100 </del>') && str_contains($c325['sekce'][0]['html'], '<ins>120 </ins>'),
    str_contains($c325['sekce'][2]['html'], '<ins>'), str_contains($c325['sekce'][4]['html'], '<del>'), $c325['sekce'][1]['html'], $c325['pridano'] > 0 && $c325['smazano'] > 0,
    Kaleta\Builder\DraftHistory::compare($c325Old, $c325Old)['pridano'], Kaleta\Builder\DraftHistory::compare(null, null)['sekce'],
    str_contains(Kaleta\Builder\DraftHistory::compare(json_encode(['v' => 1, 'deti' => [$c325Section('x', '<b>X</b>', 'a <script>b</script>')]]), null)['sekce'][0]['html'], '<script>'),
], [['Prices:changed', 'Team:same', 'Reviews:added', 'Contact:layout', 'Old offer:removed'], true, true, true, '', true, 0, [], false]);
$c325Builder = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/BuilderActions.php');
$c325Tools = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Tools.php');
check('[source-pin] 3.25 (U17): every builder and MCP draft save records the draft history; restores keep the replaced draft; published versions leave the draft rows out', [
    substr_count($c325Builder, 'DraftHistory::record('), str_contains($c325Builder, "DraftHistory::record(\$this->app, \$target['revize'], \$build, \$target['koncept'] ?? \$target['stavba'], true);"),
    substr_count($c325Tools, 'DraftHistory::record('), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Builder/Publisher.php'), "['ids = ? AND cast IS NULL'"),
    Kaleta\Builder\DraftHistory::KEPT, Kaleta\Builder\DraftHistory::when(Kaleta\Core\Clock::now()) === t('today') . ' ' . substr(Kaleta\Core\Clock::now(), 11, 5),
    Kaleta\Builder\DraftHistory::listAll($w324Db, ['cast' => str_repeat('x', 80)]), Kaleta\Builder\DraftHistory::load($w324Db, ['ids' => 0], 5),
], [2, true, 1, true, 10, true, [], null]);

// 3.25 (C): the left menu – Dashboard, My work, then the groups Content, Workspace, Appearance, Claude, Site care,
// Administration; each group contiguous and at most 7 items for an administrator with every feature on
$c325All = [];
foreach (Kaleta\Admin\Kernel::MODULES as $c325Class) {
    $c325All[$c325Class::IDENT] = $c325Class;
}
$c325Runs = [];
foreach (Kaleta\Admin\MenuGroups::groups($c325All) as $c325Run) {
    $c325Name = $c325Run['group'];
    $c325Last = array_key_last($c325Runs);
    if ($c325Last !== null && $c325Runs[$c325Last][0] === $c325Name) {
        $c325Runs[$c325Last][1] = [...$c325Runs[$c325Last][1], ...$c325Run['items']];
    } else {
        $c325Runs[] = [$c325Name, $c325Run['items']];
    }
}
$c325Names = array_column($c325Runs, 0);
check('3.25 (C): the menu groups of an administrator with every feature on – contiguous, in the order Content, Workspace, Appearance, Claude, Site care, Administration', [
    $c325Names, count($c325Names) === count(array_unique($c325Names)), max(array_map(fn (array $r): int => count($r[1]), $c325Runs)) <= Kaleta\Admin\MenuGroups::MAX_ITEMS,
    array_values(array_diff(array_unique(array_map(fn (string $c): string => $c::GROUP, array_slice(Kaleta\Admin\Kernel::MODULES, 1))), Kaleta\Admin\MenuGroups::ORDER)),
], [Kaleta\Admin\MenuGroups::ORDER, true, true, []]);
check('3.25 (C): the items of each group (Bookings in Workspace, Statistics in Site care, Business details and the Newsletter hub in Content; no Company or Customers group)', array_combine($c325Names, array_column($c325Runs, 1)), [
    'Content' => ['pages', 'news', 'collections', 'media', 'newsletters', 'business'],
    'Workspace' => ['enquiries', 'contacts', 'pipeline', 'quotes', 'applications', 'bookings'], // 3.28: Customers right after Enquiries; 3.31: the Pipeline right after Customers; 3.34: Applications; 3.38: Quotes after the Pipeline
    'Appearance' => ['appearance', 'parts', 'menu', 'components', 'popups'],
    'Claude' => ['requests', 'claude_settings'],
    'Site care' => ['audit', 'stats', 'redirects', 'broken_links', 'changelog', 'status'],
    'Administration' => ['users', 'settings', 'connectors', 'extensions', 'transfer', 'whistleblowing', 'fleet'],
]);
check('3.25 (C): the Newsletter hub (Newsletters · Subscribers, URLs unchanged), Subscribers its own item only without Newsletters; flat list for 8 items or fewer', [
    Kaleta\Admin\Hubs::TABS['newsletter'] ?? null, Kaleta\Admin\Modules\Subscribers::PARENT, Kaleta\Admin\Modules\Subscribers::HUB, Kaleta\Admin\Modules\Newsletters::HUB, Kaleta\Admin\Modules\Newsletters::NAME,
    array_column(Kaleta\Admin\MenuGroups::groups(['subscribers' => Kaleta\Admin\Modules\Subscribers::class, 'stats' => Kaleta\Admin\Modules\Stats::class]), 'items'),
    Kaleta\Admin\MenuGroups::count($c325All), Kaleta\Admin\MenuGroups::flat($c325All),
    Kaleta\Admin\MenuGroups::flat(array_intersect_key($c325All, array_flip(['my_work', 'pages', 'news', 'categories', 'tags', 'media', 'requests', 'status']))),
    Kaleta\Admin\MenuGroups::flat(array_intersect_key($c325All, array_flip(['my_work', 'pages', 'news', 'collections', 'media', 'business', 'enquiries', 'parts']))),
    Kaleta\Admin\MenuGroups::key('Site care'),
], [[['newsletters', '', 'Newsletters'], ['subscribers', '', 'Subscribers']], 'newsletters', 'newsletter', 'newsletter', 'Newsletter', [['subscribers'], ['stats']],
    34, false, true, false, 'site-care']); // 3.28: Customers; 3.31: Pipeline; 3.34: Applications; 3.38: Quotes
$c325Layout = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php');
$c325Script = (string) file_get_contents(KALETA_ROOT . '/image/admin-menu.js');
check('[source-pin] 3.25 (C): collapsible groups – details/summary with the current group marked, the script before the first paint, storage only inside try/catch, "More…" after 5 collections', [
    str_contains($c325Layout, '<details class="menu-skupiny" data-menu-skupina="'), str_contains($c325Layout, "' data-aktualni' : ''"), str_contains($c325Layout, '<summary class="menu-skupina">'),
    (bool) preg_match('#<script src="<\?= e\(\$app->url\(\'image/admin-menu\.js\'\)\) \?>\?v=<\?= e\(KALETA_VERSION\) \?>"( data-menu-prihlaseni="[^"]*")?></script>#', $c325Layout), str_contains($c325Layout, "e(t('More…'))"),
    substr_count($c325Script, 'localStorage.'), substr_count($c325Script, 'try {'), str_contains($c325Script, "hasAttribute('data-aktualni')"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/admin.css'), '.menu-skupiny[open] .menu-skupina-pocet { display: none; }'),
], [true, true, true, true, true, 4, 2, true, true]); // 3.29.1: one more – a new sign-in resets the remembered groups
$c325Paths = (new ReflectionClassConstant(Kaleta\Admin\MenuPaths::class, 'PATHS'))->getValue();
$c325Wrong = [];
foreach ($c325Paths as [$parts, $module]) {
    if (in_array($parts[0], ['Customers', 'Company'], true)) {
        $c325Wrong[] = implode(' → ', $parts);
    } elseif (in_array($parts[0], Kaleta\Admin\MenuGroups::ORDER, true) && count($parts) > 1 && !in_array($parts[0], ['Content', 'Settings'], true)
        && (!isset($c325All[$module]) || $c325All[$module]::GROUP !== $parts[0] || $c325All[$module]::NAME !== $parts[1])) {
        $c325Wrong[] = implode(' → ', $parts);
    }
}
check('[source-pin] 3.25 (C): menu paths that name a group name the group the item is in now; nothing points to Customers or Company; the Booking hint and the newsletter prompt follow the moves', [
    $c325Wrong, str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Builder/Elements/Booking.php'), "in Workspace → Bookings; the form appears here.')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Prompts.php'), 'Content → Newsletter.'),
], [[], true, true]);
check('3.25 (C): the group label Workspace in the dictionaries (cs Pracoviště), More… too', array_map(fn (string $code): array => array_intersect_key(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php', ['Workspace' => 1, 'More…' => 1]), ['cs', 'de', 'es']), [
    ['Workspace' => 'Pracoviště', 'More…' => 'Další…'], ['Workspace' => 'Arbeitsbereich', 'More…' => 'Mehr…'], ['Workspace' => 'Espacio de trabajo', 'More…' => 'Más…'],
]);

$c325Editor = (string) file_get_contents(KALETA_ROOT . '/image/editor.js');
$c325Builder = (string) file_get_contents(KALETA_ROOT . '/image/stavitel.js');
check('[source-pin] 3.25 (N-U9): one link picker – the Text dialog and every [data-odkaz-vyber] field (builder link fields, menu editor) share linkPicker; the old datalist is gone', [
    substr_count($c325Editor, 'linkPicker('), str_contains($c325Editor, "e.target.hasAttribute('data-odkaz-vyber')"), str_contains($c325Editor, "list.setAttribute('popover', 'manual')"),
    str_contains($c325Builder, "'data-odkaz-vyber': def.typ === 'odkaz'"), str_contains($c325Builder, 'st-dl-odkazy'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/menu.js'), "'data-odkaz-vyber': ''"),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/editor.css'), '.odkaz-plovouci {'),
], [3, true, true, true, false, true, true]);

// N325-2: the link picker offers the site's addresses with the installation folder (App::url) – the menu editor stores a
// custom link without it (Core\Menu::items adds it) and a form's thank-you page does not get it twice
check('[source-pin] N325-2: picked addresses in a site installed in a folder – the menu editor strips the folder, the thank-you redirect adds it only once', [
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/menu.js'), 'p.url = withoutBase(e.target.value.trim());'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Front/Forms.php'), "(\$base === '' || !str_starts_with(\$thankYouUrl, \$base . '/')) ? \$base . \$thankYouUrl : \$thankYouUrl;"),
], [true, true]);

check('[source-pin] N325-3: the floating link picker of a field the builder redrew away takes its list and scroll listener with it', [
    str_contains($c325Editor = (string) file_get_contents(KALETA_ROOT . '/image/editor.js'), "if (f.input.isConnected) { return true; }"), str_contains($c325Editor, "window.removeEventListener('scroll', f.onScroll, true);"),
    str_contains($c325Editor, 'floating.push({ input: input, list: list, onScroll: onScroll });'),
], [true, true, true]);

// ---- 3.26 (agent B): notifications – Core\Inbox, the bell, the Notifications screen, the e-mail choice ----
$i326 = Kaleta\Core\Inbox::class;
$i326App = (new ReflectionClass(Kaleta\Core\App::class))->newInstanceWithoutConstructor(); // never reaches the database: refused before it
$i326Db = (new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor();
check('3.26 Inbox: the contract – notify(App, int, string, string, ?int, string = \'\'): void; nothing for the actor themself, nobody or an unknown kind (before the database)', [
    implode(', ', array_map(fn (ReflectionParameter $p): string => ($p->getType()?->__toString() ?? '') . ' $' . $p->getName() . ($p->isOptional() ? ' = ' . var_export($p->getDefaultValue(), true) : ''), (new ReflectionMethod($i326, 'notify'))->getParameters())),
    (string) (new ReflectionMethod($i326, 'notify'))->getReturnType(), (new ReflectionMethod($i326, 'notify'))->isStatic(),
    Kaleta\Core\Inbox::record($i326Db, 5, 'mention', 'page:1', 5), Kaleta\Core\Inbox::record($i326Db, 0, 'mention', 'page:1', 5), Kaleta\Core\Inbox::record($i326Db, 6, 'like', 'page:1', 5),
    Kaleta\Core\Inbox::KINDS, Kaleta\Core\Inbox::EMAIL, Kaleta\Core\Inbox::KEEP_DAYS, Kaleta\Core\Inbox::EXCERPT],
    ['Kaleta\Core\App $app, int $userId, string $kind, string $target, ?int $actorId, string $excerpt = \'\'', 'void', true, null, null, null,
    ['mention', 'assigned', 'comment', 'review_due', 'task_due', 'fleet_expiry', 'deletion_due', 'quote_expiring', 'quote_expired', 'quote_accepted', 'quote_declined'], ['instant', 'daily', 'off'], 90, 200]); // 3.27: task_due; 3.30: fleet_expiry; 3.34: deletion_due; 3.38: quotes; 3.40: the customer's answer
Kaleta\Core\Inbox::notify($i326App, 5, 'mention', 'page:1', 5, 'x'); // must not throw – the actor themself, nothing happens
check('3.26 Inbox: the e-mail choice, the excerpt (plain text, one line, at most 200 characters), the targets and the daily hour', [
    Kaleta\Core\Inbox::emailMode('daily'), Kaleta\Core\Inbox::emailMode(''), Kaleta\Core\Inbox::emailMode('weekly'),
    Kaleta\Core\Inbox::excerpt("  Jana,\n please check\tthe <b>prices</b> & more  "), mb_strlen(Kaleta\Core\Inbox::excerpt(str_repeat('a', 500))), str_ends_with(Kaleta\Core\Inbox::excerpt(str_repeat('a ', 300)), '…'),
    Kaleta\Core\Inbox::excerpt("a\x07b"), Kaleta\Core\Inbox::parse('enquiry:12'), Kaleta\Core\Inbox::parse('page:5'), Kaleta\Core\Inbox::parse('item:9'), Kaleta\Core\Inbox::parse('stranka:5'),
    Kaleta\Core\Inbox::parse('page:0'), Kaleta\Core\Inbox::parse('page:5 '), Kaleta\Core\Inbox::places($i326Db, ['offer:3', 'x']),
    Kaleta\Core\Inbox::due('', new DateTimeImmutable('2026-11-02 07:59')), Kaleta\Core\Inbox::due('', new DateTimeImmutable('2026-11-02 08:00')),
    Kaleta\Core\Inbox::due('2026-11-02', new DateTimeImmutable('2026-11-02 15:00')), Kaleta\Core\Inbox::due('2026-11-01', new DateTimeImmutable('2026-11-02 23:10'))],
    ['daily', 'instant', 'instant', 'Jana, please check the <b>prices</b> & more', 200, true, 'ab', ['enquiry', 12], ['page', 5], ['item', 9], null, null, null, [], false, true, false, true]);
$i326Recipient = fn (string $language, string $register = ''): array => ['jazyk' => $language, 'register' => $register];
$i326Enquiry = ['kind' => 'enquiry', 'id' => 12, 'title' => 'Contact', 'path' => ''];
$i326Mention = Kaleta\Core\Inbox::mail($reportSettings([]), $i326Recipient(''), ['kind' => 'mention', 'actor_name' => 'Jana', 'excerpt' => 'Please check the prices'], $i326Enquiry, 'https://example.com/admin.php?action=inbox&open=7', 'https://example.com/admin.php?action=account#notifications');
$i326Assigned = Kaleta\Core\Inbox::mail($reportSettings([]), $i326Recipient('cs'), ['kind' => 'assigned', 'actor_name' => '', 'excerpt' => 'never shown'], ['kind' => 'page', 'id' => 5, 'title' => 'Services', 'path' => ''], 'https://x/1', 'https://x/2');
$i326Review = Kaleta\Core\Inbox::mail($reportSettings([]), $i326Recipient('de', 'informal'), ['kind' => 'review_due', 'actor_name' => '', 'excerpt' => ''], ['kind' => 'item', 'id' => 9, 'title' => 'Oak', 'collection' => 'Products', 'path' => ''], 'https://x/1', 'https://x/2');
$i326Long = Kaleta\Core\Inbox::mail($reportSettings([]), $i326Recipient(''), ['kind' => 'comment', 'actor_name' => 'Petr', 'excerpt' => str_repeat('b', 201)], null, 'https://x/1', 'https://x/2');
check('3.26 Inbox: the instant e-mail – who, what, where in the recipient\'s admin language; the quote only when it is there and short, never for an assignment; the link and where to change the choice', [
    $i326Mention[0], str_contains($i326Mention[1], "Jana mentioned you: Enquiry #12 · Contact\n\n“Please check the prices”\n"), str_contains($i326Mention[1], 'https://example.com/admin.php?action=inbox&open=7'),
    str_contains($i326Mention[1], 'https://example.com/admin.php?action=account#notifications'),
    $i326Assigned[0], str_contains($i326Assigned[1], 'never shown'), $i326Review[0], str_contains($i326Review[1], 'Der Prüftermin ist da: „Oak“ in Products'),
    $i326Long[0], str_contains($i326Long[1], 'bbb')],
    ['Jana mentioned you: Enquiry #12 · Contact', true, true, true, 'Kaleta vás určil(a) jako odpovědnou osobu: Stránka „Services“', false, 'Heute zu prüfen: „Oak“', true, 'Petr commented on (no longer available)', false]);
$i326Digest = Kaleta\Core\Inbox::digest($reportSettings(['site_name' => 'Firma']), $i326Recipient('es'), [
    ['kind' => 'mention', 'actor_name' => 'Jana', 'excerpt' => 'Mira esto', 'url' => 'https://x/a', 'place' => $i326Enquiry],
    ['kind' => 'assigned', 'actor_name' => 'Petr', 'excerpt' => 'hidden', 'url' => '', 'place' => null]], 'https://x/inbox', 'https://x/account');
check('3.26 Inbox: the daily summary – the count and the site in the subject, each notification with its quote and link, the screen and the choice', [
    $i326Digest[0], str_contains($i326Digest[1], "- Jana te ha mencionado – Consulta n.º 12 · Contact\n  “Mira esto”\n  https://x/a"), str_contains($i326Digest[1], 'Petr te ha nombrado persona responsable – (ya no está disponible)'),
    str_contains($i326Digest[1], 'hidden'), str_contains($i326Digest[1], "https://x/inbox"), str_contains($i326Digest[1], "https://x/account"),
    Kaleta\Core\Inbox::digest($reportSettings(['site_name' => 'Firma']), $i326Recipient('en'), [['kind' => 'comment', 'actor_name' => 'A', 'excerpt' => '', 'url' => '', 'place' => null]], 'i', 'a')[0]],
    ['2 notificaciones nuevas – Firma', true, true, false, true, true, '1 new notification – Firma']);
$i326Texts = ['%s mentioned you', '%s commented', '%s made you responsible', 'The review date has come', 'Enquiry #%s · %s', '“%s” in %s', '(no longer available)', '%s mentioned you: %s', '%s commented on %s',
    '%s made you responsible for %s', 'This e-mail came right away because of your choice in My account. You can have one summary a day instead, or none:', '1 new notification – %s', '%d new notifications – %s',
    'What happened since the last summary:', 'All notifications:', 'You chose one summary a day in My account. You can have each notification right away instead, or none:',
    'All notifications are marked as read.', 'This notification no longer exists – notifications are kept for 90 days.', 'What this notification was about is no longer available.', 'Notifications',
    'You get one e-mail summary a day, in the morning.', 'You get no e-mail about them – they are only here.', 'You get an e-mail about each one right away.',
    'Mentions, comments on what you are responsible for, things assigned to you and review dates. Kept for 90 days.', 'Change it in My account', 'Mark all as read', 'No older notifications.',
    'No notifications yet.', 'When a colleague mentions you, comments on what you are responsible for or assigns you something, and when a review date comes, you will see it here.', 'Back to the newest',
    'Unread:', 'Newer', 'Older', 'Notifications – %d unread', 'Notifications by e-mail', 'Right away', 'One e-mail for each notification, as it happens.', 'Once a day',
    'One summary in the morning (around 8:00) of what you have not read yet.', 'Never', 'No e-mail – only the bell in the administration.',
    'Your account has no e-mail address – add it above, otherwise notifications are only under the bell.', 'Save the choice', 'Your choice is saved.',
    'Notifications tell you when a colleague mentions you, comments on what you are responsible for or assigns you something, and when a review date comes. They are always under the bell at the top of the administration; here you choose whether they also come by e-mail.'];
$i326Missing = [];
foreach (['cs', 'de', 'es'] as $i326Code) {
    $i326Dictionary = require KALETA_SYSTEM . '/jazyky/admin-' . $i326Code . '.php';
    $i326Missing = [...$i326Missing, ...array_map(fn (string $k): string => $i326Code . ': ' . $k, array_values(array_diff($i326Texts, array_keys($i326Dictionary))))];
}
check('3.26: the texts of notifications are translated in every admin language', $i326Missing, []);
$i326Kernel = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Kernel.php');
$i326Layout = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php');
$i326Post = fn (string $action, array $post = []): bool => Kaleta\Admin\Kernel::clearsPageCache(new Kaleta\Core\Request(['module' => '', 'action' => $action], $post, ['REQUEST_METHOD' => 'POST']));
check('[source-pin] 3.26: the Notifications screen is a header action (no section, no left-menu item, no menu group), reading and marking keep the page cache, the e-mail choice too; the bell sits in the header bar with one COUNT', [
    in_array('inbox', Kaleta\Admin\Kernel::SITE_NEUTRAL_ACTIONS[''], true), str_contains($i326Kernel, "if (\$action === 'inbox' && \$ident === '') {"),
    array_values(array_filter(Kaleta\Admin\Kernel::MODULES, fn (string $c): bool => in_array($c::IDENT, ['inbox', 'notifications'], true))),
    $i326Post('inbox', ['read_all' => '1']), $i326Post('account', ['co' => 'notifications']), $i326Post('account', ['co' => 'profil']),
    (bool) preg_match('#<section class="loginprouzek".*class="zvonek.*</section>#s', $i326Layout), (bool) preg_match('#<nav class="menu-obal".*zvonek.*</nav>#s', $i326Layout),
    str_contains($i326Layout, "\$bell = \$user !== null && empty(\$gated) ? Kaleta\\Core\\Inbox::unreadCount(\$app->db(), (int) \$user['idu']) : null;"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Inbox.php'), "'SELECT COUNT(*) FROM {notifications} WHERE user_id = ? AND read_at IS NULL'"),
    Kaleta\Core\Scheduler::JOBS['inbox'][0] ?? null, Kaleta\Core\Scheduler::JOBS['inbox'][1] ?? null, isset(Kaleta\Core\Scheduler::jobs()['inbox']), Kaleta\Core\Settings::DEFAULTS['inbox_digest_day'] ?? null],
    [true, true, [], false, false, true, true, false, true, true, 3600, 'any', true, '']);
$i326Assignment = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Assignment.php');
$i326Validity = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Validity.php');
$i326Comments = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Comments.php');
check('[source-pin] 3.26: the assignment e-mail and the review reminder go through the notifications – one record each, their own e-mail only for "instant" and then marked e-mailed (no second e-mail from the summary); an enquiry\'s deleted or anonymised notes take the notifications quoting them along', [
    str_contains($i326Assignment, "Inbox::record(\$db, \$toUserId, 'assigned', \$kind . ':' . \$id, \$byUserId > 0 ? \$byUserId : null);"),
    str_contains($i326Assignment, "if ((\$note['email'] ?? 'instant') !== 'instant' || count(Mail::addresses((string) \$to['email'])) !== 1) {"),
    str_contains($i326Assignment, "Inbox::markEmailed(\$db, \$note !== null ? [\$note['id']] : []);"),
    str_contains($i326Validity, "Inbox::record(\$db, \$owner, 'review_due',"), str_contains($i326Validity, "\$mail = \$choice === 'instant' && count(Mail::addresses((string) \$user['email'])) === 1;"),
    str_contains($i326Validity, 'Inbox::markEmailed($db, array_map(fn (array $n): int => $n[\'id\'], $notes));'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Inbox.php'), "WHERE user_id = ? AND read_at IS NULL AND emailed_at IS NULL ORDER BY id LIMIT 100"),
    str_contains($i326Comments, 'Inbox::forgetTargets($db, $targets);'), str_contains($i326Comments, 'Inbox::forgetTargets($db, $targets, true);'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/index.php'), 'Kaleta\Core\Mail::afterResponse($app);')],
    [true, true, true, true, true, true, true, true, true, true]);
// the merge of 3.26: comments and mentions (agent A) notify through the real Inbox, and every way a comment's text goes takes its quote along
check('[source-pin] 3.26 merge: Comments notify through Inbox::notify directly (no stand-in check), never the author; an edit requotes, a delete blanks, forget and both sweeps forget the notifications', [
    str_contains($i326Comments, 'Inbox::notify($app, $userId, $kind, $target, $author > 0 ? $author : null, $excerpt);'), str_contains($i326Comments, 'is_callable'),
    substr_count($i326Comments, 'Inbox::requote($db, $target,'), substr_count($i326Comments, 'Inbox::forgetTargets($db, $gone);'), str_contains($i326Comments, "Inbox::forgetTargets(\$db, \$targets); // the notifications about them go too (they may quote a comment)"),
    Kaleta\Core\Comments::recipients([3, 5, 3], 5, 5), Kaleta\Core\Comments::EXCERPT === Kaleta\Core\Inbox::EXCERPT, Kaleta\Core\Inbox::requote($i326Db, 'page:1', '', 'x')],
    [true, false, 2, 2, true, [[3, 'mention']], true, 0]);
// 3.26 (A): the team's threads on pages and items, @mentions in every thread (Core\Comments)
$a326 = Kaleta\Core\Comments::class;
$a326People = [5 => ['user' => 'jana', 'name' => 'Jana <Nová>'], 7 => ['user' => 'petr.novak', 'name' => 'Petr']];
check('3.26 (A): targets of a thread – an enquiry, a page or an item with a positive id; the kind of comment follows the target', [
    $a326::page(12), $a326::item(3), $a326::parseTarget('page:12'), $a326::parseTarget('item:7'), $a326::parseTarget('enquiry:4'), $a326::parseTarget('page:0'), $a326::parseTarget('item:3x'),
    $a326::parseTarget('stranka:3'), $a326::parseTarget('page:12345678901'), $a326::KINDS,
], ['page:12', 'item:3', ['type' => 'page', 'id' => 12], ['type' => 'item', 'id' => 7], ['type' => 'enquiry', 'id' => 4], null, null, null, null,
    ['enquiry' => 'note', 'page' => 'comment', 'item' => 'comment', 'contact' => 'comment', 'company' => 'comment', 'deal' => 'comment', 'application' => 'note']]); // 3.28: customers and companies; 3.31: deals; 3.34: job applications
check('3.26 (A): "@" and a sign-in name is a mention – once each, not inside a word or an e-mail address, not a single letter', [
    $a326::mentionTokens('Hi @jana and @Petr.Novak, mail x@y.cz or @jana again, @a, a@b, (@petr)'), $a326::mentionTokens('no mention'), $a326::mentionTokens("line\n@eva-k: ok"),
], [['jana', 'Petr.Novak', 'petr'], [], ['eva-k']]);
check('3.26 (A): a comment as HTML – escaped, line breaks kept, a known mention as the name (escaped too), a full stop after it stays, an unknown "@word" stays text', [
    $a326::html("Hi @Jana. <b>x</b>\n@petr.novak, @nobody & co", $a326People),
    $a326::html('<script>alert(1)</script>', []),
], ['Hi <span class="zminka" data-mention="5">@Jana &lt;Nová&gt;</span>. &lt;b&gt;x&lt;/b&gt;<br />' . "\n" . '<span class="zminka" data-mention="7">@Petr</span>, @nobody &amp; co',
    '&lt;script&gt;alert(1)&lt;/script&gt;']);
check('3.26 (A): the excerpt of a notification – plain text, names for mentions, whitespace folded, at most 200 characters', [
    $a326::excerpt("Please  check\n@jana the prices", $a326People), mb_strlen($a326::excerpt(str_repeat('word ', 80), [])), str_ends_with($a326::excerpt(str_repeat('word ', 80), []), '…'),
    $a326::excerpt('<b>plain</b>', []),
], ['Please check @Jana <Nová> the prices', 200, true, '<b>plain</b>']);
check('3.26 (A): who is notified – each newly mentioned person once, the responsible person of a new comment unless mentioned anyway, never the author', [
    $a326::recipients([5, 7, 5, 3], 7, 3), $a326::recipients([], 9, 3), $a326::recipients([], 3, 3), $a326::recipients([3], null, 3), $a326::recipients([5], 9, 0),
], [[[5, 'mention'], [7, 'mention']], [[9, 'comment']], [], [], [[5, 'mention'], [9, 'comment']]]);
$a326Src = fn (string $file): string => (string) file_get_contents(KALETA_SYSTEM . '/' . $file);
check('[source-pin] 3.26 (A): a thread follows its target – the permanent delete and the emptied trash of pages and items, a deleted collection; the trash keeps it; the real Inbox notifies', [
    str_contains($a326Src('src/Admin/Modules/Pages.php'), "Comments::forget(\$this->db, [\\Kaleta\\Core\\Comments::page(\$this->request->postInt('ids'))]);"),
    str_contains($a326Src('src/Admin/Modules/Pages.php'), 'Comments::forget($db, array_map(\Kaleta\Core\Comments::page(...), $ids));'),
    str_contains($a326Src('src/Admin/Modules/Collections.php'), "Comments::forget(\$this->db, [\\Kaleta\\Core\\Comments::item(\$this->request->postInt('idp'))]);"),
    str_contains($a326Src('src/Admin/Modules/Collections.php'), 'Comments::forget($db, array_map(\Kaleta\Core\Comments::item(...), $ids));'),
    str_contains($a326Src('src/Builder/Collections.php'), 'Comments::forget($db, array_map('),
    str_contains($a326Src('src/Core/Scheduler.php'), '$threads = Comments::sweepContent($app->db());'), // T1 (Q5): the daily trash job, no longer the Pages list
    // the merge: the real Core\Inbox is called directly (it never throws), no longer behind is_callable
    str_contains($a326Src('src/Core/Comments.php'), 'Inbox::notify($app, $userId, $kind, $target,') && !str_contains($a326Src('src/Core/Comments.php'), 'is_callable'),
    in_array('comment', Kaleta\Admin\Kernel::SITE_NEUTRAL_ACTIONS['pages'], true) && in_array('comment', Kaleta\Admin\Kernel::SITE_NEUTRAL_ACTIONS['collections'], true),
], [true, true, true, true, true, true, true, true]);
$a326Admin = (string) file_get_contents(KALETA_ROOT . '/image/admin.js');
check('[source-pin] 3.26 (A): the "@" autocomplete is delegated from image/admin.js (CSP: no inline script) for textarea[data-mentions] – the comment panel, the enquiry composer and the builder Team tab carry it', [
    str_contains($a326Admin, "e.target.matches('textarea[data-mentions]')"), str_contains($a326Admin, "'@' + o.getAttribute('data-user') + ' '"),
    str_contains($a326Src('views/admin/comments.php'), 'data-mentions="<?= e($mentionsJson) ?>"'), str_contains($a326Src('views/admin/enquiries/detail.php'), 'data-mentions="<?= e($mentionsJson) ?>"'),
    str_contains((string) file_get_contents(KALETA_ROOT . '/image/stavitel.js'), "'data-mentions': people"),
    str_contains($a326Src('views/admin/pages/form.php'), "render('admin/comments'"), str_contains($a326Src('views/admin/collections/item.php'), "render('admin/comments'"),
], [true, true, true, true, true, true, true]);

// 3.26 (C): MCP list_comments / add_comment on Core\Comments, and the "work" tool set for an employee's own Claude (D-W10, T12)
check('[source-pin] 3.26 (C): MCP comments stand on Core\Comments – the targets, the check of mentions and post() of the administration; the refusal in English', [
    Kaleta\Core\Comments::parseTarget('page:12'), Kaleta\Core\Comments::parseTarget('item:40'), Kaleta\Core\Comments::parseTarget('enquiry:7'),
    Kaleta\Core\Comments::parseTarget('page:0'), Kaleta\Core\Comments::parseTarget('news:3'), Kaleta\Core\Comments::parseTarget('page:12;drop'), Kaleta\Core\Comments::parseTarget('stranka:5'),
    str_contains($c326Tools = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/CommentTools.php'), "Language::runWith('en', fn (): array => Comments::mentions(\$db, \$target, \$text), 'admin-')"),
    str_contains($c326Tools, "Comments::post(\$this->app, \$target, \$text, \$auth->user() ?? [], 'mcp')"), str_contains($c326Tools, "Comments::add(\$db, \$target, \$text, \$auth->user() ?? [], 'mcp')"),
], [['type' => 'page', 'id' => 12], ['type' => 'item', 'id' => 40], ['type' => 'enquiry', 'id' => 7], null, null, null, null, true, true, true]);
check('[source-pin] 3.26 (C): list_comments (read) and add_comment (draft – no @mention from a drafts-only connection), English on both sides, outside the essentials, site-neutral, inside the site', [
    Kaleta\Mcp\Catalog::TOOLS['list_comments'] ?? null, Kaleta\Mcp\Catalog::TOOLS['add_comment'] ?? null, Kaleta\Mcp\Translator::czech('list_comments'), Kaleta\Mcp\Translator::czech('add_comment'),
    array_values(array_intersect(['list_comments', 'add_comment'], Kaleta\Mcp\Catalog::ESSENTIALS)), Kaleta\Mcp\Catalog::isSiteNeutral('add_comment'), Kaleta\Mcp\Catalog::isOpenWorld('add_comment'),
    Kaleta\Mcp\Catalog::isIdempotent('add_comment'), Kaleta\Mcp\Catalog::allows('drafts', 'add_comment'), Kaleta\Mcp\Catalog::allows('read', 'add_comment'), Kaleta\Mcp\Catalog::allows('read', 'list_comments'),
    Kaleta\Mcp\Tools::annotations('list_comments')['readOnlyHint'], Kaleta\Mcp\Discovery::runner('add_comment'),
    array_column(Kaleta\Mcp\Tools::definitions(), 'inputSchema', 'name')['add_comment']['required'] ?? null,
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/CommentTools.php'), "\$mentions !== [] && \$auth->draftsOnly()"),
], [['read', ''], ['draft', ''], 'list_comments', 'add_comment', [], true, false, false, true, false, true, true, 'run_tool', ['target', 'text'], true]);
// a comment Claude writes on a page or an item is journaled by its row (undo takes it back); the table stays out of the
// journaled tables, so an enquiry note never waits in the journal
check('3.26 (C): comments on pages and items join the Claude session for undo, enquiry notes never; the table itself stays a personal-data table', [
    str_contains($c326Tools, "if (\$db->journal !== null && !in_array(\$type, ['enquiry', 'contact'], true)) {"), // 3.28: nor a note on a customer
    Kaleta\Core\AgentJournal::journaled('comments'), in_array('comments', Kaleta\Core\AgentJournal::PERSONAL_TABLES, true),
], [true, false, true]);
$w326All = array_map(Kaleta\Mcp\Server::listed(...), Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()));
$w326Json = fn (mixed $v): int => strlen((string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
$w326Work = Kaleta\Mcp\Discovery::essentials($w326All, 'full', Kaleta\Mcp\Catalog::WORK);
$w326Drafts = Kaleta\Mcp\Discovery::essentials(array_values(array_filter($w326All, fn (array $t): bool => Kaleta\Mcp\Catalog::allows('drafts', $t['name']))), 'drafts', Kaleta\Mcp\Catalog::WORK);
check('3.26 (C, T12): the work tool set – catalog tools once each in catalog order, then find_tools and the runners; under 49,000 bytes; the essentials unchanged and in budget', [
    Kaleta\Mcp\Catalog::TOOL_SETS, Kaleta\Mcp\Catalog::toolSet('work'), Kaleta\Mcp\Catalog::toolSet('Work'), Kaleta\Mcp\Catalog::listOf('work') === Kaleta\Mcp\Catalog::WORK,
    Kaleta\Mcp\Catalog::listOf('essentials') === Kaleta\Mcp\Catalog::ESSENTIALS, Kaleta\Mcp\Catalog::listOf('all'), Kaleta\Mcp\Catalog::listOf('anything'),
    array_values(array_diff(Kaleta\Mcp\Catalog::WORK, array_keys(Kaleta\Mcp\Catalog::TOOLS))), array_keys(array_filter(array_count_values(Kaleta\Mcp\Catalog::WORK), fn (int $n): bool => $n > 1)),
    array_column($w326Work, 'name') === [...array_values(array_intersect(array_column($w326All, 'name'), Kaleta\Mcp\Catalog::WORK)), 'find_tools', 'run_read_tool', 'run_tool', 'run_destructive_tool'],
    $w326Json(['tools' => $w326Work]) <= 49000, $w326Json(['tools' => Kaleta\Mcp\Discovery::essentials($w326All, 'full')]) <= 49000,
    array_values(array_diff(['site_info', 'my_work', 'list_enquiries', 'update_enquiry', 'reply_to_enquiry', 'list_comments', 'add_comment', 'assign', 'get_email_signature', 'list_requests', 'update_request',
        'read_notebook', 'write_notebook', 'list_pages', 'get_page', 'preview_link', 'list_collection_items', 'save_collection_item'], Kaleta\Mcp\Catalog::WORK)),
    array_values(array_intersect(['list_comments', 'add_comment', 'my_work', 'assign'], Kaleta\Mcp\Catalog::ESSENTIALS)),
    in_array('assign', array_column($w326Drafts, 'name'), true), in_array('add_comment', array_column($w326Drafts, 'name'), true),
], [['all', 'essentials', 'work', 'migration', 'fleet'] /* 3.36 */, 'work', 'all', true, true, null, null, [], [], true, true, true, [], [], false, true]);
check('[source-pin] 3.26 (C): the work tool set is offered under Advanced on the consent screen and for a new token, labelled on My account; the server instructions name it', [
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/views/admin/connection-access.php'), "'work' => ['Work – for a colleague’s own Claude'"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/account.php'), "'work' => t('work tools')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Prompts.php'), 'YOUR TOOL LIST HOLDS THE WORK TOOLS'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Server.php'), 'Discovery::essentials($this->available($tools), $this->access(), $list)'),
    array_map(fn (string $code): bool => isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['work tools']), ['cs', 'de', 'es']),
], [1 /* 3.36: one list of sets, the Claude and the application wording differ only in one sentence */, true, true, true, [true, true, true]]);

// 3.26 (C, N-U2): spacing and colour in the builder are token choices – the field checks a custom value as the server does
$n326Script = (string) file_get_contents(KALETA_ROOT . '/image/stavitel.js');
preg_match('~const COLOUR = /(.+)/i; // mirrors Style::COLOR_PATTERN~', $n326Script, $n326Colour);
$n326Php = (string) (new ReflectionClassConstant(Kaleta\Builder\Style::class, 'COLOR_PATTERN'))->getValue();
check('[source-pin] 3.26 (N-U2): the builder\'s colour check mirrors Style::COLOR_PATTERN; spacing and colour fields are token choices with Custom…', [
    $n326Colour[1] ?? '', str_contains($n326Script, "} else if (def.typ === 'mezera') {\n\t\t\tinputEl = spaceControl("), str_contains($n326Script, "} else if (def.typ === 'barva') {\n\t\t\tinputEl = colourControl("),
    str_contains($n326Script, 'pattern: LENGTH, placeholder: \'24px, 1.5rem\''), str_contains($n326Script, 'pattern: COLOUR,'),
], [substr($n326Php, 1, strrpos($n326Php, '/') - 1), true, true, true, true]);
check('3.26 (N-U2) Style: a margin takes a spacing step or a length, never a colour word; a colour takes a token or a safe custom colour', [
    array_map(fn (string $h): ?string => Kaleta\Builder\Style::value('okraj_dole', $h), ['m', '0', '24px', '-1.5rem', 'auto', 'primarni', 'xl ', '24 px', 'var(--x)']),
    array_map(fn (string $h): ?string => Kaleta\Builder\Style::value('pozadi', $h), ['primarni', 'transparent', '#1e40af', 'oklch(60% 0.1 250)', 'm', 'red', 'url(x)']),
], [['var(--ka-mezera-m)', '0', '24px', '-1.5rem', 'auto', null, null, null, null],
    ['var(--ka-barva-primarni)', 'transparent', '#1e40af', 'oklch(60% 0.1 250)', null, null, null]]);

// 3.26.1 (B): the uptime of a paired site in its own monthly report – Core\UptimeStrip and Fleet\Uptime
use Kaleta\Core\UptimeStrip;
use Kaleta\Fleet\Uptime;

check('3.26.1 UptimeStrip: a day from its checks – up, partial, down, none; the percent rounded down (never 100 % with a failure); from a percent alone; the colour of each', [
    UptimeStrip::day('2026-10-01', 288, 0), UptimeStrip::day('2026-10-02', 288, 1), UptimeStrip::day('2026-10-03', 10, 10), UptimeStrip::day('2026-10-04', 0, 0), UptimeStrip::day('2026-10-05', 10, 99),
    UptimeStrip::percent(100000, 1), UptimeStrip::fromPercent('2026-10-06', 99.5), UptimeStrip::fromPercent('2026-10-07', null), UptimeStrip::fromPercent('2026-10-08', 100.0), UptimeStrip::fromPercent('2026-10-09', 0.0),
    array_map(UptimeStrip::tone(...), [UptimeStrip::day('a', 288, 0), UptimeStrip::day('a', 288, 2), UptimeStrip::day('a', 100, 2), UptimeStrip::day('a', 5, 5), UptimeStrip::day('a', 0, 0), ['state' => 'evil', 'percent' => 50]]),
], [['date' => '2026-10-01', 'state' => 'up', 'percent' => 100.0], ['date' => '2026-10-02', 'state' => 'partial', 'percent' => 99.65], ['date' => '2026-10-03', 'state' => 'down', 'percent' => 0.0],
    ['date' => '2026-10-04', 'state' => 'none', 'percent' => null], ['date' => '2026-10-05', 'state' => 'down', 'percent' => 0.0], 99.99,
    ['date' => '2026-10-06', 'state' => 'partial', 'percent' => 99.5], ['date' => '2026-10-07', 'state' => 'none', 'percent' => null], ['date' => '2026-10-08', 'state' => 'up', 'percent' => 100.0], ['date' => '2026-10-09', 'state' => 'down', 'percent' => 0.0],
    ['up', 'weak', 'down', 'down', 'none', 'none']]);
$strip3261 = Kaleta\Core\Language::runWith('en', fn (): string => UptimeStrip::html([UptimeStrip::day('2026-10-01', 288, 0), UptimeStrip::day('2026-10-02', 288, 1), UptimeStrip::day('2026-10-03', 0, 0),
    ['date' => '<script>x</script>', 'state' => 'up', 'percent' => 100.0], 'junk'], ['label' => 'Up "time" <b>', 'height' => 500]), 'admin-');
$wide3261 = UptimeStrip::html(array_map(fn (int $i): array => UptimeStrip::day(date('Y-m-d', strtotime('2026-08-01 +' . $i . ' days')), 288, 0), range(0, 89)), ['legend' => false, 'dates' => false]);
check('3.26.1 UptimeStrip::html – e-mail safe (tables, inline styles and bgcolor, no SVG, class or script), one titled cell a day, the label and every text escaped, the legend only of the colours shown, the dates at both ends', [
    substr_count($strip3261, '<td title="'), str_contains($strip3261, 'role="img" aria-label="Up &quot;time&quot; &lt;b&gt;"'), str_contains($strip3261, '<script'), str_contains($strip3261, '&lt;script&gt;x&lt;/script&gt;: 100.00 %'),
    preg_match('/<svg|class=|<style|javascript:/i', $strip3261), str_contains($strip3261, 'title="1 Oct 2026: 100.00 %" bgcolor="#12A150" height="80"'), str_contains($strip3261, 'title="2 Oct 2026: 99.65 %" bgcolor="#C97F00"'),
    str_contains($strip3261, 'title="3 Oct 2026: not measured" height="80" valign="bottom"'), [str_contains($strip3261, '>No outage<'), str_contains($strip3261, '>Brief outage (99 % or more)<'), str_contains($strip3261, '>Outage<'), str_contains($strip3261, '>Not measured<')],
    str_contains($strip3261, '>1 Oct 2026</td>'), substr_count($wide3261, '<td title="'), str_contains($wide3261, 'cellspacing="1"'), str_contains($wide3261, 'No outage'), UptimeStrip::html([]),
    Kaleta\Core\Language::runWith('en', fn (): string => UptimeStrip::summary([UptimeStrip::day('2026-10-01', 288, 0), UptimeStrip::day('2026-10-02', 288, 1)]), 'admin-'),
], [4, true, false, true, 0, true, true, true, [true, true, false, true], true, 90, true, false, '', 'Uptime: 2 days measured, 1 of them without an outage; the lowest 99.65 % on 2 Oct 2026.']);
$uptime3261 = Uptime::forMonth([['date' => '2026-10-02', 'checks' => 288, 'failed' => 1], ['date' => '2026-10-03', 'checks' => 288, 'failed' => 288], ['date' => '2026-09-30', 'checks' => 288, 'failed' => 288],
    ['date' => '2026-10-04', 'checks' => 288, 'failed' => 0], ['date' => '2026-10-09', 'checks' => 288, 'failed' => 0]], '2026-10', '2026-10-05');
check('3.26.1 Uptime::forMonth – the report contract: percent, checks, failed and every day of the month up to today with its state and percent; null for a month without a measured day', [
    array_keys($uptime3261), [$uptime3261['percent'], $uptime3261['checks'], $uptime3261['failed']], count($uptime3261['days']), $uptime3261['days'][0], $uptime3261['days'][1], array_keys($uptime3261['days'][4]),
    array_column($uptime3261['days'], 'state'), count(Uptime::forMonth([['date' => '2026-02-10', 'checks' => 1, 'failed' => 0]], '2026-02', '2026-12-31')['days']),
    Uptime::forMonth([['date' => '2026-09-30', 'checks' => 288, 'failed' => 0]], '2026-10', '2026-10-31'), Uptime::forMonth([['date' => '2026-10-01', 'checks' => 0, 'failed' => 0]], '2026-10', '2026-10-31'), Uptime::forMonth([], '2026-13', '2026-12-31'),
    Kaleta\Core\Language::runWith('en', fn (): string => UptimeStrip::text($uptime3261), 'admin-'), UptimeStrip::text(null),
    Kaleta\Core\Language::runWith('en', fn (): string => UptimeStrip::text(Uptime::forMonth([['date' => '2026-10-01', 'checks' => 288, 'failed' => 0]], '2026-10', '2026-10-01')), 'admin-'),
], [['percent', 'checks', 'failed', 'days'], [66.55, 864, 289], 5, ['date' => '2026-10-01', 'state' => 'none', 'percent' => null], ['date' => '2026-10-02', 'state' => 'partial', 'percent' => 99.65], ['date', 'state', 'percent'],
    ['none', 'partial', 'down', 'up', 'none'], 28, null, null, null,
    "Uptime: 66.55 % (864 checks, 289 failed)\nDays with an outage: 2 Oct 2026 (99.65 %), 3 Oct 2026 (0.00 %)\nDays not measured: 2", '', "Uptime: 100.00 % (288 checks, 0 failed)\nNo outage on any measured day."]);
check('3.26.1 Uptime::clean, merge and due – only real dates and whole counts in bounds (failed never above the checks), one entry a date; new days over the old, older months dropped; asked again after 20 hours or once the month turned', [
    Uptime::clean([['date' => '2026-10-01', 'checks' => 288, 'failed' => 999], ['date' => '2026-02-30', 'checks' => 1, 'failed' => 0], ['date' => '2026-10-02', 'checks' => '5', 'failed' => 0],
        ['date' => '2026-10-03 ', 'checks' => 1, 'failed' => 0], ['date' => '2026-10-04', 'checks' => 9_999_999, 'failed' => -3], 'x', ['date' => '2026-10-01', 'checks' => 10, 'failed' => 1]]),
    Uptime::clean('nope'), count(Uptime::clean(array_map(fn (int $i): array => ['date' => date('Y-m-d', strtotime('2026-01-01 +' . $i . ' days')), 'checks' => 1, 'failed' => 0], range(0, 199)))),
    Uptime::merge([['date' => '2026-07-31', 'checks' => 1, 'failed' => 0], ['date' => '2026-08-01', 'checks' => 1, 'failed' => 1], ['date' => '2026-10-01', 'checks' => 1, 'failed' => 1]],
        [['date' => '2026-10-01', 'checks' => 2, 'failed' => 0], ['date' => '2026-09-15', 'checks' => 3, 'failed' => 0]], '2026-08-01'),
    Uptime::due(null, (int) strtotime('2026-10-15 12:00')), Uptime::due(['checked' => (int) strtotime('2026-10-15 01:00'), 'received' => null, 'days' => []], (int) strtotime('2026-10-15 12:00')),
    Uptime::due(['checked' => (int) strtotime('2026-10-14 15:00'), 'received' => null, 'days' => []], (int) strtotime('2026-10-15 12:00')),
    Uptime::due(['checked' => (int) strtotime('2026-10-31 23:30'), 'received' => null, 'days' => []], (int) strtotime('2026-11-01 00:10')),
    Uptime::fromHistory(['2026-10-01' => ['checks_ok' => 286, 'checks_failed' => 2, 'status' => 'ok'], '2026-10-02' => ['checks_ok' => null, 'checks_failed' => null]]),
], [[['date' => '2026-10-01', 'checks' => 10, 'failed' => 1], ['date' => '2026-10-04', 'checks' => 100000, 'failed' => 0]], [], 70,
    [['date' => '2026-08-01', 'checks' => 1, 'failed' => 1], ['date' => '2026-09-15', 'checks' => 3, 'failed' => 0], ['date' => '2026-10-01', 'checks' => 2, 'failed' => 0]],
    true, false, true, true, [['date' => '2026-10-01', 'checks' => 288, 'failed' => 2], ['date' => '2026-10-02', 'checks' => 0, 'failed' => 0]]]);
// the console's answer: only signed by the console's own key; an older console (404) or another key brings nothing
$keys3261 = sodium_crypto_sign_keypair();
$console3261 = $reportSettings(['site_key_secret' => base64_encode(sodium_crypto_sign_secretkey($keys3261))]);
$body3261 = (string) json_encode(['ok' => true, 'days' => [['date' => '2026-10-01', 'checks' => 288, 'failed' => 3]]]);
$answer3261 = ['status' => 200, 'body' => $body3261, 'json' => json_decode($body3261, true), 'signature' => Kaleta\Fleet\Keys::sign($console3261, $body3261), 'error' => ''];
$public3261 = base64_encode(sodium_crypto_sign_publickey($keys3261));
check('3.26.1 Uptime::received – the days of an answer signed by the console\'s key; an older console without the address, a changed body or another key bring nothing', [
    Uptime::received($answer3261, $public3261), Uptime::received(['status' => 404, 'body' => '<html>', 'json' => null, 'signature' => '', 'error' => ''] + $answer3261, $public3261),
    Uptime::received(['body' => str_replace('288', '289', $body3261)] + $answer3261, $public3261), Uptime::received($answer3261, base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()))),
    Uptime::received(['json' => ['ok' => true]] + $answer3261, $public3261)],
    [[['date' => '2026-10-01', 'checks' => 288, 'failed' => 3]], null, null, null, null]);
$stored3261 = $reportSettings([Uptime::SETTING => (string) json_encode(['checked' => time(), 'received' => time(), 'days' => [['date' => date('Y-m-d'), 'checks' => 288, 'failed' => 0]]])]);
check('3.26.1 Uptime::stored, month and fill – the report of a month archived before 3.26.1 gains the uptime key; one that has it keeps it; a site without a console has null', [
    Uptime::stored($reportSettings([])), Uptime::month($reportSettings([]), date('Y-m')), Uptime::month($stored3261, date('Y-m'))['checks'], Uptime::fill($stored3261, ['month' => date('Y-m')])['uptime']['percent'],
    Uptime::fill($stored3261, ['month' => date('Y-m'), 'uptime' => null])['uptime'], array_key_exists('uptime', Uptime::fill($reportSettings([]), ['month' => '2020-01'])), Uptime::fill($reportSettings([]), ['x' => 1])['uptime'],
    Uptime::stored($reportSettings([Uptime::SETTING => '{"checked":"x"}']))],
    [null, null, 288, 100.0, null, true, null, null]);
$sources3261 = array_map(fn (string $f): string => (string) file_get_contents(KALETA_SYSTEM . '/' . $f), ['src/Core/MonthlyReport.php', 'src/Front/Kernel.php', 'src/Fleet/Link.php', 'src/Mcp/Handlers/ReportTools.php', 'src/Core/ReportArchive.php', 'views/admin/fleet/trend.php']);
check('[source-pin] 3.26.1 wiring: the report data has uptime (and older months gain it), the console answers /fleet/uptime, the heartbeat asks after a confirmed send and unpairing forgets the days, MCP adds it, the archive asks first; the trend draws the shared strip; the setting is internal', [
    str_contains($sources3261[0], "'uptime' => \$section(fn (): ?array => \\Kaleta\\Fleet\\Uptime::month(\$s, \$monthStart->format('Y-m'))),"), str_contains($sources3261[0], 'Uptime::fill($app->settings(), $stored[\'data\'])'),
    str_contains($sources3261[1], "'/fleet/uptime' => \\Kaleta\\Fleet\\Uptime::answer("), str_contains($sources3261[2], 'Uptime::afterHeartbeat($app, $refreshUptime)'), str_contains($sources3261[2], "'fleet_kit_error', Uptime::SETTING] as \$name"),
    str_contains($sources3261[3], 'Uptime::fill($this->app->settings(), $stored[\'data\'])'), str_contains($sources3261[4], 'Uptime::afterHeartbeat($app);'), str_contains($sources3261[5], 'UptimeStrip::html('), str_contains($sources3261[5], '<svg'),
    Kaleta\Core\Settings::DEFAULTS[Uptime::SETTING], in_array(Uptime::SETTING, Kaleta\Core\SiteExport::SETTINGS, true), (bool) preg_match((new ReflectionClassConstant(Kaleta\Mcp\Tools::class, 'MCP_SETTINGS'))->getValue(), Uptime::SETTING),
], [true, true, true, true, true, true, true, true, false, '', false, false]);
$fleetDays3261 = Report::siteData(['id' => 7, 'name' => 'Bakery', 'url' => 'https://bakery.example', 'version' => '3.17.1'], ['2026-09-01' => $day317(287, 1, 287 * 200, 287), '2026-09-03' => $day317(0, 10)], [], [], null, '2026-09');
$fleetBlocks3261 = Kaleta\Core\Language::runWith('en', fn (): array => array_column(Report::sections(['month' => '2026-09', 'sites' => [$fleetDays3261], 'totals' => Report::totals([$fleetDays3261])]), null, 'key')['site-7']['blocks'], 'admin-');
$fleetPage3261 = Report::render($view316, ['month' => '2026-09', 'customer' => '', 'created_at' => '2026-10-01 06:05:00', 'rebuilt_at' => null, 'data' => ['month' => '2026-09', 'sites' => [$fleetDays3261], 'totals' => Report::totals([$fleetDays3261])]],
    $reportSettings(['site_name' => 'Agency console']), 'https://console.example');
check('3.26.1 Fleet\\Report: each site carries its month\'s days, printed as the uptime strip after its facts; a report stored before has none and no block', [
    count($fleetDays3261['days']), array_column(array_slice($fleetDays3261['days'], 0, 3), 'state'), array_column($fleetBlocks3261, 'type'), substr_count($fleetPage3261, '<td title="'), str_contains($fleetPage3261, '<div class="uptime"><table role="img"'),
    array_key_exists('days', Report::siteData(['id' => 8, 'name' => '', 'url' => 'https://shop.example', 'version' => '3.16.2'], [], [], [], null))],
    [30, ['partial', 'none', 'down'], ['pairs', 'uptime', 'note'], 30, true, false]);

// N3261-1: after an update, the process that installed it runs no background job – it still has the old version's code in
// memory (the update check overwrote the new line's update information with the old line's manifest)
$installed3261 = new ReflectionProperty(Kaleta\Core\Updater::class, 'installedNow');
$installedBefore3261 = Kaleta\Core\Updater::installedNow();
$installed3261->setValue(null, true);
$runs3261 = [Kaleta\Core\Updater::installedNow()];
$installed3261->setValue(null, false);
$jobSources3261 = array_map(fn (string $f): string => (string) file_get_contents(KALETA_SYSTEM . '/src/Core/' . $f), ['Notifications.php', 'Scheduler.php', 'Updater.php']);
check('[source-pin] 3.26.1 (N3261-1): the process that installed an update runs no background job – neither after the admin answer nor the rest of a scheduler run', [
    $installedBefore3261, $runs3261[0], str_contains($jobSources3261[0], "< 60 || Updater::installedNow()) {\n            return;"), str_contains($jobSources3261[1], 'if (microtime(true) > $end || Updater::installedNow()) {'),
    substr_count($jobSources3261[2], 'self::$installedNow = true;')], [false, true, true, true, 1]);

// N3261-2: "Done by people" counts the changes of signed-in people once – no sign-ins (a bot's failed attempts), no comment twice
$peopleSql3261 = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/MonthlyReport.php');
check('[source-pin] 3.26.1 (N3261-2): the changes people made – the care count when the report has it, otherwise the change log by area (without sign-ins), only the oldest reports the bare count; the query leaves out sign-ins, Kaleta and comments', [
    ReportSummary::care(['care' => ['people_changes' => 3, 'comments' => 2], 'changes' => ['people' => 40], 'work' => ['areas' => ['pages' => ['people' => 9]]]])['people'],
    ReportSummary::care(['changes' => ['people' => 40], 'work' => ['areas' => ['pages' => ['people' => 4, 'claude' => 6], 'look' => ['people' => 1], 'x' => 'junk']]])['people'],
    ReportSummary::care(['changes' => ['people' => 40]])['people'],
    str_contains($peopleSql3261, "via = '' AND kdo IS NOT NULL\n            AND modul NOT IN ('prihlaseni', 'whistleblowing') AND akce NOT IN ('comment added', 'note added')")],
    [['changes' => 3, 'comments' => 2], ['changes' => 5], ['changes' => 40], true]);

// N3261-3: the runs of the daily checks – the difference to the previous month's counters; the first month unknown; a reset counter counts from 0
check('3.26.1 (N3261-3): MonthlyReport::checkRuns – the month\'s runs from the counters, none without the month before, a counter reset (a restored database) counts the runs since',
    [MonthlyReport::checkRuns(['domain_watch' => 90, 'security' => 61], ['domain_watch' => 60, 'security' => 30]), MonthlyReport::checkRuns(['domain_watch' => 90], null),
        MonthlyReport::checkRuns(['domain_watch' => 12, 'cookie_scan' => 5], ['domain_watch' => 300, 'cookie_scan' => 'x']), MonthlyReport::checkRuns([], [])],
    [['domain_watch' => 30, 'security' => 31], null, ['domain_watch' => 12, 'cookie_scan' => 5], []]);

// N3261-4: the days the site keeps – only those it asked for (a console's day in the future never pushes real days out), all of KEEP_MONTHS
$many3261 = array_map(fn (int $i): array => ['date' => date('Y-m-d', strtotime('2026-08-01 +' . $i . ' days')), 'checks' => 288, 'failed' => 0], range(0, 91));
$future3261 = array_map(fn (int $i): array => ['date' => date('Y-m-d', strtotime('2030-01-01 +' . $i . ' days')), 'checks' => 288, 'failed' => 0], range(0, 69));
$kept3261 = Uptime::merge(array_slice($many3261, 0, 60), [...array_slice($many3261, 60), ...$future3261], '2026-08-01', '2026-11-02');
check('3.26.1 (N3261-4): Uptime keeps the three months whole (not just the 70 days of one answer) and no day after the one asked for – a console\'s future dates are dropped', [
    count($kept3261), $kept3261[0]['date'], end($kept3261)['date'], count(Uptime::stored($reportSettings([Uptime::SETTING => (string) json_encode(['checked' => time(), 'days' => $many3261])]))['days']),
    count(Uptime::clean($many3261)), Uptime::merge([], [['date' => '2026-11-03', 'checks' => 1, 'failed' => 0]], '2026-09-01', '2026-11-03')[0]['date']],
    [92, '2026-08-01', '2026-10-31', 92, Uptime::MAX_DAYS, '2026-11-03']);

// N3261-5: a month with many rows everywhere stays under the size Gmail clips at – the HTML lists get shorter, the text keeps every row
$rows3261 = fn (string $key): array => array_map(fn (int $i): array => [$key => '/services/web-design-for-small-business-' . $i, 'n' => 100 - $i], range(1, 5));
$full3261 = array_replace_recursive($care3261, ['stats' => ['pages' => $rows3261('path'), 'sources' => $rows3261('site'), 'campaigns' => $rows3261('campaign'), 'days' => range(100, 130),
    'contact_clicks' => ['calls' => 5, 'emails' => 6, 'whatsapp' => 7]], 'enquiries' => ['forms' => $rows3261('form'), 'pages' => $rows3261('path'), 'unanswered' => 4],
    'search' => array_map(fn (int $i): array => ['query' => 'web design brno ' . $i, 'engine' => $i % 2 ? 'bing' : 'google', 'clicks' => $i], range(1, 6)),
    'problems' => array_map(fn (int $i): array => ['name' => 'Problem ' . $i, 'info' => 'The certificate expires in 12 days – renew it', 'state' => 'chyba'], range(1, 10)),
    'work' => ['pages_published' => array_fill(0, 20, ['title' => 'Spring offer for new clients', 'date' => '2026-09-05T10:00:00+02:00']), 'pages_published_count' => 40,
        'news_published' => array_fill(0, 20, ['title' => 'We have moved to a new office', 'date' => '2026-09-05T10:00:00+02:00']), 'news_published_count' => 40],
    'uptime' => ['percent' => 99.5, 'checks' => 8900, 'failed' => 40, 'days' => array_map(fn (int $i): array => UptimeStrip::day(sprintf('2026-09-%02d', $i), 288, $i % 3), range(1, 30))],
    'care' => ['attention' => array_fill_keys(array_keys(ReportSummary::ATTENTION_EVENTS), 2)], 'open' => ['requests' => 3, 'staged_files' => 2]]);
$fullMail3261 = MonthlyReport::render($full3261, $reportSettings(['agency_name' => 'Studio', 'agency_url' => 'https://studio.example']), 'https://example.com', $full3261);
check('3.26.1 (N3261-5): many rows everywhere – the HTML stays under the budget (Gmail clips at ~102 KB) with the button to the full report and the footer; the text part keeps every row', [
    strlen($fullMail3261['html']) <= MonthlyReport::EMAIL_BUDGET, str_contains($fullMail3261['html'], 'Open the full report'), str_contains($fullMail3261['html'], 'Sent once a month by the website'),
    substr_count($fullMail3261['text'], '/services/web-design-for-small-business-'), str_contains($fullMail3261['html'], 'web-design-for-small-business-1<'), str_contains($fullMail3261['html'], 'web-design-for-small-business-5<')],
    [true, true, true, 25, true, false]);

// 3.27 (A): tasks and follow-ups (Core\Tasks, migration 0113) – the pure parts; the database flows are in tools/test.sh
$t327Now = new DateTimeImmutable('2026-11-02 14:30:00', Kaleta\Core\Clock::zone());
$t327Bad = function (string $value) use ($t327Now): string {
    try {
        Kaleta\Core\Tasks::parseMoment($value, $t327Now);

        return 'accepted';
    } catch (DomainException) {
        return 'refused';
    }
};
check('3.27: Tasks::parseMoment – dates at 9:00, times, ISO with an offset to the site clock, today/tomorrow, +days/weeks at 9:00, +hours/minutes from now, none; nonsense refused', [
    Kaleta\Core\Tasks::parseMoment('2026-12-01', $t327Now), Kaleta\Core\Tasks::parseMoment('2026-12-01 15:45', $t327Now), Kaleta\Core\Tasks::parseMoment('2026-12-01T15:45:00', $t327Now),
    Kaleta\Core\Tasks::parseMoment(' Tomorrow ', $t327Now), Kaleta\Core\Tasks::parseMoment('today', $t327Now), Kaleta\Core\Tasks::parseMoment('+3 days', $t327Now),
    Kaleta\Core\Tasks::parseMoment('1 week', $t327Now), Kaleta\Core\Tasks::parseMoment('+2 hours', $t327Now), Kaleta\Core\Tasks::parseMoment('+30 minutes', $t327Now),
    Kaleta\Core\Tasks::parseMoment('', $t327Now), Kaleta\Core\Tasks::parseMoment('none', $t327Now),
    Kaleta\Core\Tasks::parseMoment('2026-12-01T14:45:00Z', $t327Now) === Kaleta\Core\Clock::toDb(new DateTimeImmutable('2026-12-01 14:45:00', new DateTimeZone('UTC'))),
    $t327Bad('2026-02-30'), $t327Bad('next friday'), $t327Bad('2026-12-01 25:99'), $t327Bad('-3 days'), $t327Bad('+3 days; DROP TABLE')],
    ['2026-12-01 09:00:00', '2026-12-01 15:45:00', '2026-12-01 15:45:00', '2026-11-03 09:00:00', '2026-11-02 09:00:00', '2026-11-05 09:00:00', '2026-11-09 09:00:00',
        '2026-11-02 16:30:00', '2026-11-02 15:00:00', null, null, true, 'refused', 'refused', 'refused', 'refused', 'refused']);
check('3.27: Tasks::group by the day of the due moment – overdue, today, upcoming (7 days), later (further or none); cleanTarget takes only enquiry|page|item:<id>', [
    Kaleta\Core\Tasks::group('2026-11-01 23:59:00', '2026-11-02'), Kaleta\Core\Tasks::group('2026-11-02 00:00:00', '2026-11-02'), Kaleta\Core\Tasks::group('2026-11-02 23:00:00', '2026-11-02'),
    Kaleta\Core\Tasks::group('2026-11-03 09:00:00', '2026-11-02'), Kaleta\Core\Tasks::group('2026-11-09 18:00:00', '2026-11-02'), Kaleta\Core\Tasks::group('2026-11-10 08:00:00', '2026-11-02'),
    Kaleta\Core\Tasks::group(null, '2026-11-02'),
    array_map(Kaleta\Core\Tasks::cleanTarget(...), ['enquiry:12', ' page:5 ', 'item:9', 'page:0', 'task:3', 'news:4', 'page:5x', '', null, 12])],
    ['overdue', 'today', 'today', 'upcoming', 'upcoming', 'later', 'later', ['enquiry:12', 'page:5', 'item:9', null, null, null, null, null, null, null]]);
$t327Schema = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
$t327Migration = (string) @file_get_contents(KALETA_SYSTEM . '/sql/migrace/0113-tasks.sql');
preg_match('/CREATE TABLE ka_tasks \((.+?)\) ENGINE/s', $t327Schema, $t327Table);
preg_match('/CREATE TABLE ka_tasks \((.+?)\) ENGINE/s', $t327Migration, $t327MigTable);
$t327Columns = fn (string $sql): array => array_values(array_filter(array_map(fn (string $l): string => preg_match('/^\s*([a-z_]+)\s+(INT|VARCHAR|DATETIME)/', $l, $m) ? $m[1] : '', explode("\n", $sql))));
check('3.27: ka_tasks in schema.sql and migration 0113 alike (columns, the three keys), in the names map, its moments in TimeZoneConversion::INSTANTS, DB version 113', [
    $t327Columns($t327Table[1] ?? ''), $t327Columns($t327Table[1] ?? '') === $t327Columns($t327MigTable[1] ?? ''),
    preg_match_all('/KEY ix_tasks_(assignee \(assignee_id, done_at, due_at\)|target \(target\)|due \(done_at, notified_at\))/', $t327Table[1] ?? ''), Kaleta\Core\Names::english('tasks'),
    Kaleta\Core\TimeZoneConversion::INSTANTS['tasks'] ?? null, KALETA_DB_VERSION >= 113],
    [['id', 'title', 'target', 'assignee_id', 'created_by', 'due_at', 'remind_at', 'notified_at', 'done_at', 'done_by', 'request_id', 'created_at', 'updated_at'], true, 3, 'tasks',
        ['due_at', 'remind_at', 'notified_at', 'done_at', 'created_at', 'updated_at'], true]);
$t327Due = Kaleta\Core\Inbox::mail($reportSettings([]), ['jazyk' => 'en', 'register' => ''], ['kind' => 'task_due', 'actor_name' => 'Jana', 'excerpt' => 'Call back about the windows'],
    ['kind' => 'enquiry', 'id' => 12, 'title' => 'Contact', 'path' => ''], 'https://x/1', 'https://x/2');
$t327Self = Kaleta\Core\Inbox::mail($reportSettings([]), ['jazyk' => 'cs', 'register' => ''], ['kind' => 'task_due', 'actor_name' => '', 'excerpt' => ''],
    ['kind' => 'task', 'id' => 3, 'title' => 'Review prices', 'path' => ''], 'https://x/1', 'https://x/2');
check('3.27: Inbox – kind task_due, "task:<id>" targets (a task about nothing opens My work), the what, the where and the e-mail subject with the task title', [
    in_array('task_due', Kaleta\Core\Inbox::KINDS, true), Kaleta\Core\Inbox::parse('task:3'), Kaleta\Core\Inbox::parse('task:0'), Kaleta\Core\Inbox::what('task_due', 'Jana'),
    Kaleta\Core\Inbox::where(['kind' => 'task', 'id' => 3, 'title' => 'Review prices']), $t327Due[0], str_contains($t327Due[1], '“Call back about the windows”'), $t327Self[0],
    Kaleta\Core\Inbox::record(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''), 0, 'task_due', 'task:1', null)],
    [true, ['task', 3], null, 'A task is due', 'Task “Review prices”', 'Task due: Call back about the windows', true, 'Úkol na řadě: Úkol „Review prices“', null]);
check('3.27: the tools – list_tasks (read), save_task and complete_task (write, inside the site, never clearing the page cache), English on both sides, in the work set, never in the essentials', [
    Kaleta\Mcp\Catalog::TOOLS['list_tasks'] ?? null, Kaleta\Mcp\Catalog::TOOLS['save_task'] ?? null, Kaleta\Mcp\Catalog::TOOLS['complete_task'] ?? null,
    array_map(Kaleta\Mcp\Translator::czech(...), ['list_tasks', 'save_task', 'complete_task']), array_values(array_intersect(['list_tasks', 'save_task', 'complete_task'], Kaleta\Mcp\Catalog::ESSENTIALS)),
    array_values(array_intersect(['list_tasks', 'save_task', 'complete_task'], Kaleta\Mcp\Catalog::WORK)), Kaleta\Mcp\Catalog::isSiteNeutral('save_task'), Kaleta\Mcp\Catalog::isSiteNeutral('complete_task'),
    Kaleta\Mcp\Catalog::isIdempotent('complete_task'), Kaleta\Mcp\Catalog::isIdempotent('save_task'), Kaleta\Mcp\Catalog::isOpenWorld('save_task'), Kaleta\Mcp\Tools::annotations('list_tasks')['readOnlyHint'],
    Kaleta\Mcp\Catalog::allows('drafts', 'save_task'), Kaleta\Mcp\Catalog::allows('read', 'list_tasks')],
    [['read', ''], ['write', ''], ['write', ''], ['list_tasks', 'save_task', 'complete_task'], [], ['list_tasks', 'save_task', 'complete_task'], true, true, true, false, false, true, false, true]);
check('[source-pin] 3.27: the job "tasks" every 5 minutes on visits too, before the mail; ka_tasks never journaled by itself (a personal-data table) – Core\Tasks journals what is not about an enquiry', [
    Kaleta\Core\Scheduler::JOBS['tasks'] ?? null, isset(Kaleta\Core\Scheduler::jobs()['tasks']),
    array_search('tasks', array_keys(Kaleta\Core\Scheduler::JOBS), true) < array_search('mail', array_keys(Kaleta\Core\Scheduler::JOBS), true),
    Kaleta\Core\AgentJournal::journaled('tasks'), in_array('tasks', Kaleta\Core\AgentJournal::PERSONAL_TABLES, true),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Tasks.php'), "\$journal = \$db->journal !== null && !self::personal(\$task['target']);")], // 3.28: nor about a contact
    [[300, 'any', 'Task reminders'], true, true, false, true, true]);
check('[source-pin] 3.27: the hand-over counts open tasks (no section of their own), the processing record names them, the cleanup paths of enquiries, pages and items take them along', [
    Kaleta\Core\Handover::describe(['page' => 0, 'item' => 1, 'enquiry' => 0, 'task' => 4]), Kaleta\Core\Handover::describe(['page' => 2, 'item' => 0, 'enquiry' => 0]),
    Kaleta\Core\Handover::sectionsOf(['page' => 1, 'item' => 0, 'enquiry' => 2, 'task' => 3]),
    substr_count((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Comments.php'), 'Tasks::'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Privacy.php'), "t('Tasks of the team about an enquiry"),
    array_map(fn (string $code): bool => isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['Task reminders']), ['cs', 'de', 'es'])],
    ['collection items: 1, open tasks: 4', 'pages: 2', ['pages', 'enquiries'], 4, true, [true, true, true]]); // 3.28: anonymiseTargets of contacts too
// 3.27 (agent B): the tasks of a record in the admin – the form's dates (quick days, a time, the reminder), the default titles
// (a follow-up is named after the enquiry's form, never after what the customer wrote), the cache and the modules
use Kaleta\Admin\TaskPanel;

$due327 = fn (string $date, string $time, string $quick = ''): string => TaskPanel::due($date, $time, $quick, '2026-11-30');
$timeRefused327 = (function (): string {
    try {
        TaskPanel::due('2026-12-01', '25:00', '', '2026-11-30');
    } catch (\DomainException $e) {
        return 'refused';
    }

    return 'accepted';
})();
check('3.27 TaskPanel::due – a quick day wins over the date and counts from the site\'s day (over a month end), the time applies to either, a time alone is today\'s, nothing is none, a bad time is refused', [
    $due327('', '', 'today'), $due327('2027-01-05', '', 'tomorrow'), $due327('', '14:30', 'week'), $due327('2026-12-24', '08:15'), $due327('2026-12-24', ''), $due327('', '07:00'), $due327('', ''), $due327('', '', 'never'),
    $timeRefused327, Kaleta\Core\Tasks::parseMoment($due327('', '', '3days')), Kaleta\Core\Tasks::parseMoment($due327('2026-12-24', '08:15'))],
    ['2026-11-30', '2026-12-01', '2026-12-07 14:30', '2026-12-24 08:15', '2026-12-24', '2026-11-30 07:00', '', '', 'refused', '2026-12-03 ' . Kaleta\Core\Tasks::DEFAULT_TIME . ':00', '2026-12-24 08:15:00']);
check('3.27 TaskPanel::remindAt / remindChoice – an hour or a day before the due moment, nothing without one; a stored reminder maps back, one set elsewhere is "keep"', [
    TaskPanel::remindAt('2026-12-01 09:00:00', '1h'), TaskPanel::remindAt('2026-12-01 09:00:00', '1d'), TaskPanel::remindAt('2026-12-01 09:00:00', ''), TaskPanel::remindAt(null, '1d'), TaskPanel::remindAt('2026-12-01 09:00:00', 'x'),
    TaskPanel::remindChoice('2026-12-01 09:00:00', '2026-12-01 08:00:00'), TaskPanel::remindChoice('2026-12-01 09:00:00', '2026-11-30 09:00:00'), TaskPanel::remindChoice('2026-12-01 09:00:00', null),
    TaskPanel::remindChoice('2026-12-01 09:00:00', '2026-11-28 10:00:00'), TaskPanel::remindChoice(null, '2026-11-28 10:00:00')],
    ['2026-12-01 08:00:00', '2026-11-30 09:00:00', null, null, null, '1h', '1d', '', 'keep', 'keep']);
$form327 = Kaleta\Core\Language::runWith('en', fn (): array => TaskPanel::fromForm(new Kaleta\Core\Request([], ['title' => ' Call back ', 'assignee_id' => '7', 'due_date' => '2026-12-02', 'due_time' => '10:30', 'remind' => '1d'], ['REQUEST_METHOD' => 'POST']), '2026-11-30'), 'admin-');
$quickForm327 = TaskPanel::fromForm(new Kaleta\Core\Request([], ['title' => 'x', 'assignee_id' => '-3', 'due_date' => '2026-12-24', 'due_quick' => 'tomorrow'], ['REQUEST_METHOD' => 'POST']), '2026-11-30');
check('3.27 TaskPanel::fromForm – the fields of Tasks::create (title, person, due and reminder as DATETIME); a quick day wins over the date; no person = 0 (me)', [$form327, [$quickForm327['assignee_id'], $quickForm327['due_at'], $quickForm327['remind_at']]],
    [['title' => 'Call back', 'assignee_id' => 7, 'due_at' => '2026-12-02 10:30:00', 'remind_at' => '2026-12-01 10:30:00'], [0, '2026-12-01 ' . Kaleta\Core\Tasks::DEFAULT_TIME . ':00', null]]);
check('3.27 default titles – a follow-up names the form (an empty one: Enquiry), a review the record; never longer than a task title', Kaleta\Core\Language::runWith('en', fn (): array => [
    TaskPanel::followUpTitle('Contact form'), TaskPanel::followUpTitle('  '), TaskPanel::reviewTitle('Price list'), mb_strlen(TaskPanel::followUpTitle(str_repeat('x', 400))) <= Kaleta\Core\Tasks::TITLE], 'admin-'),
    ['Follow up: Contact form', 'Follow up: Enquiry', 'Review: Price list', true]);
// no enquiry content in a task title: the follow-up takes the record's title, and the record of an enquiry is read with its form name only
$enquiriesSource327 = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php');
preg_match('/function commentTarget\(\): \?array\s*\{(.*?)\n    \}/s', $enquiriesSource327, $enquiryTarget327);
preg_match('/function followUp\(.*?\n    \}/s', (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/TaskActions.php'), $followUp327);
check('[source-pin] 3.27 no enquiry content in a follow-up: the enquiry is read as idp and form name only, its task target reuses it, the title is followUpTitle(record title)', [
    preg_match("/SELECT idp, formular FROM \{poptavky\}/", $enquiryTarget327[1] ?? '') === 1, preg_match('/\b(data|email|poznamka|navrh_odpovedi)\b/', $enquiryTarget327[1] ?? 'data') === 1,
    str_contains($enquiriesSource327, '$record = $this->commentTarget();'), str_contains($followUp327[0] ?? '', "TaskPanel::followUpTitle(\$record['title'])"),
    str_contains($followUp327[0] ?? '', "'assignee_id' => \$me")],
    [true, false, true, true, true]);
$t327Post = fn (string $module): bool => Kaleta\Admin\Kernel::clearsPageCache(new Kaleta\Core\Request(['module' => $module, 'action' => 'task'], ['op' => 'add'], ['REQUEST_METHOD' => 'POST']));
check('3.27 tasks never clear the page cache – enquiries, pages and collections; the three modules share Admin\TaskActions; every quick day, follow-up and snooze has a label', [
    $t327Post('enquiries'), $t327Post('pages'), $t327Post('collections'),
    array_map(fn (string $class): bool => in_array(Kaleta\Admin\TaskActions::class, class_uses($class), true), [Kaleta\Admin\Modules\Enquiries::class, Kaleta\Admin\Modules\Pages::class, Kaleta\Admin\Modules\Collections::class]),
    array_diff(array_keys(TaskPanel::QUICK + TaskPanel::FOLLOW_UP + Kaleta\Core\Tasks::SNOOZE), array_keys(TaskPanel::DAY_LABELS)), array_keys(TaskPanel::REMIND) === array_keys(TaskPanel::REMIND_LABELS)],
    [false, false, false, [true, true, true], [], true]);

// N327-4: moments to the minute – "+2 hours" from a time with seconds and an ISO time with seconds drop the seconds, so the same
// moment saved again from the admin's edit form (HH:MM) is no new moment and never notifies a second time
$n3274Now = new DateTimeImmutable('2026-11-02 14:30:27', Kaleta\Core\Clock::zone());
check('N327-4: Tasks::parseMoment drops seconds (relative hours and minutes, ISO with seconds)', [
    Kaleta\Core\Tasks::parseMoment('+2 hours', $n3274Now), Kaleta\Core\Tasks::parseMoment('+30 minutes', $n3274Now), Kaleta\Core\Tasks::parseMoment('2026-12-01T15:45:59', $n3274Now),
    Kaleta\Core\Tasks::parseMoment('2026-12-01 15:45', $n3274Now)],
    ['2026-11-02 16:30:00', '2026-11-02 15:00:00', '2026-12-01 15:45:00', '2026-12-01 15:45:00']);

// 3.27: tasks nobody has (handed to nobody, a removed or blocked account) are never lost – My work → Team lists them with
// "Reassign to…" (MyWork::actionReassign, site-neutral, save_task in the parity map) and list_tasks takes assignee:none
$n327rView = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/my_work/team.php');
$n327rTools = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/TaskTools.php');
$n327rStrings = ['Not assigned', 'Was for', 'Reassign to…', 'Open tasks nobody active has – handed to nobody, or left on a removed or blocked account. Give each to a colleague; they are notified.',
    'Nobody can be given this task now – what it is about is gone, in the trash or switched off.', 'This task is no longer waiting for someone.', 'Choose who takes the task.',
    'The task was given to %s – they are notified.', 'The task is yours now.'];
check('[source-pin] 3.27: tasks nobody has – the Team tab lists them with Reassign to… (site-neutral, CSRF form, its own people), list_tasks assignee:none for administrators only, translated', [
    Kaleta\Admin\Kernel::clearsPageCache(new Kaleta\Core\Request(['module' => 'my_work', 'action' => 'reassign'], ['id' => '1', 'assignee_id' => '2'], ['REQUEST_METHOD' => 'POST'])),
    method_exists(Kaleta\Admin\Modules\MyWork::class, 'actionReassign'), Kaleta\Core\Tasks::hasActiveAssignee(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''), null),
    str_contains($n327rView, 'id="unassigned"') && str_contains($n327rView, '<a href="#unassigned">') && str_contains($n327rView, '<?= $csrf ?><input type="hidden" name="id"'),
    str_contains($n327rTools, 'Tasks::unassigned($db, $status, $limit)') && substr_count($n327rTools, "'Only an administrator may list the tasks of someone else – use filter mine.'") === 2,
    str_contains(array_column(Kaleta\Mcp\Tools::definitions(), null, 'name')['list_tasks']['inputSchema']['properties']['filter']['description'] ?? '', 'assignee:none'),
    array_map(fn (string $code): array => array_values(array_diff($n327rStrings, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [false, true, false, true, true, true, [[], [], []]]);

// 3.28 (agent A): contacts and companies – the customer memory of the site (Core\Contacts, migration 0114)
$k328 = Kaleta\Core\Contacts::class;
check('3.28: an address is keyed normalised (trimmed, lower case); the domain, the free-mail rule (a list and the country families) and the company domain', [
    $k328::normalise('  Jana.Nova@Firma.CZ '), $k328::normalise('not an address'), $k328::domainOf('x@Acme.Example.com'), $k328::domainOf(''),
    array_map($k328::isFreeMail(...), ['gmail.com', 'seznam.cz', 'yahoo.co.uk', 'hotmail.de', 'outlook.cz', 'gmx.at', 'acme.cz', 'gmail.acme.cz', 'mygmail.com', '']),
    $k328::companyDomain('jan@gmail.com'), $k328::companyDomain('jan@acme.cz'), $k328::companyDomain('jan@post.cz'),
    $k328::suggestCompany(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''), 'jan@gmail.com'),
], ['jana.nova@firma.cz', null, 'acme.example.com', '', [true, true, true, true, true, true, false, false, false, true], '', 'acme.cz', '', null]);
check('3.28: a company domain is cleaned (no scheme, www, path or @), anything else is no domain', array_map($k328::cleanDomain(...), ['https://www.Acme.cz/kontakt', '@acme.cz', 'acme', 'a b.cz', 'sub.acme.co.uk', '']),
    ['acme.cz', 'acme.cz', '', '', 'sub.acme.co.uk', '']);
check('3.28: the name and phone of a contact come from the form\'s fields – a name field, a phone; never a message, an attachment or the address', [
    $k328::fromFields([['Name', 'Jana Nová'], ['E-mail', 'jana@x.cz'], ['Telefon', '+420 777 123 456'], ['Message', "Hello,\nplease call"]], 'jana@x.cz'),
    $k328::fromFields([['Message', 'Call me at 777 123 456 please'], ['CV', 'cv.pdf', '2026/11/x.pdf']]),
    $k328::fromFields([['Company name', 'Acme'], ['Jméno a příjmení', 'Petr Dvořák'], ['Mobil', '777123456']]),
    $k328::fromFields([['Name', str_repeat('x', 120)]]), $k328::fromFields(['bad', [1]]),
], [['name' => 'Jana Nová', 'phone' => '+420 777 123 456'], ['name' => '', 'phone' => ''], ['name' => 'Petr Dvořák', 'phone' => '777123456'], ['name' => '', 'phone' => ''], ['name' => '', 'phone' => '']]);
$k328Src = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Contacts.php');
check('[source-pin] 3.28: two different addresses are never merged by Kaleta – link() finds the contact by the normalised address or a record of the same address a person merged; only merge() moves another contact\'s records', [
    str_contains($k328Src, '$contact = self::findByEmail($db, $norm) ?? self::mergedInto($db, $app->settings(), $norm, $kind, $id);'),
    str_contains($k328Src, "' AS email, contact_id FROM {' . \$table . '} WHERE ' . \$column . ' = ? AND contact_id IS NOT NULL'")
        && str_contains($k328Src, "self::normalise((string) \$r['email']) === \$norm ? self::find("),
    substr_count($k328Src, 'SET contact_id = ? WHERE contact_id = ?'), substr_count($k328Src, 'SET contact_id = ? WHERE ' . "' . \$key . ' = ? AND contact_id IS NULL"),
    str_contains($k328Src, "\$kind === 'subscriber' && !\$record['confirmed']"), (function () use ($k328): string { // 3.36 T3: a database it cannot reach is an error, never "no open deal"
        try {
            return var_export($k328::hasOpenDeal(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''), 1), true);
        } catch (PDOException) {
            return 'thrown';
        }
    })(),
], [true, true, 3, 1, true, 'thrown']); // 3.31: merge() moves the deals too
check('[source-pin] 3.28 (N328-3): linking a new address scans no table until the first merge or change of a linked address (contacts_merged_at); merge() and an address change set it, and it is bookkeeping that undo never resets', [
    str_contains($k328Src, "if (\$s->get('contacts_merged_at') === '') {\n            return null;"), substr_count($k328Src, 'self::markMerged($app->settings());'),
    array_key_exists('contacts_merged_at', Kaleta\Core\Settings::DEFAULTS), preg_match('/_(check|time|checked|seen|ts|at)$/', 'contacts_merged_at'),
], [true, 2, true, 1]);
check('[source-pin] 3.28 (N328-7): the retention counts the team\'s comments and tasks about a customer as activity – an open task is never blanked by it, and the processing record and the setting say so', [
    str_contains($k328Src, '$team = self::teamActivity($db, $id);'), str_contains($k328Src, 'self::touch($db, $id, min($team, Clock::now()));'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Privacy.php'), 'logged contact, comment or task).'),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/enquiries/list.php'), 'logged contact, comment or task for this long')], [true, true, true, true]);
check('[source-pin] 3.28: the timeline is bounded – the default and the most, every source limited, newest first; get_contact shows at most 50 entries, each text at most 200 characters', [
    $k328::TIMELINE, $k328::TIMELINE_MAX, str_contains($k328Src, '$limit = max(1, min(self::TIMELINE_MAX, $limit));'), substr_count($k328Src, "LIMIT ' . \$limit, [\$id]"),
    str_contains($k328Src, "return array_slice(array_map("), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/ContactTools.php'), "\$limit = max(0, min(50, (int) (\$a['timeline'] ?? 20)));"),
], [100, 500, true, 2, true, true]);
$k328Schema = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
$k328Migration = (string) @file_get_contents(KALETA_SYSTEM . '/sql/migrace/0114-contacts.sql');
$k328Columns = fn (string $sql, string $table): array => preg_match('/CREATE TABLE ' . $table . ' \((.+?)\) ENGINE/s', $sql, $m) ? array_values(array_filter(array_map(fn (string $l): string => preg_match('/^\s*([a-z_]+)\s+(INT|VARCHAR|DATETIME|TEXT)/', $l, $c) ? $c[1] : '', explode("\n", $m[1])))) : [];
check('[source-pin] 3.28: ka_contacts, ka_companies and ka_contact_activities alike in schema.sql and migration 0114, contact_id on enquiries, bookings and subscribers (indexed), the names map, the moments, DB version 114', [
    // 3.38 review M3: email_by_agent_at came with migration 0124; 3.40: price_list_id (contacts and companies) with migration 0128
    array_values(array_diff($k328Columns($k328Schema, 'ka_contacts'), ['email_by_agent_at', 'price_list_id'])), array_values(array_diff($k328Columns($k328Schema, 'ka_contacts'), ['email_by_agent_at', 'price_list_id'])) === $k328Columns($k328Migration, 'ka_contacts'),
    array_values(array_diff($k328Columns($k328Schema, 'ka_companies'), ['price_list_id'])) === $k328Columns($k328Migration, 'ka_companies') && $k328Columns($k328Schema, 'ka_companies') !== [],
    // 3.35 review L5: merged_email came with migration 0121
    array_values(array_diff($k328Columns($k328Schema, 'ka_contact_activities'), ['merged_email'])) === $k328Columns($k328Migration, 'ka_contact_activities') && $k328Columns($k328Schema, 'ka_contact_activities') !== [],
    str_contains($k328Schema, 'UNIQUE KEY ux_contacts_email (email_norm)'), preg_match_all('/ALTER TABLE ka_(poptavky|bookings|odberatele) ADD COLUMN contact_id INT UNSIGNED NULL DEFAULT NULL, ADD KEY ix_\1_contact \(contact_id\);/', $k328Migration),
    preg_match_all('/KEY ix_(poptavky|bookings|odberatele)_contact \(contact_id\)/', $k328Schema), str_contains($k328Migration, 'FOREIGN KEY'),
    array_map(Kaleta\Core\Names::english(...), ['contacts', 'companies', 'contact_activities']), Kaleta\Core\TimeZoneConversion::INSTANTS['contacts'] ?? null, KALETA_DB_VERSION >= 114,
    Kaleta\Core\Settings::DEFAULTS['contacts_months'] ?? null, str_contains($k328Migration, "SELECT 'contacts_months', e.hodnota FROM (SELECT hodnota FROM ka_nastaveni WHERE promenna = 'enquiries_months') AS e;"),
], [['id', 'email', 'email_norm', 'name', 'phone', 'company_id', 'owner_id', 'language', 'source', 'summary', 'first_seen_at', 'last_activity_at', 'created_at', 'updated_at', 'anonymised_at'],
    true, true, true, true, 3, 3, false, ['contacts', 'companies', 'contact_activities'], ['first_seen_at', 'last_activity_at', 'created_at', 'updated_at', 'anonymised_at', 'email_by_agent_at'], true, '24', true]);
check('3.28: comments, tasks and notifications take customers and companies (section Customers); the hand-over counts them; never journaled', [
    Kaleta\Core\Comments::parseTarget('contact:4'), Kaleta\Core\Comments::parseTarget('company:2'), Kaleta\Core\Comments::parseTarget('contact:0'), Kaleta\Core\Comments::contact(7), Kaleta\Core\Comments::company(8),
    array_map(Kaleta\Core\Tasks::cleanTarget(...), ['contact:3', 'company:9', 'contact:x', 'deal:x']), Kaleta\Core\Inbox::parse('contact:3'), Kaleta\Core\Inbox::parse('company:2'),
    Kaleta\Core\Tasks::personal('contact:3'), Kaleta\Core\Tasks::personal('company:3'), Kaleta\Core\Tasks::personal('enquiry:1'), Kaleta\Core\Tasks::personal(null),
    Kaleta\Core\Handover::sectionsOf(['page' => 0, 'item' => 0, 'enquiry' => 1, 'contact' => 2, 'company' => 0]), Kaleta\Core\Handover::sectionsOf(['page' => 0, 'item' => 0, 'enquiry' => 0, 'company' => 1]),
    Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Handover::describe(['page' => 0, 'item' => 0, 'enquiry' => 0, 'contact' => 3, 'company' => 1]), 'admin-'),
    array_map(fn (string $t): bool => in_array($t, Kaleta\Core\AgentJournal::PERSONAL_TABLES, true) && !Kaleta\Core\AgentJournal::journaled($t), ['contacts', 'companies', 'contact_activities']),
    Kaleta\Core\Language::runWith('en', fn (): string => Kaleta\Core\Inbox::where(['kind' => 'contact', 'id' => 4, 'title' => 'Jana Nová']), 'admin-'),
], [['type' => 'contact', 'id' => 4], ['type' => 'company', 'id' => 2], null, 'contact:7', 'company:8', ['contact:3', 'company:9', null, null], ['contact', 3], ['company', 2],
    true, false, true, false, ['enquiries', 'contacts'], ['contacts'], 'customers: 3, companies: 1', [true, true, true], 'Customer #4']);
$k328Mail = Kaleta\Core\Assignment::mail($reportSettings([]), ['jazyk' => 'en', 'register' => ''], 'contact', ['id' => 12, 'title' => '#12', 'path' => ''], 'Jana', 'https://x/1');
check('[source-pin] 3.28: the assignment e-mail of a customer names its number only – never the customer\'s name', [$k328Mail[0], str_contains($k328Mail[1], 'customer #12'), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Assignment.php'), "['id' => \$id, 'title' => '#' . \$id, 'path' => Contacts::adminPath(\$id)]")],
    ['You are responsible for the customer #12', true, true]);
$k328Tools = ['list_contacts', 'get_contact', 'save_contact', 'log_activity', 'merge_contacts'];
$k328Defs = array_column(Kaleta\Mcp\Tools::definitions(), null, 'name');
check('[source-pin] 3.28: the contact tools – English on both sides, in the work set and never in the essentials, site-neutral, merge destructive with confirm, the descriptions say the changes are not undoable', [
    array_map(fn (string $t): ?array => Kaleta\Mcp\Catalog::TOOLS[$t] ?? null, $k328Tools), array_map(Kaleta\Mcp\Translator::czech(...), $k328Tools),
    array_values(array_intersect($k328Tools, Kaleta\Mcp\Catalog::ESSENTIALS)), array_values(array_intersect($k328Tools, Kaleta\Mcp\Catalog::WORK)),
    array_map(Kaleta\Mcp\Catalog::isSiteNeutral(...), ['save_contact', 'log_activity', 'merge_contacts']), Kaleta\Mcp\Discovery::runner('merge_contacts'), Kaleta\Mcp\Catalog::allows('drafts', 'save_contact'),
    $k328Defs['merge_contacts']['inputSchema']['required'] ?? null, array_map(fn (string $t): bool => str_contains($k328Defs[$t]['description'] ?? '', 'NOT UNDOABLE'), ['save_contact', 'log_activity', 'merge_contacts']),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/ContactTools.php'), "if ((\$a['confirm'] ?? false) !== true) {"),
], [[['read', ''], ['read', ''], ['write', ''], ['write', ''], ['destructive', '']], $k328Tools, [], $k328Tools, [true, true, true], 'run_destructive_tool', false, ['into', 'from', 'confirm'], [true, true, true], true]);
$k328Strings = ['Give the contact an e-mail address, a name or a phone number.', 'A contact with this e-mail address already exists (#%d) – open it, or merge the two.', 'Merged with the contact #%d (%s).',
    'First contact on %s through the form “%s”.', 'Customer relations', 'customers: %d', 'companies: %d', 'Customer #%s', 'Call', 'Meeting', 'Anonymise customers inactive for',
    'You are responsible for the customer #%s', 'This person cannot open Customers (or the account is blocked) – choose someone who can.', 'Customer records: %d'];
check('3.28: the texts of contacts are in every admin language; the du overlay addresses the person informally', [
    array_map(fn (string $code): array => array_values(array_diff($k328Strings, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es']),
    (require KALETA_SYSTEM . '/jazyky/admin-de-du.php')['You can delete only your own entries.'] ?? null,
], [[[], [], []], 'Du kannst nur deine eigenen Einträge löschen.']);

// 3.28 (agent B): Customers – the module (Workspace, right after Enquiries, core: no extension, site-neutral), the import's
// columns and rows, a CSV cell never runs as a formula, the last-activity filter, an icon and a label for every kind of the
// timeline, the comments and tasks of a contact or a company, the palette search, the box on an enquiry and a booking
$b328 = Kaleta\Admin\Modules\Contacts::class;
$b328Icons = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/icons.php');
$b328Modules = array_map(fn (string $c): string => $c::IDENT, Kaleta\Admin\Kernel::MODULES);
check('[source-pin] 3.28 (B): Customers – IDENT contacts (= Core\Contacts::SECTION), NAME Customers in Workspace right after Enquiries, core (no extension, not admin-only), site-neutral, comments and tasks', [
    $b328::IDENT, Kaleta\Core\Contacts::SECTION, $b328::NAME, $b328::GROUP, $b328::EXTENSION, $b328::ADMIN_ONLY, array_search('contacts', $b328Modules, true) - array_search('enquiries', $b328Modules, true),
    Kaleta\Admin\Kernel::clearsPageCache(new Kaleta\Core\Request(['module' => 'contacts', 'action' => 'save'], ['id' => '1'], ['REQUEST_METHOD' => 'POST'])),
    Kaleta\Admin\Kernel::clearsPageCache(new Kaleta\Core\Request(['module' => 'contacts', 'action' => 'merge'], [], ['REQUEST_METHOD' => 'POST'])),
    [in_array(Kaleta\Admin\CommentActions::class, class_uses($b328), true), in_array(Kaleta\Admin\TaskActions::class, class_uses($b328), true)],
    str_contains($b328Icons, "'" . $b328::ICON . "' =>"), in_array('contacts', Kaleta\Admin\Modules\Roles::PRESETS['client'][3], true), in_array('contacts', Kaleta\Admin\Modules\Roles::PRESETS['office'][3], true),
    array_map(fn (string $code): string => (require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['Customers'] ?? '', ['cs', 'de', 'es'])],
    ['contacts', 'contacts', 'Customers', 'Workspace', '', false, 1, false, false, [true, true], true, true, true, ['Zákazníci', 'Kunden', 'Clientes']]);
check('3.28 (B): an import reads the columns e-mail, name, phone and company by their usual names (Czech, German, Spanish, with diacritics); a row is one line each', [
    $b328::importColumns(['E-mail', 'Jméno', 'Telefon', 'Firma']), $b328::importColumns(['Name', 'Company', 'Email Address', 'Phone number', 'Notes']),
    $b328::importColumns(['Correo electrónico', 'Nombre', 'Teléfono', 'Empresa']), $b328::importColumns(['Vorname', 'Unternehmen']),
    $b328::importRow(["  jana@firma.cz ", "Jana\n Nováková", '+420 777 123 456', ''], ['email' => 0, 'name' => 1, 'phone' => 2]),
    $b328::importRow(['x'], ['email' => 0, 'company' => 3])],
    [['email' => 0, 'name' => 1, 'phone' => 2, 'company' => 3], ['name' => 0, 'company' => 1, 'email' => 2, 'phone' => 3], ['email' => 0, 'name' => 1, 'phone' => 2, 'company' => 3], ['company' => 1],
    ['email' => 'jana@firma.cz', 'name' => 'Jana Nováková', 'phone' => '+420 777 123 456', 'company' => ''], ['email' => 'x', 'name' => '', 'phone' => '', 'company' => '']]);
check('[source-pin] 3.28 (N328-5): the daily clean-up of personal data also removes customers CSV uploads nobody confirmed within a day', [
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/Notifications.php'), '\\Kaleta\\Admin\\Modules\\Contacts::sweepImports();'),
    (new ReflectionMethod($b328, 'sweepImports'))->isPublic()], [true, true]);
check('3.28 (B): a CSV cell starting with = + - @ gets an apostrophe; the last-activity filter (active since / quiet before); unknown keys are no filter', [
    $b328::csvSafe(['=HYPERLINK("x")', '+420 777', '-1', '@sum', 'Jana', '']), $b328::activityFilter('active30', '2026-11-03 10:00:00'), $b328::activityFilter('quiet365', '2026-11-03 10:00:00'),
    $b328::activityFilter('nonsense'), array_keys($b328::SORT_LABELS) === array_keys(Kaleta\Core\Contacts::SORTS),
    // N328-4: an exported row imports as it was – the apostrophe of csvSafe comes off again (a phone "+420 …" is a phone)
    $b328::importRow($b328::csvSafe(['jana@firma.cz', '-Jana-', '+420 777 123 456', "'Quoted"]), ['email' => 0, 'name' => 1, 'phone' => 2, 'company' => 3])],
    [["'=HYPERLINK(\"x\")", "'+420 777", "'-1", "'@sum", 'Jana', ''], ['active_since' => '2026-10-04 10:00:00'], ['inactive_before' => '2025-11-03 10:00:00'], [], true,
        ['email' => 'jana@firma.cz', 'name' => '-Jana-', 'phone' => '+420 777 123 456', 'company' => "'Quoted"]]);
$b328Kinds = ['enquiry', 'reply', 'booking', 'subscription', 'comment', 'task', ...array_keys(Kaleta\Core\Contacts::ACTIVITY_KINDS)];
check('[source-pin] 3.28 (B): every kind of the timeline has an icon in views/admin/icons.php and a translated label', [
    array_values(array_diff($b328Kinds, array_keys($b328::KINDS))),
    array_values(array_filter($b328::KINDS, fn (array $k): bool => !str_contains($b328Icons, "'" . $k[0] . "' =>"))),
    array_map(fn (string $code): array => array_values(array_diff(array_column(array_diff_key($b328::KINDS, ['call' => 1, 'meeting' => 1]), 1), array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'), ['E-mail'])), ['cs', 'de', 'es'])], // E-mail is the same word in Czech
    [[], [], [[], [], []]]);
$b328Layout = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/layout.php');
$b328Script = (string) file_get_contents(KALETA_ROOT . '/image/admin.js');
$b328Box = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/contact_box.php');
check('[source-pin] 3.28 (B): the palette searches customers on the server only for whoever has Customers; the box on an enquiry and a booking; Link to a contact posts with the form token', [
    str_contains($b328Layout, "isset(\$modules['contacts']) ? ' data-contacts=\"'"), str_contains($b328Script, "palette.getAttribute('data-contacts')"), str_contains($b328Script, "T('customer')"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/enquiries/detail.php'), "render('admin/contact_box'"), str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/bookings/detail.php'), "render('admin/contact_box'"),
    str_contains($b328Box, '<?= $csrf ?><input type="hidden" name="kind"'),
    array_map(fn (string $code): string => (string) json_decode((string) (preg_match('/^\t"customer": ("[^"]*")/m', (string) file_get_contents(KALETA_ROOT . '/image/jazyky/admin-' . $code . '.js'), $m) ? $m[1] : '""')), ['cs', 'de', 'es'])],
    [true, true, true, true, true, true, ['zákazník', 'Kunde', 'cliente']]);

// 3.28 (D-W12): off-site backups encrypted at rest – BackupEncryption (sodium secretstream or OpenSSL AES-256-GCM, chunked,
// versioned header) under the recovery key (RecoveryKey: outside the database, confirmed before any copy leaves the server)
$c328Dir = sys_get_temp_dir() . '/kaleta-c328-' . bin2hex(random_bytes(4));
mkdir($c328Dir);
$c328Key = random_bytes(32);
$c328Error = function (callable $run): string {
    try {
        $run();

        return 'no error';
    } catch (RuntimeException $e) {
        return $e->getMessage();
    }
};
$c328Trip = function (int $algorithm, string $plain, int $chunk) use ($c328Dir, $c328Key): array {
    file_put_contents($c328Dir . '/in', $plain);
    @unlink($c328Dir . '/out');
    Kaleta\Core\BackupEncryption::encryptFile($c328Dir . '/in', $c328Dir . '/enc', $c328Key, $algorithm, $chunk);
    $cipher = (string) file_get_contents($c328Dir . '/enc');
    Kaleta\Core\BackupEncryption::decryptFile($c328Dir . '/enc', $c328Dir . '/out', $c328Key);

    return [file_get_contents($c328Dir . '/out') === $plain, $plain === '' || !str_contains($cipher, substr($plain, 0, 64)), Kaleta\Core\BackupEncryption::inspect($c328Dir . '/enc')['algorithm'],
        Kaleta\Core\BackupEncryption::isEncrypted($c328Dir . '/enc'), is_file($c328Dir . '/enc.part') || is_file($c328Dir . '/out.part')];
};
$c328Plain = "-- Kaleta 3.28 - database backup\n" . str_repeat("INSERT INTO `ka_contacts` VALUES (1,'jana@example.com');\n", 300) . random_bytes(777);
$c328Results = [];
foreach ([Kaleta\Core\BackupEncryption::SODIUM, Kaleta\Core\BackupEncryption::OPENSSL] as $c328Algorithm) {
    // several chunks, an exact multiple of the chunk size (an empty final chunk), an empty file
    $c328Results[$c328Algorithm] = [$c328Trip($c328Algorithm, $c328Plain, 1024), $c328Trip($c328Algorithm, str_repeat('x', 2048), 1024), $c328Trip($c328Algorithm, '', 1024)];
    $c328Trip($c328Algorithm, $c328Plain, 1024);
    $c328Enc = (string) file_get_contents($c328Dir . '/enc');
    $c328Out = $c328Dir . '/restored';
    $c328Decrypt = fn (string $bytes, ?string $key = null): string => $c328Error(function () use ($c328Dir, $bytes, $key, $c328Key, $c328Out): void {
        file_put_contents($c328Dir . '/case', $bytes);
        Kaleta\Core\BackupEncryption::decryptFile($c328Dir . '/case', $c328Out, $key ?? $c328Key);
    });
    $c328Flipped = $c328Enc;
    $c328Flipped[intdiv(strlen($c328Flipped), 2)] = chr(ord($c328Flipped[intdiv(strlen($c328Flipped), 2)]) ^ 1);
    $c328NewVersion = $c328Enc;
    $c328NewVersion[8] = chr(2);
    $c328Chunk = $c328Enc;
    $c328Chunk[12] = chr(ord($c328Chunk[12]) ^ 1); // the chunk size in the header: authenticated, so the first chunk fails
    // the last chunk cut away completely: every remaining chunk is genuine, only the final one is missing
    $c328LastChunk = $c328Algorithm === Kaleta\Core\BackupEncryption::SODIUM ? 4 + (strlen($c328Plain) % 1024) + 17 : 1 + 4 + (strlen($c328Plain) % 1024) + 16;
    $c328Results[$c328Algorithm][] = [
        str_contains($c328Decrypt($c328Enc, random_bytes(32)), 'does not belong to this backup'),
        str_contains($c328Decrypt(substr($c328Enc, 0, -10)), 'incomplete'),
        str_contains($c328Decrypt(substr($c328Enc, 0, -$c328LastChunk)), 'incomplete'),
        str_contains($c328Decrypt(substr($c328Enc, 0, 20)), 'incomplete'),
        str_contains($c328Decrypt($c328Flipped), 'damaged'),
        str_contains($c328Decrypt($c328Chunk), 'damaged'),
        str_contains($c328Decrypt($c328Enc . 'x'), 'damaged'),
        str_contains($c328Decrypt($c328NewVersion), 'newer version of Kaleta (format 2)'),
        str_contains($c328Decrypt('-- Kaleta 3.28 - database backup'), 'not an encrypted Kaleta backup'),
        str_contains($c328Decrypt(str_replace("\r\n", "\n", $c328Enc)), 'not an encrypted Kaleta backup'), // FTP text mode
        is_file($c328Out) || is_file($c328Out . '.part'), // nothing is left behind by a failed decryption
    ];
}
$c328Formatted = Kaleta\Core\RecoveryKey::format($c328Key);
check('3.28 (D-W12): backup encryption – round trip (several chunks, an exact multiple, empty) with both algorithms; wrong key, cut-off, changed bytes, a newer format and FTP text mode refused, nothing half written', [
    $c328Results, Kaleta\Core\BackupEncryption::keyId($c328Key) === Kaleta\Core\BackupEncryption::inspect($c328Dir . '/enc')['key_id'], strlen(Kaleta\Core\BackupEncryption::keyId($c328Key)),
    Kaleta\Core\BackupEncryption::algorithm(), Kaleta\Core\BackupEncryption::isEncrypted($c328Dir . '/in')],
    [[1 => [[true, true, 1, true, false], [true, true, 1, true, false], [true, true, 1, true, false], [...array_fill(0, 10, true), false]],
        2 => [[true, true, 2, true, false], [true, true, 2, true, false], [true, true, 2, true, false], [...array_fill(0, 10, true), false]]],
        true, 16, Kaleta\Core\BackupEncryption::SODIUM, false]);
// a larger backup streams through with the real chunk size: memory stays near one chunk
file_put_contents($c328Dir . '/big', str_repeat(random_bytes(1024 * 64), 80)); // 5 MB
$c328Memory = memory_get_peak_usage();
Kaleta\Core\BackupEncryption::encryptFile($c328Dir . '/big', $c328Dir . '/big.enc', $c328Key);
Kaleta\Core\BackupEncryption::decryptFile($c328Dir . '/big.enc', $c328Dir . '/big.out', $c328Key);
check('3.28: a 5 MB backup is encrypted and decrypted in chunks (peak memory grows by far less than the file)', [
    hash_file('sha256', $c328Dir . '/big') === hash_file('sha256', $c328Dir . '/big.out'), memory_get_peak_usage() - $c328Memory < 4 * 1048576], [true, true]);

// the recovery key: KBK1 + 13 groups, read back from any case and spacing, outside the database, confirmed by its one-way id
$c328Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($c328Settings, ['backup_key_confirmed' => '']);
$c328File = $c328Dir . '/storage/backup-key.php';
$c328States = [Kaleta\Core\RecoveryKey::state($c328Settings, $c328File)];
Kaleta\Core\RecoveryKey::save($c328Key, $c328File);
$c328States[] = Kaleta\Core\RecoveryKey::state($c328Settings, $c328File);
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($c328Settings, ['backup_key_confirmed' => Kaleta\Core\BackupEncryption::keyId($c328Key) . '|2026-11-03 10:00']);
$c328States[] = Kaleta\Core\RecoveryKey::state($c328Settings, $c328File);
$c328States[] = Kaleta\Core\RecoveryKey::ready($c328Settings, $c328File);
$c328Second = Kaleta\Core\RecoveryKey::create($c328File); // a new key: not confirmed until the administrator saves it
$c328States[] = Kaleta\Core\RecoveryKey::state($c328Settings, $c328File);
$c328Content = (string) file_get_contents($c328File);
check('3.28: the recovery key – format and parse, a file outside the database that prints nothing over the web, states none → unconfirmed → confirmed → a new key unconfirmed', [
    (bool) preg_match('/^KBK1(-[A-Z2-7]{4}){13}$/D', $c328Formatted), Kaleta\Core\RecoveryKey::parse($c328Formatted) === $c328Key,
    Kaleta\Core\RecoveryKey::parse(' ' . strtolower(str_replace('-', ' ', substr($c328Formatted, 5))) . "\n") === $c328Key,
    Kaleta\Core\RecoveryKey::parse(substr($c328Formatted, 0, -1)), Kaleta\Core\RecoveryKey::parse(str_repeat('A', 51) . 'B'), Kaleta\Core\RecoveryKey::parse('KBK1-hello'),
    $c328States, Kaleta\Core\RecoveryKey::load($c328File) === $c328Second, str_starts_with($c328Content, '<?php exit; ?>'), str_contains($c328Content, $c328Formatted),
    Kaleta\Core\RecoveryKey::confirmedAt($c328Settings), str_contains(Kaleta\Core\RecoveryKey::document($c328Key, 'https://example.com/'), $c328Formatted)],
    [true, true, true, null, null, null, ['none', 'unconfirmed', 'confirmed', true, 'unconfirmed'], true, true, false, '2026-11-03 10:00', true]);

clearstatcache();
check('3.28 (N328-8): the key file is closed to others before the key is written into it, and no temporary file stays', [
    fileperms($c328File) & 0007, Kaleta\Core\Vault::configMode() & 0007, glob($c328File . '.kaleta-*') ?: [],
    strpos((string) file_get_contents(KALETA_SYSTEM . '/src/Core/RecoveryKey.php'), '@chmod($temporary') < strpos((string) file_get_contents(KALETA_SYSTEM . '/src/Core/RecoveryKey.php'), '@file_put_contents($temporary')],
    [0, 0, [], true]);

// the encrypted copy uploaded back is a backup of the list, restored only with a key; its texts are translated; the actions
// never clear the page cache; the paused copies alert the owner; the media copy stays as it is (public files)
$c328Strings = ['Off-site copies are paused until the recovery key is saved (Settings → Backups and updates).', 'Show and save the recovery key', 'Make a new recovery key', 'Recovery key',
    'Save this recovery key now, somewhere away from this server.', 'I have saved the recovery key somewhere away from this server', 'Confirm and start the off-site copies',
    'The recovery key does not belong to this backup – check that it was copied in full, or use the key of the site the backup comes from. Nothing was restored.',
    'This backup is encrypted. Enter the recovery key of the site it comes from, then click Restore again. Nothing was restored.',
    'The encrypted backup is damaged or was changed – it cannot be restored. Nothing was restored.', 'Download the recovery key (.txt)', 'Recovery key saved %s.'];
check('[source-pin] 3.28: .enc backups are listed and restored with the key; site-neutral actions; the pause alerts; translated (cs, de, es)', [
    (bool) preg_match('/^kaleta-[0-9a-z-]+\.sql(\.gz)?(\.enc)?$/D', 'kaleta-20261103-101500-auto-a1b2c3d4.sql.gz.enc'), Kaleta\Core\Backup::encrypted('kaleta-1-auto-x.sql.gz.enc'), Kaleta\Core\Backup::encrypted('kaleta-1-auto-x.sql.gz'),
    array_map(fn (string $a): bool => Kaleta\Admin\Kernel::clearsPageCache(new Kaleta\Core\Request(['module' => 'settings', 'action' => $a], [], ['REQUEST_METHOD' => 'POST'])), ['backup_key', 'backup_key_download', 'backup_key_confirm', 'backup_key_new', 'backup_key_use']),
    in_array('backup.copy_paused', Kaleta\Core\Alerts::WARNINGS, true), str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Core/RemoteBackup.php'), "\$put(basename(\$path) . BackupEncryption::EXTENSION, \$encrypted)"),
    array_map(fn (string $code): array => array_values(array_diff($c328Strings, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [true, true, false, [false, false, false, false, false], true, true, [[], [], []]]);
array_map('unlink', array_filter(glob($c328Dir . '/{,storage/}*', GLOB_BRACE) ?: [], 'is_file'));
@rmdir($c328Dir . '/storage');
@rmdir($c328Dir);

