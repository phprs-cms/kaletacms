<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Demo;
use Kaleta\Core\Events;
use Kaleta\Core\Language;
use Kaleta\Core\Mail;
use Kaleta\Core\Settings;

/**
 * Alert rules of the fleet console (3.17, Fleet → Alerts and digest): which states of a site e-mail the owner, from what
 * threshold, to whom, and how often.
 *
 *  - Each kind (KINDS) is on or off and has a threshold where it makes sense; the defaults are what the console did before
 *    3.17 – an e-mail when a site is down or stopped reporting – so nothing changes until the owner changes it.
 *  - The background job fleet_alerts (every 5 minutes, after the uptime check) looks at every site's current state
 *    (matches – pure), drops what was sent for the same site and kind within the quiet hours (quiet – pure) and sends one
 *    e-mail with the rest through Core\Mail; each alert sent is the event fleet.alert_sent (site and kind), which is also
 *    what the quiet hours are counted from. A state that lasts is sent again after the quiet hours.
 *  - Recipients: fleet_alert_recipients, otherwise alerts_email, otherwise the site e-mail; alerts_enabled switches these
 *    e-mails off together with the other alert e-mails.
 * Core\Alerts leaves the events fleet.site_down and fleet.site_silent to this class (OWN_EVENTS). The e-mail carries site
 * names, addresses and plain links to the console – never anything a site's visitors wrote.
 */
final class AlertRules
{
    /**
     * kind => [on by default, default threshold (null = none), label, threshold label, min, max]. The default thresholds are
     * the console's own (Console::THRESHOLDS) – the same the inbox and a site's attention use.
     */
    public const array KINDS = [
        'site_down' => [true, null, 'Site down', '', 0, 0],
        'site_silent' => [true, Console::THRESHOLDS['site_silent'], 'Site stopped reporting', 'hours without a report', 2, 720],
        'certificate' => [false, Console::THRESHOLDS['certificate'], 'Certificate ends soon', 'days before', 1, 90],
        'domain' => [false, Console::THRESHOLDS['domain'], 'Domain registration ends soon', 'days before', 1, 365],
        'update_failed' => [false, null, 'Update failed', '', 0, 0],
        'security_spike' => [false, Console::THRESHOLDS['security_spike'], 'Failed sign-ins (security spike)', 'failed sign-ins in 7 days', 5, 100000],
        'requests_waiting' => [false, Console::THRESHOLDS['requests_waiting'], 'Requests waiting', 'hours the oldest waits', 1, 720],
    ];

    /** The console events these rules send (Core\Alerts does not send them again). */
    public const array OWN_EVENTS = ['fleet.site_down', 'fleet.site_silent'];

    public const int QUIET_DEFAULT = 24;

    public const int QUIET_MAX = 720;

    /**
     * The rules in force: every kind with on and its threshold, from the setting fleet_alert_rules over the defaults.
     *
     * @return array<string, array{on: bool, threshold: ?int}>
     */
    public static function rules(Settings $s): array
    {
        $saved = json_decode($s->get('fleet_alert_rules'), true);

        return self::sanitize(is_array($saved) ? $saved : []);
    }

    /**
     * Rules from any input (the form, the stored JSON): known kinds only, thresholds within their bounds, the defaults for
     * what is missing. Pure.
     *
     * @param array<mixed> $input kind => ['on' => bool|'1', 'threshold' => int|string]
     * @return array<string, array{on: bool, threshold: ?int}>
     */
    public static function sanitize(array $input): array
    {
        $out = [];
        foreach (self::KINDS as $kind => [$on, $threshold, , , $min, $max]) {
            $given = is_array($input[$kind] ?? null) ? $input[$kind] : null;
            $value = $given['threshold'] ?? null;
            $out[$kind] = [
                'on' => $given === null ? $on : in_array($given['on'] ?? false, [true, 1, '1', 'on'], true),
                'threshold' => $threshold === null ? null : (is_numeric($value) ? max($min, min($max, (int) $value)) : $threshold),
            ];
        }

        return $out;
    }

    /**
     * The thresholds in force by kind (on or off – a rule that is off still says from when a state is a problem): what the
     * owner set in Fleet → Alerts and digest, otherwise Console::THRESHOLDS. The inbox ranks by them (Fleet\Inbox).
     *
     * @return array<string, int> site_silent (hours) | certificate, domain (days) | security_spike (count) | requests_waiting (hours)
     */
    public static function thresholds(Settings $s): array
    {
        return array_filter(array_map(static fn (array $rule): ?int => $rule['threshold'], self::rules($s)), 'is_int') + Console::THRESHOLDS;
    }

    /** The quiet hours: the same alert for the same site is not sent again sooner. */
    public static function quietHours(Settings $s): int
    {
        return max(1, min(self::QUIET_MAX, $s->int('fleet_alert_quiet_hours') ?: self::QUIET_DEFAULT));
    }

    /**
     * Where the alerts go: fleet_alert_recipients, otherwise alerts_email, otherwise the site e-mail.
     *
     * @return list<string>
     */
    public static function recipients(Settings $s): array
    {
        $list = array_values(array_filter(array_unique(preg_split('/[\s,;]+/', $s->get('fleet_alert_recipients')) ?: []),
            static fn (string $e): bool => $e !== '' && filter_var($e, FILTER_VALIDATE_EMAIL) !== false));
        if ($list === []) {
            $fallback = $s->get('alerts_email') !== '' ? $s->get('alerts_email') : $s->get('site_email');
            $list = $fallback !== '' ? [$fallback] : [];
        }

        return array_slice($list, 0, 10);
    }

    /**
     * Which rules a site matches right now, with a short detail in English (translated where shown). Pure.
     *
     * @param array<string, mixed> $site a row of ka_fleet_sites
     * @param array<string, array{on: bool, threshold: ?int}> $rules
     * @return list<array{kind: string, detail: string}>
     */
    public static function matches(array $site, array $rules, int $now): array
    {
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
        $beat = is_array($beat) ? $beat : [];
        $on = fn (string $kind): bool => $rules[$kind]['on'] ?? false;
        $limit = fn (string $kind): int => (int) ($rules[$kind]['threshold'] ?? self::KINDS[$kind][1]);
        $out = [];
        if ($on('site_down') && $site['up'] !== null && (int) $site['up'] === 0) {
            $out[] = ['kind' => 'site_down', 'detail' => (int) ($site['up_status'] ?? 0) > 0 ? 'HTTP ' . (int) $site['up_status'] : 'no answer'];
        }
        $seen = $site['last_seen'] ?? null;
        $since = $seen !== null ? (int) strtotime((string) $seen) : (int) strtotime((string) ($site['paired_at'] ?? 'now'));
        if ($on('site_silent') && $now - $since > $limit('site_silent') * 3600) {
            $out[] = ['kind' => 'site_silent', 'detail' => $seen !== null ? 'last report ' . substr((string) $seen, 0, 16) : 'no report yet'];
        }
        // every value of the stored heartbeat read through Console's bounds (N317-1): a site's odd report never stops the rules
        $watch = Console::part($beat, 'domain_watch');
        foreach (['certificate' => 'certificate', 'domain' => 'domain'] as $kind => $part) {
            $days = Console::daysLeft($watch[$part] ?? null, $now);
            if ($on($kind) && empty($watch['local']) && $days !== null && $days < $limit($kind)) {
                $out[] = ['kind' => $kind, 'detail' => $days < 0 ? 'ended' : $days . ' days left'];
            }
        }
        if ($on('update_failed') && !empty($beat['update_problem'])) {
            // the site's own words, on one line (N317-2): it must not write lines of its own into the e-mail
            $out[] = ['kind' => 'update_failed', 'detail' => Console::oneLine($beat['update_problem'], 120)];
        }
        $security = Console::part($beat, 'security_7_days');
        $failed = Console::count($security['failed_sign_ins'] ?? null) ?? 0;
        $reuse = Console::count(Console::part($security, 'events')['security.token_reuse'] ?? null) ?? 0;
        if ($on('security_spike') && ($failed >= $limit('security_spike') || $reuse > 0)) {
            $out[] = ['kind' => 'security_spike', 'detail' => $failed . ' failed sign-ins in 7 days' . ($reuse > 0 ? ', a reused token' : '')];
        }
        $waiting = Console::count(Console::part($beat, 'requests')['oldest_waiting_hours'] ?? null);
        if ($on('requests_waiting') && $waiting !== null && $waiting > $limit('requests_waiting')) {
            $out[] = ['kind' => 'requests_waiting', 'detail' => 'the oldest waits ' . $waiting . ' hours'];
        }

        return $out;
    }

    /**
     * The alerts not sent for the same site and kind within the quiet hours. Pure.
     *
     * @param list<array{site: int, kind: string, detail: string}> $alerts
     * @param array<string, int> $lastSent "site:kind" => the Unix time it was last sent
     * @return list<array{site: int, kind: string, detail: string}>
     */
    public static function quiet(array $alerts, array $lastSent, int $quietHours, int $now): array
    {
        return array_values(array_filter($alerts, fn (array $a): bool => $now - ($lastSent[$a['site'] . ':' . $a['kind']] ?? 0) >= $quietHours * 3600));
    }

    /**
     * When each site and kind was last sent, within the given hours (from the events fleet.alert_sent).
     *
     * @return array<string, int> "site:kind" => Unix time
     */
    public static function lastSent(\Kaleta\Core\Db $db, int $hours): array
    {
        $out = [];
        foreach ($db->all("SELECT created_at, data FROM {events} WHERE type = 'fleet.alert_sent' AND created_at > ? ORDER BY id", [date('Y-m-d H:i:s', time() - $hours * 3600)]) as $row) {
            $data = json_decode((string) $row['data'], true);
            if (is_array($data) && is_int($data['site'] ?? null) && is_string($data['kind'] ?? null)) {
                $out[$data['site'] . ':' . $data['kind']] = (int) strtotime((string) $row['created_at']);
            }
        }

        return $out;
    }

    /**
     * The background job fleet_alerts: one e-mail with what matches and was not sent within the quiet hours. A site whose
     * rules fail is logged and skipped (3.17, N317-1) – the alerts of every other site, "site down" too, still go out.
     */
    public static function run(App $app): string
    {
        $s = $app->settings();
        $db = $app->db();
        if (!$s->bool('alerts_enabled') || Demo::active()) {
            return 'off';
        }
        $rules = self::rules($s);
        $now = time();
        $alerts = [];
        $names = [];
        foreach (Console::sites($db) as $site) {
            $names[(int) $site['id']] = $site;
            try {
                foreach (self::matches($site, $rules, $now) as $match) {
                    $alerts[] = ['site' => (int) $site['id']] + $match;
                }
            } catch (\Throwable $e) {
                error_log('Fleet alerts: site ' . (int) $site['id'] . ' – ' . $e->getMessage());
            }
        }
        $quietHours = self::quietHours($s);
        $alerts = self::quiet($alerts, self::lastSent($db, $quietHours), $quietHours, $now);
        if ($alerts === []) {
            return 'nothing new';
        }
        $recipients = self::recipients($s);
        if ($recipients === []) {
            return 'no address';
        }
        [$subject, $text] = self::message($s, $alerts, $names, self::consoleUrl($app));
        foreach ($recipients as $recipient) {
            Mail::send($s, $recipient, $subject, $text);
        }
        foreach ($alerts as $alert) {
            $site = $names[$alert['site']];
            Events::record($db, 'fleet.alert_sent', 'info', t('Alert sent: %s – %s.', Console::oneLine($site['url'], 255), t(self::KINDS[$alert['kind']][2])), ['site' => $alert['site'], 'kind' => $alert['kind']]);
        }

        return 'sent ' . count($alerts);
    }

    /**
     * The subject and the plain text, in the console's default language.
     *
     * @param list<array{site: int, kind: string, detail: string}> $alerts
     * @param array<int, array<string, mixed>> $sites rows of ka_fleet_sites by id
     * @return array{0: string, 1: string}
     */
    public static function message(Settings $s, array $alerts, array $sites, string $consoleUrl): array
    {
        return Language::runWith(Language::defaults($s), function () use ($s, $alerts, $sites, $consoleUrl): array {
            $bySite = [];
            foreach ($alerts as $alert) {
                $bySite[$alert['site']][] = $alert;
            }
            $lines = [];
            foreach ($bySite as $id => $siteAlerts) {
                $site = $sites[$id] ?? ['name' => '', 'url' => ''];
                // what the site sent – its name, its address, its update message – on one line each (N317-2)
                $name = Console::oneLine($site['name'] ?? '', Console::NAME_LENGTH);
                $lines[] = ($name !== '' ? $name . ' – ' : '') . Console::oneLine($site['url'] ?? '', 255);
                foreach ($siteAlerts as $alert) {
                    $detail = Console::oneLine(self::detail($alert['detail']), 200);
                    $lines[] = '  – ' . t(self::KINDS[$alert['kind']][2]) . ($detail !== '' ? ': ' . $detail : '');
                }
                $lines[] = '  ' . $consoleUrl . '/admin.php?module=fleet&action=detail&id=' . $id;
                $lines[] = '';
            }
            $subject = t('%s: %d alert(s) about your sites', $s->get('site_name'), count($alerts));
            $text = t('The fleet console noticed something on your sites:') . "\n\n" . implode("\n", $lines)
                . t('The same alert for the same site is not sent again within %d hours. Change the rules in Fleet → Alerts and digest:', self::quietHours($s)) . ' '
                . $consoleUrl . '/admin.php?module=fleet&action=alerts';

            return [$subject, $text];
        }, 'admin-');
    }

    /** The detail of an alert in the current language (the English phrases matches() writes). */
    private static function detail(string $detail): string
    {
        return match (true) {
            $detail === 'no answer', $detail === 'no report yet', $detail === 'ended' => t($detail),
            preg_match('/^last report (.+)$/', $detail, $m) === 1 => t('last report %s', $m[1]),
            preg_match('/^(-?\d+) days left$/', $detail, $m) === 1 => t('%d days left', (int) $m[1]),
            preg_match('/^(\d+) failed sign-ins in 7 days(, a reused token)?$/', $detail, $m) === 1 => t('%d failed sign-ins in 7 days', (int) $m[1]) . (($m[2] ?? '') !== '' ? ', ' . t('a reused token') : ''),
            preg_match('/^the oldest waits (\d+) hours$/', $detail, $m) === 1 => t('the oldest waits %d hours', (int) $m[1]),
            default => $detail, // HTTP 503, the site's own update message (English)
        };
    }

    /** The console's address for the links in the e-mails (the setting, not the Host header). */
    public static function consoleUrl(App $app): string
    {
        return rtrim($app->settings()->get('site_url') ?: $app->request->origin(), '/');
    }
}
