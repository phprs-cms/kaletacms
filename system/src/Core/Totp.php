<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Jednorázové kódy pro dvoufázové přihlášení (TOTP, RFC 6238) - kompatibilní s Google Authenticatorem,
 * Microsoft Authenticatorem, 1Password, Aegis a dalšími. Bez knihoven.
 */
final class Totp
{
    private const string ABECEDA = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function noveTajemstvi(): string
    {
        $tajemstvi = '';
        foreach (str_split(random_bytes(32)) as $bajt) { // 32 znaků = 160 bitů, jak doporučuje RFC 4226
            $tajemstvi .= self::ABECEDA[ord($bajt) % 32];
        }

        return $tajemstvi;
    }

    /** Ověří šestimístný kód; toleruje posun hodin o jeden 30vteřinový krok. */
    public static function over(string $tajemstvi, string $kod, ?int $cas = null): bool
    {
        $kod = preg_replace('/\s+/', '', $kod) ?? '';
        if (!preg_match('/^\d{6}$/', $kod)) {
            return false;
        }
        $krok = intdiv($cas ?? time(), 30);
        foreach ([0, -1, 1] as $posun) {
            if (hash_equals(self::kod($tajemstvi, $krok + $posun), $kod)) {
                return true;
            }
        }

        return false;
    }

    public static function kod(string $tajemstvi, int $krok): string
    {
        $hmac = hash_hmac('sha1', pack('J', $krok), self::base32($tajemstvi), true);
        $od = ord($hmac[19]) & 0x0F;
        $cislo = ((ord($hmac[$od]) & 0x7F) << 24) | (ord($hmac[$od + 1]) << 16) | (ord($hmac[$od + 2]) << 8) | ord($hmac[$od + 3]);

        return str_pad((string) ($cislo % 1_000_000), 6, '0', STR_PAD_LEFT);
    }

    /** Adresa pro aplikaci (většina ji umí otevřít přímo z odkazu v mobilu). */
    public static function uri(string $tajemstvi, string $ucet, string $web): string
    {
        return 'otpauth://totp/' . rawurlencode($web . ':' . $ucet) . '?secret=' . $tajemstvi . '&issuer=' . rawurlencode($web) . '&digits=6&period=30';
    }

    /**
     * Osm jednorázových záložních kódů pro případ ztráty telefonu.
     *
     * @return array{0: list<string>, 1: string} čitelné kódy a JSON otisků k uložení
     */
    public static function zalozniKody(): array
    {
        $kody = [];
        for ($i = 0; $i < 8; $i++) {
            $kody[] = substr(bin2hex(random_bytes(5)), 0, 5) . '-' . substr(bin2hex(random_bytes(5)), 0, 5);
        }

        return [$kody, (string) json_encode(array_map(fn (string $k): string => hash('sha256', $k), $kody))];
    }

    /** Spotřebuje záložní kód; vrací nový JSON otisků, nebo null když kód neplatí. */
    public static function pouzijZalozni(?string $json, string $kod): ?string
    {
        $otisky = json_decode((string) $json, true);
        $hledany = hash('sha256', strtolower(trim($kod)));
        if (!is_array($otisky) || !in_array($hledany, $otisky, true)) {
            return null;
        }

        return (string) json_encode(array_values(array_diff($otisky, [$hledany])));
    }

    private static function base32(string $text): string
    {
        $bity = '';
        foreach (str_split(strtoupper($text)) as $znak) {
            $pozice = strpos(self::ABECEDA, $znak);
            if ($pozice !== false) {
                $bity .= str_pad(decbin($pozice), 5, '0', STR_PAD_LEFT);
            }
        }
        $bajty = '';
        foreach (str_split($bity, 8) as $osmice) {
            if (strlen($osmice) === 8) {
                $bajty .= chr((int) bindec($osmice));
            }
        }

        return $bajty;
    }
}
