<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\App;
use Kaleta\Core\Db;
use Kaleta\Core\Events;
use Kaleta\Core\Response;
use Kaleta\Core\Settings;
use Kaleta\Core\Updater;

/**
 * The console's side (2.9): a Kaleta install with the extension "fleet" keeps the sites paired with it.
 *
 *  - Pairing: the console makes a one-time code (24 hours); the site sends it with its public key, signed by that key.
 *  - Heartbeat: each site reports every hour, signed; an older or repeated heartbeat is refused. The console stores the
 *    last one and answers – signed by its own key – which version the site may install.
 *  - Staged updates: canary sites get a new version at once; the rest when every canary has run it for UPDATE_WAIT without
 *    errors, without being down and without a failed update (without canaries: UPDATE_WAIT after the console first saw
 *    the version). Security releases go to everyone at once. An administrator can allow a version by hand.
 *  - Uptime: the console asks each site's home page every 5 minutes; down twice in a row = the event fleet.site_down.
 *  - History (3.17, Fleet\History): each check and the day's state of each site are kept 400 days – trends, alert rules
 *    (Fleet\AlertRules), the weekly digest (Fleet\Digest) and the monthly report per customer (Fleet\Report) read it.
 * The console never calls into a site and holds no credentials for it: it only answers the sites.
 */
final class Console
{
    public const int CODE_HOURS = 24;
    public const int UPDATE_WAIT = 48 * 3600;
    public const int SILENT_AFTER = 26 * 3600;
    public const int DOWN_AFTER = 2;

    /** What a heartbeat may carry (Fleet\Heartbeat) – anything else is dropped. */
    private const array HEARTBEAT_KEYS = ['name', 'url', 'version', 'php', 'db_version', 'status', 'problems', 'jobs_failing', 'cron_last_run', 'last_backup', 'offsite_backup',
        'update_available', 'update_problem', 'auto_updates', 'update_channel', 'enquiries_unanswered', 'enquiries_7_days', 'visits_7_days', 'audit', 'problems_7_days', 'claude', 'kit_version',
        // 3.16: what waits for people – each has its own shape and bounds (cleanPart); a site before 3.16 sends none of them
        'requests', 'pending_review', 'enquiries_new_not_spam', 'bookings_pending', 'security_7_days', 'domain_watch', 'agent_runs', 'updates', 'report_month',
        // 3.17: the headline counts of the site's latest monthly report (Fleet\History::summary) for the fleet report
        'report_summary'];

    /** Reasons for attention => weight; the list on the console is sorted by the sum. */
    public const array REASONS = [
        'down' => 100, 'not_reporting' => 80, 'errors' => 60, 'update_failed' => 50, 'jobs_failing' => 40, 'certificate_expiring' => 40, 'no_backup' => 30,
        'domain_expiring' => 30, 'no_heartbeat' => 20, 'warnings' => 10, 'requests_waiting' => 10, 'sign_in_attacks' => 10, 'no_cron' => 5, 'enquiries' => 5,
        'bookings_to_confirm' => 5, 'update_available' => 5, 'audit' => 2,
    ];

    /** 3.16: a certificate or a domain registration ending within this many days asks for attention. */
    public const int CERTIFICATE_DAYS = 14;
    public const int DOMAIN_DAYS = 30;

    /** 3.16: failed sign-ins in 7 days from which a site asks for attention (someone is guessing passwords). */
    public const int SIGN_IN_SPIKE = 50;

    /** A new one-time pairing key (shown once). */
    public static function newPairingKey(App $app): string
    {
        $s = $app->settings();
        $code = bin2hex(random_bytes(16));
        $db = $app->db();
        $db->run('DELETE FROM {fleet_pairing} WHERE expires_at < ?', [\Kaleta\Core\Clock::at('-7 days')]);
        $db->insert('fleet_pairing', ['code_hash' => hash('sha256', $code), 'created_at' => date('Y-m-d H:i:s'), 'expires_at' => date('Y-m-d H:i:s', time() + self::CODE_HOURS * 3600)]);

        return Link::makeKey(rtrim($s->get('site_url'), '/'), $code, Keys::publicKey($s), $s->get('site_name'));
    }

    /** POST /fleet/pair */
    public static function pair(App $app, string $body, string $signature): Response
    {
        $d = json_decode($body, true);
        if (!is_array($d) || ($d['action'] ?? '') !== 'pair' || !is_string($d['code'] ?? null) || !is_string($d['public_key'] ?? null) || !Keys::isPublicKey($d['public_key'])
            || !is_string($d['url'] ?? null) || preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~iD', $d['url']) !== 1) {
            return Response::json(['error' => 'Not a pairing request.'], 400);
        }
        if (!Keys::verify($body, $signature, $d['public_key']) || abs(time() - (int) ($d['ts'] ?? 0)) > Link::MAX_SKEW) {
            return Response::json(['error' => 'The signature or the time of the request is not valid.'], 403);
        }
        $db = $app->db();
        $hash = hash('sha256', $d['code']);
        if ($db->value('SELECT 1 FROM {fleet_pairing} WHERE code_hash = ? AND used_at IS NULL AND expires_at > ?', [$hash, \Kaleta\Core\Clock::now()]) === null) {
            return Response::json(['error' => 'The pairing key is unknown, used or expired – make a new one on the console.'], 403);
        }
        $row = ['name' => mb_substr((string) ($d['name'] ?? ''), 0, 150), 'url' => mb_substr(rtrim($d['url'], '/'), 0, 255), 'manage_updates' => !empty($d['manage_updates']) ? 1 : 0,
            'version' => mb_substr((string) ($d['version'] ?? ''), 0, 30)];
        $existing = $db->value('SELECT id FROM {fleet_sites} WHERE public_key = ?', [$d['public_key']]);
        if ($existing !== null) {
            $id = (int) $existing; // the same site paired again (e.g. after it disconnected): its ring and history stay
            $db->update('fleet_sites', $row + ['last_ts' => 0], ['id' => $id]);
        } else {
            $id = $db->insert('fleet_sites', $row + ['public_key' => $d['public_key'], 'paired_at' => date('Y-m-d H:i:s'), 'version_since' => date('Y-m-d H:i:s')]);
        }
        $db->update('fleet_pairing', ['used_at' => date('Y-m-d H:i:s'), 'site_id' => $id], ['code_hash' => $hash]);
        Events::record($db, 'fleet.site_paired', 'info', t('%s was paired with the console.', $row['url']), ['site' => $id]);

        return self::signed($app->settings(), ['ok' => true, 'site_id' => $id, 'console_name' => $app->settings()->get('site_name')]);
    }

    /** POST /fleet/heartbeat */
    public static function heartbeat(App $app, string $body, string $signature): Response
    {
        $d = json_decode($body, true);
        $db = $app->db();
        $site = is_array($d) ? $db->one('SELECT * FROM {fleet_sites} WHERE id = ?', [(int) ($d['site_id'] ?? 0)]) : null;
        if ($site === null) {
            return Response::json(['error' => 'This console does not know the site.'], 404);
        }
        if (!Keys::verify($body, $signature, (string) $site['public_key'])) {
            return Response::json(['error' => 'The signature is not valid.'], 403);
        }
        $ts = (int) ($d['ts'] ?? 0);
        if (abs(time() - $ts) > Link::MAX_SKEW || $ts <= (int) $site['last_ts']) {
            return Response::json(['error' => 'An old or repeated heartbeat.'], 409);
        }
        $beat = self::clean($d);
        $version = (string) ($beat['version'] ?? '');
        $update = ['last_seen' => date('Y-m-d H:i:s'), 'last_ts' => $ts, 'heartbeat' => (string) json_encode($beat, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'status' => in_array($beat['status'] ?? '', ['ok', 'warning', 'error'], true) ? $beat['status'] : '', 'silent_reported' => 0,
            'manage_updates' => !empty($d['manage_updates']) ? 1 : 0, 'version' => $version];
        if (($beat['name'] ?? '') !== '') {
            $update['name'] = $beat['name'];
        }
        if (preg_match('~^https?://[^\s/?#]+(/[^\s?#]*)?$~iD', (string) ($beat['url'] ?? '')) === 1) {
            $update['url'] = $beat['url'];
        }
        if ($version !== (string) $site['version']) {
            $update['version_since'] = date('Y-m-d H:i:s');
            if ((string) $site['version'] !== '' && $site['last_seen'] !== null) {
                Events::record($db, 'fleet.site_updated', 'info', t('%s runs version %s.', (string) ($update['url'] ?? $site['url']), $version), ['site' => (int) $site['id'], 'version' => $version]);
            }
        }
        if ($version !== '' && (string) $site['update_allowed'] !== '' && !version_compare((string) $site['update_allowed'], $version, '>')) {
            $update['update_allowed'] = ''; // the version allowed by hand is installed
        }
        $db->update('fleet_sites', $update, ['id' => (int) $site['id']]);
        $site = array_merge($site, $update);
        [$latest, $security, $firstSeen] = self::latest($app);
        $allowed = self::allowedVersion(self::row($site), array_map(self::row(...), self::sites($db)), $latest, $security, $firstSeen, time());

        // 2.16: the newest shared design kit is only announced here – a site that wants it asks /fleet/kit itself (Fleet\Kit)
        // 3.17: a kit meant for one customer is announced to that customer's sites only
        $kit = Kit::announcementFor($db, isset($site['customer_id']) ? (int) $site['customer_id'] : null);

        return self::signed($app->settings(), ['ok' => true, 'update_allowed' => $allowed] + ($kit !== null ? ['kit' => $kit] : []));
    }

    /** POST /fleet/unpair – the site ended the pairing. */
    public static function unpair(App $app, string $body, string $signature): Response
    {
        $d = json_decode($body, true);
        $db = $app->db();
        $site = is_array($d) ? $db->one('SELECT id, url, public_key FROM {fleet_sites} WHERE id = ?', [(int) ($d['site_id'] ?? 0)]) : null;
        if ($site === null || !Keys::verify($body, $signature, (string) $site['public_key']) || abs(time() - (int) ($d['ts'] ?? 0)) > Link::MAX_SKEW) {
            return Response::json(['error' => 'Not a valid request of a paired site.'], 403);
        }
        self::remove($app, (int) $site['id']);

        return self::signed($app->settings(), ['ok' => true]);
    }

    public static function remove(App $app, int $id): void
    {
        $db = $app->db();
        $url = (string) $db->value('SELECT url FROM {fleet_sites} WHERE id = ?', [$id]);
        if ($db->delete('fleet_sites', ['id' => $id]) > 0) {
            History::forget($db, $id); // 3.17: its days and down periods; the fleet reports already made keep it
            Events::record($db, 'fleet.site_removed', 'info', t('%s is no longer in the console.', $url), ['site' => $id]);
        }
    }

    /**
     * Allows the newest version now: one site, or every site that lets the console decide. Each picks it up with its next
     * heartbeat (within an hour). Returns how many sites.
     */
    public static function allowNow(App $app, ?int $siteId = null): int
    {
        [$latest] = self::latest($app);
        $count = 0;
        foreach (self::sites($app->db()) as $site) {
            if (($siteId === null || (int) $site['id'] === $siteId) && (int) $site['manage_updates'] === 1 && version_compare($latest, (string) $site['version'], '>')) {
                $app->db()->update('fleet_sites', ['update_allowed' => $latest], ['id' => (int) $site['id']]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * 3.17: allows the newest version now on the sites of one customer (Fleet\Customers) that let the console decide.
     * Returns how many sites.
     */
    public static function allowNowForCustomer(App $app, int $customerId): int
    {
        $count = 0;
        foreach (self::sites($app->db()) as $site) {
            if ((int) ($site['customer_id'] ?? 0) === $customerId && $customerId > 0) {
                $count += self::allowNow($app, (int) $site['id']);
            }
        }

        return $count;
    }

    /**
     * The newest version the console knows (its own, or the newer one its update channel offers), whether it is a security
     * release, and when the console first saw it (setting fleet_versions, JSON version => [time, security]).
     *
     * @return array{0: string, 1: bool, 2: int}
     */
    public static function latest(App $app): array
    {
        $s = $app->settings();
        // the console's own PHP does not decide for the sites: a release it cannot run itself is still the newest one
        // (a site on an older PHP than the release needs refuses it itself – Core\Updater)
        $state = (new Updater($s))->state();
        $new = $state['nova'] ?? $state['vyzaduje_php'];
        $version = $new !== null ? (string) $new['verze'] : KALETA_VERSION;
        $seen = json_decode($s->get('fleet_versions'), true);
        $seen = is_array($seen) ? $seen : [];
        if (!isset($seen[$version])) {
            $seen[$version] = [time(), $new !== null && !empty($new['bezpecnostni'])];
            $s->set('fleet_versions', (string) json_encode(array_slice($seen, -20, null, true)));
        }

        return [$version, (bool) $seen[$version][1], (int) $seen[$version][0]];
    }

    /**
     * Which version a site may install now ('' = none). Pure – see the class description.
     *
     * @param array{id: int, version: string, ring: string, manage_updates: bool, update_allowed: string, status: string, up: ?bool, version_since: ?int, update_problem: bool} $site
     * @param list<array{id: int, version: string, ring: string, manage_updates: bool, update_allowed: string, status: string, up: ?bool, version_since: ?int, update_problem: bool}> $sites
     */
    public static function allowedVersion(array $site, array $sites, string $latest, bool $security, int $firstSeen, int $now): string
    {
        if (!$site['manage_updates'] || $latest === '' || ($site['version'] !== '' && !version_compare($latest, $site['version'], '>'))) {
            return '';
        }
        if ($site['update_allowed'] === $latest || $security || $site['ring'] === 'canary') {
            return $latest;
        }
        $canaries = array_filter($sites, fn (array $c): bool => $c['ring'] === 'canary' && $c['manage_updates'] && $c['id'] !== $site['id']);
        if ($canaries === []) {
            return $firstSeen > 0 && $now - $firstSeen >= self::UPDATE_WAIT ? $latest : '';
        }
        foreach ($canaries as $c) {
            if ($c['version'] !== $latest || $c['version_since'] === null || $now - $c['version_since'] < self::UPDATE_WAIT || $c['status'] === 'error' || $c['up'] === false || $c['update_problem']) {
                return '';
            }
        }

        return $latest;
    }

    /**
     * Why a site needs attention, by weight (REASONS). Pure.
     *
     * @param array<string, mixed> $site a row of ka_fleet_sites
     * @return array{score: int, reasons: list<string>}
     */
    public static function attention(array $site, int $now): array
    {
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
        $beat = is_array($beat) ? $beat : [];
        $lastSeen = $site['last_seen'] !== null ? (int) strtotime((string) $site['last_seen']) : null;
        $reasons = [];
        if ($site['up'] !== null && (int) $site['up'] === 0) {
            $reasons[] = 'down';
        }
        if ($lastSeen === null) {
            $reasons[] = 'no_heartbeat';
        } elseif ($now - $lastSeen > self::SILENT_AFTER) {
            $reasons[] = 'not_reporting';
        }
        if (($site['status'] ?? '') === 'error') {
            $reasons[] = 'errors';
        }
        if (!empty($beat['update_problem'])) {
            $reasons[] = 'update_failed';
        }
        if (!empty($beat['jobs_failing'])) {
            $reasons[] = 'jobs_failing';
        }
        if ($beat !== [] && (empty($beat['last_backup']) || $now - (int) $beat['last_backup'] > 8 * 86400)) {
            $reasons[] = 'no_backup';
        }
        if (($site['status'] ?? '') === 'warning') {
            $reasons[] = 'warnings';
        }
        if ($beat !== [] && (empty($beat['cron_last_run']) || $now - (int) $beat['cron_last_run'] > 86400)) {
            $reasons[] = 'no_cron';
        }
        if ((int) (self::enquiriesWaiting($beat) ?? 0) > 0) {
            $reasons[] = 'enquiries';
        }
        if (!empty($beat['update_available'])) {
            $reasons[] = 'update_available';
        }
        if (is_array($beat['audit'] ?? null) && array_sum($beat['audit']) > 0) {
            $reasons[] = 'audit';
        }
        // 3.16: what waits for people, certificates and domains, sign-in attacks (sites before 3.16 send none of it)
        if ((int) ($beat['requests']['oldest_waiting_hours'] ?? 0) > Heartbeat::REQUEST_WAIT_HOURS) {
            $reasons[] = 'requests_waiting';
        }
        if ((int) ($beat['bookings_pending'] ?? 0) > 0) {
            $reasons[] = 'bookings_to_confirm';
        }
        $days = self::daysLeft($beat['domain_watch']['certificate'] ?? null, $now);
        if ($days !== null && $days < self::CERTIFICATE_DAYS) {
            $reasons[] = 'certificate_expiring';
        }
        $days = self::daysLeft($beat['domain_watch']['domain'] ?? null, $now);
        if ($days !== null && $days < self::DOMAIN_DAYS) {
            $reasons[] = 'domain_expiring';
        }
        if ((int) ($beat['security_7_days']['failed_sign_ins'] ?? 0) >= self::SIGN_IN_SPIKE || (int) ($beat['security_7_days']['events']['security.token_reuse'] ?? 0) > 0) {
            $reasons[] = 'sign_in_attacks';
        }

        return ['score' => array_sum(array_map(fn (string $r): int => self::REASONS[$r], $reasons)), 'reasons' => $reasons];
    }

    /**
     * Enquiries waiting for an answer as the console shows them (3.16): new and not spam, as the site's own list counts
     * them; a site before 3.16 sends only enquiries_unanswered (every unopened enquiry, spam included).
     *
     * @param array<string, mixed> $beat
     */
    public static function enquiriesWaiting(array $beat): ?int
    {
        $count = $beat['enquiries_new_not_spam'] ?? $beat['enquiries_unanswered'] ?? null;

        return is_int($count) ? $count : null;
    }

    /**
     * Days until a certificate or a domain registration ends (3.16), from its date when the report has one – the site checks
     * once a day, the console counts from today; null when unknown.
     *
     * @param mixed $part ['expires' => 'Y-m-d', 'days' => int] from the heartbeat's domain_watch
     */
    public static function daysLeft(mixed $part, int $now): ?int
    {
        if (!is_array($part)) {
            return null;
        }
        if (($date = self::date($part['expires'] ?? null)) !== null) {
            return (int) floor(($date->getTimestamp() - $now) / 86400);
        }

        return is_int($part['days'] ?? null) ? $part['days'] : null;
    }

    /**
     * All sites, the ones that need attention first.
     *
     * @return list<array<string, mixed>> rows of ka_fleet_sites with attention, reasons and the decoded heartbeat (beat)
     */
    public static function overview(Db $db, int $now): array
    {
        $out = [];
        foreach (self::sites($db) as $site) {
            $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);
            $out[] = $site + self::attention($site, $now) + ['beat' => is_array($beat) ? $beat : []];
        }
        usort($out, fn (array $a, array $b): int => [$b['score'], (string) $a['name']] <=> [$a['score'], (string) $b['name']]);

        return $out;
    }

    /**
     * The background job: is each site's home page up (any answer below 500), and has any site stopped reporting?
     */
    public static function checkUptime(App $app, ?int $siteId = null): string
    {
        $db = $app->db();
        $sites = array_values(array_filter(self::sites($db), fn (array $s): bool => $siteId === null || (int) $s['id'] === $siteId));
        $checks = Http::checks(array_map(fn (array $s): string => (string) $s['url'] . '/', $sites));
        $down = 0;
        $now = date('Y-m-d H:i:s');
        foreach ($sites as $i => $site) {
            $status = $checks[$i]['status'] ?? 0;
            $up = $status > 0 && $status < 500;
            $failures = $up ? 0 : min(255, (int) $site['up_failures'] + 1);
            $update = ['up_status' => $status, 'up_checked' => $now, 'up_failures' => $failures];
            History::recordCheck($db, (int) $site['id'], $up, $checks[$i]['ms'] ?? null, substr($now, 0, 10)); // 3.17: the day's uptime and response time
            if ($up && $site['up'] !== null && (int) $site['up'] === 0) {
                $update += ['up' => 1, 'up_changed' => $now];
                Events::record($db, 'fleet.site_up', 'info', t('%s answers again.', (string) $site['url']), ['site' => (int) $site['id']]);
                History::closeIncident($db, (int) $site['id'], $now);
            } elseif ($up && $site['up'] === null) {
                $update += ['up' => 1, 'up_changed' => $now];
            } elseif (!$up && $failures >= self::DOWN_AFTER && ($site['up'] === null || (int) $site['up'] === 1)) {
                $update += ['up' => 0, 'up_changed' => $now];
                Events::record($db, 'fleet.site_down', 'error', t('%s does not answer (%s).', (string) $site['url'], $status > 0 ? 'HTTP ' . $status : t('no answer')), ['site' => (int) $site['id'], 'status' => $status]);
                // the period starts with the first failed check of the row (the previous check, when this one is not the first)
                History::openIncident($db, (int) $site['id'], (int) $site['up_failures'] >= 1 && $site['up_checked'] !== null ? (string) $site['up_checked'] : $now, $status);
            }
            $down += $up ? 0 : 1;
            $lastSeen = $site['last_seen'] !== null ? (int) strtotime((string) $site['last_seen']) : (int) strtotime((string) $site['paired_at']);
            if ((int) $site['silent_reported'] === 0 && time() - $lastSeen > self::SILENT_AFTER) {
                $update['silent_reported'] = 1;
                Events::record($db, 'fleet.site_silent', 'warning', t('%s has not sent its heartbeat for more than a day.', (string) $site['url']), ['site' => (int) $site['id']]);
            }
            $db->update('fleet_sites', $update, ['id' => (int) $site['id']]);
        }

        return 'checked ' . count($sites) . ', down ' . $down;
    }

    /**
     * The customer a site belongs to, '' for none (3.17). The customer is set on the console per site (Kaleta 3.17, work
     * stream A: a column of ka_fleet_sites); until that column exists the row has no such key and every site is ''.
     * The fleet report and the digest group by it.
     *
     * @param array<string, mixed> $site a row of ka_fleet_sites
     */
    public static function customerOf(array $site): string
    {
        $customer = $site['customer'] ?? '';

        return is_string($customer) ? mb_substr(trim($customer), 0, 150) : '';
    }

    /** @return list<array<string, mixed>> */
    public static function sites(Db $db): array
    {
        return $db->all('SELECT * FROM {fleet_sites} ORDER BY ' . $db->sortText('name') . ', id');
    }

    /**
     * A row in the shape allowedVersion() reads.
     *
     * @param array<string, mixed> $site
     * @return array{id: int, version: string, ring: string, manage_updates: bool, update_allowed: string, status: string, up: ?bool, version_since: ?int, update_problem: bool}
     */
    public static function row(array $site): array
    {
        $beat = json_decode((string) ($site['heartbeat'] ?? ''), true);

        return ['id' => (int) $site['id'], 'version' => (string) $site['version'], 'ring' => (string) $site['ring'], 'manage_updates' => (int) $site['manage_updates'] === 1,
            'update_allowed' => (string) $site['update_allowed'], 'status' => (string) $site['status'], 'up' => $site['up'] === null ? null : (int) $site['up'] === 1,
            'version_since' => $site['version_since'] !== null ? (int) strtotime((string) $site['version_since']) : null, 'update_problem' => is_array($beat) && !empty($beat['update_problem'])];
    }

    /**
     * Only the known keys of a heartbeat, with sane types and lengths.
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    public static function clean(array $d): array
    {
        $out = [];
        foreach (self::HEARTBEAT_KEYS as $key) {
            $value = $d[$key] ?? null;
            if (in_array($key, self::PARTS, true)) {
                $out[$key] = self::cleanPart($key, $value);
                continue;
            }
            $out[$key] = match (true) {
                is_string($value) => mb_substr($value, 0, 255),
                is_int($value), is_bool($value), $value === null => $value,
                is_float($value) => self::whole($value),
                is_array($value) => self::cleanList($value),
                default => null,
            };
        }
        // the whistleblowing channel never shows on the console (3.16, N316-3) – a site before 3.16.0 still sends its events
        if (is_array($out['problems_7_days'])) {
            $out['problems_7_days'] = Heartbeat::withoutWhistleblowing($out['problems_7_days']);
        }
        if (is_array($out['problems'])) { // the health checks of a 3.15 site name the channel's events and job in their details
            $out['problems'] = array_values(array_filter(array_map(function (mixed $p): mixed {
                if (!is_array($p) || !is_string($p['detail'] ?? null) || !Heartbeat::isWhistleblowing($p['detail'])) {
                    return $p;
                }
                $p['detail'] = implode(', ', array_filter(preg_split('/\s*[,;]\s*/', $p['detail']) ?: [], fn (string $part): bool => !Heartbeat::isWhistleblowing($part)));

                return $p['detail'] === '' ? null : $p;
            }, $out['problems']), fn (mixed $p): bool => $p !== null));
        }
        if (is_array($out['jobs_failing'])) {
            $out['jobs_failing'] = array_values(array_filter($out['jobs_failing'], fn (mixed $job): bool => !is_string($job) || !Heartbeat::isWhistleblowing($job)));
        }

        return $out;
    }

    /**
     * 3.16: the parts of a heartbeat with their own shape (Fleet\Heartbeat) and their bounds:
     *  - requests: new, in_progress, done_to_review, oldest_waiting_hours; items = null or up to 20 {id, title ≤ 120 with
     *    e-mail addresses masked, status, created_at, updated_at};
     *  - pending_review: the kinds of Core\PendingReview => count;
     *  - enquiries_new_not_spam, bookings_pending: a count;
     *  - security_7_days: firewall reason => count (≤ 10), failed_sign_ins, locked_accounts, security.* event => count (≤ 10);
     *  - domain_watch: checked (a Unix time), local, certificate and domain {expires – a real Y-m-d date, days, error}, mail {error, spf, dmarc, dkim, spf_covers_smtp};
     *  - agent_runs: runs_7_days by result, next_due, next_task (the kind), sessions_7_days;
     *  - updates: behind_days, major_available; report_month: YYYY-MM;
     *  - report_summary (3.17): month YYYY-MM and the counts of History::SUMMARY_COUNTS (null when the site has no such count).
     * Every count is a non-negative integer up to MAX_COUNT; a value that is not one (a string, a float beyond 10^15) is dropped
     * on its own, never the whole heartbeat. Moments are real ones (ISO 8601 with the offset).
     */
    private const array PARTS = ['requests', 'pending_review', 'enquiries_new_not_spam', 'bookings_pending', 'security_7_days', 'domain_watch', 'agent_runs', 'updates', 'report_month', 'report_summary'];

    private const int MAX_COUNT = 1_000_000;

    /** A count as a part may carry it: a non-negative integer up to MAX_COUNT, null for anything else (a float too big to be a whole number too). */
    private static function count(mixed $n): ?int
    {
        $n = is_float($n) ? self::whole($n) : $n;

        return is_int($n) ? max(0, min(self::MAX_COUNT, $n)) : null;
    }

    /**
     * A float as a whole number when it can be one (3.16, N316-5): NAN, INF and anything beyond ±10^15 are null – casting
     * them would give a wrong number, and PHP 8.5 warns about it, which the console's error handler turns into a 500.
     */
    private static function whole(float $n): ?int
    {
        return is_finite($n) && abs($n) < 1e15 ? (int) $n : null;
    }

    /** A moment as the site sends it (ISO 8601 with its offset) that is a real moment, null for anything else. */
    private static function moment(mixed $value): ?string
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:([+-])(\d{2}):(\d{2})|Z)$/D', $value, $m) !== 1) {
            return null;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (int) $m[4] < 24 && (int) $m[5] < 60 && (int) $m[6] < 60 && (int) ($m[8] ?? 0) <= 14 && (int) ($m[9] ?? 0) < 60 ? $value : null;
    }

    /**
     * A calendar date 'Y-m-d' that exists (3.16, N316-5): "2026-13-45" passes a pattern, but no calendar – the console's
     * pages and counts use this, never the raw text a site sent.
     */
    public static function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }

    /** The latest Unix time a heartbeat may carry as a moment (1 January 2100). */
    private const int LATEST_TIME = 4_102_444_800;

    /**
     * A map of name => count: only names from $keys (or matching $pattern), at most $max of them.
     *
     * @param list<string>|null $keys
     * @return array<string, int>
     */
    private static function counts(mixed $map, ?array $keys, string $pattern = '', int $max = 20): array
    {
        $out = [];
        foreach (is_array($map) ? $map : [] as $name => $n) {
            $known = is_string($name) && ($keys !== null ? in_array($name, $keys, true) : preg_match($pattern, $name) === 1);
            if ($known && count($out) < $max && ($n = self::count($n)) !== null) {
                $out[$name] = $n;
            }
        }

        return $out;
    }

    /**
     * One 3.16 part of a heartbeat (PARTS): known keys only, counts bounded, dates and versions by pattern, request titles
     * cleaned again – the console does not trust a site to have masked the e-mail addresses.
     */
    private static function cleanPart(string $key, mixed $v): mixed
    {
        if ($key === 'enquiries_new_not_spam' || $key === 'bookings_pending') {
            return self::count($v);
        }
        if ($key === 'report_month') {
            return is_string($v) && preg_match('/^\d{4}-\d{2}$/D', $v) === 1 ? $v : null;
        }
        if ($key === 'report_summary') {
            $summary = History::summary($v);

            return $summary === null ? null : array_map(fn (mixed $n): mixed => is_int($n) ? self::count($n) : $n, $summary);
        }
        if (!is_array($v)) {
            return null;
        }
        switch ($key) {
            case 'pending_review':
                return self::counts($v, array_keys(\Kaleta\Core\PendingReview::KINDS));
            case 'requests':
                $items = null;
                if (is_array($v['items'] ?? null)) {
                    $items = [];
                    foreach (array_slice($v['items'], 0, Heartbeat::REQUEST_ITEMS) as $item) {
                        if (is_array($item) && self::count($item['id'] ?? null) !== null && is_string($item['status'] ?? null) && isset(\Kaleta\Core\Requests::STATUSES[$item['status']])) {
                            $items[] = ['id' => self::count($item['id']), 'title' => Heartbeat::cleanTitle(is_string($item['title'] ?? null) ? $item['title'] : ''), 'status' => $item['status'],
                                'created_at' => self::moment($item['created_at'] ?? null), 'updated_at' => self::moment($item['updated_at'] ?? null)];
                        }
                    }
                }

                return ['new' => self::count($v['new'] ?? null) ?? 0, 'in_progress' => self::count($v['in_progress'] ?? null) ?? 0,
                    'done_to_review' => self::count($v['done_to_review'] ?? null) ?? 0, 'oldest_waiting_hours' => self::count($v['oldest_waiting_hours'] ?? null), 'items' => $items];
            case 'security_7_days':
                return ['firewall' => self::counts($v['firewall'] ?? null, null, '/^[a-z_]{1,40}$/D', 10), 'failed_sign_ins' => self::count($v['failed_sign_ins'] ?? null) ?? 0,
                    'locked_accounts' => self::count($v['locked_accounts'] ?? null) ?? 0, 'events' => self::counts($v['events'] ?? null, null, '/^security\.[a-z_]{1,40}$/D', 10)];
            case 'domain_watch':
                // checked is a Unix time, not a count: kept as it is between 1970 and 2100
                $checked = $v['checked'] ?? null;
                $out = ['checked' => is_int($checked) && $checked >= 0 && $checked <= self::LATEST_TIME ? $checked : null, 'local' => ($v['local'] ?? false) === true];
                foreach (['certificate', 'domain'] as $part) {
                    $p = $v[$part] ?? null;
                    $out[$part] = !is_array($p) ? null : ['expires' => self::date($p['expires'] ?? null) !== null ? $p['expires'] : null,
                        'days' => is_int($p['days'] ?? null) ? max(-100000, min(100000, $p['days'])) : null, 'error' => ($p['error'] ?? false) === true];
                }
                $mail = $v['mail'] ?? null;
                $out['mail'] = !is_array($mail) ? null : array_map(fn (string $check): ?bool => is_bool($mail[$check] ?? null) ? $mail[$check] : null,
                    ['error' => 'error', 'spf' => 'spf', 'dmarc' => 'dmarc', 'dkim' => 'dkim', 'spf_covers_smtp' => 'spf_covers_smtp']);

                return $out;
            case 'agent_runs':
                return ['runs_7_days' => self::counts($v['runs_7_days'] ?? null, Heartbeat::RUN_STATUSES), 'next_due' => self::moment($v['next_due'] ?? null),
                    'next_task' => is_string($v['next_task'] ?? null) && preg_match('/^[a-z_]{1,30}$/D', $v['next_task']) === 1 ? $v['next_task'] : null,
                    'sessions_7_days' => self::count($v['sessions_7_days'] ?? null) ?? 0];
            case 'updates':
                $major = $v['major_available'] ?? null;

                return ['behind_days' => self::count($v['behind_days'] ?? null),
                    'major_available' => is_string($major) && preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]{1,20})?$/D', $major) === 1 ? $major : null];
        }

        return null;
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private static function cleanList(array $value, int $depth = 0): array
    {
        $out = [];
        foreach (array_slice($value, 0, 40, true) as $k => $v) {
            $k = is_int($k) ? $k : mb_substr((string) $k, 0, 60);
            $out[$k] = match (true) {
                is_string($v) => mb_substr($v, 0, 300),
                is_int($v), is_bool($v), $v === null => $v,
                is_array($v) && $depth < 2 => self::cleanList($v, $depth + 1),
                default => null,
            };
        }

        return $out;
    }

    /** A JSON answer signed by the console's key (the heartbeat reply, the kit). @param array<string, mixed> $data */
    public static function signed(Settings $s, array $data, int $status = 200): Response
    {
        $body = (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return new Response($body, $status, ['Content-Type' => 'application/json; charset=utf-8', Http::HEADER => Keys::sign($s, $body), 'Cache-Control' => 'no-store']);
    }
}
