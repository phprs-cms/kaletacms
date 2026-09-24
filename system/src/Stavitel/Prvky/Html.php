<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Vlastní HTML (mapa, rezervační systém, vložený kód služby). Vkládá a mění jen správce; skripty se nepropustí. */
final class Html extends Prvek
{
    public const string TYP = 'html';
    public const string NAZEV = 'Vlastní HTML';
    public const string POPIS = 'Vložený kód jiné služby (mapa, rezervace). Jen pro správce.';
    public const string IKONA = 'kod';
    public const string SKUPINA = 'Pokročilé';
    public const array ZNACKY = ['div'];
    public const bool JEN_SPRAVCE = true;

    public static function vlastnosti(): array
    {
        return ['kod' => ['typ' => 'kod', 'popisek' => 'HTML', 'vychozi' => '', 'max' => 20000]];
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        return '<div' . $a . '>' . $p['obsah']['kod'] . '</div>';
    }
}
