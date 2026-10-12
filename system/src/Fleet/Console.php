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
        'update_available', 'update_problem', 'auto_updates', 'update_channel', 'enquiries_unanswered', 'enquiries_7_days', 'visits_7_days', 'audit', 'problems_7_days', 'claude', 'kit_version'];

    /** Reasons for attention => weight; the list on the console is sorted by the sum. */
    public const array REASONS = [
        'down' => 100, 'not_reporting' => 80, 'errors' => 60, 'update_failed' => 50, 'jobs_failing' => 40, 'no_backup' => 30,
        'no_heartbeat' => 20, 'warnings' => 10, 'no_cron' => 5, 'enquiries' => 5, 'update_available' => 5, 'audit' => 2,
    ];

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
        $kit = Kit::announcement($db);

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
        if ((int) ($beat['enquiries_unanswered'] ?? 0) > 0) {
            $reasons[] = 'enquiries';
        }
        if (!empty($beat['update_available'])) {
            $reasons[] = 'update_available';
        }
        if (is_array($beat['audit'] ?? null) && array_sum($beat['audit']) > 0) {
            $reasons[] = 'audit';
        }

        return ['score' => array_sum(array_map(fn (string $r): int => self::REASONS[$r], $reasons)), 'reasons' => $reasons];
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
        $statuses = Http::statuses(array_map(fn (array $s): string => (string) $s['url'] . '/', $sites));
        $down = 0;
        foreach ($sites as $i => $site) {
            $status = $statuses[$i] ?? 0;
            $up = $status > 0 && $status < 500;
            $failures = $up ? 0 : min(255, (int) $site['up_failures'] + 1);
            $update = ['up_status' => $status, 'up_checked' => date('Y-m-d H:i:s'), 'up_failures' => $failures];
            if ($up && $site['up'] !== null && (int) $site['up'] === 0) {
                $update += ['up' => 1, 'up_changed' => date('Y-m-d H:i:s')];
                Events::record($db, 'fleet.site_up', 'info', t('%s answers again.', (string) $site['url']), ['site' => (int) $site['id']]);
            } elseif ($up && $site['up'] === null) {
                $update += ['up' => 1, 'up_changed' => date('Y-m-d H:i:s')];
            } elseif (!$up && $failures >= self::DOWN_AFTER && ($site['up'] === null || (int) $site['up'] === 1)) {
                $update += ['up' => 0, 'up_changed' => date('Y-m-d H:i:s')];
                Events::record($db, 'fleet.site_down', 'error', t('%s does not answer (%s).', (string) $site['url'], $status > 0 ? 'HTTP ' . $status : t('no answer')), ['site' => (int) $site['id'], 'status' => $status]);
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
            $out[$key] = match (true) {
                is_string($value) => mb_substr($value, 0, 255),
                is_int($value), is_bool($value), $value === null => $value,
                is_float($value) => (int) $value,
                is_array($value) => self::cleanList($value),
                default => null,
            };
        }

        return $out;
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
