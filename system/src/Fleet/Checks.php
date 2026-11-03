<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

/**
 * The health checks of the console (3.29): one fixed list for the health matrix (a row per site, a column per check), the
 * Today view (what needs attention across the sites, ranked) and the workload roll-up.
 *
 * Each check reads its part of the stored heartbeat (Fleet\Heartbeat; the console's own uptime for "Site answers") and maps
 * it to a state ok | warn | bad | unknown with a short system text (no personal data – the heartbeat carries none). A
 * site that sends none of the 3.29 parts (before 3.29) is "unknown – update the site to see"; nothing about it is
 * guessed. The link to fix a check is built HERE from the site's known address (Console::url) and a fixed admin path per
 * check (CHECKS) – never from a path or an address the site sends. The console never calls into a site (plain links only).
 *
 * Everything here is pure: the screens (Admin\Modules\Fleet) and the MCP tools pass the rows of ka_fleet_sites and the time.
 */
final class Checks
{
    public const array STATES = ['ok', 'warn', 'bad', 'unknown'];

    /**
     * id => [label (English, through t()), the heartbeat field it reads, weight for the Today ranking, admin path on the site
     * that fixes it ('' = the site's home page)]. The order is the order of the matrix columns.
     */
    public const array CHECKS = [
        'uptime' => ['Site answers', 'up', 100, ''],
        'reporting' => ['Reports to the console', 'last_seen', 80, 'admin.php?module=settings&tab=console'],
        'updates' => ['Updates', 'update_available', 50, 'admin.php?module=settings&tab=backups'],
        'offsite_backup' => ['Off-site backup', 'offsite', 60, 'admin.php?module=settings&tab=backups'],
        'local_backup' => ['Local backup', 'last_backup', 50, 'admin.php?module=settings&tab=backups'],
        'certificate' => ['Certificate', 'domain_watch.certificate', 45, 'admin.php?module=status'],
        'domain' => ['Domain', 'domain_watch.domain', 40, 'admin.php?module=status'],
        'mail_records' => ['SPF and DMARC', 'domain_watch.mail', 20, 'admin.php?module=settings&tab=mail'],
        'smtp' => ['Mail through SMTP, tested', 'mail_setup', 25, 'admin.php?module=settings&tab=mail'],
        'two_step' => ['Two-step sign-in for administrators', 'twofa', 30, 'admin.php?module=settings&tab=security'],
        'auto_updates' => ['Automatic security updates', 'auto_updates', 20, 'admin.php?module=settings&tab=backups'],
        'cookie_scan' => ['Cookie scan', 'cookie_scan', 10, 'admin.php?module=settings&tab=cookies'],
        'privacy_policy' => ['Privacy policy', 'privacy_page', 15, 'admin.php?module=settings&tab=cookies'],
        'accessibility_statement' => ['Accessibility statement', 'accessibility_statement', 10, 'admin.php?module=settings&tab=cookies'],
        'handover' => ['Hand-over checklist', 'handover', 5, 'admin.php?module=audit'],
        'claude_connection' => ['Claude connection', 'claude_connection', 15, 'admin.php?module=claude_settings'],
        'jobs' => ['Background jobs', 'jobs_failing', 40, 'admin.php?module=status'],
        'cron' => ['Cron', 'cron_last_run', 30, 'admin.php?module=status'],
    ];

    /** The 3.29 parts of a heartbeat Checks bounds (Console::clean hands them here). */
    public const array PARTS = ['offsite', 'mail_setup', 'twofa', 'cookie_scan', 'privacy_page', 'accessibility_statement', 'handover', 'workload'];

    /** The enum values of the 3.29 parts. */
    public const array OFFSITE_STATUSES = ['off', 'never', 'ok', 'paused', 'failing'];
    public const array RECOVERY_KEY_STATES = ['none', 'unconfirmed', 'confirmed'];
    public const array MAIL_MODES = ['smtp', 'php'];
    public const array TWOFA_REQUIRED = ['none', 'admins', 'everyone'];
    public const array PAGE_STATES = ['published', 'hidden', 'none'];
    public const array STATEMENT_STATES = ['published', 'draft', 'none'];

    /** The keys of the hand-over checklist rows (Core\Audit::handoverRows) – a failing key outside this list is dropped. */
    public const array HANDOVER_ROWS = ['site_email', 'smtp', 'remote_backup', 'company', 'indexing', 'favicon', 'cookies', 'security_contact', 'two_step', 'unused_access',
        'auto_suspend', 'client_account', 'monthly_report', 'guardrails', 'agency', 'notebook', 'cron', 'domain'];

    /** The counts of the workload part (Heartbeat::workload), each null when the site does not have it. */
    public const array WORKLOAD = ['enquiries_this_week', 'enquiries_last_week', 'unanswered_over_24h', 'median_first_reply_hours', 'tasks_open', 'tasks_overdue', 'customers_this_month'];

    /** The release that added the parts (PARTS): a site that sends none of them shows "update the site to see". */
    public const string SINCE = '3.29.0';

    /** Thresholds (days or hours) of the checks. */
    public const int BACKUP_DAYS = 8;
    public const int OFFSITE_DAYS = 8;
    public const int COOKIE_SCAN_DAYS = 14;
    public const int CRON_WARN_HOURS = 3;
    public const int CRON_BAD_HOURS = 24;
    public const int REPORT_WARN_HOURS = 2;
    public const int UPDATE_BAD_DAYS = 30;

    private const array RANK = ['bad' => 2, 'warn' => 1, 'ok' => 0, 'unknown' => 0];

    /**
     * Bounds one 3.29 part of a heartbeat (Console::clean): enum values only, counts through Console::count, days real
     * calendar dates between 1970 and 2100, the hand-over keys from HANDOVER_ROWS; anything else is null on its own.
     */
    public static function clean(string $key, mixed $v): mixed
    {
        $enum = static fn (mixed $value, array $allowed): ?string => is_string($value) && in_array($value, $allowed, true) ? $value : null;
        if ($key === 'privacy_page') {
            return $enum($v, self::PAGE_STATES);
        }
        if ($key === 'accessibility_statement') {
            return $enum($v, self::STATEMENT_STATES);
        }
        if (!is_array($v)) {
            return null;
        }
        switch ($key) {
            case 'offsite':
                $status = $enum($v['status'] ?? null, self::OFFSITE_STATUSES);

                return $status === null ? null : ['status' => $status, 'at' => self::day($v['at'] ?? null), 'recovery_key' => $enum($v['recovery_key'] ?? null, self::RECOVERY_KEY_STATES)];
            case 'mail_setup':
                $mode = $enum($v['mode'] ?? null, self::MAIL_MODES);

                return $mode === null ? null : ['mode' => $mode, 'tested' => ($v['tested'] ?? false) === true, 'tested_at' => self::day($v['tested_at'] ?? null)];
            case 'twofa':
                $required = $enum($v['required'] ?? null, self::TWOFA_REQUIRED);

                return $required === null ? null : ['required' => $required, 'admins_without' => Console::count($v['admins_without'] ?? null) ?? 0];
            case 'cookie_scan':
                return ['at' => self::day($v['at'] ?? null), 'error' => ($v['error'] ?? false) === true, 'cookies' => Console::count($v['cookies'] ?? null) ?? 0];
            case 'handover':
                $total = min(count(self::HANDOVER_ROWS) * 2, Console::count($v['total'] ?? null) ?? 0);
                $failing = [];
                foreach (is_array($v['failing'] ?? null) ? array_slice($v['failing'], 0, 100) : [] as $row) {
                    if (is_string($row) && in_array($row, self::HANDOVER_ROWS, true) && !in_array($row, $failing, true)) {
                        $failing[] = $row;
                    }
                }

                return $total < 1 ? null : ['done' => min($total, Console::count($v['done'] ?? null) ?? 0), 'total' => $total, 'failing' => $failing];
            case 'workload':
                $out = [];
                foreach (self::WORKLOAD as $count) {
                    $out[$count] = Console::count($v[$count] ?? null);
                }

                return $out;
        }

        return null;
    }

    /**
     * The checks of one site, in the order of CHECKS. $heartbeat = the stored heartbeat (raw or Console::storedBeat – it is
     * bounded again here), $uptime = the console's own fields of the site (a row of ka_fleet_sites: up, up_status,
     * up_changed, last_seen). $context: security_version – the newest version when it is a security release (Console::latest),
     * so an update waiting for it is bad at once.
     *
     * @param array<string, mixed> $heartbeat
     * @param array<string, mixed> $uptime
     * @param array{security_version?: string} $context
     * @return list<array{id: string, label: string, state: string, detail: string, path: string, field: string, weight: int, since: ?int}>
     */
    public static function forSite(array $heartbeat, array $uptime, ?int $now = null, array $context = []): array
    {
        $now ??= time();
        $beat = $heartbeat !== [] ? array_filter(Console::clean($heartbeat), static fn (mixed $v): bool => $v !== null) : [];
        // a site that sends none of the 3.29 parts is before 3.29 (the version alone would not tell a build between releases)
        $old = array_intersect_key($beat, array_flip(self::PARTS)) === [];
        $out = [];
        foreach (self::CHECKS as $id => [$label, $field, $weight, $path]) {
            [$state, $detail, $since] = self::evaluate($id, $beat, $uptime, $now, $old, $context) + [2 => null];
            $out[] = ['id' => $id, 'label' => t($label), 'state' => $state, 'detail' => $detail, 'path' => $path, 'field' => $field, 'weight' => $weight, 'since' => $since];
        }

        return $out;
    }

    /**
     * The checks of a row of ka_fleet_sites (forSite with its stored heartbeat and its uptime fields).
     *
     * @param array<string, mixed> $site
     * @param array{security_version?: string} $context
     * @return list<array{id: string, label: string, state: string, detail: string, path: string, field: string, weight: int, since: ?int}>
     */
    public static function forRow(array $site, ?int $now = null, array $context = []): array
    {
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);

        return self::forSite(is_array($beat) ? $beat : [], $site, $now, $context);
    }

    /**
     * What needs attention across the sites, ranked: bad before warn, then the check's weight, then the oldest first (a
     * known start before an unknown one), then the site's name. Only warn and bad; unknown is never in it.
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @param array{security_version?: string} $context
     * @return list<array{site_id: int, site: string, url: string, customer_id: ?int, check: string, label: string, state: string, detail: string, path: string, link: string, weight: int, since: ?int}>
     */
    public static function today(array $sites, ?int $now = null, array $context = []): array
    {
        $now ??= time();
        $items = [];
        foreach ($sites as $site) {
            $url = Console::url(rtrim((string) ($site['url'] ?? ''), '/')) ?? '';
            $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
            $customer = Console::customerIdOf($site);
            foreach (self::forRow($site, $now, $context) as $check) {
                if ($check['state'] !== 'bad' && $check['state'] !== 'warn') {
                    continue;
                }
                $items[] = ['site_id' => (int) ($site['id'] ?? 0), 'site' => $name !== '' ? $name : $url, 'url' => $url, 'customer_id' => $customer > 0 ? $customer : null,
                    'check' => $check['id'], 'label' => $check['label'], 'state' => $check['state'], 'detail' => $check['detail'], 'path' => $check['path'],
                    'link' => self::link($url, $check['id']), 'weight' => $check['weight'], 'since' => $check['since']];
            }
        }
        usort($items, static fn (array $a, array $b): int => [self::RANK[$b['state']], $b['weight'], $a['since'] === null ? 1 : 0, $a['since'] ?? 0, mb_strtolower($a['site']), $a['site_id']]
            <=> [self::RANK[$a['state']], $a['weight'], $b['since'] === null ? 1 : 0, $b['since'] ?? 0, mb_strtolower($b['site']), $b['site_id']]);

        return $items;
    }

    /**
     * The workload roll-up: per site the WORKLOAD counts (null = the site does not send them – before 3.29, or the part
     * is off there) and the totals over the sites that send each; the median hours to the first reply is the median of the
     * sites' medians. Counts only – no names, no hours worked, no money.
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @return array{sites: list<array<string, mixed>>, totals: array<string, ?int>, reporting: int}
     */
    public static function workload(array $sites): array
    {
        $rows = [];
        $totals = array_fill_keys(self::WORKLOAD, null);
        $medians = [];
        $reporting = 0;
        foreach ($sites as $site) {
            $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
            $part = is_array($beat) ? self::clean('workload', $beat['workload'] ?? null) : null;
            $counts = is_array($part) ? $part : array_fill_keys(self::WORKLOAD, null);
            $url = Console::url(rtrim((string) ($site['url'] ?? ''), '/')) ?? '';
            $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
            $rows[] = ['site_id' => (int) ($site['id'] ?? 0), 'site' => $name !== '' ? $name : $url, 'url' => $url, 'customer_id' => Console::customerIdOf($site) ?: null,
                'known' => is_array($part)] + $counts;
            $reporting += is_array($part) ? 1 : 0;
            foreach (self::WORKLOAD as $key) {
                if ($key === 'median_first_reply_hours') {
                    if ($counts[$key] !== null) {
                        $medians[] = $counts[$key];
                    }
                } elseif ($counts[$key] !== null) {
                    $totals[$key] = min(1_000_000_000, ($totals[$key] ?? 0) + $counts[$key]);
                }
            }
        }
        if ($medians !== []) {
            sort($medians);
            $middle = intdiv(count($medians), 2);
            $totals['median_first_reply_hours'] = count($medians) % 2 === 1 ? $medians[$middle] : intdiv($medians[$middle - 1] + $medians[$middle], 2);
        }

        return ['sites' => $rows, 'totals' => $totals, 'reporting' => $reporting];
    }

    /**
     * The plain link to fix a check on a site: the site's known address (Console::url) + the check's fixed path. '' when
     * the address is not a valid one or the check is unknown. The console never adds a token or a session to it.
     */
    public static function link(string $siteUrl, string $checkId): string
    {
        $url = Console::url(rtrim($siteUrl, '/'));
        if ($url === null || !isset(self::CHECKS[$checkId])) {
            return '';
        }

        return rtrim($url, '/') . '/' . self::CHECKS[$checkId][3];
    }

    /**
     * One check: [state, detail, since]. $old = the site sends none of the 3.29 parts (or nothing): a missing part is unknown,
     * "update the site to see".
     *
     * @param array<string, mixed> $beat cleaned, nulls left out
     * @param array<string, mixed> $uptime
     * @param array{security_version?: string} $context
     * @return array{0: string, 1: string, 2?: ?int}
     */
    private static function evaluate(string $id, array $beat, array $uptime, int $now, bool $old, array $context): array
    {
        $none = $beat === [] ? ['unknown', t('No report from the site yet')] : null;
        $missing = $old ? ['unknown', t('Update the site to see')] : ['unknown', t('Not reported')];
        $days = static fn (?int $time): ?int => $time !== null ? max(0, intdiv($now - $time, 86400)) : null;
        $hours = static fn (?int $time): ?int => $time !== null ? max(0, intdiv($now - $time, 3600)) : null;

        switch ($id) {
            case 'uptime':
                $up = $uptime['up'] ?? null;
                $since = self::time($uptime['up_changed'] ?? null);
                if ($up === null || $up === '') {
                    return ['unknown', t('Not checked yet')];
                }
                if ((int) $up === 0) {
                    $status = (int) ($uptime['up_status'] ?? 0);

                    return ['bad', $status > 0 ? t('Answers with HTTP %d', min(999, max(0, $status))) : t('No answer'), $since];
                }

                return ['ok', t('Answers'), $since];
            case 'reporting':
                $seen = self::time($uptime['last_seen'] ?? null);
                if ($seen === null) {
                    return ['unknown', t('No report from the site yet')];
                }
                $h = (int) $hours($seen);

                return match (true) {
                    $h > Console::SILENT_HOURS => ['bad', t('No report for %d hours', $h), $seen],
                    $h >= self::REPORT_WARN_HOURS => ['warn', t('Last report %d hours ago', $h), $seen],
                    default => ['ok', t('Reports every hour'), $seen],
                };
        }
        if ($none !== null) {
            return $none;
        }

        switch ($id) {
            case 'updates':
                $available = is_string($beat['update_available'] ?? null) ? $beat['update_available'] : '';
                $behind = Console::count(Console::part($beat, 'updates')['behind_days'] ?? null);
                $since = $behind !== null && $behind > 0 ? $now - $behind * 86400 : null;
                $security = (string) ($context['security_version'] ?? '');

                return match (true) {
                    isset($beat['update_problem']) => ['bad', t('The last update failed')],
                    $available !== '' && $security !== '' && $available === $security => ['bad', t('Security update %s waits', $available), $since],
                    $available !== '' && $behind !== null && $behind >= self::UPDATE_BAD_DAYS => ['bad', t('Version %s waits for %d days', $available, $behind), $since],
                    $available !== '' => ['warn', t('Version %s is available', $available), $since],
                    default => ['ok', t('Up to date')],
                };
            case 'offsite_backup':
                $offsite = $beat['offsite'] ?? null;
                if (!is_array($offsite)) {
                    return ($beat['offsite_backup'] ?? null) === false ? ['bad', t('Off – backups stay on the same server')] : $missing;
                }
                $at = Console::date($offsite['at'] ?? null)?->getTimestamp();
                $ago = (int) $days($at);

                return match ($offsite['status']) {
                    'off' => ['bad', t('Off – backups stay on the same server')],
                    'paused' => ['bad', t('Paused – the recovery key is not saved'), $at],
                    'failing' => ['bad', t('The last copy failed'), $at],
                    'never' => ['warn', t('No copy yet')],
                    default => $at === null || $ago > self::OFFSITE_DAYS ? ['warn', $at === null ? t('No copy yet') : t('Last copy %d days ago', $ago), $at] : ['ok', self::ago($ago, 'copy'), $at],
                };
            case 'local_backup':
                $at = Console::unixTime($beat['last_backup'] ?? null);
                if ($at === null) {
                    return ['bad', t('No backup yet')];
                }
                $ago = (int) $days($at);

                return $ago > self::BACKUP_DAYS ? ['bad', t('Last backup %d days ago', $ago), $at] : ['ok', self::ago($ago, 'backup'), $at];
            case 'certificate':
            case 'domain':
                return self::expiry($id, Console::part($beat, 'domain_watch'), $now, $old);
            case 'mail_records':
                $watch = Console::part($beat, 'domain_watch');
                $mail = is_array($watch['mail'] ?? null) ? $watch['mail'] : null;
                if (!empty($watch['local'])) {
                    return ['unknown', t('Not checked on a local address')];
                }
                if ($mail === null) {
                    return ['unknown', t('Not checked yet')];
                }

                return match (true) {
                    $mail['error'] === true => ['warn', t('Could not be checked')],
                    $mail['spf'] === false && $mail['dmarc'] === false => ['bad', t('No SPF and no DMARC record')],
                    $mail['spf'] === false => ['bad', t('No SPF record')],
                    $mail['dmarc'] === false => ['warn', t('No DMARC record')],
                    $mail['spf_covers_smtp'] === false => ['warn', t('SPF does not cover the SMTP server')],
                    default => ['ok', t('SPF and DMARC found')],
                };
            case 'smtp':
                $setup = $beat['mail_setup'] ?? null;
                if (!is_array($setup)) {
                    return $missing;
                }

                return match (true) {
                    $setup['mode'] !== 'smtp' => ['warn', t('Sent through the host\'s mail()')],
                    !$setup['tested'] => ['warn', t('SMTP set, no message accepted yet')],
                    default => ['ok', t('Tested'), Console::date($setup['tested_at'])?->getTimestamp()],
                };
            case 'two_step':
                $twofa = $beat['twofa'] ?? null;
                if (!is_array($twofa)) {
                    return $missing;
                }

                return match (true) {
                    $twofa['required'] !== 'none' => ['ok', $twofa['required'] === 'everyone' ? t('Required for everyone') : t('Required for administrators')],
                    $twofa['admins_without'] > 0 => ['bad', t('Not required – %d administrators sign in without it', $twofa['admins_without'])],
                    default => ['warn', t('Not required, every administrator uses it')],
                };
            case 'auto_updates':
                return match ($beat['auto_updates'] ?? null) {
                    true => ['ok', t('On')],
                    false => ['warn', t('Off')],
                    default => ['unknown', t('Not reported')],
                };
            case 'cookie_scan':
                $scan = $beat['cookie_scan'] ?? null;
                if (!is_array($scan)) {
                    return $missing;
                }
                $at = Console::date($scan['at'])?->getTimestamp();
                $ago = (int) $days($at);

                return match (true) {
                    $at === null => ['warn', t('Never ran')],
                    $scan['error'] => ['warn', t('The last scan could not reach the site'), $at],
                    $ago > self::COOKIE_SCAN_DAYS => ['warn', t('Last scan %d days ago', $ago), $at],
                    default => ['ok', t('%d cookies set by the server', $scan['cookies']), $at],
                };
            case 'privacy_policy':
                return match ($beat['privacy_page'] ?? null) {
                    'published' => ['ok', t('Published')],
                    'hidden' => ['warn', t('The page is hidden')],
                    'none' => ['bad', t('No privacy policy page')],
                    default => $missing,
                };
            case 'accessibility_statement':
                return match ($beat['accessibility_statement'] ?? null) {
                    'published' => ['ok', t('Published')],
                    'draft' => ['warn', t('Drafted, not published')],
                    'none' => ['warn', t('No accessibility statement')],
                    default => $missing,
                };
            case 'handover':
                $handover = $beat['handover'] ?? null;
                if (!is_array($handover)) {
                    return $missing;
                }

                return [$handover['done'] >= $handover['total'] ? 'ok' : 'warn', t('%d of %d done', $handover['done'], $handover['total'])];
            case 'claude_connection':
                $claude = $beat['claude_connection'] ?? null;
                if (!is_array($claude)) {
                    return $missing;
                }
                $reason = Console::claudeReason($beat);

                return match (true) {
                    $reason === 'claude_not_connected' => ['bad', t('Requests wait, Claude is not connected')],
                    $reason === 'claude_run_stalled' => ['bad', t('Requests wait, the scheduled run does not run')],
                    $claude['state'] === 'off' => ['ok', t('Claude is off')],
                    $claude['state'] === 'none' => ['warn', t('Not connected')],
                    $claude['pickup'] === 'stalled' => ['warn', t('The scheduled run does not run')],
                    default => ['ok', t('Connected')],
                };
            case 'jobs':
                $jobs = is_array($beat['jobs_failing'] ?? null) ? $beat['jobs_failing'] : [];

                return $jobs === [] ? ['ok', t('All run')] : ['bad', t('%d failing: %s', count($jobs), implode(', ', array_slice($jobs, 0, 3)))];
            case 'cron':
                $at = Console::unixTime($beat['cron_last_run'] ?? null);
                if ($at === null) {
                    return ['bad', t('Never ran')];
                }
                $h = (int) $hours($at);

                return match (true) {
                    $h >= self::CRON_BAD_HOURS => ['bad', t('Last run %d hours ago', $h), $at],
                    $h >= self::CRON_WARN_HOURS => ['warn', t('Last run %d hours ago', $h), $at],
                    default => ['ok', t('Runs'), $at],
                };
        }

        return ['unknown', t('Not reported')];
    }

    /**
     * The certificate or the domain registration: bad when it ended or ends within a week (the certificate) or two (the
     * domain), warn up to Console::CERTIFICATE_DAYS / DOMAIN_DAYS or when the check failed.
     *
     * @param array<array-key, mixed> $watch the cleaned domain_watch
     * @return array{0: string, 1: string, 2?: ?int}
     */
    private static function expiry(string $id, array $watch, int $now, bool $old): array
    {
        if ($watch === []) {
            return ['unknown', $old ? t('Update the site to see') : t('Not checked yet')];
        }
        if (!empty($watch['local'])) {
            return ['unknown', t('Not checked on a local address')];
        }
        $part = $watch[$id === 'certificate' ? 'certificate' : 'domain'] ?? null;
        if (!is_array($part)) {
            return ['unknown', t('Not checked yet')];
        }
        $days = Console::daysLeft($part, $now);
        if ($days === null) {
            return !empty($part['error']) ? ['warn', t('Could not be checked')] : ['unknown', t('Not checked yet')];
        }
        [$bad, $warn] = $id === 'certificate' ? [7, Console::CERTIFICATE_DAYS] : [14, Console::DOMAIN_DAYS];

        return match (true) {
            $days < 0 => ['bad', t('Expired')],
            $days < $bad => ['bad', t('Expires in %d days', $days)],
            $days < $warn => ['warn', t('Expires in %d days', $days)],
            default => ['ok', t('Valid for %d more days', $days)],
        };
    }

    /** "Last copy today" / "… yesterday" / "… %d days ago" for an ok state. */
    private static function ago(int $days, string $what): string
    {
        return match (true) {
            $what === 'copy' && $days === 0 => t('Last copy today'),
            $what === 'copy' => t('Last copy %d days ago', $days),
            $days === 0 => t('Last backup today'),
            default => t('Last backup %d days ago', $days),
        };
    }

    /** A calendar day 'Y-m-d' that exists, between 1970 and 2100; null for anything else. */
    private static function day(mixed $value): ?string
    {
        $date = Console::date($value);

        return $date !== null && (int) $date->format('Y') >= 1970 && (int) $date->format('Y') <= 2100 ? $date->format('Y-m-d') : null;
    }

    /** A Unix time from a DATETIME column, null for anything else. */
    private static function time(mixed $value): ?int
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $time = strtotime($value);

        return $time !== false && $time > 0 ? $time : null;
    }
}
