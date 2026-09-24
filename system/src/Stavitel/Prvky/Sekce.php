<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

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
        return '.mc-obal { width: min(100% - 2 * var(--mc-mezera-m), var(--mc-sirka)); margin-inline: auto; }
.mc-obal--uzka { width: min(100% - 2 * var(--mc-mezera-m), var(--mc-sirka-textu)); }
.mc-obal > * + * { margin-block-start: var(--mc-mezera-m); }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $sirka = $p['obsah']['sirka'] ?? 'obsah';
        $obsah = $sirka === 'plna' ? $deti : '<div class="mc-obal' . ($sirka === 'uzka' ? ' mc-obal--uzka' : '') . '">' . $deti . '</div>';

        return '<' . $p['znacka'] . $a . '>' . $obsah . '</' . $p['znacka'] . '>';
    }
}
