<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel;

use MiroCMS\Core\App;
use MiroCMS\Core\Db;

/**
 * Publikování konceptu stavby (stránka nebo část webu) – z editoru i z MCP. Předchozí publikovaná verze jde do historie
 * (mc_stavba_revize, 20 posledních pro každý cíl), cache webu se vymaže.
 */
final class Publikace
{
    public const int VERZI = 20;

    /** Stránka: do textu se uloží obsah bez rozložení – z něj čerpá hledání, llms.txt, API i návrat k textu. */
    public static function stranka(App $app, array $stranka): void
    {
        $novy = $stranka['stavba_koncept'] ?? $stranka['stavba'];
        self::verze($app, ['ids' => $stranka['ids']], $stranka['stavba'], $novy, $stranka['zmeneno'] ?? null);
        $text = Stavba::jakoText(Stavba::zJson($novy) ?? []);
        $app->db()->update('stranky', ['stavba' => $novy, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')] + ($text !== '' ? ['text' => $text] : []), ['ids' => $stranka['ids']]);
        \MiroCMS\Front\Cache::vymaz();
    }

    public static function cast(App $app, array $radek): void
    {
        $novy = $radek['stavba_koncept'] ?? $radek['stavba'];
        self::verze($app, ['cast' => Casti::klicRevize($radek['typ'], $radek['jazyk'])], $radek['stavba'], $novy, $radek['zmeneno'] ?? null);
        $app->db()->update('casti', ['stavba' => $novy, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['typ' => $radek['typ'], 'jazyk' => $radek['jazyk']]);
        \MiroCMS\Front\Cache::vymaz();
    }

    /** Uloží předchozí publikovanou verzi do historie. @param array{ids?: int|string, cast?: string} $cil */
    public static function verze(App $app, array $cil, ?string $stara, ?string $nova, ?string $datum): void
    {
        if ($stara === null || $stara === $nova) {
            return;
        }
        $db = $app->db();
        $db->insert('stavba_revize', $cil + ['datum' => $datum ?? date('Y-m-d H:i:s'), 'kdo' => $app->auth()->id() ?: null, 'stavba' => $stara]);
        [$kde, $hodnota] = self::kde($cil);
        $hranice = $db->value('SELECT idr FROM {stavba_revize} WHERE ' . $kde . ' ORDER BY idr DESC LIMIT 1 OFFSET ' . self::VERZI, [$hodnota]);
        if ($hranice !== null) {
            $db->run('DELETE FROM {stavba_revize} WHERE ' . $kde . ' AND idr <= ?', [$hodnota, $hranice]);
        }
    }

    /** @param array{ids?: int|string, cast?: string} $cil @return list<array<string, mixed>> */
    public static function seznam(Db $db, array $cil): array
    {
        [$kde, $hodnota] = self::kde($cil);

        return $db->all("SELECT r.idr, r.datum, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo FROM {stavba_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r." . $kde . ' ORDER BY r.idr DESC', [$hodnota]);
    }

    /** @param array{ids?: int|string, cast?: string} $cil */
    public static function nacti(Db $db, array $cil, int $idr): ?string
    {
        [$kde, $hodnota] = self::kde($cil);
        $stavba = $db->value('SELECT stavba FROM {stavba_revize} WHERE idr = ? AND ' . $kde, [$idr, $hodnota]);

        return $stavba === null ? null : (string) $stavba;
    }

    /** @return array{0: string, 1: int|string} */
    private static function kde(array $cil): array
    {
        return isset($cil['ids']) ? ['ids = ?', (int) $cil['ids']] : ['cast = ?', (string) $cil['cast']];
    }
}
