<?php

declare(strict_types=1);

namespace Kaleta\Stavitel;

use Kaleta\Core\Db;

/**
 * Komponenty – znovupoužitelné bloky stavitele (tabulka ka_komponenty). Uvnitř komponenty jsou {{vlastnosti}} – stejné
 * značky jako u kolekcí (Kolekce::dosad) – a každé použití na stránce (prvek „komponenta“) jim dá vlastní hodnoty.
 */
final class Komponenty
{
    /** Typy vlastností (podmnožina polí kolekcí). */
    public const array TYPY = ['text' => 'krátký text', 'radky' => 'delší text', 'html' => 'formátovaný text', 'obrazek' => 'obrázek', 'odkaz' => 'odkaz'];

    /** Nejvyšší zanoření komponent do sebe (komponenta v komponentě…). */
    public const int MAX_ZANORENI = 4;

    /** @return list<array<string, mixed>> */
    public static function vsechny(Db $db): array
    {
        return array_map(self::rozbal(...), $db->all('SELECT * FROM {komponenty} ORDER BY nazev'));
    }

    /** @return array<string, mixed>|null */
    public static function podleId(Db $db, int $idm): ?array
    {
        $r = $db->one('SELECT * FROM {komponenty} WHERE idm = ?', [$idm]);

        return $r === null ? null : self::rozbal($r);
    }

    private static function rozbal(array $r): array
    {
        $r['vlastnosti'] = json_decode((string) $r['vlastnosti'], true) ?: [];

        return $r;
    }

    /**
     * Definice vlastností z formuláře nebo od AI: klíč, popisek, typ a výchozí hodnota (zkontrolovaná podle typu).
     *
     * @return list<array{klic: string, popisek: string, typ: string, vychozi: string}>
     */
    public static function vycistiVlastnosti(mixed $vstup): array
    {
        // jen řádky s popiskem; výchozí hodnota jde ruku v ruce s polem, pořadí se nesmí rozejít
        $radky = array_values(array_filter(is_array($vstup) ? $vstup : [], fn (mixed $v): bool => is_array($v) && trim(strip_tags((string) ($v['popisek'] ?? ''))) !== ''));
        $radky = array_slice($radky, 0, 30);
        $pole = Kolekce::vycistiPole(array_map(fn (array $v): array => ['typ' => isset(self::TYPY[$v['typ'] ?? '']) ? $v['typ'] : 'text'] + $v, $radky));
        $vychozi = Kolekce::vycistiData($pole, array_combine(array_column($pole, 'klic'), array_map(fn (array $v): string => is_scalar($v['vychozi'] ?? null) ? (string) $v['vychozi'] : '', $radky)));

        return array_map(fn (array $p): array => $p + ['vychozi' => $vychozi[$p['klic']] ?? ''], $pole);
    }

    /**
     * Hodnoty pro {{značky}}: zadané u použití, jinak výchozí. Zkontrolují se podle typu vlastnosti (obrázek, odkaz, HTML).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hodnoty(array $komponenta, array $zadane): array
    {
        $cista = Kolekce::vycistiData($komponenta['vlastnosti'], $zadane);
        $h = [];
        foreach ($komponenta['vlastnosti'] as $v) {
            $h[$v['klic']] = [($cista[$v['klic']] ?? '') !== '' ? $cista[$v['klic']] : (string) $v['vychozi'], $v['typ']];
        }

        return $h;
    }
}
