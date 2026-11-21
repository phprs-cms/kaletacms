<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.48 B (U40): how long the site keeps personal data – one registry of the retention periods. The Privacy tab of Settings
 * shows and edits them, the daily clean-up (Notifications::purgePersonalData) and the other cleanups read the number they
 * work with from here (effective()), the record of processing lists them (rows(), MCP processing_record → retention).
 *
 * - PERIODS: the periods with a setting of their own (or following one: bookings follow the enquiries). A shorter period is
 *   confirmed on its own screen (Settings → retention, changes()); a period of a feature that is off still applies to the
 *   data already stored, so its row is listed too.
 * - FIXED: what the site keeps for a fixed time, read-only – each row names the constant the cleanup reads.
 * - Legal hold (roadmap 3.51 §3.6), reserved and unused in 3.48: `floor` on every row (a period cannot be set below it – all
 *   0 now) and HOLDS / held() – the place a later release registers records kept by law ("kept until"); no purge asks yet.
 *
 * The array keys are the contract: new rows and new row keys are additive, an existing one never changes its meaning.
 */
final class Retention
{
    /**
     * key => row. setting: the key in ka_nastaveni ('' = follows another period); unit months|days; min/max: the range of
     * the field; zero: what 0 means – keep (no limit), inherit:<key> (as that period), default:<n>, min (the minimum);
     * floor: the lowest period allowed (legal hold, all 0 in 3.48); feature: the feature whose data it is ('' = always);
     * then: the setting that says what happens after the period; follows: the period it takes; edit: the screen where it
     * is set when that is not the Privacy tab; mcp: update_settings may set it.
     *
     * @var array<string, array{label: string, setting: string, unit: string, min: int, max: int, zero: string, floor: int, feature: string, mcp: bool, then?: string, follows?: string, edit?: string}>
     */
    public const array PERIODS = [
        'enquiries' => ['label' => 'Enquiries', 'setting' => 'enquiries_months', 'unit' => 'months', 'min' => 0, 'max' => 120,
            'zero' => 'keep', 'floor' => 0, 'feature' => 'poptavky', 'then' => 'enquiries_expiry', 'mcp' => false],
        'job_enquiries' => ['label' => 'Job applications sent as enquiries', 'setting' => 'job_applications_months', 'unit' => 'months', 'min' => 0, 'max' => 120,
            'zero' => 'inherit:enquiries', 'floor' => 0, 'feature' => 'poptavky', 'mcp' => false],
        'bookings' => ['label' => 'Bookings', 'setting' => '', 'follows' => 'enquiries', 'unit' => 'months', 'min' => 0, 'max' => 120,
            'zero' => 'keep', 'floor' => 0, 'feature' => 'bookings', 'then' => 'enquiries_expiry', 'mcp' => false],
        'customers' => ['label' => 'Customers without activity', 'setting' => 'contacts_months', 'unit' => 'months', 'min' => 0, 'max' => 120,
            'zero' => 'keep', 'floor' => 0, 'feature' => 'customers', 'mcp' => false],
        'quotes' => ['label' => 'Quotes', 'setting' => 'quotes_months', 'unit' => 'months', 'min' => 0, 'max' => 120,
            'zero' => 'keep', 'floor' => 0, 'feature' => 'quotes', 'then' => 'enquiries_expiry', 'mcp' => false],
        'applications' => ['label' => 'Job applications', 'setting' => 'applications_months', 'unit' => 'months', 'min' => 0, 'max' => 120,
            'zero' => 'keep', 'floor' => 0, 'feature' => 'applications', 'mcp' => false],
        'talent_pool' => ['label' => 'Talent pool (with consent)', 'setting' => 'applications_pool_months', 'unit' => 'months', 'min' => 1, 'max' => 60,
            'zero' => 'min', 'floor' => 0, 'feature' => 'applications', 'mcp' => false],
        'whistleblowing' => ['label' => 'Closed whistleblowing cases', 'setting' => 'whistleblowing_retention_months', 'unit' => 'months', 'min' => 1, 'max' => 120,
            'zero' => 'default:' . Whistleblowing::DEFAULT_RETENTION_MONTHS, 'floor' => 0, 'feature' => 'whistleblowing', 'mcp' => false],
        'consents' => ['label' => 'Cookie consent records', 'setting' => 'cookies_log_months', 'unit' => 'months', 'min' => 0, 'max' => 120,
            'zero' => 'keep', 'floor' => 0, 'feature' => '', 'mcp' => true],
        'offsite_backups' => ['label' => 'Off-site backups', 'setting' => 'backup_remote_days', 'unit' => 'days', 'min' => 0, 'max' => 3650,
            'zero' => 'keep', 'floor' => 0, 'feature' => '', 'edit' => 'module=settings&tab=backups', 'mcp' => false],
    ];

    /**
     * The steps of the daily clean-up (Notifications::cleanups) that are no retention period, and why.
     *
     * @var array<string, string>
     */
    public const array SWEEPS = [
        'customer_imports' => 'an uploaded customers file nobody confirmed goes after a day',
        'queues' => 'delivered webhook and connector bodies',
        'imports' => 'the working files of imports and migration reports (WpFile::KEEP_DAYS)',
        'sealing' => 'seals private files, deletes nothing',
    ];

    /**
     * Settings named like a period (…_months, …_days, …retention…) that are none, and why – so a new period cannot be
     * added to Settings::DEFAULTS without a row here or in PERIODS (unit 4330).
     *
     * @var array<string, string>
     */
    public const array NOT_A_PERIOD = [
        'applications_retention_confirmed' => 'the highest application id an administrator confirmed (ApplicationRetention)',
        'quote_valid_days' => 'how long a quote is valid for the customer',
        'quote_link_days' => 'how long the customer’s link works after the validity',
        'booking_horizon_days' => 'how far ahead visitors may book',
        'fleet_uptime_days' => 'uptime days from the console (JSON)',
    ];

    /** What happens after the enquiries' period (enquiries_expiry), the gentler first. */
    public const array EXPIRY = ['anonymise', 'delete'];

    /** The trash of pages, news and items and the unconfirmed newsletter sign-ups (3.48: named, read by their cleanups). */
    public const int TRASH_DAYS = 30;
    public const int UNCONFIRMED_SIGNUP_DAYS = 30;

    /**
     * Read-only rows: key => [label, the number, unit, the constant the cleanup reads].
     *
     * @var array<string, array{0: string, 1: int, 2: string, 3: string}>
     */
    public const array FIXED = [
        'local_backups' => ['Backups on this server', Backup::KEEP_DAYS, 'days', 'Kaleta\Core\Backup::KEEP_DAYS'],
        'trash' => ['Trash of pages, news and items', self::TRASH_DAYS, 'days', 'Kaleta\Core\Retention::TRASH_DAYS'],
        'notifications' => ['Notifications behind the bell', Inbox::KEEP_DAYS, 'days', 'Kaleta\Core\Inbox::KEEP_DAYS'],
        'unconfirmed_signups' => ['Unconfirmed newsletter sign-ups', self::UNCONFIRMED_SIGNUP_DAYS, 'days', 'Kaleta\Core\Retention::UNCONFIRMED_SIGNUP_DAYS'],
        'staged_files' => ['Files waiting in an import', MediaStaging::DAYS, 'days', 'Kaleta\Core\MediaStaging::DAYS'],
        'quote_drafts' => ['Quote drafts not touched', QuoteDelivery::DRAFT_MONTHS, 'months', 'Kaleta\Core\QuoteDelivery::DRAFT_MONTHS'],
        'download_links' => ['Download links in e-mails', FileLink::DAYS, 'days', 'Kaleta\Core\FileLink::DAYS'],
    ];

    /**
     * Legal hold, reserved (3.48: empty): period key => list of [class, method]; the method takes (Db, list<int> ids) and
     * returns id => ['until' => Y-m-d, 'reason' => text] for the records the law keeps. No purge calls held() in 3.48.
     *
     * @var array<string, list<array{0: class-string, 1: string}>>
     */
    public const array HOLDS = [];

    /** The stored number of a period (a following period: the one it follows). */
    public static function value(Settings $s, string $key): int
    {
        return self::valueWith(fn (string $k): string => $s->get($k), $key);
    }

    /** The number a cleanup works with: after inherit, default, min and follows; 0 = kept without limit. */
    public static function effective(Settings $s, string $key): int
    {
        return self::effectiveWith(fn (string $k): string => $s->get($k), $key);
    }

    /** Does the period end in anonymising instead of deleting (enquiries_expiry; enquiries, bookings, quotes)? */
    public static function anonymises(Settings $s): bool
    {
        return $s->get('enquiries_expiry') === 'anonymise';
    }

    /** @param callable(string): string $get */
    private static function valueWith(callable $get, string $key): int
    {
        $row = self::PERIODS[$key];

        return isset($row['follows']) ? self::valueWith($get, $row['follows']) : (int) $get($row['setting']);
    }

    /** @param callable(string): string $get */
    private static function effectiveWith(callable $get, string $key): int
    {
        $row = self::PERIODS[$key];
        if (isset($row['follows'])) {
            return self::effectiveWith($get, $row['follows']);
        }
        $value = (int) $get($row['setting']);
        if ($value > 0) {
            return $value;
        }
        [$rule, $parameter] = explode(':', $row['zero'], 2) + [1 => ''];

        return match ($rule) {
            'inherit' => self::effectiveWith($get, $parameter),
            'default' => (int) $parameter,
            'min' => $row['min'],
            default => 0,
        };
    }

    /** May the period be set to this number: inside its range and not below its floor (legal hold)? Pure. */
    public static function allowed(array $row, int $value): bool
    {
        return $value >= max($row['min'], $row['floor']) && $value <= $row['max'];
    }

    /** The period key of a setting, or null. */
    public static function keyOf(string $setting): ?string
    {
        foreach (self::PERIODS as $key => $row) {
            if ($row['setting'] !== '' && $row['setting'] === $setting) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Validates a proposal from a form or an address: only the settings of PERIODS (and enquiries_expiry), each a whole
     * number inside its range and not below its floor – never clamped. Returns [the proposal, the invalid settings].
     *
     * @param array<string, mixed> $input
     * @param list<string> $only the settings allowed (default: those edited on the Privacy tab and enquiries_expiry)
     * @return array{0: array<string, string>, 1: list<string>}
     */
    public static function parse(array $input, ?array $only = null): array
    {
        $only ??= [...self::editable(), 'enquiries_expiry'];
        $proposed = [];
        $invalid = [];
        foreach ($only as $setting) {
            if (!array_key_exists($setting, $input)) {
                continue;
            }
            $raw = is_scalar($input[$setting]) ? trim((string) $input[$setting]) : null;
            if ($setting === 'enquiries_expiry') {
                in_array($raw, self::EXPIRY, true) ? $proposed[$setting] = (string) $raw : $invalid[] = $setting;
                continue;
            }
            $key = self::keyOf($setting);
            if ($key === null || $raw === null || preg_match('/^\d{1,5}$/D', $raw) !== 1 || !self::allowed(self::PERIODS[$key], (int) $raw)) {
                $invalid[] = $setting;
                continue;
            }
            $proposed[$setting] = (string) (int) $raw;
        }

        return [$proposed, $invalid];
    }

    /**
     * 3.48 review: whether a shorter value of the setting waits for the confirmation screen (Settings → retention) – only what
     * that screen parses (editable() and enquiries_expiry). A period set on a screen of its own (off-site backups on the
     * Backups tab) is saved at once from its form, a shorter value written to the change log (saveUnconfirmed).
     */
    public static function confirmable(string $setting): bool
    {
        return $setting === 'enquiries_expiry' || in_array($setting, self::editable(), true);
    }

    /** @return list<string> the settings edited on the Privacy tab (every period with a setting and no screen of its own) */
    public static function editable(): array
    {
        return array_values(array_map(fn (array $row): string => $row['setting'], array_filter(self::PERIODS, fn (array $row): bool => $row['setting'] !== '' && !isset($row['edit']))));
    }

    /**
     * What a proposal changes, compared by the effective periods (so a period that inherits or follows shows up as a
     * consequence). harsher: the data goes sooner – a shorter period, or a number where there was no limit; enquiries_expiry
     * from anonymise to delete. consequence: the period has no proposed setting of its own (bookings, a job period that
     * inherits). The list is in the order of PERIODS, enquiries_expiry last.
     *
     * @param array<string, string> $proposed setting => new value
     * @return list<array{key: string, setting: string, from: int|string, to: int|string, unit: string, harsher: bool, consequence: bool}>
     */
    public static function changes(Settings $s, array $proposed): array
    {
        $before = fn (string $k): string => $s->get($k);
        $after = fn (string $k): string => array_key_exists($k, $proposed) ? $proposed[$k] : $s->get($k);

        return self::changesWith($before, $after, $proposed);
    }

    /**
     * changes() over two readers (pure – the unit test feeds arrays).
     *
     * @param callable(string): string $before
     * @param callable(string): string $after
     * @param array<string, string> $proposed
     * @return list<array{key: string, setting: string, from: int|string, to: int|string, unit: string, harsher: bool, consequence: bool}>
     */
    public static function changesWith(callable $before, callable $after, array $proposed): array
    {
        $out = [];
        foreach (self::PERIODS as $key => $row) {
            $from = self::effectiveWith($before, $key);
            $to = self::effectiveWith($after, $key);
            if ($from === $to) {
                continue;
            }
            $out[] = ['key' => $key, 'setting' => $row['setting'], 'from' => $from, 'to' => $to, 'unit' => $row['unit'],
                'harsher' => $to > 0 && ($from === 0 || $to < $from), 'consequence' => $row['setting'] === '' || !array_key_exists($row['setting'], $proposed)];
        }
        $fromExpiry = $before('enquiries_expiry') === 'anonymise' ? 'anonymise' : 'delete';
        if (array_key_exists('enquiries_expiry', $proposed) && $proposed['enquiries_expiry'] !== $fromExpiry) {
            $out[] = ['key' => 'enquiries_expiry', 'setting' => 'enquiries_expiry', 'from' => $fromExpiry, 'to' => $proposed['enquiries_expiry'], 'unit' => '',
                'harsher' => $proposed['enquiries_expiry'] === 'delete', 'consequence' => false];
        }

        return $out;
    }

    /**
     * The settings of a proposal that make the data go sooner (their own change is harsher) – they wait for the
     * confirmation; the rest is saved at once.
     *
     * @param list<array{key: string, setting: string, from: int|string, to: int|string, unit: string, harsher: bool, consequence: bool}> $changes
     * @return list<string>
     */
    public static function harsherSettings(array $changes): array
    {
        return array_values(array_unique(array_column(array_filter($changes, fn (array $c): bool => $c['harsher'] && !$c['consequence']), 'setting')));
    }

    /**
     * Records the law keeps (legal hold) among $ids of a period: id => [until, reason]. 3.48: no source is registered
     * (HOLDS is empty) and no purge asks; $sources lets a test register one.
     *
     * @param list<int> $ids
     * @param array<string, list<array{0: class-string, 1: string}>> $sources
     * @return array<int, array{until: string, reason: string}>
     */
    public static function held(Db $db, string $period, array $ids, array $sources = self::HOLDS): array
    {
        $out = [];
        foreach ($sources[$period] ?? [] as [$class, $method]) {
            foreach ((array) $class::$method($db, $ids) as $id => $hold) {
                if (in_array((int) $id, $ids, true) && is_array($hold)) {
                    $out[(int) $id] = ['until' => (string) ($hold['until'] ?? ''), 'reason' => (string) ($hold['reason'] ?? '')];
                }
            }
        }

        return $out;
    }

    /**
     * The rows for the Privacy tab and the record of processing. The first seven keys are the shape of MCP
     * processing_record → retention (fixed in 3.48; later releases only add keys); the rest is for the admin screen.
     *
     * @return list<array{key: string, label: string, value: int, unit: string, zero: string, editable: bool, feature_on: bool, setting: string, effective: int, row: array<string, mixed>}>
     */
    public static function rows(Settings $s): array
    {
        $out = [];
        foreach (self::PERIODS as $key => $row) {
            $out[] = ['key' => $key, 'label' => $row['label'], 'value' => self::value($s, $key), 'unit' => $row['unit'], 'zero' => isset($row['follows']) ? 'follows:' . $row['follows'] : $row['zero'],
                'editable' => $row['setting'] !== '' && !isset($row['edit']), 'feature_on' => $row['feature'] === '' || Extensions::isEnabled($s, $row['feature']),
                'setting' => $row['setting'], 'effective' => self::effective($s, $key), 'row' => $row];
        }

        return $out;
    }

    /**
     * The MCP rows (processing_record → retention): {key, label, value, unit, zero, editable, feature_on}.
     *
     * @return list<array{key: string, label: string, value: int, unit: string, zero: string, editable: bool, feature_on: bool}>
     */
    public static function mcpRows(Settings $s): array
    {
        return array_map(fn (array $r): array => ['key' => $r['key'], 'label' => $r['label'], 'value' => $r['value'], 'unit' => $r['unit'], 'zero' => $r['zero'],
            'editable' => $r['editable'], 'feature_on' => $r['feature_on']], self::rows($s));
    }

    /** "24 months", "90 days", "without limit" – the period as a person reads it. */
    public static function label(int $value, string $unit): string
    {
        if ($value <= 0) {
            return t('without limit');
        }

        return $unit === 'days' ? t('%d days', $value) : t('%d months', $value);
    }

    /** "24 months, then deleted" – the line beside a section's settings (Retention: … – change). */
    public static function summary(Settings $s, string $key): string
    {
        $row = self::PERIODS[$key];
        $effective = self::effective($s, $key);
        $text = self::label($effective, $row['unit']);
        if ($effective > 0 && isset($row['then'])) {
            $text .= ', ' . (self::anonymises($s) ? t('then anonymised') : t('then deleted'));
        }

        return $text;
    }

    /**
     * How many records are past a new, shorter period today (for the confirmation screen); null when the site cannot
     * count them (an approximation – the cleanup's own rules, such as an open deal holding a customer, keep some).
     */
    public static function pastLimit(Db $db, Settings $s, string $key, int $period): ?int
    {
        if ($period <= 0) {
            return 0;
        }
        $before = self::PERIODS[$key]['unit'] === 'days' ? Clock::at('-' . $period . ' days') : Clock::monthsAgo($period);
        $sql = match ($key) {
            'enquiries' => ['SELECT COUNT(*) FROM {poptavky} WHERE datum < ?', [$before]],
            'job_enquiries' => ($sources = Jobs::sources($db)) === [] ? null : ['SELECT COUNT(*) FROM {poptavky} WHERE datum < ? AND zdroj IN (' . implode(',', array_fill(0, count($sources), '?')) . ')', [$before, ...$sources]],
            'bookings' => ['SELECT COUNT(*) FROM {bookings} WHERE ends_at < ?', [$before]],
            'customers' => ['SELECT COUNT(*) FROM {contacts} WHERE anonymised_at IS NULL AND COALESCE(last_activity_at, created_at) < ?', [$before]],
            'quotes' => ["SELECT COUNT(*) FROM {quotes} WHERE status <> 'draft' AND updated_at < ?", [$before]],
            'applications' => ['SELECT COUNT(*) FROM {applications} WHERE COALESCE(decided_at, last_activity_at) < ?', [$before]],
            'whistleblowing' => ["SELECT COUNT(*) FROM {whistleblowing_cases} WHERE status = 'closed' AND closed_at IS NOT NULL AND closed_at < ?", [$before]],
            'consents' => ['SELECT COUNT(*) FROM {souhlasy} WHERE cas < ?', [$before]],
            default => null,
        };
        if ($sql === null) {
            return null;
        }
        try {
            return (int) $db->value($sql[0], $sql[1]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return null; // a table of a release not migrated yet
        }
    }

    /**
     * 3.50.1 (D1): a proposal that arrives through the old field names of a section's form (an admin page of 3.47 still open,
     * or a form that posts them on purpose): what does not make data go sooner is saved at once, a shorter period is not
     * saved – it is returned for the confirmation screen (Settings → retention, confirmationUrl), so every shortening is
     * confirmed in one place. Nothing is written to the change log here; the confirmation writes it.
     *
     * @param array<string, string> $proposed setting => value (already in range)
     * @return array<string, string> the settings held for the confirmation => their proposed value
     */
    public static function saveOrHold(App $app, array $proposed): array
    {
        $s = $app->settings();
        [$now, $held] = self::holdWith(fn (string $k): string => $s->get($k), $proposed);
        foreach ($now as $setting => $value) {
            $s->set($setting, $value);
        }

        return $held;
    }

    /**
     * saveOrHold() without the store (pure): [saved now, held for the confirmation] – held are the settings whose own change
     * is harsher (harsherSettings), a consequence never holds anything.
     *
     * @param callable(string): string $before
     * @param array<string, string> $proposed
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    public static function holdWith(callable $before, array $proposed): array
    {
        $after = fn (string $k): string => array_key_exists($k, $proposed) ? $proposed[$k] : $before($k);
        $held = array_intersect_key($proposed, array_flip(self::harsherSettings(self::changesWith($before, $after, $proposed))));

        return [array_diff_key($proposed, $held), $held];
    }

    /** The confirmation screen of Settings with the held proposal in the address (numbers only, read by Retention::parse). */
    public static function confirmationUrl(App $app, array $held): string
    {
        return $app->url('admin.php?' . http_build_query(['module' => 'settings', 'action' => 'retention'] + $held));
    }

    /**
     * Saves a proposal at once (update_settings – cookies_log_months – and the off-site backup days of the Backups tab):
     * a harsher change written to the change log with the old and the new value. The old field names of the sections'
     * forms go through saveOrHold since 3.50.1.
     *
     * @param array<string, string> $proposed setting => value (already in range)
     */
    public static function saveUnconfirmed(App $app, string $section, array $proposed): void
    {
        $s = $app->settings();
        $changes = self::changes($s, $proposed);
        foreach ($proposed as $setting => $value) {
            $s->set($setting, $value);
        }
        foreach ($changes as $change) {
            if ($change['harsher'] && !$change['consequence']) {
                ChangeLog::write($app, $section, 'retention', self::changeLine($change));
            }
        }
    }

    /** "Enquiries: 24 → 12 months" – a change as one line (the change log, the confirmation screen). */
    public static function changeLine(array $change): string
    {
        if ($change['key'] === 'enquiries_expiry') {
            return t('After the period: %s → %s', t($change['from'] === 'anonymise' ? 'anonymise' : 'delete'), t($change['to'] === 'anonymise' ? 'anonymise' : 'delete'));
        }

        return t('%s: %s → %s', t(self::PERIODS[$change['key']]['label']), self::label((int) $change['from'], $change['unit']), self::label((int) $change['to'], $change['unit']));
    }
}
