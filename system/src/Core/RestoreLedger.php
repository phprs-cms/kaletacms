<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * What a database restore must never bring back (3.46, N156). A restore replaces every table with the backup's copy; before
 * 3.46 that silently returned revoked sign-in tokens and connections, blocked accounts, old passwords, removed passkeys,
 * erased people and unsubscribed addresses, and sent the queues of the backup a second time.
 *
 *  - The ledger (table ka_restore_ledger) is never written into a backup (Backup::create skips it) and a restore never
 *    touches it (Backup::restore skips its statements), so it outlives every restore. It holds keyed fingerprints of erased
 *    and unsubscribed addresses (HMAC-SHA256 with the ledger's own random key – never an address), the fingerprints of the
 *    tokens and passkeys a restore already removed once, and the one-way ids of the site's signing keys.
 *  - capture() reads the sign-in state of the site right before a restore; reapply() puts it back over the restored tables:
 *    the passwords, two-step secrets, blocks and sign-in epochs of today, no token, passkey, OAuth code or outside-service
 *    connection that is gone today, every account the backup has and today's site does not stays blocked. Only for a
 *    backup of this site (its signing key is known to the ledger): a backup restored onto a new installation after losing
 *    the hosting keeps its own accounts.
 *  - Erasures and unsubscribes recorded after the backup was made are reapplied (PersonalData::erase, the subscriber
 *    removed); those recorded before it are already in the backup.
 *  - Rows the backup had waiting in the mail, webhook, connector, newsletter and mailing-service queues are stopped,
 *    unless the same row is still waiting today – what went out since is never sent a second time.
 *  - preview() says before the restore what it loses and what it keeps.
 */
final class RestoreLedger
{
    /**
     * 3.46.1 (re-audit M1): the kind of a record for an application its applicant deleted through the talent-pool link – the
     * fingerprint of its number and address (applicationSubject), so a restore deletes that application again and nothing else.
     */
    public const string APPLICATION_DELETED = 'application_deleted';

    /** The error of a queue row a restore stopped (stored as data, like the other delivery errors). */
    public const string STOPPED = 'Restored from a backup – not sent again.';

    /**
     * The columns of an account that carry the state of today over a restore: the sign-in state, and (review M3) the level,
     * the custom role and the e-mail the password reset goes to.
     */
    private const array ACCOUNT_COLUMNS = ['password', 'blokovat', 'blokovano_automaticky', 'pocet_chyb', 'zamceno_do', 'obnova_otisk', 'obnova_cas', 'totp_tajemstvi',
        'totp_zalozni', 'passkey_handle', 'passkey_only', 'sign_in_epoch', 'admin', 'role', 'email'];

    /**
     * Review M3: the settings of the sign-in rules and the console pairing that are today's after a restore (the stored values
     * as they are – a sealed one stays sealed under today's key). The signing key (secret_key, secret_key_previous) is
     * carried too, see signingKeys().
     */
    public const array CARRIED_SETTINGS = ['require_2fa', 'signin_passkey_only', 'webhook_secret', 'site_key_secret', 'fleet_console_url', 'fleet_console_key',
        'fleet_console_name', 'fleet_site_id', 'fleet_updates', 'fleet_answers_bound', 'fleet_last_ts', 'backup_site_id',
        // 3.46.1 (re-audit M5): who reads the whistleblowing channel, the rules of Claude connections, the secrets of cron, the
        // health check and the screen, the firewall and the CAPTCHA – access taken away or tightened since stays so
        'whistleblowing_readers', 'claude_destructive', 'claude_protected_pages', 'claude_apps_only', 'claude_change_limit', 'tasks_token', 'health_token', 'screen_secret',
        'firewall_enabled', 'firewall_ips', 'firewall_countries', 'firewall_probes', 'firewall_rate', 'firewall_proxy',
        'captcha_provider', 'captcha_site_key', 'captcha_secret', 'captcha_fail_open'];

    /** Review M4: the settings that say "sent already" for a period – today's value stays when it is later than the backup's. */
    public const array SENT_MARKERS = ['report_last_month', 'fleet_digest_sent', 'inbox_digest_day'];

    /**
     * Review M4: the columns that say a row's e-mail or announcement went out – table => [id column, columns]. A restored row
     * whose column is empty while today's says sent takes today's value, so nothing goes out a second time.
     *
     * @var array<string, array{0: string, 1: list<string>}>
     */
    public const array SENT_FLAGS = [
        'novinky' => ['idc', ['oznameno']],                      // the publishing announcement (webhook, IndexNow)
        'applications' => ['id', ['pool_renewal_sent_at']],     // the talent-pool renewal e-mail
        'bookings' => ['id', ['reminded_at', 'hold_reminded_at']], // booking reminders
        'tasks' => ['id', ['notified_at', 'escalated_at']],     // task reminders and escalations
        'notifications' => ['id', ['emailed_at']],              // the e-mails of the bell
    ];

    /** The credentials of an outside-service connection (ka_connectors) that are today's after a restore. */
    private const array CONNECTOR_COLUMNS = ['account', 'client_id', 'secret', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'connected_at', 'connected_by'];

    /**
     * The queues a restore must not send again: table => [id column, "waiting" condition, what stops a row, what tells one
     * row from another besides its id (review L9: after an earlier restore an id can be given to another message)].
     *
     * @var array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public const array QUEUES = [
        'posta' => ['idp', 'odeslano IS NULL AND telo IS NOT NULL AND dalsi_pokus IS NOT NULL', 'dalsi_pokus = NULL, chyba = ?', "CONCAT(vytvoreno, '|', komu)"],
        'webhook_deliveries' => ['id', 'next_attempt IS NOT NULL AND body IS NOT NULL', 'next_attempt = NULL, error = ?', "CONCAT(created, '|', event)"],
        'connector_queue' => ['id', 'next_attempt IS NOT NULL', 'next_attempt = NULL, last_error = ?', "CONCAT(created_at, '|', action)"],
        'newsletter_queue' => ['id', 'next_attempt IS NOT NULL', 'next_attempt = NULL, error = ?', "CONCAT(newsletter_id, '|', subscriber_id)"],
        'odber_fronta' => ['idf', 'dalsi IS NOT NULL', 'dalsi = NULL, chyba = ?', "CONCAT(vytvoreno, '|', email)"],
    ];

    /**
     * Review M2: the state of today written before a restore starts, deleted when the restore and its reapply are over. A
     * request that dies in between (a time or memory limit, a deploy) leaves it, and the next request finishes the reapply
     * (guard()). It holds no more than the safety backup next to it (password hashes, sealed secrets); the folder is never
     * served (storage/, its own deny-all .htaccess).
     */
    public const string STATE_FILE = Backup::FOLDER . '/.restore-state';

    /** True while another request restores the database (guard() found its lock): no sending and no token in this request. */
    public static bool $busy = false;

    /**
     * Records an erased or unsubscribed address (its keyed fingerprint only). Review M1: the LAST time counts – an address
     * erased or unsubscribed again moves its record forward, so a restore reapplies it to every backup made before that.
     */
    public static function record(Db $db, string $kind, string $email): void
    {
        $email = PersonalData::cleanAddress($email);
        if ($email === '' || !in_array($kind, ['erased', 'unsubscribed'], true)) {
            return;
        }
        try {
            $db->run('INSERT INTO {restore_ledger} (kind, hash, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE created_at = VALUES(created_at)', [$kind, self::fingerprint($db, $email), Clock::now()]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // before migration 0133
        }
    }

    /** 3.46.1 (re-audit M1): an application deleted at its applicant's request (TalentPool::remove) – a restore deletes it again. */
    public static function recordApplication(Db $db, int $id, string $email): void
    {
        if ($id <= 0 || PersonalData::cleanAddress($email) === '') {
            return;
        }
        try {
            $db->run('INSERT INTO {restore_ledger} (kind, hash, created_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE created_at = VALUES(created_at)',
                [self::APPLICATION_DELETED, self::applicationFingerprint($db, $id, $email), Clock::now()]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // before migration 0133
        }
    }

    /** The keyed fingerprint of a deleted application (its number and address) – what recordApplication() stores. */
    public static function applicationFingerprint(Db $db, int $id, string $email): string
    {
        return self::keyedHash(self::key($db), self::applicationSubject($id, $email));
    }

    /** What the fingerprint of a deleted application is made of: its number and its address (cleaned). Pure. */
    public static function applicationSubject(int $id, string $email): string
    {
        return 'application:' . $id . '|' . PersonalData::cleanAddress($email);
    }

    /** A new confirmed subscription after an unsubscribe: a restore must not remove it again. */
    public static function forget(Db $db, string $kind, string $email): void
    {
        try {
            $db->run('DELETE FROM {restore_ledger} WHERE kind = ? AND hash = ?', [$kind, self::fingerprint($db, PersonalData::cleanAddress($email))]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);
        }
    }

    /** The keyed fingerprint of an address (cleaned, lower case): HMAC-SHA256 with the ledger's own key. */
    public static function fingerprint(Db $db, string $email): string
    {
        return self::keyedHash(self::key($db), PersonalData::cleanAddress($email));
    }

    /** HMAC-SHA256 of an address under a key (hex). Pure. */
    public static function keyedHash(string $key, string $email): string
    {
        return hash_hmac('sha256', $email, $key);
    }

    /**
     * The ledger's own random key – made the first time it is needed, kept in the ledger (outside every backup). Review L7,
     * judged: the key stays next to the fingerprints. A setting would travel with every backup, and a key derived from the
     * Vault key in config.php would change the fingerprints when that key moves (a site without it at first) – the records
     * made before would no longer match. So whoever reads the live database can test addresses against the fingerprints;
     * the backups – what leaves the server – never hold either.
     */
    private static function key(Db $db): string
    {
        $key = (string) $db->value("SELECT hash FROM {restore_ledger} WHERE kind = 'key' ORDER BY id LIMIT 1");
        if ($key === '') {
            $db->run("INSERT IGNORE INTO {restore_ledger} (kind, hash, created_at) VALUES ('key', ?, ?)", [bin2hex(random_bytes(32)), Clock::now()]);
            $key = (string) $db->value("SELECT hash FROM {restore_ledger} WHERE kind = 'key' ORDER BY id LIMIT 1");
        }

        return $key;
    }

    /** Review L1: a signing key the site used (a new one, the one it replaced) – a backup made under it is this site's. */
    public static function recordSiteKey(Db $db, string $key): void
    {
        if ($key !== '') {
            self::attempt(fn () => self::insert($db, 'site', hash('sha256', $key)), null);
        }
    }

    /**
     * When a backup was made, as the site's wall time (Y-m-d H:i:s), from its name (kaleta-YYYYMMDD-HHMMSS-…, also an
     * uploaded ….enc copy); null for a name without it. Pure.
     */
    public static function madeAt(string $file): ?string
    {
        if (preg_match('/^kaleta-(\d{4})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})-/', basename($file), $m) !== 1 || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
    }

    /**
     * Review L8: when a backup was made – the earlier of the time its first line states (Backup::header, also of a decrypted
     * copy during a restore) and the time in its name; the file's time only when neither says. The earlier time reapplies
     * more erasures, never fewer, so a renamed copy cannot skip one. Null when nothing tells (then all are reapplied).
     */
    public static function backupTime(string $file, ?string $headerTime = null): ?string
    {
        $path = Backup::path(basename($file));
        $headerTime ??= $path !== null ? (Backup::header($path)['made'] ?? null) : null;
        $times = array_filter([$headerTime, self::madeAt($file)]);
        if ($times !== []) {
            return min($times);
        }

        return $path !== null ? date('Y-m-d H:i:s', (int) filemtime($path)) : null;
    }

    /**
     * Review L8: whose backup this is by the key its first line names – 'site' (a signing key the ledger knows), 'other' (one
     * it does not know) or 'unknown' (an encrypted copy or a backup from before 3.46, whose first line names no key).
     */
    public static function backupOrigin(Db $db, string $file): string
    {
        $path = Backup::path(basename($file));
        $key = $path !== null ? (Backup::header($path)['key'] ?? null) : null;
        if ($key === null) {
            return 'unknown';
        }
        foreach (self::knownSiteKeys($db) as $hash) {
            if (str_starts_with($hash, $key)) {
                return 'site';
            }
        }

        return 'other';
    }

    /** @return list<string> the one-way ids of every signing key the site is known to have used (the ledger and today's) */
    private static function knownSiteKeys(Db $db): array
    {
        return array_values(array_unique(array_merge(self::siteKeys($db), self::attempt(fn (): array => array_map('strval', array_column($db->all("SELECT hash FROM {restore_ledger} WHERE kind = 'site'"), 'hash')), []))));
    }

    /**
     * The state of the site right before a restore: the sign-in state, the rights, the signing key and the carried settings,
     * the newsletters, what was sent since the backup ($madeAt) and the waiting queue rows. The site's signing keys go into
     * the ledger at once, so the backup about to be restored is recognised as this site's.
     *
     * @return array<string, mixed>
     */
    public static function capture(Db $db, ?string $madeAt = null, int $user = 0): array
    {
        $site = self::siteKeys($db);
        foreach ($site as $hash) {
            self::attempt(fn () => self::insert($db, 'site', $hash), null); // before migration 0133 the keys are compared in memory only
        }
        $queues = [];
        foreach (self::QUEUES as $table => [$id, $waiting, , $sign]) {
            $queues[$table] = self::attempt(fn (): array => array_map(fn (array $r): string => $r['id'] . '|' . $r['s'], $db->all('SELECT ' . $id . ' AS id, ' . $sign . ' AS s FROM {' . $table . '} WHERE ' . $waiting)), []);
        }
        $accounts = [];
        foreach (self::attempt(fn (): array => $db->all('SELECT * FROM {uzivatele}'), []) as $row) {
            $accounts[(int) $row['idu']] = array_intersect_key($row, array_flip([...self::ACCOUNT_COLUMNS, 'user', 'jmeno'])); // jmeno: only to put the restoring account back
        }
        $rights = [];
        foreach (self::attempt(fn (): array => $db->all('SELECT fk_id_user, ident_modulu FROM {uzivatele_prava}'), []) as $row) {
            $rights[(int) $row['fk_id_user']][] = (string) $row['ident_modulu'];
        }
        $connectors = [];
        foreach (self::attempt(fn (): array => $db->all('SELECT * FROM {connectors}'), []) as $row) {
            $connectors[(string) $row['service']] = array_intersect_key($row, array_flip(self::CONNECTOR_COLUMNS));
        }
        $settings = self::attempt(fn (): array => $db->pairs('SELECT promenna, hodnota FROM {nastaveni} WHERE promenna IN (' . implode(',', array_fill(0, count(self::CARRIED_SETTINGS) + count(self::SENT_MARKERS) + 2, '?')) . ')',
            [...self::CARRIED_SETTINGS, ...self::SENT_MARKERS, 'secret_key', 'secret_key_previous']), []);
        $flags = [];
        foreach (self::SENT_FLAGS as $table => [$id, $columns]) {
            foreach ($columns as $column) {
                $flags[$table][$column] = self::attempt(fn (): array => $db->pairs('SELECT ' . $id . ', ' . $column . ' FROM {' . $table . '} WHERE ' . $column . ' IS NOT NULL' . ($madeAt !== null ? ' AND ' . $column . ' >= ?' : ''), $madeAt !== null ? [$madeAt] : []), []);
            }
        }

        return [
            'site' => $site,
            'user' => $user,
            'accounts' => $accounts,
            'rights' => $rights,
            'tokens' => self::attempt(fn (): array => array_map('strval', array_column($db->all('SELECT otisk FROM {api_tokeny}'), 'otisk')), []),
            'passkeys' => self::attempt(fn (): array => array_map('strval', array_column($db->all('SELECT otisk_id FROM {uzivatele_klice}'), 'otisk_id')), []),
            'codes' => self::attempt(fn (): array => array_map('strval', array_column($db->all('SELECT otisk FROM {oauth_kody}'), 'otisk')), []),
            'connectors' => $connectors,
            'settings' => $settings,
            'newsletters' => self::attempt(fn (): array => $db->all('SELECT id, status, scheduled_at, started_at, finished_at, recipients, sent_count, failed_count FROM {newsletters}'), []),
            'flags' => $flags,
            'queues' => $queues,
        ];
    }

    /**
     * Review M2: writes the state of today and the restore under way to STATE_FILE (mode 0600) – before the restore touches
     * a table. $phase 'restoring' until Backup::restore() is over, then 'restored'.
     *
     * @param array<string, mixed> $today capture()
     */
    public static function begin(array $today, string $file, string $safetyBackup, ?string $madeAt): void
    {
        self::writeState(['phase' => 'restoring', 'file' => basename($file), 'safety' => $safetyBackup, 'made_at' => $madeAt, 'started' => time(), 'today' => $today], Backup::UNTOUCHED);
    }

    /** The restore itself is over (review M2): the next request knows the tables are the backup's, not half of them. */
    public static function restored(?string $madeAt = null): void
    {
        $state = self::readState();
        if ($state !== null) {
            $state['phase'] = 'restored';
            $state['made_at'] = $madeAt ?? $state['made_at'];
            try {
                self::writeState($state);
            } catch (\RuntimeException $e) {
                ErrorLog::write(ErrorLog::exception($e)); // the restore is done; the state of today is still there for a later request
            }
        }
    }

    /** The restore and its reapply are over: the state of today is not needed any more. */
    public static function end(): void
    {
        @unlink(self::STATE_FILE);
    }

    /** @param array<string, mixed> $state */
    private static function writeState(array $state, int $code = 0): void
    {
        if (!is_dir(Backup::FOLDER)) {
            @mkdir(Backup::FOLDER, 0775, true);
        }
        $temporary = self::STATE_FILE . '.' . bin2hex(random_bytes(4));
        $old = umask(0077);
        try {
            if (@file_put_contents($temporary, serialize($state), LOCK_EX) === false || !@rename($temporary, self::STATE_FILE)) {
                @unlink($temporary);
                throw new \RuntimeException('The state before the restore could not be written to storage/zalohy – check the write permissions. Nothing was restored.', $code);
            }
        } finally {
            umask($old);
        }
        @chmod(self::STATE_FILE, 0600);
    }

    /** @return array<string, mixed>|null */
    private static function readState(): ?array
    {
        $raw = is_file(self::STATE_FILE) ? @file_get_contents(self::STATE_FILE) : false;
        $state = is_string($raw) ? @unserialize($raw, ['allowed_classes' => false]) : false;

        return is_array($state) && is_array($state['today'] ?? null) ? $state : null;
    }

    /** The database lock a restore holds from its start to the end of its reapply (one restore at a time, per database and prefix). */
    public static function lock(Db $db, int $wait = 0): bool
    {
        return (int) $db->value('SELECT GET_LOCK(?, ?)', [$db->lockName('restore'), $wait]) === 1; // 3.46.1 (N3): per database, not per server
    }

    public static function unlock(Db $db): void
    {
        $db->value('SELECT RELEASE_LOCK(?)', [$db->lockName('restore')]);
    }

    /**
     * Review M2, at the start of every request (Front\Kernel, Admin\Kernel): 'none' – no restore left a state; 'running' –
     * another request restores right now (it holds the lock): this request sends nothing and accepts no token (self::$busy);
     * 'finished' – a restore whose request died left its state: the reapply runs now, before anything else, and the event
     * says so.
     */
    public static function guard(App $app): string
    {
        if (!is_file(self::STATE_FILE)) {
            return 'none';
        }
        $db = $app->db();
        if (!self::lock($db)) {
            self::$busy = true;

            return 'running';
        }
        try {
            $state = self::readState();
            if ($state === null) {
                self::end();

                return 'none';
            }
            $file = (string) $state['file'];
            $madeAt = is_string($state['made_at'] ?? null) ? $state['made_at'] : self::madeAt($file);
            $half = ($state['phase'] ?? '') !== 'restored';
            // 3.46.1 (re-audit L1): a restore that died before the batch with the signing key left none – this site's then
            $today = ['half' => $half] + $state['today'];
            $done = self::reapplyFirst($app, $today, $file);
            self::migrate($app);
            $done = self::merge($done, self::reapplyAfter($app, $today, $madeAt));
            Backup::sweepLeftovers(0, false); // 3.46.1 (re-audit L2): the decrypted copy the dead restore left (this request holds the lock)
            Events::record($db, 'backup.restored', $half ? 'error' : 'warning', $half
                ? t('A restore from backup %s stopped before its end; what it must never bring back was put back afterwards. The database may be half restored – restore backup %s (the state before) or %s again.', $file, (string) $state['safety'], $file)
                : t('The database was restored from backup %s; the state before the restore is in backup %s.', $file, (string) $state['safety']), ['file' => $file, 'safety_backup' => (string) $state['safety'], 'finished_later' => true, 'half' => $half] + $done);
            self::end();

            return 'finished';
        } finally {
            self::unlock($db);
        }
    }

    /** The pending migrations of a restored older backup (its settings read fresh). */
    public static function migrate(App $app): void
    {
        $app->settings()->reload();
        try {
            if (Migration::pending($app->settings(), Migration::latest())) {
                Migration::apply($app->db(), $app->settings());
            }
        } catch (\Throwable $e) {
            Migration::writeError($e);
        }
        $app->settings()->reload();
    }

    /**
     * Right after Backup::restore(), before the migrations (review M2): the queues stopped, today's sign-in state, rights,
     * signing key and carried settings over the restored tables (a backup of this site only), the newsletters and the sent
     * markers – the tables they touch exist in every backup since 1.x; a part that fails is retried by reapplyAfter().
     *
     * @param array<string, mixed> $before capture()
     * @return array<string, int|bool>
     */
    public static function reapplyFirst(App $app, array $before, string $file = ''): array
    {
        $db = $app->db();
        $app->settings()->reload();
        $done = ['same_site' => self::sameSite($db, $before['site'], (bool) ($before['half'] ?? false))];
        $failed = [];
        $step = self::stepper($done, $failed);
        $step('queues', fn (): array => ['queue_rows_stopped' => self::stopQueues($db, $before['queues'])]);
        if ($done['same_site']) {
            $step('accounts', fn (): array => self::accounts($db, $before['accounts'], (int) ($before['user'] ?? 0)));
            $step('rights', fn (): array => ['rights_kept' => self::rights($db, $before['rights'] ?? [], $before['accounts'])]);
            $step('tokens', fn (): array => ['tokens_removed' => self::removeGone($db, 'api_tokeny', 'otisk', 'token', $before['tokens'])]);
            $step('passkeys', fn (): array => ['passkeys_removed' => self::removeGone($db, 'uzivatele_klice', 'otisk_id', 'passkey', $before['passkeys'])]);
            $step('codes', fn (): array => ['codes_removed' => self::removeCodes($db, $before['codes'])]);
            $step('connectors', fn (): array => ['connections_kept' => self::connectors($db, $before['connectors'])]);
            $step('signing_key', fn (): array => self::signingKeys($app, $before['settings'] ?? []));
            $step('settings', fn (): array => ['settings_kept' => self::settings($db, $before['settings'] ?? [])]);
        }
        $step('newsletters', fn (): array => ['newsletters_stopped' => self::newsletters($db, $before['newsletters'] ?? [])]);
        $step('sent', fn (): array => ['sent_flags_kept' => self::sentFlags($db, $before['flags'] ?? [], $before['settings'] ?? [])]);
        $app->settings()->reload();
        $done['failed_first'] = $failed;

        return $done;
    }

    /**
     * After the migrations: the accounts once more (a column an older backup lacked exists now), the erasures and
     * unsubscribes recorded after the backup was made, and what failed before the migrations once more.
     *
     * @param array<string, mixed> $before capture()
     * @return array<string, int|bool>
     */
    public static function reapplyAfter(App $app, array $before, ?string $madeAt): array
    {
        $db = $app->db();
        $done = [];
        $failed = [];
        $step = self::stepper($done, $failed);
        if (self::sameSite($db, $before['site'], (bool) ($before['half'] ?? false))) {
            $step('accounts', fn (): array => ['accounts_again' => self::accounts($db, $before['accounts'], (int) ($before['user'] ?? 0))['accounts_kept']]);
            $step('rights', fn (): array => ['rights_again' => self::rights($db, $before['rights'] ?? [], $before['accounts'])]);
            $step('settings', fn (): array => ['settings_again' => self::settings($db, $before['settings'] ?? [])]);
        }
        $step('queues', fn (): array => ['queue_rows_again' => self::stopQueues($db, $before['queues'])]);
        $step('sent', fn (): array => ['sent_flags_again' => self::sentFlags($db, $before['flags'] ?? [], $before['settings'] ?? [])]);
        $step('people', fn (): array => self::people($app, $madeAt));
        $step('returned', fn (): array => ['returned' => self::forgetReturned($db)]);
        $app->settings()->reload();
        $done['failed_after'] = $failed;

        return $done;
    }

    /**
     * Both halves in one answer for the summary and the event: the counts of the first, the people of the second; failed
     * only when a part failed after the migrations too (or was never retried).
     *
     * @param array<string, mixed> $first
     * @param array<string, mixed> $after
     * @return array<string, int|bool>
     */
    public static function merge(array $first, array $after): array
    {
        $retried = ['accounts', 'rights', 'settings', 'queues', 'sent']; // run again after the migrations – their result there counts
        $failed = array_values(array_unique([...array_diff((array) ($first['failed_first'] ?? []), $retried), ...(array) ($after['failed_after'] ?? [])]));
        unset($first['failed_first'], $after['failed_after']);
        $done = $first + array_intersect_key($after, array_flip(['erased', 'unsubscribed']));
        $done['failed'] = $failed !== [];
        $done['failed_parts'] = implode(', ', $failed);

        return $done;
    }

    /**
     * After a restore in one call (what a later request finishes, and the tests): reapplyFirst, the migrations, reapplyAfter;
     * a part that could not be put back is an event.
     *
     * @param array<string, mixed> $before capture()
     * @return array<string, int|bool|string>
     */
    public static function reapply(App $app, array $before, ?string $madeAt): array
    {
        $first = self::reapplyFirst($app, $before);
        self::migrate($app);
        $done = self::merge($first, self::reapplyAfter($app, $before, $madeAt));
        self::reportFailed($app->db(), $done);

        return $done;
    }

    /** @param array<string, mixed> $done merge() */
    public static function reportFailed(Db $db, array $done): void
    {
        if (!empty($done['failed'])) {
            Events::record($db, 'backup.restore_ledger_failed', 'error', t('After the restore, some of what the restore must not bring back could not be checked (%s). Look at the error log and restore again if needed.', (string) $done['failed_parts']), ['parts' => (string) $done['failed_parts']]);
        }
    }

    /**
     * @param array<string, mixed> $done
     * @param list<string> $failed
     * @return callable(string, callable(): array<string, mixed>): void
     */
    private static function stepper(array &$done, array &$failed): callable
    {
        return function (string $name, callable $fn) use (&$done, &$failed): void {
            try {
                $done += $fn();
            } catch (\Throwable $e) {
                ErrorLog::write(ErrorLog::exception($e));
                $failed[] = $name;
            }
        };
    }

    /**
     * What a restore of a backup made at $madeAt loses and what it keeps (shown before the restore). Counts only. Review L9:
     * the Backups tab asks for all its rows at once (previewMany) – seven queries for the whole list, not seven per row.
     *
     * @return array{lost: array<string, int>, kept: array<string, int>, known: bool}
     */
    public static function preview(Db $db, ?string $madeAt): array
    {
        return self::previewMany($db, [$madeAt])[0];
    }

    /**
     * @param list<string|null> $times when each backup was made (null = unknown)
     * @return list<array{lost: array<string, int>, kept: array<string, int>, known: bool}>
     */
    public static function previewMany(Db $db, array $times): array
    {
        $queries = [
            'lost' => ['enquiries' => ['poptavky', 'datum', ''], 'applications' => ['applications', 'received_at', ''], 'bookings' => ['bookings', 'created_at', ''],
                'subscribers' => ['odberatele', 'datum', ''], 'changes' => ['protokol', 'cas', '']],
            'kept' => ['erasures' => ['restore_ledger', 'created_at', "kind IN ('erased', '" . self::APPLICATION_DELETED . "')"], 'unsubscribes' => ['restore_ledger', 'created_at', "kind = 'unsubscribed'"]],
        ];
        $since = array_map(fn (?string $t): string => $t ?? '1000-01-01 00:00:00', $times);
        $out = array_map(fn (?string $t): array => ['lost' => [], 'kept' => [], 'known' => $t !== null], $times);
        if ($times === []) {
            return [];
        }
        foreach ($queries as $group => $items) {
            foreach ($items as $name => [$table, $column, $where]) {
                $sums = implode(', ', array_map(fn (int $i): string => 'COALESCE(SUM(' . $column . ' > ?), 0) AS c' . $i, array_keys($since)));
                $row = self::attempt(fn (): ?array => $db->one('SELECT ' . $sums . ' FROM {' . $table . '}' . ($where !== '' ? ' WHERE ' . $where : ''), $since), null);
                foreach (array_keys($since) as $i) {
                    $out[$i][$group][$name] = (int) ($row['c' . $i] ?? 0);
                }
            }
        }

        return $out;
    }

    /**
     * preview() in words, for the confirmation of a restore. Review L8: an unknown time says so instead of counts, and the
     * sign-in promise is made only for a backup of this site ($origin backupOrigin(): 'site', 'other', 'unknown').
     *
     * @param array{lost: array<string, int>, kept: array<string, int>, known?: bool} $preview
     */
    public static function previewText(array $preview, string $origin = 'site'): string
    {
        $lost = $preview['lost'];
        $text = ($preview['known'] ?? true)
            ? t('Since this backup, the restore loses %d enquiries, %d job applications, %d bookings, %d newsletter sign-ups and %d changes in the change log.',
                $lost['enquiries'], $lost['applications'], $lost['bookings'], $lost['subscribers'], $lost['changes'])
            : t('It is not known when this backup was made: everything added to the site since then is lost, and every erasure and unsubscribe on record is applied again.');
        $text .= ' ' . match ($origin) {
            'other' => t('The backup comes from another installation: its accounts, passwords and connections come back as they are in it.'),
            'unknown' => t('If the backup comes from this site, the restore keeps the sign-in security of today (revoked tokens and connections, blocked or removed accounts, changed passwords, roles and e-mails, two-step sign-in and passkeys).'),
            default => t('It keeps the sign-in security of today (revoked tokens and connections, blocked or removed accounts, changed passwords, roles and e-mails, two-step sign-in and passkeys).'),
        };
        if ($preview['known'] ?? true) {
            $text .= ' ' . t('It applies again %d erasures of personal data and %d unsubscribes made since.', $preview['kept']['erasures'], $preview['kept']['unsubscribes']);
        }
        // 3.46.1 (re-audit M5, L8a): what the restore cannot know or leaves to the backup on purpose
        $text .= ' ' . t('Erasures and unsubscribes made before the update to Kaleta 3.46 are not on record.');
        if ($origin !== 'other') {
            $text .= ' ' . self::backupsOwnText();
        }

        return $text . ' ' . t('Queued e-mails, webhooks, newsletters and deliveries from the backup are not sent again.');
    }

    /** 3.46.1 (re-audit M5): the settings a restore of this site's backup leaves as they are in the backup – to check afterwards. */
    public static function backupsOwnText(): string
    {
        return t('Webhook addresses, the off-site backup target, add-ons and who may open each internal folder are as they are in the backup – check them after the restore.');
    }

    /** reapply() in words, for the message after a restore. @param array<string, int|bool|string> $done */
    public static function summary(array $done): string
    {
        $text = t('Kept from today: %d tokens and %d passkeys the backup had were removed, %d accounts kept their current sign-in, %d accounts the backup had are blocked; %d erasures and %d unsubscribes applied again; %d queued deliveries from the backup stopped.',
            (int) ($done['tokens_removed'] ?? 0), (int) ($done['passkeys_removed'] ?? 0), (int) ($done['accounts_kept'] ?? 0), (int) ($done['accounts_blocked'] ?? 0),
            (int) ($done['erased'] ?? 0), (int) ($done['unsubscribed'] ?? 0), (int) ($done['queue_rows_stopped'] ?? 0));
        if (!($done['same_site'] ?? false)) {
            $text .= ' ' . t('The backup comes from another installation, so its accounts and connections stay as they are in it.');
        } elseif (($done['signing_key'] ?? '') === 'backup') {
            $text .= ' ' . t('The signing key is the backup\'s: the stored secrets could not be encrypted again with today\'s key.');
        }
        if ($done['same_site'] ?? false) {
            $text .= ' ' . self::backupsOwnText(); // 3.46.1 (re-audit M5)
        }

        return $text;
    }

    /**
     * Is the restored database a backup of this site? Its signing key is one the ledger knows (recorded before this
     * restore or any earlier one, by migration 0133 and by every new key – review L1).
     *
     * @param list<string> $before the keys of the site before the restore
     * @param bool $half a restore that died half-way (re-audit L1): no key yet means the settings table is not restored – this site
     */
    private static function sameSite(Db $db, array $before, bool $half = false): bool
    {
        $restored = self::siteKeys($db);
        if ($restored === []) {
            return $half;
        }
        $known = array_merge($before, self::attempt(fn (): array => array_map('strval', array_column($db->all("SELECT hash FROM {restore_ledger} WHERE kind = 'site'"), 'hash')), []));

        return array_intersect($restored, $known) !== [];
    }

    /** @return list<string> the one-way ids of the signing key and the previous one, as stored (sha256) */
    private static function siteKeys(Db $db): array
    {
        $values = self::attempt(fn (): array => $db->pairs("SELECT promenna, hodnota FROM {nastaveni} WHERE promenna IN ('secret_key', 'secret_key_previous')"), []);
        $keys = [(string) ($values['secret_key'] ?? ''), explode('|', (string) ($values['secret_key_previous'] ?? ''), 2)[0]];

        return array_values(array_unique(array_map(fn (string $k): string => hash('sha256', $k), array_filter($keys, fn (string $k): bool => $k !== ''))));
    }

    /**
     * The accounts (review L2: by their id alone – a sign-in name changed since does not make an account "removed"): one
     * that exists today gets today's sign-in state, level, role and e-mail (and its sign-in name when no other account holds
     * it); one that is gone today stays blocked – unless an earlier restore took it away (it was never removed: undoing
     * a restore with its safety backup), and never the account that runs the restore. A site is never left without an
     * active administrator: the restoring account is unblocked then.
     *
     * @param array<int, array<string, mixed>> $before
     * @return array{accounts_kept: int, accounts_blocked: int}
     */
    private static function accounts(Db $db, array $before, int $user): array
    {
        $columns = array_map('strval', array_column($db->all('SHOW COLUMNS FROM {uzivatele}'), 'Field'));
        $lost = self::remembered($db, 'account_lost');
        $kept = 0;
        $blocked = 0;
        $restored = [];
        foreach ($db->all('SELECT idu, user, blokovat FROM {uzivatele}') as $row) {
            $id = (int) $row['idu'];
            $restored[$id] = true;
            $today = $before[$id] ?? null;
            if ($today !== null) {
                $data = array_intersect_key($today, array_flip(array_intersect(self::ACCOUNT_COLUMNS, $columns)));
                if ($data !== []) {
                    $db->update('uzivatele', $data, ['idu' => $id]);
                    $kept++;
                }
                if ((string) $today['user'] !== (string) $row['user']) {
                    try {
                        $db->update('uzivatele', ['user' => (string) $today['user']], ['idu' => $id]);
                    } catch (\PDOException) {
                        // any database error here (the name is another restored account's – unique) only keeps the backup's name
                    }
                }
            } elseif (isset($lost[hash('sha256', 'account:' . $id)])) {
                // taken away by a restore, back with its undo – as it was (the record goes at the end, forgetReturned())
            } elseif ((int) $row['blokovat'] === 0 && $id !== $user) {
                $db->update('uzivatele', ['blokovat' => 1], ['idu' => $id]);
                $db->delete('api_tokeny', ['idu' => $id]);
                $blocked++;
            }
        }
        // accounts of today the backup does not have: a later restore (the undo with the safety backup) brings them back unblocked
        foreach (array_keys(array_diff_key($before, $restored)) as $id) {
            self::insert($db, 'account_lost', hash('sha256', 'account:' . $id));
        }
        // review N1: the account that restores is there afterwards – put back from today (credentials, level, role, e-mail; its
        // rights follow in rights()) when the backup is older than it; and the site keeps an administrator who can sign in
        if ($user > 0 && !isset($restored[$user]) && isset($before[$user])) {
            $row = ['idu' => $user] + array_intersect_key($before[$user], array_flip(array_intersect([...self::ACCOUNT_COLUMNS, 'user', 'jmeno'], $columns)));
            try {
                $db->insert('uzivatele', $row);
            } catch (\PDOException) {
                // any database error here is the sign-in name another restored account holds (unique) – it comes back under a free one
                $db->insert('uzivatele', ['user' => mb_substr((string) $row['user'], 0, 30) . '-' . $user] + $row);
            }
            $restored[$user] = true;
        }
        if ($user > 0 && isset($restored[$user]) && (int) $db->value('SELECT COUNT(*) FROM {uzivatele} WHERE admin = 2 AND blokovat = 0') === 0) {
            $db->update('uzivatele', ['blokovat' => 0, 'admin' => 2], ['idu' => $user]); // never a site without an administrator who can sign in
        }

        return ['accounts_kept' => $kept, 'accounts_blocked' => $blocked];
    }

    /**
     * Review M3: the section rights of the accounts that exist today are today's (a person whose sections were taken away
     * since the backup does not get them back).
     *
     * @param array<int, list<string>> $rights
     * @param array<int, array<string, mixed>> $accounts
     */
    private static function rights(Db $db, array $rights, array $accounts): int
    {
        $count = 0;
        foreach (array_map('intval', array_column($db->all('SELECT idu FROM {uzivatele}'), 'idu')) as $id) {
            if (!isset($accounts[$id])) {
                continue;
            }
            $db->delete('uzivatele_prava', ['fk_id_user' => $id]);
            foreach ($rights[$id] ?? [] as $module) {
                $db->run('INSERT IGNORE INTO {uzivatele_prava} (fk_id_user, ident_modulu) VALUES (?, ?)', [$id, $module]);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Removes the restored rows of a sign-in table that are not there today (revoked or removed since the backup) or that a
     * restore removed before; the ledger remembers them. Review L2: a row today has and the backup lacks was taken away by
     * this restore, not revoked – it is remembered as such (<kind>_lost), so undoing the restore with its safety backup
     * keeps it instead of removing it for good.
     *
     * @param list<string> $today
     */
    private static function removeGone(Db $db, string $table, string $column, string $kind, array $today): int
    {
        $remembered = self::remembered($db, $kind);
        $lost = self::remembered($db, $kind . '_lost');
        $today = array_flip($today);
        $removed = 0;
        $restored = array_map('strval', array_column($db->all('SELECT ' . $column . ' AS h FROM {' . $table . '}'), 'h'));
        foreach ($restored as $hash) {
            if (isset($remembered[$hash])) {
                $removed += $db->delete($table, [$column => $hash]);
            } elseif (isset($lost[$hash]) && !isset($today[$hash])) {
                // a restore took it away, its undo brings it back (the record goes at the end, forgetReturned())
            } elseif (!isset($today[$hash])) {
                $removed += $db->delete($table, [$column => $hash]);
                self::insert($db, $kind, $hash);
            }
        }
        foreach (array_keys(array_diff_key($today, array_flip($restored))) as $hash) {
            self::insert($db, $kind . '_lost', (string) $hash);
        }

        return $removed;
    }

    /**
     * Review L2, at the very end of a reapply: what a restore took away and this restore brought back (an account, a token, a
     * passkey that exists again) is no longer remembered as taken away. Returns how many records went.
     */
    private static function forgetReturned(Db $db): int
    {
        $count = 0;
        foreach (['account_lost' => fn (): array => array_map(fn (array $r): string => hash('sha256', 'account:' . $r['idu']), $db->all('SELECT idu FROM {uzivatele}')),
            'token_lost' => fn (): array => array_map('strval', array_column($db->all('SELECT otisk FROM {api_tokeny}'), 'otisk')),
            'passkey_lost' => fn (): array => array_map('strval', array_column($db->all('SELECT otisk_id FROM {uzivatele_klice}'), 'otisk_id'))] as $kind => $present) {
            $remembered = self::remembered($db, $kind);
            foreach ($present() as $hash) {
                if (isset($remembered[$hash])) {
                    self::forgetHash($db, $kind, $hash);
                    $count++;
                }
            }
        }

        return $count;
    }

    /** @return array<string, int> the hashes the ledger holds for $kind */
    private static function remembered(Db $db, string $kind): array
    {
        return array_flip(array_map('strval', array_column(self::attempt(fn (): array => $db->all('SELECT hash FROM {restore_ledger} WHERE kind = ?', [$kind]), []), 'hash')));
    }

    private static function forgetHash(Db $db, string $kind, string $hash): void
    {
        self::attempt(fn () => $db->run('DELETE FROM {restore_ledger} WHERE kind = ? AND hash = ?', [$kind, $hash]), null);
    }

    /** @param list<string> $today OAuth codes waiting today – any other restored one goes */
    private static function removeCodes(Db $db, array $today): int
    {
        $today = array_flip($today);
        $removed = 0;
        foreach (array_map('strval', array_column($db->all('SELECT otisk FROM {oauth_kody}'), 'otisk')) as $hash) {
            if (!isset($today[$hash])) {
                $removed += $db->delete('oauth_kody', ['otisk' => $hash]);
            }
        }

        return $removed;
    }

    /**
     * The outside-service connections are today's: the credentials of a connected service come back as they are now, a
     * service not connected today is disconnected.
     *
     * @param array<string, array<string, mixed>> $today
     */
    private static function connectors(Db $db, array $today): int
    {
        $count = 0;
        foreach (array_map('strval', array_column($db->all('SELECT service FROM {connectors}'), 'service')) as $service) {
            $data = $today[$service] ?? ['secret' => null, 'access_token' => null, 'refresh_token' => null, 'expires_at' => null, 'connected_at' => null, 'connected_by' => ''];
            $count += $db->update('connectors', $data, ['service' => $service]) > 0 ? 1 : 0;
        }

        return $count;
    }

    /**
     * Review M3 (owner's call: today's key stays): the signing key of today over the restored one, so links signed before a
     * rotation stay withdrawn – both are recorded in the ledger. While the key is the base of the encryption (Vault state
     * 'database') the restored secrets are encrypted again from the backup's key to today's, in one transaction; when that
     * would leave a value unreadable, or the encryption key is half moved or missing, the backup's key stays (the summary
     * says so).
     *
     * @param array<string, string> $settings capture()['settings']
     * @return array{signing_key: string}
     */
    private static function signingKeys(App $app, array $settings): array
    {
        $db = $app->db();
        $today = (string) ($settings['secret_key'] ?? '');
        $restored = (string) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'secret_key'");
        self::recordSiteKey($db, $restored);
        if ($today === '' || hash_equals($today, $restored)) {
            return ['signing_key' => 'same'];
        }
        $app->settings()->reload();
        $state = Vault::state($app->settings());
        if ($state === 'pending' || $state === 'missing') {
            return ['signing_key' => 'backup'];
        }
        try {
            $db->transaction(function (Db $db) use ($state, $restored, $today, $settings): void {
                if ($state === 'database' && $restored !== '') {
                    $previous = explode('|', (string) $db->value("SELECT hodnota FROM {nastaveni} WHERE promenna = 'secret_key_previous'"), 2)[0];
                    $previous = preg_match('/^[a-f0-9]{64}$/D', $previous) === 1 ? $previous : null;
                    $before = Vault::unreadable($db, $restored, $previous);
                    [, $unreadable] = Vault::resealAll($db, $restored, $today, $previous);
                    if ($unreadable > $before) {
                        throw new \RuntimeException('values would stay unreadable');
                    }
                }
                $db->run('REPLACE INTO {nastaveni} (promenna, hodnota) VALUES (?, ?)', ['secret_key', $today]);
                $db->run('REPLACE INTO {nastaveni} (promenna, hodnota) VALUES (?, ?)', ['secret_key_previous', (string) ($settings['secret_key_previous'] ?? '')]);
            });
        } catch (\Throwable $e) {
            ErrorLog::write(ErrorLog::exception($e));

            return ['signing_key' => 'backup'];
        }

        return ['signing_key' => 'today'];
    }

    /**
     * Review M3: the sign-in rules and the console pairing are today's (CARRIED_SETTINGS) – a rule switched on since the
     * backup stays on, a console unpaired since stays unpaired.
     *
     * @param array<string, string> $settings capture()['settings']
     */
    private static function settings(Db $db, array $settings): int
    {
        $count = 0;
        foreach (self::CARRIED_SETTINGS as $key) {
            if (array_key_exists($key, $settings)) {
                $db->run('REPLACE INTO {nastaveni} (promenna, hodnota) VALUES (?, ?)', [$key, (string) $settings[$key]]);
            } else {
                $db->run('DELETE FROM {nastaveni} WHERE promenna = ?', [$key]);
            }
            $count++;
        }

        return $count;
    }

    /**
     * Review M4: a newsletter that exists today keeps today's state (sent stays sent, with its counts); one the backup has
     * scheduled or sending and today's site does not (deleted since) goes back to a draft – a restore never sends a
     * newsletter to the list a second time.
     *
     * @param list<array<string, mixed>> $today
     */
    private static function newsletters(Db $db, array $today): int
    {
        $byId = [];
        foreach ($today as $n) {
            $byId[(int) $n['id']] = $n;
        }
        $changed = 0;
        foreach ($db->all('SELECT id, status FROM {newsletters}') as $row) {
            $id = (int) $row['id'];
            if (isset($byId[$id])) {
                $data = array_diff_key($byId[$id], ['id' => 0]);
                $changed += $db->update('newsletters', $data, ['id' => $id]) > 0 ? 1 : 0;
            } elseif (in_array((string) $row['status'], ['scheduled', 'sending'], true)) {
                $changed += $db->update('newsletters', ['status' => 'draft', 'scheduled_at' => null], ['id' => $id]);
            }
        }

        return $changed;
    }

    /**
     * Review M4: what went out since the backup stays sent – the restored rows of SENT_FLAGS take today's value where theirs
     * is empty, and the SENT_MARKERS settings keep today's when it is later.
     *
     * @param array<string, array<string, array<int|string, string>>> $flags capture()['flags']
     * @param array<string, string> $settings capture()['settings']
     */
    private static function sentFlags(Db $db, array $flags, array $settings): int
    {
        $count = 0;
        foreach (self::SENT_FLAGS as $table => [$id, $columns]) {
            foreach ($columns as $column) {
                foreach ($flags[$table][$column] ?? [] as $rowId => $value) {
                    $count += (int) self::attempt(fn (): int => $db->run('UPDATE {' . $table . '} SET ' . $column . ' = ? WHERE ' . $id . ' = ? AND ' . $column . ' IS NULL', [$value, $rowId])->rowCount(), 0);
                }
            }
        }
        foreach (self::SENT_MARKERS as $key) {
            $mine = (string) ($settings[$key] ?? '');
            if ($mine !== '' && $mine > (string) $db->value('SELECT hodnota FROM {nastaveni} WHERE promenna = ?', [$key])) {
                $db->run('REPLACE INTO {nastaveni} (promenna, hodnota) VALUES (?, ?)', [$key, $mine]);
                $count++;
            }
        }

        return $count;
    }

    /**
     * Stops every restored queue row that is not waiting today too – the same id and the same message (review L9).
     *
     * @param array<string, list<string>> $today "id|signature" per waiting row
     */
    public static function stopQueues(Db $db, array $today): int
    {
        $stopped = 0;
        foreach (self::QUEUES as $table => [$id, $waiting, $stop, $sign]) {
            $stopped += (int) self::attempt(function () use ($db, $table, $id, $waiting, $stop, $sign, $today): int {
                $keep = array_flip(array_map('strval', $today[$table] ?? []));
                $ids = [];
                foreach ($db->all('SELECT ' . $id . ' AS id, ' . $sign . ' AS s FROM {' . $table . '} WHERE ' . $waiting) as $r) {
                    if (!isset($keep[$r['id'] . '|' . $r['s']])) {
                        $ids[] = (int) $r['id'];
                    }
                }
                $count = 0;
                foreach (array_chunk($ids, 500) as $chunk) {
                    $count += $db->run('UPDATE {' . $table . '} SET ' . $stop . ' WHERE ' . $id . ' IN (' . implode(',', $chunk) . ')', [self::STOPPED])->rowCount();
                }

                return $count;
            }, 0);
        }

        return $stopped;
    }

    /**
     * The erasures and unsubscribes recorded after the backup was made ($madeAt; null = all of them) are applied again to the
     * restored tables.
     *
     * @return array{erased: int, unsubscribed: int}
     */
    private static function people(App $app, ?string $madeAt): array
    {
        $db = $app->db();
        $wanted = [];
        foreach ($db->all("SELECT kind, hash FROM {restore_ledger} WHERE kind IN ('erased', 'unsubscribed', '" . self::APPLICATION_DELETED . "')" . ($madeAt !== null ? ' AND created_at > ?' : ''), $madeAt !== null ? [$madeAt] : []) as $r) {
            $wanted[(string) $r['hash']][] = (string) $r['kind'];
        }
        if ($wanted === []) {
            return ['erased' => 0, 'unsubscribed' => 0];
        }
        $key = self::key($db);
        $done = ['erased' => self::applicationsDeleted($app, $key, $wanted), 'unsubscribed' => 0];
        // 3.46.1 (re-audit M1): the addresses of every place PersonalData::find() looks – a person found only through a form
        // field, a merge note or a queued body is erased again too; (N1) read in batches and compared as they come, each
        // address handled once
        $handled = [];
        foreach (PersonalData::candidates($db) as $email) {
            $hash = self::keyedHash($key, $email);
            if (!isset($wanted[$hash]) || isset($handled[$hash])) {
                continue;
            }
            $handled[$hash] = true;
            $kinds = $wanted[$hash];
            if (in_array('erased', $kinds, true)) {
                PersonalData::erase($app, $email);
                $done['erased']++;
            } elseif (in_array('unsubscribed', $kinds, true)) {
                $subscriber = $db->one('SELECT ido FROM {odberatele} WHERE LOWER(email) = ?', [$email]);
                if ($subscriber !== null) {
                    $db->delete('newsletter_queue', ['subscriber_id' => (int) $subscriber['ido']]);
                    $db->delete('odberatele', ['ido' => (int) $subscriber['ido']]);
                    $done['unsubscribed']++;
                }
                $db->run("DELETE FROM {odber_fronta} WHERE LOWER(email) = ? AND akce = 'pridat'", [$email]);
            }
        }

        return $done;
    }

    /**
     * 3.46.1 (re-audit M1): an application its applicant deleted through the talent-pool link after the backup was made
     * (recordApplication) is deleted again – the application alone, as the applicant asked, not everything about the address.
     *
     * @param array<string, list<string>> $wanted fingerprint => kinds
     */
    private static function applicationsDeleted(App $app, string $key, array $wanted): int
    {
        if (!in_array(self::APPLICATION_DELETED, array_merge(...array_values($wanted)), true)) {
            return 0;
        }
        $ids = [];
        $last = 0;
        do { // 3.46.1 (N1): in batches, like the candidates
            $rows = self::attempt(fn (): array => $app->db()->all("SELECT id, email FROM {applications} WHERE id > ? AND email <> '' ORDER BY id LIMIT " . PersonalData::CANDIDATE_BATCH, [$last]), []);
            foreach ($rows as $r) {
                $last = (int) $r['id'];
                if (in_array(self::APPLICATION_DELETED, $wanted[self::keyedHash($key, self::applicationSubject((int) $r['id'], (string) $r['email']))] ?? [], true)) {
                    $ids[] = (int) $r['id'];
                }
            }
        } while (count($rows) === PersonalData::CANDIDATE_BATCH);
        if ($ids !== []) {
            Applications::delete($app, $ids, false, $held);
        }

        return count($ids);
    }

    private static function insert(Db $db, string $kind, string $hash): void
    {
        $db->run('INSERT IGNORE INTO {restore_ledger} (kind, hash, created_at) VALUES (?, ?, ?)', [$kind, $hash, Clock::now()]);
    }

    /**
     * A read of a table an old backup may not have: the fallback when it is missing.
     *
     * @template T
     * @param callable(): T $fn
     * @param T $fallback
     * @return T
     */
    private static function attempt(callable $fn, mixed $fallback): mixed
    {
        try {
            return $fn();
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e);

            return $fallback;
        }
    }
}
