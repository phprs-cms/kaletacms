<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Audit;
use Kaleta\Admin\Modules\Enquiries;
use Kaleta\Core\Backup;
use Kaleta\Core\Clock;
use Kaleta\Core\Db;
use Kaleta\Core\DomainWatch;
use Kaleta\Core\Events;
use Kaleta\Core\Extensions;
use Kaleta\Core\Health;
use Kaleta\Core\Language;
use Kaleta\Core\PendingReview;
use Kaleta\Core\Privacy;
use Kaleta\Core\RecoveryKey;
use Kaleta\Core\Redact;
use Kaleta\Core\Scheduler;
use Kaleta\Core\SecurityHygiene;
use Kaleta\Core\Updater;

/**
 * What a site tells its console (2.9): version, health, background jobs, backups, updates, enquiries waiting for an answer,
 * visits, the site audit and errors. Counts and states – never e-mail addresses, phone numbers or the content of enquiries;
 * the only text staff write that leaves is the request titles below, and only while the site shares them.
 * Texts are English (the console translates its own screens, not the site's sentences).
 *
 * 3.16 – what waits for people, so the console (3.17) can show one inbox across the sites: requests to Claude (counts,
 * the oldest waiting, and – when the site allows it, setting fleet_request_titles, on by default – up to 20 titles with
 * e-mail addresses and phone numbers masked, Core\Redact), the "Waiting for you" counts (Core\PendingReview), enquiries without spam, bookings to confirm,
 * security in the last 7 days (counts by reason, never an address or a name), the certificate and domain dates from the
 * domain watch, Claude's scheduled runs, how long an update has waited and the latest monthly report.
 * Left out on purpose: anything about the whistleblowing channel – even a count of reports tells who might have reported
 * in a small company, and the channel's promise is that nothing about it leaves the site: its events are dropped from
 * problems_7_days, its job from jobs_failing, both from the health checks (Health::checks leaving), and the console drops
 * them again.
 * Each new part is optional: null when its feature is off, and a part that fails is null instead of stopping the report.
 *
 * 3.17 – report_summary: the headline counts of the latest archived monthly report (Core\ReportArchive) – visits,
 * enquiries, requests handled, changes by people and by Claude, updates and backups – so the console's monthly report per
 * customer can show them. Counts only; no titles, pages or anything else of the report leave the site.
 *
 * 3.18 – claude_connection: whether Claude is connected (Core\ClaudeConnection::summary) – the state, the moment of the last
 * call and of the earliest end of a connection, and how requests are picked up; never who connected or how.
 *
 * 3.29 – what the console's health matrix and Today view check (Fleet\Checks), as states, dates and counts: the off-site
 * copies (offsite), how mail goes out (mail_setup), two-step sign-in (twofa), the last cookie scan (cookie_scan), whether
 * the privacy policy and the accessibility statement are published (privacy_page, accessibility_statement), the hand-over
 * checklist (handover: done, total and the keys of the rows not done) and the team's workload (workload: enquiries this
 * and last week, unanswered after 24 hours, the median hours to the first reply this month, open and overdue tasks,
 * customers added this month). Never a host, an address, a name or the text of anything.
 */
final class Heartbeat
{
    /** How many request titles a heartbeat carries at most, and how long each may be. */
    public const int REQUEST_ITEMS = 20;
    public const int TITLE_LENGTH = 120;

    /** A request that waits longer than this (new or in progress) asks for attention on the console (Console::REQUEST_WAIT_HOURS). */
    public const int REQUEST_WAIT_HOURS = Console::REQUEST_WAIT_HOURS;

    /** Agent run statuses a heartbeat counts (Core\AgentSchedules; "running" is not a result yet). */
    public const array RUN_STATUSES = ['ok', 'partial', 'failed', 'missed'];

    /** 3.29: the setting require_2fa as the heartbeat names it ('' = none). */
    public const array TWOFA = ['spravci' => 'admins', 'vsichni' => 'everyone'];

    /** 3.29: how many enquiries with a reply the median time to the first reply reads at most. */
    private const int REPLY_SAMPLE = 2000;

    /** @return array<string, mixed> */
    public static function build(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $checks = Language::runWith('en', fn (): array => Health::checks($app, true)); // without the whistleblowing channel
        $problems = array_values(array_map(fn (array $c): array => ['group' => $c['skupina'], 'check' => $c['nazev'], 'status' => $c['stav'] === 'chyba' ? 'error' : 'warning',
            'detail' => mb_substr(trim(strip_tags((string) $c['info'])), 0, 200)], array_filter($checks, fn (array $c): bool => $c['stav'] !== 'ok')));
        $backup = Backup::listAll()[0] ?? null;
        $update = (new Updater($s))->state();
        $rolledBack = $db->one("SELECT created_at, message FROM {events} WHERE type IN ('update.rolled_back', 'update.failed') ORDER BY id DESC LIMIT 1");
        $pending = self::part(fn (): array => PendingReview::counts($app));
        $audit = [];
        try {
            foreach (Language::runWith('en', fn (): array => (new Audit($app))->run()) as $finding) {
                $audit[$finding['kind']] = ($audit[$finding['kind']] ?? 0) + 1;
            }
        } catch (\Throwable) {
            $audit = null; // the audit must never stop the heartbeat
        }

        return [
            'name' => $s->get('site_name'),
            'url' => rtrim($s->get('site_url'), '/'),
            'version' => KALETA_VERSION,
            'php' => PHP_VERSION,
            'db_version' => $s->int('db_version'),
            'status' => ['ok' => 'ok', 'varovani' => 'warning', 'chyba' => 'error'][Health::summary($checks)],
            'problems' => array_slice($problems, 0, 20),
            'jobs_failing' => array_values(array_filter(array_map(fn (array $j): string => $j['name'], array_filter(Scheduler::overview($db, $s), fn (array $j): bool => $j['failures'] > 0)),
                fn (string $job): bool => !self::isWhistleblowing($job))),
            'cron_last_run' => $s->int('tasks_last_run') ?: null,
            'last_backup' => $backup !== null ? (int) $backup['cas'] : null,
            'offsite_backup' => $s->get('remote_backup') !== 'vypnuto',
            'update_available' => $update['nova']['verze'] ?? null,
            'update_problem' => $rolledBack !== null && strtotime((string) $rolledBack['created_at']) > time() - 14 * 86400 ? mb_substr((string) $rolledBack['message'], 0, 200) : null,
            'auto_updates' => $s->bool('auto_updates'),
            'update_channel' => $update['channel'], // 3.8 (D3): latest | stable | custom
            'enquiries_unanswered' => Extensions::isEnabled($s, 'poptavky') ? (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE stav = 0') : null,
            'enquiries_7_days' => Extensions::isEnabled($s, 'poptavky') ? (int) $db->value('SELECT COUNT(*) FROM {poptavky} WHERE datum > ?', [\Kaleta\Core\Clock::at('-7 days')]) : null,
            'visits_7_days' => \Kaleta\Front\Stats::enabled($s) ? (int) $db->value('SELECT COALESCE(SUM(navstevy), 0) FROM {stat_dny} WHERE den > ?', [\Kaleta\Core\Clock::day('-7 days')]) : null,
            'audit' => $audit,
            'problems_7_days' => self::withoutWhistleblowing(Events::problems($db, 168)),
            'claude' => Extensions::isEnabled($s, 'claude'),
            'kit_version' => $s->int('fleet_kit_version') ?: null, // the shared design kit this site applied (2.16, Fleet\Kit)
            // 3.16: what waits for people (see the class description)
            'requests' => self::part(fn (): array => self::requests($app, (int) ($pending['requests_done'] ?? 0))),
            'pending_review' => $pending,
            // the console's "Enquiries waiting": new and not spam, as the site's own list and badge count them
            // (enquiries_unanswered above keeps its meaning – every unopened enquiry, spam included – for older consoles)
            'enquiries_new_not_spam' => Extensions::isEnabled($s, 'poptavky') ? self::part(fn (): int => Enquiries::countNew($db)) : null,
            'bookings_pending' => Extensions::isEnabled($s, 'bookings')
                ? self::part(fn (): int => (int) $db->value("SELECT COUNT(*) FROM {bookings} WHERE status = 'pending' AND ends_at > ?", [Clock::now()])) : null,
            'security_7_days' => self::part(fn (): array => self::security($db)),
            'domain_watch' => self::domainWatch(DomainWatch::cached($s)),
            'agent_runs' => Extensions::isEnabled($s, 'claude') ? self::part(fn (): array => self::agentRuns($db)) : null,
            // 3.18: whether Claude is connected – state off | none | idle | active, last_call and expires (ISO 8601), pickup
            // automatic | manual | stalled (null while not connected); nothing about who or which connection
            'claude_connection' => self::part(fn (): array => \Kaleta\Core\ClaudeConnection::summary(\Kaleta\Core\ClaudeConnection::status($db, $s))),
            'updates' => ['behind_days' => Updater::behindDays($update), 'major_available' => self::part(fn (): ?string => self::version(Updater::nextMajor($s)['verze'] ?? null))],
            'report_month' => self::part(fn (): ?string => self::reportMonth($db)),
            'report_summary' => self::part(fn (): ?array => ($month = self::reportMonth($db)) !== null ? self::reportSummary(\Kaleta\Core\ReportArchive::get($db, $month)) : null),
            // 3.29: the console's health matrix and Today view (Fleet\Checks) – see the class description
            'offsite' => self::part(fn (): array => self::offsite($s, RecoveryKey::state($s))),
            'mail_setup' => self::part(fn (): array => self::mailSetup($s)),
            'twofa' => self::part(fn (): array => ['required' => self::TWOFA[$s->get('require_2fa')] ?? 'none', 'admins_without' => count(SecurityHygiene::findings($app)['two_step'])]),
            'cookie_scan' => self::part(fn (): array => self::cookieScan(Privacy::lastScan($s))),
            'privacy_page' => self::part(fn (): string => self::privacyPage($app)),
            'accessibility_statement' => self::part(fn (): string => self::accessibilityStatement($app)),
            'handover' => self::part(fn (): array => self::handover((new Audit($app))->handoverChecklist())),
            'workload' => self::part(fn (): array => self::workload($app)),
        ];
    }

    /**
     * Whether a name (an event type, a job) belongs to the whistleblowing channel – such names never leave the site (3.16,
     * N316-3): even "whistleblowing.due: 1" tells the console that a report came in about a week ago.
     */
    public static function isWhistleblowing(string $name): bool
    {
        return \Kaleta\Core\Whistleblowing::concerns($name);
    }

    /**
     * A map name => count without the whistleblowing names (isWhistleblowing).
     *
     * @param array<array-key, mixed> $map
     * @return array<array-key, mixed>
     */
    public static function withoutWhistleblowing(array $map): array
    {
        return array_filter($map, fn (int|string $name): bool => !self::isWhistleblowing((string) $name), ARRAY_FILTER_USE_KEY);
    }

    /** A version number as the heartbeat sends it, null for anything else. */
    private static function version(mixed $version): ?string
    {
        return is_string($version) && preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/D', $version) === 1 ? $version : null;
    }

    /** A part of the heartbeat that must never stop it: its value, or null when it fails (a missing table, a bad row). */
    private static function part(callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * A request title as the console may see it: plain text on one line, at most TITLE_LENGTH characters, with e-mail
     * addresses (also written out or spaced) and phone numbers masked by Core\Redact – the title is written by staff and
     * may quote a customer's address or number. The console runs it again on what it receives.
     */
    public static function cleanTitle(string $title): string
    {
        $title = Redact::text(html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $title = trim(preg_replace('/[\s\x{00A0}]+/u', ' ', $title) ?? '');

        return mb_strlen($title) > self::TITLE_LENGTH ? rtrim(mb_substr($title, 0, self::TITLE_LENGTH - 1)) . '…' : $title;
    }

    /**
     * Requests to Claude (Core\Requests): how many are new and in progress, how many done ones wait for the requester's
     * review (Core\PendingReview requests_done), how long the oldest open one has waited, and – when the site shares
     * them (fleet_request_titles) – the open ones and the ones done in the last 14 days, with their titles.
     *
     * @return array{new: int, in_progress: int, done_to_review: int, oldest_waiting_hours: ?int, items: ?list<array{id: int, title: string, status: string, created_at: ?string, updated_at: ?string}>}
     */
    private static function requests(App $app, int $doneToReview): array
    {
        $db = $app->db();
        $counts = $db->pairs("SELECT status, COUNT(*) FROM {requests} WHERE status IN ('new', 'in_progress') GROUP BY status");
        $oldest = Clock::fromDb((string) $db->value("SELECT MIN(created_at) FROM {requests} WHERE status IN ('new', 'in_progress')"));
        $items = null;
        if ($app->settings()->bool('fleet_request_titles')) {
            $iso = fn (mixed $value): ?string => Clock::fromDb(is_string($value) ? $value : null)?->format(DATE_ATOM);
            $items = array_map(fn (array $r): array => ['id' => (int) $r['id'], 'title' => self::cleanTitle((string) $r['title']), 'status' => (string) $r['status'],
                'created_at' => $iso($r['created_at']), 'updated_at' => $iso($r['updated_at'])],
                $db->all("SELECT id, title, status, created_at, updated_at FROM {requests} WHERE status IN ('new', 'in_progress') OR (status = 'done' AND done_at >= ?)
                    ORDER BY FIELD(status, 'new', 'in_progress', 'done'), id DESC LIMIT " . self::REQUEST_ITEMS, [Clock::at('-' . PendingReview::REQUEST_DAYS . ' days')]));
        }

        return ['new' => (int) ($counts['new'] ?? 0), 'in_progress' => (int) ($counts['in_progress'] ?? 0),
            'done_to_review' => $doneToReview,
            'oldest_waiting_hours' => $oldest !== null ? max(0, intdiv(time() - $oldest->getTimestamp(), 3600)) : null, 'items' => $items];
    }

    /**
     * Security in the last 7 days, as counts: firewall refusals by reason, failed sign-ins (wrong passwords, codes and
     * passkeys – ka_kontrola_ip type login), accounts locked right now after too many errors, and the security events
     * (an unused account suspended, a connection revoked, a refresh token used again). Never an address or a name.
     *
     * @return array{firewall: array<string, int>, failed_sign_ins: int, locked_accounts: int, events: array<string, int>}
     */
    private static function security(Db $db): array
    {
        $since = Clock::at('-7 days');
        $firewall = array_map('intval', $db->pairs('SELECT reason, COUNT(*) FROM {firewall_log} WHERE created_at > ? GROUP BY reason ORDER BY reason LIMIT 10', [$since]));

        return ['firewall' => $firewall,
            'failed_sign_ins' => (int) $db->value("SELECT COUNT(*) FROM {kontrola_ip} WHERE typ = 'login' AND cas > ?", [$since]),
            'locked_accounts' => (int) $db->value('SELECT COUNT(*) FROM {uzivatele} WHERE zamceno_do > ?', [Clock::now()]),
            'events' => array_map('intval', $db->pairs("SELECT type, COUNT(*) FROM {events} WHERE type LIKE 'security.%' AND created_at > ? GROUP BY type ORDER BY type LIMIT 10", [$since]))];
    }

    /**
     * The last domain and mail check (Core\DomainWatch, cached daily) as dates and states: when the certificate and the
     * domain registration end, and whether SPF, DMARC and DKIM were found – never the records, hosts or error texts.
     *
     * @param array<string, mixed>|null $cached DomainWatch::cached()
     * @return array<string, mixed>|null
     */
    public static function domainWatch(?array $cached): ?array
    {
        if ($cached === null || !empty($cached['demo'])) {
            return null;
        }
        $expiry = fn (mixed $part): ?array => is_array($part) ? ['expires' => is_int($part['expires'] ?? null) ? date('Y-m-d', $part['expires']) : null,
            'days' => is_int($part['days'] ?? null) ? $part['days'] : null, 'error' => ($part['error'] ?? null) !== null] : null;
        $mail = is_array($cached['mail'] ?? null) ? $cached['mail'] : null;

        return ['checked' => (int) $cached['checked'], 'local' => !empty($cached['local']), 'certificate' => $expiry($cached['tls'] ?? null), 'domain' => $expiry($cached['domain'] ?? null),
            'mail' => $mail === null ? null : ['error' => ($mail['error'] ?? null) !== null, 'spf' => ($mail['spf'] ?? null) !== null, 'dmarc' => ($mail['dmarc'] ?? null) !== null,
                'dkim' => ($mail['dkim'] ?? null) !== null, 'spf_covers_smtp' => is_bool($mail['spf_covers_smtp'] ?? null) ? $mail['spf_covers_smtp'] : null]];
    }

    /**
     * Claude's scheduled runs (Core\AgentSchedules) in the last 7 days by result, the next one due (the kind of task, not
     * its name or instructions) and how many Claude sessions changed the site (Core\AgentJournal).
     *
     * @return array{runs_7_days: array<string, int>, next_due: ?string, next_task: ?string, sessions_7_days: int}
     */
    private static function agentRuns(Db $db): array
    {
        $since = Clock::at('-7 days');
        $counts = $db->pairs('SELECT status, COUNT(*) FROM {agent_runs} WHERE due_at > ? GROUP BY status', [$since]);
        $next = $db->one('SELECT task, next_due FROM {agent_schedules} WHERE active = 1 AND next_due IS NOT NULL ORDER BY next_due LIMIT 1');

        return ['runs_7_days' => array_combine(self::RUN_STATUSES, array_map(fn (string $status): int => (int) ($counts[$status] ?? 0), self::RUN_STATUSES)),
            'next_due' => $next !== null ? Clock::fromDb((string) $next['next_due'])?->format(DATE_ATOM) : null, 'next_task' => $next !== null ? (string) $next['task'] : null,
            'sessions_7_days' => (int) $db->value('SELECT COUNT(*) FROM {agent_sessions} WHERE started_at > ?', [$since])];
    }

    /**
     * The headline counts of an archived monthly report (3.17, Core\ReportArchive::get) – what the console's monthly report
     * per customer shows for the site. A count the report does not have (its feature was off) is null. Pure.
     *
     * @param array{month: string, data: array<string, mixed>}|null $stored
     * @return array{month: string, visits: ?int, enquiries: ?int, requests_handled: ?int, changes_people: ?int, changes_claude: ?int, updates: int, backups: int}|null
     */
    public static function reportSummary(?array $stored): ?array
    {
        if ($stored === null) {
            return null;
        }
        $d = $stored['data'];
        $work = is_array($d['work'] ?? null) ? $d['work'] : null;
        $sum = function (string $who) use ($work): ?int {
            if ($work === null) {
                return null;
            }
            $n = 0;
            foreach ((array) ($work['areas'] ?? []) as $area) {
                $n += is_array($area) ? (int) ($area[$who] ?? 0) : 0;
            }

            return $n;
        };

        return ['month' => $stored['month'],
            'visits' => is_array($d['stats'] ?? null) ? (int) ($d['stats']['visits'] ?? 0) : null,
            'enquiries' => is_array($d['enquiries'] ?? null) ? (int) ($d['enquiries']['total'] ?? 0) : null,
            'requests_handled' => is_array($d['requests'] ?? null) ? (int) ($d['requests']['done_count'] ?? 0) : null,
            'changes_people' => $sum('people'), 'changes_claude' => $sum('claude'),
            'updates' => count(array_filter((array) ($d['updates'] ?? []), static fn (mixed $u): bool => is_array($u) && ($u['type'] ?? '') === 'update.applied')),
            'backups' => (int) ($d['backups']['created'] ?? 0)];
    }

    /** The month of the latest archived monthly report ('2026-09', Core\ReportArchive), null while there is none or the table is not there yet. */
    private static function reportMonth(Db $db): ?string
    {
        if ($db->value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$db->tableName('monthly_reports')]) === null) {
            return null;
        }
        $month = (string) $db->value('SELECT MAX(month) FROM {monthly_reports}');

        return preg_match('/^\d{4}-\d{2}/', $month) === 1 ? substr($month, 0, 7) : null;
    }

    /**
     * The off-site copies (3.29) as the console checks them: status off (no target) | never (no copy tried yet) | ok |
     * paused (waiting for the recovery key, 3.28) | failing; at = the day of the last try ('Y-m-d'), recovery_key = none |
     * unconfirmed | confirmed (Core\RecoveryKey::state). Never the target, its host or the error text. Pure.
     *
     * @return array{status: string, at: ?string, recovery_key: string}
     */
    public static function offsite(\Kaleta\Core\Settings $s, string $recoveryKey): array
    {
        [$when, $result] = explode('|', $s->get('remote_backup_status'), 2) + [1 => ''];
        $at = preg_match('/^\d{4}-\d{2}-\d{2}/', $when) === 1 ? substr($when, 0, 10) : null;
        $status = match (true) {
            !\Kaleta\Core\RemoteBackup::isOn($s) => 'off',
            $at === null => 'never',
            $result === 'ok' => 'ok',
            $result === \Kaleta\Core\RemoteBackup::PAUSED => 'paused',
            default => 'failing',
        };

        return ['status' => $status, 'at' => $status === 'off' ? null : $at, 'recovery_key' => in_array($recoveryKey, ['none', 'unconfirmed', 'confirmed'], true) ? $recoveryKey : 'none'];
    }

    /**
     * How mail goes out (3.29): mode smtp | php (the host's mail()), whether the SMTP server set now accepted a message
     * (Core\Mail::smtpTested, 3.23) and on which day it last did. Never the server's name. Pure.
     *
     * @return array{mode: string, tested: bool, tested_at: ?string}
     */
    public static function mailSetup(\Kaleta\Core\Settings $s): array
    {
        $smtp = $s->get('mail_mode') === 'smtp' && $s->get('smtp_host') !== '';
        $tested = $smtp && \Kaleta\Core\Mail::smtpTested($s);
        $day = substr($s->get('smtp_last_ok'), 0, 10);

        return ['mode' => $smtp ? 'smtp' : 'php', 'tested' => $tested, 'tested_at' => $tested && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $day) === 1 ? $day : null];
    }

    /**
     * The last cookie scan (3.29, Core\Privacy::lastScan): the day, whether it failed, how many cookies the server set –
     * never their names. Pure.
     *
     * @param array<string, mixed> $scan
     * @return array{at: ?string, error: bool, cookies: int}
     */
    public static function cookieScan(array $scan): array
    {
        $time = is_int($scan['time'] ?? null) && $scan['time'] > 0 ? $scan['time'] : null;

        return ['at' => $time !== null ? date('Y-m-d', $time) : null, 'error' => is_string($scan['error'] ?? null) && $scan['error'] !== '',
            'cookies' => is_array($scan['cookies'] ?? null) ? count($scan['cookies']) : 0];
    }

    /**
     * Whether the privacy policy is published (3.29): published (a visible page named like it – Settings::privacyPolicyPage –
     * or a link set for the cookie bar), hidden (the page is there, not shown) or none.
     */
    private static function privacyPage(App $app): string
    {
        $page = \Kaleta\Admin\Modules\Settings::privacyPolicyPage($app->db(), $app->settings());

        return match (true) {
            ($page['visible'] ?? false) === true, Privacy::policyUrl($app->settings()) !== '' => 'published',
            $page !== null => 'hidden',
            default => 'none',
        };
    }

    /**
     * Whether the accessibility statement is published (3.29): published (a visible page whose address names it, as the
     * site audit looks for it – Audit::STATEMENT), draft (the statement drafted from Settings → Privacy and cookies is still
     * hidden) or none.
     */
    private static function accessibilityStatement(App $app): string
    {
        $db = $app->db();
        foreach ($db->all('SELECT seo_link FROM {stranky} WHERE smazano IS NULL AND zobrazit = 1') as $page) {
            if (preg_match(Audit::STATEMENT, (string) $page['seo_link']) === 1) {
                return 'published';
            }
        }
        $draft = $app->settings()->int('accessibility_statement_page');
        $visible = $draft > 0 ? $db->value('SELECT zobrazit FROM {stranky} WHERE ids = ? AND smazano IS NULL', [$draft]) : null;

        return match (true) {
            $visible === null => 'none',
            (int) $visible === 1 => 'published',
            default => 'draft',
        };
    }

    /**
     * The hand-over checklist (3.29, Core\Audit::handoverChecklist) as counts and the keys of the rows not done – never
     * their texts (they name accounts and connections).
     *
     * @param array{done: int, total: int, items: list<array<string, mixed>>} $checklist
     * @return array{done: int, total: int, failing: list<string>}
     */
    public static function handover(array $checklist): array
    {
        return ['done' => $checklist['done'], 'total' => $checklist['total'],
            'failing' => array_values(array_map(fn (array $i): string => (string) $i['key'], array_filter($checklist['items'], fn (array $i): bool => empty($i['done']))))];
    }

    /**
     * The team's workload (3.29) – counts only, each null when its part is off or not there yet: enquiries (spam left out)
     * in the last 7 days and the 7 before, open ones without a reply older than 24 hours, the median whole hours to the
     * first reply of this month's enquiries (Core\EnquiryReply), open and overdue tasks (Core\Tasks), customers added this
     * month (Core\Contacts).
     *
     * @return array{enquiries_this_week: ?int, enquiries_last_week: ?int, unanswered_over_24h: ?int, median_first_reply_hours: ?int, tasks_open: ?int, tasks_overdue: ?int, customers_this_month: ?int}
     */
    private static function workload(App $app): array
    {
        $db = $app->db();
        $enquiries = Extensions::isEnabled($app->settings(), 'poptavky');
        $notSpam = Enquiries::NOT_SPAM;
        $month = Clock::at('first day of this month midnight');
        $count = fn (string $sql, array $params = []): ?int => self::part(fn (): int => (int) $db->value($sql, $params));
        $noReply = "(replies IS NULL OR replies = '' OR replies = '[]')";

        return [
            'enquiries_this_week' => $enquiries ? $count('SELECT COUNT(*) FROM {poptavky} WHERE datum > ? AND ' . $notSpam, [Clock::at('-7 days')]) : null,
            'enquiries_last_week' => $enquiries ? $count('SELECT COUNT(*) FROM {poptavky} WHERE datum > ? AND datum <= ? AND ' . $notSpam, [Clock::at('-14 days'), Clock::at('-7 days')]) : null,
            'unanswered_over_24h' => $enquiries ? $count('SELECT COUNT(*) FROM {poptavky} WHERE stav < 2 AND datum <= ? AND ' . $noReply . ' AND ' . $notSpam, [Clock::at('-24 hours')]) : null,
            'median_first_reply_hours' => $enquiries ? self::part(fn (): ?int => self::medianFirstReply($db->all("SELECT datum, replies FROM {poptavky} WHERE datum >= ? AND replies IS NOT NULL AND replies <> '' AND "
                . $notSpam . ' ORDER BY idp DESC LIMIT ' . self::REPLY_SAMPLE, [$month]))) : null,
            'tasks_open' => $count('SELECT COUNT(*) FROM {tasks} WHERE done_at IS NULL'),
            'tasks_overdue' => $count('SELECT COUNT(*) FROM {tasks} WHERE done_at IS NULL AND due_at IS NOT NULL AND due_at < ?', [Clock::now()]),
            'customers_this_month' => $count('SELECT COUNT(*) FROM {contacts} WHERE created_at >= ? AND anonymised_at IS NULL', [$month]),
        ];
    }

    /**
     * The median whole hours from an enquiry to its first reply (3.29), null when none has a reply. Only the moments are
     * read (Core\EnquiryReply::decode) – never who replied or what. Pure.
     *
     * @param list<array<string, mixed>> $rows datum (DATETIME) and replies (JSON) of ka_poptavky
     */
    public static function medianFirstReply(array $rows): ?int
    {
        $hours = [];
        foreach ($rows as $row) {
            $received = strtotime((string) ($row['datum'] ?? ''));
            $first = null;
            foreach (\Kaleta\Core\EnquiryReply::decode($row['replies'] ?? null) as $reply) {
                $at = strtotime($reply['at']);
                $first = $at !== false && ($first === null || $at < $first) ? $at : $first;
            }
            if ($received !== false && $first !== null && $first >= $received) {
                $hours[] = ($first - $received) / 3600;
            }
        }
        if ($hours === []) {
            return null;
        }
        sort($hours);
        $middle = intdiv(count($hours), 2);

        return (int) round(count($hours) % 2 === 1 ? $hours[$middle] : ($hours[$middle - 1] + $hours[$middle]) / 2);
    }
}
