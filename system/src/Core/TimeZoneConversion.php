<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Saved dates converted when the site's time zone changes (3.12, docs/design/4.0-data-model.md §6; the owner's decision of
 * 11 October 2026: a time_zone change OFFERS the conversion, with the choice to keep the dates as they are).
 *
 * The database holds the site's wall time, so a site that moves from Prague to London would otherwise publish its scheduled
 * news an hour off. Settings → General asks first (Admin\Modules\Settings::actionTimeZone): "Convert saved dates" moves
 * every moment (INSTANTS) from the old zone's clock to the new one; "Keep their clock time" leaves everything as it is.
 * Calendar dates and civil times (CIVIL) never move – a booking at 10:00 stays at 10:00, a DATE stays its day.
 *
 *  - Table by table, in batches by primary key; each value is converted at its own date with PHP's DateTimeZone, so
 *    daylight saving is right on both sides. A value in the hour a DST change repeats takes the first occurrence and is
 *    counted (ambiguous).
 *  - Each batch and its progress record (ka_time_zone_progress) are written in one transaction: a conversion stopped at any
 *    moment continues where it was, and no row is converted twice. A finished conversion is never run again.
 *  - The zone and the start change together, once (begin(), 3.12.1): a parallel or repeated confirmation changes nothing.
 *    A site of up to AT_ONCE_ROWS moments is converted in that same transaction; a larger one converts its busy tables
 *    then and the rest in batches, where a numeric primary key above upto_key marks a row written on the new clock.
 *    The background job "time_zone" runs before every other job and holds the others back until it is done
 *    (Core\Scheduler) – unless it fails: then the others run anyway and System status warns.
 *  - A value that would leave the DATETIME range stays as it is and is counted; a table that fails is skipped with its
 *    error on the progress row (both in the change log and on System status).
 *  - The start and the end are in the change log (Admin\ChangeLog).
 */
final class TimeZoneConversion
{
    /** Rows per batch (one transaction). */
    public const int BATCH = 300;

    /**
     * Moments the start converts at once, in the transaction that switches the zone (3.12.1, N312-7): a site with at most
     * this many rows in the tables of INSTANTS switches in one step, so no request ever sees half of it.
     */
    public const int AT_ONCE_ROWS = 20000;

    /** Tables written on almost every request (sign-ins, connections, the job records, 404s): always converted at the start. */
    private const array BUSY = ['uzivatele', 'uzivatele_klice', 'api_tokeny', 'oauth_klienti', 'jobs', 'nenalezeno', 'firewall_blocks', 'novinky_koncepty'];

    /** The range of a DATETIME column (MySQL and MariaDB). */
    private const string DATETIME_MIN = '1000-01-01 00:00:00';

    private const string DATETIME_MAX = '9999-12-31 23:59:59';

    /** One start at a time (begin()); the prefix is added, so two sites in one database do not wait for each other. */
    private const string LOCK = 'kaleta_time_zone';

    /**
     * The moments: table (without the prefix) => DATETIME columns converted from the old zone to the new one. Created,
     * changed, sent, logged, publish-at, expires… – things that happened or will happen at one instant.
     */
    public const array INSTANTS = [
        'uzivatele' => ['blokovano_automaticky', 'zamceno_do', 'obnova_cas', 'posledni_login', 'potvrzeno'],
        'novinky' => ['datum', 'zmeneno', 'aktualizovano', 'oznameno', 'odkazy_cas', 'smazano'],
        'media' => ['datum'],
        'kontrola_ip' => ['cas'],
        'novinky_revize' => ['datum'],
        'stranky' => ['zverejnit_od', 'zmeneno', 'links_checked', 'smazano'],
        'casti' => ['zmeneno'],
        'stavba_revize' => ['datum'],
        'tridy' => ['zmeneno'],
        'presmerovani' => ['vytvoreno'],
        'souhlasy' => ['cas'],
        'protokol' => ['cas'],
        'api_tokeny' => ['expirace', 'vytvoren', 'pouzit'],
        'uzivatele_klice' => ['vytvoreno', 'pouzito'],
        'posta' => ['vytvoreno', 'odeslano', 'dalsi_pokus'],
        'nenalezeno' => ['naposledy', 'ignorovano'],
        'novinky_koncepty' => ['cas'],
        'odkazy_vadne' => ['cas'],
        'draft_comments' => ['created_at', 'resolved_at'],
        'poptavky' => ['datum', 'triaged_at', 'anonymizovano'],
        'kolekce' => ['zmeneno'],
        'kolekce_polozky' => ['zverejnit_od', 'datum', 'zmeneno', 'links_checked', 'smazano'],
        'kolekce_sablony' => ['zmeneno'],
        'collection_categories' => ['updated_at'],
        'collection_category_templates' => ['zmeneno'],
        'document_versions' => ['replaced_at'],
        'menu' => ['zmeneno'],
        'stranky_revize' => ['datum'],
        'sekce' => ['zmeneno'],
        'popupy' => ['zmeneno'],
        'komponenty' => ['zmeneno'],
        'odberatele' => ['datum', 'potvrzeno'],
        'odber_fronta' => ['dalsi', 'vytvoreno'],
        'oauth_klienti' => ['vytvoren', 'approved'],
        'oauth_kody' => ['expirace'],
        'oauth_rotated' => ['rotated_at', 'expires_at'],
        'newsletters' => ['scheduled_at', 'created', 'changed', 'started_at', 'finished_at'],
        'newsletter_queue' => ['next_attempt', 'sent_at'],
        'look_versions' => ['created'],
        'webhook_deliveries' => ['created', 'next_attempt', 'delivered'],
        'events' => ['created_at'],
        'jobs' => ['last_run', 'last_ok'],
        'firewall_blocks' => ['until', 'created_at'],
        'firewall_log' => ['created_at'],
        'fleet_sites' => ['relay_expires', 'paired_at', 'last_seen', 'version_since', 'up_checked', 'up_changed'],
        'fleet_pairing' => ['created_at', 'expires_at', 'used_at'],
        'fleet_kits' => ['created_at'],
        'facts' => ['updated_at'],
        'fact_history' => ['changed_at'],
        'hours_exceptions' => ['created_at'],
        'blueprints' => ['applied_at'],
        'notice_log' => ['at'],
        'testimonial_requests' => ['created_at', 'expires_at', 'used_at'],
        'connectors' => ['expires_at', 'connected_at'],
        'connector_queue' => ['next_attempt', 'created_at', 'delivered_at'],
        'connector_log' => ['created_at'],
        'whistleblowing_cases' => ['created_at', 'acknowledged_at', 'feedback_due', 'closed_at'],
        'whistleblowing_messages' => ['created_at'],
        'social_drafts' => ['created_at', 'copied_at'],
        'google_reviews' => ['reviewed_at', 'replied_at', 'fetched_at'],
        'notebook' => ['created_at', 'updated_at'],
        'requests' => ['created_at', 'updated_at', 'done_at'],
        'request_messages' => ['created_at'],
        'agent_sessions' => ['started_at', 'last_at', 'undone_at'],
        'agent_journal' => ['created_at'],
        'agent_schedules' => ['last_run_at', 'created_at'],
        'agent_runs' => ['due_at', 'started_at', 'finished_at'],
        'bookings' => ['created_at', 'reminded_at', 'hold_until', 'hold_reminded_at', 'cancelled_at', 'anonymised_at'],
    ];

    /**
     * Calendar dates and civil times – never converted: they mean the clock on the wall where the business is, whatever
     * the zone. Every other DATETIME and DATE column of schema.sql is in INSTANTS (tools/unit-tests.php checks both lists
     * cover the schema exactly). Opening hours (setting company_hours, ka_booking_hours TIME columns) and the dates inside
     * collection items (JSON) are not DATETIME columns and stay as they are too.
     */
    public const array CIVIL = [
        // days: "visible until", "review by", the days of statistics and downloads, the days of a snapshot
        'novinky' => ['valid_until', 'review_by'],
        'stranky' => ['valid_until', 'review_by'],
        'kolekce_polozky' => ['valid_until', 'review_by'],
        'popupy' => ['valid_until', 'review_by'],
        'stat_dny' => ['den'], 'stat_navstevnici' => ['den'], 'stat_novinky' => ['den'], 'stat_stranky' => ['den'], 'stat_kampane' => ['den'],
        'stat_zarizeni' => ['den'], 'stat_konverze' => ['den'], 'stat_zdroje' => ['den'], 'web_vitals' => ['day'], 'document_downloads' => ['day'], 'search_stats' => ['day'],
        // opening hours exceptions (holidays), appointments, days off and proposed times: a 10:00 appointment stays at 10:00
        'hours_exceptions' => ['date_from', 'date_to'],
        'bookings' => ['starts_at', 'ends_at'],
        'booking_off' => ['off_from', 'off_to'],
        'booking_proposals' => ['starts_at', 'ends_at'],
        // a scheduled Claude run "every day at 07:00" stays at 07:00 on the site's clock
        'agent_schedules' => ['next_due'],
        // the conversion's own records
        'time_zone_conversions' => ['started_at', 'finished_at'],
        'time_zone_progress' => ['finished_at'],
    ];

    /** The conversion that has not finished yet, or null. A site before migration 0093 has none. */
    public static function running(Db $db): ?array
    {
        try {
            return $db->one('SELECT * FROM {time_zone_conversions} WHERE finished_at IS NULL ORDER BY id LIMIT 1');
        } catch (\PDOException) {
            return null;
        }
    }

    /** The latest conversion with its tables (for Settings → General), or null. */
    public static function latest(Db $db): ?array
    {
        try {
            $conversion = $db->one('SELECT * FROM {time_zone_conversions} ORDER BY id DESC LIMIT 1');
        } catch (\PDOException) {
            return null;
        }
        if ($conversion === null) {
            return null;
        }
        $conversion['tables'] = $db->all('SELECT * FROM {time_zone_progress} WHERE conversion_id = ? ORDER BY position', [(int) $conversion['id']]);

        return $conversion;
    }

    /**
     * Changes the time_zone setting from $from to $to and, with $convert, starts the conversion in the same step (3.12.1,
     * N312-1). It runs under a lock and only while the setting still holds $from (a compare-and-set in the transaction that
     * creates the conversion): a double click, a second tab or a retried request finds the new zone and changes nothing, so
     * the saved dates move once. Returns null when refused (the zone is no longer $from, a conversion still runs, the lock
     * is busy, an unknown zone), 0 for "keep their clock time", otherwise the id of the conversion.
     *
     * The start converts at once, in that transaction (N312-7): the whole site when its moments are at most $atOnceRows
     * rows (AT_ONCE_ROWS – nearly every site, which then switches in one step), otherwise the tables written on almost
     * every request (BUSY) and those without a numeric key; the rest goes in batches, where a numeric key above the one
     * at the start (0 for an empty table) marks a row written on the new clock.
     */
    public static function begin(Db $db, Settings $settings, string $from, string $to, bool $convert, ?int $userId, ?int $atOnceRows = null): ?int
    {
        $zones = \DateTimeZone::listIdentifiers();
        if ($from === $to || !in_array($from, $zones, true) || !in_array($to, $zones, true)) {
            return null;
        }
        $lock = self::LOCK . '_' . $db->prefix;
        if ((int) $db->value('SELECT GET_LOCK(?, 10)', [$lock]) !== 1) {
            return null;
        }
        try {
            if (self::running($db) !== null) {
                return null;
            }
            // the zone may be the default without a row: one is written first, so the compare-and-set has a row to compare
            $db->run('INSERT IGNORE INTO {nastaveni} (promenna, hodnota) VALUES (?, ?)', ['time_zone', $from]);
            $plan = $convert ? self::plan($db) : []; // information_schema, before the transaction
            $db->pdo()->beginTransaction();
            try {
                $changed = $db->run("UPDATE {nastaveni} SET hodnota = ? WHERE promenna = 'time_zone' AND hodnota = ?", [$to, $from])->rowCount() === 1;
                $id = !$changed ? null : ($convert ? self::create($db, $plan, $from, $to, $userId, $atOnceRows ?? self::AT_ONCE_ROWS) : 0);
                $changed ? $db->pdo()->commit() : $db->pdo()->rollBack();
            } catch (\Throwable $e) {
                if ($db->pdo()->inTransaction()) {
                    $db->pdo()->rollBack();
                }
                throw $e;
            }
            if ($id !== null) {
                $settings->set('time_zone', $to); // the settings this request holds (the same value)
            }

            return $id;
        } finally {
            $db->value('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /**
     * The tables to convert, smaller first: [table, primary key, estimated rows].
     *
     * @return list<array{0: string, 1: list<array{0: string, 1: bool}>, 2: int}>
     */
    private static function plan(Db $db): array
    {
        $sizes = [];
        foreach ($db->all('SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $r) {
            $sizes[(string) $r['TABLE_NAME']] = (int) $r['TABLE_ROWS'];
        }
        $plan = [];
        foreach (array_keys(self::INSTANTS) as $table) {
            if (isset($sizes[$physical = $db->prefix . Names::physical($table)])) {
                $plan[] = [$table, self::primaryKey($db, $table), $sizes[$physical]];
            }
        }
        usort($plan, fn (array $a, array $b): int => [$a[2], $a[0]] <=> [$b[2], $b[0]]);

        return $plan;
    }

    /**
     * The conversion and its progress rows, inside begin()'s transaction; the tables converted at once are finished here.
     *
     * @param list<array{0: string, 1: list<array{0: string, 1: bool}>, 2: int}> $plan
     */
    private static function create(Db $db, array $plan, string $from, string $to, ?int $userId, int $atOnceRows): int
    {
        $id = $db->insert('time_zone_conversions', ['from_zone' => $from, 'to_zone' => $to, 'started_by' => $userId, 'started_at' => Clock::now()]);
        $whole = array_sum(array_column($plan, 2)) <= $atOnceRows;
        $atOnce = [];
        foreach ($plan as $position => [$table, $key]) {
            $numeric = count($key) === 1 && $key[0][1];
            // a numeric key: rows added from now on are written on the new clock and must not move (an empty table: none)
            $upto = $numeric ? (int) ($db->value('SELECT MAX(' . Db::quoteName($key[0][0]) . ') FROM {' . $table . '}') ?? 0) : null;
            $db->insert('time_zone_progress', ['conversion_id' => $id, 'table_name' => $table, 'position' => $position, 'upto_key' => $upto]);
            if ($whole || !$numeric || in_array($table, self::BUSY, true)) {
                $atOnce[] = $table;
            }
        }
        $fromZone = new \DateTimeZone($from);
        $toZone = new \DateTimeZone($to);
        foreach ($atOnce as $table) {
            do {
                $progress = $db->one('SELECT * FROM {time_zone_progress} WHERE conversion_id = ? AND table_name = ?', [$id, $table]) ?? [];
            } while (!self::batch($db, $id, $progress, $fromZone, $toZone, false));
        }
        if (count($atOnce) === count($plan)) {
            $db->run('UPDATE {time_zone_conversions} SET finished_at = ? WHERE id = ?', [Clock::now(), $id]);
        }

        return $id;
    }

    /**
     * Converts the next batches of the running conversion within $seconds. Safe to run any number of times and to stop at
     * any moment. A table whose batch fails (3.12.1, N312-2) is left as it is, with the error on its progress row – it never
     * stops the other tables or the other background jobs. Returns null when nothing runs, otherwise the state: done (all
     * tables), finished_now (this call finished it – the caller writes the change log), the counts.
     *
     * @return array{id: int, from: string, to: string, done: bool, finished_now: bool, tables: int, tables_done: int, values: int, ambiguous: int, out_of_range: int, failed: list<string>}|null
     */
    public static function run(Db $db, float $seconds): ?array
    {
        $conversion = self::running($db);
        if ($conversion === null) {
            return null;
        }
        $id = (int) $conversion['id'];
        $from = new \DateTimeZone((string) $conversion['from_zone']);
        $to = new \DateTimeZone((string) $conversion['to_zone']);
        $end = microtime(true) + $seconds;
        foreach ($db->all('SELECT * FROM {time_zone_progress} WHERE conversion_id = ? AND finished_at IS NULL ORDER BY position', [$id]) as $progress) {
            try {
                while (microtime(true) < $end) {
                    if (self::batch($db, $id, $progress, $from, $to)) {
                        break; // the table is done
                    }
                    $progress = $db->one('SELECT * FROM {time_zone_progress} WHERE conversion_id = ? AND table_name = ?', [$id, $progress['table_name']]) ?? $progress;
                }
            } catch (\Throwable $e) {
                // the table stays as it is and is reported; the conversion goes on with the next one
                $db->run('UPDATE {time_zone_progress} SET error = ?, finished_at = ? WHERE conversion_id = ? AND table_name = ?',
                    [mb_substr($e->getMessage(), 0, 255), Clock::now(), $id, $progress['table_name']]);
            }
            if (microtime(true) >= $end) {
                break;
            }
        }

        return self::state($db, $id, $from->getName(), $to->getName(), true);
    }

    /**
     * The counts of a conversion; with $finish, a conversion whose tables are all done is marked finished.
     *
     * @return array{id: int, from: string, to: string, done: bool, finished_now: bool, tables: int, tables_done: int, values: int, ambiguous: int, out_of_range: int, failed: list<string>}
     */
    public static function state(Db $db, int $id, string $from, string $to, bool $finish = false): array
    {
        $totals = $db->one('SELECT COUNT(*) AS tables, SUM(finished_at IS NOT NULL) AS tables_done, COALESCE(SUM(values_done), 0) AS `values`, COALESCE(SUM(ambiguous), 0) AS ambiguous,
            COALESCE(SUM(out_of_range), 0) AS out_of_range FROM {time_zone_progress} WHERE conversion_id = ?', [$id]) ?? [];
        $done = (int) ($totals['tables'] ?? 0) === (int) ($totals['tables_done'] ?? 0);
        $finishedNow = $finish && $done && $db->run('UPDATE {time_zone_conversions} SET finished_at = ? WHERE id = ? AND finished_at IS NULL', [Clock::now(), $id])->rowCount() === 1;

        return ['id' => $id, 'from' => $from, 'to' => $to, 'done' => $done, 'finished_now' => $finishedNow, 'tables' => (int) ($totals['tables'] ?? 0),
            'tables_done' => (int) ($totals['tables_done'] ?? 0), 'values' => (int) ($totals['values'] ?? 0), 'ambiguous' => (int) ($totals['ambiguous'] ?? 0),
            'out_of_range' => (int) ($totals['out_of_range'] ?? 0),
            'failed' => array_map('strval', array_column($db->all("SELECT table_name FROM {time_zone_progress} WHERE conversion_id = ? AND error <> '' ORDER BY position", [$id]), 'table_name'))];
    }

    /**
     * The end of a conversion in the change log (the start is written by Settings when the administrator chooses it).
     *
     * @param array{from: string, to: string, tables: int, values: int, ambiguous: int, out_of_range: int, failed: list<string>} $state
     */
    public static function logFinished(App $app, array $state): void
    {
        \Kaleta\Admin\ChangeLog::write($app, 'settings', 'time_zone_converted', $state['from'] . ' → ' . $state['to'] . ': ' . $state['values'] . ' values in ' . $state['tables'] . ' tables'
            . ($state['ambiguous'] > 0 ? ', ' . $state['ambiguous'] . ' in the repeated hour of a daylight-saving change' : '')
            . ($state['out_of_range'] > 0 ? ', ' . $state['out_of_range'] . ' left as they were (outside the years 1000–9999 after the move)' : '')
            . ($state['failed'] !== [] ? ', not converted: ' . implode(', ', $state['failed']) : ''));
    }

    /**
     * A value of a DATETIME column on the old zone's clock, on the new zone's clock: [new value, whether the old one was in
     * an hour a DST change repeats, whether the new one would leave the range of a DATETIME column]; null when it is empty,
     * zero or not a date. A value that would leave the range (a "never" date such as 9999-12-31 23:30 moved east) has no
     * new value – it is left as it is and counted, never an error (3.12.1, N312-2).
     *
     * @return array{0: ?string, 1: bool, 2: bool}|null
     */
    public static function convert(?string $value, \DateTimeZone $from, \DateTimeZone $to): ?array
    {
        if ($value === null || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }
        $moment = \DateTimeImmutable::createFromFormat('!' . Clock::FORMAT, $value, $from);
        if ($moment === false || $moment->format(Clock::FORMAT) !== $value) {
            return null; // not a valid moment (or one in the hour skipped in spring): left as it is
        }
        // the same wall time an hour earlier on the real timeline = the hour that repeats in autumn: PHP reads the second
        // one, the conversion takes the first (the one the site lived through first) and counts it
        $earlier = (new \DateTimeImmutable('@' . ($moment->getTimestamp() - 3600)))->setTimezone($from);
        $ambiguous = $earlier->format(Clock::FORMAT) === $value;
        $new = ($ambiguous ? $earlier : $moment)->setTimezone($to)->format(Clock::FORMAT);
        // a four-digit year compares as text; year 999 or 10000 does not have four digits
        $inRange = preg_match('/^\d{4}-/', $new) === 1 && $new >= self::DATETIME_MIN && $new <= self::DATETIME_MAX;

        return $inRange ? [$new, $ambiguous, false] : [null, $ambiguous, true];
    }

    /**
     * One batch of one table; true when the table is finished. In its own transaction, or (the start) in the caller's. The
     * rows are read FOR UPDATE inside it, so a request writing a row at the same moment waits instead of being overwritten.
     */
    private static function batch(Db $db, int $id, array $progress, \DateTimeZone $from, \DateTimeZone $to, bool $ownTransaction = true): bool
    {
        $table = (string) $progress['table_name'];
        $columns = self::INSTANTS[$table] ?? [];
        $key = array_column(self::primaryKey($db, $table), 0);
        $after = $progress['last_key'] !== '' ? json_decode((string) $progress['last_key'], true) : null;
        [$where, $params] = is_array($after) && count($after) === count($key) ? self::after($key, array_values($after)) : ['1 = 1', []];
        if ($progress['upto_key'] !== null) {
            $where .= ' AND ' . Db::quoteName($key[0]) . ' <= ?';
            $params[] = (int) $progress['upto_key'];
        }
        $quoted = array_map(Db::quoteName(...), $key);
        $values = 0;
        $ambiguous = 0;
        $outside = 0;
        if ($ownTransaction) {
            $db->pdo()->beginTransaction();
        }
        try {
            $rows = $db->all('SELECT ' . implode(', ', [...$quoted, ...array_map(Db::quoteName(...), $columns)]) . ' FROM {' . $table . '} WHERE ' . $where
                . ' ORDER BY ' . implode(', ', $quoted) . ' LIMIT ' . self::BATCH . ' FOR UPDATE', $params);
            foreach ($rows as $row) {
                $set = [];
                foreach ($columns as $column) {
                    if (($converted = self::convert(isset($row[$column]) ? (string) $row[$column] : null, $from, $to)) === null) {
                        continue;
                    }
                    if ($converted[0] === null) {
                        $outside++;
                        continue;
                    }
                    $set[$column] = $converted[0];
                    $ambiguous += $converted[1] ? 1 : 0;
                }
                if ($set !== [] && $set !== array_intersect_key($row, $set)) {
                    $db->run('UPDATE {' . $table . '} SET ' . implode(', ', array_map(fn (string $c): string => Db::quoteName($c) . ' = ?', array_keys($set)))
                        . ' WHERE ' . implode(' AND ', array_map(fn (string $c): string => $c . ' = ?', $quoted)), [...array_values($set), ...array_map(fn (string $k): mixed => $row[$k], $key)]);
                    $values += count($set);
                }
            }
            $last = $rows === [] ? null : end($rows);
            $finished = count($rows) < self::BATCH;
            $db->run('UPDATE {time_zone_progress} SET last_key = ?, rows_done = rows_done + ?, values_done = values_done + ?, ambiguous = ambiguous + ?, out_of_range = out_of_range + ?,
                finished_at = ? WHERE conversion_id = ? AND table_name = ?', [$last === null ? (string) $progress['last_key'] : (string) json_encode(array_map(fn (string $k): mixed => $last[$k], $key)),
                count($rows), $values, $ambiguous, $outside, $finished ? Clock::now() : null, $id, $table]);
            if ($ownTransaction) {
                $db->pdo()->commit();
            }
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $db->pdo()->rollBack();
            }
            throw $e;
        }

        return $finished;
    }

    /**
     * The condition "after this primary key" in key order: (a > ?) OR (a = ? AND b > ?) … – spelled out, so MariaDB uses the
     * index as MySQL does.
     *
     * @param list<string> $key
     * @param list<mixed> $values
     * @return array{0: string, 1: list<mixed>}
     */
    private static function after(array $key, array $values): array
    {
        $or = [];
        $params = [];
        foreach ($key as $i => $column) {
            $and = [];
            for ($j = 0; $j < $i; $j++) {
                $and[] = Db::quoteName($key[$j]) . ' = ?';
                $params[] = $values[$j];
            }
            $and[] = Db::quoteName($column) . ' > ?';
            $params[] = $values[$i];
            $or[] = '(' . implode(' AND ', $and) . ')';
        }

        return ['(' . implode(' OR ', $or) . ')', $params];
    }

    /**
     * The primary key of a table: [column, numeric] in key order.
     *
     * @return list<array{0: string, 1: bool}>
     */
    private static function primaryKey(Db $db, string $table): array
    {
        $rows = $db->all("SELECT k.COLUMN_NAME, c.DATA_TYPE FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.COLUMNS c
            ON c.TABLE_SCHEMA = k.TABLE_SCHEMA AND c.TABLE_NAME = k.TABLE_NAME AND c.COLUMN_NAME = k.COLUMN_NAME
            WHERE k.TABLE_SCHEMA = DATABASE() AND k.TABLE_NAME = ? AND k.CONSTRAINT_NAME = 'PRIMARY' ORDER BY k.ORDINAL_POSITION", [$db->prefix . Names::physical($table)]);

        return array_map(fn (array $r): array => [(string) $r['COLUMN_NAME'], in_array(strtolower((string) $r['DATA_TYPE']), ['tinyint', 'smallint', 'mediumint', 'int', 'bigint'], true)], $rows);
    }
}
