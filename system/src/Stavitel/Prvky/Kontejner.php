<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/** Skupina prvků (flex): karta, řada tlačítek, sloupec textu. S odkazem se celá stane odkazem. */
final class Kontejner extends Prvek
{
    public const string TYP = 'kontejner';
    public const string NAZEV = 'Kontejner';
    public const string POPIS = 'Skupina prvků pod sebou nebo vedle sebe – karta, řada tlačítek.';
    public const string IKONA = 'kontejner';
    public const string SKUPINA = 'Rozložení';
    public const bool KONTEJNER = true;
    public const array ZNACKY = ['div', 'article', 'aside', 'nav', 'header', 'footer', 'ul', 'li'];

    public static function vlastnosti(): array
    {
        return ['odkaz' => ['typ' => 'odkaz', 'popisek' => 'Celý kontejner jako odkaz (nepovinné)', 'vychozi' => '']];
    }

    public static function vychoziStyl(): array
    {
        return ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']];
    }

    public static function zakladniCss(): string
    {
        // karta jako odkaz: text zůstane v barvách karty, ne v barvě odkazu; najetí myší ji jemně zvedne
        return '.mc-karta-odkaz { display: block; color: inherit; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease; }
.mc-karta-odkaz:hover { transform: translateY(-2px); box-shadow: var(--mc-stin-m); }
.mc-karta-odkaz:focus-visible { outline: 2px solid var(--mc-barva-primarni); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) { .mc-karta-odkaz { transition: none; } .mc-karta-odkaz:hover { transform: none; } }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $odkaz = (string) ($p['obsah']['odkaz'] ?? '');
        if ($odkaz !== '') {
            return '<a' . Text::sTridou($a, 'mc-karta-odkaz') . ' href="' . e($odkaz) . '">' . $deti . '</a>';
        }

        return '<' . $p['znacka'] . $a . '>' . $deti . '</' . $p['znacka'] . '>';
    }
}
