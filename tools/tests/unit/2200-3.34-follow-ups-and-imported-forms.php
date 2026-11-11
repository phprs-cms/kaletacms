<?php

declare(strict_types=1);

use Kaleta\Core\Applications;

/* ---------- 3.34 (integration): an imported career form stays an enquiry form; Move to Applications; site_info; the list_enquiries hint ---------- */
// 3.33 imports old forms as Form elements. An imported form never carries the Purpose and lands on a page (stranka:<id>), so it
// keeps sending enquiries – even a career form – until it sits on a job's item page or someone sets its Purpose.
$f340Find = function (array $nodes) use (&$f340Find): ?array {
    foreach ($nodes as $node) {
        if (($node['typ'] ?? '') === 'formular') {
            return $node;
        }
        $inner = $f340Find($node['deti'] ?? []);
        if ($inner !== null) {
            return $inner;
        }
    }

    return null;
};
$f340Form = $f340Find(Kaleta\Builder\HtmlConverter::convert('<h2>Careers</h2><form action="/apply" method="post"><label for="n">Full name</label><input id="n" name="name" required>'
    . '<label for="cv">Your CV</label><input id="cv" type="file" name="cv"><label for="p">Position</label><select id="p" name="position"><option>Welder</option></select>'
    . '<button type="submit">Send application</button></form>')['stavba']['deti'] ?? []) ?? [];
check('3.34: an imported career form is a Form element without the Purpose – an enquiry form on a page; with the Purpose or on a job page it is an application form', [
    $f340Form !== [], $f340Form['obsah']['ucel'] ?? '',
    Applications::isApplicationForm($f340Form['obsah'] ?? [], 'stranka:7', ['kolekce:3']),
    Applications::isApplicationForm(['ucel' => Applications::PURPOSE] + ($f340Form['obsah'] ?? []), 'stranka:7', ['kolekce:3']),
    Applications::isApplicationForm($f340Form['obsah'] ?? [], 'kolekce:3', ['kolekce:3']),
    Applications::isApplicationForm($f340Form['obsah'] ?? [], 'popup:2', [])],
    [true, '', false, true, true, false]);
check('3.34: the importers never write the Purpose of a form nor a jobs collection (the jobs preset comes only from Collections)', [
    preg_match("/'ucel'/", (string) file_get_contents(KALETA_SYSTEM . '/src/Builder/HtmlConverter.php') . file_get_contents(KALETA_SYSTEM . '/src/Builder/HtmlWidgets.php') . file_get_contents(KALETA_SYSTEM . '/src/Core/WebImport.php')),
    preg_match('/Jobs::PRESET|\'jobs\'/', (string) file_get_contents(KALETA_SYSTEM . '/src/Core/WpImport.php') . file_get_contents(KALETA_SYSTEM . '/src/Core/WebImport.php'))],
    [0, 0]);

// Move to Applications on one enquiry (3.34): for a person with both sections, the steps of the move by form, the detail of the application next
$f340Enquiries = (string) file_get_contents(KALETA_SYSTEM . '/src/Admin/Modules/Enquiries.php');
$f340Detail = (string) file_get_contents(KALETA_SYSTEM . '/views/admin/enquiries/detail.php');
$f340Move = (string) file_get_contents(KALETA_SYSTEM . '/src/Core/ApplicationMove.php');
check('3.34: Move to Applications – the action needs the section Applications, uses ApplicationMove for one row and opens the application; the button only for those who may', [
    str_contains($f340Enquiries, "protected function actionToApplication(): Response"),
    str_contains($f340Enquiries, "!isset(\$this->kernel->modules()[\\Kaleta\\Core\\Applications::SECTION])"),
    str_contains($f340Enquiries, '\Kaleta\Core\ApplicationMove::enquiryToApplication($this->app, $idp)'),
    str_contains($f340Enquiries, "'toApplication' => isset(\$this->kernel->modules()[\\Kaleta\\Core\\Applications::SECTION])"),
    str_contains($f340Detail, "<?php if (\$toApplication):") && str_contains($f340Detail, "action=\"<?= e(\$module->url('to_application')) ?>\" data-to-application data-potvrdit="),
    str_contains($f340Move, 'public static function enquiryToApplication(App $app, int $idp): ?array') && substr_count($f340Move, 'self::moveRows($app, $rows)') === 2],
    [true, true, true, true, true, true]);
check('3.34: Move to Applications never clears the page cache (Enquiries is a site-neutral section)', [
    Kaleta\Admin\Kernel::clearsPageCache(new Kaleta\Core\Request(['module' => 'enquiries', 'action' => 'to_application'], ['idp' => '1'], ['REQUEST_METHOD' => 'POST']))], [false]);

// site_info features.applications and the list_enquiries hint (additive keys)
$f340Pages = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/PageTools.php');
$f340List = (string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Handlers/EnquiryAndPopupTools.php');
check('3.34: site_info carries features.applications (on, the switch for Claude, whether this user may open them); list_enquiries points kind job to list_applications only with the feature on', [
    str_contains($f340Pages, "'features' => ['applications' => ['enabled' => \\Kaleta\\Core\\Applications::isOn(\$siteSettings), 'claude' => \$siteSettings->bool('applications_claude'),"),
    str_contains($f340List, "\$applicationsHint = \\Kaleta\\Core\\Applications::isOn(\$siteSettings)"),
    str_contains($f340List, "+ (\$applicationsHint !== null && \$p['kategorie'] === 'job' ? ['applications_hint' => \$applicationsHint] : []),"),
    str_contains((string) file_get_contents(KALETA_SYSTEM . '/src/Mcp/Translator.php'), 'With Applications on (site_info features.applications) job applications are in list_applications')],
    [true, true, true, true]);
