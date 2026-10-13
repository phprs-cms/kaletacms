<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Background jobs (2.8): one list of what the site does by itself, when each job runs, and what happened last time.
 *
 * Two triggers run the same list:
 *  - cron calling /tasks or the older /ulohy (every 5 minutes, the reliable way): every due job, with a larger time budget;
 *  - a visit to the site (Notifications::runInBackground, at most once a minute, after the page is sent): only jobs marked
 *    "any", with a small budget, so a site without cron still publishes on time and sends its mail.
 * A job is due when INTERVAL seconds passed since it last ran. Its result goes to ka_jobs (last run, last success, the
 * error, failures in a row); after FAILURES_TO_ALERT failures in a row the event task.failed is recorded (Core\Events),
 * and task.recovered when it works again. One run at a time: a database lock, so cron and a visit never run jobs twice.
 */
final class Scheduler
{
    /**
     * name => [interval in seconds, 'any' (also on visits) | 'cron' (only from cron), label]. Interval 0 = on every trigger:
     * the queues decide themselves what is due, and backups, links and the clean-up keep their own pace.
     */
    public const array JOBS = [
        // first, and the others wait while it runs: they would write the new clock into rows not converted yet (3.12); when it
        // fails, they run anyway and System status warns (3.12.1)
        'time_zone' => [0, 'any', 'Saved dates converted to a new time zone'],
        'notifications' => [0, 'any', 'Scheduled publishing and announcements'],
        'mail' => [0, 'any', 'Sending e-mail'],
        'webhooks' => [0, 'any', 'Webhook deliveries'],
        'newsletter_sync' => [0, 'any', 'Subscribers to the mailing service'],
        'newsletter_mailing' => [0, 'cron', 'Sending newsletters'],
        'media_sync' => [0, 'any', 'Off-site copy of the media'],
        'backup' => [0, 'cron', 'Automatic backups'],
        'links' => [0, 'any', 'Checking links'],
        'redirects' => [86400, 'any', 'Redirects for addresses visitors could not find'],
        'redirect_sources' => [0, 'any', 'Old addresses of redirects prepared for Kaleta 4.0'],
        'cleanup' => [0, 'any', 'Deleting old personal data and events'],
        'alerts' => [300, 'any', 'Alert e-mails'],
        'domain_watch' => [86400, 'any', 'Domain, certificate and mail records'],
        'security' => [86400, 'any', 'Suspending unused accounts and connections'],
        'validity' => [3600, 'any', 'Content that expires or asks for review'],
        'events' => [3600, 'any', 'Repeating events move to their next date'],
        'triage' => [300, 'any', 'Sorting new enquiries with the writing assistant'],
        'connectors' => [0, 'any', 'Deliveries to connected services'],
        'agent_journal' => [86400, 'any', 'Undo journal of Claude sessions: older than 30 days removed'],
        'search_data' => [86400, 'any', 'Search Console and Bing data'],
        'gbp' => [86400, 'any', 'Google Business Profile: hours out, reviews in'],
        'notices' => [3600, 'any', 'Official notice board: postings and takedowns'],
        'updates' => [0, 'any', 'Updates'],
        'heartbeat' => [3600, 'any', 'Report to the fleet console'],
        'fleet_uptime' => [300, 'any', 'Fleet console: are the sites up'],
        'monthly_report' => [3600, 'any', 'Monthly report by e-mail'],
        'cookie_scan' => [86400, 'cron', 'What cookies the site sets'],
        'whistleblowing' => [86400, 'any', 'Whistleblowing: due deadlines and the retention of closed cases'],
        'agent_runs' => [3600, 'any', 'Scheduled Claude runs: noticing runs nobody picked up'],
        'booking_reminders' => [3600, 'any', 'Online booking: reminders before the appointment'],
        'import_recheck' => [0, 'any', 'Imported content checked again with today\'s sanitizers'],
    ];

    public const int FAILURES_TO_ALERT = 3;

    /** One run at a time; a time zone conversion started from Settings holds it too (Admin\Modules\Settings::actionTimeZone). */
    public const string LOCK = 'kaleta_scheduler';

    /**
     * Every job with its implementation: it gets the app and the trigger (cron | visit) and returns a short result.
     *
     * @return array<string, array{0: int, 1: string, 2: string, 3: callable(App, string): string}>
     */
    public static function jobs(): array
    {
        $jobs = [
            'time_zone' => function (App $app, string $source): string {
                $state = TimeZoneConversion::run($app->db(), $source === 'cron' ? 20.0 : 3.0);
                if ($state !== null && $state['finished_now']) {
                    TimeZoneConversion::logFinished($app, $state);
                }

                return ($state === null ? 'not needed' : ($state['done'] ? 'done' : 'converted ' . $state['tables_done'] . ' of ' . $state['tables'] . ' tables'))
                    . ($state !== null && $state['failed'] !== [] ? ', not converted: ' . implode(', ', $state['failed']) : '');
            },
            'notifications' => function (App $app): string {
                Notifications::process($app);

                return 'ok';
            },
            'mail' => fn (App $app, string $source): string => 'sent ' . Mail::processQueue($app->settings(), $source === 'cron' ? 30 : 10),
            'webhooks' => fn (App $app, string $source): string => 'delivered ' . Webhook::processQueue($app->settings(), $source === 'cron' ? 30 : 10),
            'newsletter_sync' => fn (App $app): string => 'synced ' . Newsletter::processQueue($app),
            'newsletter_mailing' => fn (App $app): string => 'sent ' . Mailing::processQueue($app),
            'media_sync' => function (App $app, string $source): string {
                RemoteBackup::syncMediaInBackground($app->settings(), $source === 'cron' ? 25 : 10);

                return 'ok';
            },
            'backup' => function (App $app): string {
                Backup::createAutomatic($app->db(), $app->settings());

                return 'ok';
            },
            'links' => function (App $app): string {
                Links::runInBackground($app);

                return 'ok';
            },
            'redirects' => fn (App $app): string => RedirectMatcher::run($app),
            'redirect_sources' => function (App $app, string $source): string {
                // 3.14 (data model §1.3): new and older redirects get their normal form; nothing to do once all have it
                $done = RedirectSources::fill($app->db(), $source === 'cron' ? 10.0 : 1.0);

                return $done['filled'] === 0 && !$done['left'] ? 'nothing to do' : 'normalised ' . $done['filled'] . ($done['left'] ? ', more left' : '');
            },
            'cleanup' => function (App $app, string $source): string {
                Notifications::purgePersonalData($app, $source === 'cron'); // from cron on every run, on visits once a day
                Events::prune($app->db());
                Firewall::cleanUp($app->db());

                return 'ok';
            },
            'alerts' => fn (App $app): string => Alerts::run($app),
            'domain_watch' => function (App $app): string {
                $result = (new DomainWatch())->refresh($app);

                return !empty($result['local']) ? 'local address, skipped' : 'checked';
            },
            'security' => function (App $app): string {
                $done = SecurityHygiene::run($app);
                $clients = \Kaleta\Front\OAuth::purgeUnusedClients($app->db()); // registrations nobody approved within a day (3.3.4, N66)

                return 'suspended ' . count($done['blocked']) . ', revoked ' . count($done['revoked']) . ', unused app registrations removed ' . $clients;
            },
            'validity' => fn (App $app): string => Validity::run($app),
            'events' => fn (App $app): string => Calendar::run($app),
            'triage' => fn (App $app): string => Triage::run($app),
            'connectors' => fn (App $app): string => Connectors::processQueue($app),
            'agent_journal' => fn (App $app): string => AgentJournal::purge($app->db()),
            'search_data' => fn (App $app): string => SearchData::run($app),
            'gbp' => fn (App $app): string => GoogleBusiness::run($app),
            'notices' => fn (App $app): string => Notices::run($app),
            'updates' => fn (App $app): string => Updater::runInBackground($app), // keeps its own 12-hour pace
            'heartbeat' => fn (App $app): string => \Kaleta\Fleet\Link::send($app),
            'fleet_uptime' => fn (App $app): string => \Kaleta\Fleet\Console::checkUptime($app),
            'monthly_report' => fn (App $app): string => MonthlyReport::runIfDue($app),
            'cookie_scan' => function (App $app): string {
                $scan = Privacy::scan($app);

                return $scan['error'] !== '' ? $scan['error'] : 'pages ' . $scan['pages'] . ', cookies ' . count($scan['cookies']);
            },
            'whistleblowing' => fn (App $app): string => Whistleblowing::run($app),
            'agent_runs' => fn (App $app): string => 'missed ' . AgentSchedules::markMissed($app),
            'booking_reminders' => fn (App $app): string => Booking::remind($app),
            'import_recheck' => function (App $app, string $source): string {
                $state = ImportRecheck::run($app->db(), $app->settings(), $source === 'cron' ? 20.0 : 2.0); // nothing to do once done (3.3.3, N63)

                return $state === null ? 'not needed' : ($state['done'] ? 'done' : 'checked ' . $state['checked']) . ', changed ' . $state['changed'];
            },
        ];
        $all = [];
        foreach (self::JOBS as $name => [$interval, $where, $label]) {
            $all[$name] = [$interval, $where, $label, $jobs[$name]];
        }

        return $all + \Kaleta\Extension\Registry::get()->jobs(); // jobs of add-ons (3.0), ext_<slug>_<name>
    }

    /**
     * Runs the due jobs within the time budget. Returns name => result for the jobs that ran.
     *
     * @param 'cron'|'visit' $source
     * @return array<string, string>
     */
    public static function run(App $app, string $source, float $budget): array
    {
        \Kaleta\Extension\Registry::boot($app);
        $db = $app->db();
        // a visit gives way at once; cron waits for a run started by a visit to finish, so its call is never skipped
        if ((int) $db->value('SELECT GET_LOCK(?, ?)', [self::LOCK, $source === 'cron' ? 20 : 0]) !== 1) {
            return []; // another run is in progress
        }
        $end = microtime(true) + $budget;
        $results = [];
        try {
            $state = [];
            foreach ($db->all('SELECT name, last_run, failures FROM {jobs}') as $r) {
                $state[(string) $r['name']] = $r;
            }
            foreach (self::jobs() as $name => [$interval, $where, , $job]) {
                if (microtime(true) > $end) {
                    break; // the rest stays due for the next run
                }
                if (($where === 'cron' && $source !== 'cron') || !self::applies($name, $app->settings())) {
                    continue;
                }
                $last = isset($state[$name]['last_run']) ? strtotime((string) $state[$name]['last_run']) : false;
                if (!self::isDue($last === false ? null : $last, $interval, time())) {
                    continue;
                }
                $results[$name] = self::runOne($app, $name, $job, $source, (int) ($state[$name]['failures'] ?? 0));
                if ($name === 'time_zone' && !str_starts_with($results[$name], 'error') && TimeZoneConversion::running($db) !== null) {
                    break; // saved dates are being converted: the other jobs wait for the next run – unless the conversion fails (3.12.1, N312-2)
                }
            }
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [self::LOCK]);
        }

        return $results;
    }

    /** Jobs of a role the site may not have: the heartbeat only on a site paired with a console, the uptime checks only on a console (2.9). */
    public static function applies(string $name, Settings $s): bool
    {
        return match ($name) {
            'heartbeat' => \Kaleta\Fleet\Link::isPaired($s),
            'fleet_uptime' => Extensions::isEnabled($s, 'fleet'),
            default => true,
        };
    }

    /** Whether a job is due: never run, or its interval has passed (a little early is fine – cron is not exact). */
    public static function isDue(?int $lastRun, int $interval, int $now): bool
    {
        return $interval <= 0 || $lastRun === null || $now - $lastRun >= $interval - min(30, (int) ($interval / 10));
    }

    /** @param callable(App, string): string $job */
    private static function runOne(App $app, string $name, callable $job, string $source, int $failures): string
    {
        $db = $app->db();
        $start = microtime(true);
        $now = date('Y-m-d H:i:s');
        try {
            $result = mb_substr((string) $job($app, $source), 0, 120);
            $db->run('INSERT INTO {jobs} (name, last_run, last_ok, last_error, failures, runs, duration_ms) VALUES (?, ?, ?, \'\', 0, 1, ?)
                ON DUPLICATE KEY UPDATE last_run = VALUES(last_run), last_ok = VALUES(last_ok), last_error = \'\', failures = 0, runs = runs + 1, duration_ms = VALUES(duration_ms)',
                [$name, $now, $now, (int) ((microtime(true) - $start) * 1000)]);
            if ($failures >= self::FAILURES_TO_ALERT) {
                Events::record($db, 'task.recovered', 'info', t('The background job “%s” works again.', $name), ['job' => $name]);
            }

            return $result;
        } catch (\Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 255);
            $db->run('INSERT INTO {jobs} (name, last_run, last_error, failures, runs, duration_ms) VALUES (?, ?, ?, 1, 1, ?)
                ON DUPLICATE KEY UPDATE last_run = VALUES(last_run), last_error = VALUES(last_error), failures = failures + 1, runs = runs + 1, duration_ms = VALUES(duration_ms)',
                [$name, $now, $error, (int) ((microtime(true) - $start) * 1000)]);
            if ($failures + 1 === self::FAILURES_TO_ALERT) {
                Events::record($db, 'task.failed', 'error', t('The background job “%s” failed %d times in a row: %s', $name, self::FAILURES_TO_ALERT, $error), ['job' => $name]);
            }

            return 'error: ' . $error;
        }
    }

    /**
     * The state of every job for System status and get_health.
     *
     * @return list<array{name: string, label: string, interval: int, where: string, last_run: ?string, last_ok: ?string, last_error: string, failures: int, runs: int}>
     */
    public static function overview(Db $db, ?Settings $s = null): array
    {
        $state = [];
        foreach ($db->all('SELECT * FROM {jobs}') as $r) {
            $state[(string) $r['name']] = $r;
        }
        $out = [];
        foreach (self::jobs() as $name => [$interval, $where, $label]) {
            if ($s !== null && !self::applies($name, $s)) {
                continue;
            }
            $r = $state[$name] ?? [];
            $out[] = ['name' => $name, 'label' => $label, 'interval' => $interval, 'where' => $where, 'last_run' => $r['last_run'] ?? null, 'last_ok' => $r['last_ok'] ?? null,
                'last_error' => (string) ($r['last_error'] ?? ''), 'failures' => (int) ($r['failures'] ?? 0), 'runs' => (int) ($r['runs'] ?? 0)];
        }

        return $out;
    }
}
