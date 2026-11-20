<?php

declare(strict_types=1);

use Kaleta\Admin\SectionSettings;
use Kaleta\Core\Retention;
use Kaleta\Core\Settings;

/* ---------- 3.48 B (U40, U70, U31): every retention period in one registry and on the Privacy tab, Settings of sections, the stage arrows ---------- */

$b348Fields = (new ReflectionClassConstant(Kaleta\Admin\Modules\Settings::class, 'FIELDS'))->getValue();
check('3.48 B1: every setting of Retention::PERIODS has a default, and a field on the Privacy tab with the same range – or a screen of its own (edit)', [
    array_values(array_filter(array_column(Retention::PERIODS, 'setting'), fn (string $k): bool => $k !== '' && !array_key_exists($k, Settings::DEFAULTS))),
    array_keys(array_filter(Retention::PERIODS, fn (array $row): bool => $row['setting'] !== '' && !isset($row['edit'])
        && ($b348Fields['cookies'][$row['setting']] ?? null) !== 'cislo:' . $row['min'] . ':' . $row['max'])),
    array_keys(array_filter(Retention::PERIODS, fn (array $row): bool => isset($row['edit']) && ($b348Fields['backups'][$row['setting']] ?? null) !== 'cislo:' . $row['min'] . ':' . $row['max'])),
    $b348Fields['cookies']['enquiries_expiry'], array_keys(array_filter(Retention::PERIODS, fn (array $row): bool => $row['setting'] === '' && !isset($row['follows']))),
], [[], [], [], 'vyber:delete|anonymise', []]);
check('3.48 B1: a setting named like a period (…_months, …_days, …retention…) is in PERIODS or in NOT_A_PERIOD – a new period cannot slip past the registry', [
    array_values(array_filter(array_keys(Settings::DEFAULTS), fn (string $k): bool => (str_ends_with($k, '_months') || str_ends_with($k, '_days') || str_contains($k, 'retention'))
        && !in_array($k, array_column(Retention::PERIODS, 'setting'), true) && !isset(Retention::NOT_A_PERIOD[$k]))),
    array_values(array_diff(array_keys(Retention::NOT_A_PERIOD), array_keys(Settings::DEFAULTS))),
], [[], []]);
check('3.48 B1: every step of the daily clean-up is a period (PERIODS or FIXED) or a sweep with its reason; each fixed row reads the constant it names', [
    array_values(array_filter(array_keys(Kaleta\Core\Notifications::cleanups(new Kaleta\Core\App([]))), fn (string $k): bool => !isset(Retention::PERIODS[$k]) && !isset(Retention::FIXED[$k]) && !isset(Retention::SWEEPS[$k]))),
    array_keys(array_filter(Retention::FIXED, fn (array $row): bool => !defined($row[3]) || constant($row[3]) !== $row[1])),
    array_intersect(['bookings', 'enquiries', 'customers', 'quotes', 'applications', 'consents', 'job_enquiries'], array_keys(Kaleta\Core\Notifications::cleanups(new Kaleta\Core\App([])))) === ['bookings', 'enquiries', 'customers', 'quotes', 'applications', 'consents', 'job_enquiries'],
    Kaleta\Admin\Modules\Pages::TRASH_DAYS, Retention::HOLDS,
], [[], [], true, 30, []]);

// effective() equals what each cleanup read before 3.48, for every combination of zero and non-zero
$b348Old = static function (array $v): array {
    $i = fn (string $k): int => (int) ($v[$k] ?? Settings::DEFAULTS[$k]);

    return ['enquiries' => $i('enquiries_months'), 'bookings' => $i('enquiries_months'), 'customers' => max(0, $i('contacts_months')), 'quotes' => max(0, $i('quotes_months')),
        'applications' => $i('applications_months'), 'talent_pool' => max(1, $i('applications_pool_months')), 'whistleblowing' => $i('whistleblowing_retention_months') ?: 24,
        'consents' => $i('cookies_log_months'), 'offsite_backups' => $i('backup_remote_days'),
        // job enquiries: purgeApplications deletes after the stored period; 0 = like other enquiries (deleteExpired)
        'job_enquiries' => $i('job_applications_months') > 0 ? $i('job_applications_months') : $i('enquiries_months')];
};
$b348Diff = [];
foreach ([0, 12] as $b348A) {
    foreach ([0, 6] as $b348B) {
        foreach ([0, 24] as $b348C) {
            $b348V = ['enquiries_months' => (string) $b348A, 'job_applications_months' => (string) $b348B, 'contacts_months' => (string) $b348C, 'quotes_months' => (string) $b348A,
                'applications_months' => (string) $b348B, 'applications_pool_months' => (string) $b348C, 'whistleblowing_retention_months' => (string) $b348A, 'cookies_log_months' => (string) $b348B, 'backup_remote_days' => (string) $b348C];
            $b348S = $reportSettings($b348V);
            foreach ($b348Old($b348V) as $b348Key => $b348Was) {
                if (Retention::effective($b348S, $b348Key) !== $b348Was) {
                    $b348Diff[] = "$b348Key $b348A/$b348B/$b348C: " . Retention::effective($b348S, $b348Key) . " ≠ $b348Was";
                }
            }
            if (Retention::value($b348S, 'job_enquiries') !== $b348B) {
                $b348Diff[] = "job_enquiries value $b348B";
            }
        }
    }
}
check('3.48 B1: effective() gives every cleanup the number it read before (all zero / non-zero combinations); value() is the stored one', $b348Diff, []);

// changes(): compared by the effective periods; a harsher change and its consequences
$b348Changes = fn (array $stored, array $proposed): array => array_map(fn (array $c): string => $c['key'] . ':' . $c['from'] . '>' . $c['to'] . ($c['harsher'] ? ' harsher' : '') . ($c['consequence'] ? ' follows' : ''),
    Retention::changes($reportSettings($stored), $proposed));
check('3.48 B1: changes() – job enquiries 12 → 0 (as enquiries again) is longer, 0 → 12 is harsher; enquiries 24 → 12 takes bookings and the inheriting job period with it; anonymise → delete is harsher', [
    $b348Changes(['enquiries_months' => '24', 'job_applications_months' => '12'], ['job_applications_months' => '0']),
    $b348Changes(['enquiries_months' => '24', 'job_applications_months' => '0'], ['job_applications_months' => '12']),
    $b348Changes(['enquiries_months' => '24', 'job_applications_months' => '0'], ['enquiries_months' => '12']),
    Retention::harsherSettings(Retention::changes($reportSettings(['enquiries_months' => '24', 'job_applications_months' => '0']), ['enquiries_months' => '12', 'contacts_months' => '36'])),
    $b348Changes(['enquiries_expiry' => 'anonymise'], ['enquiries_expiry' => 'delete']), $b348Changes(['enquiries_expiry' => 'delete'], ['enquiries_expiry' => 'anonymise']),
    $b348Changes(['enquiries_months' => '0', 'job_applications_months' => '0'], ['enquiries_months' => '12']), $b348Changes(['enquiries_months' => '12'], ['enquiries_months' => '0']),
    $b348Changes(['enquiries_months' => '24'], ['enquiries_months' => '24']),
], [
    ['job_enquiries:12>24'], ['job_enquiries:24>12 harsher'], ['enquiries:24>12 harsher', 'job_enquiries:24>12 harsher follows', 'bookings:24>12 harsher follows'], ['enquiries_months'],
    ['enquiries_expiry:anonymise>delete harsher'], ['enquiries_expiry:delete>anonymise'],
    ['enquiries:0>12 harsher', 'job_enquiries:0>12 harsher follows', 'bookings:0>12 harsher follows'], ['enquiries:12>0', 'job_enquiries:12>0 follows', 'bookings:12>0 follows'], [],
]);

// the legal hold, reserved: the floor refuses a lower value, held() asks a registered source and nothing else
$b348Hold = get_class(new class () {
    /** @param list<int> $ids */
    public static function kept(Kaleta\Core\Db $db, array $ids): array
    {
        return [7 => ['until' => '2036-12-31', 'reason' => 'invoice'], 99 => ['until' => '2030-01-01', 'reason' => 'not asked']];
    }
});
$b348Db = (new ReflectionClass(Kaleta\Core\Db::class))->newInstanceWithoutConstructor();
check('3.48 B1: legal hold – the floor refuses a period below it, parse() refuses (never clamps) a value outside the range; held() asks only a registered source and keeps only the ids asked', [
    Retention::allowed(['min' => 0, 'max' => 120, 'floor' => 6] + Retention::PERIODS['quotes'], 3), Retention::allowed(['floor' => 6] + Retention::PERIODS['quotes'], 6), Retention::allowed(Retention::PERIODS['quotes'], 0),
    Retention::allowed(Retention::PERIODS['talent_pool'], 0), Retention::allowed(Retention::PERIODS['quotes'], 121),
    Retention::parse(['enquiries_months' => '12', 'quotes_months' => '121', 'contacts_months' => '-1', 'applications_pool_months' => '0', 'enquiries_expiry' => 'shred', 'backup_remote_days' => '5', 'site_name' => 'x']),
    Retention::parse(['enquiries_months' => ' 07 ', 'enquiries_expiry' => 'delete', 'whistleblowing_retention_months' => '120']),
    Retention::held($b348Db, 'quotes', [7, 8], ['quotes' => [[$b348Hold, 'kept']]]), Retention::held($b348Db, 'quotes', [7, 8]), Retention::held($b348Db, 'enquiries', [7], ['quotes' => [[$b348Hold, 'kept']]]),
], [false, true, true, false, false,
    [['enquiries_months' => '12'], ['contacts_months', 'quotes_months', 'applications_pool_months', 'enquiries_expiry']],
    [['enquiries_months' => '7', 'whistleblowing_retention_months' => '120', 'enquiries_expiry' => 'delete'], []],
    [7 => ['until' => '2036-12-31', 'reason' => 'invoice']], [], []]);

check('3.48 B5: the rows of processing_record → retention keep their shape (later releases only add keys); features that are off are listed too', [
    array_values(array_unique(array_map('array_keys', Retention::mcpRows($reportSettings(['extensions' => 'poptavky']))), SORT_REGULAR)),
    array_column(Retention::mcpRows($reportSettings(['extensions' => 'poptavky'])), 'key') === array_keys(Retention::PERIODS),
    array_column(array_filter(Retention::mcpRows($reportSettings(['extensions' => 'poptavky'])), fn (array $r): bool => !$r['feature_on']), 'key'),
    array_column(array_filter(Retention::mcpRows($reportSettings([])), fn (array $r): bool => $r['key'] === 'bookings' || $r['key'] === 'job_enquiries'), 'zero'),
    Retention::editable(),
], [[['key', 'label', 'value', 'unit', 'zero', 'editable', 'feature_on']], true, ['bookings', 'customers', 'quotes', 'applications', 'talent_pool', 'whistleblowing'], ['inherit:enquiries', 'follows:enquiries'],
    ['enquiries_months', 'job_applications_months', 'contacts_months', 'quotes_months', 'applications_months', 'applications_pool_months', 'whistleblowing_retention_months', 'cookies_log_months']]);
check('3.48 B1: the line beside a section and the change log – the period with what follows it, "without limit" for 0', Kaleta\Core\Language::runWith('en', fn (): array => [
    Retention::summary($reportSettings(['enquiries_months' => '24']), 'enquiries'), Retention::summary($reportSettings(['enquiries_months' => '24', 'enquiries_expiry' => 'anonymise']), 'bookings'),
    Retention::summary($reportSettings(['contacts_months' => '0']), 'customers'), Retention::summary($reportSettings(['backup_remote_days' => '90']), 'offsite_backups'),
    Retention::changeLine(['key' => 'enquiries', 'from' => 24, 'to' => 12, 'unit' => 'months']), Retention::changeLine(['key' => 'enquiries_expiry', 'from' => 'anonymise', 'to' => 'delete', 'unit' => '']),
]), ['24 months, then deleted', '24 months, then anonymised', 'without limit', '90 days', 'Enquiries: 24 months → 12 months', 'After the period: anonymise → delete']);

// B3: Settings of sections – only the sections the person may open, every link to a real action of its section
$b348Classes = [];
foreach ((new ReflectionClassConstant(Kaleta\Admin\Kernel::class, 'MODULES'))->getValue() as $b348Class) {
    $b348Classes[$b348Class::IDENT] = $b348Class;
}
$b348Broken = [];
foreach (SectionSettings::ROWS as $b348Section => [, $b348Links]) {
    foreach ($b348Links as [$b348Label, $b348Query]) {
        parse_str((string) strtok($b348Query, '#'), $b348Q);
        $b348Action = 'action' . str_replace('_', '', ucwords((string) ($b348Q['action'] ?? 'list'), '_'));
        if (($b348Q['module'] ?? '') !== $b348Section || !isset($b348Classes[$b348Section]) || !method_exists($b348Classes[$b348Section], $b348Action)) {
            $b348Broken[] = "$b348Section: $b348Query";
        }
    }
}
check('3.48 B3: Settings of sections lists only the sections the person may open, in its order; every link opens a real action of its own section; the tab has no fields and comes right after General', [
    array_keys(SectionSettings::links(['pipeline', 'quotes', 'nonexistent', 'pages'])), SectionSettings::links([]), $b348Broken,
    $b348Fields['sections'], array_slice(array_keys(Kaleta\Admin\Modules\Settings::TABS), 0, 2), Kaleta\Admin\Guide::SETTINGS['sections'] ?? null,
    array_column(Kaleta\Admin\Palette::quick(['settings'], true), 1), Kaleta\Admin\Palette::quick(['settings'], false) !== [],
], [['quotes', 'pipeline'], [], [], [], ['general', 'sections'], 'first-steps', ['module=settings&tab=sections', 'module=settings&tab=cookies#retention'], true]);
check('3.48 B2: the shorter retention screen is a Settings action no visitor sees (the page cache stays); the hints name the new place in the admin language', [
    method_exists(Kaleta\Admin\Modules\Settings::class, 'actionRetention'), in_array('retention', Kaleta\Admin\Kernel::SITE_NEUTRAL_ACTIONS['settings'], true),
    Kaleta\Core\Language::runWith('cs', fn (): int => substr_count(Kaleta\Admin\MenuPaths::links('/admin.php', t('Retention: not limited – set it in Settings → Privacy and cookies.') . ' ' . t('Settings') . ' → ' . t('Privacy and cookies') . ' → ' . t('How long data is kept'), ['settings']), 'tab=cookies'), 'admin-'),
    Kaleta\Core\Language::runWith('cs', fn (): string => t('How long data is kept'), 'admin-'),
], [true, true, 2, 'Jak dlouho se data uchovávají']);

// B4 (U31): the stage arrows – a shift swaps a row with its neighbour in the saved order; past either end, removed or empty, nothing moves
$b348Stages = fn (string $shift, array $remove = []): array => array_column(Kaleta\Admin\Modules\Pipeline::stageRows(['new', 'qualified', 'won', 'lost', ''], ['New', 'Qualified', 'Won', 'Lost', ''], ['open', 'open', 'won', 'lost', 'open'], ['1', '2', '3', '4', ''], $remove, $shift), 'key');
check('3.48 B4: stageRows() – up and down swap with the neighbour, nothing moves past the ends, for a removed or an empty row or a malformed shift; the 3.31 numbers still sort', [
    $b348Stages('up:1'), $b348Stages('down:1'), $b348Stages('up:0'), $b348Stages('down:3'), $b348Stages('down:4'), $b348Stages('up:2', ['1']), $b348Stages('sideways:1'), $b348Stages(''),
    array_column(Kaleta\Admin\Modules\Pipeline::stageRows(['a', 'b', 'c'], ['A', 'B', 'C'], ['open', 'won', 'lost'], ['3', '1', '2'], [], 'up:0'), 'key'),
], [['qualified', 'new', 'won', 'lost'], ['new', 'won', 'qualified', 'lost'], ['new', 'qualified', 'won', 'lost'], ['new', 'qualified', 'won', 'lost'], ['new', 'qualified', 'won', 'lost'],
    ['won', 'new', 'lost'], ['new', 'qualified', 'won', 'lost'], ['new', 'qualified', 'won', 'lost'], ['b', 'a', 'c']]);
