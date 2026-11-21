<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * 3.50.1: how a redirect row is written – the one path of a changed slug, the importers, the daily matcher, the CSV import,
 * the admin form and MCP. It lived in Admin\Modules\Redirects (twin, add, takeOver), which forwards here under the same
 * names and signatures; Core calls it here, so Core never names the admin module for it (docs/design/code-layers.md).
 */
final class RedirectStore
{
    /**
     * The old address of a redirect is unique under the Czech collation of z_adresy (uq_presmerovani: letter case and
     * accents folded – "cafe" = "café"), while since 3.19 the site looks redirects up by the exact normal form (source_norm:
     * case still folded, accents kept). So "cafe" and "café" are two addresses for visitors but cannot be two rows until 4.0
     * moves the key onto source_norm. twin() finds the row that blocks $from: an existing row equal under the collation whose
     * normal form differs (accents, or another case on an edit, $sameForm). null = $from can be saved (a row with the same
     * normal form – "Cafe" for "cafe" – is the same address and is simply updated). N319-2.
     *
     * @return array{idp: int, z_adresy: string, na_adresu: string}|null
     */
    public static function twin(Db $db, string $from, int $exceptIdp = 0, bool $sameForm = false): ?array
    {
        $from = mb_substr(trim($from, '/ '), 0, 255);
        $normal = RedirectSources::normalize($from);
        foreach ($db->all('SELECT idp, z_adresy, na_adresu FROM {presmerovani} WHERE z_adresy = ? AND idp <> ?', [$from, $exceptIdp]) as $r) {
            if ($sameForm || RedirectSources::normalize((string) $r['z_adresy']) !== $normal) {
                return ['idp' => (int) $r['idp'], 'z_adresy' => (string) $r['z_adresy'], 'na_adresu' => (string) $r['na_adresu']];
            }
        }

        return null;
    }

    /**
     * $heal = false: a bulk import (Core\RedirectRules::save) leaves the site's links alone, a scan per row would take minutes.
     *
     * N319-2: the automatic writers (a changed slug, the WordPress, web and batch imports) come here without a person to ask.
     * When twin() finds a row the new address wins – it is the one the site just stopped answering at – and that row takes
     * it over (old address, normal form, target, 301, not automatic), recorded as the event redirect.replaced with the old
     * address and target, so nothing changes silently: before, the row kept its address and got the new target, and the new
     * address never redirected. The admin form and MCP refuse before they get here (Admin\Modules\Redirects::TWIN), the CSV import and MCP
     * save_redirects refuse the row (RedirectRules::plan), the daily job skips the address (RedirectMatcher::run).
     */
    public static function add(Db $db, string $z, string $commandName, bool $heal = true): void
    {
        $z = trim($z, '/ ');
        if ($z === '' || $z === trim($commandName, '/ ')) {
            return;
        }
        // the new target URL also takes over older redirects, so that no chains form
        $db->run('UPDATE {presmerovani} SET na_adresu = ? WHERE na_adresu = ?', [$commandName, $z]);
        $db->run('DELETE FROM {presmerovani} WHERE z_adresy = ?', [trim($commandName, '/ ')]);
        $from = mb_substr($z, 0, 255);
        $target = mb_substr($commandName, 0, 255);
        if (($twin = self::twin($db, $from)) !== null) {
            self::takeOver($db, $twin, $from, $target, 301);
        } else {
            // 3.19: with its exact normal form (source_norm), which the site looks redirects up by
            $db->run(
                'INSERT INTO {presmerovani} (z_adresy, source_norm, na_adresu, vytvoreno) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE na_adresu = VALUES(na_adresu)', // the same address in another letter case: the row keeps its z_adresy and normal form
                [$from, RedirectSources::normalize($from), $target, Clock::now()],
            );
        }
        // links on the site that still lead to the old address are rewritten, so visitors never meet the redirect (2.14)
        if ($heal) {
            LinkHealing::heal($db, $z, $commandName);
        }
    }

    /**
     * N319-2: the row twin() found takes over the new old address – its normal form, the target and the code, counted and
     * dated anew, no longer automatic – and the event redirect.replaced keeps what it was (see add()).
     *
     * @param array{idp: int, z_adresy: string, na_adresu: string} $twin
     */
    public static function takeOver(Db $db, array $twin, string $from, string $to, int $code): void
    {
        $db->update('presmerovani', ['z_adresy' => $from, 'source_norm' => RedirectSources::normalize($from), 'na_adresu' => $to,
            'typ' => $code, 'auto_score' => null, 'pocet' => 0, 'vytvoreno' => Clock::now()], ['idp' => $twin['idp']]);
        $shown = static fn (string $address): string => preg_match('#^https?://#i', $address) ? $address : '/' . ltrim($address, '/');
        Events::record($db, 'redirect.replaced', 'warning',
            t('%s now redirects to %s and took over the redirect of %s (to %s) – the two addresses differ only in accents and cannot both have a redirect until Kaleta 4.0.', $shown($from), $shown($to), $shown($twin['z_adresy']), $shown($twin['na_adresu'])),
            ['from' => $shown($from), 'to' => $shown($to), 'code' => $code, 'replaced' => $shown($twin['z_adresy']), 'replaced_to' => $shown($twin['na_adresu'])]);
    }
}
