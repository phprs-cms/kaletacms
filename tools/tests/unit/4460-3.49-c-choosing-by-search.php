<?php

declare(strict_types=1);

/* ---------- 3.49 C: choosing by search – one picker over the existing select, five read-only search endpoints ---------- */
use Kaleta\Admin\Module;
use Kaleta\Admin\Modules\Contacts as C449Contacts;
use Kaleta\Admin\Modules\InternalFiles as C449Files;
use Kaleta\Admin\Modules\Pipeline as C449Pipeline;
use Kaleta\Admin\Modules\Quotes as C449Quotes;
use Kaleta\Core\Language;
use Kaleta\Core\Response;

// every data-pick value the views write has a branch (a key of pickSources) in the script, and every branch is written somewhere
$c449Script = (string) file_get_contents(KALETA_ROOT . '/image/admin.js');
$c449Branches = preg_match('/var pickSources = \{([^}]*)\};/', $c449Script, $c449m) === 1 && preg_match_all('/(\w+): \'(\w+)\'/', $c449m[1], $c449k) > 0 ? $c449k[1] : [];
$c449Written = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/views/admin', FilesystemIterator::SKIP_DOTS)) as $c449File) {
    $c449Source = (string) file_get_contents($c449File->getPathname());
    preg_match_all('/data-pick="(\w+)"|\'pick\' => \'(\w+)\'/', $c449Source, $c449Found, PREG_SET_ORDER);
    foreach ($c449Found as $c449One) {
        $c449Written[($c449One[1] ?? '') !== '' ? $c449One[1] : $c449One[2]] = true;
    }
}
$c449Written = array_keys($c449Written);
sort($c449Written);
$c449Sorted = $c449Branches;
sort($c449Sorted);
check('3.49 C: every data-pick value of the views (the select and the input variant) has a branch in image/admin.js and the reverse', [$c449Written, $c449Sorted, $c449Written === $c449Sorted],
    [['companies', 'contacts', 'deals', 'files', 'products'], ['companies', 'contacts', 'deals', 'files', 'products'], true]); // 3.49 A and B: files – the Attachments block of a reply and a quote e-mail, the box of a record

// the five endpoints exist as actions of their own modules (so Module::handle gates them by the feature and the section) and are in the parity map as read-only
$c449Actions = [[new ReflectionClass(C449Contacts::class), 'search_json'], [new ReflectionClass(C449Contacts::class), 'company_search_json'], [new ReflectionClass(C449Pipeline::class), 'search_json'],
    [new ReflectionClass(C449Quotes::class), 'product_search_json'], [new ReflectionClass(C449Files::class), 'search_json']];
check('3.49 C: the search endpoints are actions of Customers, the Pipeline, Quotes and Internal files', array_map(function (array $a): bool {
    $method = 'action' . str_replace('_', '', ucwords($a[1], '_'));
    return $a[0]->hasMethod($method) && $a[0]->getMethod($method)->getName() === $method;
}, $c449Actions), [true, true, true, true, true]);

// no cache keeps an answer; the caps of the no-script first screen stay as they are; the picker asks for eight rows
$c449Json = Response::json(['contacts' => []], 200, Response::NO_STORE);
check('3.49 C: a search answers JSON with Cache-Control no-store; the caps are unchanged; pick=1 asks for 8 rows', [
    $c449Json->headers, Response::json([])->headers, C449Pipeline::CONTACT_CHOICES, C449Pipeline::COMPANY_CHOICES, C449Quotes::DEAL_CHOICES, C449Quotes::PRODUCT_CHOICES, Module::PICK_ROWS],
    [['Content-Type' => 'application/json; charset=utf-8', 'Cache-Control' => 'no-store, private'], ['Content-Type' => 'application/json; charset=utf-8'], 300, 500, 300, 1000, 8]);

// "New customer…": only when no customer is chosen and a name or an e-mail is filled in
check('3.49 C (U32): "New customer…" asks for a contact only without a chosen customer and with a name or an e-mail', [
    C449Pipeline::newContact(' Eva ', '', 0), C449Pipeline::newContact('', 'eva@example.com', 0), C449Pipeline::newContact('Eva', 'eva@example.com', 12), C449Pipeline::newContact('  ', ' ', 0)],
    [['name' => 'Eva', 'email' => ''], ['name' => '', 'email' => 'eva@example.com'], null, null]);

// the rendered deal form: the select stays the value carrier with today's options; the special options keep their values
$c449App = new Kaleta\Core\App([]);
$c449Module = new class { /** @param array<string, scalar> $p */ public function url(string $a = '', array $p = []): string { return 'admin.php?' . http_build_query(['module' => 'pipeline'] + ($a !== '' ? ['action' => $a] : []) + $p); } };
$c449Form = fn (array $draft, int $enquiry, array $companies = [4 => 'Firma <b>'], array $d = ['id' => 0]): string => Language::runWith('en', fn (): string => $c449App->view->render('admin/pipeline/form', ['app' => $c449App,
    'module' => $c449Module, 'csrf' => '', 'd' => $d, 'draft' => $draft, 'contacts' => [7 => 'Eva <i>', 301 => 'Petr'], 'companies' => $companies, 'people' => [], 'stages' => [], 'canContacts' => true, 'me' => 1, 'enquiry' => $enquiry]), 'admin-');
$c449New = $c449Form([], 0);
$c449Enquiry = $c449Form(['contact_id' => '', 'company_id' => ''], 5);
$c449None = $c449Form(['contact_id' => 'none', 'company_id' => 'none'], 5);
$c449Own = $c449Form(['contact_id' => '301'], 0, [], ['id' => 9]);
$c449Refused = $c449Form(['contact_new_name' => 'Ida', 'contact_new_email' => 'ida@example.com'], 0);
check('3.49 C: the deal form – pickers over ordinary selects, "—" and "The enquiry\'s customer" keep 0 and none, the chosen one stays selected, no company field without companies, "New customer…" is a details', [
    preg_match('#<select id="obchod-novy-zakaznik" name="contact_id" data-pick="contacts" data-pick-url="[^"]*admin\.php\?module=contacts&amp;action=search_json">#', $c449New) === 1,
    preg_match('#data-pick="companies" data-pick-url="[^"]*admin\.php\?module=contacts&amp;action=company_search_json"#', $c449New) === 1,
    str_contains($c449New, '<option value="0" data-pick-special selected>—</option>'), str_contains($c449New, 'Eva &lt;i&gt;') && str_contains($c449New, 'Firma &lt;b&gt;'),
    str_contains($c449Enquiry, '<option value="0" data-pick-special selected>The enquiry’s customer</option>'), str_contains($c449Enquiry, '<option value="none" data-pick-special>—</option>'),
    str_contains($c449None, '<option value="none" data-pick-special selected>—</option>'), str_contains($c449None, '<option value="0" data-pick-special>The enquiry’s customer</option>'),
    str_contains($c449Own, '<option value="301" selected>Petr</option>'), str_contains($c449Own, 'name="company_id"'),
    substr_count($c449New, 'data-pick-new'), str_contains($c449New, '<details class="vyber-novy" data-pick-new>'), str_contains($c449Refused, 'data-pick-new open>') && str_contains($c449Refused, 'value="ida@example.com"')],
    [true, true, true, true, true, true, true, true, true, false, 1, true, true]);
