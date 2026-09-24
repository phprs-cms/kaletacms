<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

/** Logo webu z Vzhledu (bez něj název webu) jako odkaz na úvodní stránku. Výšku mění styl „Výška“. */
final class Logo extends Prvek
{
    public const string TYP = 'logo';
    public const string NAZEV = 'Logo';
    public const string POPIS = 'Logo webu z nastavení Vzhledu, jinak název webu – odkaz na úvodní stránku.';
    public const string IKONA = 'logo';
    public const string SKUPINA = 'Části webu';
    public const array ZNACKY = ['a'];
    public const bool JEN_CASTI = true;

    public static function vlastnosti(): array
    {
        return ['nazev' => ['typ' => 'prepinac', 'popisek' => 'Vedle loga i název webu', 'vychozi' => false]];
    }

    public static function zakladniCss(): string
    {
        return '.ka-logo { display: inline-flex; align-items: center; gap: var(--ka-mezera-xs); height: 2.75rem; color: inherit; font-family: var(--ka-pismo-titulky); font-size: var(--ka-krok-1); font-weight: 800; line-height: 1.1; text-decoration: none; }
.ka-logo img { display: block; width: auto; height: 100%; max-width: none; }';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $web = $k->app->settings();
        $nazev = $web->get('nazev_webu');
        $logo = $web->get('logo_webu');
        $uvod = $k->url('');
        $obsah = $logo !== ''
            ? '<img src="' . e($k->obrazek($logo)) . '" alt="' . e($p['obsah']['nazev'] ? '' : $nazev) . '">' . ($p['obsah']['nazev'] ? '<span>' . e($nazev) . '</span>' : '')
            : e($nazev);

        return '<a' . Text::sTridou($a, 'ka-logo') . ' href="' . e($uvod) . '"' . ($k->cesta === $uvod ? ' aria-current="page"' : '') . '>' . $obsah . '</a>';
    }
}
