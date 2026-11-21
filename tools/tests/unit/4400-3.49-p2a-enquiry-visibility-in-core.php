<?php

declare(strict_types=1);

use Kaleta\Admin\Modules\Enquiries;
use Kaleta\Core\EnquiryVisibility;

/* ---------- 3.49 pre-step P2a: the visibility condition of enquiry reads lives in Core\EnquiryVisibility ---------- */
// Enquiries::visible() and stillMoving() moved down to Core (decision D3), so Core\Quotes and the other Core readers no longer
// name the admin module for it; the admin names stay as forwards for the admin, MCP and the tests.
$p2aSignature = static function (ReflectionMethod $m): string {
    $params = array_map(static fn (ReflectionParameter $p): string => ($p->getType() ?? 'mixed') . ($p->isPassedByReference() ? ' &' : ' ') . '$' . $p->getName()
        . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : ''), $m->getParameters());

    return ($m->isStatic() ? 'static ' : '') . ($m->isPublic() ? 'public ' : 'private ') . $m->getName() . '(' . implode(', ', $params) . '): ' . ($m->getReturnType() ?? 'mixed');
};
check('3.49 P2a: Enquiries::visible() and stillMoving() are forwards with the signature of Core\\EnquiryVisibility', array_map(
    static fn (string $name): bool => $p2aSignature(new ReflectionMethod(Enquiries::class, $name)) === $p2aSignature(new ReflectionMethod(EnquiryVisibility::class, $name)),
    ['visible', 'stillMoving']), [true, true]);

// the same condition through both names: a twin by alias after migration 0119, nothing before it
$p2aSettings = new Kaleta\Core\Settings(new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', ''));
$p2aValues = new ReflectionProperty(Kaleta\Core\Settings::class, 'values');
$p2aValues->setValue($p2aSettings, ['db_version' => '120', 'applications_move_until' => '']);
$p2aAfter = [EnquiryVisibility::visible($p2aSettings->db(), $p2aSettings, true, 'p'), Enquiries::visible($p2aSettings->db(), $p2aSettings, true, 'p'),
    EnquiryVisibility::stillMoving($p2aSettings->db(), $p2aSettings) === Enquiries::stillMoving($p2aSettings->db(), $p2aSettings)];
$p2aValues->setValue($p2aSettings, ['db_version' => '118', 'applications_move_until' => '']);
check('3.49 P2a: the condition is the same through the forward and through Core (a twin by alias; before migration 0119 nothing to hide)', [
    ...$p2aAfter, EnquiryVisibility::visible($p2aSettings->db(), $p2aSettings, false), EnquiryVisibility::stillMoving($p2aSettings->db(), $p2aSettings)], [
    'NOT EXISTS (SELECT 1 FROM {applications} twin WHERE twin.source_enquiry_id = p.idp AND twin.received_at = p.datum)',
    'NOT EXISTS (SELECT 1 FROM {applications} twin WHERE twin.source_enquiry_id = p.idp AND twin.received_at = p.datum)', true, '1 = 1', ' AND 1 = 1']);

// no Core file reaches the admin module for the condition any more (the layer test freezes what is left: deleteExpired, deleteAttachments, NOT_SPAM)
$p2aUp = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(KALETA_SYSTEM . '/src/Core', FilesystemIterator::SKIP_DOTS)) as $p2aFile) {
    if (str_ends_with((string) $p2aFile, '.php') && preg_match('/Modules\\\\Enquiries::(?:visible|stillMoving)\(|\bEnquiries::(?:visible|stillMoving)\(/', (string) file_get_contents((string) $p2aFile)) === 1) {
        $p2aUp[] = basename((string) $p2aFile);
    }
}
check('3.49 P2a: no Core file calls Admin\\Modules\\Enquiries::visible() or stillMoving()', $p2aUp, []);
