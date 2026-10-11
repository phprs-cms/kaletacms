<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Undo a whole Claude session (2.17). While a Claude connection runs a tool that changes the site, Core\Db hands every
 * write on a content table to this journal: the row before and after, by its primary key. The changes of one connection
 * that follow each other within SESSION_GAP minutes form a session; the administrator (or Claude, on the user's request)
 * can take a whole session back in one step.
 *
 *  - Undo restores, in reverse order, every row the session touched to its state before the session: rows it created are
 *    deleted, rows it changed or deleted are written back (an upsert – never REPLACE, which would cascade-delete children).
 *  - A row someone changed after the session (a person in the admin, another session) is a conflict: it is left alone and
 *    reported, unless the undo is forced.
 *  - A write the journal cannot follow (a raw INSERT … SELECT, a statement without a WHERE it can repeat) is recorded as
 *    untracked and named in the result – undo never pretends to be complete when it is not.
 *  - Only content tables are journaled (TABLES); logs, statistics, queues and security tables are not part of a session.
 *    Files uploaded to Media stay on disk; their Media rows go.
 *
 * The journal is kept JOURNAL_DAYS days.
 */
final class AgentJournal
{
    public const int SESSION_GAP = 30;

    public const int JOURNAL_DAYS = 30;

    /**
     * Content tables a Claude session may change and undo may restore. Not the personal data of visitors (3.3.2): enquiries,
     * testimonial requests, bookings, subscribers and mail are never copied into the journal, so an erasure on request
     * (Core\PersonalData) cannot be undone and the person's data does not wait here for JOURNAL_DAYS.
     */
    public const array TABLES = ['nastaveni', 'kategorie', 'novinky', 'novinky_revize', 'novinky_koncepty', 'novinky_stitky', 'stitky', 'media', 'media_slozky',
        'media_pouziti', 'stranky', 'stranky_revize', 'casti', 'stavba_revize', 'tridy', 'presmerovani', 'kolekce', 'kolekce_polozky', 'kolekce_sablony',
        'collection_categories', 'collection_category_texts', 'collection_item_categories', 'collection_category_templates',
        'document_versions', 'menu', 'sekce', 'popupy', 'komponenty', 'newsletters', 'look_versions', 'facts', 'fact_history', 'hours_exceptions', 'blueprints',
        'social_drafts', 'notebook', 'requests', 'request_messages', 'draft_comments'];

    /** Tables with visitors' personal data: never journaled; rows an older release journaled are redacted by forget(). */
    public const array PERSONAL_TABLES = ['poptavky', 'testimonial_requests', 'bookings', 'odberatele', 'posta', 'odber_fronta', 'newsletter_queue'];

    /** Settings keys that are the site's own bookkeeping (timestamps of background work, versions) – never undone. */
    private const string BOOKKEEPING = '/^(db_version|data_migrations|notification_check)$|_(check|time|checked|seen|ts|at)$/';

    /** @var array<string, list<string>> primary key columns by table */
    private static array $keys = [];

    private bool $busy = false;

    private function __construct(private readonly Db $db, public readonly int $session, private readonly string $tool, private readonly int $call)
    {
    }

    /**
     * Starts journaling one tool call of a connection: finds the connection's open session (a change within SESSION_GAP
     * minutes) or opens a new one. Returns null when the tables are not there yet (before the migration).
     */
    public static function start(Db $db, string $connection, string $tool): ?self
    {
        try {
            $now = Clock::now();
            $session = $db->one('SELECT id FROM {agent_sessions} WHERE connection = ? AND undone_at IS NULL AND last_at > ? ORDER BY id DESC LIMIT 1',
                [$connection, Clock::toDb(time() - self::SESSION_GAP * 60)]);
            $id = $session !== null ? (int) $session['id'] : $db->insert('agent_sessions', ['connection' => mb_substr($connection, 0, 100), 'started_at' => $now, 'last_at' => $now, 'calls' => 0]);
            $db->run('UPDATE {agent_sessions} SET last_at = ?, calls = calls + 1 WHERE id = ?', [$now, $id]);
            $call = (int) $db->value('SELECT calls FROM {agent_sessions} WHERE id = ?', [$id]);
        } catch (\PDOException) {
            return null;
        }

        return new self($db, $id, mb_substr($tool, 0, 60), $call);
    }

    /** Is a table journaled? It takes the English name too (pages = stranky, 3.10); the journal itself stores today's name. */
    public static function journaled(string $table): bool
    {
        return in_array(Names::physical($table), self::TABLES, true);
    }

    /* ---------- called by Core\Db around its writes ---------- */

    /** The rows a write with this condition will touch, before it. @param array<string, mixed> $where @return list<array<string, mixed>> */
    public function rowsWhere(string $table, string $whereSql, array $params): array
    {
        $table = Names::physical($table);
        return $this->quietly(fn (): array => $this->db->all('SELECT * FROM {' . $table . '} WHERE ' . $whereSql, $params));
    }

    /**
     * Records the change of rows: $before are the rows before the write (empty for an insert), $after is looked up by their
     * keys now (or by $insertedKey for an insert).
     *
     * @param list<array<string, mixed>> $before
     * @param array<string, mixed>|null $insertedKey
     */
    public function record(string $table, array $before, ?array $insertedKey = null): void
    {
        $table = Names::physical($table); // the undo, the bookkeeping rule below and the key lookup know the table by today's name
        $this->quietly(function () use ($table, $before, $insertedKey): void {
            $keyColumns = self::primaryKey($this->db, $table);
            if ($keyColumns === []) {
                $this->untracked($table, 'a table without a primary key');

                return;
            }
            $targets = $insertedKey !== null ? [[$insertedKey, null]] : array_map(fn (array $row): array => [array_intersect_key($row, array_flip($keyColumns)), $row], $before);
            foreach ($targets as [$key, $old]) {
                if (count($key) !== count($keyColumns)) {
                    $this->untracked($table, 'a row without its full key');

                    continue;
                }
                if ($table === 'nastaveni' && preg_match(self::BOOKKEEPING, (string) ($key['promenna'] ?? '')) === 1) {
                    continue;
                }
                $new = $this->db->one('SELECT * FROM {' . $table . '} WHERE ' . self::condition(array_keys($key)), array_values($key));
                if ($old === $new) {
                    continue; // nothing changed (an update to the same values)
                }
                $this->db->insert('agent_journal', ['session_id' => $this->session, 'call_no' => $this->call, 'tool' => $this->tool, 'tbl' => $table,
                    'row_key' => (string) json_encode($key, JSON_UNESCAPED_UNICODE), 'before_row' => $old === null ? null : (string) json_encode($old, JSON_UNESCAPED_UNICODE),
                    'after_row' => $new === null ? null : (string) json_encode($new, JSON_UNESCAPED_UNICODE), 'created_at' => Clock::now()]);
            }
        });
    }

    /**
     * A row inserted by Core\Db::insert(): its key is the auto-increment id, or the key columns from the inserted data.
     *
     * @param array<string, mixed> $data
     */
    public function inserted(string $table, array $data, int $id): void
    {
        $table = Names::physical($table);
        $keyColumns = $this->quietly(fn (): array => self::primaryKey($this->db, $table));
        $key = count($keyColumns) === 1 && $id > 0 ? [$keyColumns[0] => $id] : array_intersect_key($data, array_flip($keyColumns));
        $this->record($table, [], $key);
    }

    /** A write the journal cannot follow – named in the session and in the undo result. */
    public function untracked(string $table, string $why): void
    {
        $table = Names::physical($table);
        $this->quietly(fn (): int => $this->db->insert('agent_journal', ['session_id' => $this->session, 'call_no' => $this->call, 'tool' => $this->tool, 'tbl' => $table,
            'row_key' => '', 'before_row' => null, 'after_row' => null, 'untracked' => mb_substr($why, 0, 120), 'created_at' => Clock::now()]));
    }

    /**
     * A raw statement given to Core\Db::run(): UPDATE/DELETE with a WHERE is repeated as a SELECT to find its rows; an
     * INSERT … ON DUPLICATE KEY UPDATE with the full primary or unique key in its columns is followed by that key. Returns
     * a closure to call after the statement ran, or null when there is nothing to journal.
     *
     * @param array<int|string, scalar|null> $params
     */
    public function aroundRaw(string $sql, array $params): ?\Closure
    {
        if ($this->busy || preg_match('/^\s*(UPDATE|DELETE\s+FROM|INSERT\s+INTO|REPLACE\s+INTO)\s+\{([a-z0-9_]+)\}/i', $sql, $m) !== 1 || !self::journaled($table = Names::physical($m[2]))) {
            return null;
        }
        $verb = strtoupper(substr(ltrim($m[1]), 0, 6));
        if (($verb === 'UPDATE' || $verb === 'DELETE') && array_is_list($params) && preg_match('/\sWHERE\s(.+)$/is', $sql, $w) === 1 && stripos($w[1], 'SELECT') === false) {
            $whereSql = (string) preg_replace('/\s+(ORDER\s+BY|LIMIT)\s.*$/is', '', $w[1]);
            $count = substr_count($whereSql, '?');
            $before = $this->rowsWhere($table, $whereSql, $count > 0 ? array_slice($params, -$count) : []);

            return fn () => $this->record($table, $before);
        }
        if ($verb === 'INSERT' && array_is_list($params) && preg_match('/^\s*INSERT\s+INTO\s+\{[a-z0-9_]+\}\s*\(([^)]+)\)\s*VALUES\s*\(([^)]*)\)\s*ON\s+DUPLICATE/is', $sql, $i) === 1) {
            $columns = array_map(fn (string $c): string => trim($c, " `\t\n"), explode(',', $i[1]));
            if (count($columns) === count($params)) {
                $values = array_combine($columns, $params);
                foreach ($this->quietly(fn (): array => self::uniqueKeys($this->db, $table)) as $key) {
                    if (array_diff($key, $columns) === []) {
                        $where = array_intersect_key($values, array_flip($key));
                        $before = $this->rowsWhere($table, self::condition(array_keys($where)), array_values($where));
                        $primary = self::primaryKey($this->db, $table);

                        return fn () => $before !== [] ? $this->record($table, $before) : $this->record($table, [], array_intersect_key(
                            (array) $this->quietly(fn (): ?array => $this->db->one('SELECT * FROM {' . $table . '} WHERE ' . self::condition(array_keys($where)), array_values($where))),
                            array_flip($primary)));
                    }
                }
            }
        }

        return fn () => $this->untracked($table, strtolower($verb) . ' that cannot be followed row by row');
    }

    /* ---------- sessions and undo ---------- */

    /** @return list<array<string, mixed>> the newest sessions with their counts */
    public static function sessions(Db $db, int $limit = 50): array
    {
        return $db->all('SELECT s.*, (SELECT COUNT(*) FROM {agent_journal} j WHERE j.session_id = s.id AND j.untracked IS NULL) AS rows_changed,
            (SELECT COUNT(*) FROM {agent_journal} j WHERE j.session_id = s.id AND j.untracked IS NOT NULL) AS rows_untracked,
            (SELECT GROUP_CONCAT(DISTINCT j.tool ORDER BY j.tool SEPARATOR \', \') FROM {agent_journal} j WHERE j.session_id = s.id) AS tools
            FROM {agent_sessions} s ORDER BY s.id DESC LIMIT ' . max(1, min(200, $limit)));
    }

    /**
     * Takes a session back. Returns what happened: rows restored, rows of the session deleted, conflicts left alone (with
     * table and key) and writes it could not follow.
     *
     * @return array{restored: int, removed: int, conflicts: list<array{table: string, key: array<string, mixed>}>, untracked: list<string>, undone: bool}
     */
    public static function undo(App $app, int $sessionId, bool $force = false): array
    {
        $db = $app->db();
        $session = $db->one('SELECT * FROM {agent_sessions} WHERE id = ?', [$sessionId]) ?? throw new \InvalidArgumentException('The session does not exist.');
        if ($session['undone_at'] !== null) {
            throw new \DomainException('This session was already undone.');
        }
        $entries = $db->all('SELECT * FROM {agent_journal} WHERE session_id = ? ORDER BY id', [$sessionId]);
        $untracked = array_values(array_unique(array_map(fn (array $e): string => $e['tbl'] . ': ' . $e['untracked'] . ' (' . $e['tool'] . ')',
            array_filter($entries, fn (array $e): bool => $e['untracked'] !== null))));
        // per row: the state before the session (the first entry) and after it (the last one), in the order they were first touched
        $rows = [];
        foreach ($entries as $e) {
            if ($e['untracked'] !== null) {
                continue;
            }
            $id = $e['tbl'] . '|' . $e['row_key'];
            $rows[$id] ??= ['table' => $e['tbl'], 'key' => (array) json_decode((string) $e['row_key'], true), 'before' => $e['before_row']];
            $rows[$id]['after'] = $e['after_row'];
        }
        $result = ['restored' => 0, 'removed' => 0, 'conflicts' => [], 'untracked' => $untracked, 'undone' => false];
        $db->transaction(function (Db $db) use ($rows, $force, &$result): void {
            foreach (array_reverse($rows) as $row) {
                $current = $db->one('SELECT * FROM {' . $row['table'] . '} WHERE ' . self::condition(array_keys($row['key'])), array_values($row['key']));
                $expected = $row['after'] !== null ? json_decode((string) $row['after'], true) : null;
                if (!$force && !self::same($current, $expected)) {
                    $result['conflicts'][] = ['table' => $row['table'], 'key' => $row['key']];

                    continue;
                }
                if ($row['before'] === null) {
                    if ($current !== null) {
                        $db->delete($row['table'], $row['key']);
                        $result['removed']++;
                    }

                    continue;
                }
                $before = (array) json_decode((string) $row['before'], true);
                $columns = array_keys($before);
                $db->run('INSERT INTO {' . $row['table'] . '} (' . implode(', ', array_map(fn (string $c): string => '`' . str_replace('`', '', $c) . '`', $columns)) . ') VALUES ('
                    . implode(', ', array_fill(0, count($columns), '?')) . ') ON DUPLICATE KEY UPDATE '
                    . implode(', ', array_map(fn (string $c): string => '`' . str_replace('`', '', $c) . '` = VALUES(`' . str_replace('`', '', $c) . '`)', $columns)), array_values($before));
                $result['restored']++;
            }
        });
        $user = $app->auth()->user();
        $db->update('agent_sessions', ['undone_at' => Clock::now(), 'undone_by' => mb_substr((string) ($user['jmeno'] ?? '') ?: (string) ($user['user'] ?? ''), 0, 100)], ['id' => $sessionId]);
        $result['undone'] = true;
        \Kaleta\Front\Cache::clear();
        \Kaleta\Admin\ChangeLog::write($app, 'changelog', 'undo_session', '#' . $sessionId . ' ' . $session['connection'] . ': ' . $result['restored'] . '/' . $result['removed'] . '/' . count($result['conflicts']));
        Events::record($db, 'claude.session_undone', 'info', t('A Claude session was undone: %d rows restored, %d removed, %d left because they changed since.', $result['restored'], $result['removed'], count($result['conflicts'])),
            ['session' => $sessionId, 'restored' => $result['restored'], 'removed' => $result['removed'], 'conflicts' => count($result['conflicts'])]);

        return $result;
    }

    /**
     * Forgets an e-mail address in the journal (3.3.2, Core\PersonalData::erase): every entry of a personal-data table that
     * names it loses its rows and becomes an untracked write, so the session still says something was there but undo
     * cannot write it back and nobody can read it from the journal. Returns how many entries were redacted.
     */
    public static function forget(Db $db, string $email): int
    {
        $like = '%' . addcslashes(mb_strtolower($email), '%_\\') . '%';
        try {
            return $db->run("UPDATE {agent_journal} SET row_key = '', before_row = NULL, after_row = NULL, untracked = 'personal data erased on request' WHERE tbl IN ("
                . implode(',', array_fill(0, count(self::PERSONAL_TABLES), '?')) . ') AND (LOWER(before_row) LIKE ? OR LOWER(after_row) LIKE ?)', [...self::PERSONAL_TABLES, $like, $like])->rowCount();
        } catch (\PDOException) {
            return 0; // before the 2.17 migration
        }
    }

    /** Deletes journal entries and sessions older than JOURNAL_DAYS (a daily job). */
    public static function purge(Db $db): string
    {
        $before = Clock::toDb(time() - self::JOURNAL_DAYS * 86400);
        $n = $db->run('DELETE FROM {agent_sessions} WHERE last_at < ?', [$before])->rowCount();

        return 'sessions removed ' . $n;
    }

    /** Two rows equal by value (the database gives strings; a JSON round trip may give numbers). */
    public static function same(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }
        $norm = fn (array $r): array => array_map(fn (mixed $v): ?string => $v === null ? null : (string) $v, $r);

        return $norm($a) == $norm($b);
    }

    /** @param list<string> $columns */
    private static function condition(array $columns): string
    {
        return implode(' AND ', array_map(fn (string $c): string => '`' . str_replace('`', '', $c) . '` = ?', $columns));
    }

    /** @return list<string> */
    private static function primaryKey(Db $db, string $table): array
    {
        return self::$keys[$table] ??= array_map(fn (mixed $c): string => (string) $c, array_column($db->all("SHOW KEYS FROM {" . $table . "} WHERE Key_name = 'PRIMARY'"), 'Column_name'));
    }

    /** The primary key and every unique key, each as its columns. @return list<list<string>> */
    private static function uniqueKeys(Db $db, string $table): array
    {
        $keys = [];
        foreach ($db->all('SHOW KEYS FROM {' . $table . '} WHERE Non_unique = 0') as $k) {
            $keys[(string) $k['Key_name']][(int) $k['Seq_in_index']] = (string) $k['Column_name'];
        }

        return array_values(array_map(fn (array $c): array => array_values($c), $keys));
    }

    /** Runs the journal's own queries without journaling them. */
    private function quietly(callable $fn): mixed
    {
        $journal = $this->db->journal;
        $this->db->journal = null;
        $this->busy = true;
        try {
            return $fn();
        } finally {
            $this->busy = false;
            $this->db->journal = $journal;
        }
    }
}
