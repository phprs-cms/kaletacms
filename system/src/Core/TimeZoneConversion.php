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
 *  - Rows written after the start are already on the new clock: a numeric primary key above upto_key is skipped. Smaller
 *    tables go first, and the background job "time_zone" runs before every other job and holds the others back until it
 *    is done (Core\Scheduler), so mail, webhooks and the job records are not written halfway.
 *  - The start and the end are in the change log (Admin\ChangeLog).
 */
final class TimeZoneConversion
{
    /** Rows per batch (one transaction). */
    public const int BATCH = 300;

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
     * Starts a conversion from $from to $to: one progress row per table, smaller tables first. Call it right after the
     * time_zone setting changed. Returns the id; 0 when another conversion still runs or the zones are the same or unknown.
     */
    public static function start(Db $db, string $from, string $to, ?int $userId): int
    {
        $zones = \DateTimeZone::listIdentifiers();
        if ($from === $to || !in_array($from, $zones, true) || !in_array($to, $zones, true) || self::running($db) !== null) {
            return 0;
        }
        $sizes = [];
        foreach ($db->all('SELECT TABLE_NAME, TABLE_ROWS FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()') as $r) {
            $sizes[(string) $r['TABLE_NAME']] = (int) $r['TABLE_ROWS'];
        }
        $tables = array_filter(array_keys(self::INSTANTS), fn (string $table): bool => isset($sizes[$db->prefix . Names::physical($table)]));
        usort($tables, fn (string $a, string $b): int => [$sizes[$db->prefix . Names::physical($a)], $a] <=> [$sizes[$db->prefix . Names::physical($b)], $b]);
        $db->pdo()->beginTransaction();
        try {
            $id = $db->insert('time_zone_conversions', ['from_zone' => $from, 'to_zone' => $to, 'started_by' => $userId, 'started_at' => Clock::now()]);
            foreach ($tables as $position => $table) {
                $key = self::primaryKey($db, $table);
                // a numeric key: rows added from now on are written on the new clock and must not move
                $upto = count($key) === 1 && $key[0][1] ? $db->value('SELECT MAX(' . Db::quoteName($key[0][0]) . ') FROM {' . $table . '}') : null;
                $db->insert('time_zone_progress', ['conversion_id' => $id, 'table_name' => $table, 'position' => $position, 'upto_key' => $upto === null ? null : (int) $upto]);
            }
            $db->pdo()->commit();
        } catch (\Throwable $e) {
            $db->pdo()->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * Converts the next batches of the running conversion within $seconds. Safe to run any number of times and to stop at
     * any moment. Returns null when nothing runs, otherwise the state: done (all tables), finished_now (this call finished
     * it – the caller writes the change log), tables, tables_done, values, ambiguous.
     *
     * @return array{id: int, from: string, to: string, done: bool, finished_now: bool, tables: int, tables_done: int, values: int, ambiguous: int}|null
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
            while (microtime(true) < $end) {
                if (self::batch($db, $id, $progress, $from, $to)) {
                    break; // the table is done
                }
                $progress = $db->one('SELECT * FROM {time_zone_progress} WHERE conversion_id = ? AND table_name = ?', [$id, $progress['table_name']]) ?? $progress;
            }
            if (microtime(true) >= $end) {
                break;
            }
        }
        $totals = $db->one('SELECT COUNT(*) AS tables, SUM(finished_at IS NOT NULL) AS tables_done, COALESCE(SUM(values_done), 0) AS `values`, COALESCE(SUM(ambiguous), 0) AS ambiguous
            FROM {time_zone_progress} WHERE conversion_id = ?', [$id]) ?? [];
        $done = (int) ($totals['tables'] ?? 0) === (int) ($totals['tables_done'] ?? 0);
        $finishedNow = $done && $db->run('UPDATE {time_zone_conversions} SET finished_at = ? WHERE id = ? AND finished_at IS NULL', [Clock::now(), $id])->rowCount() === 1;

        return ['id' => $id, 'from' => $from->getName(), 'to' => $to->getName(), 'done' => $done, 'finished_now' => $finishedNow, 'tables' => (int) ($totals['tables'] ?? 0),
            'tables_done' => (int) ($totals['tables_done'] ?? 0), 'values' => (int) ($totals['values'] ?? 0), 'ambiguous' => (int) ($totals['ambiguous'] ?? 0)];
    }

    /**
     * The end of a conversion in the change log (the start is written by Settings when the administrator chooses it).
     *
     * @param array{from: string, to: string, tables: int, values: int, ambiguous: int} $state
     */
    public static function logFinished(App $app, array $state): void
    {
        \Kaleta\Admin\ChangeLog::write($app, 'settings', 'time_zone_converted', $state['from'] . ' → ' . $state['to'] . ': ' . $state['values'] . ' values in ' . $state['tables'] . ' tables'
            . ($state['ambiguous'] > 0 ? ', ' . $state['ambiguous'] . ' in the repeated hour of a daylight-saving change' : ''));
    }

    /**
     * A value of a DATETIME column on the old zone's clock, on the new zone's clock; null when it is empty, zero or not a
     * date (left as it is). [new value, whether the old one was in an hour a DST change repeats].
     *
     * @return array{0: string, 1: bool}|null
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

        return [($ambiguous ? $earlier : $moment)->setTimezone($to)->format(Clock::FORMAT), $ambiguous];
    }

    /** One batch of one table in one transaction; true when the table is finished. */
    private static function batch(Db $db, int $id, array $progress, \DateTimeZone $from, \DateTimeZone $to): bool
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
        $rows = $db->all('SELECT ' . implode(', ', [...$quoted, ...array_map(Db::quoteName(...), $columns)]) . ' FROM {' . $table . '} WHERE ' . $where
            . ' ORDER BY ' . implode(', ', $quoted) . ' LIMIT ' . self::BATCH, $params);
        $values = 0;
        $ambiguous = 0;
        $db->pdo()->beginTransaction();
        try {
            foreach ($rows as $row) {
                $set = [];
                foreach ($columns as $column) {
                    if (($converted = self::convert(isset($row[$column]) ? (string) $row[$column] : null, $from, $to)) === null) {
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
            $db->run('UPDATE {time_zone_progress} SET last_key = ?, rows_done = rows_done + ?, values_done = values_done + ?, ambiguous = ambiguous + ?, finished_at = ?
                WHERE conversion_id = ? AND table_name = ?', [$last === null ? (string) $progress['last_key'] : (string) json_encode(array_map(fn (string $k): mixed => $last[$k], $key)),
                count($rows), $values, $ambiguous, $finished ? Clock::now() : null, $id, $table]);
            $db->pdo()->commit();
        } catch (\Throwable $e) {
            $db->pdo()->rollBack();
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
