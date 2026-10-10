<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * English names of the data model (3.10, docs/design/4.0-data-model.md §4), read from the rename map system/sql/names.php.
 *
 * In 3.x nothing is renamed: physical() lets SQL name a table either way ({news} and {novinky} are both `ka_novinky`),
 * english() gives the name 4.0 will use. New code may write the English placeholders; old code and add-ons keep working.
 */
final class Names
{
    /** @var array<string, array{0: string, 1: array<string, string>}>|null */
    private static ?array $map = null;

    /** @var array<string, string>|null English name => physical name, only where they differ */
    private static ?array $aliases = null;

    /** @return array<string, array{0: string, 1: array<string, string>}> table (without the prefix) => [English name, [column => English column]] */
    public static function map(): array
    {
        // without the file (an update half copied) every name is its own: today's placeholders work as before
        $file = dirname(__DIR__, 2) . '/sql/names.php';

        return self::$map ??= is_file($file) ? require $file : [];
    }

    /** The table of today for a placeholder name: an English name of a renamed table ({news} → novinky), anything else as it is. */
    public static function physical(string $name): string
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
        return self::map()[self::physical($table)][1] ?? [];
    }
}
