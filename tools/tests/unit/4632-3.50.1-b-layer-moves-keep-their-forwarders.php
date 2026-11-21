<?php

declare(strict_types=1);

/* ---------- 3.50.1 B2 (CODE-c1, CPO-l12): four helpers moved to Core, the old names forward ---------- */
// Core stopped calling into the administration and Front for page slugs, the edit stamp, enquiry spam and files, the
// redirect store and the statistics switch. The old public names stay (an admin or MCP caller, an add-on, and old code in
// memory during an update still call them), so each forwarder must keep the signature of its Core target and answer the same.
use Kaleta\Admin\Modules\Enquiries as B632Enquiries;
use Kaleta\Admin\Modules\Pages as B632Pages;
use Kaleta\Admin\Modules\Redirects as B632Redirects;
use Kaleta\Core\Clock as B632Clock;
use Kaleta\Core\Db as B632Db;
use Kaleta\Core\EnquiryFiles as B632Files;
use Kaleta\Core\EnquiryVisibility as B632Visibility;
use Kaleta\Core\RedirectStore as B632Store;
use Kaleta\Core\Routes as B632Routes;
use Kaleta\Core\StatsGate as B632Gate;
use Kaleta\Core\TeamSummary as B632Team;
use Kaleta\Front\Stats as B632Stats;

// the signature as text: static, parameters with their types, defaults and by-reference flags, and the return type
$b632Signature = static function (string $class, string $method): string {
    $m = new ReflectionMethod($class, $method);
    $params = array_map(static fn (ReflectionParameter $p): string => (string) $p->getType() . ($p->isPassedByReference() ? ' &' : ' ') . '$' . $p->getName()
        . ($p->isDefaultValueAvailable() ? ' = ' . var_export($p->getDefaultValue(), true) : ''), $m->getParameters());

    return ($m->isStatic() ? 'static ' : '') . ($m->isPublic() ? 'public ' : 'non-public ') . '(' . implode(', ', $params) . '): ' . (string) $m->getReturnType();
};
$b632Pairs = [
    [B632Pages::class, 'slugReserved', B632Routes::class, 'slugReserved'],
    [B632Pages::class, 'edited', B632Clock::class, 'edited'],
    [B632Enquiries::class, 'deleteAttachments', B632Files::class, 'delete'],
    [B632Redirects::class, 'twin', B632Store::class, 'twin'],
    [B632Redirects::class, 'add', B632Store::class, 'add'],
    [B632Redirects::class, 'takeOver', B632Store::class, 'takeOver'],
    [B632Stats::class, 'isOn', B632Gate::class, 'isOn'],
    [B632Stats::class, 'enabled', B632Gate::class, 'enabled'],
    [B632Stats::class, 'isBot', B632Gate::class, 'isBot'],
];
$b632Mismatch = [];
foreach ($b632Pairs as [$b632Old, $b632OldMethod, $b632New, $b632NewMethod]) {
    if ($b632Signature($b632Old, $b632OldMethod) !== $b632Signature($b632New, $b632NewMethod)) {
        $b632Mismatch[] = $b632Old . '::' . $b632OldMethod . ' ≠ ' . $b632New . '::' . $b632NewMethod;
    }
}
check('3.50.1 B2: every old name forwards with the very signature of its Core target (Pages::slugReserved/edited, Enquiries::deleteAttachments, Redirects::twin/add/takeOver, Stats::isOn/enabled/isBot)', $b632Mismatch, []);

check('3.50.1 B2: the moved constants are one value – Pages::RESERVED_SLUGS is Routes::RESERVED_SLUGS, and the three NOT_SPAM conditions (Enquiries, TeamSummary, EnquiryVisibility) are identical', [
    B632Pages::RESERVED_SLUGS === B632Routes::RESERVED_SLUGS, in_array('formular', B632Routes::RESERVED_SLUGS, true), in_array('subscription', B632Routes::RESERVED_SLUGS, true),
    B632Enquiries::NOT_SPAM === B632Visibility::NOT_SPAM, B632Team::NOT_SPAM === B632Visibility::NOT_SPAM, B632Visibility::NOT_SPAM,
], [true, true, true, true, true, "kategorie <> 'spam'"]);

// the same answers for sample input (no database: a slug check without one, the stamp, the bot rule, the switch from settings)
$b632Slugs = ['admin', 'formular', 'subscription', 'en', 'o-nas', 'sluzby/admin', '_report', 'cenik'];
$b632Agents = ['Mozilla/5.0 (Macintosh; Intel Mac OS X 14_5) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', 'curl/8.4.0', 'WhatsApp/2.23.20', ''];
$b632Settings = static function (string $extensions): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, ['extensions' => $extensions, 'site_language' => 'en']);

    return $s;
};
check('3.50.1 B2: the forwarders answer what their Core targets answer – reserved slugs (a word of the system, an English system path, a language code; a subpage and a plain slug are free), the edit stamp, the bot rule and the statistics switch', [
    array_map(static fn (string $s): bool => B632Pages::slugReserved($s, null), $b632Slugs),
    array_map(static fn (string $s): bool => B632Routes::slugReserved($s, null), $b632Slugs),
    [B632Pages::edited(7)['zmenil'], B632Pages::edited(0)['zmenil'], array_keys(B632Pages::edited(3))],
    [B632Clock::edited(7)['zmenil'], B632Clock::edited(0)['zmenil'], array_keys(B632Clock::edited(3))],
    array_map(B632Stats::isBot(...), $b632Agents), array_map(B632Gate::isBot(...), $b632Agents),
    [B632Stats::enabled($b632Settings('statistika')), B632Stats::enabled($b632Settings('-')), B632Gate::enabled($b632Settings('statistika')), B632Gate::enabled($b632Settings('-'))],
], [
    [true, true, true, true, false, false, true, false], [true, true, true, true, false, false, true, false],
    [7, null, ['zmeneno', 'zmenil']], [7, null, ['zmeneno', 'zmenil']],
    [false, true, true, true, false], [false, true, true, true, false],
    [true, false, true, false],
]);

// the attachment clean-up keeps a file whenever it cannot ask whether an application still lists it (any database error
// but a missing table means "listed" – N9), so a lost connection never deletes a CV; with no files it asks nothing
$b632Dir = KALETA_ROOT . '/storage/prilohy/2099/01';
$b632Name = bin2hex(random_bytes(12)) . '.pdf';
$b632Made = is_dir($b632Dir) || @mkdir($b632Dir, 0775, true);
$b632Written = $b632Made && file_put_contents($b632Dir . '/' . $b632Name, 'cv') !== false;
$b632Down = new B632Db('mysql:host=127.0.0.1;port=1;dbname=none', 'none', '');
B632Files::delete([['idp' => 1, 'data' => json_encode([['CV', 'cv.pdf', '2099/01/' . $b632Name]])]], $b632Down);
$b632Kept = is_file($b632Dir . '/' . $b632Name);
B632Enquiries::deleteAttachments([['idp' => 2, 'data' => json_encode([['Name', 'Jan']])]], $b632Down); // no path: nothing to ask, nothing thrown
@unlink($b632Dir . '/' . $b632Name);
@rmdir($b632Dir);
@rmdir(dirname($b632Dir));
check('3.50.1 B2: EnquiryFiles::delete (and the Enquiries forwarder) keeps a file when the database cannot say whether an application lists it', [$b632Written, $b632Kept], [true, true]);
