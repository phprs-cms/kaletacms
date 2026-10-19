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
 * A section is null when its feature is off; scrub() keeps every "@" out. The e-mail of a month is written from the
 * archived data (monthData). A summary of what happened – never hours, a work log or anything about money.
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
            ];
        }
        $signups = Extensions::isEnabled($s, 'newsletter') ? $count('SELECT COUNT(*) FROM {odberatele} WHERE stav = 1 AND datum >= ? AND datum < ?', [$from, $to]) : null;

        $updates = array_map(function (array $e): array {
            $info = json_decode((string) $e['data'], true); // Updater records the version it went to and the one it came from
            $info = is_array($info) ? $info : [];

            return ['type' => (string) $e['type'], 'date' => (string) $e['created_at'], 'message' => (string) $e['message'], 'from' => (string) ($info['from'] ?? ''), 'to' => (string) ($info['version'] ?? '')];
        }, $db->all('SELECT type, created_at, message, data FROM {events} WHERE type IN (?, ?, ?) AND created_at >= ? AND created_at < ? ORDER BY id', [...self::UPDATE_EVENTS, $from, $to]));
        $lastBackup = Backup::listAll()[0]['cas'] ?? null;
        $backups = [
            'created' => $count('SELECT COUNT(*) FROM {events} WHERE type = ? AND created_at >= ? AND created_at < ?', ['backup.created', $from, $to]),
            'failed' => $count('SELECT COUNT(*) FROM {events} WHERE type = ? AND created_at >= ? AND created_at < ?', ['backup.failed', $from, $to]),
            'last' => $lastBackup === null ? null : date('Y-m-d H:i:s', $lastBackup),
        ];
        // the change log names the Claude connection a change came through (ChangeLog::write); empty = a person in the admin
        $changes = [
            'people' => $count("SELECT COUNT(*) FROM {protokol} WHERE cas >= ? AND cas < ? AND via = ''", [$from, $to]),
            'claude' => $count("SELECT COUNT(*) FROM {protokol} WHERE cas >= ? AND cas < ? AND via <> ''", [$from, $to]),
        ];
        $problems = [];
        $errors = 0;
        foreach (Health::checks($app) as $check) {
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
        $remote = $s->get('remote_backup');
        $remoteStatus = explode('|', $s->get('remote_backup_status'), 2);
        $backups += ['offsite' => in_array($remote, ['ftp', 's3'], true) ? $remote : '', 'offsite_last' => $remote !== 'vypnuto' && ($remoteStatus[1] ?? '') === 'ok' ? $remoteStatus[0] : null,
            'offsite_failing' => $remote !== 'vypnuto' && $remote !== '' && isset($remoteStatus[1]) && $remoteStatus[1] !== 'ok'];

        return self::scrub([
            'schema' => ReportArchive::SCHEMA,
            'month' => $monthStart->format('Y-m'),
            'generated_at' => Clock::now(),
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
                'sign_ins' => $count("SELECT COUNT(*) FROM {protokol} WHERE modul = 'prihlaseni' AND akce = 'login' AND cas >= ? AND cas < ?", [$from, $to]),
                'failed_sign_ins' => $count("SELECT COUNT(*) FROM {protokol} WHERE modul = 'prihlaseni' AND akce = 'neuspech' AND cas >= ? AND cas < ?", [$from, $to]),
                'events' => $events(self::SECURITY_EVENTS)]),
            'audit' => $section(fn (): array => self::audit($app, $monthStart)),
            'bookings' => Extensions::isEnabled($s, 'bookings') ? $section(fn (): array => ['total' => $count('SELECT COUNT(*) FROM {bookings} WHERE created_at >= ? AND created_at < ?', [$from, $to]),
                'statuses' => array_map('intval', $db->pairs('SELECT status, COUNT(*) FROM {bookings} WHERE created_at >= ? AND created_at < ? GROUP BY status', [$from, $to])),
                'appointments' => $count("SELECT COUNT(*) FROM {bookings} WHERE starts_at >= ? AND starts_at < ? AND status IN ('confirmed', 'done', 'no_show')", [$from, $to])]) : null,
            'newsletter' => Extensions::isEnabled($s, 'newsletter') ? $section(fn (): array => ['signups' => (int) $signups,
                'campaigns' => array_map(static fn (array $r): array => ['subject' => (string) $r['subject'], 'date' => (string) $r['finished_at'], 'sent' => (int) $r['sent_count']],
                    $db->all("SELECT subject, finished_at, sent_count FROM {newsletters} WHERE status = 'sent' AND finished_at >= ? AND finished_at < ? ORDER BY finished_at LIMIT 20", [$from, $to]))]) : null,
            'open' => $section(fn (): array => self::open($app, $enquiries)),
        ]);
    }

    /** Security events the report counts (3.16) – the types only, never an address or an account. */
    private const array SECURITY_EVENTS = ['firewall.blocked', 'security.account_suspended', 'security.connection_revoked', 'security.token_reuse'];

    /** At most this many titles in one list of the archived report (requests, pages, news, runs). */
    public const int LIST_LIMIT = 20;

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
        $row = static fn (array $r): array => ['title' => (string) $r['title'], 'status' => (string) $r['status'], 'created' => (string) $r['created_at'], 'done' => (string) ($r['done_at'] ?? '')];

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
        foreach ($db->all("SELECT modul, akce, via <> '' AS claude, COUNT(*) AS n FROM {protokol} WHERE cas >= ? AND cas < ? AND modul <> 'prihlaseni'
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
                $pages[(int) $info['ids']] = ['date' => (string) $r['created_at'], 'claude' => !empty($info['claude'])];
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
            'news_published' => array_map(static fn (array $r): array => ['title' => (string) $r['titulek'], 'date' => (string) $r['datum']],
                $db->all('SELECT titulek, datum FROM {novinky} WHERE visible = 1 AND smazano IS NULL AND datum >= ? AND datum < ? ORDER BY datum LIMIT ' . self::LIST_LIMIT, [$from, $to])),
            'news_published_count' => (int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND smazano IS NULL AND datum >= ? AND datum < ?', [$from, $to]),
            'look_published' => (int) $db->value("SELECT COUNT(*) FROM {events} WHERE type = 'look.published' AND created_at >= ? AND created_at < ?", [$from, $to]),
        ];
    }

    /**
     * Claude sessions (the undo journal, 2.17) and scheduled runs (2.17): counts, and each run with its task, result and summary.
     *
     * @return array<string, mixed>
     */
    private static function claude(Db $db, string $from, string $to): array
    {
        $sessions = $db->one('SELECT COUNT(*) AS n, COALESCE(SUM(calls), 0) AS calls, COALESCE(SUM(undone_at IS NOT NULL), 0) AS undone FROM {agent_sessions} WHERE started_at >= ? AND started_at < ?', [$from, $to]) ?? [];
        $statuses = array_map('intval', $db->pairs('SELECT status, COUNT(*) FROM {agent_runs} WHERE due_at >= ? AND due_at < ? GROUP BY status', [$from, $to]));

        return [
            'sessions' => (int) ($sessions['n'] ?? 0), 'calls' => (int) ($sessions['calls'] ?? 0), 'undone' => (int) ($sessions['undone'] ?? 0),
            'runs' => array_sum($statuses), 'run_statuses' => $statuses,
            'run_list' => array_map(static fn (array $r): array => ['name' => (string) $r['name'], 'task' => (string) $r['task'], 'date' => (string) ($r['finished_at'] ?? $r['due_at']),
                'status' => (string) $r['status'], 'summary' => mb_substr(trim((string) ($r['summary'] ?? '')), 0, 300)],
                $db->all('SELECT s.name, s.task, r.due_at, r.finished_at, r.status, r.summary FROM {agent_runs} r JOIN {agent_schedules} s ON s.id = r.schedule_id WHERE r.due_at >= ? AND r.due_at < ? ORDER BY r.due_at DESC LIMIT ' . self::LIST_LIMIT, [$from, $to])),
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
     * Nothing personal leaves the report (3.16): every string of the data – titles written by staff, summaries written by
     * Claude, search queries, form names – loses what looks like an e-mail address, and any other word with "@" (a
     * handle). The archive, the print page and get_monthly_report all read the scrubbed data.
     *
     * @param array<mixed> $data
     * @return array<mixed>
     */
    public static function scrub(array $data): array
    {
        array_walk_recursive($data, static function (mixed &$value): void {
            if (is_string($value) && str_contains($value, '@')) {
                $value = (string) preg_replace('/[^\s@<>"]+@[^\s@<>"]+\.[a-z]{2,}/i', '…', $value);
                $value = (string) preg_replace('/\S*@\S*/u', '…', $value);
            }
        });

        return $data;
    }

    /**
     * The e-mail: inline-styled tables (Outlook and Gmail ignore most CSS) at most 600 px wide, plus a plain-text twin. Written in
     * the site's default language with the admin dictionary – the owner reads it, not a visitor.
     *
     * @param array<string, mixed> $data from build()
     * @return array{subject: string, html: string, text: string}
     */
    public static function render(array $data, Settings $s, string $siteUrl): array
    {
        return Language::runWith(Language::defaults($s), function () use ($data, $s, $siteUrl): array {
            $siteUrl = rtrim($siteUrl, '/');
            $siteName = $s->get('site_name');
            $agency = $s->get('agency_name');
            $monthName = t(self::MONTHS[(int) substr((string) $data['month'], 5, 2)] ?? 'January') . ' ' . substr((string) $data['month'], 0, 4);
            $subject = t('Website report – %s – %s', $monthName, $siteName);
            // nothing that looks like an e-mail address gets through from the data – a form name or a message could carry one
            $mask = static fn (string $text): string => (string) preg_replace('/[^\s@<>"]+@[^\s@<>"]+\.[a-z]{2,}/i', '…', $text);
            $safe = static fn (string $text): string => e($mask($text));
            $n = static fn (int $value): string => format_number($value, 0);
            $admin = $siteUrl . '/admin.php';

            $html = [];
            $text = [];
            $section = function (string $title, string $body, string $plain) use (&$html, &$text): void {
                $html[] = '<tr><td style="padding:20px 28px 4px;font:600 15px/1.3 Arial,Helvetica,sans-serif;color:#121212;">' . e($title) . '</td></tr><tr><td style="padding:0 28px 12px;font:14px/1.5 Arial,Helvetica,sans-serif;color:#333;">' . $body . '</td></tr>';
                $text[] = mb_strtoupper($title) . "\n" . $plain;
            };
            $row = static fn (string $label, string $value): string => '<tr><td style="padding:4px 0;border-bottom:1px solid #eee;">' . $label . '</td><td style="padding:4px 0 4px 12px;border-bottom:1px solid #eee;text-align:right;white-space:nowrap;">' . $value . '</td></tr>';
            $table = static fn (array $rows): string => $rows === [] ? '' : '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="font:14px/1.5 Arial,Helvetica,sans-serif;color:#333;">' . implode('', $rows) . '</table>';
            $plainRows = static fn (array $pairs): string => implode("\n", array_map(static fn (array $p): string => '  ' . $mask($p[0]) . ': ' . $mask($p[1]), $pairs));
            $change = static function (int $now, int $before) use ($n): string {
                if ($before === 0) {
                    return $n($now);
                }
                $percent = (int) round(100 * ($now - $before) / $before);

                return $n($now) . ' (' . ($percent >= 0 ? '+' : '−') . abs($percent) . ' %)';
            };

            // needs your decision – first, so the owner sees it before the numbers
            $decisions = (array) ($data['decisions'] ?? []);
            $pending = [];
            if (($decisions['enquiries'] ?? 0) > 0) {
                $pending[] = [t('%d enquiry(ies) nobody has opened yet', (int) $decisions['enquiries']), $admin . '?module=enquiries'];
            }
            if (($decisions['errors'] ?? 0) > 0) {
                $pending[] = [t('%d problem(s) in System status need fixing', (int) $decisions['errors']), $admin . '?module=status'];
            }
            if ($pending !== []) {
                $items = array_map(static fn (array $p): string => '<li style="margin:0 0 4px;"><a href="' . e($p[1]) . '" style="color:#121212;">' . e($p[0]) . '</a></li>', $pending);
                $section(t('Needs your decision'), '<ul style="margin:0;padding:0 0 0 18px;">' . implode('', $items) . '</ul>', implode("\n", array_map(static fn (array $p): string => '  – ' . $p[0] . ' – ' . $p[1], $pending)));
            }

            // traffic
            $stats = $data['stats'] ?? null;
            if (is_array($stats)) {
                $rows = [$row(e(t('Visits')), e($change((int) $stats['visits'], (int) $stats['previous_visits']))), $row(e(t('Page views')), e($change((int) $stats['views'], (int) $stats['previous_views'])))];
                $pairs = [[t('Visits'), $change((int) $stats['visits'], (int) $stats['previous_visits'])], [t('Page views'), $change((int) $stats['views'], (int) $stats['previous_views'])]];
                $list = function (string $title, array $items, string $key) use (&$rows, &$pairs, $row, $safe, $n): void {
                    if ($items === []) {
                        return;
                    }
                    $rows[] = '<tr><td colspan="2" style="padding:12px 0 2px;font-weight:600;">' . e($title) . '</td></tr>';
                    $pairs[] = [$title, ''];
                    foreach ($items as $item) {
                        $rows[] = $row($safe((string) $item[$key]), $n((int) $item['n']));
                        $pairs[] = ['  ' . (string) $item[$key], $n((int) $item['n'])];
                    }
                };
                $list(t('Most read pages'), (array) $stats['pages'], 'path');
                $list(t('Visitors came from'), (array) $stats['sources'], 'site');
                $list(t('Campaigns'), (array) $stats['campaigns'], 'campaign');
                $section(t('Traffic'), $table($rows) . '<p style="margin:8px 0 0;font-size:12px;color:#777;">' . e(t('Compared with the month before.')) . '</p>', $plainRows($pairs));
            } else {
                $section(t('Traffic'), '<p style="margin:0;">' . e(t('The built-in statistics are off (Settings → Analytics), so there are no traffic figures.')) . '</p>', '  ' . t('The built-in statistics are off (Settings → Analytics), so there are no traffic figures.'));
            }

            // searches that brought visitors – only when an engine is connected and had data this month
            $search = (array) ($data['search'] ?? []);
            if ($search !== []) {
                $rows = [];
                $pairs = [];
                foreach ($search as $q) {
                    $label = t('%s on %s', (string) $q['query'], $q['engine'] === 'bing' ? 'Bing' : 'Google');
                    $rows[] = $row($safe($label), e(t('%s clicks', $n((int) $q['clicks']))));
                    $pairs[] = [$label, t('%s clicks', $n((int) $q['clicks']))];
                }
                $section(t('Searches that brought visitors'), $table($rows), $plainRows($pairs));
            }

            // leads – the contact clicks (calls, e-mails, WhatsApp) join them when there were any
            $enquiries = $data['enquiries'] ?? null;
            $signups = $data['signups'] ?? null;
            $clicks = (array) ($stats['contact_clicks'] ?? []);
            if (is_array($enquiries) || $signups !== null || Conversions::total($clicks) > 0) {
                $rows = [];
                $pairs = [];
                if (is_array($enquiries)) {
                    $rows[] = $row(e(t('Enquiries received')), $n((int) $enquiries['total']));
                    $pairs[] = [t('Enquiries received'), $n((int) $enquiries['total'])];
                    foreach ((array) $enquiries['forms'] as $f) {
                        $rows[] = $row('&nbsp;&nbsp;' . $safe((string) $f['form']), $n((int) $f['n']));
                        $pairs[] = ['  ' . (string) $f['form'], $n((int) $f['n'])];
                    }
                    foreach ((array) $enquiries['pages'] as $p) {
                        $rows[] = $row('&nbsp;&nbsp;' . t('from page %s', $safe((string) $p['path'])), $n((int) $p['n']));
                        $pairs[] = ['  ' . t('from page %s', (string) $p['path']), $n((int) $p['n'])];
                    }
                }
                if ($signups !== null) {
                    $rows[] = $row(e(t('Newsletter sign-ups')), $n((int) $signups));
                    $pairs[] = [t('Newsletter sign-ups'), $n((int) $signups)];
                }
                foreach (['calls' => 'Calls – clicks on a phone number', 'emails' => 'E-mails – clicks on an e-mail address', 'whatsapp' => 'WhatsApp – clicks on a WhatsApp link'] as $key => $label) {
                    if ((int) ($clicks[$key] ?? 0) > 0) {
                        $rows[] = $row(e(t($label)), $n((int) $clicks[$key]));
                        $pairs[] = [t($label), $n((int) $clicks[$key])];
                    }
                }
                $section(t('Enquiries and sign-ups'), $table($rows), $plainRows($pairs));
            }

            // 3.16: the work on the site – requests handled, what was published, Claude's sessions and scheduled runs; the
            // whole story is the printable report in Settings → Reports
            $work = (array) ($data['work'] ?? []);
            $requests = $data['requests'] ?? null;
            $claude = $data['claude'] ?? null;
            $rows = [];
            $pairs = [];
            $add = function (string $label, int $value) use (&$rows, &$pairs, $row, $n): void {
                $rows[] = $row(e($label), $n($value));
                $pairs[] = [$label, $n($value)];
            };
            if (is_array($requests)) {
                $add(t('Requests handled'), (int) $requests['done_count']);
                $add(t('Requests still open'), (int) $requests['open_count']);
            }
            if ($work !== []) {
                $add(t('Pages published'), (int) ($work['pages_published_count'] ?? 0));
                $add(t('News published'), (int) ($work['news_published_count'] ?? 0));
            }
            if (is_array($claude)) {
                $add(t('Claude sessions'), (int) $claude['sessions']);
                $add(t('Scheduled Claude runs'), (int) $claude['runs']);
            }
            if ($rows !== []) {
                $section(t('Work on the site'), $table($rows), $plainRows($pairs));
            }

            // care of the site: updates, backups, changes
            $rows = [];
            $pairs = [];
            $updates = (array) ($data['updates'] ?? []);
            if ($updates === []) {
                $rows[] = $row(e(t('Updates')), e(t('none this month')));
                $pairs[] = [t('Updates'), t('none this month')];
            }
            foreach ($updates as $u) {
                $rows[] = $row(e(format_date((string) $u['date'])) . ' · ' . $safe((string) $u['message']), '');
                $pairs[] = [format_date((string) $u['date']), (string) $u['message']];
            }
            if (isset($data['version'])) {
                $channel = t(['stable' => 'Stable channel', 'custom' => 'custom update source'][(string) ($data['update_channel'] ?? '')] ?? 'Latest channel');
                $rows[] = $row(e(t('Kaleta version')), e((string) $data['version'] . ' · ' . $channel));
                $pairs[] = [t('Kaleta version'), (string) $data['version'] . ' · ' . $channel];
            }
            $backups = (array) ($data['backups'] ?? []);
            $backupText = t('%d made, %d failed', (int) ($backups['created'] ?? 0), (int) ($backups['failed'] ?? 0))
                . (($backups['last'] ?? null) !== null ? ', ' . t('the last one %s', format_date((string) $backups['last'])) : '');
            $rows[] = $row(e(t('Backups')), e($backupText));
            $pairs[] = [t('Backups'), $backupText];
            $changes = (array) ($data['changes'] ?? []);
            $changesText = t('%d by people, %d by Claude', (int) ($changes['people'] ?? 0), (int) ($changes['claude'] ?? 0));
            $rows[] = $row(e(t('Changes in the administration')), e($changesText));
            $pairs[] = [t('Changes in the administration'), $changesText];
            $security = $data['security'] ?? null;
            if (is_array($security)) {
                $securityText = t('%d blocked requests, %d failed sign-ins', array_sum(array_map('intval', (array) ($security['firewall'] ?? []))), (int) ($security['failed_sign_ins'] ?? 0));
                $rows[] = $row(e(t('Security')), e($securityText));
                $pairs[] = [t('Security'), $securityText];
            }
            $section(t('Care of the site'), $table($rows), $plainRows($pairs));

            // problems right now
            $problems = (array) ($data['problems'] ?? []);
            if ($problems === []) {
                $section(t('Problems right now'), '<p style="margin:0;">' . e(t('None – every check in System status is fine.')) . '</p>', '  ' . t('None – every check in System status is fine.'));
            } else {
                $items = array_map(static fn (array $p): string => '<li style="margin:0 0 4px;">' . ($p['state'] === 'chyba' ? '<strong>' . $safe((string) $p['name']) . '</strong>' : $safe((string) $p['name'])) . ' – ' . $safe((string) $p['info']) . '</li>', $problems);
                $section(t('Problems right now'), '<ul style="margin:0;padding:0 0 0 18px;">' . implode('', $items) . '</ul><p style="margin:8px 0 0;font-size:12px;"><a href="' . e($admin . '?module=status') . '" style="color:#121212;">' . e(t('Details are in System status')) . '</a></p>',
                    implode("\n", array_map(static fn (array $p): string => '  – ' . $mask((string) $p['name']) . ' – ' . $mask((string) $p['info']), $problems)) . "\n  " . $admin . '?module=status');
            }

            // header and footer: the agency when it is set, otherwise the site
            $logo = $s->get('agency_logo');
            $title = $agency !== '' ? $agency : $siteName;
            $header = '<tr><td style="padding:24px 28px 8px;">'
                . ($agency !== '' && $logo !== '' ? '<img src="' . e($siteUrl . '/' . ltrim($logo, '/')) . '" alt="" height="32" style="display:block;height:32px;margin:0 0 12px;">' : '')
                . '<div style="font:600 18px/1.3 Arial,Helvetica,sans-serif;color:#121212;">' . e($title) . '</div>'
                . '<div style="font:14px/1.5 Arial,Helvetica,sans-serif;color:#555;margin-top:4px;">' . e(t('Website report – %s', $monthName)) . ' · <a href="' . e($siteUrl) . '" style="color:#555;">' . e($siteName) . '</a></div></td></tr>';
            $contacts = array_filter([
                $s->get('agency_url') !== '' ? '<a href="' . e($s->get('agency_url')) . '" style="color:#555;">' . e(preg_replace('#^https?://#', '', $s->get('agency_url')) ?? '') . '</a>' : '',
                $s->get('agency_email') !== '' ? '<a href="mailto:' . e($s->get('agency_email')) . '" style="color:#555;">' . e($s->get('agency_email')) . '</a>' : '',
                $s->get('agency_phone') !== '' ? e($s->get('agency_phone')) : '',
            ]);
            $plainContacts = array_filter([$s->get('agency_url'), $s->get('agency_email'), $s->get('agency_phone')]);
            $footerNote = t('Sent once a month by the website %s. The full report to print or download, the recipients and the switch are in Settings → Reports.', $siteName);
            $footer = '<tr><td style="padding:20px 28px 24px;border-top:1px solid #eee;font:12px/1.6 Arial,Helvetica,sans-serif;color:#777;">'
                . ($agency !== '' && $contacts !== [] ? '<div style="margin:0 0 6px;color:#555;">' . e($agency) . ' · ' . implode(' · ', $contacts) . '</div>' : '')
                . e($footerNote) . '</td></tr>';

            $document = '<!DOCTYPE html><html lang="' . e(Language::tag(Language::code())) . '"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>' . e($subject) . '</title></head>'
                . '<body style="margin:0;padding:0;background:#f6f4ee;">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background:#f6f4ee;"><tr><td align="center" style="padding:24px 12px;">'
                . '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="600" style="width:100%;max-width:600px;background:#ffffff;border-radius:8px;">'
                . $header . implode('', $html) . $footer
                . '</table></td></tr></table></body></html>';
            $plain = $title . "\n" . t('Website report – %s', $monthName) . ' · ' . $siteName . ' (' . $siteUrl . ")\n\n" . implode("\n\n", $text) . "\n\n"
                . ($agency !== '' && $plainContacts !== [] ? $agency . ' · ' . implode(' · ', $plainContacts) . "\n" : '') . $footerNote . "\n";

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

        return Language::runWith(Language::defaults($s), fn (): array => self::render(self::monthData($app, $monthStart), $s, self::siteUrl($app)), 'admin-');
    }

    /**
     * The data of a month for the e-mail: the archived report (3.16, Core\ReportArchive – stored first when it is missing),
     * so the e-mail and the printable report say the same; built on the spot when the archive cannot be used.
     *
     * @return array<string, mixed>
     */
    public static function monthData(App $app, \DateTimeImmutable $monthStart): array
    {
        try {
            return ReportArchive::data($app, $monthStart);
        } catch (\Throwable) {
            return self::build($app, $monthStart);
        }
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
