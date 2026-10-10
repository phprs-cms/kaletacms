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

        $updates = array_map(fn (array $e): array => ['type' => (string) $e['type'], 'date' => (string) $e['created_at'], 'message' => (string) $e['message']],
            $db->all('SELECT type, created_at, message FROM {events} WHERE type IN (?, ?, ?) AND created_at >= ? AND created_at < ? ORDER BY id', [...self::UPDATE_EVENTS, $from, $to]));
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

        return [
            'month' => $monthStart->format('Y-m'),
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
        ];
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
            $footerNote = t('Sent once a month by the website %s. Switch it off or change the recipients in Settings → Mail.', $siteName);
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

        return Language::runWith(Language::defaults($s), fn (): array => self::render(self::build($app, $monthStart), $s, self::siteUrl($app)), 'admin-');
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
            $app->settings()->set('report_last_month', $month);
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
