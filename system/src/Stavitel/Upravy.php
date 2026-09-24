<?php

declare(strict_types=1);

namespace Kaleta\Stavitel;

/**
 * Dílčí úpravy stavby podle id prvků – aby jazykový model (MCP) při opravě jednoho odkazu nemusel posílat celou stránku.
 * Operace se provedou postupně nad kopií; výsledek pak projde Stavba::vycisti() jako každá jiná stavba.
 *
 *   {"op":"uprav","id":"…","obsah":{…},"styl":{"mobil":{"mezera":"s","sloupce":null}},"tridy":[…],"kotva":"…","znacka":"…"}
 *       obsah a styl se slučují (null nebo "" hodnotu odebere), tridy se nahradí
 *   {"op":"nahrad","id":"…","prvek":{…}}
 *   {"op":"smaz","id":"…"}
 *   {"op":"vloz","prvky":[…] (nebo "prvek"),"do":"id rodiče | null = kořen","pozice":0 | "za":"id" | "pred":"id"}
 *   {"op":"presun","id":"…","do":…,"pozice":… | "za":… | "pred":…}
 */
final class Upravy
{
    public const int MAX_OPERACI = 100;

    /**
     * @param array<string, mixed> $stavba
     * @param list<array<string, mixed>> $operace
     * @param array<string, string> $chyby op[i] => text chyby (operace s chybou se přeskočí, ostatní proběhnou)
     * @return array<string, mixed>
     */
    public static function proved(array $stavba, array $operace, array &$chyby = []): array
    {
        $koren = ['id' => null, 'deti' => array_values($stavba['deti'] ?? [])];
        foreach (array_slice(array_values($operace), 0, self::MAX_OPERACI) as $i => $o) {
            $kde = 'op[' . $i . ']';
            if (!is_array($o)) {
                $chyby[$kde] = 'Operace musí být objekt.';
                continue;
            }
            try {
                $koren = self::jedna($koren, $o);
            } catch (\InvalidArgumentException $e) {
                $chyby[$kde] = $e->getMessage();
            }
        }
        if (count($operace) > self::MAX_OPERACI) {
            $chyby['op'] = 'Najednou jde provést nejvýš ' . self::MAX_OPERACI . ' operací – zbytek vynechán.';
        }

        return ['v' => $stavba['v'] ?? Stavba::VERZE, 'deti' => $koren['deti']];
    }

    /** @return array<string, mixed> */
    private static function jedna(array $koren, array $o): array
    {
        $id = is_string($o['id'] ?? null) ? $o['id'] : '';
        switch ($o['op'] ?? '') {
            case 'uprav':
                return self::zmen($koren, $id, function (array $p) use ($o): array {
                    if (is_array($o['obsah'] ?? null)) {
                        $p['obsah'] = self::sluc(is_array($p['obsah'] ?? null) ? $p['obsah'] : [], $o['obsah']);
                    }
                    if (is_array($o['styl'] ?? null)) {
                        $styl = is_array($p['styl'] ?? null) ? $p['styl'] : [];
                        foreach ($o['styl'] as $stav => $vlastnosti) {
                            $styl[$stav] = $vlastnosti === null ? [] : self::sluc(is_array($styl[$stav] ?? null) ? $styl[$stav] : [], (array) $vlastnosti);
                            if ($styl[$stav] === []) {
                                unset($styl[$stav]);
                            }
                        }
                        $p['styl'] = $styl;
                    }
                    foreach (['tridy', 'kotva', 'znacka', 'podminky', 'atributy', 'popis'] as $klic) {
                        if (array_key_exists($klic, $o)) {
                            if ($o[$klic] === null) {
                                unset($p[$klic]);
                            } else {
                                $p[$klic] = $o[$klic];
                            }
                        }
                    }

                    return $p;
                });

            case 'nahrad':
                if (!is_array($o['prvek'] ?? null)) {
                    throw new \InvalidArgumentException('Chybí "prvek".');
                }

                return self::zmen($koren, $id, fn (array $p): array => ['id' => $p['id']] + $o['prvek']);

            case 'smaz':
                [$koren, $vyjmuty] = self::vyjmi($koren, $id);
                if ($vyjmuty === null) {
                    throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není.');
                }

                return $koren;

            case 'vloz':
                $prvky = is_array($o['prvky'] ?? null) ? array_values($o['prvky']) : (is_array($o['prvek'] ?? null) ? [$o['prvek']] : []);
                if ($prvky === []) {
                    throw new \InvalidArgumentException('Chybí "prvky" (pole prvků) nebo "prvek".');
                }

                return self::vloz($koren, $prvky, $o);

            case 'presun':
                [$bez, $vyjmuty] = self::vyjmi($koren, $id);
                if ($vyjmuty === null) {
                    throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není.');
                }
                if (isset($o['do']) && self::najdi($vyjmuty, (string) $o['do'])) {
                    throw new \InvalidArgumentException('Prvek nejde přesunout do sebe sama.');
                }

                return self::vloz($bez, [$vyjmuty], $o);
        }
        throw new \InvalidArgumentException('Neznámá operace (op): uprav | nahrad | smaz | vloz | presun.');
    }

    /** Sloučí změny do pole: null nebo "" klíč odebere, ostatní přepíše. */
    private static function sluc(array $puvodni, array $zmeny): array
    {
        foreach ($zmeny as $k => $v) {
            if ($v === null || $v === '') {
                unset($puvodni[$k]);
            } else {
                $puvodni[$k] = $v;
            }
        }

        return $puvodni;
    }

    /** Změní prvek s daným id; když ve stavbě není, hlásí chybu. */
    private static function zmen(array $koren, string $id, callable $zmena): array
    {
        $nalezen = false;
        $koren = self::zmenVUzlu($koren, $id, $zmena, $nalezen);
        if (!$nalezen) {
            throw new \InvalidArgumentException('Prvek „' . $id . '“ ve stavbě není. Id najdeš ve stavba_nacti.');
        }

        return $koren;
    }

    private static function zmenVUzlu(array $uzel, string $id, callable $zmena, bool &$nalezen): array
    {
        foreach ($uzel['deti'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $id) {
                $uzel['deti'][$i] = $zmena($p);
                $nalezen = true;

                return $uzel;
            }
            $uzel['deti'][$i] = self::zmenVUzlu($p, $id, $zmena, $nalezen);
            if ($nalezen) {
                return $uzel;
            }
        }

        return $uzel;
    }

    /** @return array{0: array<string, mixed>, 1: ?array<string, mixed>} [strom bez prvku, vyjmutý prvek] */
    private static function vyjmi(array $uzel, string $id): array
    {
        foreach ($uzel['deti'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $id) {
                array_splice($uzel['deti'], $i, 1);

                return [$uzel, $p];
            }
            [$novy, $vyjmuty] = self::vyjmi($p, $id);
            if ($vyjmuty !== null) {
                $uzel['deti'][$i] = $novy;

                return [$uzel, $vyjmuty];
            }
        }

        return [$uzel, null];
    }

    private static function najdi(array $uzel, string $id): bool
    {
        if (($uzel['id'] ?? null) === $id) {
            return true;
        }
        foreach ($uzel['deti'] ?? [] as $p) {
            if (is_array($p) && self::najdi($p, $id)) {
                return true;
            }
        }

        return false;
    }

    /** Vloží prvky do rodiče „do“ (null = kořen) na pozici, za prvek „za“ nebo před prvek „pred“; bez určení na konec. */
    private static function vloz(array $koren, array $prvky, array $o): array
    {
        $soused = is_string($o['za'] ?? null) ? $o['za'] : (is_string($o['pred'] ?? null) ? $o['pred'] : null);
        if ($soused !== null) {
            $hotovo = false;
            $koren = self::vlozVedle($koren, $soused, $prvky, isset($o['za']), $hotovo);
            if (!$hotovo) {
                throw new \InvalidArgumentException('Prvek „' . $soused . '“ (za/pred) ve stavbě není.');
            }

            return $koren;
        }
        $rodic = isset($o['do']) && $o['do'] !== null && $o['do'] !== '' ? (string) $o['do'] : null;
        $pozice = isset($o['pozice']) && is_numeric($o['pozice']) ? (int) $o['pozice'] : null;
        if ($rodic === null) {
            array_splice($koren['deti'], $pozice ?? count($koren['deti']), 0, $prvky);

            return $koren;
        }

        return self::zmen($koren, $rodic, function (array $p) use ($prvky, $pozice): array {
            $p['deti'] = array_values(is_array($p['deti'] ?? null) ? $p['deti'] : []);
            array_splice($p['deti'], $pozice ?? count($p['deti']), 0, $prvky);

            return $p;
        });
    }

    private static function vlozVedle(array $uzel, string $soused, array $prvky, bool $za, bool &$hotovo): array
    {
        foreach ($uzel['deti'] ?? [] as $i => $p) {
            if (!is_array($p)) {
                continue;
            }
            if (($p['id'] ?? null) === $soused) {
                array_splice($uzel['deti'], $za ? $i + 1 : $i, 0, $prvky);
                $hotovo = true;

                return $uzel;
            }
            $uzel['deti'][$i] = self::vlozVedle($p, $soused, $prvky, $za, $hotovo);
            if ($hotovo) {
                return $uzel;
            }
        }

        return $uzel;
    }
}
