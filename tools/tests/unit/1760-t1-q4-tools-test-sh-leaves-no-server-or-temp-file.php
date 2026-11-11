<?php

declare(strict_types=1);

/* ---------- T1 (Q4): tools/test.sh leaves no server or temp file behind ---------- */
$t4Sh = (string) smoke_test_source();
$t4Trap = substr($t4Sh, (int) strpos($t4Sh, 'cleanup() {'), 1500);
preg_match_all('/& *([A-Z0-9_]+)=\$!/', $t4Sh, $t4Started);
check('T1 Q4: the exit trap kills every background job of the run (each server is one – none started in a subshell), and the fake services log into the work folder', [
    str_contains($t4Trap, 'for pid in $(jobs -p); do kill "$pid"'), str_contains($t4Trap, 'rm -rf "${WORK:?}"'), count(array_unique($t4Started[1])) >= 20,
    (bool) preg_match('/\$\([^)]*php -S/', $t4Sh), str_contains($t4Sh, 'KALETA_FAKE_DIR="$WORK/fake" exec php -S'), str_contains($t4Sh, 'FAKE_LOGS="$WORK/fake/kaleta-fake-$FAKE_PORT"'),
    str_contains($t4Sh, "sys_get_temp_dir();')/kaleta-fake"), str_contains((string) file_get_contents(KALETA_ROOT . '/tools/fake-services.php'), "getenv('KALETA_FAKE_DIR')")],
    [true, true, true, false, true, true, false, true]);
// ---------- 3.32 part A (CRM for daily use): names, My work, Create deal, defaults, glossary ----------
// 3.32 (U27, U41, R2): one helper names a record by who and what; a section the viewer may not open, and every e-mail, keep the number
$a332Row = ['formular' => 'Contact', 'tema' => 'Contact', 'zdroj' => 'stranka:4', 'email' => 'olivia@hartsteel.example', 'data' => json_encode([['Name', 'Olivia Hart'], ['E-mail', 'olivia@hartsteel.example'],
    ['Message', 'We need 40 custom brackets for a new hall in March']])];
$a332Views = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/enquiries/list.php') . (string) file_get_contents(KALETA_SYSTEM . '/views/admin/inbox.php') . (string) file_get_contents(KALETA_SYSTEM . '/views/admin/my_work/list.php');
$a332Texts = ['(no name)', 'Enquiry: %s', 'Deal “%s”', 'Customer “%s”', 'Who'];
check('3.32 (U27/U41): RecordTitle – who (name, else company, else e-mail) and what (a collection topic, else the first words of the message, else the topic); the form only when nothing is known; place() names only for the section', [
    Kaleta\Core\Language::runWith('en', fn (): array => [
        Kaleta\Core\RecordTitle::enquiry($a332Row),
        Kaleta\Core\RecordTitle::enquiry(['data' => json_encode([['Company', 'Hartsteel Ltd'], ['Phone', '+44 20 7946 0000']])] + $a332Row),
        Kaleta\Core\RecordTitle::enquiry(['zdroj' => 'kolekce:2', 'tema' => 'Services – Bathroom renovation'] + $a332Row),
        Kaleta\Core\RecordTitle::enquiry(['data' => '[]'] + $a332Row),
        Kaleta\Core\RecordTitle::enquiry(['data' => '[]', 'email' => '', 'anonymizovano' => '2026-01-01 00:00:00', 'tema' => ''] + $a332Row),
        Kaleta\Core\RecordTitle::enquiry(['formular' => '', 'tema' => '', 'email' => '', 'data' => '[]']),
        Kaleta\Core\RecordTitle::place(['kind' => 'deal', 'id' => 6, 'title' => '#6', 'name' => 'Maintenance contract'], ['pipeline' => 'x']),
        Kaleta\Core\RecordTitle::place(['kind' => 'deal', 'id' => 6, 'title' => '#6', 'name' => 'Maintenance contract'], ['enquiries' => 'x']),
        Kaleta\Core\RecordTitle::place(['kind' => 'enquiry', 'id' => 3, 'title' => 'Contact', 'name' => 'Olivia Hart – We need'], ['enquiries' => 'x']),
        Kaleta\Core\RecordTitle::place(['kind' => 'contact', 'id' => 4, 'title' => 'Olivia Hart', 'name' => 'Olivia Hart'], []),
        Kaleta\Core\RecordTitle::place(null, ['pipeline' => 'x']),
        Kaleta\Core\Inbox::where(['kind' => 'deal', 'id' => 6, 'title' => '#6', 'name' => 'Maintenance contract'])]),
    str_contains($a332Views, 'data-enquiry-name') && str_contains($a332Views, 'RecordTitle::place($place, $modules)') && str_contains($a332Views, "bdi((string) (\$r['name'] ?? \$r['title']))"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/WorkTools.php'), "'name'"),
    array_map(fn (string $code): array => array_values(array_diff($a332Texts, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [['Olivia Hart – We need 40 custom brackets for…', 'Hartsteel Ltd – Contact', 'Olivia Hart – Services – Bathroom renovation', 'olivia@hartsteel.example – Contact', 'Contact', 'Enquiry',
        'Deal “Maintenance contract”', 'Deal #6', 'Enquiry: Olivia Hart – We need', 'Customer #4', '(no longer available)', 'Deal #6'], true, false, [[], [], []]]);

$a332MyWork = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/MyWork.php');
check('3.32 (R1): My work keeps an unassigned enquiry until it is answered (a reply in replies) or resolved – read or not', [
    Kaleta\Core\MyWork::UNANSWERED, str_contains($a332MyWork, "'prirazeno IS NULL AND ' . self::UNANSWERED"), str_contains($a332MyWork, 'prirazeno IS NULL AND stav = 0')],
    ["stav < 2 AND (replies IS NULL OR replies IN ('', '[]'))", true, false]);

$a332Deals = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/Deals.php');
$a332Pipeline = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Pipeline.php');
check('3.32 (U25): Create deal opens the short form prefilled from the enquiry (the title from who and what, no value, the first stage); createFromEnquiry and MCP save_deal take the same default title, an explicit one wins', [
    str_contains($a332Deals, "'title' => self::line(RecordTitle::enquiry(\$enquiry), self::TITLE_MAX)"), str_contains($a332Deals, "\$given + ['title' => \$defaults['title']"),
    str_contains($a332Deals, "'title' => \$topic !== '' ? \$topic"), str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/deal_box.php'), "href=\"<?= e(\$box['formUrl']) ?>\" data-deal-create"),
    str_contains($a332Pipeline, "Deals::createFromEnquiry(\$this->app, \$idp, \$this->app->auth()->id(), \$fields)"), str_contains($a332Pipeline, "'currency' => '', 'stage' => \$fromEnquiry['stage']"),
    array_map(fn (string $code): bool => isset((require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php')['Filled in from the enquiry – check the name, add the value and the expected close if you know them, and create the deal.']), ['cs', 'de', 'es', 'de-du'])],
    [true, true, false, true, true, true, [true, true, true, true]]);

$a332Enquiries = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php');
check('3.32 (R4): a new deal without a responsible person gets its creator (when they may open the pipeline), from an enquiry its handler first; the reply form offers the follow-up, titled by the form only', [
    str_contains($a332Deals, "\$data['owner_id'] = \$byUserId > 0 && self::mayOpen(\$db, \$s, \$byUserId) ? \$byUserId : null;"),
    str_contains($a332Deals, "foreach ([(int) (\$enquiry['prirazeno'] ?? 0), \$byUserId] as \$candidate)"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/views/admin/enquiries/detail.php'), 'name="follow_up" data-reply-follow-up'),
    str_contains($a332Enquiries, "\\Kaleta\\Admin\\TaskPanel::followUpTitle((string) \$form)"),
    array_map(fn (string $code): array => array_values(array_diff(['Follow up if they do not answer', 'No follow-up'], array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [true, true, true, true, [[], [], []]]);

// 3.32 (U38, U39): one glossary – every language calls each term as Core\Glossary says, no screen uses a retired key, and in
// Czech an enquiry kind is never "Obchod" (a deal); a lost deal or stage is "prohraná" again since 3.34 (owner decision), never "ztracená"
$a332Dicts = array_map(fn (string $code): array => require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php', array_combine(Kaleta\Core\Glossary::LANGUAGES, Kaleta\Core\Glossary::LANGUAGES));
$a332Glossary = [ // English key => [cs, de, es]
    'Responsible person' => ['Odpovědná osoba', 'Verantwortliche Person', 'Persona responsable'],
    'Customers' => ['Zákazníci', 'Kunden', 'Clientes'],
    'Customer' => ['Zákazník', 'Kunde', 'Cliente'],
    'New customer' => ['Nový zákazník', 'Neuer Kunde', 'Nuevo cliente'],
    'People' => ['Lidé', 'Personen', 'Personas'],
    'Companies' => ['Firmy', 'Unternehmen', 'Empresas'],
    'Deal' => ['Obchod', 'Deal', 'Oportunidad'],
    'Deals' => ['Obchody', 'Deals', 'Oportunidades'],
    'Sales' => ['Prodej', 'Vertrieb', 'Ventas'],
    'Won' => ['Vyhraný', 'Gewonnen', 'Ganado'],
    'Lost' => ['Prohraný', 'Verloren', 'Perdido'],
    '%s made you responsible' => ['%s vás určil(a) jako odpovědnou osobu', '%s hat Sie als verantwortliche Person eingetragen', '%s te ha nombrado persona responsable'],
];
$a332Wrong = array_values(array_diff(Kaleta\Core\Glossary::TERMS, array_keys($a332Glossary)));
foreach ($a332Glossary as $a332Key => $a332Words) {
    foreach (Kaleta\Core\Glossary::LANGUAGES as $a332I => $a332Code) {
        if (($a332Dicts[$a332Code][$a332Key] ?? null) !== $a332Words[$a332I]) {
            $a332Wrong[] = $a332Code . ': ' . $a332Key . ' = ' . ($a332Dicts[$a332Code][$a332Key] ?? 'missing');
        }
    }
}
$a332Retired = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM, FilesystemIterator::SKIP_DOTS)) as $a332File) {
    $a332Path = (string) $a332File;
    if (!str_ends_with($a332Path, '.php') || str_contains($a332Path, '/jazyky/') || (!str_contains($a332Path, '/src/') && !str_contains($a332Path, '/views/'))) {
        continue;
    }
    $a332Code = (string) file_get_contents($a332Path);
    foreach (array_keys(Kaleta\Core\Glossary::RETIRED) as $a332Key) {
        if (str_contains($a332Code, "t('" . $a332Key . "'") || str_contains($a332Code, "['" . $a332Key . "', 'module=")) {
            $a332Retired[] = substr($a332Path, strlen(KALETA_SYSTEM)) . ': ' . $a332Key;
        }
    }
}
check('3.32 (U38/U39): the glossary – the terms in cs, de and es, no retired key on a screen, Czech "Prodej" for the Sales kind (a deal is "Obchod"), "prohraná" for a lost deal or stage, never "ztracen…" (3.34)', [
    $a332Wrong, $a332Retired, array_values(array_filter(Kaleta\Core\Triage::CATEGORIES, fn (string $label): bool => ($a332Dicts['cs'][$label] ?? '') === $a332Dicts['cs']['Deal'])),
    array_values(array_filter(array_keys($a332Dicts['cs']), fn (string $k): bool => preg_match('/ztracen/iu', $a332Dicts['cs'][$k]) === 1)),
    array_values(array_filter(['lost', 'Deals lost', 'Say why the deal was lost: %s.', 'Lost – “%s” is closed.'], fn (string $k): bool => !str_contains(mb_strtolower($a332Dicts['cs'][$k] ?? ''), 'prohran'))),
    array_map(fn (string $code): array => array_values(array_diff(array_values(Kaleta\Core\Glossary::RETIRED), array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [[], [], [], [], [], [[], [], []]]);

