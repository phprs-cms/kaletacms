<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Pás stránky přes celou šířku; obsah drží vnitřní obal v šířce webu (nebo úzký pro text, nebo žádný). */
final class Sekce extends Prvek
{
    public const string TYP = 'sekce';
    public const string NAZEV = 'Sekce';
    public const string POPIS = 'Pás přes celou šířku stránky s obsahem uprostřed.';
    public const string IKONA = 'sekce';
    public const string SKUPINA = 'Rozložení';
    public const bool KONTEJNER = true;
    public const array ZNACKY = ['section', 'header', 'footer', 'aside', 'article', 'div'];

    public static function vlastnosti(): array
    {
        return ['sirka' => ['typ' => 'vyber', 'popisek' => 'Šířka obsahu', 'vychozi' => 'obsah', 'moznosti' => ['obsah' => 'šířka webu', 'uzka' => 'úzká (text)', 'plna' => 'celá šířka']]];
    }

    public static function vychoziStyl(): array
    {
        return ['zaklad' => ['odsazeni_y' => 'xl']];
    }

    public static function zakladniCss(): string
    {
        return '.ka-obal { width: min(100% - 2 * var(--ka-mezera-m), var(--ka-sirka)); margin-inline: auto; }
.ka-obal--uzka { width: min(100% - 2 * var(--ka-mezera-m), var(--ka-sirka-textu)); }
.ka-obal > * + * { margin-block-start: var(--ka-mezera-m); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $sirka = $p['obsah']['sirka'] ?? 'obsah';
        $obsah = $sirka === 'plna' ? $deti : '<div class="ka-obal' . ($sirka === 'uzka' ? ' ka-obal--uzka' : '') . '">' . $deti . '</div>';

        return '<' . $p['znacka'] . $a . '>' . $obsah . '</' . $p['znacka'] . '>';
    }
}
