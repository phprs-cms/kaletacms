<?php

declare(strict_types=1);

namespace Kaleta\Core;

use PDO;
use PDOStatement;

/**
 * A thin layer over PDO.
 *
 * Table names are written in SQL in curly braces without a prefix:
 *   SELECT * FROM {novinky} WHERE id = ?
 * and on execution the prefix from the configuration is filled in (default "ka_").
 */
final class Db
{
    private ?PDO $pdo = null;

    public int $queryCount = 0;

    /** While a Claude connection runs a tool that changes the site: every content write is journaled for undo (2.17). */
    public ?AgentJournal $journal = null;

    /**
     * 3.15: whether the tables carry their English names (renamed by 4.0, then rolled back to 3.15). Read when the
     * connection opens from a marker file in storage/cache (no query), and found out again – one query – only when a
     * statement names a table the database does not have (tableNamesChanged()). null = not connected yet.
     */
    private ?bool $english = null;

    /** MySQL/MariaDB "Table … doesn't exist". */
    private const int MISSING_TABLE = 1146;

    /** MySQL/MariaDB "Unknown column …". */
    private const int MISSING_COLUMN = 1054;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
        public readonly string $prefix = 'ka_',
    ) {
    }

    /**
     * The session's sql_mode: the server's own, without NO_BACKSLASH_ESCAPES and ANSI_QUOTES (3.19.1, N81, N3191-7) – in any
     * position, MySQL and MariaDB. The combination mode ANSI goes too – it would bring ANSI_QUOTES back (its other parts
     * stay listed on their own).
     */
    public const string SQL_MODE = "TRIM(BOTH ',' FROM REPLACE(REPLACE(REPLACE(CONCAT(',', @@SESSION.sql_mode, ','), ',NO_BACKSLASH_ESCAPES,', ','), ',ANSI_QUOTES,', ','), ',ANSI,', ','))";

    /** @param array{host?:string,port?:int,socket?:string,name:string,user:string,password:string,prefix?:string} $c */
    public static function fromConfig(array $c): self
    {
        $dsn = !empty($c['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['name'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', $c['port'] ?? 3306, $c['name']);

        return new self($dsn, $c['user'], $c['password'], $c['prefix'] ?? 'ka_');
    }

    /**
     * The name of a GET_LOCK for one purpose (3.15): per database DSN and table prefix, so two sites on one MySQL server
     * never wait for each other; at most 64 characters (the limit of MySQL).
     */
    public function lockName(string $purpose, string $scope = ''): string
    {
        return substr('kaleta-' . $purpose . '-' . hash('sha256', $this->dsn . '|' . $this->prefix . '|' . $scope), 0, 64);
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // The database must count time the same way as PHP: PHP writes article dates (date()), but queries compare them with NOW().
            // On a server with the database in a different zone (typically UTC) a just published article would show up hours later.
            // An offset instead of a zone name: named zones need loaded tables in MySQL, which are often missing on hosting.
            $this->pdo->exec("SET time_zone = '" . Clock::offset() . "'");
            // 3.19.1 (N81): NO_BACKSLASH_ESCAPES (and ANSI_QUOTES) in the server's sql_mode are dropped for Kaleta's session –
            // PDO::quote and every LIKE pattern built with addcslashes() rely on the backslash (without it every backup came out
            // empty), and Kaleta's SQL quotes strings, never names, with ". A host that refuses it keeps the site running (N3191-10)
            try {
                $this->pdo->exec('SET sql_mode = ' . self::SQL_MODE);
            } catch (\PDOException) {
                // any database error: the server's mode stays; Backup::create refuses to write an empty backup in that case
            }
            // 3.15: today's names unless this database was found renamed before (the marker) – no query on a normal site
            $this->english = $this->marker() !== null && is_file($this->marker());
            Names::setRenamed($this->english);
        }

        return $this->pdo;
    }

    /**
     * 3.15: asks the database which names its tables have – the settings table under today's name (`ka_nastaveni`) or under
     * its English one (`ka_settings`, after 4.0 renamed the tables) – and remembers the answer in the marker file. Today's
     * name wins when both exist (a backup from before 4.0 restored over a renamed database). Called when a statement
     * misses a table, by migrations and after a backup is restored; returns whether the tables carry the English names.
     */
    public function detectTableNames(): bool
    {
        $pdo = $this->pdo();
        $today = $this->prefix . Names::physical('nastaveni', false);
        $english = $this->prefix . Names::physical('nastaveni', true);
        $stmt = $pdo->prepare('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN (?, ?)');
        $stmt->execute([$today, $english]);
        $found = array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        // neither (an empty database before the installation): today's names, nothing remembered
        $this->english = !in_array($today, $found, true) && in_array($english, $found, true);
        Names::setRenamed($this->english);
        $marker = $this->marker();
        if ($marker !== null) {
            $this->english ? @touch($marker) : (is_file($marker) && @unlink($marker));
        }

        return $this->english;
    }

    /** Whether the tables of this database carry their English names (3.15); connects first. */
    public function englishTables(): bool
    {
        $this->pdo();

        return (bool) $this->english;
    }

    /**
     * The full name of a table in this database, prefix included, for the places that name it without a placeholder
     * (information_schema, SHOW … LIKE): 'stranky' or 'pages' → ka_stranky, or ka_pages once 4.0 renamed the tables (3.15).
     */
    public function tableName(string $table): string
    {
        return $this->prefix . Names::physical($table, $this->englishTables());
    }

    /** The marker of a renamed database: one file per database and prefix in storage/cache, there only while renamed. */
    private function marker(): ?string
    {
        return defined('KALETA_ROOT') ? KALETA_ROOT . '/storage/cache/tables-english-' . substr(hash('sha256', $this->dsn . '|' . $this->prefix), 0, 16) : null;
    }

    /** A statement missed a table: did the names of the tables change since the connection opened (3.15)? */
    private function tableNamesChanged(\PDOException $e): bool
    {
        if ((int) ($e->errorInfo[1] ?? 0) !== self::MISSING_TABLE) {
            return false;
        }
        $before = $this->english;
        try {
            return $this->detectTableNames() !== $before;
        } catch (\PDOException) {
            return false; // any database error: run() throws the statement's own error
        }
    }

    /**
     * 3.36 (T3): whether a database error only says that a table or a column does not exist (yet) – SQLSTATE 42S02 / 42S22,
     * MySQL and MariaDB 1146 / 1054. That is the one error code written before its migration may read as "nothing yet";
     * a lost connection, a deadlock or a mistake in the SQL is not.
     */
    public static function tableMissing(\PDOException $e): bool
    {
        return in_array((int) ($e->errorInfo[1] ?? 0), [self::MISSING_TABLE, self::MISSING_COLUMN], true)
            || in_array((string) $e->getCode(), ['42S02', '42S22'], true);
    }

    /**
     * For a catch block of code that may run before its migration (new files on the server, the database not updated yet):
     * returns when the table or column is missing – the caller then answers "nothing yet" – and throws the error on in
     * every other case, so a real database error is logged and shown as an error, never as an empty list (3.36, T3).
     */
    public static function rethrowUnlessMissing(\PDOException $e): void
    {
        if (!self::tableMissing($e)) {
            throw $e;
        }
    }

    /**
     * Fills in the table prefix: {novinky} -> `ka_novinky`. The English name of a table works the same (3.10, Core\Names):
     * {news} -> `ka_novinky`; on a database with English table names (the 3.15 bridge) both give `ka_news`.
     */
    public function sql(string $sql): string
    {
        return preg_replace_callback(
            '/\{([a-z][a-z0-9_]*)\}/',
            fn (array $m): string => '`' . $this->prefix . Names::physical($m[1], $this->english ?? false) . '`',
            $sql,
        ) ?? $sql;
    }

    /**
     * A text column or expression for ORDER BY in the alphabetical order of the site language (3.12, Core\Collation):
     * 'titulek' unchanged on a Czech site, 'titulek COLLATE utf8mb4_german2_ci' on a German one. Only for ORDER BY.
     */
    public function sortText(string $expression, ?string $language = null): string
    {
        return Collation::sort($this, $expression, $language);
    }

    /** The collation a search compares with: case and diacritics do not matter ("dvorak" finds Dvořák; MySQL and MariaDB). */
    public const string SEARCH_COLLATION = 'utf8mb4_unicode_ci';

    /**
     * A search in admin lists and MCP (3.52, A1): the words of $q, each found in at least one of the columns, in any
     * order – "novak jan" finds "Jan Novák". At most 100 characters and $maxWords words; '%', '_' and '\' are literal.
     * $json names columns holding JSON text (the form data of enquiries, collection items), searched in both ways the
     * text may be stored (likeJson()). $more adds one alternative per word (null = none), e.g. the normalised
     * search column of news. An empty search gives ['1 = 1', []]. The columns must be utf8mb4 text.
     *
     * @param list<string> $columns
     * @param list<string> $json
     * @param (\Closure(string): (array{0: string, 1: list<string>}|null))|null $more
     * @return array{0: string, 1: list<string>}
     */
    public static function likeWords(array $columns, string $q, array $json = [], ?\Closure $more = null, int $maxWords = 6): array
    {
        $words = array_slice(preg_split('/\s+/u', trim(mb_substr($q, 0, 100)), -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, max(1, $maxWords));
        if ($words === []) {
            return ['1 = 1', []];
        }
        $groups = [];
        $params = [];
        foreach ($words as $word) {
            $alternatives = [];
            foreach ($columns as $column) {
                $alternatives[] = $column . ' COLLATE ' . self::SEARCH_COLLATION . ' LIKE ?';
                $params[] = '%' . addcslashes($word, '%_\\') . '%';
            }
            foreach ($json as $column) {
                [$sql, $jsonParams] = self::likeJson($column, $word);
                $alternatives[] = $sql;
                array_push($params, ...$jsonParams);
            }
            if ($more !== null && ($extra = $more($word)) !== null) {
                $alternatives[] = $extra[0];
                array_push($params, ...$extra[1]);
            }
            $groups[] = '(' . implode(' OR ', $alternatives) . ')';
        }

        return ['(' . implode(' AND ', $groups) . ')', $params];
    }

    /**
     * One word in a column with JSON text: as json_encode writes it with JSON_UNESCAPED_UNICODE (forms, collection items),
     * with or without escaped slashes, and as older rows have it, with \uXXXX instead of diacritics.
     *
     * @return array{0: string, 1: list<string>}
     */
    public static function likeJson(string $column, string $word): array
    {
        $patterns = [];
        foreach ([JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, JSON_UNESCAPED_UNICODE, 0] as $flags) {
            $patterns['%' . addcslashes(substr((string) json_encode($word, $flags | JSON_INVALID_UTF8_SUBSTITUTE), 1, -1), '%_\\') . '%'] = true;
        }
        $patterns = array_map('strval', array_keys($patterns));

        return ['(' . implode(' OR ', array_fill(0, count($patterns), $column . ' COLLATE ' . self::SEARCH_COLLATION . ' LIKE ?')) . ')', $patterns];
    }

    /** @param array<int|string, scalar|null> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $journaled = $this->journal?->aroundRaw($sql, $params);
        $pdo = $this->pdo(); // connected first: the connection decides which table names sql() writes
        try {
            $stmt = $this->statement($pdo, $sql, $params);
        } catch (\PDOException $e) {
            // 3.15: a missing table after the tables were renamed (4.0, or its rollback) – once more with the names the
            // database has now; any other error, or the same answer, is thrown as it is
            if (!$this->tableNamesChanged($e)) {
                throw $e;
            }
            $stmt = $this->statement($pdo, $sql, $params);
        }
        $this->queryCount++;
        if ($journaled !== null) {
            $journaled();
        }

        return $stmt;
    }

    /** @param array<int|string, scalar|null> $params */
    private function statement(PDO $pdo, string $sql, array $params): PDOStatement
    {
        $stmt = $pdo->prepare($this->sql($sql));
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $stmt->bindValue(is_int($key) ? $key + 1 : $key, $value, $type);
        }
        $stmt->execute();

        return $stmt;
    }

    /** @return list<array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->run($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $value = $this->run($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /** First column as key, second as value - handy for <select>. */
    public function pairs(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @param array<string, scalar|null> $data */
    public function insert(string $table, array $data): int
    {
        $name = $table; // the placeholder keeps the name the caller wrote ({news} resolves the same; only a legacy name is logged, 3.14)
        $table = Names::table($table); // {news} and 'news' are the news table – journaled and undone like 'novinky' (3.10, N310-5)
        $data = $this->searchText($table, $data, true);
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO {%s} (%s) VALUES (%s)',
            $name,
            implode(', ', array_map(self::quoteName(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        $this->withoutJournal(fn (): PDOStatement => $this->run($sql, array_values($data)));
        $id = (int) $this->pdo()->lastInsertId();
        if ($this->journal !== null && AgentJournal::journaled($table)) {
            $this->journal->inserted($table, $data, $id);
        }

        return $id;
    }

    /**
     * @param array<string, scalar|null> $data
     * @param array<string, scalar|null> $where conditions joined with AND
     */
    public function update(string $table, array $data, array $where): int
    {
        $name = $table;
        $table = Names::table($table);
        $data = $this->searchText($table, $data, false);
        if ($data === []) {
            return 0; // only a search text was passed: the rule took it away
        }
        $cond = implode(' AND ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($where)));
        $write = fn (array $data): int => $this->run(sprintf('UPDATE {%s} SET %s WHERE %s', $name, implode(', ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($data))), $cond),
            [...array_values($data), ...array_values($where)])->rowCount();
        $before = $this->journal !== null && AgentJournal::journaled($table) ? $this->journal->rowsWhere($table, $cond, array_values($where)) : null;
        $count = $this->withoutJournal(function () use ($write, $data): int {
            try {
                return $write($data);
            } catch (\PDOException $e) {
                // new files on a database before migration 0141 (the update has not run, or failed): the content is saved
                // without the search text, which is not there yet – once per connection, then the rule leaves it out
                if (!array_key_exists('hledani', $data) || !self::tableMissing($e)) {
                    throw $e;
                }
                $this->searchTextMissing = true;
                unset($data['hledani']);

                return $write($data);
            }
        });
        if ($before !== null) {
            $this->journal?->record($table, $before);
        }

        return $count;
    }

    /** The search text column was found missing on this connection (a database before migration 0141, 3.53 A4). */
    private bool $searchTextMissing = false;

    /**
     * 3.53 (A4): the one rule that keeps the search text of pages and collection items from going stale. An update that
     * writes a content column (SearchIndex::CONTENT) empties hledani – also over a value the caller passed, so a copied
     * full row never carries another row's text – and an insert never sets it. Core\SearchIndex makes the text again: right
     * after a save (SearchIndex::page/item) or by the background job. Raw SQL that changes page or item content must set
     * hledani = NULL itself.
     *
     * @param array<string, scalar|null> $data
     * @return array<string, scalar|null>
     */
    private function searchText(string $table, array $data, bool $insert): array
    {
        $content = SearchIndex::CONTENT[$table] ?? null;
        if ($content === null) {
            return $data;
        }
        unset($data['hledani']);
        if (!$insert && !$this->searchTextMissing && array_intersect_key($data, array_flip($content)) !== []) {
            $data['hledani'] = null;
        }

        return $data;
    }

    /** @param array<string, scalar|null> $where */
    public function delete(string $table, array $where): int
    {
        $name = $table;
        $table = Names::table($table);
        if ($where === []) {
            throw new \LogicException('Deleting without a condition is not allowed.');
        }
        $cond = implode(' AND ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($where)));
        $before = $this->journal !== null && AgentJournal::journaled($table) ? $this->journal->rowsWhere($table, $cond, array_values($where)) : null;
        $count = $this->withoutJournal(fn (): int => $this->run(sprintf('DELETE FROM {%s} WHERE %s', $name, $cond), array_values($where))->rowCount());
        if ($before !== null) {
            $this->journal?->record($table, $before);
        }

        return $count;
    }

    /**
     * 3.53 (A3): writes of derived data that never enter the undo journal of a Claude session (the search text hledani,
     * Core\SearchIndex) – an undo restores the content, and the derived data is made again from it.
     */
    public function unjournaled(\Closure $fn): mixed
    {
        return $this->withoutJournal($fn);
    }

    /** A helper's own statement: the helper journals it with the rows it knows, run() must not journal it again. */
    private function withoutJournal(\Closure $fn): mixed
    {
        $journal = $this->journal;
        $this->journal = null;
        try {
            return $fn();
        } finally {
            $this->journal = $journal;
        }
    }

    /**
     * Runs $fn in a transaction. Called inside another one (3.12: a collection category saved by the WordPress import, which
     * runs each post in its own transaction), it joins the outer transaction – the outer one commits or rolls back all of it.
     *
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $joined = $this->pdo()->inTransaction();
        if ($joined) {
            return $fn($this);
        }
        $this->pdo()->beginTransaction();
        try {
            $result = $fn($this);
            $this->pdo()->commit();

            return $result;
        } catch (\Throwable $e) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }
            throw $e;
        }
    }

    public static function quoteName(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/iD', $name)) {
            throw new \InvalidArgumentException("Neplatný název sloupce: {$name}");
        }

        return '`' . $name . '`';
    }
}
