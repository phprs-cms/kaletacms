<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Podepsaný odkaz na náhled konceptu (stránka nebo část webu) bez přihlášení – pro Clauda přes MCP a pro sdílení s kolegou.
 * Klíč „platnost.podpis“ platí jen pro jeden cíl a do vypršení; podpis je HMAC tajným klíčem instalace (Antispam::klic).
 * Náhled nemá noindex jen v meta: stránka s parametrem se neukládá do mezipaměti a vyhledávače ji nedostanou do indexu.
 */
final class Nahled
{
    public const int MAX_MINUT = 7 * 24 * 60;

    /** Klíč náhledu pro cíl „stranka:12“ nebo „cast:hlavicka:en“ platný zadaný počet minut. */
    public static function klic(Db $db, Settings $settings, string $cil, int $minut): string
    {
        $do = time() + 60 * max(5, min(self::MAX_MINUT, $minut));

        return $do . '.' . self::podpis($db, $settings, $cil, $do);
    }

    public static function over(Db $db, Settings $settings, string $cil, string $klic): bool
    {
        if (!preg_match('/^(\d{10})\.([a-f0-9]{64})$/', $klic, $m) || (int) $m[1] < time()) {
            return false;
        }

        return hash_equals(self::podpis($db, $settings, $cil, (int) $m[1]), $m[2]);
    }

    private static function podpis(Db $db, Settings $settings, string $cil, int $do): string
    {
        return hash_hmac('sha256', 'nahled|' . $cil . '|' . $do, (new Antispam($db, $settings))->klic());
    }
}
