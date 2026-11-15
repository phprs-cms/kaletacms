<?php

declare(strict_types=1);

use Kaleta\Core\Absences;
use Kaleta\Core\Assignment;
use Kaleta\Core\Colleagues;
use Kaleta\Core\Departments;
use Kaleta\Core\Inbox;
use Kaleta\Core\Language;
use Kaleta\Core\Tasks;
use Kaleta\Mcp\Catalog;

/* ---------- 3.42 B: the team hierarchy – departments, reports to, absences with a deputy, the stand-in, escalation ---------- */
check('3.42 B: an absence – both days as dates, the end not before the start nor already past, at most a year', [
    Absences::refusal('2026-12-20', '2027-01-02', '2026-11-15'), Absences::refusal('2026-11-15', '2026-11-15', '2026-11-15'),
    Absences::refusal('2026-12-20', '2026-12-19', '2026-11-15'), Absences::refusal('2026-11-01', '2026-11-14', '2026-11-15'),
    Absences::refusal('2026-02-30', '2026-03-02', '2026-01-01'), Absences::refusal('20.12.2026', '2026-12-24', '2026-11-15'),
    Absences::refusal('2026-12-01', '2027-12-02', '2026-11-15'), Absences::refusal('2026-12-01', '2027-12-01', '2026-11-15')],
    [null, null, 'The last day cannot be before the first one.', 'The absence has already ended.', 'Enter the first and the last day of the absence.',
        'Enter the first and the last day of the absence.', 'An absence can be at most a year long.', null]);

check('3.42 B: the reasons are a fixed list of keys (never free text, never health data), each translated in cs, de and es', [
    array_keys(Absences::REASONS), Absences::reasonLabel('nonsense') === Absences::reasonLabel('away'),
    array_map(fn (string $code): array => array_values(array_diff(Absences::REASONS, array_keys(require KALETA_SYSTEM . '/jazyky/admin-' . $code . '.php'))), ['cs', 'de', 'es'])],
    [['holiday', 'trip', 'training', 'day_off', 'away'], true, [[], [], []]]);

check('3.42 B: the forms a department routes – trimmed, blanks folded, no empty line or repeat (any case), at most FORMS, each at most 120 characters', [
    Departments::forms(['  Contact ', '', 'contact', "Quote\tform", 'Service']), count(Departments::forms(array_map(fn (int $i): string => 'Form ' . $i, range(1, 30)))),
    mb_strlen(Departments::forms([str_repeat('x', 300)])[0])],
    [['Contact', 'Quote form', 'Service'], Departments::FORMS, 120]);

check('3.42 B: the stand-in gets every kind about responsibility – never a mention (it names the person) nor the console\'s reminders; escalated is a new kind', [
    array_values(array_diff(Inbox::STAND_IN_KINDS, Inbox::KINDS)), in_array('mention', Inbox::STAND_IN_KINDS, true), in_array('fleet_expiry', Inbox::STAND_IN_KINDS, true),
    in_array('escalated', Inbox::KINDS, true), in_array('task_due', Inbox::STAND_IN_KINDS, true), in_array('assigned', Inbox::STAND_IN_KINDS, true)],
    [[], false, false, true, true, true]);

$u342Settings = $reportSettings([]);
$u342Note = Inbox::mail($u342Settings, ['jazyk' => 'en', 'register' => ''], ['kind' => 'task_due', 'actor_name' => 'Jana', 'excerpt' => 'Call back', 'standing_in_for' => 'Petr Novák'],
    ['kind' => 'task', 'id' => 3, 'title' => 'Call back', 'path' => ''], 'https://x/1', 'https://x/2');
$u342Plain = Inbox::mail($u342Settings, ['jazyk' => 'en', 'register' => ''], ['kind' => 'task_due', 'actor_name' => 'Jana', 'excerpt' => 'Call back'],
    ['kind' => 'task', 'id' => 3, 'title' => 'Call back', 'path' => ''], 'https://x/1', 'https://x/2');
$u342Escalated = Inbox::mail($u342Settings, ['jazyk' => 'en', 'register' => ''], ['kind' => 'escalated', 'actor_name' => 'Jana', 'excerpt' => 'Send the offer'],
    ['kind' => 'task', 'id' => 4, 'title' => 'Send the offer', 'path' => ''], 'https://x/1', 'https://x/2');
$u342Assigned = Assignment::mail($u342Settings, ['jazyk' => 'cs', 'register' => ''], 'enquiry', ['id' => 12, 'title' => 'Contact', 'path' => ''], 'Kaleta', 'https://x/3', 'Petr Novák');
$u342Digest = Inbox::digest($u342Settings, ['jazyk' => 'en', 'register' => ''], [['kind' => 'review_due', 'actor_name' => '', 'excerpt' => '', 'url' => 'https://x/4',
    'place' => ['kind' => 'page', 'id' => 2, 'title' => 'Prices'], 'standing_in_for' => 'Petr Novák']], 'https://x/5', 'https://x/6');
check('3.42 B: a stand-in\'s copy says whom it stands in for – the instant e-mail, the digest and the assignment e-mail (in the recipient\'s language); the person\'s own says nothing', [
    str_starts_with($u342Note[1], 'You are standing in for Petr Novák, who is away.'), str_contains($u342Plain[1], 'standing in'),
    $u342Escalated[0], Inbox::what('escalated', 'Jana'), str_starts_with($u342Assigned[1], 'Zastupujete Petr Novák'), str_contains($u342Digest[1], '(standing in for Petr Novák)'),
    Language::runWith('de', fn (): string => Inbox::standingIn('Petr'), 'admin-'), Language::runWith('es', fn (): string => Inbox::standingIn(''), 'admin-')],
    [true, false, 'Overdue task of Jana: Send the offer', 'A task of Jana is overdue', true, true, 'Sie vertreten Petr, die oder der abwesend ist.', 'Está sustituyendo a (antiguo usuario), que está ausente.']);

check('3.42 B: list_colleagues keeps its compact shape and adds the department, reports_to and – away today – absent_until and deputy; the rows of 3.37 still work', [
    Colleagues::compact([['id' => 3, 'name' => 'Olivia', 'position' => '', 'phone' => '+44 1', 'email' => '', 'photo' => 'media/o.jpg']]),
    Colleagues::compact([['id' => 4, 'name' => 'Petr', 'position' => 'Sales', 'phone' => '', 'email' => 'p@example.cz', 'photo' => '', 'department_id' => 2, 'department' => 'Sales',
        'reports_to' => 3, 'absent_until' => '2026-12-31', 'deputy' => 5]]),
    Colleagues::compactDepartments(['departments' => [['id' => 2, 'name' => 'Sales', 'depth' => 0, 'parent_id' => null, 'head' => 3, 'members' => []],
        ['id' => 7, 'name' => 'Export', 'depth' => 1, 'parent_id' => 2, 'head' => null, 'members' => []]], 'none' => []])],
    [[['id' => 3, 'name' => 'Olivia', 'phone' => '+44 1']],
        [['id' => 4, 'name' => 'Petr', 'position' => 'Sales', 'email' => 'p@example.cz', 'department' => 'Sales', 'reports_to' => 3, 'absent_until' => '2026-12-31', 'deputy' => 5]],
        [['id' => 2, 'name' => 'Sales', 'head' => 3], ['id' => 7, 'name' => 'Export', 'parent' => 2]]]);

check('3.42 B: no new MCP tool – list_colleagues (Work set) carries the org chart; escalation waits ESCALATE_DAYS and leaves tasks older than the window alone', [
    in_array('list_colleagues', Catalog::WORK, true), isset(Catalog::TOOLS['list_org_chart']), Tasks::ESCALATE_DAYS, Tasks::ESCALATE_WINDOW > Tasks::ESCALATE_DAYS],
    [true, false, 3, true]);

// the stable API for part A (folder visibility by department) – the signatures other code may rely on
$u342Api = fn (string $method): string => implode(', ', array_map(fn (ReflectionParameter $p): string => ($p->getType()?->__toString() ?? '') . ' $' . $p->getName()
    . ($p->isOptional() ? ' = ' . var_export($p->getDefaultValue(), true) : ''), (new ReflectionMethod(Departments::class, $method))->getParameters())) . ': ' . (new ReflectionMethod(Departments::class, $method))->getReturnType();
check('3.42 B: Core\\Departments – all(), ofUser(), withChildren(), userIn() keep their signatures', [
    $u342Api('all'), $u342Api('ofUser'), $u342Api('withChildren'), $u342Api('userIn')],
    ['Kaleta\\Core\\Db $db: array', 'Kaleta\\Core\\Db $db, int $userId: ?int', 'Kaleta\\Core\\Db $db, int $id: array', 'Kaleta\\Core\\Db $db, int $userId, int $departmentId, bool $withChildren = true: bool']);
