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
use Kaleta\Core\Scheduler;
use Kaleta\Core\Updater;

/**
 * What a site tells its console (2.9): version, health, background jobs, backups, updates, enquiries waiting for an answer,
 * visits, the site audit and errors. Counts and states only – never names, e-mail addresses or the content of enquiries.
 * Texts are English (the console translates its own screens, not the site's sentences).
 *
 * 3.16 – what waits for people, so the console (3.17) can show one inbox across the sites: requests to Claude (counts,
 * the oldest waiting, and – when the site allows it, setting fleet_request_titles, on by default – up to 20 titles with
 * e-mail addresses masked), the "Waiting for you" counts (Core\PendingReview), enquiries without spam, bookings to confirm,
 * security in the last 7 days (counts by reason, never an address or a name), the certificate and domain dates from the
 * domain watch, Claude's scheduled runs, how long an update has waited and the latest monthly report.
 * Left out on purpose: anything about the whistleblowing channel – even a count of reports tells who might have reported
 * in a small company, and the channel's promise is that nothing about it leaves the site.
 * Each new part is optional: null when its feature is off, and a part that fails is null instead of stopping the report.
 */
final class Heartbeat
{
    /** How many request titles a heartbeat carries at most, and how long each may be. */
    public const int REQUEST_ITEMS = 20;
    public const int TITLE_LENGTH = 120;

    /** A request that waits longer than this (new or in progress) asks for attention on the console. */
    public const int REQUEST_WAIT_HOURS = 48;

    /** Agent run statuses a heartbeat counts (Core\AgentSchedules; "running" is not a result yet). */
    public const array RUN_STATUSES = ['ok', 'partial', 'failed', 'missed'];

    /** @return array<string, mixed> */
    public static function build(App $app): array
    {
        $db = $app->db();
        $s = $app->settings();
        $checks = Language::runWith('en', fn (): array => Health::checks($app));
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
            'jobs_failing' => array_values(array_map(fn (array $j): string => $j['name'], array_filter(Scheduler::overview($db, $s), fn (array $j): bool => $j['failures'] > 0))),
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
            'problems_7_days' => Events::problems($db, 168),
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
            'updates' => ['behind_days' => Updater::behindDays($update), 'major_available' => self::part(fn (): ?string => self::version(Updater::nextMajor($s)['verze'] ?? null))],
            'report_month' => self::part(fn (): ?string => self::reportMonth($db)),
        ];
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
     * A request title as the console may see it: plain text on one line, at most TITLE_LENGTH characters, and anything
     * shaped like an e-mail address masked (the title is written by staff and may quote a customer's address).
     */
    public static function cleanTitle(string $title): string
    {
        $title = html_entity_decode(strip_tags($title), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = preg_replace('/[^\s@<>()\[\]{},;:"\'`]+@[^\s@<>()\[\]{},;:"\'`]+/u', '[e-mail]', $title) ?? '';
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
     * The month of the latest archived monthly report ('2026-09'), null without the archive.
     * TODO(3.16 merge): the archive table comes from work-stream A (MonthlyReport); align the table and column names here
     * when both are merged – until then the guard finds no table and the key stays null.
     */
    private static function reportMonth(Db $db): ?string
    {
        if ($db->value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?', [$db->tableName('monthly_reports')]) === null) {
            return null;
        }
        $month = (string) $db->value('SELECT MAX(month) FROM {monthly_reports}');

        return preg_match('/^\d{4}-\d{2}/', $month) === 1 ? substr($month, 0, 7) : null;
    }
}
