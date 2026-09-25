<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * QR kód bez cizí knihovny: bajtový režim, oprava chyb úrovně M (zvládne ~15 % poškození), verze 1–40.
 * Stačí na adresu otpauth:// pro dvoufázové přihlášení (typicky verze 5–8). Výstup je matice modulů nebo SVG.
 *
 * Postup podle normy ISO/IEC 18004: data → bloky s kódy Reed–Solomon → proložení → rozmístění do matice
 * kolem pevných vzorů → vyzkoušení osmi masek a výběr té s nejnižší penalizací → formátové (a verzní) bity.
 */
final class Qr
{
    /** Počet opravných kódových slov na blok a počet bloků pro úroveň M, index = verze (0 je výplň). */
    private const array ECC_NA_BLOK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
    private const array BLOKU = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

    /** Formátové bity úrovně M (00) – maska se doplní. */
    private const int UROVEN_M = 0;

    /** @var list<list<bool>> tmavé moduly [y][x] */
    private array $moduly = [];
    /** @var list<list<bool>> moduly pevných vzorů (maska ani data je nemění) */
    private array $pevne = [];
    private int $velikost;

    private function __construct(private readonly int $verze)
    {
        $this->velikost = $verze * 4 + 17;
        $radek = array_fill(0, $this->velikost, false);
        $this->moduly = array_fill(0, $this->velikost, $radek);
        $this->pevne = array_fill(0, $this->velikost, $radek);
    }

    /**
     * Matice QR kódu: řádky shora, v každém moduly zleva, true = tmavý. Bez okraje (tichá zóna je na volajícím).
     *
     * @param int|null $maska 0–7 vynutí masku (testy); null = vybere se podle penalizace jako ve čtečkách
     * @return list<list<bool>>
     * @throws \InvalidArgumentException když se data nevejdou ani do verze 40
     */
    public static function matice(string $data, ?int $maska = null): array
    {
        $verze = 1;
        while (self::kapacita($verze) < strlen($data)) {
            if (++$verze > 40) {
                throw new \InvalidArgumentException('Text je na QR kód příliš dlouhý.');
            }
        }
        $qr = new self($verze);
        $qr->kresliPevneVzory();
        $qr->kresliData($qr->kodovaSlova(self::bajty($data, $verze)));

        if ($maska === null) {
            $nejlepsi = PHP_INT_MAX;
            for ($m = 0; $m < 8; $m++) {
                $qr->maskuj($m);
                $qr->kresliFormat($m);
                $penale = $qr->penalizace();
                if ($penale < $nejlepsi) {
                    [$nejlepsi, $maska] = [$penale, $m];
                }
                $qr->maskuj($m); // maska je XOR – druhé použití ji vrátí
            }
        }
        $qr->maskuj((int) $maska);
        $qr->kresliFormat((int) $maska);

        return $qr->moduly;
    }

    /**
     * QR kód jako samostatné SVG (jedna cesta, bez skriptů a odkazů ven) s tichou zónou 4 moduly.
     * Barvy jsou pevné černá na bílé – čtečky telefonů s obráceným kontrastem v tmavém režimu nepočítají.
     */
    public static function svg(string $data, string $popis, int $modul = 5): string
    {
        $matice = self::matice($data);
        $okraj = 4;
        $rozmer = count($matice) + 2 * $okraj;
        $cesta = '';
        foreach ($matice as $y => $radek) {
            foreach ($radek as $x => $tmavy) {
                if ($tmavy) {
                    $cesta .= 'M' . ($x + $okraj) . ' ' . ($y + $okraj) . 'h1v1h-1z';
                }
            }
        }

        return '<svg class="qr" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $rozmer . ' ' . $rozmer . '" width="' . ($rozmer * $modul) . '" height="' . ($rozmer * $modul)
            . '" role="img" aria-label="' . htmlspecialchars($popis, ENT_QUOTES) . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . $cesta . '"/></svg>';
    }

    /** Kolik bajtů dat se vejde do verze při úrovni M. */
    private static function kapacita(int $verze): int
    {
        $bitu = self::datovychSlov($verze) * 8 - 4 - ($verze < 10 ? 8 : 16);

        return intdiv($bitu, 8);
    }

    /** Počet datových kódových slov (bez opravných) ve verzi. */
    private static function datovychSlov(int $verze): int
    {
        return intdiv(self::surovychModulu($verze), 8) - self::ECC_NA_BLOK[$verze] * self::BLOKU[$verze];
    }

    /** Moduly, které zbudou na data a opravné kódy po odečtení všech pevných vzorů. */
    private static function surovychModulu(int $verze): int
    {
        $n = (16 * $verze + 128) * $verze + 64;
        if ($verze >= 2) {
            $zarovnani = intdiv($verze, 7) + 2;
            $n -= (25 * $zarovnani - 10) * $zarovnani - 55;
            if ($verze >= 7) {
                $n -= 36;
            }
        }

        return $n;
    }

    /**
     * Datová kódová slova: režim 0100 (bajty), délka, data, zakončení a výplň 0xEC 0x11.
     *
     * @return list<int>
     */
    private static function bajty(string $data, int $verze): array
    {
        $bity = '0100' . str_pad(decbin(strlen($data)), $verze < 10 ? 8 : 16, '0', STR_PAD_LEFT);
        foreach (str_split($data) as $znak) {
            $bity .= str_pad(decbin(ord($znak)), 8, '0', STR_PAD_LEFT);
        }
        $kapacita = self::datovychSlov($verze) * 8;
        $bity .= str_repeat('0', min(4, $kapacita - strlen($bity)));
        $bity .= str_repeat('0', (8 - strlen($bity) % 8) % 8);
        $slova = array_map('bindec', str_split($bity, 8));
        for ($vypln = 0xEC; count($slova) < $kapacita / 8; $vypln ^= 0xEC ^ 0x11) {
            $slova[] = $vypln;
        }

        return $slova;
    }

    /**
     * Rozdělí data do bloků, ke každému dopočítá opravná slova a bloky proloží (nejdřív data, pak opravy).
     *
     * @param list<int> $data
     * @return list<int>
     */
    private function kodovaSlova(array $data): array
    {
        $bloku = self::BLOKU[$this->verze];
        $eccDelka = self::ECC_NA_BLOK[$this->verze];
        $surovych = intdiv(self::surovychModulu($this->verze), 8);
        $kratkych = $bloku - $surovych % $bloku;
        $kratkyBlok = intdiv($surovych, $bloku);
        $delitel = self::generator($eccDelka);
        $bloky = [];
        for ($i = 0, $k = 0; $i < $bloku; $i++) {
            $delka = $kratkyBlok - $eccDelka + ($i < $kratkych ? 0 : 1);
            $blok = array_slice($data, $k, $delka);
            $k += $delka;
            $bloky[] = [$blok, self::zbytek($blok, $delitel)];
        }
        $vysledek = [];
        for ($i = 0; $i < $kratkyBlok - $eccDelka + 1; $i++) {
            foreach ($bloky as [$blok]) {
                if (isset($blok[$i])) {
                    $vysledek[] = $blok[$i];
                }
            }
        }
        for ($i = 0; $i < $eccDelka; $i++) {
            foreach ($bloky as [, $ecc]) {
                $vysledek[] = $ecc[$i];
            }
        }

        return $vysledek;
    }

    /** Násobení v tělese GF(2^8) s polynomem x^8 + x^4 + x^3 + x^2 + 1. */
    private static function nasob(int $x, int $y): int
    {
        $z = 0;
        for ($i = 7; $i >= 0; $i--) {
            $z = (($z << 1) ^ (($z >> 7) * 0x11D)) & 0xFF;
            $z ^= (($y >> $i) & 1) * $x;
        }

        return $z;
    }

    /**
     * Koeficienty generujícího polynomu Reed–Solomon daného stupně (bez vedoucí jedničky).
     *
     * @return list<int>
     */
    private static function generator(int $stupen): array
    {
        $g = array_fill(0, $stupen - 1, 0);
        $g[] = 1;
        $koren = 1;
        for ($i = 0; $i < $stupen; $i++) {
            for ($j = 0; $j < $stupen; $j++) {
                $g[$j] = self::nasob($g[$j], $koren);
                if ($j + 1 < $stupen) {
                    $g[$j] ^= $g[$j + 1];
                }
            }
            $koren = self::nasob($koren, 0x02);
        }

        return $g;
    }

    /**
     * Opravná kódová slova: zbytek po dělení dat generujícím polynomem.
     *
     * @param list<int> $data
     * @param list<int> $delitel
     * @return list<int>
     */
    public static function zbytek(array $data, array $delitel): array
    {
        $z = array_fill(0, count($delitel), 0);
        foreach ($data as $b) {
            $faktor = $b ^ array_shift($z);
            $z[] = 0;
            foreach ($delitel as $i => $koef) {
                $z[$i] ^= self::nasob($koef, $faktor);
            }
        }

        return $z;
    }

    /** Opravná slova pro blok dat (pro testy se známými vektory). @param list<int> $data @return list<int> */
    public static function opravneKody(array $data, int $pocet): array
    {
        return self::zbytek($data, self::generator($pocet));
    }

    private function nastav(int $x, int $y, bool $tmavy): void
    {
        $this->moduly[$y][$x] = $tmavy;
        $this->pevne[$y][$x] = true;
    }

    /** Časovací linky, tři hledací vzory, zarovnávací vzory, rezervace formátu a u verze 7+ verzní bity. */
    private function kresliPevneVzory(): void
    {
        $n = $this->velikost;
        for ($i = 0; $i < $n; $i++) {
            $this->nastav(6, $i, $i % 2 === 0);
            $this->nastav($i, 6, $i % 2 === 0);
        }
        foreach ([[3, 3], [$n - 4, 3], [3, $n - 4]] as [$cx, $cy]) {
            for ($dy = -4; $dy <= 4; $dy++) {
                for ($dx = -4; $dx <= 4; $dx++) {
                    $x = $cx + $dx;
                    $y = $cy + $dy;
                    if ($x >= 0 && $x < $n && $y >= 0 && $y < $n) {
                        $vzdalenost = max(abs($dx), abs($dy));
                        $this->nastav($x, $y, $vzdalenost !== 2 && $vzdalenost !== 4);
                    }
                }
            }
        }
        $pozice = $this->poziceZarovnani();
        $pocet = count($pozice);
        foreach ($pozice as $i => $x) {
            foreach ($pozice as $j => $y) {
                if (($i === 0 && $j === 0) || ($i === 0 && $j === $pocet - 1) || ($i === $pocet - 1 && $j === 0)) {
                    continue; // tam jsou hledací vzory
                }
                for ($dy = -2; $dy <= 2; $dy++) {
                    for ($dx = -2; $dx <= 2; $dx++) {
                        $this->nastav($x + $dx, $y + $dy, max(abs($dx), abs($dy)) !== 1);
                    }
                }
            }
        }
        $this->kresliFormat(0); // zatím jen rezervace míst, skutečné bity přijdou s maskou
        if ($this->verze >= 7) {
            $zbytek = $this->verze;
            for ($i = 0; $i < 12; $i++) {
                $zbytek = ($zbytek << 1) ^ (($zbytek >> 11) * 0x1F25);
            }
            $bity = $this->verze << 12 | $zbytek;
            for ($i = 0; $i < 18; $i++) {
                $bit = (($bity >> $i) & 1) === 1;
                $a = $n - 11 + $i % 3;
                $b = intdiv($i, 3);
                $this->nastav($a, $b, $bit);
                $this->nastav($b, $a, $bit);
            }
        }
    }

    /** @return list<int> středy zarovnávacích vzorů (v obou osách stejné) */
    private function poziceZarovnani(): array
    {
        if ($this->verze === 1) {
            return [];
        }
        $pocet = intdiv($this->verze, 7) + 2;
        $krok = intdiv($this->verze * 8 + $pocet * 3 + 5, $pocet * 4 - 4) * 2;
        $pozice = [6];
        for ($p = $this->velikost - 7; count($pozice) < $pocet; $p -= $krok) {
            array_splice($pozice, 1, 0, [$p]);
        }

        return $pozice;
    }

    /** Formátové bity (úroveň M + maska, BCH kód, XOR 0x5412) na obě místa a tmavý modul. */
    private function kresliFormat(int $maska): void
    {
        $data = self::UROVEN_M << 3 | $maska;
        $zbytek = $data;
        for ($i = 0; $i < 10; $i++) {
            $zbytek = ($zbytek << 1) ^ (($zbytek >> 9) * 0x537);
        }
        $bity = ($data << 10 | $zbytek) ^ 0x5412;
        $bit = fn (int $i): bool => (($bity >> $i) & 1) === 1;
        $n = $this->velikost;
        for ($i = 0; $i <= 5; $i++) {
            $this->nastav(8, $i, $bit($i));
        }
        $this->nastav(8, 7, $bit(6));
        $this->nastav(8, 8, $bit(7));
        $this->nastav(7, 8, $bit(8));
        for ($i = 9; $i < 15; $i++) {
            $this->nastav(14 - $i, 8, $bit($i));
        }
        for ($i = 0; $i < 8; $i++) {
            $this->nastav($n - 1 - $i, 8, $bit($i));
        }
        for ($i = 8; $i < 15; $i++) {
            $this->nastav(8, $n - 15 + $i, $bit($i));
        }
        $this->nastav(8, $n - 8, true);
    }

    /** Kódová slova po dvousloupcích zprava, střídavě nahoru a dolů, mimo pevné vzory. @param list<int> $slova */
    private function kresliData(array $slova): void
    {
        $n = $this->velikost;
        $i = 0;
        $bitu = count($slova) * 8;
        for ($vpravo = $n - 1; $vpravo >= 1; $vpravo -= 2) {
            if ($vpravo === 6) {
                $vpravo = 5; // svislá časovací linka
            }
            for ($svisle = 0; $svisle < $n; $svisle++) {
                for ($j = 0; $j < 2; $j++) {
                    $x = $vpravo - $j;
                    $nahoru = (($vpravo + 1) & 2) === 0;
                    $y = $nahoru ? $n - 1 - $svisle : $svisle;
                    if (!$this->pevne[$y][$x] && $i < $bitu) {
                        $this->moduly[$y][$x] = (($slova[$i >> 3] >> (7 - ($i & 7))) & 1) === 1;
                        $i++;
                    }
                }
            }
        }
    }

    private function maskuj(int $maska): void
    {
        for ($y = 0; $y < $this->velikost; $y++) {
            for ($x = 0; $x < $this->velikost; $x++) {
                $prohodit = match ($maska) {
                    0 => ($x + $y) % 2 === 0,
                    1 => $y % 2 === 0,
                    2 => $x % 3 === 0,
                    3 => ($x + $y) % 3 === 0,
                    4 => (intdiv($x, 3) + intdiv($y, 2)) % 2 === 0,
                    5 => $x * $y % 2 + $x * $y % 3 === 0,
                    6 => ($x * $y % 2 + $x * $y % 3) % 2 === 0,
                    default => (($x + $y) % 2 + $x * $y % 3) % 2 === 0,
                };
                if ($prohodit && !$this->pevne[$y][$x]) {
                    $this->moduly[$y][$x] = !$this->moduly[$y][$x];
                }
            }
        }
    }

    /** Penalizace podle normy: dlouhé běhy, bloky 2×2, vzory podobné hledacím a nevyváženost tmavé a světlé. */
    private function penalizace(): int
    {
        $n = $this->velikost;
        $body = 0;
        $tmavych = 0;
        $vzor = [true, false, true, true, true, false, true];
        for ($osa = 0; $osa < 2; $osa++) {
            for ($a = 0; $a < $n; $a++) {
                $rada = [];
                for ($b = 0; $b < $n; $b++) {
                    $rada[] = $osa === 0 ? $this->moduly[$a][$b] : $this->moduly[$b][$a];
                }
                // běhy pěti a více stejných modulů
                $beh = 1;
                for ($b = 1; $b <= $n; $b++) {
                    if ($b < $n && $rada[$b] === $rada[$b - 1]) {
                        $beh++;
                        continue;
                    }
                    if ($beh >= 5) {
                        $body += 3 + $beh - 5;
                    }
                    $beh = 1;
                }
                // 1:1:3:1:1 se čtyřmi světlými moduly před nebo za (okraj matice se počítá jako světlý)
                for ($b = 0; $b + 7 <= $n; $b++) {
                    if (array_slice($rada, $b, 7) !== $vzor) {
                        continue;
                    }
                    $svetlePred = true;
                    $svetleZa = true;
                    for ($k = 1; $k <= 4; $k++) {
                        $svetlePred = $svetlePred && !($rada[$b - $k] ?? false);
                        $svetleZa = $svetleZa && !($rada[$b + 6 + $k] ?? false);
                    }
                    $body += ($svetlePred ? 40 : 0) + ($svetleZa ? 40 : 0);
                }
            }
        }
        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $tmavych += $this->moduly[$y][$x] ? 1 : 0;
                if ($x + 1 < $n && $y + 1 < $n && $this->moduly[$y][$x] === $this->moduly[$y][$x + 1]
                    && $this->moduly[$y][$x] === $this->moduly[$y + 1][$x] && $this->moduly[$y][$x] === $this->moduly[$y + 1][$x + 1]) {
                    $body += 3;
                }
            }
        }
        // každých 5 % odchylky od poloviny tmavých modulů = 10 bodů
        $body += (int) (ceil(abs($tmavych * 20 - $n * $n * 10) / ($n * $n)) - 1) * 10;

        return $body;
    }
}
