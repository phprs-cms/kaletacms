<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

final class Oddelovac extends Prvek
{
    public const string TYP = 'oddelovac';
    public const string NAZEV = 'Oddělovač';
    public const string POPIS = 'Tenká vodorovná čára mezi částmi obsahu.';
    public const string IKONA = 'oddelovac';
    public const array ZNACKY = ['hr'];

    public static function zakladniCss(): string
    {
        return 'hr.ka-oddelovac { border: 0; border-top: 1px solid var(--ka-barva-linka); margin-block: var(--ka-mezera-l); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        return '<hr' . Text::sTridou($a, 'ka-oddelovac') . '>';
    }
}
