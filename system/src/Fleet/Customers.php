<?php

declare(strict_types=1);

namespace Kaleta\Fleet;

use Kaleta\Core\Db;

/**
 * Customers on the fleet console (3.17): a site belongs to at most one customer (ka_fleet_sites.customer_id), a name kept
 * once in ka_fleet_customers – renaming it renames it for every site. The list of sites, the inbox, the staged updates and
 * the shared kit can be narrowed to one customer; the weekly digest and the monthly fleet report group the sites by it
 * (Fleet\Digest, Fleet\Report – by the id, so a renamed customer keeps its archived reports). Everything here stays on
 * the console: a site never learns its customer or the owner's note about it.
 */
final class Customers
{
    public const int NAME_LENGTH = 150;
    public const int NOTE_LENGTH = 1000;

    /** @var array<int, array<int, string>> the names read in this request, by the database connection (nameOf) */
    private static array $cache = [];

    /** A customer name as stored: one line, trimmed, at most NAME_LENGTH characters ('' = no customer). */
    public static function clean(string $name): string
    {
        return mb_substr(trim(preg_replace('/[\s\x{00A0}]+/u', ' ', strip_tags($name)) ?? ''), 0, self::NAME_LENGTH);
    }

    /** The owner's note about a site: plain text, at most NOTE_LENGTH characters. */
    public static function cleanNote(string $note): string
    {
        return mb_substr(trim(str_replace("\r", '', strip_tags($note))), 0, self::NOTE_LENGTH);
    }

    /** @return array<int, string> id => name, by name – the order of the inbox's customer filter, the digest and the reports */
    public static function names(Db $db): array
    {
        return self::$cache[spl_object_id($db)] = array_map('strval', $db->pairs('SELECT id, name FROM {fleet_customers} ORDER BY ' . $db->sortText('name')));
    }

    /** The name of a customer by its id ('' for 0 or one that no longer exists), read once per request. */
    public static function nameOf(Db $db, int $id): string
    {
        if ($id <= 0) {
            return '';
        }

        return (self::$cache[spl_object_id($db)] ?? self::names($db))[$id] ?? '';
    }

    /** @return list<array{id: int, name: string, sites: int}> by name, with how many sites each has */
    public static function all(Db $db): array
    {
        return array_map(fn (array $r): array => ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'sites' => (int) $r['sites']],
            $db->all('SELECT c.id, c.name, (SELECT COUNT(*) FROM {fleet_sites} s WHERE s.customer_id = c.id) AS sites FROM {fleet_customers} c ORDER BY ' . $db->sortText('c.name')));
    }

    /** The customer of this name, made when it is new; null for an empty name. */
    public static function idFor(Db $db, string $name): ?int
    {
        $name = self::clean($name);
        if ($name === '') {
            return null;
        }
        $id = $db->value('SELECT id FROM {fleet_customers} WHERE name = ?', [$name]);

        if ($id !== null) {
            return (int) $id;
        }
        self::$cache = [];

        return $db->insert('fleet_customers', ['name' => $name]);
    }

    /** The customer of a name given on a form or in a filter: its id when it exists (never made), null otherwise. */
    public static function find(Db $db, string $name): ?int
    {
        $name = self::clean($name);
        $id = $name !== '' ? $db->value('SELECT id FROM {fleet_customers} WHERE name = ?', [$name]) : null;

        return $id !== null ? (int) $id : null;
    }

    /**
     * Gives sites a customer by name ('' = none) and forgets customers left without a site and without a kit meant for
     * them. Returns how many sites changed.
     *
     * @param list<int> $siteIds
     */
    public static function assign(Db $db, array $siteIds, string $name): int
    {
        $siteIds = array_values(array_unique(array_filter(array_map('intval', $siteIds), fn (int $id): bool => $id > 0)));
        if ($siteIds === []) {
            return 0;
        }
        $id = self::idFor($db, $name);
        $changed = 0;
        foreach ($siteIds as $siteId) {
            $changed += $db->update('fleet_sites', ['customer_id' => $id], ['id' => $siteId]) > 0 ? 1 : 0;
        }
        self::forgetUnused($db);

        return $changed;
    }

    /**
     * Renames a customer for all its sites. Renaming to a name another customer has merges the two: the sites move to
     * that one (so do kits meant for this one). Returns false when the customer does not exist or the name is empty.
     */
    public static function rename(Db $db, int $id, string $name): bool
    {
        $name = self::clean($name);
        if ($name === '' || $db->value('SELECT 1 FROM {fleet_customers} WHERE id = ?', [$id]) === null) {
            return false;
        }
        self::$cache = [];
        $other = $db->value('SELECT id FROM {fleet_customers} WHERE name = ? AND id <> ?', [$name, $id]);
        if ($other !== null) {
            $db->run('UPDATE {fleet_sites} SET customer_id = ? WHERE customer_id = ?', [(int) $other, $id]);
            $db->run('UPDATE {fleet_kits} SET customer_id = ? WHERE customer_id = ?', [(int) $other, $id]);
            // the archived fleet reports follow; a month both already have keeps both reports (each under its own id)
            $db->run('UPDATE IGNORE {fleet_reports} SET customer_id = ? WHERE customer_id = ?', [(int) $other, $id]);
            $db->delete('fleet_customers', ['id' => $id]);

            return true;
        }
        $db->update('fleet_customers', ['name' => $name], ['id' => $id]);

        return true;
    }

    /** Sets the owner's note about a site. */
    public static function setNote(Db $db, int $siteId, string $note): void
    {
        $db->update('fleet_sites', ['note' => self::cleanNote($note)], ['id' => $siteId]);
    }

    /**
     * Customers without a site and without a kit meant for them are forgotten. Their archived fleet reports stay, under the
     * old id with the name they had (Fleet\Report).
     */
    private static function forgetUnused(Db $db): void
    {
        self::$cache = [];
        $db->run('DELETE FROM {fleet_customers} WHERE id NOT IN (SELECT customer_id FROM {fleet_sites} WHERE customer_id IS NOT NULL)'
            . ' AND id NOT IN (SELECT customer_id FROM {fleet_kits} WHERE customer_id IS NOT NULL)');
    }
}
