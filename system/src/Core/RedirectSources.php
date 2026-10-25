<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Normalised redirect sources (3.14, docs/design/4.0-data-model.md §1.3 – expand). In 4.0 a redirect is looked up by an
 * exact comparison (utf8mb4_bin) of a normalised old address instead of the Czech text collation of z_adresy, which folds
 * case and some accents. Migration 0094 adds the column source_norm (utf8mb4_bin, NULL = not normalised yet); the
 * background job "redirect_sources" fills it in batches, and later rows too (nothing that writes a redirect has to know
 * about it). Lookups kept using z_adresy in 3.14–3.18; since 3.19 every lookup of a redirect for a visited address goes by
 * source_norm (Front\Kernel, Audit, Links, MigrationReport), every writer fills it, and migration 0106 normalised the rows
 * the job had not reached. The key stays non-unique: groups that collide are listed on System status (Core\Health).
 *
 * The normal form is what the lookup in Front\Kernel effectively compares today: the address as Redirects stores it
 * (RedirectRules::normalizeFrom – no slashes around it, percent-decoded, "?p=123" for an old WordPress address) in lower
 * case, because the collation ignores case. A language prefix stays part of the address: since 3.9 a version's redirects
 * carry it (en/old), and the lookup tries that form first. Accents are kept – an exact comparison tells "cafe" from
 * "café", which today's collation does not; such pairs are reported (collisions()) so the owner can decide before 4.0.
 */
final class RedirectSources
{
    /** Rows per batch: one SELECT and one transaction of UPDATEs. */
    public const int BATCH = 500;

    /** At most this many groups are listed on System status and in get_health. */
    public const int MAX_LISTED = 50;

    /** The normal form of an old address (see the class). */
    public static function normalize(string $source): string
    {
        return mb_substr(mb_strtolower(RedirectRules::normalizeFrom($source), 'UTF-8'), 0, 255);
    }

    /**
     * Fills source_norm for rows without it, batch by batch, within $seconds. Safe to stop at any moment and to run again:
     * a row is only written while its old address is still the one read (an edit in between leaves it for the next run).
     *
     * @return array{filled: int, left: bool} left = rows remain for the next run
     */
    public static function fill(Db $db, float $seconds): array
    {
        $end = microtime(true) + $seconds;
        $filled = 0;
        do {
            $rows = $db->all('SELECT idp, z_adresy FROM {presmerovani} WHERE source_norm IS NULL ORDER BY idp LIMIT ' . self::BATCH);
            if ($rows === []) {
                return ['filled' => $filled, 'left' => false];
            }
            $db->pdo()->beginTransaction();
            try {
                foreach ($rows as $r) {
                    $filled += $db->run('UPDATE {presmerovani} SET source_norm = ? WHERE idp = ? AND z_adresy = ?',
                        [self::normalize((string) $r['z_adresy']), (int) $r['idp'], (string) $r['z_adresy']])->rowCount();
                }
                $db->pdo()->commit();
            } catch (\Throwable $e) {
                $db->pdo()->rollBack();
                throw $e;
            }
        } while (count($rows) === self::BATCH && microtime(true) < $end);

        return ['filled' => $filled, 'left' => count($rows) === self::BATCH];
    }

    /** Rows the job has not normalised yet. */
    public static function pending(Db $db): int
    {
        return (int) $db->value('SELECT COUNT(*) FROM {presmerovani} WHERE source_norm IS NULL');
    }

    /**
     * Redirects whose old addresses would collide or change meaning once compared exactly: normal forms equal under
     * utf8mb4_czech_ci (the same address in other letter case, percent-encoded or with slashes around it, or one that differs
     * only in accents the collation folds – cafe and café). Pattern rules are left out (they never match exactly). At most
     * MAX_LISTED groups, each with its old addresses as stored.
     *
     * @return list<list<string>>
     */
    public static function collisions(Db $db, int $limit = self::MAX_LISTED): array
    {
        $rows = $db->all("SELECT GROUP_CONCAT(z_adresy ORDER BY idp SEPARATOR '\\n') AS sources FROM {presmerovani}
            WHERE source_norm IS NOT NULL AND z_adresy NOT LIKE '%*%'
            GROUP BY source_norm COLLATE utf8mb4_czech_ci HAVING COUNT(*) > 1 ORDER BY MIN(idp) LIMIT " . max(1, $limit));

        return array_map(fn (array $r): array => explode("\n", (string) $r['sources']), $rows);
    }

    /** How many such groups there are in all (collisions() lists at most MAX_LISTED). */
    public static function collisionCount(Db $db): int
    {
        return (int) $db->value("SELECT COUNT(*) FROM (SELECT 1 FROM {presmerovani} WHERE source_norm IS NOT NULL AND z_adresy NOT LIKE '%*%'
            GROUP BY source_norm COLLATE utf8mb4_czech_ci HAVING COUNT(*) > 1) AS collided"); // not "groups": a reserved word in MySQL 8
    }
}
