<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * English names of the data model (3.10), read from the map system/sql/names.php.
 *
 * Nothing is renamed: the tables and columns keep their Czech names (owner decisions of 21 and 22 October 2026, docs/ROADMAP.md
 * "Path to 4.0"). physical() lets SQL name a table either way ({news} and {novinky} are both `ka_novinky`), english() gives
 * the English alias. New code may write the English placeholders; old code and add-ons keep working.
 *
 * 3.15 left a bridge for databases whose tables carry the English names: Core\Db finds out which names the database has
 * (setRenamed()) and physical() then gives the English one. No release renames a database, so the bridge only waits for a
 * major release to remove it (docs/design/4.0-data-model.md §4). table() stays the name the code knows a table by.
 */
final class Names
{
    /** @var array<string, array{0: string, 1: array<string, string>}>|null */
    private static ?array $map = null;

    /** @var array<string, string>|null English name => today's name, only where they differ */
    private static ?array $aliases = null;

    /** 3.15: the tables of this database carry their English names (renamed by 4.0) – set by Core\Db when it connects or finds out. */
    private static bool $renamed = false;

    /** @return array<string, array{0: string, 1: array<string, string>}> table (without the prefix) => [English name, [column => English column]] */
    public static function map(): array
    {
        // without the file (an update half copied) every name is its own: today's placeholders work as before
        $file = dirname(__DIR__, 2) . '/sql/names.php';

        return self::$map ??= is_file($file) ? require $file : [];
    }

    /**
     * The name of a table in this database for a placeholder name (3.15): today's name ({news} → novinky), or – in a database
     * whose tables 4.0 already renamed – the English one ({novinky} → news). $renamed null = what the connected Db found.
     */
    public static function physical(string $name, ?bool $renamed = null): string
    {
        $table = self::table($name);

        return ($renamed ?? self::$renamed) ? self::english($table) : $table;
    }

    /** 3.15: whether the tables of the connected database carry their English names (Core\Db sets it, tests may too). */
    public static function setRenamed(bool $renamed): void
    {
        self::$renamed = $renamed;
    }

    public static function renamed(): bool
    {
        return self::$renamed;
    }

    /**
     * The name 3.x code knows a table by, whatever the database calls it: an English name of a renamed table ({news} →
     * novinky), anything else as it is. The agent journal, the export and the backup compare tables by it.
     */
    public static function table(string $name): string
    {
        if (self::$aliases === null) {
            self::$aliases = [];
            foreach (self::map() as $table => [$english]) {
                if ($english !== $table) {
                    self::$aliases[$english] = $table;
                }
            }
        }

        return self::$aliases[$name] ?? $name;
    }

    /** The English name of a table (novinky → news); a table the map does not know (an add-on's own) as it is. */
    public static function english(string $table): string
    {
        return self::map()[$table][0] ?? $table;
    }

    /**
     * English names of a table's columns that differ from today's (the most-read ones; the rest keep their name).
     *
     * @return array<string, string>
     */
    public static function columns(string $table): array
    {
        return self::map()[self::table($table)][1] ?? [];
    }
}
