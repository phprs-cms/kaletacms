<?php

declare(strict_types=1);

/* ---------- 3.34 second review N6: every read of enquiry rows goes through Enquiries::visible() or names why not ---------- */
// Every PHP statement of system/src and system/views that SELECTs rows from ka_poptavky must carry the visibility condition
// (visible(), visibleHere(), findVisible() or a $visible built from it) – the job enquiries migration 0120 is still moving stay
// with the people who may open Applications, and a moved twin (N9) with nobody – or be named here with the reason. A count or
// an existence check (COUNT, MAX, MIN, SELECT 1) returns no row of a person and needs neither. A new query fails this test.
$n6Exempt = [
    'src/Core/PersonalData.php' => [
        'FROM {poptavky} WHERE LOWER(email) = ?' => 'an administrator\'s export or erasure reaches every enquiry of the address',
        'SELECT idp, data FROM {poptavky} WHERE idp IN' => 'the files of the enquiries the erasure found (deleteAttachments keeps what an application lists)'],
    'src/Core/Deals.php' => [
        'SELECT DISTINCT p.idp FROM {poptavky} p JOIN {deals}' => 'the retention asks which enquiries a deal holds – ids only',
        'SELECT idp, formular, tema, zdroj, email, data FROM {poptavky} WHERE idp IN' => 'the neutral title of deals whose enquiries go (forgetEnquiries) – the callers chose the rows'],
    'src/Core/Inbox.php' => ['SELECT idp FROM {poptavky} WHERE idp IN' => 'which targets still exist – ids only'],
    'src/Core/QuoteDelivery.php' => ['SELECT email, zdroj FROM {poptavky} WHERE idp = ?' => 'the address (and whether an import brought it) of the enquiry a quote was made from – compared with the quote\'s, never shown (send_quote, A-5)'],
    'src/Core/Comments.php' => ['SELECT idp FROM {poptavky} WHERE idp IN' => 'which targets still exist – ids only'],
    'src/Core/Privacy.php' => ['SELECT idp, data, replies FROM {poptavky} WHERE anonymizovano IS NULL' => 'anonymises the rows its callers chose'],
    'src/Core/Triage.php' => [
        'SELECT triaged_by FROM {poptavky} WHERE idp = ?' => 'saving a triage a caller allowed',
        'SELECT idp, zdroj FROM {poptavky} WHERE idp = ?' => 'the rule on an enquiry the form has just stored'],
    'src/Core/Contacts.php' => ['SELECT p.idp, p.datum, p.formular, c.id, c.summary FROM {poptavky} p' => 'the first-contact line of the expiring enquiries the retention chose'],
    'src/Core/Assignment.php' => ['SELECT formular FROM {poptavky} WHERE idp = ?' => 'the form name of an enquiry its caller just assigned'],
    'src/Admin/Modules/Enquiries.php' => [
        'FROM {poptavky} \' . $whereParts' => 'the list: $whereParts carries visibleHere()',
        'SELECT formular FROM {poptavky} WHERE idp = ?' => 'the title of a follow-up after a reply EnquiryReply::send allowed',
        'SELECT idp, data FROM {poptavky} WHERE idp IN (\' . $v' => 'the files of the ids visibleHere() kept',
        'SELECT idp, data FROM {poptavky} WHERE idp = ?' => 'the files of an enquiry findVisible() found',
        'SELECT idp, data FROM {poptavky} WHERE datum < ?\' . $keep' => 'the retention: $keep carries visible()'],
    'src/Mcp/Handlers/EnquiryAndPopupTools.php' => ['FROM {poptavky} WHERE \' . implode(\' AND \', $whereParts)' => 'list_enquiries: $whereParts carries visible()'],
    'src/Core/Applications.php' => ['SELECT idp, data FROM {poptavky} WHERE idp > ?' => 'which CV paths enquiries still name – the sweep keeps their encrypted copies (N9)',
        'SELECT p.idp, p.data FROM {poptavky} p JOIN {applications} a' => 'the moved twins themselves, to delete them with their application and in the clean-up (3.34 third review F4)'],
    'src/Core/ApplicationMove.php' => ['SELECT * FROM {poptavky} p WHERE' => 'the move to Applications itself (people with both sections; an enquiry with an application twin is left out by its own NOT EXISTS)'],
    'src/Fleet/Heartbeat.php' => ['SELECT datum, replies FROM {poptavky}' => 'the median hours to a first reply – numbers only'],
];
$n6Missing = [];
$n6Used = [];
$n6Seen = 0;
$n6Files = [];
foreach (['src', 'views'] as $n6Dir) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/' . $n6Dir, FilesystemIterator::SKIP_DOTS)) as $n6File) {
        if (str_ends_with((string) $n6File, '.php')) {
            $n6Files[] = (string) $n6File;
        }
    }
}
sort($n6Files);
foreach ($n6Files as $n6File) {
    $n6Rel = substr($n6File, strlen(KALETA_SYSTEM) + 1);
    $n6Source = (string) file_get_contents($n6File);
    if (preg_match_all('/FROM \{(?:poptavky|enquiries)\}/', $n6Source, $n6Found, PREG_OFFSET_CAPTURE) === 0) {
        continue;
    }
    foreach ($n6Found[0] as [, $n6At]) {
        // the PHP statement around it: from the previous ; { or } to the next ;
        $n6Start = max(strrpos(substr($n6Source, 0, $n6At), ';') ?: 0, strrpos(substr($n6Source, 0, $n6At), '{') ?: 0, strrpos(substr($n6Source, 0, $n6At), '}') ?: 0);
        $n6Statement = substr($n6Source, $n6Start, (strpos($n6Source, ';', $n6At) ?: strlen($n6Source)) - $n6Start);
        $n6Select = substr($n6Statement, 0, $n6At - $n6Start);
        $n6Select = substr($n6Select, (int) strrpos($n6Select, 'SELECT'));
        if (!str_contains($n6Statement, 'SELECT') || preg_match('/^SELECT\s+(?:DISTINCT\s+)?(?:COUNT\(|1\b|MAX\(|MIN\(|SUM\()|COUNT\(\*\)/', $n6Select) === 1) {
            continue; // not a SELECT, or a count / an existence check
        }
        $n6Seen++;
        if (stripos($n6Statement, 'visible') !== false) {
            continue;
        }
        $n6Reason = null;
        foreach ($n6Exempt[$n6Rel] ?? [] as $n6Needle => $n6Why) {
            if (str_contains($n6Statement, $n6Needle)) {
                $n6Reason = $n6Why;
                $n6Used[$n6Rel . ' · ' . $n6Needle] = true;
                break;
            }
        }
        if ($n6Reason === null) {
            $n6Missing[] = $n6Rel . ':' . (substr_count(substr($n6Source, 0, $n6At), "\n") + 1);
        }
    }
}
$n6Stale = [];
foreach ($n6Exempt as $n6Rel => $n6Needles) {
    foreach ($n6Needles as $n6Needle => $_) {
        if (!isset($n6Used[$n6Rel . ' · ' . $n6Needle])) {
            $n6Stale[] = $n6Rel . ' · ' . $n6Needle;
        }
    }
}
check('3.34 second review N6: every SELECT of enquiry rows carries Enquiries::visible() or a named exemption; no exemption is stale', [$n6Seen > 30, $n6Missing, $n6Stale], [true, [], []]);

// the condition itself: a moved twin for everyone, the enquiries still to move for a person without Applications; the table name or alias
$n6Settings = new Kaleta\Core\Settings(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''));
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($n6Settings, ['db_version' => '120', 'applications_move_until' => '']);
$n6Twin = Kaleta\Admin\Modules\Enquiries::visible($n6Settings->db(), $n6Settings, true, 'p');
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($n6Settings, ['db_version' => '118', 'applications_move_until' => '']);
check('3.34 second review N6: visible() – a twin is an application with the enquiry\'s idp and date (by the given alias); before migration 0119 nothing to hide', [
    $n6Twin, Kaleta\Admin\Modules\Enquiries::visible($n6Settings->db(), $n6Settings, false)],
    ['NOT EXISTS (SELECT 1 FROM {applications} twin WHERE twin.source_enquiry_id = p.idp AND twin.received_at = p.datum)', '1 = 1']);
$n6Places = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php');
check('[source-pin] 3.34 second review N6: in Enquiries the detail, attachment, triage, notes, status, delete, bulk and CSV act only on what the person may see', [
    substr_count($n6Places, '$this->findVisible('), str_contains($n6Places, "\$this->db->all('SELECT idp FROM {poptavky} WHERE idp IN (' . implode(',', \$ids) . ') AND ' . \$this->visibleHere())"),
    str_contains($n6Places, "\$this->db->all('SELECT * FROM {poptavky} WHERE ' . \$this->visibleHere() . ' ORDER BY idp')")], [7, true, true]);
