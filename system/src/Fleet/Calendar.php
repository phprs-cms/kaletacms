<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Auth;
use Kaleta\Core\Clock;
use Kaleta\Core\Db;
use Kaleta\Core\Demo;
use Kaleta\Core\Language;
use Kaleta\Core\Mail;
use Kaleta\Core\Response;
use Kaleta\Core\Settings;

/**
 * The expiry calendar of the fleet console (3.30): when the certificate and the domain registration of each site end (from
 * the domain watch each site runs itself, Core\DomainWatch, sent in its heartbeat), on one month view and in a list, and
 * whether each site's backup recovery key is saved (no date – a list of its own). The thresholds are those of the health
 * checks (Fleet\Checks): a certificate is red under 7 days and amber under Console::CERTIFICATE_DAYS, a domain red under 14
 * and amber under Console::DOMAIN_DAYS.
 *
 *  - Reminders (job fleet_expiry, hourly): N days before each date one e-mail to the console's alert recipients
 *    (AlertRules::recipients, only while alerts_enabled) and a notification in the bell of each administrator (Core\Inbox,
 *    kind fleet_expiry). The days differ by kind (REMINDER_SETTINGS, 3.31.1): a domain registration by the setting
 *    fleet_expiry_reminders (default 30 and 7 – a console that saved its own keeps it), a certificate by
 *    fleet_certificate_reminders (default 14 and 7, as the certificate check's amber and red; certificates usually renew
 *    30 days before they end, so a 30-day reminder was noise). Each date is reminded once per "days before" (table
 *    ka_fleet_expiry_reminders – written before anything is sent, so two runs at once never send twice); a date found when
 *    several thresholds have passed already is reminded once, at the smallest; a threshold counts as reminded when any
 *    reminder of the date went out within it (covered() – a date reminded at 30 days before 3.31.1 while 12 days were left
 *    is not reminded again at 14). A renewed certificate has a new date.
 *    3.31: an e-mail that fails (the mail server down) is not lost – Mail::send keeps it, per recipient, in the mail queue
 *    (ka_posta) and the job "mail" retries it after 5, 30, 120 and 720 minutes (Mail::RETRY_DELAYS), exactly once when it
 *    goes through and only to the address it was meant for; the reminder stays recorded, so neither the bell nor the
 *    e-mail is repeated by this job. A failure the queue gives up on is an event "mail.failed" in System status.
 *  - A calendar feed (.ics) per administrator: GET /fleet/calendar.ics?token=… with a secret only its owner saw (stored as
 *    a hash, setting fleet_calendar_feeds), revoked in one click; the feed has the dates and plain links to the console.
 * Dates, kinds and site names only – never anything a site's visitors wrote. Everything apart from run(), feed() and the
 * feed tokens is pure.
 */
final class Calendar
{
    /** kind => label (English, through t()). */
    public const array KINDS = ['certificate' => 'Certificate', 'domain' => 'Domain registration'];

    /** Under this many days a date is red (bad), under WARN_DAYS amber – as Fleet\Checks. */
    public const array BAD_DAYS = ['certificate' => 7, 'domain' => 14];
    public const array WARN_DAYS = ['certificate' => Console::CERTIFICATE_DAYS, 'domain' => Console::DOMAIN_DAYS];

    /** The setting of the reminder days of each kind (3.31.1; a certificate had the domains' days before). */
    public const array REMINDER_SETTINGS = ['certificate' => 'fleet_certificate_reminders', 'domain' => 'fleet_expiry_reminders'];

    /** At most this many reminder thresholds, each 0–MAX_REMINDER days before. */
    public const int MAX_REMINDERS = 5;
    public const int MAX_REMINDER = 365;

    /** Sent reminders are kept this many days. */
    public const int KEEP_DAYS = 400;

    /** How many dates the MCP tool returns at most. */
    public const int MCP_LIMIT = 300;

    /** The states of a recovery key in the calendar's list, the most urgent first. */
    public const array KEY_STATES = ['bad', 'warn', 'unknown', 'ok'];

    /** bad | warn | ok for a date that many days ahead (negative = passed). Pure. */
    public static function state(string $kind, int $days): string
    {
        return match (true) {
            $days < self::BAD_DAYS[$kind] => 'bad',
            $days < self::WARN_DAYS[$kind] => 'warn',
            default => 'ok',
        };
    }

    /**
     * Every known date of the sites, the soonest first: site, kind, the date ('Y-m-d'), the days left, the state and the
     * plain link to the screen of the site that shows it. A site on a local address, a failed check or a site that sends no
     * domain watch has none. Pure.
     *
     * @param list<array<string, mixed>> $sites rows of ka_fleet_sites
     * @return list<array{site_id: int, site: string, url: string, customer_id: ?int, kind: string, date: string, days: int, state: string, link: string}>
     */
    public static function dates(array $sites, int $now): array
    {
        $out = [];
        foreach ($sites as $site) {
            $beat = Console::storedBeat($site);
            $watch = Console::part($beat, 'domain_watch');
            if ($watch === [] || !empty($watch['local'])) {
                continue;
            }
            $url = Console::url(rtrim((string) ($site['url'] ?? ''), '/')) ?? '';
            $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
            foreach (array_keys(self::KINDS) as $kind) {
                $part = is_array($watch[$kind] ?? null) ? $watch[$kind] : null;
                $date = self::dateOf($part, is_int($watch['checked'] ?? null) ? $watch['checked'] : null);
                if ($date === null) {
                    continue;
                }
                $days = (int) Console::daysLeft(['expires' => $date], $now);
                $out[] = ['site_id' => (int) ($site['id'] ?? 0), 'site' => $name !== '' ? $name : $url, 'url' => $url, 'customer_id' => Console::customerIdOf($site) ?: null,
                    'kind' => $kind, 'date' => $date, 'days' => $days, 'state' => self::state($kind, $days), 'link' => Checks::link($url, $kind)];
            }
        }
        usort($out, static fn (array $a, array $b): int => [$a['date'], mb_strtolower($a['site']), $a['site_id'], $a['kind']] <=> [$b['date'], mb_strtolower($b['site']), $b['site_id'], $b['kind']]);

        return $out;
    }

    /**
     * The date of a certificate or domain part: its expires day, otherwise the day of the check + the days the site
     * counted then; null when neither is known. Pure.
     *
     * @param array<array-key, mixed>|null $part
     */
    private static function dateOf(?array $part, ?int $checked): ?string
    {
        if ($part === null) {
            return null;
        }
        if (($date = Console::date($part['expires'] ?? null)) !== null) {
            return $date->format('Y-m-d');
        }
        if (is_int($part['days'] ?? null) && $checked !== null && abs($part['days']) < 100000) {
            return date('Y-m-d', $checked + $part['days'] * 86400);
        }

        return null;
    }

    /**
     * The recovery key of each site's encrypted off-site copies (3.28, Core\RecoveryKey), the most urgent first: bad when
     * the copies wait for it, warn when it is not saved while copies are on, ok when saved (with the day, when the site sends
     * it), unknown before 3.29. Sites with off-site copies off are left out. Pure.
     *
     * @param list<array<string, mixed>> $sites
     * @return list<array{site_id: int, site: string, url: string, customer_id: ?int, state: string, key: ?string, since: ?string, days: ?int, link: string}>
     */
    public static function keys(array $sites, int $now): array
    {
        $out = [];
        foreach ($sites as $site) {
            $beat = Console::storedBeat($site);
            if ($beat === []) {
                continue;
            }
            $offsite = is_array($beat['offsite'] ?? null) ? $beat['offsite'] : null;
            if ($offsite === null && ($beat['offsite_backup'] ?? null) === false || ($offsite['status'] ?? '') === 'off') {
                continue;
            }
            $key = $offsite['recovery_key'] ?? null;
            $since = is_string($beat['recovery_key_since'] ?? null) ? $beat['recovery_key_since'] : null;
            $state = match (true) {
                $offsite === null => 'unknown',
                $offsite['status'] === 'paused' => 'bad',
                $key === 'confirmed' => 'ok',
                default => 'warn',
            };
            $url = Console::url(rtrim((string) ($site['url'] ?? ''), '/')) ?? '';
            $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
            $out[] = ['site_id' => (int) ($site['id'] ?? 0), 'site' => $name !== '' ? $name : $url, 'url' => $url, 'customer_id' => Console::customerIdOf($site) ?: null,
                'state' => $state, 'key' => is_string($key) ? $key : null, 'since' => $state === 'ok' ? $since : null,
                'days' => $state === 'ok' && ($day = Console::date($since)) !== null ? max(0, intdiv($now - $day->getTimestamp(), 86400)) : null,
                'link' => Checks::link($url, 'offsite_backup')];
        }
        $order = array_flip(self::KEY_STATES);
        usort($out, static fn (array $a, array $b): int => [$order[$a['state']], mb_strtolower($a['site']), $a['site_id']] <=> [$order[$b['state']], mb_strtolower($b['site']), $b['site_id']]);

        return $out;
    }

    /**
     * A month of the calendar: weeks from Monday, each day with its dates. $month 'Y-m' (anything else: the month of $now).
     * Pure.
     *
     * @param list<array<string, mixed>> $events dates()
     * @return array{month: string, previous: string, next: string, weeks: list<list<array{date: string, day: int, in_month: bool, today: bool, events: list<array<string, mixed>>}>>}
     */
    public static function month(array $events, string $month, int $now): array
    {
        $first = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', $month) === 1 ? \DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01') : false;
        if ($first === false || (int) $first->format('Y') < 2000 || (int) $first->format('Y') > 2100) {
            $first = (new \DateTimeImmutable('@' . $now))->setTimezone(new \DateTimeZone(date_default_timezone_get()))->modify('first day of this month')->setTime(0, 0);
        }
        $byDay = [];
        foreach ($events as $e) {
            $byDay[$e['date']][] = $e;
        }
        $today = date('Y-m-d', $now);
        $day = $first->modify('-' . ((int) $first->format('N') - 1) . ' days');
        $last = $first->modify('last day of this month');
        $weeks = [];
        while ($day <= $last) {
            $week = [];
            for ($i = 0; $i < 7; $i++) {
                $d = $day->format('Y-m-d');
                $week[] = ['date' => $d, 'day' => (int) $day->format('j'), 'in_month' => $day->format('Y-m') === $first->format('Y-m'), 'today' => $d === $today, 'events' => $byDay[$d] ?? []];
                $day = $day->modify('+1 day');
            }
            $weeks[] = $week;
        }

        return ['month' => $first->format('Y-m'), 'previous' => $first->modify('-1 month')->format('Y-m'), 'next' => $first->modify('+1 month')->format('Y-m'), 'weeks' => $weeks];
    }

    /**
     * The reminder thresholds from their text ("30, 7"): whole numbers 0–MAX_REMINDER, at most MAX_REMINDERS, the largest
     * first; null when the text is not such a list ('' = no reminders: []). Pure.
     *
     * @return list<int>|null
     */
    public static function parseReminders(string $text): ?array
    {
        $parts = array_values(array_filter(preg_split('/[\s,;]+/', trim($text)) ?: [], static fn (string $p): bool => $p !== ''));
        $out = [];
        foreach ($parts as $p) {
            if (preg_match('/^\d{1,3}$/D', $p) !== 1 || (int) $p > self::MAX_REMINDER) {
                return null;
            }
            $out[(int) $p] = (int) $p;
        }
        if (count($out) > self::MAX_REMINDERS) {
            return null;
        }
        rsort($out); // rsort re-indexes: a list

        return $out;
    }

    /** @return list<int> the thresholds in force for a kind (its setting, the default when it is not a valid list); domain by default */
    public static function reminders(Settings $s, string $kind = 'domain'): array
    {
        $key = self::REMINDER_SETTINGS[$kind] ?? self::REMINDER_SETTINGS['domain'];

        return self::parseReminders($s->get($key)) ?? self::parseReminders(Settings::DEFAULTS[$key]) ?? [];
    }

    /**
     * The thresholds in force of every kind (3.31.1).
     *
     * @return array<string, list<int>> kind => thresholds
     */
    public static function remindersByKind(Settings $s): array
    {
        $out = [];
        foreach (array_keys(self::REMINDER_SETTINGS) as $kind) {
            $out[$kind] = self::reminders($s, $kind);
        }

        return $out;
    }

    /**
     * The reminders due: for each date, the thresholds it has passed (days left ≤ N); one reminder at the smallest of them
     * when that one was not sent – with every passed threshold, so the larger ones are never sent later. Pure.
     *
     * @param list<array<string, mixed>> $events dates()
     * @param list<int> $thresholds
     * @param array<string, true> $sent "site:kind:date:N" of the reminders sent
     * @return list<array{event: array<string, mixed>, days_before: int, passed: list<int>}>
     */
    public static function due(array $events, array $thresholds, array $sent): array
    {
        $out = [];
        foreach ($events as $e) {
            $passed = array_values(array_filter($thresholds, static fn (int $n): bool => $e['days'] <= $n));
            if ($passed === []) {
                continue;
            }
            $smallest = min($passed);
            if (!isset($sent[self::sentKey($e, $smallest)])) {
                $out[] = ['event' => $e, 'days_before' => $smallest, 'passed' => $passed];
            }
        }

        return $out;
    }

    /**
     * due() with each kind's own thresholds (3.31.1): a certificate by its days, a domain by its. Pure.
     *
     * @param list<array<string, mixed>> $events dates()
     * @param array<string, list<int>> $byKind kind => thresholds
     * @param array<string, true> $sent
     * @return list<array{event: array<string, mixed>, days_before: int, passed: list<int>}>
     */
    public static function dueByKind(array $events, array $byKind, array $sent): array
    {
        $out = [];
        foreach ($events as $e) {
            foreach (self::due([$e], $byKind[$e['kind']] ?? [], $sent) as $d) {
                $out[] = $d;
            }
        }

        return $out;
    }

    /**
     * The thresholds already reminded in effect (3.31.1), as sent keys: a reminder of a date that went out while N or fewer
     * days were left covers the threshold N of that date, whichever threshold it was recorded at – so changing the days
     * (the certificates' 30 before 3.31.1 to 14) never reminds a date again within days of the last reminder. Pure.
     *
     * @param list<array<string, mixed>> $records rows of ka_fleet_expiry_reminders (site_id, kind, expires, sent_at)
     * @param array<string, list<int>> $byKind kind => thresholds in force
     * @return array<string, true> "site:kind:date:N"
     */
    public static function covered(array $records, array $byKind): array
    {
        $out = [];
        foreach ($records as $r) {
            $date = substr((string) $r['expires'], 0, 10);
            $end = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $sent = \DateTimeImmutable::createFromFormat('!Y-m-d', substr((string) $r['sent_at'], 0, 10));
            if ($end === false || $sent === false) {
                continue;
            }
            $left = (int) round(($end->getTimestamp() - $sent->getTimestamp()) / 86400);
            foreach ($byKind[(string) $r['kind']] ?? [] as $n) {
                if ($left <= $n) {
                    $out[self::sentKey(['site_id' => $r['site_id'], 'kind' => $r['kind'], 'date' => $date], $n)] = true;
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $e */
    private static function sentKey(array $e, int $n): string
    {
        return $e['site_id'] . ':' . $e['kind'] . ':' . $e['date'] . ':' . $n;
    }

    /**
     * The background job fleet_expiry (hourly): the reminders due, recorded first, then the bell of each administrator and
     * one e-mail to the alert recipients. Never on a demo.
     */
    public static function run(App $app): string
    {
        $s = $app->settings();
        $db = $app->db();
        if (Demo::active()) {
            return 'off';
        }
        $thresholds = self::remindersByKind($s);
        try {
            $db->run('DELETE FROM {fleet_expiry_reminders} WHERE sent_at < ? OR site_id NOT IN (SELECT id FROM {fleet_sites})', [Clock::at('-' . self::KEEP_DAYS . ' days')]);
            $records = $db->all('SELECT site_id, kind, expires, days_before, sent_at FROM {fleet_expiry_reminders}');
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
            return 'before the migration';
        }
        $sent = self::covered($records, $thresholds);
        foreach ($records as $r) {
            $sent[$r['site_id'] . ':' . $r['kind'] . ':' . substr((string) $r['expires'], 0, 10) . ':' . $r['days_before']] = true;
        }
        if ($thresholds['certificate'] === [] && $thresholds['domain'] === []) {
            return 'no reminders set';
        }
        $sites = [];
        foreach (Console::sites($db) as $site) {
            $sites[(int) $site['id']] = $site;
        }
        $fresh = [];
        // 3.39: a certificate or a domain the operator accepted (Fleet\Acceptances) gets no reminder while the acceptance holds
        $accepted = Acceptances::active(array_values($sites), Acceptances::load($db), time(), Health::context($app));
        $events = array_values(array_filter(self::dates(array_values($sites), time()), fn (array $e): bool => !isset($accepted[Acceptances::key((int) $e['site_id'], (string) $e['kind'])])));
        foreach (self::dueByKind($events, $thresholds, $sent) as $d) {
            $e = $d['event'];
            $first = 0;
            foreach ($d['passed'] as $n) {
                $inserted = $db->run('INSERT IGNORE INTO {fleet_expiry_reminders} (site_id, kind, expires, days_before, sent_at) VALUES (?, ?, ?, ?, ?)',
                    [$e['site_id'], $e['kind'], $e['date'], $n, Clock::now()])->rowCount();
                $first += $n === $d['days_before'] ? $inserted : 0;
            }
            if ($first > 0) { // another run at the same moment recorded it first: it sends it
                $fresh[] = $d;
            }
        }
        if ($fresh === []) {
            return 'nothing due';
        }
        $admins = array_map('intval', array_column($db->all('SELECT idu FROM {uzivatele} WHERE admin = ? AND blokovat = 0', [Auth::ADMIN]), 'idu'));
        $noted = [];
        Language::runWith(Language::defaults($s), function () use ($db, $fresh, $admins, &$noted): void {
            foreach ($fresh as $d) {
                foreach ($admins as $uid) {
                    $note = \Kaleta\Core\Inbox::record($db, $uid, 'fleet_expiry', 'fleet_site:' . $d['event']['site_id'], null, self::line($d['event']));
                    if ($note !== null) {
                        $noted[] = $note['id'];
                    }
                }
            }
        }, 'admin-');
        \Kaleta\Core\Inbox::markEmailed($db, $noted); // the e-mail below says it; the daily summary must not say it again
        $recipients = $s->bool('alerts_enabled') ? AlertRules::recipients($s) : [];
        $queued = 0;
        if ($recipients !== []) {
            [$subject, $text] = self::message($s, $fresh, AlertRules::consoleUrl($app));
            foreach ($recipients as $recipient) {
                // a failed send waits in the mail queue and the job "mail" retries it (never queueOnFailure false here)
                $queued += Mail::send($s, $recipient, $subject, $text) ? 0 : 1;
            }
        }

        return 'reminded ' . count($fresh) . ($recipients === [] ? ' (bell only)' : '') . ($queued > 0 ? ', e-mails queued for a retry: ' . $queued : '');
    }

    /** "Certificate of Acme ends on 1 December 2026 (in 7 days)." in the current language. Pure apart from the dictionaries. @param array<string, mixed> $e */
    public static function line(array $e): string
    {
        $certificate = $e['kind'] === 'certificate';

        return match (true) {
            $e['days'] < 0 => $certificate ? t('The certificate of %s ended on %s.', $e['site'], format_date($e['date'])) : t('The domain registration of %s ended on %s.', $e['site'], format_date($e['date'])),
            $e['days'] === 0 => $certificate ? t('The certificate of %s ends today.', $e['site']) : t('The domain registration of %s ends today.', $e['site']),
            default => $certificate ? t('The certificate of %s ends on %s (in %d days).', $e['site'], format_date($e['date']), $e['days'])
                : t('The domain registration of %s ends on %s (in %d days).', $e['site'], format_date($e['date']), $e['days']),
        };
    }

    /**
     * The e-mail: subject and plain text in the console's default language, each date on its line with a plain link to
     * the site in the console and to the calendar.
     *
     * @param list<array{event: array<string, mixed>, days_before: int, passed: list<int>}> $due
     * @return array{0: string, 1: string}
     */
    public static function message(Settings $s, array $due, string $consoleUrl): array
    {
        return Language::runWith(Language::defaults($s), function () use ($s, $due, $consoleUrl): array {
            $lines = [];
            foreach ($due as $d) {
                $lines[] = '– ' . Console::oneLine(self::line($d['event']), 400);
                $lines[] = '  ' . $consoleUrl . '/admin.php?module=fleet&action=detail&id=' . (int) $d['event']['site_id'];
            }
            $subject = t('%s: %d dates of your sites are coming up', $s->get('site_name'), count($due));
            $text = t('These dates of your sites are coming up:') . "\n\n" . implode("\n", $lines) . "\n\n"
                . t('Each date is reminded once – a certificate %s days before, a domain registration %s days before. Change it in Fleet → Calendar:',
                    implode(', ', self::reminders($s, 'certificate')) ?: '–', implode(', ', self::reminders($s, 'domain')) ?: '–') . ' '
                . $consoleUrl . '/admin.php?module=fleet&action=calendar';

            return [$subject, $text];
        }, 'admin-');
    }

    /**
     * The dates as an iCalendar feed (RFC 5545): an all-day event per date, the summary in the console's language, a plain
     * link to the site in the console. Pure apart from the dictionaries.
     *
     * @param list<array<string, mixed>> $events dates()
     */
    public static function ics(array $events, string $calendarName, string $consoleUrl, int $now): string
    {
        $host = (string) (parse_url($consoleUrl, PHP_URL_HOST) ?: 'kaleta');
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Kaleta//Fleet console//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . self::icsText($calendarName)];
        foreach ($events as $e) {
            $start = \DateTimeImmutable::createFromFormat('!Y-m-d', $e['date']);
            if ($start === false) {
                continue;
            }
            $lines = [...$lines, 'BEGIN:VEVENT', 'UID:fleet-' . (int) $e['site_id'] . '-' . $e['kind'] . '-' . $start->format('Ymd') . '@' . $host, 'DTSTAMP:' . gmdate('Ymd\THis\Z', $now),
                'DTSTART;VALUE=DATE:' . $start->format('Ymd'), 'DTEND;VALUE=DATE:' . $start->modify('+1 day')->format('Ymd'),
                'SUMMARY:' . self::icsText(($e['kind'] === 'certificate' ? t('Certificate ends') : t('Domain registration ends')) . ' – ' . $e['site']),
                'DESCRIPTION:' . self::icsText(self::line($e)), 'URL:' . self::icsText($consoleUrl . '/admin.php?module=fleet&action=detail&id=' . (int) $e['site_id']),
                'TRANSP:TRANSPARENT', 'END:VEVENT'];
        }
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::fold(...), $lines)) . "\r\n";
    }

    /** A text value of iCalendar: backslash, semicolon, comma and line breaks escaped, control characters dropped. */
    private static function icsText(string $text): string
    {
        $text = Console::oneLine($text, 500);

        return str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $text);
    }

    /** A content line folded at 75 octets (RFC 5545 §3.1), never inside a UTF-8 character. */
    private static function fold(string $line): string
    {
        $out = '';
        $current = '';
        foreach (mb_str_split($line) as $char) {
            if (strlen($current) + strlen($char) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $current;
                $current = '';
            }
            $current .= $char;
        }

        return $out === '' ? $current : $out . "\r\n " . $current;
    }

    /**
     * A new calendar link for an administrator (any earlier one stops working): the secret is returned once and stored
     * only as its hash. Token "<user id>-<32 hex>".
     */
    public static function newFeed(Settings $s, int $userId): string
    {
        $secret = bin2hex(random_bytes(16));
        $feeds = self::feeds($s);
        $feeds[(string) $userId] = hash('sha256', $secret);
        $s->set('fleet_calendar_feeds', (string) json_encode($feeds));

        return $userId . '-' . $secret;
    }

    /** Revokes an administrator's calendar link. */
    public static function revokeFeed(Settings $s, int $userId): void
    {
        $feeds = self::feeds($s);
        unset($feeds[(string) $userId]);
        $s->set('fleet_calendar_feeds', $feeds === [] ? '' : (string) json_encode($feeds));
    }

    public static function hasFeed(Settings $s, int $userId): bool
    {
        return isset(self::feeds($s)[(string) $userId]);
    }

    /** @return array<string, string> user id => sha256 of the secret */
    private static function feeds(Settings $s): array
    {
        $feeds = json_decode($s->get('fleet_calendar_feeds'), true);

        return is_array($feeds) ? array_filter($feeds, static fn (mixed $h, int|string $uid): bool => is_string($h) && preg_match('/^[0-9a-f]{64}$/D', $h) === 1 && (int) $uid > 0, ARRAY_FILTER_USE_BOTH) : [];
    }

    /** The administrator a calendar token belongs to (an active administrator with this link not revoked), null otherwise. */
    public static function feedUser(Settings $s, Db $db, string $token): ?int
    {
        if (preg_match('/^([1-9]\d{0,9})-([0-9a-f]{32})$/D', $token, $m) !== 1) {
            return null;
        }
        $hash = self::feeds($s)[$m[1]] ?? null;
        if ($hash === null || !hash_equals($hash, hash('sha256', $m[2]))) {
            return null;
        }

        return $db->value('SELECT 1 FROM {uzivatele} WHERE idu = ? AND admin = ? AND blokovat = 0', [(int) $m[1], Auth::ADMIN]) !== null ? (int) $m[1] : null;
    }

    /** GET /fleet/calendar.ics?token=… – the feed of an administrator's calendar link; 404 for anything else. */
    public static function feed(App $app, string $token): Response
    {
        $s = $app->settings();
        if (self::feedUser($s, $app->db(), $token) === null) {
            return new Response('Not found', 404, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        $consoleUrl = AlertRules::consoleUrl($app);
        $body = Language::runWith(Language::defaults($s), fn (): string => self::ics(self::dates(Console::sites($app->db()), time()), t('%s – sites calendar', $s->get('site_name')), $consoleUrl, time()), 'admin-');

        return new Response($body, 200, ['Content-Type' => 'text/calendar; charset=utf-8', 'Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff',
            'X-Robots-Tag' => 'noindex', 'Referrer-Policy' => 'no-referrer']);
    }
}
