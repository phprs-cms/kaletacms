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

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
        public readonly string $prefix = 'ka_',
    ) {
    }

    /** @param array{host?:string,port?:int,socket?:string,name:string,user:string,password:string,prefix?:string} $c */
    public static function fromConfig(array $c): self
    {
        $dsn = !empty($c['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['name'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', $c['port'] ?? 3306, $c['name']);

        return new self($dsn, $c['user'], $c['password'], $c['prefix'] ?? 'ka_');
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
        }

        return $this->pdo;
    }

    /**
     * Fills in the table prefix: {novinky} -> `ka_novinky`. The English name of a table works the same (3.10, Core\Names):
     * {news} -> `ka_novinky` until a release renames the table, and both keep working after that.
     */
    public function sql(string $sql): string
    {
        $legacy = [];
        $resolved = preg_replace_callback(
            '/\{([a-z][a-z0-9_]*)\}/',
            function (array $m) use (&$legacy): string {
                // 3.14: with add-ons switched on, a Czech name of a table 4.0 renames is logged for the add-on that wrote it
                if (LegacyNames::$watching && Names::isLegacy($m[1])) {
                    $legacy[] = $m[1];
                }

                return '`' . $this->prefix . Names::physical($m[1]) . '`';
            },
            $sql,
        ) ?? $sql;
        if ($legacy !== []) {
            LegacyNames::note($legacy);
        }

        return $resolved;
    }

    /**
     * A text column or expression for ORDER BY in the alphabetical order of the site language (3.12, Core\Collation):
     * 'titulek' unchanged on a Czech site, 'titulek COLLATE utf8mb4_german2_ci' on a German one. Only for ORDER BY.
     */
    public function sortText(string $expression, ?string $language = null): string
    {
        return Collation::sort($this, $expression, $language);
    }

    /** @param array<int|string, scalar|null> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
        $journaled = $this->journal?->aroundRaw($sql, $params);
        $stmt = $this->pdo()->prepare($this->sql($sql));
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
        $this->queryCount++;
        if ($journaled !== null) {
            $journaled();
        }

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
        $table = Names::physical($table); // {news} and 'news' are the news table – journaled and undone like 'novinky' (3.10, N310-5)
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
        $table = Names::physical($table);
        $set = implode(', ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($data)));
        $cond = implode(' AND ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($where)));
        $sql = sprintf('UPDATE {%s} SET %s WHERE %s', $name, $set, $cond);
        $before = $this->journal !== null && AgentJournal::journaled($table) ? $this->journal->rowsWhere($table, $cond, array_values($where)) : null;
        $count = $this->withoutJournal(fn (): int => $this->run($sql, [...array_values($data), ...array_values($where)])->rowCount());
        if ($before !== null) {
            $this->journal?->record($table, $before);
        }

        return $count;
    }

    /** @param array<string, scalar|null> $where */
    public function delete(string $table, array $where): int
    {
        $name = $table;
        $table = Names::physical($table);
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
