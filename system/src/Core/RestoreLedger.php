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
    /** The error of a queue row a restore stopped (stored as data, like the other delivery errors). */
    public const string STOPPED = 'Restored from a backup – not sent again.';

    /** The columns of an account that carry the sign-in state of today over a restore. */
    private const array ACCOUNT_COLUMNS = ['password', 'blokovat', 'blokovano_automaticky', 'pocet_chyb', 'zamceno_do', 'obnova_otisk', 'obnova_cas', 'totp_tajemstvi',
        'totp_zalozni', 'passkey_handle', 'passkey_only', 'sign_in_epoch'];

    /** The credentials of an outside-service connection (ka_connectors) that are today's after a restore. */
    private const array CONNECTOR_COLUMNS = ['account', 'client_id', 'secret', 'access_token', 'refresh_token', 'expires_at', 'scopes', 'connected_at', 'connected_by'];

    /**
     * The queues a restore must not send again: table => [id column, "waiting" condition, what stops a row].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const array QUEUES = [
        'posta' => ['idp', 'odeslano IS NULL AND telo IS NOT NULL AND dalsi_pokus IS NOT NULL', 'dalsi_pokus = NULL, chyba = ?'],
        'webhook_deliveries' => ['id', 'next_attempt IS NOT NULL AND body IS NOT NULL', 'next_attempt = NULL, error = ?'],
        'connector_queue' => ['id', 'next_attempt IS NOT NULL', 'next_attempt = NULL, last_error = ?'],
        'newsletter_queue' => ['id', 'next_attempt IS NOT NULL', 'next_attempt = NULL, error = ?'],
        'odber_fronta' => ['idf', 'dalsi IS NOT NULL', 'dalsi = NULL, chyba = ?'],
    ];

    /** Where the addresses an erasure may have to find again live after a restore: table => column. */
    private const array ADDRESSES = ['poptavky' => 'email', 'odberatele' => 'email', 'contacts' => 'email', 'bookings' => 'email', 'testimonial_requests' => 'email',
        'posta' => 'komu', 'applications' => 'email', 'quotes' => 'customer_email'];

    /**
     * Records an erased or unsubscribed address (its keyed fingerprint only). The first record stays – it says when it
     * happened, and a restore reapplies it to every backup older than that.
     */
    public static function record(Db $db, string $kind, string $email): void
    {
        $email = PersonalData::cleanAddress($email);
        if ($email === '' || !in_array($kind, ['erased', 'unsubscribed'], true)) {
            return;
        }
        try {
            $db->run('INSERT IGNORE INTO {restore_ledger} (kind, hash, created_at) VALUES (?, ?, ?)', [$kind, self::fingerprint($db, $email), Clock::now()]);
        } catch (\PDOException $e) {
            Db::rethrowUnlessMissing($e); // before migration 0133
        }
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

    /** The ledger's own random key – made the first time it is needed, kept in the ledger (outside every backup). */
    private static function key(Db $db): string
    {
        $key = (string) $db->value("SELECT hash FROM {restore_ledger} WHERE kind = 'key' ORDER BY id LIMIT 1");
        if ($key === '') {
            $db->run("INSERT IGNORE INTO {restore_ledger} (kind, hash, created_at) VALUES ('key', ?, ?)", [bin2hex(random_bytes(32)), Clock::now()]);
            $key = (string) $db->value("SELECT hash FROM {restore_ledger} WHERE kind = 'key' ORDER BY id LIMIT 1");
        }

        return $key;
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
     * The sign-in state and the waiting queue rows of the site right before a restore. The site's signing keys go into the
     * ledger at once, so the backup about to be restored is recognised as this site's.
     *
     * @return array{site: list<string>, accounts: array<int, array<string, mixed>>, tokens: list<string>, passkeys: list<string>, codes: list<string>, connectors: array<string, array<string, mixed>>, queues: array<string, list<int>>}
     */
    public static function capture(Db $db): array
    {
        $site = self::siteKeys($db);
        foreach ($site as $hash) {
            self::attempt(fn () => self::insert($db, 'site', $hash), null); // before migration 0133 the keys are compared in memory only
        }
        $queues = [];
        foreach (self::QUEUES as $table => [$id, $waiting]) {
            $queues[$table] = self::attempt(fn (): array => array_map('intval', array_column($db->all('SELECT ' . $id . ' AS id FROM {' . $table . '} WHERE ' . $waiting), 'id')), []);
        }
        $accounts = [];
        foreach (self::attempt(fn (): array => $db->all('SELECT * FROM {uzivatele}'), []) as $row) {
            $accounts[(int) $row['idu']] = array_intersect_key($row, array_flip([...self::ACCOUNT_COLUMNS, 'user']));
        }
        $connectors = [];
        foreach (self::attempt(fn (): array => $db->all('SELECT * FROM {connectors}'), []) as $row) {
            $connectors[(string) $row['service']] = array_intersect_key($row, array_flip(self::CONNECTOR_COLUMNS));
        }

        return [
            'site' => $site,
            'accounts' => $accounts,
            'tokens' => self::attempt(fn (): array => array_map('strval', array_column($db->all('SELECT otisk FROM {api_tokeny}'), 'otisk')), []),
            'passkeys' => self::attempt(fn (): array => array_map('strval', array_column($db->all('SELECT otisk_id FROM {uzivatele_klice}'), 'otisk_id')), []),
            'codes' => self::attempt(fn (): array => array_map('strval', array_column($db->all('SELECT otisk FROM {oauth_kody}'), 'otisk')), []),
            'connectors' => $connectors,
            'queues' => $queues,
        ];
    }

    /**
     * After a restore: today's sign-in state over the restored tables (a backup of this site only), the erasures and
     * unsubscribes recorded after the backup was made, and the restored queue rows stopped. Each part runs on its own –
     * one that fails (an old backup without a table) does not keep the others from running. Returns what was done.
     *
     * @param array{site: list<string>, accounts: array<int, array<string, mixed>>, tokens: list<string>, passkeys: list<string>, codes: list<string>, connectors: array<string, array<string, mixed>>, queues: array<string, list<int>>} $before capture()
     * @return array<string, int|bool>
     */
    public static function reapply(App $app, array $before, ?string $madeAt): array
    {
        $db = $app->db();
        $done = ['same_site' => self::sameSite($db, $before['site'])];
        $failed = [];
        $step = function (string $name, callable $fn) use (&$done, &$failed): void {
            try {
                $done += $fn();
            } catch (\Throwable $e) {
                ErrorLog::write(ErrorLog::exception($e));
                $failed[] = $name;
            }
        };
        $step('queues', fn (): array => ['queue_rows_stopped' => self::stopQueues($db, $before['queues'])]);
        if ($done['same_site']) {
            $step('accounts', fn (): array => self::accounts($db, $before['accounts']));
            $step('tokens', fn (): array => ['tokens_removed' => self::removeGone($db, 'api_tokeny', 'otisk', 'token', $before['tokens'])]);
            $step('passkeys', fn (): array => ['passkeys_removed' => self::removeGone($db, 'uzivatele_klice', 'otisk_id', 'passkey', $before['passkeys'])]);
            $step('codes', fn (): array => ['codes_removed' => self::removeCodes($db, $before['codes'])]);
            $step('connectors', fn (): array => ['connections_kept' => self::connectors($db, $before['connectors'])]);
        }
        $step('people', fn (): array => self::people($app, $madeAt));
        $done['failed'] = $failed !== [];
        if ($failed !== []) {
            Events::record($db, 'backup.restore_ledger_failed', 'error', t('After the restore, some of what the restore must not bring back could not be checked (%s). Look at the error log and restore again if needed.', implode(', ', $failed)), ['parts' => $failed]);
        }

        return $done;
    }

    /**
     * What a restore of a backup made at $madeAt loses and what it keeps (shown before the restore). Counts only.
     *
     * @return array{lost: array<string, int>, kept: array<string, int>}
     */
    public static function preview(Db $db, ?string $madeAt): array
    {
        $since = $madeAt ?? '9999-12-31 23:59:59';
        $count = fn (string $sql): int => (int) self::attempt(fn (): mixed => $db->value($sql, [$since]), 0);

        return [
            'lost' => [
                'enquiries' => $count('SELECT COUNT(*) FROM {poptavky} WHERE datum > ?'),
                'applications' => $count('SELECT COUNT(*) FROM {applications} WHERE received_at > ?'),
                'bookings' => $count('SELECT COUNT(*) FROM {bookings} WHERE created_at > ?'),
                'subscribers' => $count('SELECT COUNT(*) FROM {odberatele} WHERE datum > ?'),
                'changes' => $count('SELECT COUNT(*) FROM {protokol} WHERE cas > ?'),
            ],
            'kept' => [
                'erasures' => $count("SELECT COUNT(*) FROM {restore_ledger} WHERE kind = 'erased' AND created_at > ?"),
                'unsubscribes' => $count("SELECT COUNT(*) FROM {restore_ledger} WHERE kind = 'unsubscribed' AND created_at > ?"),
            ],
        ];
    }

    /** preview() in words, for the confirmation of a restore. @param array{lost: array<string, int>, kept: array<string, int>} $preview */
    public static function previewText(array $preview): string
    {
        $lost = $preview['lost'];

        return t('Since this backup, the restore loses %d enquiries, %d job applications, %d bookings, %d newsletter sign-ups and %d changes in the change log.',
            $lost['enquiries'], $lost['applications'], $lost['bookings'], $lost['subscribers'], $lost['changes'])
            . ' ' . t('It keeps the sign-in security of today (revoked tokens and connections, blocked or removed accounts, changed passwords, two-step sign-in and passkeys), %d erasures of personal data and %d unsubscribes made since. Queued e-mails, webhooks and deliveries from the backup are not sent again.',
                $preview['kept']['erasures'], $preview['kept']['unsubscribes']);
    }

    /** reapply() in words, for the message after a restore. @param array<string, int|bool> $done */
    public static function summary(array $done): string
    {
        $text = t('Kept from today: %d tokens and %d passkeys the backup had were removed, %d accounts kept their current sign-in, %d accounts the backup had are blocked; %d erasures and %d unsubscribes applied again; %d queued deliveries from the backup stopped.',
            (int) ($done['tokens_removed'] ?? 0), (int) ($done['passkeys_removed'] ?? 0), (int) ($done['accounts_kept'] ?? 0), (int) ($done['accounts_blocked'] ?? 0),
            (int) ($done['erased'] ?? 0), (int) ($done['unsubscribed'] ?? 0), (int) ($done['queue_rows_stopped'] ?? 0));
        if (!($done['same_site'] ?? false)) {
            $text .= ' ' . t('The backup comes from another installation, so its accounts and connections stay as they are in it.');
        }

        return $text;
    }

    /**
     * Is the restored database a backup of this site? Its signing key is one the ledger knows (recorded before this
     * restore or any earlier one, and by migration 0133).
     *
     * @param list<string> $before the keys of the site before the restore
     */
    private static function sameSite(Db $db, array $before): bool
    {
        $restored = self::siteKeys($db);
        if ($restored === []) {
            return false;
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
     * The accounts: one that exists today (the same id and sign-in name) gets today's password, block, two-step sign-in and
     * sign-in epoch; one that is gone today (removed since the backup) stays blocked.
     *
     * @param array<int, array<string, mixed>> $before
     * @return array{accounts_kept: int, accounts_blocked: int}
     */
    private static function accounts(Db $db, array $before): array
    {
        $columns = array_map('strval', array_column($db->all('SHOW COLUMNS FROM {uzivatele}'), 'Field'));
        $kept = 0;
        $blocked = 0;
        foreach ($db->all('SELECT idu, user, blokovat FROM {uzivatele}') as $row) {
            $id = (int) $row['idu'];
            $today = $before[$id] ?? null;
            if ($today !== null && (string) $today['user'] === (string) $row['user']) {
                $data = array_intersect_key($today, array_flip(array_intersect(self::ACCOUNT_COLUMNS, $columns)));
                if ($data !== []) {
                    $db->update('uzivatele', $data, ['idu' => $id]);
                    $kept++;
                }
            } elseif ((int) $row['blokovat'] === 0) {
                $db->update('uzivatele', ['blokovat' => 1], ['idu' => $id]);
                $db->delete('api_tokeny', ['idu' => $id]);
                $blocked++;
            }
        }

        return ['accounts_kept' => $kept, 'accounts_blocked' => $blocked];
    }

    /**
     * Removes the restored rows of a sign-in table whose fingerprint is not there today or that a restore removed before;
     * the ledger remembers them.
     *
     * @param list<string> $today
     */
    private static function removeGone(Db $db, string $table, string $column, string $kind, array $today): int
    {
        $remembered = array_flip(array_map('strval', array_column($db->all('SELECT hash FROM {restore_ledger} WHERE kind = ?', [$kind]), 'hash')));
        $today = array_flip($today);
        $removed = 0;
        foreach (array_map('strval', array_column($db->all('SELECT ' . $column . ' AS h FROM {' . $table . '}'), 'h')) as $hash) {
            if (!isset($today[$hash]) || isset($remembered[$hash])) {
                $removed += $db->delete($table, [$column => $hash]);
                self::insert($db, $kind, $hash);
            }
        }

        return $removed;
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
     * Stops every restored queue row that is not waiting today too.
     *
     * @param array<string, list<int>> $today
     */
    public static function stopQueues(Db $db, array $today): int
    {
        $stopped = 0;
        foreach (self::QUEUES as $table => [$id, $waiting, $stop]) {
            $stopped += (int) self::attempt(function () use ($db, $table, $id, $waiting, $stop, $today): int {
                $keep = array_map('intval', $today[$table] ?? []);

                return $db->run('UPDATE {' . $table . '} SET ' . $stop . ' WHERE ' . $waiting . ($keep !== [] ? ' AND ' . $id . ' NOT IN (' . implode(',', $keep) . ')' : ''), [self::STOPPED])->rowCount();
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
        foreach ($db->all("SELECT kind, hash FROM {restore_ledger} WHERE kind IN ('erased', 'unsubscribed')" . ($madeAt !== null ? ' AND created_at > ?' : ''), $madeAt !== null ? [$madeAt] : []) as $r) {
            $wanted[(string) $r['hash']][] = (string) $r['kind'];
        }
        if ($wanted === []) {
            return ['erased' => 0, 'unsubscribed' => 0];
        }
        $key = self::key($db);
        $done = ['erased' => 0, 'unsubscribed' => 0];
        foreach (self::addresses($db) as $email) {
            $kinds = $wanted[self::keyedHash($key, $email)] ?? [];
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

    /** @return list<string> the distinct addresses (cleaned) of the tables an erasure reaches */
    private static function addresses(Db $db): array
    {
        $all = [];
        foreach (self::ADDRESSES as $table => $column) {
            foreach (self::attempt(fn (): array => $db->all('SELECT DISTINCT ' . $column . ' AS a FROM {' . $table . '} WHERE ' . $column . " <> ''"), []) as $r) {
                $email = PersonalData::cleanAddress((string) $r['a']);
                if ($email !== '') {
                    $all[$email] = true;
                }
            }
        }

        return array_keys($all);
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
