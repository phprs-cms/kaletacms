<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The team summary (3.41, step 7 of the CRM path): how much of the team's work happened in a period – counts only, never
 * hours, money or the values of deals, never texts or customers. My work → Summary in the administration, MCP team_summary
 * and (team totals only, no names) the monthly report.
 *
 * Who sees what (owner decision 9): the per-person rows only an administrator, on the screen and through MCP; everyone
 * else their own row and the team's totals. A section is counted only when the viewer may open it (enquiries, the
 * pipeline, applications, quotes – tasks always); the monthly report counts what the site has switched on. The per-person
 * rows never leave the administrator's screen and MCP: not in the heartbeat, the fleet console, exports or the report.
 *
 * The counts, by user id (0 = nobody responsible):
 *  - enquiries_open – open enquiries (new or read, spam left out) each person handles now;
 *  - enquiries_answered – enquiries each person replied to in the period (the change log of EnquiryReply, kept 180 days);
 *  - tasks_open, tasks_overdue – their open tasks now; tasks_done – tasks they completed in the period;
 *  - deals_moved – moves of a deal between stages they made in the period; deals_won, deals_lost – deals closed in the
 *    period, counted for the responsible person;
 *  - applications_handled – job applications whose status they changed in the period;
 *  - quotes_sent – quotes they sent in the period; quotes_accepted – quotes accepted in the period, for the responsible person.
 */
final class TeamSummary
{
    /** The periods => label; the query parameter "period". */
    public const array PERIODS = ['7_days' => 'Last 7 days', '30_days' => 'Last 30 days', 'this_month' => 'This month', 'last_month' => 'Last month', '90_days' => 'Last 90 days'];

    public const string DEFAULT_PERIOD = '30_days';

    /** The sections and their counts, in the order of the screen. */
    public const array COUNTS = [
        'enquiries' => ['enquiries_open', 'enquiries_answered'],
        'tasks' => ['tasks_open', 'tasks_overdue', 'tasks_done'],
        'deals' => ['deals_moved', 'deals_won', 'deals_lost'],
        'applications' => ['applications_handled'],
        'quotes' => ['quotes_sent', 'quotes_accepted'],
    ];

    /** Each count => its label (the screen, the monthly report). */
    public const array LABELS = [
        'enquiries_open' => 'Open enquiries', 'enquiries_answered' => 'Enquiries answered',
        'tasks_open' => 'Open tasks', 'tasks_overdue' => 'Overdue tasks', 'tasks_done' => 'Tasks done',
        'deals_moved' => 'Deal moves', 'deals_won' => 'Deals won', 'deals_lost' => 'Deals lost',
        'applications_handled' => 'Applications handled',
        'quotes_sent' => 'Quotes sent', 'quotes_accepted' => 'Quotes accepted',
    ];

    /** Counts of the moment, not of the period – the monthly report leaves them out. */
    public const array NOW = ['enquiries_open', 'tasks_open', 'tasks_overdue'];

    /** The section a viewer must be able to open (tasks: everyone). */
    private const array SECTION_OF = ['enquiries' => 'enquiries', 'deals' => Deals::SECTION, 'applications' => Applications::SECTION, 'quotes' => Quotes::SECTION];

    /** The condition of Admin\Modules\Enquiries::NOT_SPAM (Core never names Admin – docs/design/code-layers.md; a unit test keeps them equal). */
    public const string NOT_SPAM = "kategorie <> 'spam'";

    /** The most job applications read for their status changes in one summary. */
    public const int APPLICATION_ROWS = 5000;

    /** A known period, otherwise the default. Pure. */
    public static function period(mixed $value): string
    {
        return is_string($value) && isset(self::PERIODS[$value]) ? $value : self::DEFAULT_PERIOD;
    }

    /**
     * The first moment of a period and the moment after it, on the site's clock: the last N days end now; this month runs
     * from its first day until now, last month is the whole calendar month before.
     *
     * @return array{0: string, 1: string}
     */
    public static function range(string $period): array
    {
        $now = Clock::now();
        $today = Clock::today();

        return match (self::period($period)) {
            '7_days' => [Clock::day('-6 days') . ' 00:00:00', $now],
            'this_month' => [substr($today, 0, 8) . '01 00:00:00', $now],
            'last_month' => [(new \DateTimeImmutable(substr($today, 0, 8) . '01'))->modify('-1 month')->format('Y-m-d') . ' 00:00:00', substr($today, 0, 8) . '01 00:00:00'],
            '90_days' => [Clock::day('-89 days') . ' 00:00:00', $now],
            default => [Clock::day('-29 days') . ' 00:00:00', $now],
        };
    }

    /**
     * The sections a person with these admin sections sees counted (module ident => class, Admin\Kernel::modules – a
     * section is there only with its feature on). Pure.
     *
     * @param array<string, mixed> $modules
     * @return list<string>
     */
    public static function sectionsFor(array $modules): array
    {
        return array_values(array_filter(array_keys(self::COUNTS), fn (string $section): bool => !isset(self::SECTION_OF[$section]) || isset($modules[self::SECTION_OF[$section]])));
    }

    /**
     * The sections the site has switched on (the monthly report, which no person reads with their own sections): enquiries
     * and applications with their extension, quotes with theirs, deals when the site has any, tasks always.
     *
     * @return list<string>
     */
    public static function sectionsOfSite(App $app): array
    {
        $s = $app->settings();
        $hasDeals = false;
        try {
            $hasDeals = $app->db()->value('SELECT 1 FROM {deals} LIMIT 1') !== null;
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
        }
        $on = ['enquiries' => Extensions::isEnabled($s, 'poptavky'), 'tasks' => true, 'deals' => $hasDeals, 'applications' => Applications::isOn($s), 'quotes' => Quotes::isOn($s)];

        return array_keys(array_filter($on));
    }

    /**
     * The counts of the given sections in a period, per user id (0 = nobody responsible) – only people with something
     * counted. A section whose table is not there yet (before its migration) counts nothing.
     *
     * @param list<string> $sections
     * @return array<int, array<string, int>>
     */
    public static function collect(App $app, array $sections, string $from, string $to): array
    {
        $db = $app->db();
        $out = [];
        $add = function (string $count, array $pairs) use (&$out): void {
            foreach ($pairs as $who => $n) {
                if ((int) $n > 0) {
                    $out[max(0, (int) $who)][$count] = ($out[max(0, (int) $who)][$count] ?? 0) + (int) $n;
                }
            }
        };
        $pairs = function (string $sql, array $params) use ($db): array {
            try {
                return $db->pairs($sql, $params);
            } catch (\PDOException $e) {
                Db::rethrowUnlessMissing($e);
                return [];
            }
        };
        $today = Clock::today() . ' 00:00:00';
        if (in_array('enquiries', $sections, true)) {
            $add('enquiries_open', $pairs('SELECT COALESCE(prirazeno, 0), COUNT(*) FROM {poptavky} WHERE stav < 2 AND ' . self::NOT_SPAM . ' GROUP BY COALESCE(prirazeno, 0)', []));
            // one reply action per enquiry and person (the change log writes "#<id>", "#<id> (queued)" when the mail waits)
            $add('enquiries_answered', $pairs("SELECT kdo, COUNT(DISTINCT SUBSTRING_INDEX(popis, ' ', 1)) FROM {protokol} WHERE modul = 'enquiries' AND akce = 'reply' AND kdo IS NOT NULL"
                . ' AND cas >= ? AND cas < ? GROUP BY kdo', [$from, $to]));
        }
        if (in_array('tasks', $sections, true)) {
            $add('tasks_open', $pairs('SELECT COALESCE(assignee_id, 0), COUNT(*) FROM {tasks} WHERE done_at IS NULL GROUP BY COALESCE(assignee_id, 0)', []));
            $add('tasks_overdue', $pairs('SELECT COALESCE(assignee_id, 0), COUNT(*) FROM {tasks} WHERE done_at IS NULL AND due_at < ? GROUP BY COALESCE(assignee_id, 0)', [$today]));
            $add('tasks_done', $pairs('SELECT COALESCE(done_by, 0), COUNT(*) FROM {tasks} WHERE done_at >= ? AND done_at < ? GROUP BY COALESCE(done_by, 0)', [$from, $to]));
        }
        if (in_array('deals', $sections, true)) {
            $add('deals_moved', $pairs('SELECT COALESCE(user_id, 0), COUNT(*) FROM {deal_stage_history} WHERE from_stage IS NOT NULL AND at >= ? AND at < ? GROUP BY COALESCE(user_id, 0)', [$from, $to]));
            $kinds = array_column(Deals::stages($app->settings()), 'kind', 'key');
            foreach (['won' => 'deals_won', 'lost' => 'deals_lost'] as $kind => $count) {
                $keys = array_keys(array_filter($kinds, fn (string $k): bool => $k === $kind));
                if ($keys !== []) {
                    $add($count, $pairs('SELECT COALESCE(owner_id, 0), COUNT(*) FROM {deals} WHERE closed_at >= ? AND closed_at < ? AND stage IN (' . implode(',', array_fill(0, count($keys), '?')) . ')'
                        . ' GROUP BY COALESCE(owner_id, 0)', [$from, $to, ...$keys]));
                }
            }
        }
        if (in_array('applications', $sections, true)) {
            $add('applications_handled', self::applicationsHandled($db, $from, $to));
        }
        if (in_array('quotes', $sections, true)) {
            $quotes = QuoteDelivery::countsByPerson($db, $from, $to); // the quote tables are named only by the quote classes (unit 2800)
            $add('quotes_sent', $quotes['sent']);
            $add('quotes_accepted', $quotes['accepted']);
        }
        ksort($out);

        return $out;
    }

    /**
     * The team's totals of the given sections: every count of every section, 0 when nothing happened. Pure.
     *
     * @param array<int, array<string, int>> $people
     * @param list<string> $sections
     * @return array<string, int>
     */
    public static function totals(array $people, array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            foreach (self::COUNTS[$section] ?? [] as $count) {
                $out[$count] = array_sum(array_column($people, $count));
            }
        }

        return $out;
    }

    /**
     * One person's counts of the given sections, 0 when nothing happened. Pure.
     *
     * @param array<string, int> $counts
     * @param list<string> $sections
     * @return array<string, int>
     */
    public static function row(array $counts, array $sections): array
    {
        $out = [];
        foreach ($sections as $section) {
            foreach (self::COUNTS[$section] ?? [] as $count) {
                $out[$count] = (int) ($counts[$count] ?? 0);
            }
        }

        return $out;
    }

    /**
     * The summary a viewer sees: the period, its sections, the team's totals, the viewer's own counts and – only for an
     * administrator ($perPerson) – everyone's, by name (removed or blocked accounts as Assignment labels them; "nobody"
     * for work nobody is responsible for), the busiest first.
     *
     * @param list<string> $sections
     * @return array{period: string, from: string, to: string, sections: list<string>, team: array<string, int>, you: array<string, int>, people: list<array{id: int, name: string, counts: array<string, int>}>|null}
     */
    public static function build(App $app, array $sections, string $period, int $viewer, bool $perPerson, bool $translate = true): array
    {
        $period = self::period($period);
        [$from, $to] = self::range($period);
        $collected = self::collect($app, $sections, $from, $to);
        $people = null;
        if ($perPerson) {
            $people = [];
            foreach ($collected as $id => $counts) {
                $people[] = ['id' => $id, 'name' => $id > 0 ? Assignment::label($app->db(), $id, $translate) : ($translate ? t('Nobody responsible') : 'Nobody responsible'),
                    'counts' => self::row($counts, $sections)];
            }
            usort($people, fn (array $a, array $b): int => [$a['id'] === 0, -array_sum($a['counts']), $a['name']] <=> [$b['id'] === 0, -array_sum($b['counts']), $b['name']]);
        }

        return ['period' => $period, 'from' => $from, 'to' => $to, 'sections' => $sections, 'team' => self::totals($collected, $sections),
            'you' => self::row($collected[$viewer] ?? [], $sections), 'people' => $people];
    }

    /**
     * A summary as MCP team_summary returns it: the period, the team's totals, the user's own counts and – for an
     * administrator – each person (user id, null = nobody responsible; the name; only the counts above 0). Pure.
     *
     * @param array{period: string, from: string, to: string, sections: list<string>, team: array<string, int>, you: array<string, int>, people: list<array{id: int, name: string, counts: array<string, int>}>|null} $summary
     * @return array<string, mixed>
     */
    public static function compact(array $summary): array
    {
        $out = ['period' => $summary['period'], 'from' => $summary['from'], 'to' => $summary['to'], 'team' => $summary['team'], 'you' => $summary['you']];
        if ($summary['people'] !== null) {
            $out['people'] = array_map(fn (array $p): array => ['user' => $p['id'] > 0 ? $p['id'] : null, 'name' => $p['name']] + array_filter($p['counts']), $summary['people']);
        }

        return $out;
    }

    /**
     * The team block of the monthly report (MonthlyReport::build): the totals of the counts of the period for what the site
     * has switched on, and how many people did something counted – never a name, never a person's own numbers (the report
     * may be forwarded). Null when nobody did anything counted in the month.
     *
     * @return array<string, int>|null
     */
    public static function monthTotals(App $app, string $from, string $to): ?array
    {
        $sections = self::sectionsOfSite($app);
        $collected = self::collect($app, $sections, $from, $to);
        $counted = array_flip(array_diff(array_merge(...array_values(array_intersect_key(self::COUNTS, array_flip($sections)))), self::NOW));
        $people = count(array_filter($collected, fn (array $c, int $id): bool => $id > 0 && array_intersect_key($c, $counted) !== [], ARRAY_FILTER_USE_BOTH));
        if ($people === 0) {
            return null;
        }

        return ['people' => $people] + array_intersect_key(self::totals($collected, $sections), $counted);
    }

    /**
     * The lines of the team block of the report (the e-mail and the page): how many people, then each stored count with its
     * label, in the order of COUNTS. Pure (with the translation).
     *
     * @param array<string, mixed> $team the stored block (monthTotals)
     * @param callable(mixed): string $number
     * @return list<array{0: string, 1: string}>
     */
    public static function reportPairs(array $team, callable $number): array
    {
        $pairs = [[t('People who did counted work'), $number($team['people'] ?? 0)]];
        foreach (self::LABELS as $count => $label) {
            if (array_key_exists($count, $team) && !in_array($count, self::NOW, true)) {
                $pairs[] = [t($label), $number($team[$count])];
            }
        }

        return $pairs;
    }

    /**
     * Job applications whose status each person changed in the period (their history: {at, by, from, to}) – one per
     * application and person. Reads only the applications changed since $from (status_at is the latest change).
     *
     * @return array<int, int>
     */
    private static function applicationsHandled(Db $db, string $from, string $to): array
    {
        try {
            $rows = $db->all('SELECT id, history FROM {applications} WHERE status_at >= ? ORDER BY id DESC LIMIT ' . self::APPLICATION_ROWS, [$from]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return [];
        }

        return self::changesBy($rows, $from, $to);
    }

    /**
     * Counts, per person, the rows with a change of theirs within [from, to) in the JSON history. Pure.
     *
     * @param list<array<string, mixed>> $rows id and history
     * @return array<int, int>
     */
    public static function changesBy(array $rows, string $from, string $to): array
    {
        $out = [];
        foreach ($rows as $r) {
            $history = json_decode((string) ($r['history'] ?? ''), true);
            $seen = [];
            foreach (is_array($history) ? $history : [] as $h) {
                $at = is_array($h) ? (string) ($h['at'] ?? '') : '';
                $by = is_array($h) && is_numeric($h['by'] ?? null) ? (int) $h['by'] : 0;
                if ($at >= $from && $at < $to && !isset($seen[$by])) {
                    $seen[$by] = true;
                    $out[$by] = ($out[$by] ?? 0) + 1;
                }
            }
        }

        return $out;
    }
}
