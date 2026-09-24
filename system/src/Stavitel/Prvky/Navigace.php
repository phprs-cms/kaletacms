<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Hlavní navigace: stránky „v menu“, odkaz na novinky a přepínač jazyků. Na telefonu se schová za tlačítko
 * a otevře jako popover (Popover API – bez JavaScriptu, zavře ho Esc i klepnutí mimo).
 */
final class Navigace extends Prvek
{
    public const string TYP = 'navigace';
    public const string NAZEV = 'Navigace';
    public const string POPIS = 'Stránky zařazené do menu, novinky a přepínač jazyků; na telefonu rozbalovací.';
    public const string IKONA = 'menu';
    public const string SKUPINA = 'Části webu';
    public const array ZNACKY = ['nav'];
    public const bool JEN_CASTI = true;

    public static function vlastnosti(): array
    {
        return [
            'novinky' => ['typ' => 'prepinac', 'popisek' => 'Odkaz na novinky', 'vychozi' => true],
            'mobil' => ['typ' => 'prepinac', 'popisek' => 'Na telefonu schovat za tlačítko', 'vychozi' => true],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.mc-nav { display: flex; align-items: center; }
.mc-nav-menu { display: flex; align-items: center; gap: var(--mc-mezera-xs); }
.mc-nav ul { display: flex; flex-wrap: wrap; gap: var(--mc-mezera-2xs); margin: 0; padding: 0; list-style: none; }
.mc-nav a { display: block; padding: 0.5em 0.8em; border-radius: var(--mc-zaobleni-plne); color: inherit; font-weight: 600; text-decoration: none; }
.mc-nav a:hover { background: var(--mc-barva-plocha); }
.mc-nav a[aria-current] { background: var(--mc-barva-primarni-jemna); color: var(--mc-barva-primarni); }
.mc-nav-tl { display: none; }
.mc-nav-menu[popover] { position: static; inset: auto; width: auto; margin: 0; padding: 0; border: 0; background: none; color: inherit; overflow: visible; }
@media (max-width: 767px) {
	.mc-nav-tl { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: 1px solid var(--mc-barva-linka); border-radius: 50%; background: var(--mc-barva-pozadi); color: var(--mc-barva-text); cursor: pointer; }
	.mc-nav-tl span, .mc-nav-tl span::before, .mc-nav-tl span::after { display: block; width: 1.1rem; height: 2px; background: currentColor; }
	.mc-nav-tl span { position: relative; }
	.mc-nav-tl span::before, .mc-nav-tl span::after { content: ""; position: absolute; left: 0; }
	.mc-nav-tl span::before { top: -6px; }
	.mc-nav-tl span::after { top: 6px; }
	.mc-nav-menu[popover] { position: fixed; inset: 4.5rem var(--mc-mezera-m) auto; flex-direction: column; align-items: stretch; padding: var(--mc-mezera-s); border: 1px solid var(--mc-barva-linka); border-radius: var(--mc-zaobleni); background: var(--mc-barva-pozadi); color: var(--mc-barva-text); box-shadow: var(--mc-stin-l); }
	.mc-nav-menu[popover]:not(:popover-open) { display: none; }
	.mc-nav-menu[popover] ul { flex-direction: column; }
}';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $odkaz = function (string $adresa, string $text) use ($k): string {
            $url = $k->url($adresa);
            $aktivni = $adresa === '' ? $k->cesta === $url : ($k->cesta === $url || str_starts_with($k->cesta, $url . '/'));

            return '<li><a href="' . e($url) . '"' . ($aktivni ? ' aria-current="page"' : '') . '>' . e($text) . '</a></li>';
        };
        $polozky = implode('', array_map(fn (array $s): string => $odkaz($s['seo_link'], $s['titulek']), $k->menu));
        if ($p['obsah']['novinky']) {
            $polozky .= $odkaz('novinky', t('Novinky'));
        }
        if ($polozky === '' && $k->editor) {
            $polozky = '<li><a href="#">' . e(t('Stránky zařazené do menu')) . '</a></li>';
        }
        $menu = '<ul>' . $polozky . '</ul>' . $k->jazyky;
        if (!$p['obsah']['mobil']) {
            return '<nav' . Text::sTridou($a, 'mc-nav') . ' aria-label="' . e(t('Hlavní navigace')) . '"><div class="mc-nav-menu">' . $menu . '</div></nav>';
        }
        $id = 'mc-nav-' . $p['id'];

        return '<nav' . Text::sTridou($a, 'mc-nav') . ' aria-label="' . e(t('Hlavní navigace')) . '">'
            . '<button class="mc-nav-tl" type="button" popovertarget="' . e($id) . '" aria-label="' . e(t('Menu')) . '"><span aria-hidden="true"></span></button>'
            . '<div class="mc-nav-menu" id="' . e($id) . '" popover>' . $menu . '</div></nav>';
    }
}
