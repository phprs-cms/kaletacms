<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

final class Seznam extends Prvek
{
    public const string TYP = 'seznam';
    public const string NAZEV = 'Seznam';
    public const string POPIS = 'Výčet bodů – s odrážkami, čísly nebo fajfkami.';
    public const string IKONA = 'seznam';
    public const array ZNACKY = ['ul', 'ol'];

    public static function vlastnosti(): array
    {
        return [
            'polozky' => ['typ' => 'radky', 'popisek' => 'Položky (každá na řádek)', 'vychozi' => t('První výhoda') . "\n" . t('Druhá výhoda') . "\n" . t('Třetí výhoda'), 'max' => 4000],
            'styl' => ['typ' => 'vyber', 'popisek' => 'Odrážky', 'vychozi' => 'odrazky', 'moznosti' => ['odrazky' => 'běžné', 'fajfky' => 'fajfky', 'bez' => 'bez odrážek']],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.ka-seznam--fajfky, .ka-seznam--bez { list-style: none; padding-inline-start: 0; }
.ka-seznam--fajfky li { position: relative; padding-inline-start: 1.6em; }
.ka-seznam--fajfky li::before { content: ""; position: absolute; left: 0.1em; top: 0.35em; width: 0.9em; height: 0.5em; border: solid var(--ka-barva-primarni); border-width: 0 0 0.16em 0.16em; transform: rotate(-45deg); }
.ka-seznam li + li { margin-block-start: 0.4em; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $polozky = array_filter(array_map(trim(...), preg_split('/\R/', $p['obsah']['polozky']) ?: []), fn (string $r): bool => $r !== '');

        return '<' . $p['znacka'] . Text::sTridou($a, 'ka-seznam ka-seznam--' . $p['obsah']['styl']) . '>'
            . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $polozky)) . '</' . $p['znacka'] . '>';
    }
}
