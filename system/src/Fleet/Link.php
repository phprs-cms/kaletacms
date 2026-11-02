<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Admin\ChangeLog;
use Kaleta\Core\App;
use Kaleta\Core\Events;
use Kaleta\Core\Settings;

/**
 * The site's side of a fleet console (2.9). An administrator pastes the pairing key from the console in Settings →
 * Fleet console; the site sends its public key signed by its own key, and from then on a signed heartbeat every hour.
 * The console answers with a signed reply that may allow an update – the only thing a console decides here, and only
 * when the administrator let it (fleet_updates). The site always calls the console, never the other way round: a console
 * cannot reach into a site, and it gets no Claude token (Claude works on each site through that site's own connection).
 */
final class Link
{
    /** A request older or newer than this many seconds is refused (the clocks of two servers may differ a little). */
    public const int MAX_SKEW = 600;

    public static function isPaired(Settings $s): bool
    {
        return $s->get('fleet_console_url') !== '' && $s->int('fleet_site_id') > 0;
    }

    /**
     * The pairing key a console shows: "kaleta-console:" + base64url of {u: console address, c: one-time code, k: console
     * public key, n: console name}. Returns null when it is not one.
     *
     * @return array{url: string, code: string, key: string, name: string}|null
     */
    public static function parseKey(string $pairingKey): ?array
    {
        $pairingKey = preg_replace('/\s+/', '', $pairingKey) ?? '';
        if (!str_starts_with($pairingKey, 'kaleta-console:')) {
            return null;
        }
        $data = json_decode((string) base64_decode(strtr(substr($pairingKey, 15), '-_', '+/'), true), true);
        if (!is_array($data) || !is_string($data['u'] ?? null) || !is_string($data['c'] ?? null) || !is_string($data['k'] ?? null)
            || !preg_match('/^[a-f0-9]{32}$/D', $data['c']) || !Keys::isPublicKey($data['k']) || !Http::allowedUrl($data['u'])) {
            return null;
        }

        return ['url' => rtrim($data['u'], '/'), 'code' => $data['c'], 'key' => $data['k'], 'name' => mb_substr((string) ($data['n'] ?? ''), 0, 150)];
    }

    public static function makeKey(string $consoleUrl, string $code, string $consoleKey, string $consoleName): string
    {
        return 'kaleta-console:' . rtrim(strtr(base64_encode((string) json_encode(['u' => $consoleUrl, 'c' => $code, 'k' => $consoleKey, 'n' => $consoleName], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)), '+/', '-_'), '=');
    }

    /** Pairs the site with a console; $manageUpdates = the console decides when new versions install here. */
    public static function pair(App $app, string $pairingKey, bool $manageUpdates): void
    {
        $s = $app->settings();
        $key = self::parseKey($pairingKey);
        if ($key === null) {
            throw new \RuntimeException(t('This is not a pairing key of a Kaleta console (it starts with kaleta-console:).'));
        }
        if (rtrim($s->get('site_url'), '/') === '') {
            throw new \RuntimeException(t('Fill in the site address in Settings → General first.'));
        }
        $answer = Http::post($key['url'] . '/fleet/pair', [
            'action' => 'pair', 'code' => $key['code'], 'url' => rtrim($s->get('site_url'), '/'), 'name' => $s->get('site_name'), 'version' => KALETA_VERSION,
            'public_key' => Keys::publicKey($s), 'manage_updates' => $manageUpdates, 'ts' => time(),
        ], fn (string $body): string => Keys::sign($s, $body), 20);
        if ($answer['status'] !== 200 || !Keys::verify($answer['body'], $answer['signature'], $key['key']) || (int) ($answer['json']['site_id'] ?? 0) <= 0) {
            $reason = (string) ($answer['json']['error'] ?? ($answer['error'] !== '' ? $answer['error'] : 'HTTP ' . $answer['status']));
            throw new \RuntimeException(t('The console refused the pairing: %s', mb_substr($reason, 0, 200)));
        }
        foreach (['fleet_console_url' => $key['url'], 'fleet_console_key' => $key['key'], 'fleet_console_name' => mb_substr((string) ($answer['json']['console_name'] ?? $key['name']), 0, 150),
            'fleet_site_id' => (string) (int) $answer['json']['site_id'], 'fleet_updates' => $manageUpdates ? '1' : '0', 'fleet_update_allowed' => '', 'fleet_last_error' => ''] as $name => $value) {
            $s->set($name, $value);
        }
        ChangeLog::write($app, 'settings', 'fleet_pair', $key['url'] . ($manageUpdates ? ', updates by the console' : ''));
        Events::record($app->db(), 'fleet.paired', 'info', t('The site was paired with the fleet console %s.', $key['url']));
        try {
            self::send($app);
        } catch (\RuntimeException) {
            // the first heartbeat is tried again by the background jobs; the error is shown in the tab
        }
    }

    /** The site lets the console decide about updates, or takes the decision back. */
    public static function setManageUpdates(App $app, bool $manage): void
    {
        $app->settings()->set('fleet_updates', $manage ? '1' : '0');
        $app->settings()->set('fleet_update_allowed', '');
        ChangeLog::write($app, 'settings', 'fleet_updates', $manage ? 'on' : 'off');
    }

    /** Ends the pairing: the console's token is revoked and the console is told (it may be gone – then only this side). */
    public static function unpair(App $app): void
    {
        $s = $app->settings();
        if (self::isPaired($s)) {
            Http::post($s->get('fleet_console_url') . '/fleet/unpair', ['action' => 'unpair', 'site_id' => $s->int('fleet_site_id'), 'ts' => time()],
                fn (string $body): string => Keys::sign($s, $body), 10);
        }
        // the kit counter goes too, so another console's first kit is applied; the drafts that arrived stay for the person to decide
        foreach (['fleet_console_url', 'fleet_console_key', 'fleet_console_name', 'fleet_site_id', 'fleet_updates', 'fleet_update_allowed', 'fleet_last_sent', 'fleet_last_error',
            'fleet_kit_version', 'fleet_kit_applied_at', 'fleet_kit_error', Uptime::SETTING] as $name) {
            $s->set($name, '');
        }
        ChangeLog::write($app, 'settings', 'fleet_unpair');
    }

    /**
     * Sends the heartbeat; returns a short result for the background jobs. 3.26.1: after a confirmed one the site asks for
     * its uptime days once a day (Fleet\Uptime), or right away when $refreshUptime (the administrator's "Send now").
     */
    public static function send(App $app, bool $refreshUptime = false): string
    {
        $s = $app->settings();
        if (!self::isPaired($s)) {
            return 'not paired';
        }
        $payload = ['action' => 'heartbeat', 'site_id' => $s->int('fleet_site_id'), 'ts' => time(), 'manage_updates' => $s->bool('fleet_updates')] + Heartbeat::build($app);
        $answer = Http::post($s->get('fleet_console_url') . '/fleet/heartbeat', $payload, fn (string $body): string => Keys::sign($s, $body), 20);
        if ($answer['status'] !== 200 || !Keys::verify($answer['body'], $answer['signature'], $s->get('fleet_console_key'))) {
            // stored as an English sentence and translated where it is shown; the detail goes to the job's error
            $error = $answer['status'] === 404 || $answer['status'] === 410 ? 'The console does not know this site any more – pair it again.' : 'The console did not confirm the report.';
            $s->set('fleet_last_error', $error);
            $error .= ' (' . ($answer['error'] !== '' ? $answer['error'] : 'HTTP ' . $answer['status']) . ')';

            throw new \RuntimeException($error); // the scheduler counts the failure; three in a row become an event and an alert
        }
        $s->set('fleet_last_sent', (string) time());
        $s->set('fleet_last_error', '');
        $allowed = (string) ($answer['json']['update_allowed'] ?? '');
        $s->set('fleet_update_allowed', preg_match('/^\d+\.\d+\.\d+([.-][0-9A-Za-z.-]+)?$/D', $allowed) === 1 ? $allowed : '');

        // 2.16: a newer shared design kit announced in the reply is fetched and applied as drafts (only when the site opted in)
        return 'sent' . ($allowed !== '' ? ', update ' . $allowed . ' allowed' : '') . Kit::afterHeartbeat($app, $answer['json']['kit'] ?? null)
            . Uptime::afterHeartbeat($app, $refreshUptime);
    }
}
