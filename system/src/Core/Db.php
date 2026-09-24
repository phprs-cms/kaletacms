<?php

declare(strict_types=1);

namespace MiroCMS\Core;

use PDO;
use PDOStatement;

/**
 * Tenká vrstva nad PDO.
 *
 * Názvy tabulek se v SQL píší ve složených závorkách bez předpony:
 *   SELECT * FROM {novinky} WHERE id = ?
 * a při spuštění se doplní předpona z konfigurace (výchozí "mc_").
 */
final class Db
{
    private ?PDO $pdo = null;

    public int $queryCount = 0;

    public function __construct(
        private readonly string $dsn,
        private readonly string $user,
        private readonly string $password,
        public readonly string $prefix = 'mc_',
    ) {
    }

    /** @param array{host?:string,port?:int,socket?:string,name:string,user:string,password:string,prefix?:string} $c */
    public static function fromConfig(array $c): self
    {
        $dsn = !empty($c['socket'])
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=utf8mb4', $c['socket'], $c['name'])
            : sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $c['host'] ?? 'localhost', $c['port'] ?? 3306, $c['name']);

        return new self($dsn, $c['user'], $c['password'], $c['prefix'] ?? 'mc_');
    }

    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO($this->dsn, $this->user, $this->password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
            // Databáze musí počítat čas stejně jako PHP: data článků zapisuje PHP (date()), ale dotazy je porovnávají s NOW().
            // Na serveru s databází v jiném pásmu (typicky UTC) by se právě vydaný článek ukázal až o hodiny později.
            // Posun místo názvu pásma: pojmenovaná pásma vyžadují v MySQL nahrané tabulky, které na hostinzích často chybí.
            $this->pdo->exec("SET time_zone = '" . date('P') . "'");
        }

        return $this->pdo;
    }

    /** Doplní předponu tabulek: {novinky} -> `mc_novinky`. */
    public function sql(string $sql): string
    {
        return preg_replace_callback(
            '/\{([a-z][a-z0-9_]*)\}/',
            fn (array $m): string => '`' . $this->prefix . $m[1] . '`',
            $sql,
        ) ?? $sql;
    }

    /** @param array<int|string, scalar|null> $params */
    public function run(string $sql, array $params = []): PDOStatement
    {
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

    /** První sloupec jako klíč, druhý jako hodnota - hodí se pro <select>. */
    public function pairs(string $sql, array $params = []): array
    {
        return $this->run($sql, $params)->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /** @param array<string, scalar|null> $data */
    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO {%s} (%s) VALUES (%s)',
            $table,
            implode(', ', array_map(self::quoteName(...), $columns)),
            implode(', ', array_fill(0, count($columns), '?')),
        );
        $this->run($sql, array_values($data));

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * @param array<string, scalar|null> $data
     * @param array<string, scalar|null> $where podmínky spojené přes AND
     */
    public function update(string $table, array $data, array $where): int
    {
        $set = implode(', ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($data)));
        $cond = implode(' AND ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($where)));
        $sql = sprintf('UPDATE {%s} SET %s WHERE %s', $table, $set, $cond);

        return $this->run($sql, [...array_values($data), ...array_values($where)])->rowCount();
    }

    /** @param array<string, scalar|null> $where */
    public function delete(string $table, array $where): int
    {
        if ($where === []) {
            throw new \LogicException('Mazání bez podmínky není povoleno.');
        }
        $cond = implode(' AND ', array_map(fn (string $c): string => self::quoteName($c) . ' = ?', array_keys($where)));

        return $this->run(sprintf('DELETE FROM {%s} WHERE %s', $table, $cond), array_values($where))->rowCount();
    }

    /**
     * @template T
     * @param callable(self): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
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

    private static function quoteName(string $name): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/i', $name)) {
            throw new \InvalidArgumentException("Neplatný název sloupce: {$name}");
        }

        return '`' . $name . '`';
    }
}
