<?php

declare(strict_types=1);

use Kaleta\Core\Applications;

/* ---------- 3.34 (A): job applications apart from enquiries – the form rules, the files, statuses, retention, the target kind ---------- */
$a334Sources = ['kolekce:4', 'kolekce:9'];
check('3.34: an application form – on a job\'s item page (the source is a jobs collection) or with the Purpose "job application"; anything else stays an enquiry', [
    Applications::isApplicationForm([], 'kolekce:4', $a334Sources), Applications::isApplicationForm(['ucel' => 'uchazec'], 'stranka:3', $a334Sources),
    Applications::isApplicationForm(['ucel' => ''], 'stranka:3', $a334Sources), Applications::isApplicationForm([], 'kolekce:5', $a334Sources),
    Applications::isApplicationForm(['ucel' => 'uchazec'], 'popup:2', []), Applications::PURPOSE,
    (string) Kaleta\Builder\Elements\Form::properties()['ucel']['vychozi'], array_keys(Kaleta\Builder\Elements\Form::properties()['ucel']['moznosti']),
    Kaleta\Mcp\Vocabulary::CONTENT['ucel'], Kaleta\Mcp\Vocabulary::VALUES['ucel'], Kaleta\Mcp\Vocabulary::CONTENT['souhlas_uchovani']],
    [true, true, false, false, true, 'uchazec', '', ['', 'uchazec'], 'purpose', ['' => 'enquiry', 'uchazec' => 'application'], 'pool_consent']);
check('3.34: the position on a general form – the first field labelled like a position, never a long text', [
    Applications::positionValue([['Name', 'Jana'], ['Position', ' Technician ']]), Applications::positionValue([['Pozice', 'Účetní'], ['Job', 'Other']]),
    Applications::positionValue([['Job opening', '{{nazev}}']]), Applications::positionValue([['Message', 'I want a job']]),
    Applications::positionValue([['Position', str_repeat('x', 201)]]), Applications::positionValue([])],
    ['Technician', 'Účetní', '{{nazev}}', '', '', '']);
check('3.34: CVs – a narrow list of types (no archives, spreadsheets, drawings); the detected content must fit the extension', [
    Applications::CV_EXTENSIONS, array_values(array_intersect(['zip', 'xls', 'xlsx', 'dwg', 'dxf', 'svg', 'html', 'php'], Applications::CV_EXTENSIONS)),
    Applications::contentFits('pdf', 'application/pdf'), Applications::contentFits('pdf', 'text/html'), Applications::contentFits('docx', 'application/zip'),
    Applications::contentFits('png', 'image/jpeg'), Applications::contentFits('txt', 'text/x-php'), Applications::contentFits('exe', 'application/x-dosexec')],
    [['pdf', 'doc', 'docx', 'odt', 'rtf', 'txt', 'jpg', 'jpeg', 'png'], [], true, false, true, false, false, false]);
// the content check on real files: a PDF passes, HTML named .pdf, PHP named .txt or .pdf and an SVG named .png do not
$a334Dir = sys_get_temp_dir() . '/kaleta-a334-' . bin2hex(random_bytes(4));
mkdir($a334Dir);
$a334Files = ['cv.pdf' => "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\ntrailer << /Root 1 0 R >>\n%%EOF\n", 'fake.pdf' => "<!DOCTYPE html><html><body><script>alert(1)</script></body></html>",
    'shell.txt' => "<?php system(\$_GET['c']); ?>", 'shell.pdf' => "<?php echo 1; ?>", 'note.txt' => "Hello, I am applying.\n", 'image.png' => '<svg xmlns="http://www.w3.org/2000/svg" onload="alert(1)"></svg>',
    'cv.zip' => "PK\x03\x04" . str_repeat("\0", 30)];
foreach ($a334Files as $name => $content) {
    file_put_contents($a334Dir . '/' . $name, $content);
}
check('3.34: checkUpload – a real PDF and a plain text pass; HTML or PHP behind .pdf/.txt, SVG behind .png and a ZIP are refused', array_map(fn (string $name): ?string => Applications::checkUpload($a334Dir . '/' . $name, $name), array_keys($a334Files)),
    ['pdf', null, null, null, 'txt', null, null]);
// sealing: the stored file is not the original, opens with its own key (or the Vault key of its header – 3.34 second review N9),
// and the source stays until the caller removes it (N1: only once the row lists the sealed copy)
$a334SealSettings = new Kaleta\Core\Settings(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''));
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($a334SealSettings, ['secret_key' => bin2hex(random_bytes(32)), 'vault_check' => '']);
$a334Plain = 'CV of Jana – ' . bin2hex(random_bytes(8));
file_put_contents($a334Dir . '/seal.pdf', $a334Plain);
$a334Sealed = Applications::sealFile($a334SealSettings, $a334Dir . '/seal.pdf');
$a334Raw = $a334Sealed !== null ? (string) file_get_contents(Applications::FOLDER . '/' . $a334Sealed['path']) : '';
$a334Body = substr($a334Raw, 6 + (int) (unpack('n', substr($a334Raw, 4, 2)) ?: [1 => 0])[1]);
$a334Key = $a334Sealed !== null ? (string) base64_decode($a334Sealed['key'], true) : '';
check('3.34: a CV is sealed at rest – a random .bin in storage/applications, not readable as the original, opened with its own key or the Vault key of its header, the source kept for the caller', [
    $a334Sealed !== null && preg_match('#^\d{4}/\d{2}/[a-f0-9]{32}\.bin$#D', $a334Sealed['path']) === 1, str_contains($a334Raw, $a334Plain), is_file($a334Dir . '/seal.pdf'),
    strlen($a334Key) === SODIUM_CRYPTO_SECRETBOX_KEYBYTES ? sodium_crypto_secretbox_open(substr($a334Body, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($a334Body, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), $a334Key) : false,
    sodium_crypto_secretbox_open(substr($a334Body, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($a334Body, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), sodium_crypto_secretbox_keygen()),
    Applications::filePath(['path' => $a334Sealed['path'] ?? '', 'sealed' => true]) === Applications::FOLDER . '/' . ($a334Sealed['path'] ?? ''),
    Applications::filePath(['path' => '../../config.php', 'sealed' => true]), Applications::filePath(['path' => '2026/11/../../x.bin', 'sealed' => true]),
    Applications::filePath(['path' => '2026/11/' . str_repeat('a', 24) . '.pdf', 'sealed' => false]) === Applications::OLD_FOLDER . '/2026/11/' . str_repeat('a', 24) . '.pdf',
    is_file(Applications::FOLDER . '/.htaccess'), str_starts_with($a334Raw, Applications::FILE_MAGIC),
    Applications::openSealed($a334SealSettings, Applications::FOLDER . '/' . ($a334Sealed['path'] ?? 'x'), $a334Sealed['key'] ?? null),
    Applications::openSealed($a334SealSettings, Applications::FOLDER . '/' . ($a334Sealed['path'] ?? 'x'), null)],
    [true, false, true, $a334Plain, false, true, null, null, true, true, true, $a334Plain, $a334Plain]);
if ($a334Sealed !== null) {
    @unlink(Applications::FOLDER . '/' . $a334Sealed['path']);
}
array_map('unlink', glob($a334Dir . '/*') ?: []);
@rmdir($a334Dir);
check('3.34: the six fixed statuses, the decided ones and the reasons of a rejection', [Applications::STATUSES, Applications::DECIDED, array_keys(Applications::REJECT_REASONS),
    array_keys(Applications::STATUS_LABELS) === Applications::STATUSES], [['new', 'reviewed', 'interview', 'offer', 'hired', 'rejected'], ['hired', 'rejected'],
    ['not_suitable', 'position_filled', 'withdrew', 'no_response', 'other'], true]);
$a334History = null;
for ($i = 1; $i <= 52; $i++) {
    $a334History = Applications::appendHistory($a334History, ['at' => '2026-11-' . sprintf('%02d', min(28, $i)) . ' 10:00:00', 'by' => 3, 'from' => 'new', 'to' => $i === 52 ? 'hired' : 'reviewed']);
}
$a334List = json_decode((string) $a334History, true);
check('3.34: the status history keeps the last 50 changes, the newest at the end; movesIn counts each status reached in a month once', [
    count($a334List), $a334List[49]['to'], Applications::appendHistory('not json', ['at' => 'x', 'by' => null, 'from' => 'new', 'to' => 'offer']),
    Applications::movesIn('[{"at":"2026-10-30 10:00:00","to":"interview"},{"at":"2026-11-02 10:00:00","to":"interview"},{"at":"2026-11-03 10:00:00","to":"interview"},{"at":"2026-11-20 10:00:00","to":"rejected"},{"at":"2026-12-01 00:00:00","to":"hired"}]', '2026-11-01 00:00:00', '2026-12-01 00:00:00'),
    Applications::movesIn('', '2026-11-01', '2026-12-01')],
    [50, 'hired', '[{"at":"x","by":null,"from":"new","to":"offer"}]', ['interview', 'rejected'], []]);
// retention: a decided one N months after the decision, an undecided one after N months without activity, never before pool_until
$a334Rows = [
    ['id' => 1, 'decided_at' => '2026-04-10 09:00:00', 'last_activity_at' => '2026-04-10 09:00:00', 'pool_until' => null, 'assignee_id' => 3],  // decided, past
    ['id' => 2, 'decided_at' => '2026-06-10 09:00:00', 'last_activity_at' => '2026-03-01 09:00:00', 'pool_until' => null, 'assignee_id' => 3],  // decided recently, old activity
    ['id' => 3, 'decided_at' => null, 'last_activity_at' => '2026-05-01 09:00:00', 'pool_until' => null, 'assignee_id' => 3],                   // idle, past
    ['id' => 4, 'decided_at' => null, 'last_activity_at' => '2026-11-01 09:00:00', 'pool_until' => null, 'assignee_id' => 3],                   // in process
    ['id' => 5, 'decided_at' => '2026-01-10 09:00:00', 'last_activity_at' => '2026-01-10 09:00:00', 'pool_until' => '2027-01-10', 'assignee_id' => null], // consent keeps it
    ['id' => 6, 'decided_at' => null, 'last_activity_at' => '2026-05-15 09:00:00', 'pool_until' => null, 'assignee_id' => 4],                   // goes within a week
    ['id' => 7, 'decided_at' => null, 'last_activity_at' => '2026-05-15 09:00:00', 'pool_until' => null, 'assignee_id' => null],                // nobody to warn
];
check('3.34: retention – decided after the months from the decision, undecided after the months without activity, the consent keeps it until pool_until; 0 months = kept', [
    Applications::expired($a334Rows, 6, '2026-11-11'), Applications::deletionDate($a334Rows[0], 6), Applications::deletionDate($a334Rows[1], 6), Applications::deletionDate($a334Rows[3], 6),
    Applications::deletionDate($a334Rows[4], 6), Applications::expired($a334Rows, 0, '2026-11-11'), Applications::deletionDate($a334Rows[0], 0), Applications::expired($a334Rows, 6, '2027-01-11')],
    [[1, 3], '2026-10-10', '2026-12-10', '2027-05-01', '2027-01-11', [], null, [1, 2, 3, 5, 6, 7]]);
check('3.34: seven days before an undecided application goes, its responsible person is warned once – never for a decided one, nobody, or one warned already', [
    Applications::dueForWarning($a334Rows, 6, '2026-11-11', []), Applications::dueForWarning($a334Rows, 6, '2026-11-11', [6 => true]), Applications::WARN_DAYS],
    [[['id' => 6, 'assignee' => 4, 'day' => '2026-11-15']], [], 7]);
check('3.34: the list filter is checked – statuses, open, a job or none, a responsible person or nobody, the pool, a short word', [
    Applications::filter(['status' => 'interview', 'job' => '12', 'assignee' => 'none', 'pool' => '1', 'search' => ' Jana ']),
    Applications::filter(['status' => 'won', 'job' => '-3', 'assignee' => 'x', 'pool' => 'yes', 'search' => str_repeat('a', 150)])['status'] . '|' . Applications::filter(['job' => '-3'])['job']
        . '|' . Applications::filter(['assignee' => 'x'])['assignee'] . '|' . mb_strlen(Applications::filter(['search' => str_repeat('a', 150)])['search']),
    Applications::where(Applications::filter(['status' => 'open', 'job' => 'none', 'assignee' => '5', 'pool' => '1', 'search' => '50%']), '2026-11-11'),
    Applications::where(Applications::filter([]), '2026-11-11')],
    [['status' => 'interview', 'job' => '12', 'assignee' => 'none', 'pool' => true, 'search' => 'Jana'], '|||100',
        ["status NOT IN ('hired', 'rejected') AND job_item_id IS NULL AND assignee_id = ? AND pool_until >= ? AND (name LIKE ? OR email LIKE ? OR job_title LIKE ? OR data LIKE ?)", [5, '2026-11-11', '%50\%%', '%50\%%', '%50\%%', '%50\%%']],
        ['1 = 1', []]]);
// the notification of a new application: the number, the position and the link – the fields only when asked for
$a334Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($a334Settings, ['site_name' => 'Northfield', 'site_language' => 'en']);
$a334Mail = Kaleta\Core\Language::runWith('en', fn (): array => Applications::notification($a334Settings, 31, 'Technician', [], 'https://example.com/admin.php?module=applications&action=detail&id=31'));
$a334Full = Kaleta\Core\Language::runWith('en', fn (): array => Applications::notification($a334Settings, 31, '', [['Name', 'Jana Nováková'], ['Email', 'jana@example.com']], 'https://x'));
check('3.34: the e-mail about a new application names the position and the number, never the applicant; the full fields only with applications_notify_full', [
    $a334Mail[0], str_contains($a334Mail[1], 'A new application #31 for the position “Technician” arrived.'), str_contains($a334Mail[1], 'Jana'), str_contains($a334Mail[1], 'action=detail&id=31'),
    $a334Full[0], str_contains($a334Full[1], 'Jana Nováková'), str_contains($a334Full[1], '(no position)')],
    ['New application for Technician: Northfield', true, false, true, 'New application: Northfield', true, true]);
check('3.34: the target kind application – parsed, a task target, swept, a comment note, the section Applications, never journalled, named by number and position', [
    Kaleta\Core\Inbox::parse('application:7'), Kaleta\Core\Tasks::cleanTarget('application:7'), Kaleta\Core\Tasks::cleanTarget('application:0'), Kaleta\Core\Comments::parseTarget('application:7'),
    Kaleta\Core\Comments::KINDS['application'], Kaleta\Core\Assignment::KINDS['application'], Kaleta\Core\Inbox::TARGETS['application'], Kaleta\Core\Tasks::personal('application:7'),
    Applications::target(7), Applications::adminPath(7),
    Kaleta\Core\Language::runWith('en', fn (): array => [Kaleta\Core\Inbox::where(['kind' => 'application', 'id' => 7, 'title' => 'Technician']), Kaleta\Core\Inbox::where(['kind' => 'application', 'id' => 8, 'title' => '']),
        Kaleta\Core\Inbox::what('deletion_due', '')], 'admin-'),
    in_array('applications', Kaleta\Core\AgentJournal::PERSONAL_TABLES, true), Kaleta\Core\Vault::SEALED['applications'] ?? null, in_array('deletion_due', Kaleta\Core\Inbox::KINDS, true)],
    [['application', 7], 'application:7', null, ['type' => 'application', 'id' => 7], 'note', 'applications', 'application', true, 'application:7', 'admin.php?module=applications&action=detail&id=7',
        ['Application #7 · Technician', 'Application #8 · no position', 'An application will be deleted soon'], true, ['applications', 'id', ['attachments']], true]);
$a334Assign = Kaleta\Core\Language::runWith('en', fn (): array => Kaleta\Core\Assignment::mail($a334Settings, ['jazyk' => 'en'], 'application', ['id' => 9, 'title' => 'Technician', 'path' => Applications::adminPath(9)], 'Petr', 'https://x/9'));
check('3.34: the assignment e-mail names the application by its number and position', [$a334Assign[0], str_contains($a334Assign[1], 'Petr made you the responsible person for the application #9 (Technician).')],
    ['You are responsible for the application #9', true]);
// the section: given only explicitly – never a default of a level, the HR role offers it, site-neutral, its own feature that is off by default
check('3.34: the section Applications is never a default of a level; the ready-made role HR gives it; site-neutral; the feature is off by default', [
    in_array('applications', Kaleta\Admin\Modules\Users::defaultModules(Kaleta\Core\Auth::EDITOR), true), in_array('pages', Kaleta\Admin\Modules\Users::defaultModules(Kaleta\Core\Auth::EDITOR), true),
    Kaleta\Admin\Modules\Applications::GRANTED_EXPLICITLY, Kaleta\Admin\Modules\Applications::EXTENSION, Kaleta\Admin\Modules\Applications::GROUP,
    Kaleta\Admin\Modules\Roles::PRESETS['hr'][3] ?? null, isset(Kaleta\Admin\Modules\Roles::configurable()['applications']),
    in_array('applications', Kaleta\Admin\Kernel::SITE_NEUTRAL_MODULES, true), Kaleta\Core\Extensions::CATALOG['applications'][2] ?? null,
    in_array('0120-applications-move', Kaleta\Core\Migration::DATA, true), KALETA_DB_VERSION >= 120], // 3.35: later releases add migrations of their own
    [false, true, true, 'applications', 'Workspace', ['applications', 'collections', 'requests'], true, true, false, true, true]);
// the form side: an application never reaches the enquiry path (no triage, customer record, webhook or enquiry event), the CV check runs
$a334Forms = (string) file_get_contents(KALETA_SYSTEM . '/src/Front/Forms.php');
$a334Branch = substr($a334Forms, (int) strpos($a334Forms, 'if ($application) {'), 900);
check('3.34: Front\Forms – an application form goes to Applications::receive and returns before the enquiry row, triage, customer record, webhook and "enquiry received"', [
    str_contains($a334Forms, 'Applications::isApplicationForm($element[\'obsah\'], $source, \Kaleta\Core\Jobs::sources($this->app->db()))'),
    str_contains($a334Forms, 'Applications::checkUpload((string) $file[\'tmp_name\'], (string) $file[\'name\']) === null'),
    str_contains($a334Branch, 'Applications::receive(') && str_contains($a334Branch, 'return $this->finish('),
    strpos($a334Forms, 'if ($application) {') < strpos($a334Forms, "\$db->insert('poptavky'"), str_contains($a334Branch, 'Contacts::later'), str_contains($a334Branch, 'Triage::'), str_contains($a334Branch, 'Webhook::')],
    [true, true, true, true, false, false, false]);
// the optional consent for future openings: a checkbox with the form's own text, never required, only when the form has one
$a334Pool = new ReflectionMethod(Kaleta\Builder\Elements\Form::class, 'poolConsent');
check('3.34: the Form shows the consent for future openings only with its text, unticked and optional', [
    $a334Pool->invoke(null, ['souhlas_uchovani' => ''], 'abc'), str_contains((string) $a334Pool->invoke(null, ['souhlas_uchovani' => 'Keep me <b>12</b> months'], 'abc'), 'name="ka_pool" value="1"'),
    str_contains((string) $a334Pool->invoke(null, ['souhlas_uchovani' => 'Keep me <b>12</b> months'], 'abc'), '&lt;b&gt;12&lt;/b&gt;'), str_contains((string) $a334Pool->invoke(null, ['souhlas_uchovani' => 'x'], 'abc'), 'required')],
    ['', true, true, false]);
// the data migration: self-contained, repeatable by the unique source_enquiry_id, the jobs sources only, the feature written down
$a334Move = (string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0120-applications-move.php');
$a334Schema = (string) file_get_contents(KALETA_SYSTEM . '/sql/schema.sql');
check('3.34: migration 0120 moves only the enquiries of jobs collections, once each, with their comments, tasks and notifications; schema and 0119 agree', [
    str_contains($a334Move, "WHERE preset = 'jobs'"), str_contains($a334Move, 'NOT EXISTS (SELECT 1 FROM {applications} a WHERE a.source_enquiry_id = p.idp)'),
    str_contains($a334Move, "foreach (['comments', 'tasks', 'notifications'] as \$table)"), str_contains($a334Move, "\$db->delete('poptavky', ['idp' => \$idp]);"),
    str_contains($a334Move, 'Deals::forgetEnquiries'), str_contains($a334Move, "'sealed' => false"), str_contains($a334Schema, 'UNIQUE KEY ux_applications_enquiry (source_enquiry_id)'),
    substr($a334Schema, (int) strpos($a334Schema, 'CREATE TABLE ka_applications'), 3000) === substr((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0119-applications.sql'), (int) strpos((string) file_get_contents(KALETA_SYSTEM . '/sql/migrace/0119-applications.sql'), 'CREATE TABLE ka_applications'), 3000)],
    [true, true, true, true, true, true, true, true]);
