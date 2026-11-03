<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The printable monthly report (3.16, Settings → Reports): one archived month (Core\ReportArchive) as a standalone A4 page
 * the browser saves as PDF, or as the same HTML file to download. A summary of what happened to the site for the owner's
 * client – no hours, no work log, nothing about money.
 *
 * sections() turns the stored data into titled blocks (pairs, tables, lists, notes) in the language set when called – no
 * database, so it is tested on its own; the template views/admin/settings/report_print.php draws them with print CSS
 * (A4, page numbers, no table cut across a page). Written in the site's default language with the admin dictionary, like
 * the e-mail. A section whose feature is off is not in the data, so it is not printed.
 */
final class ReportPage
{
    /** Firewall reasons (ka_firewall_log.reason) => label, as Settings → Firewall names them (also on the fleet console). */
    public const array FIREWALL = ['list' => 'blocked address', 'country' => 'blocked country', 'rate' => 'too many requests', 'probe' => 'probing for other systems', 'temporary' => 'blocked for a while'];

    /** What waits at the end of the month (MonthlyReport::open) => label. */
    private const array OPEN = ['requests' => 'Requests to Claude still open', 'page_drafts' => 'Pages with unpublished changes', 'part_drafts' => 'Site parts with unpublished changes',
        'news_drafts' => 'News drafts', 'look_draft' => 'Unpublished changes of the look', 'staged_files' => 'Files from Claude waiting for approval', 'enquiries' => 'Enquiries nobody has opened yet'];

    /** Security events => label (also on the fleet console). */
    public const array SECURITY = ['firewall.blocked' => 'Addresses blocked for a while', 'security.account_suspended' => 'Unused accounts suspended',
        'security.connection_revoked' => 'Unused Claude connections revoked', 'security.token_reuse' => 'Connected applications signed out after a reused token'];

    /**
     * The whole page as an HTML document.
     *
     * @param array{month: string, created_at: string, rebuilt_at: ?string, data: array<string, mixed>} $stored from ReportArchive::get
     * @param array{script?: string, download?: string, back?: string} $links the screen toolbar; none in the downloaded file
     */
    public static function render(View $view, array $stored, Settings $s, string $siteUrl, array $links = [], ?array $previous = null): string
    {
        return Language::runWith(Language::defaults($s), fn (): string => $view->render('admin/settings/report_print', self::viewData($stored, $s, $siteUrl, $previous) + ['links' => $links]), 'admin-');
    }

    /** The width of the visual summary on the page: A4 less the margins (180 mm at 96 dpi). */
    public const int WIDTH = 676;

    /**
     * The top of the page (3.26.1) with the e-mail's components (Core\ReportVisual): the opening sentences, the headline
     * tiles, the uptime with a console, and what it took to run the site.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed>|null $previous the previous month's archived data
     * @param array<string, string> $palette ReportVisual::palette()
     */
    public static function visual(array $data, ?array $previous, array $palette, string $siteName): string
    {
        $data = MonthlyReport::scrub($data);
        $care = ReportSummary::care($data);
        $tiles = ReportSummary::tiles($data, $previous !== null ? MonthlyReport::scrub($previous) : null, $care);
        $compared = array_filter($tiles, static fn (array $tile): bool => in_array($tile['tone'] ?? '', ['up', 'down'], true)) !== [];
        $uptime = ReportSummary::uptimeBlock($data, $palette, self::WIDTH);
        $space = '<div style="height:28px;line-height:28px;font-size:0;">&nbsp;</div>';

        return ReportVisual::lead(ReportSummary::lead($care, $siteName)['html'], $palette)
            . '<div style="height:16px;line-height:16px;font-size:0;">&nbsp;</div>'
            . ReportVisual::tiles($tiles, $palette, self::WIDTH) . ($compared ? ReportVisual::note(t('The arrows compare with the month before.'), $palette) : '')
            . ($uptime !== null ? $space . $uptime['html'] : '')
            . $space . ReportSummary::careBlock($care, $palette, self::WIDTH)['html'];
    }

    /**
     * What the template prints, in the language set when called.
     *
     * @param array{month: string, created_at: string, rebuilt_at: ?string, data: array<string, mixed>} $stored
     * @return array<string, mixed>
     */
    public static function viewData(array $stored, Settings $s, string $siteUrl, ?array $previous = null): array
    {
        $siteUrl = rtrim($siteUrl, '/');
        $agency = $s->get('agency_name');
        $logo = $s->get('agency_logo');
        $month = self::monthName($stored['month']);
        $palette = ReportVisual::palette($s);

        return [
            'language' => Language::code(),
            'title' => t('Website report – %s – %s', $month, $s->get('site_name')),
            'monthName' => $month,
            'siteName' => $s->get('site_name'),
            'siteAddress' => (string) preg_replace('#^https?://#', '', $siteUrl),
            'agency' => $agency,
            'agencyLogo' => $agency !== '' && $logo !== '' ? $siteUrl . '/' . ltrim($logo, '/') : '',
            'agencyContacts' => array_values(array_filter([(string) preg_replace('#^https?://#', '', $s->get('agency_url')), $s->get('agency_email'), $s->get('agency_phone')])),
            'generated' => format_date($stored['created_at'], true),
            'rebuilt' => $stored['rebuilt_at'] !== null ? format_date($stored['rebuilt_at'], true) : '',
            'sections' => self::sections($stored['data'], $stored['rebuilt_at'] !== null ? format_date($stored['rebuilt_at'], true) : ''),
            // 3.26.1: the colours of the page (the site's accent) and the visual summary – none for a report without data (the fleet console's)
            'palette' => $palette,
            'visual' => $stored['data'] !== [] ? self::visual($stored['data'], $previous, $palette, $s->get('site_name')) : '',
        ];
    }

    /** "September 2026" in the current language. */
    public static function monthName(string $month): string
    {
        return t(MonthlyReport::MONTHS[(int) substr($month, 5, 2)] ?? 'January') . ' ' . substr($month, 0, 4);
    }

    /**
     * The report as sections of blocks; texts in the current language, every string from the data scrubbed again.
     * $rebuilt (the formatted moment of a rebuild, or '') marks the parts taken when the report was made – what waits,
     * the problems, the audit, the version, the unopened enquiries – as the state on that day, not at the end of the month.
     *
     * @param array<string, mixed> $data from MonthlyReport::build (archived)
     * @return list<array{key: string, title: string, blocks: list<array<string, mixed>>}>
     */
    public static function sections(array $data, string $rebuilt = ''): array
    {
        $data = MonthlyReport::scrub($data);
        $n = static fn (mixed $value): string => format_number((int) $value, 0);
        $date = static fn (mixed $value, bool $time = false): string => ($moment = MonthlyReport::moment($value)) !== null ? format_date($moment, $time) : '';
        // a rebuilt report: the parts taken "now" are from the day of the rebuild (N316-7)
        $asRebuilt = $rebuilt !== '' ? ['type' => 'note', 'text' => t('As on %s, when the report was rebuilt – not at the end of the month.', $rebuilt)] : null;
        $pairs = static fn (array $rows): ?array => $rows === [] ? null : ['type' => 'pairs', 'rows' => $rows];
        // numeric columns align right, short ones (dates, statuses) never wrap; the rest wraps at word boundaries
        $table = static fn (string $caption, array $head, array $rows, array $numeric = [], array $short = []): ?array => $rows === [] ? null
            : ['type' => 'table', 'caption' => $caption, 'head' => $head, 'rows' => $rows, 'numeric' => $numeric, 'short' => $short];
        $note = static fn (string $text): array => ['type' => 'note', 'text' => $text];
        $more = static fn (int $total, int $shown): ?array => $total > $shown ? ['type' => 'note', 'text' => t('… and %d more.', $total - $shown)] : null;
        $change = static function (int $now, int $before, ?string $countingChanged = null) use ($n): string {
            if ($before === 0) {
                return $n($now);
            }
            if ($countingChanged !== null && $now < $before) {
                return $n($now) . ' (' . t('counting changed on %s', format_date($countingChanged)) . ')'; // 3.29.1: no drop across the switch
            }
            $percent = (int) round(100 * ($now - $before) / $before);

            return $n($now) . ' (' . ($percent >= 0 ? '+' : '−') . abs($percent) . ' %)';
        };
        $out = [];
        $add = static function (string $key, string $title, array $blocks) use (&$out): void {
            $blocks = array_values(array_filter($blocks));
            if ($blocks !== []) {
                $out[] = ['key' => $key, 'title' => $title, 'blocks' => $blocks];
            }
        };
        $stats = is_array($data['stats'] ?? null) ? $data['stats'] : null;
        $enquiries = is_array($data['enquiries'] ?? null) ? $data['enquiries'] : null;
        $requests = is_array($data['requests'] ?? null) ? $data['requests'] : null;
        $work = is_array($data['work'] ?? null) ? $data['work'] : null;
        $claude = is_array($data['claude'] ?? null) ? $data['claude'] : null;
        $updates = (array) ($data['updates'] ?? []);
        $backups = (array) ($data['backups'] ?? []);
        $problems = (array) ($data['problems'] ?? []);

        // at a glance: the visual summary at the top of the page (3.26.1, visual()) – the tiles and what it took to run the site

        // what waits at the end of the month
        $open = (array) ($data['open'] ?? []);
        $rows = [];
        foreach (self::OPEN as $key => $label) {
            if ((int) ($open[$key] ?? 0) > 0) {
                $rows[] = [t($label), $key === 'look_draft' ? t('Yes') : $n($open[$key])];
            }
        }
        $errors = (int) ($data['decisions']['errors'] ?? 0);
        if ($errors > 0) {
            $rows[] = [t('Problems in System status that need fixing'), $n($errors)];
        }
        if (isset($data['open']) || $errors > 0) {
            $add('open', $rebuilt !== '' ? t('Waiting when the report was rebuilt') : t('Waiting at the end of the month'), [$pairs($rows) ?? $note(t('Nothing was waiting.')), $asRebuilt]);
        }

        // requests to Claude
        if ($requests !== null) {
            $status = static fn (string $s): string => t(Requests::STATUSES[$s] ?? $s);
            $add('requests', t('Requests to Claude'), [
                $pairs([[t('Written this month'), $n($requests['created'] ?? 0)], [t('Handled this month'), $n($requests['done_count'] ?? 0)], [t('Still open'), $n($requests['open_count'] ?? 0)]]),
                $table(t('Handled this month'), [t('Request'), t('Status'), t('Written'), t('Done')], array_map(static fn (array $r): array => [(string) $r['title'], $status((string) $r['status']), $date($r['created']), $date($r['done'])], (array) ($requests['done'] ?? [])), [], [1, 2, 3]),
                $more((int) ($requests['done_count'] ?? 0), count((array) ($requests['done'] ?? []))),
                $table(t('Still open'), [t('Request'), t('Status'), t('Written')], array_map(static fn (array $r): array => [(string) $r['title'], $status((string) $r['status']), $date($r['created'])], (array) ($requests['open'] ?? [])), [], [1, 2]),
                $more((int) ($requests['open_count'] ?? 0), count((array) ($requests['open'] ?? []))),
            ]);
        }

        // changes by people and by Claude
        if ($work !== null) {
            $areaRows = [];
            foreach ((array) ($work['areas'] ?? []) as $key => $area) {
                $areaRows[] = [t(MonthlyReport::AREAS[$key] ?? 'Other'), $n($area['people'] ?? 0), $n($area['claude'] ?? 0)];
            }
            $pages = (array) ($work['pages_published'] ?? []);
            $news = (array) ($work['news_published'] ?? []);
            $add('work', t('Changes to the site'), [
                $areaRows === [] ? $note(t('Nothing was changed in the administration this month.')) : $table(t('Changes by area'), [t('Area'), t('By people'), t('By Claude')], $areaRows, [1, 2]),
                $table(t('Pages published'), [t('Page'), t('Date'), t('Published by')], array_map(static fn (array $p): array => [(string) $p['title'], $date($p['date']), $p['by'] === 'claude' ? 'Claude' : t('a person')], $pages), [], [1, 2]),
                $more((int) ($work['pages_published_count'] ?? 0), count($pages)),
                $table(t('News published'), [t('Title'), t('Date')], array_map(static fn (array $p): array => [(string) $p['title'], $date($p['date'])], $news), [], [1]),
                $more((int) ($work['news_published_count'] ?? 0), count($news)),
                (int) ($work['look_published'] ?? 0) > 0 ? $pairs([[t('Look and menu published'), $n($work['look_published'])]]) : null,
            ]);
        }

        // Claude: sessions and scheduled runs
        if ($claude !== null && ((int) ($claude['sessions'] ?? 0) > 0 || (int) ($claude['runs'] ?? 0) > 0)) {
            $rows = [[t('Claude sessions'), $n($claude['sessions'] ?? 0)], [t('Changes made in them'), $n($claude['calls'] ?? 0)]];
            if ((int) ($claude['undone'] ?? 0) > 0) {
                $rows[] = [t('Sessions undone'), $n($claude['undone'])];
            }
            $rows[] = [t('Scheduled Claude runs'), $n($claude['runs'] ?? 0)];
            foreach ((array) ($claude['run_statuses'] ?? []) as $status => $count) {
                $rows[] = [t(AgentSchedules::STATUSES[$status] ?? (string) $status), $n($count), true]; // indented under the runs
            }
            // by the kind of task and the result – never a run's name or summary (N316-1)
            $taskRows = [];
            foreach ((array) ($claude['run_tasks'] ?? []) as $task => $results) {
                foreach ((array) $results as $status => $count) {
                    $taskRows[] = [t(AgentSchedules::TASKS[$task][0] ?? 'Custom instructions'), t(AgentSchedules::STATUSES[$status] ?? (string) $status), $n($count)];
                }
            }
            $add('claude', t('Claude sessions and scheduled runs'), [
                $pairs($rows),
                $table(t('Scheduled runs by task'), [t('Task'), t('Result'), t('Runs')], $taskRows, [2], [1]),
            ]);
        }

        // traffic and conversions
        if ($stats !== null) {
            $rows = [[t('Visits'), $change((int) ($stats['visits'] ?? 0), (int) ($stats['previous_visits'] ?? 0), ReportSummary::countingChanged($stats))],
                [t('Page views'), $change((int) ($stats['views'] ?? 0), (int) ($stats['previous_views'] ?? 0), ReportSummary::countingChanged($stats))]];
            foreach (['calls' => 'Calls – clicks on a phone number', 'emails' => 'E-mails – clicks on an e-mail address', 'whatsapp' => 'WhatsApp – clicks on a WhatsApp link'] as $key => $label) {
                if ((int) ($stats['contact_clicks'][$key] ?? 0) > 0) {
                    $rows[] = [t($label), $n($stats['contact_clicks'][$key])];
                }
            }
            $search = array_map(static fn (array $q): array => [(string) $q['query'], ($q['engine'] ?? '') === 'bing' ? 'Bing' : 'Google', $n($q['clicks'] ?? 0)], (array) ($data['search'] ?? []));
            $add('traffic', t('Traffic and conversions'), [
                $pairs($rows),
                $note(t('Compared with the month before.')),
                // 3.29: browser-confirmed counting started in this month or the one it is compared with
                is_string($stats['confirmed_since'] ?? null) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $stats['confirmed_since'])
                    ? $note(t('From %s, only visits confirmed by a visitor’s browser are counted – bots, link previews and your own visits are left out. Earlier numbers include some of them.', format_date($stats['confirmed_since']))) : null,
                $table(t('Most read pages'), [t('Page'), t('Views')], array_map(static fn (array $p): array => [(string) $p['path'], $n($p['n'])], (array) ($stats['pages'] ?? [])), [1]),
                $table(t('Visitors came from'), [t('Site'), t('Visits')], array_map(static fn (array $p): array => [(string) $p['site'], $n($p['n'])], (array) ($stats['sources'] ?? [])), [1]),
                $table(t('Campaigns'), [t('Campaign'), t('Visits')], array_map(static fn (array $p): array => [(string) $p['campaign'], $n($p['n'])], (array) ($stats['campaigns'] ?? [])), [1]),
                $table(t('Searches that brought visitors'), [t('Search'), t('Engine'), t('Clicks')], $search, [2], [1]),
            ]);
        }

        // enquiries: counts by form, category and page – never their contents or senders
        if ($enquiries !== null) {
            $add('enquiries', t('Enquiries'), [
                $pairs([[t('Enquiries received'), $n($enquiries['total'] ?? 0)], [$rebuilt !== '' ? t('Nobody has opened yet (when rebuilt)') : t('Nobody has opened yet (now)'), $n($enquiries['unanswered'] ?? 0)]]),
                $table(t('By form'), [t('Form'), t('Enquiries')], array_map(static fn (array $f): array => [(string) $f['form'] !== '' ? (string) $f['form'] : t('Form'), $n($f['n'])], (array) ($enquiries['forms'] ?? [])), [1]),
                $table(t('By category'), [t('Category'), t('Enquiries')], array_map(static fn (array $c): array => [t(Triage::CATEGORIES[$c['category']] ?? (string) $c['category']), $n($c['n'])], (array) ($enquiries['categories'] ?? [])), [1]),
                $table(t('By page'), [t('Page'), t('Enquiries')], array_map(static fn (array $p): array => [(string) $p['path'], $n($p['n'])], (array) ($enquiries['pages'] ?? [])), [1]),
            ]);
        }

        // bookings
        $bookings = is_array($data['bookings'] ?? null) ? $data['bookings'] : null;
        if ($bookings !== null) {
            $rows = [];
            foreach ((array) ($bookings['statuses'] ?? []) as $status => $count) {
                $rows[] = [t(Booking::STATUSES[$status] ?? (string) $status), $n($count)];
            }
            $add('bookings', t('Bookings'), [
                $pairs([[t('Booked this month'), $n($bookings['total'] ?? 0)], [t('Appointments this month'), $n($bookings['appointments'] ?? 0)]]),
                $table(t('Booked this month by status'), [t('Status'), t('Bookings')], $rows, [1]),
            ]);
        }

        // 3.31: the pipeline – counts only, never values (D-W7)
        $pipeline = is_array($data['pipeline'] ?? null) ? $data['pipeline'] : null;
        if ($pipeline !== null) {
            $add('pipeline', t('Pipeline'), [
                $pairs([[t('New deals'), $n($pipeline['new'] ?? 0)], [t('Deals won'), $n($pipeline['won'] ?? 0)], [t('Deals lost'), $n($pipeline['lost'] ?? 0)]]),
                $table(t('Deals that reached each stage'), [t('Stage'), t('Deals')], array_map(static fn (array $st): array => [(string) ($st['label'] ?? ''), $n($st['n'] ?? 0)],
                    array_values(array_filter((array) ($pipeline['stages'] ?? []), static fn (mixed $st): bool => is_array($st) && (int) ($st['n'] ?? 0) > 0))), [1]),
            ]);
        }

        // newsletter
        $newsletter = is_array($data['newsletter'] ?? null) ? $data['newsletter'] : null;
        if ($newsletter !== null) {
            $campaigns = (array) ($newsletter['campaigns'] ?? []);
            $add('newsletter', t('Newsletter'), [
                $pairs([[t('Newsletter sign-ups'), $n($newsletter['signups'] ?? 0)], [t('Newsletters sent'), $n(count($campaigns))]]),
                $table(t('Newsletters sent'), [t('Subject'), t('Sent'), t('Recipients')], array_map(static fn (array $c): array => [(string) $c['subject'], $date($c['date']), $n($c['sent'])], $campaigns), [2], [1]),
            ]);
        }

        // Kaleta updates
        $result = ['update.applied' => 'Installed', 'update.failed' => 'Failed – the site stayed on its version', 'update.rolled_back' => 'Rolled back to the previous version'];
        $channel = t(['stable' => 'Stable channel', 'custom' => 'custom update source'][(string) ($data['update_channel'] ?? '')] ?? 'Latest channel');
        $add('updates', t('Kaleta updates'), [
            isset($data['version']) ? $pairs([[$rebuilt !== '' ? t('Kaleta version when the report was rebuilt') : t('Kaleta version'), (string) $data['version'] . ' · ' . $channel]]) : null,
            $updates === [] ? $note(t('No update this month.')) : $table(t('Updates'), [t('Date'), t('From'), t('To'), t('Result')],
                array_map(static fn (array $u): array => [$date($u['date'] ?? '', true), (string) ($u['from'] ?? ''), (string) ($u['to'] ?? ''), t($result[$u['type'] ?? ''] ?? 'Installed')], $updates), [], [0, 1, 2]),
        ]);

        // backups
        $offsite = (string) ($backups['offsite'] ?? '');
        $add('backups', t('Backups'), [$pairs(array_values(array_filter([
            [t('Backups made'), $n($backups['created'] ?? 0)],
            (int) ($backups['failed'] ?? 0) > 0 ? [t('Backups failed'), $n($backups['failed'])] : null,
            ($backups['last'] ?? null) !== null ? [t('Last backup'), $date($backups['last'], true)] : null,
            [t('Off-site copy'), $offsite === '' ? t('not set up') : strtoupper($offsite) . (!empty($backups['offsite_failing']) ? ' · ' . t('the last upload failed') : (($backups['offsite_last'] ?? null) !== null ? ' · ' . t('the last one %s', $date($backups['offsite_last'], true)) : ''))],
        ])))]);

        // security: counts only, never an address or an account
        $security = is_array($data['security'] ?? null) ? $data['security'] : null;
        if ($security !== null) {
            $firewall = array_map('intval', (array) ($security['firewall'] ?? []));
            $rows = [[t('Sign-ins to the administration'), $n($security['sign_ins'] ?? 0)], [t('Failed sign-ins'), $n($security['failed_sign_ins'] ?? 0)], [t('Requests blocked by the firewall'), $n(array_sum($firewall))]];
            foreach (self::SECURITY as $type => $label) {
                if ((int) ($security['events'][$type] ?? 0) > 0) {
                    $rows[] = [t($label), $n($security['events'][$type])];
                }
            }
            $add('security', t('Security'), [
                $pairs($rows),
                $table(t('Blocked by the firewall'), [t('Reason'), t('Requests')], array_map(static fn (string $reason, int $count): array => [t(self::FIREWALL[$reason] ?? $reason), $n($count)], array_keys($firewall), $firewall), [1]),
                // the firewall keeps its log 30 days (Firewall::LOG_DAYS): a 31-day month misses its start
                $date($security['firewall_from'] ?? null) !== '' ? $note(t('The firewall keeps its log for 30 days, so requests blocked before %s are not counted.', $date($security['firewall_from'], true))) : null,
                $note(t('Counts only – no addresses, accounts or e-mail addresses.')),
            ]);
        }

        // site audit
        $audit = is_array($data['audit'] ?? null) ? $data['audit'] : null;
        if ($audit !== null) {
            $kinds = (array) ($audit['kinds'] ?? []);
            $add('audit', t('Site audit'), [
                $pairs(array_values(array_filter([[t('Findings when the report was made'), $n($audit['total'] ?? 0)],
                    ($audit['fixed'] ?? null) !== null ? [t('Fewer than a month before'), $n($audit['fixed'])] : null,
                    ($audit['new'] ?? null) !== null ? [t('New since a month before'), $n($audit['new'])] : null]))),
                $kinds === [] ? $note(t('The audit found nothing to fix.')) : $table(t('Findings by kind'), [t('Kind'), t('Findings')],
                    array_map(static fn (string $kind, int $count): array => [t(Audit::KINDS[$kind] ?? $kind), $n($count)], array_keys($kinds), array_map('intval', $kinds)), [1]),
                ($audit['fixed'] ?? null) === null ? $note(t('What was fixed and what is new is compared with the previous month from the next report on.')) : null,
                $asRebuilt,
            ]);
        }

        // problems right now (System status when the report was made)
        $add('problems', t('Problems when the report was made'), [$problems === [] ? $note(t('None – every check in System status is fine.'))
            : ['type' => 'list', 'items' => array_map(static fn (array $p): string => (string) $p['name'] . ' – ' . (string) $p['info'], $problems)], $asRebuilt]);

        return $out;
    }
}
