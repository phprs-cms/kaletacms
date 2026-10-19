<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * English names of the data model (3.10, docs/design/4.0-data-model.md §4), read from the rename map system/sql/names.php.
 *
 * In 3.x nothing is renamed: physical() lets SQL name a table either way ({news} and {novinky} are both `ka_novinky`),
 * english() gives the name 4.0 will use. New code may write the English placeholders; old code and add-ons keep working.
 *
 * 3.15 (the bridge to 4.0): the database may already carry the English names – 4.0 renames the tables, and a site rolled
 * back to 3.15 keeps them. Core\Db finds out which names the database has (setRenamed()) and physical() then gives the
 * English one: {novinky} and {news} are both `ka_news` there. table() stays the name 3.x code knows a table by.
 */
final class Names
{
    /** @var array<string, array{0: string, 1: array<string, string>}>|null */
    private static ?array $map = null;

    /** @var array<string, string>|null English name => today's name, only where they differ */
    private static ?array $aliases = null;

    /** @var array<string, string>|null legacy (Czech) name => its English name, only tables that 4.0 renames (3.14) */
    private static ?array $legacy = null;

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

    /**
     * Legacy names (3.14): the Czech name of every table 4.0 renames (novinky → news), the names add-ons should stop writing.
     * Tables named in English since 1.5 are not in it.
     *
     * @return array<string, string> legacy name => English name
     */
    public static function legacy(): array
    {
        if (self::$legacy === null) {
            self::$legacy = [];
            foreach (self::map() as $table => [$english]) {
                if ($english !== $table) {
                    self::$legacy[$table] = $english;
                }
            }
        }

        return self::$legacy;
    }

    /** Whether a placeholder is the legacy name of a table that 4.0 renames ({novinky} yes, {news} and {events} no). */
    public static function isLegacy(string $name): bool
    {
        return isset((self::$legacy ?? self::legacy())[$name]);
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
