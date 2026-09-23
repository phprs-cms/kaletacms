<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Ověřování podpisů vydavatele (Ed25519). Soubor system/aktualizace.pub smí nést VÍC veřejných klíčů - na každém řádku jeden
 * (base64, za mezerou volitelný popis, řádky s # jsou poznámky). Podpis platí, když sedí na kterýkoli z nich.
 *
 * Proč víc klíčů: vedle provozního klíče existuje záložní, který leží offline a nepoužívá se. Při ztrátě provozního klíče
 * se jím podepíše vydání s novým provozním klíčem; při úniku vydání, které kompromitovaný klíč ze souboru odstraní.
 * Postup je v docs/VYDAVANI.md.
 */
final class Podpis
{
    /** @return array<string, string> identifikátor klíče => veřejný klíč (binárně) */
    public static function klice(string $soubor): array
    {
        $klice = [];
        foreach (is_file($soubor) ? (file($soubor, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $radek) {
            $radek = trim($radek);
            if ($radek === '' || $radek[0] === '#') {
                continue;
            }
            $klic = base64_decode((string) strtok($radek, " \t"), true);
            if ($klic !== false && strlen($klic) === SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
                $klice[self::id($klic)] = $klic;
            }
        }

        return $klice;
    }

    /** Krátký identifikátor klíče (prvních 8 znaků otisku) - do manifestu a do dokumentace, ať je jasné, čím se podepisovalo. */
    public static function id(string $verejnyKlic): string
    {
        return substr(hash('sha256', $verejnyKlic), 0, 8);
    }

    /** Platí podpis (base64) zprávy vůči některému z klíčů v souboru? */
    public static function plati(string $zprava, string $podpisBase64, string $soubor): bool
    {
        $podpis = base64_decode($podpisBase64, true);
        if ($podpis === false || strlen($podpis) !== SODIUM_CRYPTO_SIGN_BYTES || !function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }
        foreach (self::klice($soubor) as $klic) {
            if (sodium_crypto_sign_verify_detached($podpis, $zprava, $klic)) {
                return true;
            }
        }

        return false;
    }

    /** Co přesně se u balíčku podepisuje: verze, otisk ZIPu i příznak bezpečnostního vydání (to se instaluje samo). */
    public static function zpravaBalicku(string $verze, string $sha256, bool $bezpecnostni): string
    {
        return $verze . '|' . strtolower($sha256) . '|' . ($bezpecnostni ? 'bezpecnostni' : 'bezne');
    }
}
