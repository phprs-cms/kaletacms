<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

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
        return '.ka-karta-odkaz { display: block; color: inherit; text-decoration: none; transition: transform .15s ease, box-shadow .15s ease; }
.ka-karta-odkaz:hover { transform: translateY(-2px); box-shadow: var(--ka-stin-m); }
.ka-karta-odkaz:focus-visible { outline: 2px solid var(--ka-barva-primarni); outline-offset: 2px; }
@media (prefers-reduced-motion: reduce) { .ka-karta-odkaz { transition: none; } .ka-karta-odkaz:hover { transform: none; } }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        if (str_contains($deti, Udaje::PRAZDNE_HODINY)) {
            // karta „Otevírací doba“ bez vyplněné doby: na webu by zůstal jen nadpis v prázdném rámečku
            $deti = str_replace(Udaje::PRAZDNE_HODINY, '', $deti);
            if (trim(strip_tags((string) preg_replace('#<(h[1-6])\b.*?</\1>#s', '', $deti))) === '') {
                return '';
            }
        }
        $odkaz = (string) ($p['obsah']['odkaz'] ?? '');
        if ($odkaz !== '') {
            // odkaz v odkazu HTML nedovoluje (prohlížeč by kartu rozlomil): tlačítka a odkazy uvnitř zůstanou jen vzhledem
            $deti = (string) preg_replace_callback('#<a\b([^>]*)>#', fn (array $m): string => '<span' . preg_replace('#\s(?:href|target|rel|download|hreflang|aria-current)="[^"]*"#', '', $m[1]) . '>', $deti);
            $deti = str_replace('</a>', '</span>', $deti);

            return '<a' . Text::sTridou($a, 'ka-karta-odkaz') . ' href="' . e($odkaz) . '">' . $deti . '</a>';
        }

        return '<' . $p['znacka'] . $a . '>' . $deti . '</' . $p['znacka'] . '>';
    }
}
