<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * System health (health check): a set of quick checks of the server, database, security and operation.
 * The result is shown in Settings and is also available as JSON for monitoring (/status.json?token=..., the older /stav.json too).
 */
final class Health
{
    /**
     * $leaving = the checks go out of the site (the fleet heartbeat, the monthly report – 3.16, N316-3): the background job
     * and the events of the whistleblowing channel are left out of them (Whistleblowing::concerns).
     * $details (3.19, N319-7; default: not leaving) = the database's own error message of a failed address switch (it can
     * name the database user and host) and the list of colliding redirect addresses; without them the texts say what is
     * wrong and what to do, with counts – the monitoring stav.json passes false as well. System status shows everything.
     *
     * @return list<array{skupina:string, nazev:string, stav:string, info:string, odkazy?: list<array{text:string, url:string}>}> stav: ok | varovani | chyba; odkazy = where to fix it (a user, a connection)
     */
    public static function checks(App $app, bool $leaving = false, ?bool $details = null): array
    {
        $details ??= !$leaving;
        $k = [];
        $add = function (string $group, string $name, bool|string $state, string $info, array $links = []) use (&$k): void {
            $k[] = ['skupina' => $group, 'nazev' => $name, 'stav' => is_bool($state) ? ($state ? 'ok' : 'chyba') : $state, 'info' => $info] + ($links !== [] ? ['odkazy' => $links] : []);
        };
        $db = $app->db();
        $siteSettings = $app->settings();

        // --- server (names and texts go through t(); the "stav" values are not translated - monitoring reads them)
        $add(t('Server'), t('PHP version'), version_compare(PHP_VERSION, KALETA_MIN_PHP, '>='), version_compare(PHP_VERSION, KALETA_MIN_PHP, '>=') ? PHP_VERSION : t('%s - the system requires %s or newer', PHP_VERSION, KALETA_MIN_PHP));
        if (($jit = self::riskyJit()) !== null) {
            $add(t('Server'), t('PHP JIT'), 'varovani', t('opcache.jit = %s on PHP %s can crash pages (a bug in PHP 8.3) – ask the hosting for opcache.jit = tracing (the default) or PHP 8.4', $jit, PHP_VERSION));
        }
        foreach (['pdo_mysql' => t('database'), 'mbstring' => t('text with diacritics'), 'gd' => t('image processing')] as $ext => $purpose) {
            $add(t('Server'), t('Extension %s', $ext), extension_loaded($ext), extension_loaded($ext) ? $purpose : t('%s - missing', $purpose));
        }
        foreach (['exif' => t('correct rotation of photos from phones'), 'intl' => t('language-aware sorting'), 'curl' => t('notifying search engines about new content')] as $ext => $purpose) {
            $add(t('Server'), t('Extension %s', $ext), extension_loaded($ext) ? 'ok' : 'varovani', extension_loaded($ext) ? $purpose : t('%s - installing it is recommended', $purpose));
        }
        $add(t('Server'), t('Upload size limit'), self::bytes((string) ini_get('upload_max_filesize')) >= 8 * 1024 * 1024 ? 'ok' : 'varovani', 'upload_max_filesize = ' . ini_get('upload_max_filesize') . ', post_max_size = ' . ini_get('post_max_size'));
        $freeSpace = @disk_free_space(KALETA_ROOT);
        if ($freeSpace !== false) {
            $add(t('Server'), t('Free disk space'), $freeSpace > 200 * 1024 * 1024 ? 'ok' : 'varovani', self::size((int) $freeSpace));
        }

        // --- database
        $add(t('Database'), t('Server'), 'ok', (string) $db->value('SELECT VERSION()'));
        $pending = Migration::latest() - max(1, $siteSettings->int('db_version'));
        $add(t('Database'), t('Database structure'), $pending <= 0, $pending <= 0 ? t('up to date (version %d)', $siteSettings->int('db_version')) : t('%d updates pending - they will run the next time the administration loads', $pending));
        $size = (int) $db->value('SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE ?', [addcslashes($db->prefix, '_%') . '%']);
        [$clockState, $clockInfo] = self::clock($siteSettings->get('time_zone'), date_default_timezone_get(), intdiv((int) date('Z'), 60),
            (int) $db->value('SELECT TIMESTAMPDIFF(MINUTE, UTC_TIMESTAMP(), NOW())'));
        $add(t('Database'), t('Time zone'), $clockState, $clockInfo);
        // 3.19 (data model §3 step 2): the same address once in each language version – on for every site the update could
        // switch; a site it could not switch says why and what to do (migration 0106, Core\Slug::failure)
        // switch; a site that was on already and where it failed (a key missing, N319-1) says so too
        $slugFailure = Slug::failure($siteSettings);
        $slugsOk = Slug::perLanguage($db) && $slugFailure === null;
        $add(t('Database'), t('Addresses in every language'), $slugsOk ? 'ok' : 'varovani', match (true) {
            $slugFailure !== null => Slug::failureText($slugFailure, $details, Slug::perLanguage($db)),
            Slug::perLanguage($db) => t('each language version may use the same address once (/kontakt and /en/kontakt)'),
            // the migration ran and switched the site on, and then an older version (a rollback to 3.18) switched it off
            in_array(Slug::MIGRATION, explode(',', $siteSettings->get('data_migrations')), true) => t('addresses are unique across all languages again – an older version of Kaleta switched “The same address in every language” off. Switch it on again in Settings → General.'),
            default => t('addresses are still unique across all languages – the database update of Kaleta 3.19 has not run yet (it runs the next time the administration loads)'),
        }, $slugsOk ? [] : [['text' => t('Settings → General'), 'url' => $app->url('admin.php?module=settings&tab=general')]]);
        // 3.19 (§1.3): redirects are looked up by the exact old address; groups that counted as one before are listed here
        try {
            $groups = RedirectSources::collisionCount($db);
            if ($groups > 0 || Extensions::isEnabled($siteSettings, 'presmerovani')) {
                $pendingSources = RedirectSources::pending($db);
                $add(t('Database'), t('Old addresses of redirects'), $groups > 0 ? 'varovani' : 'ok', match (true) {
                    $groups > 0 && $details => t('%d group(s) of redirects have old addresses that counted as one address before Kaleta 3.19 – they differ only in letter case, accents, percent-encoding or slashes. Redirects now compare the old address exactly, so a visitor gets the one stored exactly as typed or the oldest of the group: delete the extra ones in Redirects, or merge them into one. %s', $groups,
                        implode('; ', array_map(fn (array $g): string => implode(' = ', array_map(fn (string $s): string => '/' . $s, $g)), RedirectSources::collisions($db, 10)))),
                    // N319-7: outside the site only the count – the old addresses stay on System status
                    $groups > 0 => t('%d group(s) of redirects have old addresses that counted as one address before Kaleta 3.19 – they differ only in letter case, accents, percent-encoding or slashes. Redirects now compare the old address exactly: delete the extra ones in Redirects, or merge them into one.', $groups),
                    default => t('every redirect is looked up by its exact old address'),
                }
                    . ($pendingSources > 0 ? ' ' . t('%d redirect(s) are still being prepared by the background jobs.', $pendingSources) : ''),
                    $groups > 0 ? [['text' => t('Redirects'), 'url' => $app->url('admin.php?module=redirects')]] : []);
            }
        } catch (\Throwable) {
            // the column of migration 0094 is missing before the update ran – System status still opens
        }
        $add(t('Database'), t('Velikost'), 'ok', t('%s, news items: %d', self::size($size), (int) $db->value('SELECT COUNT(*) FROM {novinky}')));

        // --- files and security
        foreach (['media' => t('uploaded images'), 'storage/log' => t('error log'), 'storage/cache' => t('temporary data')] as $folder => $purpose) {
            $ok = is_dir(KALETA_ROOT . '/' . $folder) ? is_writable(KALETA_ROOT . '/' . $folder) : is_writable(KALETA_ROOT);
            $add(t('Files'), t('Write access to %s/', $folder), $ok, $ok ? $purpose : t('%s - set write permissions', $purpose));
        }
        $add(t('Bezpečnost'), t('Instalátor'), !is_file(KALETA_ROOT . '/install.php') ? 'ok' : 'varovani', is_file(KALETA_ROOT . '/install.php') ? t('install.php is still on the server - delete it') : t('install.php has been removed'));
        $add(t('Bezpečnost'), 'HTTPS', $app->request->isHttps() ? 'ok' : 'varovani', $app->request->isHttps() ? t('the site runs over an encrypted connection') : t('the site does not run over HTTPS - sign-in details travel unencrypted'));
        // 3.19.1 (N83): config.php holds the database password and the encryption key
        $configOpen = Vault::configWorldReadable();
        $add(t('Bezpečnost'), 'config.php', $configOpen ? 'varovani' : 'ok', $configOpen
            ? t('config.php can be read by every user of the server (mode %s) – it holds the database password and the encryption key. Set its mode to 0600 (0640 where the web server reads it through its group) in your FTP client or the hosting\'s file manager.', sprintf('%04o', (int) @fileperms(KALETA_ROOT . '/config.php') & 0777))
                . (Vault::canProtectConfig() ? ' ' . t('Kaleta can do it for you – the button is below the table.') : '')
            : t('readable only by the site'));
        $add(t('Bezpečnost'), t('Debug mode'), !$app->debug(), $app->debug() ? t('debug = true is set in config.php; turn it off on a live site') : t('vypnutý'));
        $add(t('Bezpečnost'), t('Security headers'), 'ok', t('the system sends X-Content-Type-Options, Referrer-Policy and X-Frame-Options; the administration also sends a Content-Security-Policy and forbids caching'));
        if (is_file(KALETA_ROOT . '/.htaccess.kaleta-nova')) {
            // an update keeps a customised .htaccess and puts its own next to it (Core\Updater) – since 3.3.2 it also closes extensions/,
            // since 3.19.1 (N86) also a PHP file in an add-on's public/ folder asked for with a path after it
            $add(t('Bezpečnost'), '.htaccess', 'varovani', t('An update left a newer .htaccess.kaleta-nova next to your customised .htaccess – carry its new rules over (since 3.3.2 they keep the code of add-ons in extensions/ away from visitors; since 3.19.1 they also refuse a PHP file in an add-on\'s public/ folder when a path follows its name, like run.php/x), then delete the file.'));
        }
        $mediaRules = is_file(KALETA_ROOT . '/media/.htaccess') ? (string) file_get_contents(KALETA_ROOT . '/media/.htaccess') : '';
        if (!is_file(KALETA_ROOT . '/media/.htaccess.kaleta-nova') && (!str_contains($mediaRules, self::MEDIA_RULE) || !str_contains($mediaRules, self::MEDIA_HEADER_RULE))) {
            // 3.9.2 (N392-2): the migration could not write the file (permissions) or something put an older one back;
            // 3.15 (N72): the same for the header rules that match an upper-case extension too (migration 0098)
            $add(t('Bezpečnost'), 'media/.htaccess', 'varovani', t('media/.htaccess does not have the rules of Kaleta 3.15 that block files like photo.php.jpg and offer documents for download whatever the case of their extension – copy media/.htaccess from the release package (on nginx, the media rules of system/nginx.example.conf).'));
        }
        if (($risky = self::riskyMedia()) !== []) {
            // 3.9.2 (N392-1): a file an import placed before 3.9.2 – blocked by the new rule, but it should not be there at all
            $add(t('Bezpečnost'), t('Media files'), 'varovani', t('Files with a script extension in their name are in media/ (%s) – check them and delete them over FTP.', implode(', ', $risky)));
        }
        if (is_file(KALETA_ROOT . '/media/.htaccess.kaleta-nova')) {
            // 3.9.2 (N67), 3.15 (N72): migrations 0084 and 0098 keep a customised media/.htaccess and put Kaleta's newer one next to it
            $add(t('Bezpečnost'), 'media/.htaccess', 'varovani', t('A newer media/.htaccess.kaleta-nova is next to your customised media/.htaccess – carry over its rules (since 3.9.2 they block files like photo.php.jpg, since 3.15 the download rules also match an upper-case extension), then delete the file.'));
        }
        $core = Integrity::check();
        $add(t('Bezpečnost'), t('Core files'), $core['stav'], $core['info']);

        // --- accounts and access (2.8, Core\SecurityHygiene): what the daily check looks at, each item with a link to fix it
        $hygiene = SecurityHygiene::findings($app);
        $suspend = SecurityHygiene::autoSuspend($siteSettings);
        $userLink = static fn (array $a, string $text): array => ['text' => $text, 'url' => $app->url('admin.php?module=users&action=edit&id=' . (int) $a['idu'])];
        $accountLinks = static fn (array $accounts, string $suffix = ''): array => array_map(static fn (array $a): array => $userLink($a, SecurityHygiene::displayName($a) . $suffix), $accounts);
        $group = t('Accounts and access');
        $add($group, t('Two-step sign-in for administrators'), $hygiene['two_step'] === [] ? 'ok' : 'varovani',
            $hygiene['two_step'] === [] ? t('all administrators have it') : t('%d administrator(s) sign in without two-step sign-in or a passkey – it is turned on under My account (avatar at the top right)', count($hygiene['two_step'])),
            $accountLinks($hygiene['two_step']));
        $unusedAccounts = $hygiene['unused_accounts'];
        $add($group, t('Unused accounts'), $unusedAccounts === [] ? 'ok' : 'varovani', match (true) {
            $unusedAccounts === [] => t('every account has been used in the last %d days', SecurityHygiene::ACCOUNT_DAYS),
            in_array(SecurityHygiene::SUSPEND_ACCOUNTS, $suspend, true) => t('%d account(s) unused for %d days – the automatic suspension blocks them on its next daily run', count($unusedAccounts), SecurityHygiene::ACCOUNT_DAYS),
            default => t('%d account(s) unused for %d days – block them in Users, or switch on the automatic suspension in Settings → General', count($unusedAccounts), SecurityHygiene::ACCOUNT_DAYS),
        }, array_map(static fn (array $a): array => $userLink($a, SecurityHygiene::displayName($a) . ' (' . t('last activity %s', format_date((string) $a['last'])) . ')'), $unusedAccounts));
        $connectionLink = static fn (array $c): array => ['text' => $c['name'] . ' (' . $c['user'] . ')', 'url' => $app->url('admin.php?module=users&action=edit&id=' . (int) $c['idu'] . '#napojeni')];
        $unusedConnections = $hygiene['unused_connections'];
        $add($group, t('Unused Claude connections'), $unusedConnections === [] ? 'ok' : 'varovani', match (true) {
            $unusedConnections === [] => t('every connection has been used in the last %d days', SecurityHygiene::CONNECTION_DAYS),
            in_array(SecurityHygiene::SUSPEND_CONNECTIONS, $suspend, true) => t('%d connection(s) unused for %d days – the automatic suspension revokes them on its next daily run', count($unusedConnections), SecurityHygiene::CONNECTION_DAYS),
            default => t('%d connection(s) unused for %d days – revoke them in the user’s account, or switch on the automatic suspension in Settings → General', count($unusedConnections), SecurityHygiene::CONNECTION_DAYS),
        }, array_map($connectionLink, $unusedConnections));
        $add($group, t('Connections without an expiry'), $hygiene['no_expiry'] === [] ? 'ok' : 'varovani',
            $hygiene['no_expiry'] === [] ? t('every personal token has an expiry date') : t('%d personal token(s) never expire – a token works until it is revoked; revoke those that are not needed, or create them again with an expiry', count($hygiene['no_expiry'])),
            array_map($connectionLink, $hygiene['no_expiry']));
        $blocked = $db->all('SELECT idu, user, jmeno, blokovano_automaticky FROM {uzivatele} WHERE blokovat = 1 ORDER BY user');
        $add($group, t('Blocked accounts'), $blocked === [] ? 'ok' : 'varovani', $blocked === [] ? t('žádné') : t('%d – blocked by an administrator or by the automatic suspension; you can reactivate them in Users', count($blocked)),
            $accountLinks($blocked));
        $add($group, t('Automatic suspension'), 'ok', $suspend === [] ? t('off – unused accounts and connections are only reported (Settings → General)')
            : implode(', ', array_filter([in_array(SecurityHygiene::SUSPEND_ACCOUNTS, $suspend, true) ? t('accounts after %d days', SecurityHygiene::ACCOUNT_DAYS) : '', in_array(SecurityHygiene::SUSPEND_CONNECTIONS, $suspend, true) ? t('Claude connections after %d days', SecurityHygiene::CONNECTION_DAYS) : ''])));

        // 3.18: whether requests reach Claude (Core\ClaudeConnection) – a warning when new requests wait and nothing picks them
        // up, or when a connection ends soon; with the Claude connection switched off only that
        $claude = ClaudeConnection::status($db, $siteSettings, true);
        $when = static fn (?string $moment): string => $moment !== null ? format_date($moment, true) : '–';
        $add($group, t('Claude connection'), match (true) {
            $claude['waiting'] > 0 && ClaudeConnection::blocked($claude) && $claude['state'] !== 'off', $claude['expiring'] => 'varovani',
            default => 'ok',
        }, match (true) {
            $claude['state'] === 'off' => t('switched off (Features)'),
            $claude['state'] === 'none' => $claude['waiting'] > 0 ? t('%d request(s) wait, but Claude is not connected to the site – connect it in Claude settings', $claude['waiting']) : t('not connected'),
            $claude['pickup'] === 'stalled' && $claude['waiting'] > 0 => t('%d request(s) wait, but the scheduled run that picks them up is not running (last missed or failed %s) – check the routine in Claude (Scheduled runs)', $claude['waiting'], $when($claude['stalled_since'])),
            default => ($claude['state'] === 'active' ? t('connected, last call %s', $when($claude['last_call'])) : ($claude['last_call'] !== null ? t('connected, no call in the last %d days (last call %s)', ClaudeConnection::ACTIVE_DAYS, $when($claude['last_call'])) : t('connected, no call yet')))
                . '; ' . match ($claude['pickup']) {
                    'automatic' => t('requests are picked up by a scheduled run'),
                    'stalled' => t('the scheduled run that picks up requests is not running'),
                    default => t('requests are picked up when someone asks Claude'),
                },
        } . ($claude['expiring'] ? ' – ' . t('a connection ends %s, connect Claude again before then', $when($claude['expires'])) : ''),
            $claude['state'] !== 'off' ? [['text' => t('Claude settings'), 'url' => $app->url('admin.php?module=claude_settings')]] : []);

        // --- operation
        $log = KALETA_ROOT . '/storage/log/chyby.log';
        $errorCount = 0;
        if (is_file($log)) {
            $from = date('c', time() - 86400);
            foreach (array_slice(file($log, FILE_IGNORE_NEW_LINES) ?: [], -500) as $row) {
                $errorCount += (int) (substr($row, 1, 25) >= $from);
            }
        }
        $add(t('Operation'), t('Errors in the last 24 hours'), $errorCount === 0 ? 'ok' : 'varovani', $errorCount === 0 ? t('žádné') : t('%d - details in storage/log/chyby.log', $errorCount));
        $last = Backup::listAll()[0]['cas'] ?? 0;
        $age = $last > 0 ? (int) floor((time() - $last) / 86400) : null;
        $add(t('Operation'), t('Database backup'), $age !== null && $age <= 8 ? 'ok' : 'varovani', $age === null ? t('none yet - create one on the Backups and updates tab') : ($age === 0 ? t('today') : t('%d days ago', $age)) . ', ' . ($siteSettings->bool('auto_backups') ? t('automatic backups on') : t('automatic backups off')));
        $add(t('Operation'), t('Search engine indexing'), $siteSettings->bool('indexing') ? 'ok' : 'varovani', $siteSettings->bool('indexing') ? t('allowed') : t('disabled on the SEO and GEO tab - the site will not appear in search results'));
        $media = 0;
        if (is_dir(KALETA_ROOT . '/media')) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(KALETA_ROOT . '/media', \FilesystemIterator::SKIP_DOTS)) as $file) {
                $media += $file->getSize();
            }
        }
        $add(t('Operation'), t('Media size'), 'ok', self::size($media));
        $remote = explode('|', $app->settings()->get('remote_backup_status'), 2);
        if ($app->settings()->get('remote_backup') !== 'vypnuto') {
            $add(t('Operation'), t('Off-site backups'), ($remote[1] ?? '') === 'ok' ? 'ok' : 'varovani', ($remote[1] ?? '') === 'ok' ? t('last copy uploaded %s', $remote[0]) : (($remote[1] ?? '') !== '' ? t('last attempt %s failed: %s', $remote[0], $remote[1]) : t('no copy has been made yet')));
        } else {
            $add(t('Operation'), t('Off-site backups'), 'varovani', t('off – backups are stored only on the same server as the site (Settings → Backups and updates)'));
        }
        $smtp = $app->settings()->get('mail_mode') === 'smtp' && $app->settings()->get('smtp_host') !== '';
        // background tasks (scheduled news items, mail queue, push, newsletter) are run by site visits or cron
        $lastRun = $siteSettings->int('notification_check');
        $before = $lastRun > 0 ? (int) floor((time() - $lastRun) / 60) : null;
        $add(t('Operation'), t('Background tasks'), $before !== null && $before <= 30 ? 'ok' : 'varovani', $before === null
            ? t('have not run yet – the first visit to the site will start them')
            : ($before <= 30 ? t('last run %d min ago', $before) : ($before < 120 ? t('last run %d min ago', $before) : t('last run %d h ago', (int) round($before / 60)))
                . ' – ' . t('on a low-traffic site set up cron; you will find the address further down this page')));
        // themeless since 1.6: a custom PHP layout left in layout/ is no longer used
        $leftover = array_map('basename', array_map('dirname', glob(KALETA_ROOT . '/layout/*/base.php') ?: []));
        if ($leftover !== []) {
            $add(t('Operation'), t('Custom layout'), 'varovani', t('%s in the layout/ folder is no longer used – since 1.6 the look comes only from Site appearance and the builder. Move what you need into shared classes and site parts, then delete the folder.', implode(', ', $leftover)));
        }
        // 3.3.3 (N63): content imported before 3.3.2 was checked again with today's sanitizers
        $recheck = ImportRecheck::state($siteSettings);
        if ($recheck !== null) {
            $add(t('Operation'), t('Imported content'), $recheck['done'] ? 'ok' : 'varovani', ($recheck['done']
                ? t('checked again with the sanitizers of 3.3.3: %d records, %d changed', $recheck['checked'], $recheck['changed'])
                : t('being checked again with the sanitizers of 3.3.3 by the background tasks: %d records so far, %d changed', $recheck['checked'], $recheck['changed']))
                . ($recheck['changed'] > 0 ? ' – ' . t('only risky markup was removed; the version before is in the history of each news item, page or collection item') : ''));
        }
        // 3.12.1 (N312-2): a time zone conversion that runs too long, skipped a table or left values as they were
        $zoneConversion = TimeZoneConversion::latest($db);
        if ($zoneConversion !== null && ($zoneConversion['finished_at'] === null || strtotime((string) $zoneConversion['finished_at']) > time() - 30 * 86400)) {
            $zoneFailed = array_column(array_filter($zoneConversion['tables'], fn (array $r): bool => (string) $r['error'] !== ''), 'table_name');
            $zoneOutside = array_sum(array_map(fn (array $r): int => (int) $r['out_of_range'], $zoneConversion['tables']));
            $zoneSlow = $zoneConversion['finished_at'] === null && strtotime((string) $zoneConversion['started_at']) < time() - 3600;
            if ($zoneConversion['finished_at'] === null || $zoneFailed !== [] || $zoneOutside > 0) {
                $add(t('Operation'), t('Time zone conversion'), $zoneSlow || $zoneFailed !== [] || $zoneOutside > 0 ? 'varovani' : 'ok',
                    ($zoneConversion['finished_at'] === null ? t('saved dates are being converted from %s to %s by the background tasks', (string) $zoneConversion['from_zone'], (string) $zoneConversion['to_zone'])
                        : t('saved dates were converted from %s to %s', (string) $zoneConversion['from_zone'], (string) $zoneConversion['to_zone']))
                    . ($zoneFailed !== [] ? ' – ' . t('not converted (an error, see the change log): %s', implode(', ', $zoneFailed)) : '')
                    . ($zoneOutside > 0 ? ' – ' . t('%d values left as they were: they would leave the years 1000–9999', $zoneOutside) : ''));
            }
        }
        $given = (int) $db->value('SELECT COUNT(*) FROM {webhook_deliveries} WHERE delivered IS NULL AND next_attempt IS NULL AND created > ?', [Clock::at('-7 days')]);
        if ($given > 0 || $siteSettings->get('webhook_url') !== '' || $siteSettings->get('webhook_enquiries') !== '') {
            $add(t('Operation'), t('Webhooks'), $given === 0 ? 'ok' : 'varovani', $given === 0 ? t('all calls of the last 7 days delivered') : t('%d call(s) of the last 7 days not delivered – see Settings → Webhooks', $given));
        }
        $cron = $siteSettings->int('tasks_last_run');
        $cronMinutes = $cron > 0 ? (int) floor((time() - $cron) / 60) : null;
        $add(t('Operation'), t('Cron'), $cronMinutes !== null && $cronMinutes <= Mailing::CRON_MINUTES ? 'ok' : 'varovani', match (true) {
            $cronMinutes === null => t('not set up – scheduled work waits for visits, and newsletters cannot be sent; you will find the address further down this page'),
            $cronMinutes <= Mailing::CRON_MINUTES => t('last run %d min ago', $cronMinutes),
            default => t('last run %s – newsletters are not being sent until cron runs again', format_date((new \DateTimeImmutable())->setTimestamp($cron), true)),
        });
        // 3.7: an English system path the site itself holds (a page "form" made before 3.7) keeps the Czech form there
        $held = Routes::heldSystemPaths($db);
        if ($held !== []) {
            // a cron job set up on /tasks reaches the page then, never the tasks – said out loud while cron is not running (N37-2)
            $cronShadowed = isset($held['tasks']) && ($cronMinutes === null || $cronMinutes > Mailing::CRON_MINUTES);
            $add(t('Operation'), t('English system addresses'), $cronShadowed ? 'varovani' : 'ok', t('The site itself uses %s, so the system keeps writing the older address %s there – both keep working. Rename the page if you want the English address.',
                implode(', ', array_map(fn (string $p): string => '/' . $p, array_keys($held))), implode(', ', array_map(fn (string $p): string => '/' . $p, $held)))
                . ($cronShadowed ? ' ' . t('A cron job that calls /tasks reaches that page and runs nothing – point it at /ulohy.') : ''));
        }
        if (($stuck = Updater::restoreFailed()) !== null) {
            // 3.19.1 (N88): a rollback left files of two versions behind – the maintenance page stays until a person fixes it
            $add(t('Operation'), t('Update rollback'), 'chyba', $stuck);
        }
        $update = (new Updater($siteSettings))->state();
        // 3.11 (UX310-3): old update information makes the Updates row amber too – never a green "up to date" next to the
        // warning; Backups and updates shows the same (Updater::staleDays)
        $staleDays = Updater::staleDays($update);
        $add(t('Operation'), t('Updates'), !$update['nastaveno'] || $update['chyba'] !== null || $update['nova'] !== null || $update['vyzaduje_php'] !== null || $staleDays !== null ? 'varovani' : 'ok', match (true) {
            !$update['nastaveno'] => t('no update source is set'),
            $update['refused'] => (string) $update['chyba'] . ' ' . t('If the publisher really published this, reset the update history in Settings → Backups and updates.'), // 3.10 (N39-7, N310-1): the message says what happened
            $update['chyba'] !== null => t('the update source is not responding: %s', (string) $update['chyba'])
                . ($update['failing_since'] > 0 && time() - $update['failing_since'] >= 86400 ? ' ' . t('The checks have been failing for %d days.', intdiv(time() - $update['failing_since'], 86400)) : ''),
            $update['vyzaduje_php'] !== null => t('version %s needs PHP %s or newer, the server runs PHP %s – ask the hosting for a newer PHP to update', $update['vyzaduje_php']['verze'], $update['vyzaduje_php']['min_php'], PHP_VERSION),
            $update['nova'] !== null => t('version %s is available (Settings → Backups and updates)', (string) $update['nova']['verze']) . ($staleDays !== null ? ' ' . self::staleText($staleDays) : ''),
            // 3.10 (N39-7): the newest signed update information the source gave is old – the source may be stuck or replay
            // an old copy (a warning only: nothing re-signs it automatically, see Updater::FRESHNESS_DAYS)
            $staleDays !== null => self::staleText($staleDays),
            default => t('the system is up to date (%s)', KALETA_VERSION) . ($update['overeno'] > 0 ? ', ' . t('checked %s', format_date((new \DateTimeImmutable())->setTimestamp((int) $update['overeno']), true)) : '')
                // 3.15: the next major version is offered on its own – never installed by itself
                . (($major = Updater::nextMajor($siteSettings)) !== null ? '. ' . t('Kaleta %s is available – read what changes, back up, then update.', (string) $major['verze']) : ''),
        });
        // 3.10 (N310-3): a signed date in the future is not remembered – a wrong clock here or at the publisher
        if ($update['future_date'] !== '') {
            $add(t('Operation'), t('Update information'), 'varovani', t('the update source signs its update information with a date in the future (%s), so the site does not remember it for the freshness check. Check the date and time of the server; if they are right, tell the Kaleta publisher.', $update['future_date']));
        }
        // 3.8 (D3): which release channel the site follows; a stable site that runs a newer minor than the stable line waits
        if ($update['nastaveno']) {
            $add(t('Operation'), t('Update channel'), $update['ahead_of'] !== null ? 'varovani' : 'ok', match (true) {
                $update['ahead_of'] !== null => t('Stable – this site runs %s, newer than the stable channel (%s): nothing is offered until the stable channel passes it, so security fixes reach it only on Latest (Settings → Backups and updates)', KALETA_VERSION, $update['ahead_of']),
                $update['channel'] === 'stable' => t('Stable – security fixes only, a new minor version about once a month'),
                $update['channel'] === 'custom' => t('custom update source – the site follows it whatever channel is chosen'),
                default => t('Latest – a new minor version every week'),
            });
        }
        // 2.8: background jobs (Core\Scheduler) and the problems of the last week (Core\Events)
        $failing = array_filter(Scheduler::overview($db, $app->settings()), fn (array $j): bool => $j['failures'] > 0 && !($leaving && Whistleblowing::concerns((string) $j['name'])));
        $add(t('Operation'), t('Background jobs'), $failing === [] ? 'ok' : (max(array_column($failing, 'failures')) >= Scheduler::FAILURES_TO_ALERT ? 'chyba' : 'varovani'),
            $failing === [] ? t('every job worked the last time it ran') : implode('; ', array_map(fn (array $j): string => t('%s failed %d× in a row: %s', t($j['label']), $j['failures'], $j['last_error']), $failing)));
        $problems = array_filter(Events::problems($db, 168), fn (string|int $type): bool => !($leaving && Whistleblowing::concerns((string) $type)), ARRAY_FILTER_USE_KEY);
        if ($problems !== []) {
            $add(t('Operation'), t('Problems in the last 7 days'), 'varovani', implode(', ', array_map(fn (string $type, int $n): string => $type . ' ×' . $n, array_keys($problems), $problems)));
        }
        $mailService = MailServices::current($app->settings()); // 3.9: the mail service behind the SMTP server, by name
        $add(t('Operation'), t('Mail delivery'), $smtp || function_exists('mail') ? 'ok' : 'varovani', $smtp ? ($mailService !== null
            ? t('via %s (SMTP server %s)', MailServices::name($mailService), $app->settings()->get('smtp_host'))
            : t('via SMTP server %s', $app->settings()->get('smtp_host'))) : (function_exists('mail') ? t('using the server\'s mail() function – SMTP is more reliable (Settings → Mail)') : t('the mail() function is disabled – set up SMTP (Settings → Mail)')));

        // --- domain and mail (2.8, Core\DomainWatch): the cached result only – no page view waits for DNS or a remote server
        return [...$k, ...DomainWatch::rows(DomainWatch::cached($siteSettings), Demo::active(), time())];
    }

    /** The deny rule of media/.htaccess since 3.9.2 (N67): a script extension anywhere in a file name, in any case. */
    private const string MEDIA_RULE = '(?i)\\.(php\\d?|pht|phtml|phar|pl|py|cgi|sh|shtml|html?|js)(\\.|$)';

    /** One of the header rules of media/.htaccess since 3.15 (N72): an SVG gets its sandbox CSP whatever the case of .svg. */
    private const string MEDIA_HEADER_RULE = '"(?i)\\.svg$"';

    /**
     * The owner's words for old update information (3.11, UX310-3) – the same sentence in System status and in Backups and
     * updates; the technical detail sits behind "Details for your developer" there.
     */
    public static function staleText(int $days): string
    {
        return t('Kaleta can’t confirm this is the newest version – the update information is %d days old. Usually this clears itself within a day; if it is still here after a week, write to info@kaletacms.com (include this message).', $days);
    }

    /**
     * Files in media/ whose name holds a script extension (photo.php.jpg, x.PHP) – Kaleta never writes such a name, an import
     * before 3.9.2 could (N67, N392-1). At most 5 are named; the walk stops after 50,000 entries so the page stays fast.
     *
     * @return list<string>
     */
    public static function riskyMedia(?string $folder = null): array
    {
        $folder ??= KALETA_ROOT . '/media';
        if (!is_dir($folder)) {
            return [];
        }
        $found = [];
        $seen = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (++$seen > 50000 || count($found) >= 5) {
                break;
            }
            $name = $file instanceof \SplFileInfo ? $file->getFilename() : '';
            if ($name !== '' && $name[0] !== '.' && preg_match('/' . self::MEDIA_RULE . '/', $name) === 1) {
                $found[] = substr($file->getPathname(), strlen(dirname($folder)) + 1);
            }
        }

        return $found;
    }

    /** Summary for monitoring: the worst status found. */
    public static function summary(array $checks): string
    {
        $statuses = array_column($checks, 'stav');

        return in_array('chyba', $statuses, true) ? 'chyba' : (in_array('varovani', $statuses, true) ? 'varovani' : 'ok');
    }

    /**
     * Do the time_zone setting, PHP and the database session count time the same way (3.10, data model §6)? PHP writes dates
     * on the site's clock and SQL compares them with NOW(); when they disagree, scheduled items and time limits shift. Pure
     * for the tests: the setting, PHP's zone, and the UTC offsets of PHP and of the database session in minutes.
     *
     * @return array{0: string, 1: string} [ok | varovani, what the administrator reads]
     */
    public static function clock(string $setting, string $phpZone, int $phpMinutes, int $dbMinutes): array
    {
        $offset = fn (int $minutes): string => sprintf('UTC%s%02d:%02d', $minutes < 0 ? '-' : '+', intdiv(abs($minutes), 60), abs($minutes) % 60);
        if ($setting !== '' && $setting !== $phpZone) {
            return ['varovani', t('The time zone setting is %s, but PHP runs in %s – this server does not know the zone, so dates are written and shown on the %s clock. Choose the zone again in Settings → General.', $setting, $phpZone, $phpZone)];
        }
        if ($dbMinutes !== $phpMinutes) {
            return ['varovani', t('PHP writes dates in %s (%s), but the database compares them in %s – scheduled publishing and time limits are off by %d minutes. Ask the hosting whether the database connection keeps its session time zone.',
                $phpZone, $offset($phpMinutes), $offset($dbMinutes), abs($phpMinutes - $dbMinutes))];
        }

        return ['ok', t('%s (%s) – PHP and the database count time the same way', $phpZone, $offset($phpMinutes))];
    }

    /**
     * 3.7: PHP 8.3's JIT compiled per function on its first runs (opcache.jit = 1235 and the like – the trigger digit 1 to 4) crashes
     * the PHP process on builder pages after a while: an engine bug in 8.3, which gets only security fixes. The default tracing JIT
     * (trigger 5), JIT for all functions at load (trigger 0) and PHP 8.4 run the whole test suite. The JIT setting, or null when it is
     * safe – off, another mode, PHP 8.4 or newer, or a hosting that hides the opcache status.
     */
    public static function riskyJit(): ?string
    {
        if (PHP_VERSION_ID >= 80400 || !function_exists('opcache_get_status')) {
            return null;
        }
        $jit = @opcache_get_status(false)['jit'] ?? null; // false with opcache.restrict_api

        return is_array($jit) && ($jit['on'] ?? false) === true && in_array($jit['kind'] ?? null, [1, 2, 3, 4], true) ? (string) ini_get('opcache.jit') : null;
    }

    private static function size(int $byteCount): string
    {
        foreach (['B', 'kB', 'MB', 'GB', 'TB'] as $unit) {
            if ($byteCount < 1024 || $unit === 'TB') {
                return format_number($byteCount, $unit === 'B' ? 0 : 1) . ' ' . $unit;
            }
            $byteCount /= 1024;
        }

        return '';
    }

    private static function bytes(string $ini): int
    {
        $number = (int) $ini;

        return match (strtoupper(substr(trim($ini), -1))) {
            'G' => $number * 1024 ** 3, 'M' => $number * 1024 ** 2, 'K' => $number * 1024, default => $number,
        };
    }
}
