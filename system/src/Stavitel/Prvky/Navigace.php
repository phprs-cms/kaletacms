<?php

declare(strict_types=1);

namespace MiroCMS\Stavitel\Prvky;

use MiroCMS\Stavitel\Kontext;
use MiroCMS\Stavitel\Prvek;

/**
 * Navigace: menu z Vzhled → Menu (hlavní nebo v patičce, i s podmenu) a přepínač jazyků. Na telefonu se schová
 * za tlačítko a otevře jako popover (Popover API – bez JavaScriptu, zavře ho Esc i klepnutí mimo).
 */
final class Navigace extends Prvek
{
    public const string TYP = 'navigace';
    public const string NAZEV = 'Navigace';
    public const string POPIS = 'Menu webu (Vzhled → Menu) i s podmenu a přepínač jazyků; na telefonu rozbalovací.';
    public const string IKONA = 'menu';
    public const string SKUPINA = 'Části webu';
    public const array ZNACKY = ['nav'];
    public const bool JEN_CASTI = true;

    public static function vlastnosti(): array
    {
        return [
            'menu' => ['typ' => 'vyber', 'popisek' => 'Které menu', 'vychozi' => 'hlavni', 'moznosti' => \MiroCMS\Core\Menu::UMISTENI],
            'novinky' => ['typ' => 'prepinac', 'popisek' => 'Odkaz na novinky (u automatického menu)', 'vychozi' => true],
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
.mc-nav li { position: relative; }
.mc-nav li > span { display: block; padding: 0.5em 0.8em; font-weight: 600; cursor: default; }
.mc-nav .podmenu > a::after, .mc-nav .podmenu > span::after { content: ""; display: inline-block; width: 0.4em; height: 0.4em; margin-inline-start: 0.45em; border: solid currentColor; border-width: 0 2px 2px 0; transform: translateY(-0.2em) rotate(45deg); }
.mc-nav .podmenu.aktivni > a, .mc-nav .podmenu.aktivni > span { color: var(--mc-barva-primarni); }
.mc-nav .podmenu > ul { display: none; position: absolute; top: 100%; left: 0; z-index: 60; flex-direction: column; flex-wrap: nowrap; min-width: 14rem; padding: var(--mc-mezera-2xs); border: 1px solid var(--mc-barva-linka); border-radius: var(--mc-zaobleni); background: var(--mc-barva-pozadi); color: var(--mc-barva-text); box-shadow: var(--mc-stin-m); }
.mc-nav .podmenu > ul a { border-radius: calc(var(--mc-zaobleni) / 1.5); font-weight: 500; }
.mc-nav .podmenu:hover > ul, .mc-nav .podmenu:focus-within > ul { display: flex; }
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
	.mc-nav-menu[popover] .podmenu > ul { display: flex; position: static; min-width: 0; padding: 0 0 0 1rem; border: 0; box-shadow: none; }
	.mc-nav-menu[popover] .podmenu > a::after, .mc-nav-menu[popover] .podmenu > span::after { display: none; }
}';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $menu = $k->menu[$p['obsah']['menu'] ?? 'hlavni'] ?? [];
        if (!$p['obsah']['novinky']) {
            $menu = array_values(array_filter($menu, fn (array $x): bool => empty($x['auto'])));
        }
        $polozky = \MiroCMS\Core\Menu::html($menu, $k->cesta, $k->url(''));
        if ($polozky === '' && $k->editor) {
            $polozky = '<li><span>' . e(t('Menu sestavíte ve Vzhled → Menu')) . '</span></li>';
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
