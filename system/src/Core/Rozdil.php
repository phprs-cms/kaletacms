<?php

declare(strict_types=1);

namespace MiroCMS\Core;

/**
 * Porovnání dvou verzí textu: po odstavcích, ve změněných odstavcích po slovech.
 * Výstup je bezpečné HTML (<ins>, <del>) - vstupní HTML se převádí na prostý text.
 */
final class Rozdil
{
    /** @return array{html:string, pridano:int, smazano:int} */
    public static function html(string $stare, string $nove): array
    {
        $a = self::odstavce($stare);
        $b = self::odstavce($nove);
        $html = '';
        $pridano = $smazano = 0;
        $kroky = self::kroky($a, $b);
        for ($i = 0; $i < count($kroky); $i++) {
            [$typ, $text] = $kroky[$i];
            if ($typ === '=') {
                $html .= '<p>' . e($text) . '</p>';
            } elseif ($typ === '-' && ($kroky[$i + 1][0] ?? '') === '+') {
                // smazaný a hned přidaný odstavec = upravený odstavec: rozdíl po slovech
                $slova = self::kroky(self::slova($text), self::slova($kroky[$i + 1][1]));
                $html .= '<p>';
                foreach ($slova as [$t, $s]) {
                    $html .= $t === '=' ? e($s) : ($t === '+' ? '<ins>' . e($s) . '</ins>' : '<del>' . e($s) . '</del>');
                    $pridano += (int) ($t === '+' && trim($s) !== '');
                    $smazano += (int) ($t === '-' && trim($s) !== '');
                }
                $html .= '</p>';
                $i++;
            } else {
                $html .= '<p>' . ($typ === '+' ? '<ins>' : '<del>') . e($text) . ($typ === '+' ? '</ins>' : '</del>') . '</p>';
                $pocet = count(self::slova($text)) / 2;
                $typ === '+' ? $pridano += (int) ceil($pocet) : $smazano += (int) ceil($pocet);
            }
        }

        return ['html' => $html, 'pridano' => $pridano, 'smazano' => $smazano];
    }

    /** @return list<string> */
    private static function odstavce(string $html): array
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('#</(p|h[1-6]|li|blockquote|figcaption|tr|div)>|<br\s*/?>#i', "\n", $html)), ENT_QUOTES | ENT_HTML5);

        return array_values(array_filter(array_map(fn (string $r): string => trim((string) preg_replace('/\s+/u', ' ', $r)), explode("\n", $text)), fn (string $r): bool => $r !== ''));
    }

    /** Slova i s mezerami za nimi, aby šel text složit zpět. @return list<string> */
    private static function slova(string $text): array
    {
        return preg_split('/(?<=\s)/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    }

    /**
     * Nejdelší společná podposloupnost -> kroky [typ, text]: "=" beze změny, "-" jen ve staré, "+" jen v nové verzi.
     *
     * @param list<string> $a
     * @param list<string> $b
     * @return list<array{0:string, 1:string}>
     */
    private static function kroky(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        if ($n * $m > 4_000_000) { // příliš dlouhé na přesné porovnání: celé smazáno, celé přidáno
            return [...array_map(fn (string $s): array => ['-', $s], $a), ...array_map(fn (string $s): array => ['+', $s], $b)];
        }
        $d = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $d[$i][$j] = $a[$i] === $b[$j] ? $d[$i + 1][$j + 1] + 1 : max($d[$i + 1][$j], $d[$i][$j + 1]);
            }
        }
        $kroky = [];
        $i = $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $kroky[] = ['=', $a[$i]];
                $i++;
                $j++;
            } elseif ($d[$i + 1][$j] >= $d[$i][$j + 1]) {
                $kroky[] = ['-', $a[$i++]];
            } else {
                $kroky[] = ['+', $b[$j++]];
            }
        }
        while ($i < $n) {
            $kroky[] = ['-', $a[$i++]];
        }
        while ($j < $m) {
            $kroky[] = ['+', $b[$j++]];
        }

        return $kroky;
    }
}
