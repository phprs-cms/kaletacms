<?php

declare(strict_types=1);

/* ---------- 2.14: whistleblowing channel (Core\Whistleblowing) ---------- */
$wb = Kaleta\Core\Whistleblowing::class;
check('2.14 Whistleblowing: the case number is the year and a four-digit sequence', [$wb::number(2026, 7), $wb::number(2026, 12345), $wb::isNumber('2026-0007'), $wb::isNumber('26-7'), $wb::isNumber('2026-0007x')],
    ['2026-0007', '2026-12345', true, false, false]);
$wbCode = $wb::newCode();
check('2.14 Whistleblowing: the access code has 20 unambiguous characters in groups of five; its hash is bound to the case and ignores case and dashes',
    [strlen($wb::normalizeCode($wbCode)), preg_match('/^[A-Z2-9]{5}(-[A-Z2-9]{5}){3}$/', $wbCode), preg_match('/[01IOL]/', $wbCode), strlen($wb::codeHash('2026-0001', $wbCode)),
        $wb::codeHash('2026-0001', $wbCode) === $wb::codeHash('2026-0001', strtolower(str_replace('-', '', $wbCode))), $wb::codeHash('2026-0001', $wbCode) === $wb::codeHash('2026-0002', $wbCode)],
    [20, 1, 0, 64, true, false]);
$wbSettings = static function (string $key): Kaleta\Core\Settings {
    $s = (new ReflectionClass(Kaleta\Core\Settings::class))->newInstanceWithoutConstructor();
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'values'))->setValue($s, ['secret_key' => $key]);
    (new ReflectionProperty(Kaleta\Core\Settings::class, 'db'))->setValue($s, new Kaleta\Core\Db('mysql:host=127.0.0.1;dbname=none', '', '')); // never connects: the key is set

    return $s;
};
$wbSite = $wbSettings(str_repeat('ab', 32));
$wbSealed = $wb::encrypt($wbSite, 'Vedoucí skladu falšuje evidenci.');
check('2.14 Whistleblowing: encrypt/decrypt round trip; another site, a damaged value and the connectors key read nothing',
    [$wb::decrypt($wbSite, $wbSealed), str_contains($wbSealed, 'skladu'), $wbSealed === $wb::encrypt($wbSite, 'Vedoucí skladu falšuje evidenci.'), $wb::decrypt($wbSettings(str_repeat('cd', 32)), $wbSealed),
        $wb::decrypt($wbSite, substr($wbSealed, 0, 10)), $wb::decrypt($wbSite, null), Kaleta\Core\Connectors::decrypt($wbSite, $wbSealed)],
    ['Vedoucí skladu falšuje evidenci.', false, false, null, null, null, null]);
$wbCase = ['created_at' => '2026-01-30 10:00:00', 'acknowledged_at' => null, 'status' => 'received', 'feedback_due' => '2026-04-30 10:00:00'];
check('2.14 Whistleblowing: deadlines – the acknowledgement in 7 days, the feedback in 3 months; what is overdue when',
    [$wb::deadlines('2026-01-30 10:00:00'), $wb::overdue($wbCase, '2026-02-06 09:00:00'), $wb::overdue($wbCase, '2026-02-07 09:00:00'), $wb::overdue($wbCase, '2026-05-01 00:00:00'),
        $wb::overdue(['acknowledged_at' => '2026-02-01 08:00:00', 'status' => 'in_progress'] + $wbCase, '2026-05-01 00:00:00'), $wb::overdue(['status' => 'closed'] + $wbCase, '2027-01-01 00:00:00')],
    [['acknowledge_by' => '2026-02-06 10:00:00', 'feedback_due' => '2026-04-30 10:00:00'], ['acknowledgement' => false, 'feedback' => false], ['acknowledgement' => true, 'feedback' => false],
        ['acknowledgement' => true, 'feedback' => true], ['acknowledgement' => false, 'feedback' => true], ['acknowledgement' => false, 'feedback' => false]]);
$wbTools = array_column(Kaleta\Mcp\Translator::listAll(Kaleta\Mcp\Tools::definitions()), 'name');
check('2.14 Whistleblowing: no MCP tool touches the cases, the public address is reserved, the job runs daily, the export leaves the tables out',
    [array_values(array_filter($wbTools, fn (string $n): bool => str_contains($n, 'whistle') || str_contains($n, 'report_case'))), in_array('_report', Kaleta\Admin\Modules\Pages::RESERVED_SLUGS, true),
        Kaleta\Core\Scheduler::JOBS['whistleblowing'][0], preg_match('/whistleblowing_(cases|messages)}/', (string) file_get_contents(KALETA_SYSTEM . '/src/Core/SiteExport.php'))],
    [[], true, 86400, 0]);
