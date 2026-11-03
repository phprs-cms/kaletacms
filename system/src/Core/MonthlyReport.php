<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Monthly report by e-mail (2.9): once a month the site tells its owner what happened in the previous calendar month –
 * traffic, the top searches (2.13, Core\SearchData), enquiries and sign-ups, updates, backups, who changed what (people
 * and Claude), the problems right now and what needs a decision. With the agency's branding (agency_* settings, 2.4) when it is set, otherwise the site's name.
 *
 * Counts and page paths only: no names, no e-mail addresses, no enquiry contents – the report may be forwarded.
 * The job (Core\Scheduler, hourly) sends it when report_monthly is on and report_last_month is not yet the previous month,
 * so a site without cron still gets it with the first visit of the new month. Recipients: report_recipients, otherwise the
 * site e-mail. The e-mail is written in the site's default language (Language::runWith, like the alerts).
 *
 * 3.16: build() is also the archived report (Core\ReportArchive, Settings → Reports, printed by Core\ReportPage): requests
 * to Claude, changes by area and the notable ones, Claude sessions and scheduled runs, update versions, the off-site
 * copy, security counts, the site audit, enquiry categories, bookings, newsletters sent and what waits at the month's end.
 * A section is null when its feature is off; scrub() masks e-mail addresses and phone numbers in every string (Core\Redact).
 * Scheduled runs are counted by task and result only – their names and summaries are free text Claude wrote, often from
 * enquiries, so they stay in Scheduled runs (N316-1). Nothing of the whistleblowing channel is counted. Moments are stored
 * as ISO 8601 with the offset (N316-9), so a later change of the time zone does not need to touch the JSON; moment()
 * reads them, and the wall times of reports from before, in the site's zone. The e-mail of a month is written from the
 * archived data when the month is stored (monthData). A summary of what happened – never hours, a work log or money.
 *
 * 3.26.1: one look for the e-mail and the report page (Core\ReportVisual blocks, Core\ReportSummary): headline tiles with
 * the change against the month before, the visits of each day, and "What it took to run the site" – what the site did by
 * itself, what Claude did and what people did, and how often it needed a person to decide (build() stores the extra counts
 * under "care"). With a fleet console the data carry "uptime" and the e-mail shows it; without one there is no uptime part.
 *
 * 3.31: "pipeline" – new, won and lost deals and how many reached each stage (Core\Deals::monthCounts); counts only, never
 * values (D-W7), and none on a site without deals.
 */
final class MonthlyReport
{
    public const int MAX_RECIPIENTS = 10;
    public const int MAX_PROBLEMS = 10;
    private const int TOP = 5;

    /** Month names in the nominative – the subject says "September 2026", not "5 September" (format_date_long uses the genitive keys). */
    public const array MONTHS = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];

    private const array UPDATE_EVENTS = ['update.applied', 'update.rolled_back', 'update.failed'];

    /** The first day of the month before the given moment, in its time zone – January goes to December of the previous year. */
    public static function previousMonth(\DateTimeImmutable $now): \DateTimeImmutable
    {
        return $now->modify('first day of last month')->setTime(0, 0);
    }

    /**
     * The data of one calendar month (the site's time zone). Everything is a count, a path or a system message.
     *
     * @return array<string, mixed>
     */
    public static function build(App $app, \DateTimeImmutable $monthStart): array
    {
        $db = $app->db();
        $s = $app->settings();
        $monthStart = $monthStart->modify('first day of this month')->setTime(0, 0);
        $monthEnd = $monthStart->modify('first day of next month');
        [$from, $to] = [$monthStart->format('Y-m-d H:i:s'), $monthEnd->format('Y-m-d H:i:s')];
        [$fromDay, $toDay] = [$monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')];
        $count = fn (string $sql, array $params): int => (int) $db->value($sql, $params);
        $top = fn (string $sql, array $params, string $key): array => array_map(fn (array $r): array => [$key => (string) $r['k'], 'n' => (int) $r['n']], $db->all($sql, $params));
        // enquiries and sign-ups store the page as a full URL: only its path goes into the report
        $path = static fn (string $url): string => (string) (parse_url($url, PHP_URL_PATH) ?: '/');

        $stats = null;
        if (\Kaleta\Front\Stats::isOn($app)) {
            $previousStart = $monthStart->modify('first day of last month');
            $totals = fn (string $a, string $b): array => $db->one('SELECT COALESCE(SUM(navstevy), 0) AS visits, COALESCE(SUM(zobrazeni), 0) AS views FROM {stat_dny} WHERE den >= ? AND den < ?', [$a, $b]) ?? ['visits' => 0, 'views' => 0];
            $now = $totals($fromDay, $toDay);
            $before = $totals($previousStart->format('Y-m-d'), $fromDay);
            $stats = [
                'visits' => (int) $now['visits'], 'views' => (int) $now['views'],
                'previous_visits' => (int) $before['visits'], 'previous_views' => (int) $before['views'],
                'pages' => $top('SELECT cesta AS k, SUM(pocet) AS n FROM {stat_stranky} WHERE den >= ? AND den < ? GROUP BY cesta ORDER BY n DESC LIMIT ' . self::TOP, [$fromDay, $toDay], 'path'),
                'sources' => $top('SELECT zdroj AS k, SUM(pocet) AS n FROM {stat_zdroje} WHERE den >= ? AND den < ? GROUP BY zdroj ORDER BY n DESC LIMIT ' . self::TOP, [$fromDay, $toDay], 'site'),
                'campaigns' => $top('SELECT kampan AS k, SUM(navstevy) AS n FROM {stat_kampane} WHERE den >= ? AND den < ? GROUP BY kampan ORDER BY n DESC LIMIT ' . self::TOP, [$fromDay, $toDay], 'campaign'),
                // contact clicks (2.12, Core\Conversions): calls, e-mails and WhatsApp – leads next to the enquiries, counts only
                'contact_clicks' => array_diff_key(Conversions::summary($db, $fromDay, $toDay), ['by_page' => true]),
            ];
            // 3.26.1: the visits of every day of the month (the chart of the report), day 1 first
            $daily = array_map('intval', $db->pairs('SELECT DAY(den), SUM(navstevy) FROM {stat_dny} WHERE den >= ? AND den < ? GROUP BY den', [$fromDay, $toDay]));
            $stats['days'] = array_map(static fn (int $day): int => $daily[$day] ?? 0, range(1, (int) $monthStart->format('t')));
            // 3.29: browser-confirmed counting started in this month or the one it is compared with – the report says so,
            // because the earlier days include some bots and the team's own visits (an additive key of the stored data)
            $since = \Kaleta\Front\Stats::confirmedSince($s, $db);
            if ($since !== null && $since > $previousStart->format('Y-m-d') && $since < $toDay) {
                $stats['confirmed_since'] = $since;
            }
        }

        $enquiries = null;
        if (Extensions::isEnabled($s, 'poptavky')) {
            $byPage = [];
            foreach ($db->all('SELECT stranka AS k, COUNT(*) AS n FROM {poptavky} WHERE datum >= ? AND datum < ? GROUP BY stranka ORDER BY n DESC LIMIT 20', [$from, $to]) as $r) {
                $p = $path((string) $r['k']);
                $byPage[$p] = ($byPage[$p] ?? 0) + (int) $r['n'];
            }
            arsort($byPage);
            $enquiries = [
                'total' => $count('SELECT COUNT(*) FROM {poptavky} WHERE datum >= ? AND datum < ?', [$from, $to]),
                'forms' => $top('SELECT formular AS k, COUNT(*) AS n FROM {poptavky} WHERE datum >= ? AND datum < ? GROUP BY formular ORDER BY n DESC LIMIT ' . self::TOP, [$from, $to], 'form'),
                'pages' => array_map(fn (string $p, int $n): array => ['path' => $p, 'n' => $n], array_keys(array_slice($byPage, 0, self::TOP, true)), array_slice($byPage, 0, self::TOP, true)),
                'unanswered' => $count('SELECT COUNT(*) FROM {poptavky} WHERE stav = 0', []), // still "new" right now – whatever month they came in
                // 3.26.1: the month before, for the change on the headline tile
                'previous_total' => $count('SELECT COUNT(*) FROM {poptavky} WHERE datum >= ? AND datum < ?', [$monthStart->modify('first day of last month')->format('Y-m-d H:i:s'), $from]),
            ];
        }
        $signups = Extensions::isEnabled($s, 'newsletter') ? $count('SELECT COUNT(*) FROM {odberatele} WHERE stav = 1 AND datum >= ? AND datum < ?', [$from, $to]) : null;

        $updates = array_map(function (array $e): array {
            $info = json_decode((string) $e['data'], true); // Updater records the version it went to and the one it came from
            $info = is_array($info) ? $info : [];

            return ['type' => (string) $e['type'], 'date' => self::iso($e['created_at']), 'message' => (string) $e['message'], 'from' => (string) ($info['from'] ?? ''), 'to' => (string) ($info['version'] ?? '')];
        }, $db->all('SELECT type, created_at, message, data FROM {events} WHERE type IN (?, ?, ?) AND created_at >= ? AND created_at < ? ORDER BY id', [...self::UPDATE_EVENTS, $from, $to]));
        $lastBackup = Backup::listAll()[0]['cas'] ?? null;
        $backups = [
            'created' => $count('SELECT COUNT(*) FROM {events} WHERE type = ? AND created_at >= ? AND created_at < ?', ['backup.created', $from, $to]),
            'failed' => $count('SELECT COUNT(*) FROM {events} WHERE type = ? AND created_at >= ? AND created_at < ?', ['backup.failed', $from, $to]),
            'last' => $lastBackup === null ? null : date(DATE_ATOM, $lastBackup),
        ];
        // the change log names the Claude connection a change came through (ChangeLog::write); empty = a person in the admin
        $changes = [
            'people' => $count("SELECT COUNT(*) FROM {protokol} WHERE cas >= ? AND cas < ? AND via = '' AND modul <> 'whistleblowing'", [$from, $to]),
            'claude' => $count("SELECT COUNT(*) FROM {protokol} WHERE cas >= ? AND cas < ? AND via <> '' AND modul <> 'whistleblowing'", [$from, $to]),
        ];
        $problems = [];
        $errors = 0;
        foreach (Health::checks($app, true) as $check) { // what leaves the site: without the whistleblowing channel (N316-3)
            if ($check['stav'] === 'ok') {
                continue;
            }
            $errors += (int) ($check['stav'] === 'chyba');
            if (count($problems) < self::MAX_PROBLEMS) {
                $problems[] = ['group' => $check['skupina'], 'name' => $check['nazev'], 'state' => $check['stav'], 'info' => $check['info']];
            }
        }

        // 3.16: the archived report – what happened to the site, section by section; a section whose source fails (a table
        // of a newer migration, an error in the audit) is left out instead of losing the whole month
        $section = static function (callable $collect): mixed {
            try {
                return $collect();
            } catch (\Throwable) {
                return null;
            }
        };
        $claudeOn = Extensions::isEnabled($s, 'claude');
        if (is_array($enquiries)) {
            $enquiries['categories'] = $section(fn (): array => $top("SELECT kategorie AS k, COUNT(*) AS n FROM {poptavky} WHERE datum >= ? AND datum < ? AND kategorie <> '' GROUP BY kategorie ORDER BY n DESC", [$from, $to], 'category')) ?? [];
        }
        $events = fn (array $types): array => $section(fn (): array => array_map('intval', $db->pairs('SELECT type, COUNT(*) FROM {events} WHERE type IN (' . implode(',', array_fill(0, count($types), '?')) . ') AND created_at >= ? AND created_at < ? GROUP BY type', [...$types, $from, $to]))) ?? [];
        $firewallFrom = Clock::at('-' . Firewall::LOG_DAYS . ' days');
        $remote = $s->get('remote_backup');
        $remoteStatus = explode('|', $s->get('remote_backup_status'), 2);
        $backups += ['offsite' => in_array($remote, ['ftp', 's3'], true) ? $remote : '', 'offsite_last' => $remote !== 'vypnuto' && ($remoteStatus[1] ?? '') === 'ok' ? self::iso($remoteStatus[0]) : null,
            'offsite_failing' => $remote !== 'vypnuto' && $remote !== '' && isset($remoteStatus[1]) && $remoteStatus[1] !== 'ok'];

        return self::scrub([
            'schema' => ReportArchive::SCHEMA,
            'month' => $monthStart->format('Y-m'),
            'generated_at' => self::iso(Clock::now()),
            'stats' => $stats,
            // what people searched for (2.13, Core\SearchData): the top 3 queries of the month's last snapshot per engine
            'search' => SearchData::topQueries($db, $fromDay, $toDay),
            'enquiries' => $enquiries,
            'signups' => $signups,
            'updates' => $updates,
            // 3.8 (D3): the version the site runs and the release channel it follows (latest | stable | custom)
            'version' => KALETA_VERSION,
            'update_channel' => (new Updater($s))->effectiveChannel(),
            'backups' => $backups,
            'changes' => $changes,
            'problems' => $problems,
            'decisions' => ['enquiries' => $enquiries['unanswered'] ?? 0, 'errors' => $errors],
            'requests' => $claudeOn ? $section(fn (): array => self::requests($db, $from, $to)) : null,
            'work' => $section(fn (): array => self::work($db, $from, $to)),
            'claude' => $claudeOn ? $section(fn (): array => self::claude($db, $from, $to)) : null,
            'security' => $section(fn (): array => ['firewall' => array_map('intval', $db->pairs('SELECT reason, COUNT(*) FROM {firewall_log} WHERE created_at >= ? AND created_at < ? GROUP BY reason ORDER BY reason', [$from, $to])),
                // the firewall keeps its log for Firewall::LOG_DAYS: in a 31-day month (or a report made or rebuilt later) the
                // first days are gone – the moment from which the counts are complete, when that is more than an hour into the month
                'firewall_from' => strtotime($firewallFrom) - $monthStart->getTimestamp() > 3600 ? self::iso($firewallFrom) : null,
                'sign_ins' => $count("SELECT COUNT(*) FROM {protokol} WHERE modul = 'prihlaseni' AND akce = 'login' AND cas >= ? AND cas < ?", [$from, $to]),
                'failed_sign_ins' => $count("SELECT COUNT(*) FROM {protokol} WHERE modul = 'prihlaseni' AND akce = 'neuspech' AND cas >= ? AND cas < ?", [$from, $to]),
                'events' => $events(self::SECURITY_EVENTS)]),
            'audit' => $section(fn (): array => self::audit($app, $monthStart)),
            'bookings' => Extensions::isEnabled($s, 'bookings') ? $section(fn (): array => ['total' => $count('SELECT COUNT(*) FROM {bookings} WHERE created_at >= ? AND created_at < ?', [$from, $to]),
                'statuses' => array_map('intval', $db->pairs('SELECT status, COUNT(*) FROM {bookings} WHERE created_at >= ? AND created_at < ? GROUP BY status', [$from, $to])),
                'appointments' => $count("SELECT COUNT(*) FROM {bookings} WHERE starts_at >= ? AND starts_at < ? AND status IN ('confirmed', 'done', 'no_show')", [$from, $to])]) : null,
            'newsletter' => Extensions::isEnabled($s, 'newsletter') ? $section(fn (): array => ['signups' => (int) $signups,
                'campaigns' => array_map(static fn (array $r): array => ['subject' => (string) $r['subject'], 'date' => self::iso($r['finished_at']), 'sent' => (int) $r['sent_count']],
                    $db->all("SELECT subject, finished_at, sent_count FROM {newsletters} WHERE status = 'sent' AND finished_at >= ? AND finished_at < ? ORDER BY finished_at LIMIT 20", [$from, $to]))]) : null,
            // 3.31: the pipeline – counts only, never values (D-W7: a report is never money); null on a site without deals
            'pipeline' => $section(fn (): ?array => self::pipeline($app, $from, $to)),
            'open' => $section(fn (): array => self::open($app, $enquiries)),
            // 3.26.1: the month's uptime from the fleet console (Fleet\Uptime, drawn by Core\UptimeStrip) – null without a console
            'uptime' => $section(fn (): ?array => \Kaleta\Fleet\Uptime::month($s, $monthStart->format('Y-m'))),
            // 3.26.1: how much work keeping the site running took – counts only (Core\ReportSummary::care reads them)
            'care' => $section(fn (): array => self::care($app, $from, $to, $monthStart, is_array($enquiries))),
        ]);
    }

    /** Events the care part counts: the off-site copies, the links and redirects fixed by themselves, the replies to enquiries. */
    private const array CARE_EVENTS = ['backup.copied', 'links.healed', 'redirect.auto', 'enquiry.replied'];

    /**
     * What it took to run the site this month (3.26.1), from what the site records anyway: events (off-site copies, links
     * healed, redirects made by themselves, replies sent and everything that asked a person to decide), the runs of the
     * daily checks (Core\Scheduler counts the runs of a job; the month's runs are the difference to the count stored with
     * the previous month's report – unknown without it), spam and handled enquiries, and the team's comments. Counts only:
     * no hours, no names, no texts.
     *
     * @return array<string, mixed>
     */
    private static function care(App $app, string $from, string $to, \DateTimeImmutable $monthStart, bool $enquiriesOn): array
    {
        $db = $app->db();
        $types = [...self::CARE_EVENTS, ...array_keys(ReportSummary::ATTENTION_EVENTS)];
        $events = array_map('intval', $db->pairs('SELECT type, COUNT(*) FROM {events} WHERE type IN (' . implode(',', array_fill(0, count($types), '?')) . ') AND created_at >= ? AND created_at < ? GROUP BY type', [...$types, $from, $to]));
        $runs = array_map('intval', $db->pairs('SELECT name, runs FROM {jobs} WHERE name IN (' . implode(',', array_fill(0, count(ReportSummary::CHECK_JOBS), '?')) . ')', ReportSummary::CHECK_JOBS));
        $own = ReportArchive::get($db, $monthStart->format('Y-m'))['data']['care'] ?? null;
        if (is_array($own) && is_array($own['job_runs'] ?? null)) {
            // N3261-3: a month rebuilt later – today's counters say nothing about it; it keeps the runs counted when it was archived
            $runs = array_map('intval', array_filter($own['job_runs'], 'is_numeric'));
            $checks = is_array($own['checks'] ?? null) ? array_map('intval', array_filter($own['checks'], 'is_numeric')) : null;
        } else {
            $checks = self::checkRuns($runs, ReportArchive::get($db, $monthStart->modify('first day of last month')->format('Y-m'))['data']['care']['job_runs'] ?? null);
        }
        // N3261-2: what people changed in the administration – the change log of signed-in people (kdo), without sign-ins and
        // failed sign-ins (a bot's attempts are no work), Kaleta's own changes (no user) and the comments counted below
        $peopleChanges = (int) $db->value("SELECT COUNT(*) FROM {protokol} WHERE cas >= ? AND cas < ? AND via = '' AND kdo IS NOT NULL
            AND modul NOT IN ('prihlaseni', 'whistleblowing') AND akce NOT IN ('comment added', 'note added')", [$from, $to]);
        $comments = null;
        try { // the team's notes on enquiries and comments on pages and items (3.26) – by people, not through Claude
            $comments = (int) $db->value("SELECT COUNT(*) FROM {comments} WHERE created_at >= ? AND created_at < ? AND deleted_at IS NULL AND via <> 'mcp'", [$from, $to]);
        } catch (\Throwable) {
        }
        $care = ['job_runs' => $runs, 'checks' => $checks, 'people_changes' => $peopleChanges, 'offsite_copies' => $events['backup.copied'] ?? 0, 'links_healed' => $events['links.healed'] ?? 0,
            'redirects_auto' => $events['redirect.auto'] ?? 0, 'attention' => array_filter(array_intersect_key($events, ReportSummary::ATTENTION_EVENTS))];
        if ($comments !== null) {
            $care['comments'] = $comments;
        }
        if ($enquiriesOn) {
            $care['spam'] = (int) $db->value("SELECT COUNT(*) FROM {poptavky} WHERE datum >= ? AND datum < ? AND kategorie = 'spam'", [$from, $to]);
            $care['enquiries_handled'] = (int) $db->value("SELECT COUNT(*) FROM {poptavky} WHERE datum >= ? AND datum < ? AND stav = 2 AND kategorie <> 'spam'", [$from, $to]);
            $care['replies'] = $events['enquiry.replied'] ?? 0;
        }

        return $care;
    }

    /**
     * The runs of the daily checks in a month: each job's counter now less the one stored with the previous month's report;
     * null without that report (the first month – unknown, never a guess). A counter lower than before was reset (a
     * restored database, a job table emptied): the runs since then are the month's. Pure.
     *
     * @param array<string, int> $now
     * @return array<string, int>|null
     */
    public static function checkRuns(array $now, mixed $before): ?array
    {
        if (!is_array($before)) {
            return null;
        }
        $checks = [];
        foreach ($now as $job => $count) {
            $earlier = is_numeric($before[$job] ?? null) ? (int) $before[$job] : 0;
            $checks[$job] = $count >= $earlier ? $count - $earlier : max(0, $count); // N3261-3: a reset counter
        }

        return $checks;
    }

    /** Security events the report counts (3.16) – the types only, never an address or an account. */
    private const array SECURITY_EVENTS = ['firewall.blocked', 'security.account_suspended', 'security.connection_revoked', 'security.token_reuse'];

    /** At most this many titles in one list of the archived report (requests, pages, news, runs). */
    public const int LIST_LIMIT = 20;

    /**
     * The pipeline of the month (3.31, Core\Deals::monthCounts): new deals, won and lost ones, and how many deals reached
     * each stage, with the stages' names in the site's language – counts only, never values. Null without deals.
     *
     * @return array{new: int, won: int, lost: int, stages: list<array{key: string, label: string, n: int}>}|null
     */
    private static function pipeline(App $app, string $from, string $to): ?array
    {
        $counts = Deals::monthCounts($app, $from, $to);
        if ($counts === null) {
            return null;
        }
        $s = $app->settings();
        $labels = array_column(Language::runWith(Language::defaults($s), fn (): array => Deals::stages($s), 'admin-'), 'label', 'key');

        return ['new' => $counts['new'], 'won' => $counts['won'], 'lost' => $counts['lost'],
            'stages' => array_map(fn (string $key, int $n): array => ['key' => $key, 'label' => (string) ($labels[$key] ?? $key), 'n' => $n], array_keys($counts['stages']), array_values($counts['stages']))];
    }

    /** Areas of the site a change belongs to, in the order the report shows them. */
    public const array AREAS = ['pages' => 'Pages and builder', 'news' => 'News', 'collections' => 'Collections', 'look' => 'Look and menu', 'media' => 'Media',
        'enquiries' => 'Enquiries and bookings', 'newsletter' => 'Newsletter', 'settings' => 'Settings and accounts', 'other' => 'Other'];

    /** Admin sections (Module::IDENT) => area; a section not listed is "other". */
    private const array MODULE_AREAS = ['pages' => 'pages', 'parts' => 'pages', 'components' => 'pages', 'popups' => 'pages', 'redirects' => 'pages', 'audit' => 'pages',
        'news' => 'news', 'categories' => 'news', 'tags' => 'news', 'collections' => 'collections', 'appearance' => 'look', 'menu' => 'look', 'media' => 'media',
        'enquiries' => 'enquiries', 'bookings' => 'enquiries', 'subscribers' => 'newsletter', 'newsletters' => 'newsletter', 'settings' => 'settings', 'users' => 'settings',
        'roles' => 'settings', 'claude_settings' => 'settings', 'extensions' => 'settings', 'business' => 'settings', 'facts' => 'settings', 'ucet' => 'settings', 'addons' => 'settings'];

    /** The area of a Claude tool (the change log names the tool) – by the words of its English name. */
    public static function toolArea(string $tool): string
    {
        $tool = \Kaleta\Mcp\Catalog::english($tool) ?? $tool;

        return match (true) {
            preg_match('/newsletter/', $tool) === 1 => 'newsletter',
            preg_match('/news|categor|tag|social/', $tool) === 1 => 'news',
            preg_match('/collection|item/', $tool) === 1 => 'collections',
            preg_match('/look|class|design|menu/', $tool) === 1 => 'look',
            preg_match('/media|upload|file/', $tool) === 1 => 'media',
            preg_match('/enquir|booking|testimonial/', $tool) === 1 => 'enquiries',
            preg_match('/page|build|section|component|part|popup|redirect|trash|draft|not_found/', $tool) === 1 => 'pages',
            preg_match('/setting|fact|hours|blueprint/', $tool) === 1 => 'settings',
            default => 'other',
        };
    }

    /** The area of a change-log row: a person's change by the admin section, Claude's by its tool. */
    public static function area(string $module, string $action): string
    {
        return $module === 'claude' ? self::toolArea($action) : (self::MODULE_AREAS[$module] ?? 'other');
    }

    /**
     * Requests to Claude (Ask Claude, 2.15): finished this month, and the ones still open – title, status and dates only.
     *
     * @return array{created: int, done: list<array<string, string>>, done_count: int, open: list<array<string, string>>, open_count: int}
     */
    private static function requests(Db $db, string $from, string $to): array
    {
        $done = "FROM {requests} WHERE status IN ('done', 'declined') AND done_at >= ? AND done_at < ?";
        $open = "FROM {requests} WHERE status IN ('new', 'in_progress') AND created_at < ?";
        $row = static fn (array $r): array => ['title' => (string) $r['title'], 'status' => (string) $r['status'], 'created' => self::iso($r['created_at']), 'done' => self::iso($r['done_at'] ?? null) ?? ''];

        return [
            'created' => (int) $db->value('SELECT COUNT(*) FROM {requests} WHERE created_at >= ? AND created_at < ?', [$from, $to]),
            'done' => array_map($row, $db->all('SELECT title, status, created_at, done_at ' . $done . ' ORDER BY done_at LIMIT ' . self::LIST_LIMIT, [$from, $to])),
            'done_count' => (int) $db->value('SELECT COUNT(*) ' . $done, [$from, $to]),
            'open' => array_map($row, $db->all('SELECT title, status, created_at, NULL AS done_at ' . $open . ' ORDER BY created_at LIMIT ' . self::LIST_LIMIT, [$to])),
            'open_count' => (int) $db->value('SELECT COUNT(*) ' . $open, [$to]),
        ];
    }

    /**
     * Changes by people and by Claude per area (the change log, without sign-ins and reads), and the notable ones: pages
     * published (the build.published events), news published that month, look publishes.
     *
     * @return array<string, mixed>
     */
    private static function work(Db $db, string $from, string $to): array
    {
        $areas = [];
        // the whistleblowing channel is never counted: even how often its cases were handled tells that reports exist
        foreach ($db->all("SELECT modul, akce, via <> '' AS claude, COUNT(*) AS n FROM {protokol} WHERE cas >= ? AND cas < ? AND modul NOT IN ('prihlaseni', 'whistleblowing')
            AND NOT (modul = 'claude' AND akce IN ('list_enquiries', 'list_bookings')) GROUP BY modul, akce, claude", [$from, $to]) as $r) {
            $area = self::area((string) $r['modul'], (string) $r['akce']);
            $areas[$area] ??= ['people' => 0, 'claude' => 0];
            $areas[$area][(int) $r['claude'] === 1 ? 'claude' : 'people'] += (int) $r['n'];
        }
        $areas = array_intersect_key(array_replace(array_fill_keys(array_keys(self::AREAS), null), $areas), $areas);
        // pages published: one row per page, the last publishing of the month, by a person or through Claude
        $pages = [];
        foreach ($db->all("SELECT created_at, data FROM {events} WHERE type = 'build.published' AND created_at >= ? AND created_at < ? ORDER BY id", [$from, $to]) as $r) {
            $info = json_decode((string) $r['data'], true);
            if (is_array($info) && isset($info['ids'])) {
                $pages[(int) $info['ids']] = ['date' => self::iso($r['created_at']), 'claude' => !empty($info['claude'])];
            }
        }
        $titles = $pages === [] ? [] : $db->pairs('SELECT ids, titulek FROM {stranky} WHERE ids IN (' . implode(',', array_keys($pages)) . ')');
        $published = [];
        foreach ($pages as $id => $p) {
            $published[] = ['title' => (string) ($titles[$id] ?? '#' . $id), 'date' => $p['date'], 'by' => $p['claude'] ? 'claude' : 'person'];
        }

        return [
            'areas' => $areas,
            'pages_published' => array_slice($published, 0, self::LIST_LIMIT),
            'pages_published_count' => count($published),
            'news_published' => array_map(static fn (array $r): array => ['title' => (string) $r['titulek'], 'date' => self::iso($r['datum'])],
                $db->all('SELECT titulek, datum FROM {novinky} WHERE visible = 1 AND smazano IS NULL AND datum >= ? AND datum < ? ORDER BY datum LIMIT ' . self::LIST_LIMIT, [$from, $to])),
            'news_published_count' => (int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND smazano IS NULL AND datum >= ? AND datum < ?', [$from, $to]),
            'look_published' => (int) $db->value("SELECT COUNT(*) FROM {events} WHERE type = 'look.published' AND created_at >= ? AND created_at < ?", [$from, $to]),
        ];
    }

    /**
     * Claude sessions (the undo journal, 2.17) and scheduled runs (2.17): counts, and the runs by the kind of task
     * (AgentSchedules::TASKS) and result. Never a run's name or summary (N316-1): Claude writes the summary, the triage
     * task from the enquiries it read – names, phone numbers and what a visitor wanted would end up in the client report.
     *
     * @return array{sessions: int, calls: int, undone: int, runs: int, run_statuses: array<string, int>, run_tasks: array<string, array<string, int>>}
     */
    private static function claude(Db $db, string $from, string $to): array
    {
        $sessions = $db->one('SELECT COUNT(*) AS n, COALESCE(SUM(calls), 0) AS calls, COALESCE(SUM(undone_at IS NOT NULL), 0) AS undone FROM {agent_sessions} WHERE started_at >= ? AND started_at < ?', [$from, $to]) ?? [];
        $statuses = array_map('intval', $db->pairs('SELECT status, COUNT(*) FROM {agent_runs} WHERE due_at >= ? AND due_at < ? GROUP BY status', [$from, $to]));
        $tasks = [];
        foreach ($db->all('SELECT s.task, r.status, COUNT(*) AS n FROM {agent_runs} r JOIN {agent_schedules} s ON s.id = r.schedule_id WHERE r.due_at >= ? AND r.due_at < ? GROUP BY s.task, r.status ORDER BY s.task, r.status', [$from, $to]) as $r) {
            $task = isset(AgentSchedules::TASKS[(string) $r['task']]) ? (string) $r['task'] : 'custom';
            $tasks[$task][(string) $r['status']] = ($tasks[$task][(string) $r['status']] ?? 0) + (int) $r['n'];
        }

        return [
            'sessions' => (int) ($sessions['n'] ?? 0), 'calls' => (int) ($sessions['calls'] ?? 0), 'undone' => (int) ($sessions['undone'] ?? 0),
            'runs' => array_sum($statuses), 'run_statuses' => $statuses, 'run_tasks' => $tasks,
        ];
    }

    /**
     * The site audit when the report is made: findings by kind, and against the previous archived month what got fixed and
     * what is new (by the counts per kind – the audit keeps no history of its own).
     *
     * @return array{kinds: array<string, int>, total: int, previous: ?int, fixed: ?int, new: ?int}
     */
    private static function audit(App $app, \DateTimeImmutable $monthStart): array
    {
        $kinds = [];
        foreach ((new Audit($app))->run() as $finding) {
            $kinds[$finding['kind']] = ($kinds[$finding['kind']] ?? 0) + 1;
        }
        $before = ReportArchive::get($app->db(), $monthStart->modify('first day of last month')->format('Y-m'))['data']['audit']['kinds'] ?? null;
        [$fixed, $new] = [null, null];
        if (is_array($before)) {
            [$fixed, $new] = [0, 0];
            foreach (array_unique([...array_keys($kinds), ...array_keys($before)]) as $kind) {
                $delta = ($kinds[$kind] ?? 0) - (int) ($before[$kind] ?? 0);
                if ($delta > 0) {
                    $new += $delta;
                } else {
                    $fixed -= $delta;
                }
            }
        }

        return ['kinds' => $kinds, 'total' => array_sum($kinds), 'previous' => is_array($before) ? (int) array_sum($before) : null, 'fixed' => $fixed, 'new' => $new];
    }

    /**
     * What waits when the report is made (the month has just ended): requests, drafts, files waiting for approval, enquiries nobody opened.
     *
     * @param array<string, mixed>|null $enquiries
     * @return array<string, int>
     */
    private static function open(App $app, ?array $enquiries): array
    {
        $db = $app->db();
        $open = [
            'requests' => Extensions::isEnabled($app->settings(), 'claude') ? (int) $db->value("SELECT COUNT(*) FROM {requests} WHERE status IN ('new', 'in_progress')") : 0,
            'page_drafts' => (int) $db->value('SELECT COUNT(*) FROM {stranky} WHERE stavba_koncept IS NOT NULL AND smazano IS NULL'),
            'part_drafts' => (int) $db->value('SELECT COUNT(*) FROM {casti} WHERE stavba_koncept IS NOT NULL'),
            'news_drafts' => Extensions::isEnabled($app->settings(), 'novinky') ? (int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 0 AND smazano IS NULL') : 0,
            'look_draft' => (int) Look::hasDraft($app->settings()),
            'staged_files' => MediaStaging::count($db),
            'enquiries' => (int) ($enquiries['unanswered'] ?? 0),
        ];

        return array_filter($open);
    }

    /**
     * Nothing personal leaves the report (3.16): every string of the data – titles written by staff, search queries, form
     * and campaign names, newsletter subjects – loses e-mail addresses (also written out or spaced), any other word with
     * "@" (a handle) and phone numbers (Core\Redact, the masker the fleet heartbeat uses too). A report stored before
     * N316-1 loses its list of scheduled runs with their names and summaries. The archive, the print page and
     * get_monthly_report all read the scrubbed data.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function scrub(array $data): array
    {
        if (is_array($data['claude'] ?? null)) {
            unset($data['claude']['run_list']);
        }
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_string($value)) {
                $value = Redact::text($value, '…', '…');
            }
        });

        return $data;
    }

    /** A moment of the database (the site's wall time) as the report stores it: ISO 8601 with the offset, null for none. */
    private static function iso(mixed $value): ?string
    {
        $value = is_string($value) ? $value : null;
        $moment = Clock::fromDb($value) ?? ($value !== null ? \DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, Clock::zone()) : false);

        return $moment instanceof \DateTimeImmutable ? $moment->format(DATE_ATOM) : null;
    }

    /**
     * A moment of a stored report in the site's zone now: ISO 8601 with the offset (3.16), or the wall time of an older
     * report; null for anything else. Printing it shows the same clock as the rest of the site after a change of the time zone.
     */
    public static function moment(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || $value === '' || ($moment = date_create_immutable($value)) === false) {
            return null;
        }

        return $moment->setTimezone(Clock::zone());
    }

    /**
     * The e-mail (3.26.1 redesign): a band with the month, the opening sentences, the headline tiles, what waits for a person,
     * the uptime (with a console), what it took to run the site, then traffic, enquiries, what was published, the care
     * details and the problems – Core\ReportVisual blocks, e-mail safe (tables, inline styles, no SVG or script, 600 px,
     * dark-mode classes), in the site's accent colour. The plain-text twin says everything the HTML says. Written in the
     * site's default language with the admin dictionary – the owner reads it, not a visitor. $previous is the previous
     * month's archived data (the changes on the tiles), null when it is not stored.
     *
     * N3261-5: a month with many rows everywhere made the HTML larger than Gmail shows (it clips at about 102 KB – the
     * button to the full report and the footer were cut off): over EMAIL_BUDGET the HTML is drawn again with fewer rows in
     * each list (3, then 1); the text part keeps every row.
     *
     * @param array<string, mixed> $data from build()
     * @param array<string, mixed>|null $previous
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(array $data, Settings $s, string $siteUrl, ?array $previous = null): array
    {
        $mail = self::draw($data, $s, $siteUrl, $previous, self::TOP);
        foreach ([3, 1] as $top) {
            if (strlen($mail['html']) <= self::EMAIL_BUDGET) {
                break;
            }
            $mail['html'] = self::draw($data, $s, $siteUrl, $previous, $top)['html'];
        }

        return $mail;
    }

    /** The largest HTML of the report e-mail before its lists get shorter (render): under the ~102 KB Gmail clips at. */
    public const int EMAIL_BUDGET = 92_000;

    /**
     * The e-mail with at most $top rows in each list of the HTML (the text part has them all).
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $previous
     * @return array{subject: string, html: string, text: string}
     */
    private static function draw(array $data, Settings $s, string $siteUrl, ?array $previous, int $top): array
    {
        return Language::runWith(Language::defaults($s), function () use ($data, $s, $siteUrl, $previous, $top): array {
            $data = self::scrub($data); // no e-mail address or phone number gets through from the data – a form name or a title could carry one
            $previous = $previous !== null ? self::scrub($previous) : null;
            $siteUrl = rtrim($siteUrl, '/');
            $siteName = $s->get('site_name');
            $agency = $s->get('agency_name');
            $month = (string) ($data['month'] ?? '');
            $monthName = t(self::MONTHS[(int) substr($month, 5, 2)] ?? 'January') . ' ' . substr($month, 0, 4);
            $subject = t('Website report – %s – %s', $monthName, $siteName);
            $n = static fn (mixed $value): string => format_count((int) $value);
            $admin = $siteUrl . '/admin.php';
            $host = (string) preg_replace('#^https?://#', '', $siteUrl);
            $p = ReportVisual::palette($s);
            $width = ReportVisual::EMAIL_INNER;
            $care = ReportSummary::care($data);

            $rows = [];
            $text = [];
            $plainPairs = static fn (array $pairs): string => implode("\n", array_map(static fn (array $pair): string => '  ' . $pair[0] . ($pair[1] !== '' ? ': ' . $pair[1] : ''), $pairs));
            $section = function (string $html, string $plain) use (&$rows, &$text): void {
                $rows[] = ReportVisual::rule() . ReportVisual::row($html);
                $text[] = $plain;
            };
            $change = static function (int $now, int $before) use ($n): string {
                if ($before === 0) {
                    return $n($now);
                }
                $percent = (int) round(100 * ($now - $before) / $before);

                return $n($now) . ' (' . ($percent >= 0 ? '+' : '−') . abs($percent) . ' %)';
            };

            // the band, the opening sentences and the headline numbers
            $rows[] = '<tr><td>' . ReportVisual::header($p, t('Website report'), $monthName, $siteName . ' · ' . $host) . '</td></tr>';
            $lead = ReportSummary::lead($care, $siteName);
            $tiles = ReportSummary::tiles($data, $previous, $care);
            $compared = array_filter($tiles, static fn (array $tile): bool => ($tile['tone'] ?? '') === 'up' || ($tile['tone'] ?? '') === 'down') !== [];
            $rows[] = ReportVisual::row(ReportVisual::lead($lead['html'], $p), 24, 18);
            $rows[] = ReportVisual::row(ReportVisual::tiles($tiles, $p, $width) . ($compared ? ReportVisual::note(t('The arrows compare with the month before.'), $p) : ''), 0, 24);
            $text[] = $lead['text'];
            $text[] = mb_strtoupper(t('At a glance')) . "\n" . $plainPairs(array_map(static fn (array $tile): array => [$tile['label'], $tile['value'] . (isset($tile['change']) ? ' (' . $tile['change'] . ')' : (($tile['note'] ?? '') !== '' ? ' (' . $tile['note'] . ')' : ''))], $tiles));

            // what waits for a person – before the numbers, so the owner sees it first
            $waiting = ReportSummary::waiting($data, $admin);
            if ($waiting !== []) {
                $rows[] = ReportVisual::row(ReportVisual::callout(t('Waiting for you'), $waiting, $p), 0, 24);
                $text[] = mb_strtoupper(t('Waiting for you')) . "\n" . implode("\n", array_map(static fn (array $w): string => '  – ' . $w[0] . ' – ' . $w[1], $waiting));
            }

            // uptime – only with a console (data from Fleet, agent B); without one the e-mail has no uptime section
            $uptime = ReportSummary::uptimeBlock($data, $p, $width);
            if ($uptime !== null) {
                $section($uptime['html'], $uptime['text']);
            }

            // what it took to run the site
            $block = ReportSummary::careBlock($care, $p, $width);
            $section($block['html'], $block['text']);

            // traffic: the visits of each day, the pages, where visitors came from
            $stats = is_array($data['stats'] ?? null) ? $data['stats'] : null;
            if ($stats !== null) {
                $pairs = [[t('Visits'), $change((int) ($stats['visits'] ?? 0), (int) ($stats['previous_visits'] ?? 0))], [t('Page views'), $change((int) ($stats['views'] ?? 0), (int) ($stats['previous_views'] ?? 0))]];
                foreach (['calls' => 'Calls – clicks on a phone number', 'emails' => 'E-mails – clicks on an e-mail address', 'whatsapp' => 'WhatsApp – clicks on a WhatsApp link'] as $key => $label) {
                    if ((int) ($stats['contact_clicks'][$key] ?? 0) > 0) {
                        $pairs[] = [t($label), $n($stats['contact_clicks'][$key])];
                    }
                }
                $html = ReportVisual::title(t('Traffic'), $p, t('Compared with the month before.'));
                $days = array_values(array_map('intval', array_filter((array) ($stats['days'] ?? []), 'is_numeric')));
                if ($days !== [] && max($days) > 0) {
                    $start = new \DateTimeImmutable($month . '-01');
                    $html .= ReportVisual::chart($days, format_date($start), format_date($start->modify('+' . (count($days) - 1) . ' days')), t('Most in a day: %s', $n(max($days))), $p) . '<div style="height:14px;line-height:14px;font-size:0;">&nbsp;</div>';
                }
                $html .= ReportVisual::pairs($pairs, $p);
                $confirmed = is_string($stats['confirmed_since'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $stats['confirmed_since'])
                    ? t('From %s, only visits confirmed by a visitor’s browser are counted – bots, link previews and your own visits are left out. Earlier numbers include some of them.', format_date($stats['confirmed_since'])) : '';
                if ($confirmed !== '') {
                    $html .= ReportVisual::note($confirmed, $p); // 3.29
                }
                $plain = [];
                foreach (['pages' => ['Most read pages', 'path'], 'sources' => ['Visitors came from', 'site'], 'campaigns' => ['Campaigns', 'campaign']] as $key => [$title, $field]) {
                    $items = array_values(array_filter((array) ($stats[$key] ?? []), 'is_array'));
                    if ($items !== []) {
                        $html .= ReportVisual::subtitle(t($title), $p) . ReportVisual::bars(array_map(static fn (array $i): array => [(string) ($i[$field] ?? ''), (int) ($i['n'] ?? 0)], array_slice($items, 0, $top)), $p);
                        $plain[] = [t($title), ''];
                        foreach ($items as $i) {
                            $plain[] = ['  ' . (string) ($i[$field] ?? ''), $n($i['n'] ?? 0)];
                        }
                    }
                }
                $section($html, mb_strtoupper(t('Traffic')) . "\n" . $plainPairs([...$pairs, ...$plain]) . ($confirmed !== '' ? "\n  " . $confirmed : ''));
            } else {
                $off = t('The built-in statistics are off (Settings → Analytics), so there are no traffic figures.');
                $section(ReportVisual::title(t('Traffic'), $p) . ReportVisual::lead(e($off), $p, 14), mb_strtoupper(t('Traffic')) . "\n  " . $off);
            }

            // searches that brought visitors – only when an engine is connected and had data this month
            $search = array_values(array_filter((array) ($data['search'] ?? []), 'is_array'));
            if ($search !== []) {
                $bars = array_map(static fn (array $q): array => [t('%s on %s', (string) ($q['query'] ?? ''), ($q['engine'] ?? '') === 'bing' ? 'Bing' : 'Google'), (int) ($q['clicks'] ?? 0), t('%s clicks', $n($q['clicks'] ?? 0))], $search);
                $section(ReportVisual::title(t('Searches that brought visitors'), $p) . ReportVisual::bars(array_slice($bars, 0, $top), $p),
                    mb_strtoupper(t('Searches that brought visitors')) . "\n" . $plainPairs(array_map(static fn (array $b): array => [$b[0], $b[2]], $bars)));
            }

            // leads: enquiries by form and page, sign-ups
            $enquiries = is_array($data['enquiries'] ?? null) ? $data['enquiries'] : null;
            $signups = $data['signups'] ?? null;
            if ($enquiries !== null || $signups !== null) {
                $pairs = [];
                if ($enquiries !== null) {
                    $pairs[] = [t('Enquiries received'), $n($enquiries['total'] ?? 0)];
                }
                if ($signups !== null) {
                    $pairs[] = [t('Newsletter sign-ups'), $n($signups)];
                }
                $html = ReportVisual::title(t('Enquiries and sign-ups'), $p) . ReportVisual::pairs($pairs, $p);
                $plain = array_slice($pairs, 0, $enquiries !== null ? 1 : 0);
                foreach (['forms' => ['By form', 'form'], 'pages' => ['By page', 'path']] as $key => [$title, $field]) {
                    $items = array_values(array_filter((array) ($enquiries[$key] ?? []), 'is_array'));
                    if ($items !== []) {
                        $html .= ReportVisual::subtitle(t($title), $p) . ReportVisual::bars(array_map(static fn (array $i): array => [(string) ($i[$field] ?? '') !== '' ? (string) $i[$field] : t('Form'), (int) ($i['n'] ?? 0)], array_slice($items, 0, $top)), $p);
                        foreach ($items as $i) {
                            $plain[] = ['  ' . ($field === 'path' ? t('from page %s', (string) ($i[$field] ?? '')) : ((string) ($i[$field] ?? '') !== '' ? (string) $i[$field] : t('Form'))), $n($i['n'] ?? 0)];
                        }
                    }
                }
                if ($signups !== null) {
                    $plain[] = [t('Newsletter sign-ups'), $n($signups)];
                }
                $section($html, mb_strtoupper(t('Enquiries and sign-ups')) . "\n" . $plainPairs($plain));
            }

            // 3.31: the pipeline – counts only (new, won, lost), never values
            $pipeline = is_array($data['pipeline'] ?? null) ? $data['pipeline'] : null;
            if ($pipeline !== null) {
                $pairs = [[t('New deals'), $n($pipeline['new'] ?? 0)], [t('Deals won'), $n($pipeline['won'] ?? 0)], [t('Deals lost'), $n($pipeline['lost'] ?? 0)]];
                $section(ReportVisual::title(t('Pipeline'), $p) . ReportVisual::pairs($pairs, $p), mb_strtoupper(t('Pipeline')) . "\n" . $plainPairs($pairs));
            }

            // what is new on the site: pages and news published this month
            $work = is_array($data['work'] ?? null) ? $data['work'] : null;
            if ($work !== null && ((int) ($work['pages_published_count'] ?? 0) > 0 || (int) ($work['news_published_count'] ?? 0) > 0)) {
                $html = ReportVisual::title(t('New on the site'), $p);
                $plain = [];
                foreach (['pages_published' => 'Pages published', 'news_published' => 'News published'] as $key => $title) {
                    $items = array_slice(array_values(array_filter((array) ($work[$key] ?? []), 'is_array')), 0, 5);
                    $total = (int) ($work[$key . '_count'] ?? count($items));
                    if ($items === []) {
                        continue;
                    }
                    $list = array_map(static fn (array $i): array => [(string) ($i['title'] ?? ''), format_date(self::moment($i['date'] ?? null))], $items);
                    $shown = array_slice($list, 0, $top);
                    $html .= ReportVisual::subtitle(t($title) . ' · ' . $n($total), $p) . ReportVisual::pairs($shown, $p) . ($total > count($shown) ? ReportVisual::note(t('… and %d more.', $total - count($shown)), $p) : '');
                    $plain[] = [t($title), $n($total)];
                    foreach ($list as $l) {
                        $plain[] = ['  ' . $l[0], $l[1]];
                    }
                }
                $section($html, mb_strtoupper(t('New on the site')) . "\n" . $plainPairs($plain));
            }

            // the care details: version, updates, backups, security
            $pairs = [];
            if (isset($data['version'])) {
                $channel = t(['stable' => 'Stable channel', 'custom' => 'custom update source'][(string) ($data['update_channel'] ?? '')] ?? 'Latest channel');
                $pairs[] = [t('Kaleta version'), (string) $data['version'] . ' · ' . $channel];
            }
            $updates = array_values(array_filter((array) ($data['updates'] ?? []), 'is_array'));
            $pairs[] = [t('Updates'), $updates === [] ? t('none this month') : ''];
            foreach ($updates as $u) {
                $pairs[] = ['  ' . format_date(self::moment($u['date'] ?? null)), (string) ($u['message'] ?? '')];
            }
            $backups = (array) ($data['backups'] ?? []);
            $pairs[] = [t('Backups'), t('%d made, %d failed', (int) ($backups['created'] ?? 0), (int) ($backups['failed'] ?? 0))
                . (self::moment($backups['last'] ?? null) !== null ? ', ' . t('the last one %s', format_date(self::moment($backups['last']))) : '')];
            $offsite = (string) ($backups['offsite'] ?? '');
            if ($offsite !== '') {
                $pairs[] = [t('Off-site copy'), strtoupper($offsite) . (!empty($backups['offsite_failing']) ? ' · ' . t('the last upload failed') : '')];
            }
            $changes = (array) ($data['changes'] ?? []);
            $pairs[] = [t('Changes in the administration'), t('%d by people, %d by Claude', (int) ($changes['people'] ?? 0), (int) ($changes['claude'] ?? 0))];
            $security = is_array($data['security'] ?? null) ? $data['security'] : null;
            if ($security !== null) {
                $pairs[] = [t('Security'), t('%d blocked requests, %d failed sign-ins', array_sum(array_map('intval', (array) ($security['firewall'] ?? []))), (int) ($security['failed_sign_ins'] ?? 0))];
            }
            $section(ReportVisual::title(t('Care of the site'), $p) . ReportVisual::pairs(array_map(static fn (array $pair): array => [trim($pair[0]), $pair[1]], $pairs), $p),
                mb_strtoupper(t('Care of the site')) . "\n" . $plainPairs($pairs));

            // problems right now
            $problems = array_values(array_filter((array) ($data['problems'] ?? []), 'is_array'));
            if ($problems === []) {
                $fine = t('None – every check in System status is fine.');
                $section(ReportVisual::title(t('Problems right now'), $p) . ReportVisual::callout($fine, [], $p, true), mb_strtoupper(t('Problems right now')) . "\n  " . $fine);
            } else {
                $items = array_map(static fn (array $pr): array => [(string) ($pr['name'] ?? '') . ' – ' . (string) ($pr['info'] ?? '')], $problems);
                $section(ReportVisual::title(t('Problems right now'), $p) . ReportVisual::callout(t('Details are in System status'), [...$items, [t('Open System status'), $admin . '?module=status']], $p),
                    mb_strtoupper(t('Problems right now')) . "\n" . implode("\n", array_map(static fn (array $i): string => '  – ' . $i[0], $items)) . "\n  " . $admin . '?module=status');
            }

            // the whole report, then the agency and the footer
            $full = $admin . '?module=settings&tab=reports';
            $rows[] = ReportVisual::rule() . ReportVisual::row(ReportVisual::button(t('Open the full report'), $full, $p), 0, 24);
            $contacts = array_values(array_filter([(string) preg_replace('#^https?://#', '', $s->get('agency_url')), $s->get('agency_email'), $s->get('agency_phone')]));
            $footerNote = t('Sent once a month by the website %s. The full report to print or download, the recipients and the switch are in Settings → Reports.', $siteName);
            $rows[] = '<tr><td class="kr-pad kr-line" style="padding:18px 24px 24px;border-top:1px solid ' . $p['line'] . ';">'
                . ($agency !== '' && $contacts !== [] ? '<div class="kr-muted" style="' . ReportVisual::font(13, 400, '1.6') . 'color:' . $p['muted'] . ';margin:0 0 6px;"><strong>' . e($agency) . '</strong> · ' . e(implode(' · ', $contacts)) . '</div>' : '')
                . '<div class="kr-muted" style="' . ReportVisual::font(12, 400, '1.6') . 'color:' . $p['muted'] . ';">' . e($footerNote) . '</div></td></tr>';

            // above the card: the agency's logo and name when it is set
            $logo = $s->get('agency_logo');
            $above = $agency === '' ? '' : ($logo !== '' ? '<img src="' . e($siteUrl . '/' . ltrim($logo, '/')) . '" alt="' . e($agency) . '" height="32" style="display:block;height:32px;width:auto;margin:0 0 6px;">' : '')
                . '<div class="kr-muted" style="' . ReportVisual::font(14, 600, '1.4') . 'color:' . $p['muted'] . ';">' . e($agency) . '</div>';

            $document = ReportVisual::document(Language::code(), $subject, $lead['text'], $above, implode('', $rows), $p);
            $title = $agency !== '' ? $agency : $siteName;
            $plain = $title . "\n" . t('Website report – %s', $monthName) . ' · ' . $siteName . ' (' . $siteUrl . ")\n\n" . implode("\n\n", $text) . "\n\n"
                . t('Open the full report') . ': ' . $full . "\n\n"
                . ($agency !== '' && $contacts !== [] ? $agency . ' · ' . implode(' · ', $contacts) . "\n" : '') . $footerNote . "\n";

            return ['subject' => $subject, 'html' => $document, 'text' => $plain];
        }, 'admin-');
    }

    /**
     * The whole e-mail for a month: the data are collected inside the e-mail's language too, so the health texts match.
     *
     * @return array{subject: string, html: string, text: string}
     */
    public static function compose(App $app, \DateTimeImmutable $monthStart): array
    {
        $s = $app->settings();

        return Language::runWith(Language::defaults($s), fn (): array => self::render(self::monthData($app, $monthStart), $s, self::siteUrl($app), self::previousData($app, $monthStart)), 'admin-');
    }

    /**
     * The archived data of the month before (3.26.1: the changes on the headline tiles), null when it is not stored.
     *
     * @return array<string, mixed>|null
     */
    public static function previousData(App $app, \DateTimeImmutable $monthStart): ?array
    {
        $before = $monthStart->modify('first day of last month');
        try {
            // through monthData: an archived month gets what later releases add on reading (3.26.1: its uptime)
            return ReportArchive::get($app->db(), $before->format('Y-m')) !== null ? self::monthData($app, $before) : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The data of a month for the e-mail and its preview: the archived report when the month is stored (3.16,
     * Core\ReportArchive – the job stores it before the e-mail goes), so the e-mail and the printable report say the same;
     * otherwise built in memory. Only the job report_archive and an explicit rebuild write the archive (N316-7) – opening
     * the preview or sending the e-mail never does.
     *
     * @return array<string, mixed>
     */
    public static function monthData(App $app, \DateTimeImmutable $monthStart): array
    {
        try {
            $stored = ReportArchive::get($app->db(), $monthStart->format('Y-m'));
        } catch (\Throwable) {
            $stored = null; // the archive table is not there yet (an update before its migration ran)
        }

        // 3.26.1: a month archived before it gains the uptime key from the days the site keeps (Fleet\Uptime::fill)
        return $stored !== null ? \Kaleta\Fleet\Uptime::fill($app->settings(), $stored['data']) : self::build($app, $monthStart);
    }

    /**
     * Where the report goes: report_recipients (comma or line separated), otherwise the site e-mail.
     *
     * @return list<string>
     */
    public static function recipients(Settings $s): array
    {
        $list = array_values(array_filter(array_unique(preg_split('/[\s,;]+/', $s->get('report_recipients')) ?: []),
            static fn (string $e): bool => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
        if ($list === [] && $s->get('site_email') !== '') {
            $list = [$s->get('site_email')];
        }

        return array_slice($list, 0, self::MAX_RECIPIENTS);
    }

    /** Sends the report for a month to every recipient (Mail::send, with its queue). Returns how many recipients. */
    public static function send(App $app, \DateTimeImmutable $monthStart): int
    {
        $s = $app->settings();
        $recipients = self::recipients($s);
        if ($recipients === []) {
            return 0;
        }
        $mail = self::compose($app, $monthStart);
        foreach ($recipients as $recipient) {
            Mail::send($s, $recipient, $mail['subject'], $mail['text'], $mail['html']);
        }

        return count($recipients);
    }

    /** Sends the report, remembers the month and records the event – the job and the "send now" button share this, so a month never goes twice. */
    public static function sendAndRecord(App $app, \DateTimeImmutable $monthStart): int
    {
        $sent = self::send($app, $monthStart);
        if ($sent > 0) {
            $month = $monthStart->format('Y-m');
            if ($month > $app->settings()->get('report_last_month')) {
                $app->settings()->set('report_last_month', $month); // an older month sent again from the archive (3.16) never makes the job send the last one twice
            }
            Events::record($app->db(), 'report.sent', 'info', t('The monthly report for %s went to %d recipient(s).', $month, $sent), ['month' => $month, 'recipients' => $sent]);
        }

        return $sent;
    }

    /** One run of the job (Core\Scheduler): sends the previous month's report when it is on and not sent yet. */
    public static function runIfDue(App $app): string
    {
        $s = $app->settings();
        if (!$s->bool('report_monthly') || Demo::active()) {
            return 'off';
        }
        $month = self::previousMonth(new \DateTimeImmutable());
        if ($s->get('report_last_month') === $month->format('Y-m')) {
            return 'sent already';
        }
        $sent = self::sendAndRecord($app, $month);

        return $sent === 0 ? 'no address' : 'sent to ' . $sent;
    }

    /** The site address for links and the logo: the setting, not the Host header (cron may call from anywhere). */
    private static function siteUrl(App $app): string
    {
        return rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/') . rtrim($app->url(''), '/');
    }
}
