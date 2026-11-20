<?php

declare(strict_types=1);

use Kaleta\Admin\Account;
use Kaleta\Admin\Hubs;
use Kaleta\Admin\Modules\Users;
use Kaleta\Builder\Elements\Form;
use Kaleta\Core\Absences;
use Kaleta\Core\Inbox;
use Kaleta\Core\Language;

/* ---------- 3.47 A: My account in tabs (U68), Users as a hub (U67), one primary button on sign-in (U69), the absence bell (N166) ---------- */
$a347View = new Kaleta\Core\View([KALETA_SYSTEM . '/views']);
$a347Settings = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
(new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($a347Settings, ['site_name' => 'Test', 'agency_name' => '']);
$a347App = new Kaleta\Core\App([]);
(new ReflectionProperty(Kaleta\Core\App::class, 'settings'))->setValue($a347App, $a347Settings);
(new ReflectionProperty(Kaleta\Core\Session::class, 'started'))->setValue($a347App->session, true); // the sign-in page's form token: no PHP session in a unit test
$_SESSION ??= [];

// U68: the tabs and the steps that post to them
check('3.47 U68: six tabs in order; every step belongs to one of them; an unknown step to my details', [
    Account::TABS, array_values(array_diff(array_unique(Account::CO_TABS), Account::TABS)), array_values(array_diff(array_unique(Account::FRAGMENT_TABS), Account::TABS)),
    Account::tabFor('heslo'), Account::tabFor('token_novy'), Account::tabFor('absence_add'), Account::tabFor('notifications'), Account::tabFor('nonsense')],
    [['', 'absence', 'security', 'claude', 'signature', 'colleagues'], [], [], 'security', 'claude', 'absence', '', '']);
check('3.47 U68: the requested tab – the org chart is the Colleagues tab by department, Claude only with the extension, anything else my details', [
    Account::requestedTab('org_chart', '', true), Account::requestedTab('colleagues', 'departments', true), Account::requestedTab('colleagues', 'other', true),
    Account::requestedTab('claude', '', false), Account::requestedTab('claude', '', true), Account::requestedTab('security', 'departments', true), Account::requestedTab('../x', '', true)],
    [['colleagues', 'departments'], ['colleagues', 'departments'], ['colleagues', ''], ['', ''], ['claude', ''], ['security', ''], ['', '']]);
check('3.47 U68: the address of a tab, with a fragment', [Account::path(''), Account::path('security', 'passkeys'), Account::path('absence')],
    ['admin.php?action=account', 'admin.php?action=account&tab=security#passkeys', 'admin.php?action=account&tab=absence']);

// U68: the absence partial – first person in My account, third person in Users; the list is named, the first day sets the last
$a347Absences = fn (bool $own): string => Language::runWith('en', fn (): string => $a347View->render('admin/absences', ['app' => $a347App, 'csrf' => '', 'people' => [7 => 'Eva'],
    'absences' => [['id' => 1, 'user_id' => 3, 'starts_on' => '2099-01-02', 'ends_on' => '2099-01-05', 'deputy_id' => 7, 'reason' => 'holiday']],
    'addUrl' => '/a', 'deleteUrl' => '/d', 'hidden' => '', 'deleteHidden' => '', 'own' => $own]), 'admin-');
check('3.47 U68: the deputy\'s empty choice speaks of me in My account and of them in Users; the list has a name; the first day guides the last', [
    str_contains($a347Absences(true), 'nobody – whom I report to'), str_contains($a347Absences(false), 'nobody – whom they report to'), str_contains($a347Absences(false), 'whom I report to'),
    str_contains($a347Absences(true), '<ul class="nepritomnosti" aria-label="Recorded absences">'), str_contains($a347Absences(true), 'data-date-min-for="absence-to"')],
    [true, true, false, true, true]);

// U67: Users, Roles and Departments as one hub
$a347Url = fn (string $path): string => '/' . $path;
$a347Hub = Language::runWith('en', fn (): array => [
    Hubs::tabs('users', ['users' => Users::class, 'roles' => Kaleta\Admin\Modules\Roles::class], 'users', 'departments', $a347Url),
    Hubs::tabs('users', ['users' => Users::class, 'roles' => Kaleta\Admin\Modules\Roles::class], 'users', 'edit', $a347Url),
    Hubs::tabs('users', ['users' => Users::class], 'users', '', $a347Url)], 'admin-');
check('3.47 U67: the hub has three tabs for an administrator, Departments is current on its screen, a user\'s form belongs to Users, without Roles two tabs; Users and Roles share it', [
    substr_count($a347Hub[0], '<a '), str_contains($a347Hub[0], 'href="/admin.php?module=users&amp;action=departments" class="aktivni"'),
    str_contains($a347Hub[1], 'href="/admin.php?module=users" class="aktivni"'), substr_count($a347Hub[2], '<a '), Users::HUB, Kaleta\Admin\Modules\Roles::HUB],
    [3, true, true, 2, 'users', 'users']);
check('3.47 U67: the forms a department may tick – the site\'s, those that sent enquiries and those saved earlier, once each, marked whether enquiries came', [
    Users::departmentForms(['Contact', 'Quote'], ['contact', 'Old form'], ['Retired form', 'QUOTE']), Users::departmentForms([], [], [])],
    [['Contact' => true, 'Old form' => true, 'QUOTE' => false, 'Retired form' => false], []]);

// U69: one primary button on the sign-in page in every state
$a347Login = fn (bool $code, array $steps, bool $keys): string => Language::runWith('en', fn (): string => $a347View->render('admin/login', ['app' => $a347App, 'error' => null, 'login' => '',
    'code' => $code, 'keys' => $keys, 'steps' => $steps, 'passkeyHost' => true, 'canonicalAdmin' => '']), 'admin-');
$a347States = [
    $a347Login(false, ['code' => true, 'backup' => true, 'passkey' => false], false),
    $a347Login(true, ['code' => true, 'backup' => true, 'passkey' => true], true),
    $a347Login(true, ['code' => false, 'backup' => true, 'passkey' => true], true),
    $a347Login(true, ['code' => false, 'backup' => false, 'passkey' => true], true),
    $a347Login(true, ['code' => false, 'backup' => true, 'passkey' => false], false),
];
check('3.47 U69: every state of the sign-in page has exactly one primary button; the password is the primary one by default and carries its hook', [
    array_map(fn (string $html): int => substr_count($html, 'class="tl"'), $a347States),
    str_contains($a347States[0], 'class="tl" type="submit" value="Sign in" data-login-password'), str_contains($a347States[0], 'class="navigace" type="button" data-klic-bezhesla'),
    str_contains($a347States[1], 'class="navigace" type="button" data-klic-prihlasit'), str_contains($a347States[2], 'class="tl" type="button" data-klic-prihlasit')],
    [[1, 1, 1, 1, 1], true, true, true, true]);
check('3.47 U69: the labels of the second step have no colon', [str_contains($a347States[1], '>Verification code</label>'), str_contains($a347States[2], '>Backup code</label>'), str_contains($a347States[1] . $a347States[2], 'code:</label>')],
    [true, true, false]);

// N166: the bell about an absence – what it says, where it leads, and the days only
check('3.47 N166: being named as deputy and an absence recorded by an administrator are kinds of the bell, outside the stand-in copies', [
    in_array('deputy_named', Inbox::KINDS, true), in_array('absence_added', Inbox::KINDS, true), array_intersect(['deputy_named', 'absence_added'], Inbox::STAND_IN_KINDS),
    max(array_map('strlen', Inbox::KINDS)) <= 20 /* ka_notifications.kind is VARCHAR(20) */],
    [true, true, [], true]);
check('3.47 N166: what the bell says, in English and in Czech', Language::runWith('en', fn (): array => [Inbox::what('deputy_named', 'Jana'), Inbox::what('absence_added', 'Petr')], 'admin-')
    + Language::runWith('cs', fn (): array => [2 => Inbox::what('deputy_named', 'Jana') !== '%s named you as deputy', 3 => Inbox::what('absence_added', '') !== 'Kaleta recorded an absence for you'], 'admin-'),
    ['Jana named you as deputy', 'Petr recorded an absence for you', true, true]);
check('3.47 N166: a note without a target leads to My account – the colleagues for a deputy, the absences for the person; other kinds have no such place', [
    Inbox::accountPlace('deputy_named'), Inbox::accountPlace('absence_added')['path'] ?? '', Inbox::accountPlace('mention'), Language::runWith('en', fn (): string => Inbox::where(Inbox::accountPlace('deputy_named')), 'admin-')],
    [['kind' => 'account', 'id' => 0, 'title' => '', 'path' => 'admin.php?action=account&tab=colleagues'], 'admin.php?action=account&tab=absence', null, 'My account']);
check('3.47 N166: the excerpt is the name and the days – never a reason', [Absences::announcement('Hana Head', '2026-12-01', '2026-12-05'), Absences::announcement('', '2026-12-01', '2026-12-01')],
    ['Hana Head: 2026-12-01 – 2026-12-05', '2026-12-01 – 2026-12-01']);
$a347Mail = Inbox::mail($a347Settings, ['jazyk' => 'en'], ['kind' => 'deputy_named', 'actor_name' => 'Jana', 'excerpt' => 'Hana Head: 2026-12-01 – 2026-12-05'], Inbox::accountPlace('deputy_named'), 'https://x.example/admin.php?action=inbox&open=5', 'https://x.example/a');
check('3.47 N166: the instant e-mail says who named whom and when, and where to look', [$a347Mail[0], str_contains($a347Mail[1], 'Jana named you as deputy: My account'), str_contains($a347Mail[1], '“Hana Head: 2026-12-01 – 2026-12-05”')],
    ['Jana named you as deputy', true, true]);

// carried from 3.47 C: a required file left out is not a refused file
$a347Fields = new ReflectionMethod(Form::class, 'fields');
$a347File = ['typ' => 'soubor', 'popisek' => 'CV', 'povinne' => true];
$a347Html = Language::runWith('en', fn (): array => [$a347Fields->invoke(null, $a347File, 2, 'f9', true, '', 2 * 1048576, false, true), $a347Fields->invoke(null, $a347File, 2, 'f9', true, '', 2 * 1048576, false, false)]);
check('3.47 C1 review: a required file field left out asks to fill it in; only a file that was sent and refused says it is too large or of the wrong type', [
    str_contains($a347Html[0], 'Please fill in this field correctly.'), str_contains($a347Html[0], 'cannot be sent'), str_contains($a347Html[1], 'This file cannot be sent: it is larger than 2 MB')],
    [true, false, true]);
