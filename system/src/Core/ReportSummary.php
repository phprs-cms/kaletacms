<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * What the monthly report says first (3.26.1): the headline numbers, how much work keeping the site running took and
 * whether it needed a person – read from the stored data of a month (MonthlyReport::build), no database. The e-mail
 * (MonthlyReport::render), the report page (Core\ReportPage, on screen and in print) and get_monthly_report (care())
 * show the same.
 *
 * The work is counted, never timed or priced (the standing rule of every report: a summary of what happened – never
 * hours, a work log or money): updates, backups, checks, fixes and spam the site did by itself; requests, sessions and
 * scheduled runs of Claude; changes and comments of people; and how many times something needed a person to decide.
 * A report stored before 3.26.1 has no "care" part – what can be read from its other parts is counted, the rest is left
 * out (never a guess).
 */
final class ReportSummary
{
    /** Daily jobs (Core\Scheduler) whose runs count as checks of the site: once a day each, so the count means something. */
    public const array CHECK_JOBS = ['domain_watch', 'security', 'cookie_scan'];

    /** Events that ask a person to decide something => the line they are counted on. */
    public const array ATTENTION_EVENTS = ['update.failed' => 'updates', 'update.rolled_back' => 'updates', 'update.restore_failed' => 'updates',
        'backup.failed' => 'backups', 'task.failed' => 'jobs', 'mail.failed' => 'deliveries', 'webhook.failed' => 'deliveries', 'connector.failed' => 'deliveries',
        'content.review' => 'reviews', 'security.token_reuse' => 'security', 'fleet.kit_received' => 'kits', 'addon.failed' => 'addons', 'agent_run.missed' => 'runs'];

    /** What the site did by itself => label. */
    public const array AUTOMATIC = ['updates' => 'Kaleta updates installed', 'backups' => 'Backups made', 'offsite_copies' => 'Backup copies stored off the server',
        'checks' => 'Daily checks of the domain, certificate and security', 'links_fixed' => 'Links and redirects fixed by themselves',
        'spam' => 'Spam enquiries sorted out', 'accounts_closed' => 'Unused accounts and connections closed'];

    /** What Claude did => label. */
    public const array CLAUDE = ['requests' => 'Requests answered', 'sessions' => 'Work sessions', 'runs' => 'Scheduled runs'];

    /** What people did => label. */
    public const array PEOPLE = ['changes' => 'Changes in the administration', 'comments' => 'Notes and comments'];

    /** Details shown under a group, not part of its total => label. */
    public const array NOTES = ['blocked' => 'Suspicious requests stopped by the firewall', 'claude_changes' => 'Changes Claude made in them',
        'enquiries_handled' => 'Enquiries marked as handled', 'replies' => 'Replies to enquiries sent'];

    /** What needed a person to decide => label. */
    public const array ATTENTION = ['updates' => 'Updates that did not go through', 'backups' => 'Backups that failed', 'jobs' => 'Background jobs that kept failing',
        'deliveries' => 'E-mails or deliveries that failed', 'reviews' => 'Content that asked for a review', 'security' => 'Security warnings',
        'kits' => 'Design kits from the console to publish', 'addons' => 'Add-ons switched off after an error', 'runs' => 'Scheduled Claude runs nobody picked up',
        'errors' => 'Problems in System status to fix'];

    /** At most this many headline tiles: four sit side by side in the 600 px of an e-mail. */
    public const int TILES = 4;

    /**
     * How much work the month took: counts by who did it, the details and the decisions it needed. Language-neutral keys
     * (get_monthly_report returns it as it is); a count the report cannot know is missing, not zero.
     *
     * @param array<string, mixed> $data from MonthlyReport::build
     * @return array{automatic: array<string, int>, claude: array<string, int>|null, people: array<string, int>, notes: array<string, int>, attention: array<string, int>,
     *     totals: array{automatic: int, claude: int, people: int, all: int, attention: int}}
     */
    public static function care(array $data): array
    {
        $care = is_array($data['care'] ?? null) ? $data['care'] : [];
        $int = static fn (mixed $v): int => is_numeric($v) ? max(0, (int) $v) : 0;
        $updates = array_values(array_filter((array) ($data['updates'] ?? []), 'is_array'));
        $security = is_array($data['security'] ?? null) ? $data['security'] : null;
        $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];

        $automatic = ['updates' => count(array_filter($updates, static fn (array $u): bool => ($u['type'] ?? '') === 'update.applied')),
            'backups' => $int($data['backups']['created'] ?? 0)];
        if (array_key_exists('offsite_copies', $care)) {
            $automatic['offsite_copies'] = $int($care['offsite_copies']);
        }
        if (is_array($care['checks'] ?? null)) {
            $automatic['checks'] = array_sum(array_map($int, $care['checks']));
        }
        if (array_key_exists('links_healed', $care) || array_key_exists('redirects_auto', $care)) {
            $automatic['links_fixed'] = $int($care['links_healed'] ?? 0) + $int($care['redirects_auto'] ?? 0);
        }
        if (array_key_exists('spam', $care)) {
            $automatic['spam'] = $int($care['spam']);
        }
        if ($security !== null) {
            $automatic['accounts_closed'] = $int($security['events']['security.account_suspended'] ?? 0) + $int($security['events']['security.connection_revoked'] ?? 0);
        }

        $claude = null;
        if (is_array($data['requests'] ?? null) || is_array($data['claude'] ?? null)) {
            $claude = array_filter(['requests' => is_array($data['requests'] ?? null) ? $int($data['requests']['done_count'] ?? 0) : null,
                'sessions' => is_array($data['claude'] ?? null) ? $int($data['claude']['sessions'] ?? 0) : null,
                'runs' => is_array($data['claude'] ?? null) ? $int($data['claude']['runs'] ?? 0) : null], static fn (?int $v): bool => $v !== null);
        }
        // N3261-2: the changes people made – counted for the care part (no sign-ins, no comments, which have their own line);
        // a report before it: the change log by area (no sign-ins either), only the oldest ones the bare count
        $areas = is_array($data['work']['areas'] ?? null) ? array_filter($data['work']['areas'], 'is_array') : null;
        $people = ['changes' => match (true) {
            array_key_exists('people_changes', $care) => $int($care['people_changes']),
            $areas !== null => array_sum(array_map(static fn (array $a): int => $int($a['people'] ?? 0), $areas)),
            default => $int($changes['people'] ?? 0),
        }];
        if (array_key_exists('comments', $care)) {
            $people['comments'] = $int($care['comments']);
        }
        $notes = array_filter([
            'blocked' => $security !== null ? array_sum(array_map($int, (array) ($security['firewall'] ?? []))) : null,
            'claude_changes' => $claude !== null ? $int($changes['claude'] ?? 0) : null,
            'enquiries_handled' => array_key_exists('enquiries_handled', $care) ? $int($care['enquiries_handled']) : null,
            'replies' => array_key_exists('replies', $care) ? $int($care['replies']) : null,
        ], static fn (?int $v): bool => $v !== null);

        // what needed a person: the events of the month (3.26.1), or what an older report shows – its failed updates and backups
        $attention = [];
        if (is_array($care['attention'] ?? null)) {
            foreach ($care['attention'] as $type => $count) {
                $line = self::ATTENTION_EVENTS[(string) $type] ?? null;
                if ($line !== null) {
                    $attention[$line] = ($attention[$line] ?? 0) + $int($count);
                }
            }
        } else {
            $attention['updates'] = count(array_filter($updates, static fn (array $u): bool => in_array($u['type'] ?? '', ['update.failed', 'update.rolled_back'], true)));
            $attention['backups'] = $int($data['backups']['failed'] ?? 0);
        }
        $attention['errors'] = $int($data['decisions']['errors'] ?? 0);
        $attention = array_filter(array_replace(array_intersect_key(array_fill_keys(array_keys(self::ATTENTION), 0), $attention), $attention)); // in the order of ATTENTION

        $totals = ['automatic' => array_sum($automatic), 'claude' => $claude === null ? 0 : array_sum($claude), 'people' => array_sum($people)];

        return ['automatic' => $automatic, 'claude' => $claude, 'people' => $people, 'notes' => $notes, 'attention' => $attention,
            'totals' => $totals + ['all' => array_sum($totals), 'attention' => array_sum($attention)]];
    }

    /**
     * The headline tiles in the current language: visits, enquiries, uptime, changes published and issues fixed – the first
     * four with data, then backups and the upkeep tasks to have at least three. The change against the month before comes from
     * the data (previous visits, enquiries) or from the previous archived month.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $previous the previous month's data, when it is archived
     * @param array<string, mixed> $care from care()
     * @return list<array{label: string, value: string, change?: string, tone?: string, note?: string}>
     */
    public static function tiles(array $data, ?array $previous, array $care): array
    {
        $tiles = [];
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : null;
        if ($stats !== null) {
            $tiles[] = ['label' => t('Visits'), 'value' => format_count((int) ($stats['visits'] ?? 0))]
                + self::change((int) ($stats['visits'] ?? 0), isset($stats['previous_visits']) ? (int) $stats['previous_visits'] : null);
        }
        $enquiries = is_array($data['enquiries'] ?? null) ? $data['enquiries'] : null;
        if ($enquiries !== null) {
            $before = $enquiries['previous_total'] ?? $previous['enquiries']['total'] ?? null;
            $tiles[] = ['label' => t('Enquiries'), 'value' => format_count((int) ($enquiries['total'] ?? 0))] + self::change((int) ($enquiries['total'] ?? 0), is_numeric($before) ? (int) $before : null);
        }
        $uptime = self::uptimePercent($data);
        if ($uptime !== null) {
            $before = self::uptimePercent($previous ?? []);
            $tiles[] = ['label' => t('Uptime'), 'value' => format_number($uptime, $uptime >= 100.0 ? 0 : 2) . ' %']
                + ($before !== null ? ['change' => t('%s last month', format_number($before, $before >= 100.0 ? 0 : 2) . ' %'), 'tone' => 'same'] : ['note' => t('Measured by the console')]);
        }
        $work = is_array($data['work'] ?? null) ? $data['work'] : null;
        if ($work !== null) {
            $published = static fn (array $w): int => (int) ($w['pages_published_count'] ?? 0) + (int) ($w['news_published_count'] ?? 0) + (int) ($w['look_published'] ?? 0);
            $tiles[] = ['label' => t('Changes published'), 'value' => format_count($published($work))]
                + self::change($published($work), is_array($previous['work'] ?? null) ? $published($previous['work']) : null);
        }
        $audit = is_array($data['audit'] ?? null) ? $data['audit'] : null;
        if ($audit !== null && ($audit['fixed'] ?? null) !== null) {
            $tiles[] = ['label' => t('Issues fixed'), 'value' => format_count((int) $audit['fixed']), 'note' => t('%s still open', format_count((int) ($audit['total'] ?? 0)))];
        }
        if (count($tiles) < 3) {
            $tiles[] = ['label' => t('Backups made'), 'value' => format_count((int) ($data['backups']['created'] ?? 0))];
        }
        if (count($tiles) < 3) {
            $tiles[] = ['label' => t('Upkeep tasks'), 'value' => format_count($care['totals']['all'] ?? 0)];
        }

        return array_slice($tiles, 0, self::TILES);
    }

    /**
     * The change against the month before as a tile shows it.
     *
     * @return array{change?: string, tone?: string}
     */
    public static function change(int $now, ?int $before): array
    {
        if ($before === null) {
            return [];
        }
        if ($before === 0) {
            return $now === 0 ? ['change' => t('= same as last month'), 'tone' => 'same'] : ['change' => t('▲ up from 0'), 'tone' => 'up'];
        }
        $percent = (int) round(100 * ($now - $before) / $before);

        return match (true) {
            $percent > 0 => ['change' => '▲ ' . format_count($percent) . ' %', 'tone' => 'up'],
            $percent < 0 => ['change' => '▼ ' . format_count(-$percent) . ' %', 'tone' => 'down'],
            default => ['change' => t('= same as last month'), 'tone' => 'same'],
        };
    }

    /** The month's uptime in per cent from the console (agent B's uptime data), null when there is none. */
    public static function uptimePercent(array $data): ?float
    {
        $uptime = $data['uptime'] ?? null;

        return is_array($uptime) && is_numeric($uptime['percent'] ?? null) ? (float) $uptime['percent'] : null;
    }

    /**
     * The opening sentences: how much work keeping the site running took and how often it needed a person. Both as HTML
     * (the numbers bold) and as plain text.
     *
     * @param array<string, mixed> $care from care()
     * @return array{html: string, text: string}
     */
    public static function lead(array $care, string $siteName): array
    {
        $totals = $care['totals'];
        $n = static fn (int $v): string => format_count($v);
        $sentences = [];
        $sentences[] = $care['claude'] !== null
            ? ['Keeping %s running took %s tasks: %s done automatically, %s by Claude and %s by people.', [$siteName, $n($totals['all']), $n($totals['automatic']), $n($totals['claude']), $n($totals['people'])]]
            : ['Keeping %s running took %s tasks: %s done automatically and %s by people.', [$siteName, $n($totals['all']), $n($totals['automatic']), $n($totals['people'])]];
        $sentences[] = match (true) {
            $totals['attention'] === 0 => ['It did not need a single decision from you.', []],
            $totals['attention'] === 1 => ['It needed your attention once.', []],
            default => ['It needed your attention %s times.', [$n($totals['attention'])]],
        };
        $html = [];
        $text = [];
        foreach ($sentences as [$format, $values]) {
            // the names stay plain, the numbers bold: the format is escaped, every value escaped before it goes in
            $html[] = sprintf(e(t($format)), ...array_map(static fn (string $v, int $i): string => $i === 0 && count($values) > 1 ? e($v) : '<strong>' . e($v) . '</strong>', $values, array_keys($values)));
            $text[] = sprintf(t($format), ...$values);
        }

        return ['html' => implode(' ', $html), 'text' => implode(' ', $text)];
    }

    /**
     * What waits for a person now (when the report was made): enquiries nobody opened, problems to fix, requests to
     * Claude and files waiting for approval – each with its place in the administration.
     *
     * @param array<string, mixed> $data
     * @return list<array{0: string, 1: string}>
     */
    public static function waiting(array $data, string $admin): array
    {
        $items = [];
        $decisions = (array) ($data['decisions'] ?? []);
        $open = (array) ($data['open'] ?? []);
        if ((int) ($decisions['enquiries'] ?? 0) > 0) {
            $items[] = [t('%d enquiry(ies) nobody has opened yet', (int) $decisions['enquiries']), $admin . '?module=enquiries'];
        }
        if ((int) ($decisions['errors'] ?? 0) > 0) {
            $items[] = [t('%d problem(s) in System status need fixing', (int) $decisions['errors']), $admin . '?module=status'];
        }
        if ((int) ($open['requests'] ?? 0) > 0) {
            $items[] = [t('Requests to Claude still open') . ': ' . format_count((int) $open['requests']), $admin . '?module=requests'];
        }
        if ((int) ($open['staged_files'] ?? 0) > 0) {
            $items[] = [t('Files from Claude waiting for approval') . ': ' . format_count((int) $open['staged_files']), $admin . '?module=media'];
        }

        return $items;
    }

    /**
     * "What it took to run the site": the split by who did the work, the three groups and the decisions it needed – as
     * HTML (ReportVisual blocks, $width wide) and as the plain-text lines of the e-mail.
     *
     * @param array<string, mixed> $care from care()
     * @param array<string, string> $p ReportVisual::palette()
     * @return array{html: string, text: string}
     */
    public static function careBlock(array $care, array $p, int $width): array
    {
        $n = static fn (int $v): string => format_count($v);
        $groups = [];
        $text = [];
        $group = function (string $title, int $total, string $color, string $class, array $counts, array $labels, array $notes) use (&$groups, &$text, $care, $n): void {
            $rows = [];
            foreach ($labels as $key => $label) {
                if ((int) ($counts[$key] ?? 0) > 0) {
                    $rows[] = [t($label), $n((int) $counts[$key])];
                }
            }
            foreach ($notes as $key) {
                if ((int) ($care['notes'][$key] ?? 0) > 0) {
                    $rows[] = [t(self::NOTES[$key]), $n((int) $care['notes'][$key]), true];
                }
            }
            $groups[] = ['title' => $title, 'total' => $total, 'color' => $color, 'class' => $class, 'rows' => $rows, 'empty' => t('Nothing this month')];
            $text[] = '  ' . $title . ': ' . $n($total);
            foreach ($rows as $r) {
                $text[] = '    ' . $r[0] . ': ' . $r[1];
            }
        };
        $totals = $care['totals'];
        $group(t('Done automatically'), $totals['automatic'], $p['accent'], '', $care['automatic'], self::AUTOMATIC, ['blocked']);
        if ($care['claude'] !== null) {
            $group(t('Done by Claude'), $totals['claude'], $p['tint'], '', $care['claude'], self::CLAUDE, ['claude_changes']);
        }
        $group(t('Done by people'), $totals['people'], $p['people'], 'kr-people', $care['people'], self::PEOPLE, ['enquiries_handled', 'replies']);

        $stack = [['label' => t('Automatically'), 'value' => $totals['automatic'], 'color' => $p['accent']]];
        if ($care['claude'] !== null) {
            $stack[] = ['label' => 'Claude', 'value' => $totals['claude'], 'color' => $p['tint']];
        }
        $stack[] = ['label' => t('People'), 'value' => $totals['people'], 'color' => $p['people'], 'class' => 'kr-people'];

        $attention = [];
        foreach (self::ATTENTION as $key => $label) {
            if ((int) ($care['attention'][$key] ?? 0) > 0) {
                $attention[] = [t($label) . ': ' . $n((int) $care['attention'][$key])];
            }
        }
        $needed = match ($totals['attention']) {
            0 => t('The site needed no decision from you this month.'),
            1 => t('The site needed your attention once this month.'),
            default => t('The site needed your attention %s times this month.', $n($totals['attention'])),
        };

        $html = ReportVisual::title(t('What it took to run the site'), $p, t('Everything that kept the site safe, up to date and working this month – counted, not timed: what happened by itself, what Claude did and what people did.'))
            . ReportVisual::stack($stack, $p)
            . '<div style="height:16px;line-height:16px;font-size:0;">&nbsp;</div>'
            . ReportVisual::groups($groups, $p, $width)
            . '<div style="height:4px;line-height:4px;font-size:0;">&nbsp;</div>'
            . ReportVisual::callout($needed, $attention, $p, $totals['attention'] === 0);
        $plain = mb_strtoupper(t('What it took to run the site')) . "\n" . implode("\n", $text) . "\n  " . $needed
            . ($attention === [] ? '' : "\n" . implode("\n", array_map(static fn (array $a): string => '    – ' . $a[0], $attention)));

        return ['html' => $html, 'text' => $plain];
    }

    /**
     * The month's uptime from the console: the percent, the checks and the strip of days (Core\UptimeStrip, agent B's
     * e-mail-safe component – drawn when it is there). Null when the site has no console (no "uptime" in the data): the
     * e-mail then has no uptime section at all.
     *
     * @param array<string, mixed> $data
     * @param array<string, string> $p
     * @return array{html: string, text: string}|null
     */
    public static function uptimeBlock(array $data, array $p, int $width): ?array
    {
        $uptime = $data['uptime'] ?? null;
        if (!is_array($uptime)) {
            return null;
        }
        $percent = self::uptimePercent($data);
        $line = $percent === null ? t('The console did not measure the uptime this month.')
            : t('%s of the time up – %s checks by the console, %s of them failed.', format_number($percent, $percent >= 100.0 ? 0 : 2) . ' %', format_count((int) ($uptime['checks'] ?? 0)), format_count((int) ($uptime['failed'] ?? 0)));
        // MERGE (3.26.1, agent B): Core\UptimeStrip draws the days the way the fleet site detail does (views/admin/fleet/trend.php)
        $strip = 'Kaleta\\Core\\UptimeStrip';
        $days = array_values(array_filter((array) ($uptime['days'] ?? []), 'is_array'));
        $stripHtml = class_exists($strip) && $days !== [] ? (string) $strip::html($days, ['height' => 32, 'legend' => true, 'dates' => true, 'color' => $p['muted']]) : '';
        $stripText = class_exists($strip) ? (string) $strip::text($uptime) : '';

        return [
            'html' => ReportVisual::title(t('Uptime'), $p) . ReportVisual::lead(e($line), $p, 15) . ($stripHtml !== '' ? '<div style="height:12px;line-height:12px;font-size:0;">&nbsp;</div>' . $stripHtml : ''),
            'text' => mb_strtoupper(t('Uptime')) . "\n  " . str_replace("\n", "\n  ", trim($stripText !== '' ? $stripText : $line)),
        ];
    }
}
