<?php

declare(strict_types=1);

use Kaleta\Admin\Modules\Applications as ApplicationsModule;
use Kaleta\Admin\Modules\InternalFiles as InternalFilesModule;
use Kaleta\Core\Applications as Store;
use Kaleta\Core\Language;

/* ---------- 3.47 B: the application on the record template (U64), one board script and "All positions" (U65), Internal files (U66) ---------- */
$b347View = new Kaleta\Core\View([KALETA_SYSTEM . '/views']);
$b347App = new Kaleta\Core\App([]);
/** a module stand-in: the URLs of Applications and its pure title rule */
$b347Module = new class {
    /** @param array<string, scalar> $p */
    public function url(string $a = '', array $p = []): string
    {
        return 'admin.php?' . http_build_query(['module' => 'applications'] + ($a !== '' ? ['action' => $a] : []) + $p);
    }

    public function hint(string $text): string
    {
        return e($text);
    }

    /** @param array<string, mixed> $a */
    public static function personTitle(array $a): string
    {
        return ApplicationsModule::personTitle($a);
    }
};
$b347A = ['id' => 41, 'name' => 'Eva Nováková', 'email' => 'eva@example.com', 'phone' => '+420 777 111 222', 'job_title' => 'Welder', 'late' => false, 'received_at' => '2026-11-10 09:00:00',
    'form' => 'Job application', 'data' => [['A few words', 'Hello']], 'attachments' => [['name' => 'cv.pdf', 'size' => 1200]], 'attachments_locked' => false, 'source_enquiry_id' => null,
    'status' => 'interview', 'reject_reason' => null, 'assignee_id' => 7, 'pool_until' => '2027-11-10', 'pool_consent_at' => '2026-11-10 09:00:00', 'pool_consent_text' => 'Keep me',
    'pool_renewal_sent_at' => null, 'history' => [['at' => '2026-11-11 10:00:00', 'by' => 7, 'from' => 'new', 'to' => 'interview']]];
$b347Detail = fn (bool $admin, array $a = []): string => Language::runWith('en', fn (): string => $b347View->render('admin/applications/detail', ['app' => $b347App, 'module' => $b347Module,
    'csrf' => '', 'a' => $a + $b347A, 'people' => [7 => 'Hana'], 'names' => [7 => 'Hana'], 'deletesOn' => '2027-05-10',
    'thread' => ['target' => 'application:41', 'comments' => [], 'fields' => ['id' => 41], 'action' => 'admin.php?module=applications&action=comment', 'people' => [], 'mentionable' => [], 'me' => 7, 'admin' => $admin, 'draft' => null],
    'tasks' => ['target' => 'application:41', 'tasks' => [], 'fields' => ['id' => 41], 'action' => 'admin.php?module=applications&action=task', 'people' => [7 => 'Hana'], 'me' => 7,
        'claude' => false, 'requestUrl' => '', 'draft' => '', 'followUp' => false, 'replied' => false, 'review' => null],
    'others' => [['id' => 40, 'job_title' => 'Painter', 'status' => 'rejected', 'received_at' => '2026-10-01 09:00:00', 'deletes_on' => null]], 'othersCount' => 1, 'me' => 7, 'admin' => $admin,
    'reply' => ['subject' => '', 'text' => '', 'template' => ''], 'replies' => [], 'replyBlocked' => null, 'replyTo' => 'hana@example.com', 'hasSignature' => false]), 'admin-');
$b347Admin = $b347Detail(true);
$b347Hr = $b347Detail(false, ['pool_until' => null]);
$b347Hooks = ['data-record-bar', 'data-application-card="41"', 'data-application-status="interview"', 'data-application-move', 'data-application-assignee', 'data-application-pool',
    'data-application-deletes', 'data-application-others', 'data-application-delete data-potvrdit', 'data-application-history', 'data-application-files', 'data-reply-form', 'data-reply-template'];
check('3.47 U64: the detail is on the record template and keeps every hook – the bar, the card, status, move, assignee, pool, deletes, others, delete, history, files, reply', [
    array_values(array_filter($b347Hooks, fn (string $hook): bool => !str_contains($b347Admin, $hook))),
    str_contains($b347Admin, '<div class="zaznam-obal"><div class="zaznam" data-application-card="41">'), substr_count($b347Admin, 'class="zaznam-bok"'),
    str_contains($b347Admin, 'uchazec-detail') || str_contains($b347Admin, 'uchazec-bok'), str_contains($b347Admin, 'href="#reply" data-record-reply')],
    [[], true, 1, false, true]);
check('3.47 U64: one Handling form saves the status, its reason and the responsible person (action handling); More holds Consent withdrawn and Delete for an administrator', [
    preg_match('#<form class="zaznam-formular" method="post" action="admin\.php\?module=applications&amp;action=handling" data-application-move>.*?name="status".*?name="reject_reason".*?name="assignee_id".*?</form>#s', $b347Admin),
    substr_count($b347Admin, 'action=handling'), str_contains($b347Admin, 'action=status"') || str_contains($b347Admin, 'action=assign"'),
    preg_match('#<details class="zaznam-vice" data-zavrit-mimo data-record-more>.*data-application-consent.*data-application-delete.*</details>#s', $b347Admin)],
    [1, 1, false, 1]);
check('3.47 U64: someone without the administrator role gets no Delete, and without a consent no More at all; the retention note says who deletes', [
    str_contains($b347Hr, 'data-application-delete '), str_contains($b347Hr, 'data-record-more'), str_contains($b347Hr, 'An application is deleted by its retention')],
    [false, false, true]);
check('3.47 U64: the person is the page title – the name, else the e-mail, else "Application #N"', Language::runWith('en', fn (): array => [
    ApplicationsModule::personTitle(['id' => 5, 'name' => ' Eva ', 'email' => 'e@x.cz']), ApplicationsModule::personTitle(['id' => 5, 'name' => '', 'email' => 'e@x.cz']),
    ApplicationsModule::personTitle(['id' => 5, 'name' => '', 'email' => '']), method_exists(ApplicationsModule::class, 'actionHandling')], 'admin-'),
    ['Eva', 'e@x.cz', 'Application #5', true]);

$b347Tabs = fn (bool $admin, string $current): string => Language::runWith('en', fn (): string => $b347View->render('admin/applications/tabs', ['module' => $b347Module, 'current' => $current, 'admin' => $admin]), 'admin-');
check('3.47 U64: the section strip is "Applications sections" and has no "Move by form" (the settings link it); the move page marks Settings', [
    str_contains($b347Tabs(true, 'list'), 'aria-label="Applications sections"'), str_contains($b347Tabs(true, 'list'), 'action=move'), str_contains($b347Tabs(true, 'list'), 'Move by form'),
    str_contains($b347Tabs(true, 'settings'), 'action=settings" class="aktivni"'), str_contains($b347Tabs(false, 'list'), 'action=settings')],
    [true, false, false, true, false]);

$b347List = Language::runWith('en', fn (): string => $b347View->render('admin/applications/list', ['app' => $b347App, 'module' => $b347Module, 'csrf' => '',
    'result' => ['rows' => [['id' => 41, 'name' => 'Eva', 'email' => 'eva@example.com', 'received_at' => '2026-11-10 09:00:00', 'job_title' => 'Welder', 'late' => false, 'status' => 'new',
        'assignee_id' => null, 'deletes_on' => null]], 'total' => 1, 'filter' => Store::filter(['status' => 'open'])],
    'page' => 1, 'pages' => 1, 'counts' => array_fill_keys(array_keys(ApplicationsModule::TABS), 1) + ['all' => 1], 'jobs' => [], 'people' => [7 => 'Hana'], 'names' => [], 'me' => 7,
    'nobody' => false, 'admin' => true, 'moving' => 0, 'awaiting' => null]), 'admin-');
check('3.47 U64: the list – the status tabs are "Status" and a select on a phone, the bulk form follows the table (hidden by CSS until a row is ticked), the buttons say what they do', [
    str_contains($b347List, '<nav class="zalozky" aria-label="Status" data-applications-tabs>'), (bool) preg_match('#</nav>\s*(<\?php.*?\?>\s*)?<form class="zalozky-vyber"#s', $b347List),
    preg_match('#<select id="uchazeci-stav-vyber" name="status" data-odeslat-pri-zmene>(.*?)</select>#s', $b347List, $b347Select) === 1 ? substr_count($b347Select[1], '<option ') : 0, (bool) preg_match('#</table>\s*</div>\s*<form class="uchazeci-hromadne"#', $b347List),
    str_contains($b347List, 'value="assign">Assign</button>'), str_contains($b347List, '<label for="hromadne-odmitnuti">Reason when rejected</label>'),
    (bool) preg_match('#<td class="uchazeci-cislo"><input type="checkbox" name="ids\[\]" value="41"[^>]*> <a [^>]*>\#41</a></td>#', $b347List)],
    [true, true, count(ApplicationsModule::TABS), true, true, true, true]);

// U65: the board – "All positions", the hooks of the shared script
check('3.47 U65: the board\'s position condition – "all" has none, "none" the spontaneous ones, a number its position; anything else is no board (filter() stays the list\'s and MCP\'s)', [
    Store::boardPosition('all'), Store::boardPosition('none'), Store::boardPosition('12'), Store::boardPosition('0'), Store::boardPosition('x'), Store::filter(['job' => 'all'])['job']],
    ['', 'job_item_id IS NULL AND', 'job_item_id = 12 AND', null, null, '']);
$b347Board = Language::runWith('en', fn (): string => $b347View->render('admin/applications/board', ['app' => $b347App, 'module' => $b347Module, 'csrf' => '',
    'jobs' => [['job_item_id' => 3, 'job_title' => 'Welder', 'open' => 1, 'all' => 1], ['job_item_id' => 4, 'job_title' => 'Painter', 'open' => 1, 'all' => 1]], 'job' => 'all', 'title' => 'All positions',
    'board' => ['columns' => ['new' => [['id' => 1, 'name' => 'Eva', 'email' => '', 'job_title' => 'Welder', 'received_at' => '2026-11-10 09:00:00', 'assignee_id' => null, 'waiting' => false, 'late' => false, 'pool_until' => null, 'reject_reason' => null]],
        'interview' => [['id' => 2, 'name' => 'Ota', 'email' => '', 'job_title' => 'Painter', 'received_at' => '2026-11-10 09:00:00', 'assignee_id' => null, 'waiting' => false, 'late' => false, 'pool_until' => null, 'reject_reason' => null]]]
        + array_fill_keys(Store::STATUSES, []), 'more' => array_fill_keys(Store::STATUSES, 0)], 'names' => [], 'admin' => false]), 'admin-');
check('3.47 U65: on "All positions" each card names its position; the columns carry label, count, cards and the empty line (hidden when not empty), Rejected needs a reason, one live region', [
    str_contains($b347Board, '<option value="all" selected>All positions</option>'), substr_count($b347Board, 'data-board-position'), str_contains($b347Board, '>Painter</bdi></p>') || str_contains($b347Board, '>Painter</p>'),
    substr_count($b347Board, 'data-board-label'), substr_count($b347Board, 'data-board-count'), substr_count($b347Board, 'data-board-cards'), substr_count($b347Board, 'data-board-empty hidden'),
    substr_count($b347Board, 'data-board-needs-reason'), (bool) preg_match('#data-board-status="rejected" data-board-needs-reason#', $b347Board), substr_count($b347Board, 'data-board-announce'),
    substr_count($b347Board, 'data-board-status=')],
    [true, 2, true, 6, 6, 6, 2, 1, true, 1, 6]);

// U66: several files, the preview
check('3.47 U66: the upload field of several files becomes one entry per file; a single field stays one; an empty slot and a missing field give none', [
    InternalFilesModule::uploadsOf(['name' => ['a.pdf', 'b.png', ''], 'type' => ['application/pdf', 'image/png', ''], 'tmp_name' => ['/tmp/a', '/tmp/b', ''], 'error' => [0, 0, UPLOAD_ERR_NO_FILE], 'size' => [10, 20, 0]]),
    InternalFilesModule::uploadsOf(['name' => 'c.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/c', 'error' => 0, 'size' => 3]),
    InternalFilesModule::uploadsOf(['name' => '', 'type' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0]), InternalFilesModule::uploadsOf(null),
    count(InternalFilesModule::uploadsOf(['name' => ['x.pdf'], 'type' => ['a'], 'tmp_name' => ['/tmp/x'], 'error' => [UPLOAD_ERR_INI_SIZE], 'size' => [0]]))],
    [[['name' => 'a.pdf', 'type' => 'application/pdf', 'tmp_name' => '/tmp/a', 'error' => 0, 'size' => 10], ['name' => 'b.png', 'type' => 'image/png', 'tmp_name' => '/tmp/b', 'error' => 0, 'size' => 20]],
        [['name' => 'c.txt', 'type' => 'text/plain', 'tmp_name' => '/tmp/c', 'error' => 0, 'size' => 3]], [], [], 1]);
check('3.47 U66: a preview shows a raster image up to 10 MB and a text document – never PDF, SVG, an office file or a larger image', array_map(fn (array $f): ?string => InternalFilesModule::previewKind($f), [
    ['extension' => 'png', 'size' => 1048576], ['extension' => 'JPG', 'size' => 10485760], ['extension' => 'webp', 'size' => 10485761], ['extension' => 'pdf', 'size' => 100],
    ['extension' => 'svg', 'size' => 100], ['extension' => 'docx', 'size' => 100], ['extension' => 'md', 'size' => 100], ['extension' => 'txt', 'size' => 200001]]),
    ['image', 'image', null, null, null, null, 'text', null]);

// 3.47 B: a class in image/admin.css that nothing emits is dead weight (CLAUDE.md: what is not written has no style). A rule over the
// tree: every class the admin styles name appears in a view, PHP or script – except the endings of names that code builds
$b347Css = (string) preg_replace(['#/\*.*?\*/#s', '/url\([^)]*\)/', '/\{[^{}]*\}/'], ['', '', '{}'], (string) file_get_contents(KALETA_ROOT . '/image/admin.css'));
preg_match_all('/\.(-?[a-zA-Z_][a-zA-Z0-9_-]*)/', $b347Css, $b347Found);
$b347Built = ['claude-stav-', 'flotila-', 'ukol-', 'stav-']; // claude_chip.php, the fleet console, tasks_panel.php, settings/health.php build these from a value
$b347Text = [];
foreach ([KALETA_SYSTEM, KALETA_ROOT . '/image', KALETA_ROOT . '/extensions'] as $b347Dir) {
    if (!is_dir($b347Dir)) {
        continue;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($b347Dir, FilesystemIterator::SKIP_DOTS)) as $b347File) {
        if (in_array($b347File->getExtension(), ['php', 'js'], true) && !str_contains($b347File->getPathname(), '/jazyky/')) {
            $b347Text[] = (string) file_get_contents($b347File->getPathname());
        }
    }
}
$b347All = implode("\n", $b347Text) . (string) file_get_contents(KALETA_ROOT . '/admin.php');
$b347Unused = [];
foreach (array_unique($b347Found[1]) as $b347Class) {
    $b347Prefix = array_filter($b347Built, fn (string $p): bool => str_starts_with($b347Class, $p));
    if ($b347Prefix === [] && preg_match('/(?<![a-zA-Z0-9_-])' . preg_quote($b347Class, '/') . '(?![a-zA-Z0-9_-])/', $b347All) !== 1) {
        $b347Unused[] = $b347Class;
    }
}
check('3.47 B: every class image/admin.css styles is written somewhere (a view, PHP or a script) – the record template replaced .uchazec-detail/-hlavni/-bok, the filter checkbox rule .obchody-bez-kroku', $b347Unused, []);
