<?php

declare(strict_types=1);

namespace Kaleta\Stavitel\Prvky;

use Kaleta\Stavitel\Kontext;
use Kaleta\Stavitel\Prvek;

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
            'menu' => ['typ' => 'vyber', 'popisek' => 'Které menu', 'vychozi' => 'hlavni', 'moznosti' => \Kaleta\Core\Menu::UMISTENI],
            'novinky' => ['typ' => 'prepinac', 'popisek' => 'Odkaz na novinky (u automatického menu)', 'vychozi' => true],
            'mobil' => ['typ' => 'prepinac', 'popisek' => 'Na telefonu schovat za tlačítko', 'vychozi' => true],
            'mega' => ['typ' => 'prepinac', 'popisek' => 'Podmenu jako široký panel (mega menu)', 'vychozi' => false],
            'zvyrazneni' => ['typ' => 'vyber', 'popisek' => 'Zvýraznění aktivní položky', 'vychozi' => 'pozadi', 'moznosti' => ['pozadi' => 'podbarvení', 'podtrzeni' => 'podtržení doplňkovou barvou']],
        ];
    }

    public static function zakladniCss(): string
    {
        return '.ka-nav { display: flex; align-items: center; }
.ka-nav-menu { display: flex; align-items: center; gap: var(--ka-mezera-xs); }
.ka-nav ul { display: flex; flex-wrap: wrap; gap: var(--ka-nav-mezera, var(--ka-mezera-2xs)); margin: 0; padding: 0; list-style: none; }
.ka-nav a { display: block; padding: var(--ka-nav-odsazeni, 0.5em 0.8em); border-radius: var(--ka-zaobleni-plne); color: inherit; font-weight: var(--ka-nav-tloustka, 600); text-decoration: none; }
.ka-nav a:focus-visible { outline: 3px solid var(--ka-barva-sekundarni); outline-offset: 2px; }
.ka-nav a:hover { background: var(--ka-barva-plocha); }
.ka-nav a[aria-current] { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-primarni); }
.ka-nav--podtrzeni a:hover, .ka-nav--podtrzeni a[aria-current] { background: none; }
.ka-nav--podtrzeni a:hover { color: var(--ka-nav-hover, var(--ka-barva-sekundarni)); }
.ka-nav--podtrzeni a[aria-current] { color: inherit; text-decoration: underline; text-decoration-color: var(--ka-barva-sekundarni); text-decoration-thickness: 2px; text-underline-offset: 6px; }
.ka-nav li { position: relative; }
.ka-nav li > .menu-skupina { display: block; border: 0; background: none; color: inherit; font: inherit; text-align: start; padding: 0.5em 0.8em; font-weight: 600; cursor: default; }
.ka-nav .podmenu > a::after, .ka-nav .podmenu > .menu-skupina::after { content: ""; display: inline-block; width: 0.4em; height: 0.4em; margin-inline-start: 0.45em; border: solid currentColor; border-width: 0 2px 2px 0; transform: translateY(-0.2em) rotate(45deg); }
.ka-nav .podmenu.aktivni > a, .ka-nav .podmenu.aktivni > .menu-skupina { color: var(--ka-barva-primarni); }
.ka-nav .podmenu > ul { display: none; position: absolute; top: 100%; left: 0; z-index: 60; flex-direction: column; flex-wrap: nowrap; min-width: 14rem; padding: var(--ka-mezera-2xs); border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); box-shadow: var(--ka-stin-m); }
.ka-nav .podmenu > ul a { border-radius: calc(var(--ka-zaobleni) / 1.5); font-weight: 500; }
.ka-nav .podmenu:hover > ul, .ka-nav .podmenu:focus-within > ul { display: flex; }
/* přepínač jazyků v navigaci (image/web.css): pravidla menu (.ka-nav a, .ka-nav ul) se na něj nevztahují */
.ka-nav .ka-jazyky a { padding: 0.45em 0.6em; font-weight: 600; }
.ka-nav .ka-jazyky a[aria-current] { background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); }
.ka-nav .ka-jazyky-vyber [popover]:popover-open { display: flex; flex-direction: column; flex-wrap: nowrap; gap: 0; }
.ka-nav .ka-jazyky-vyber [popover] a { display: flex; padding: 0.55em 0.75em; border-radius: calc(var(--ka-zaobleni) / 1.5); font-weight: 500; }
.ka-nav .ka-jazyky-vyber [popover] a[aria-current] { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-primarni); font-weight: 600; }
.ka-nav-tl { display: none; }
.ka-nav-menu[popover] { position: static; inset: auto; width: auto; margin: 0; padding: 0; border: 0; background: none; color: inherit; overflow: visible; }
@media (min-width: 768px) {
	.ka-nav--mega, .ka-nav--mega .ka-nav-menu { position: relative; }
	.ka-nav--mega li.podmenu { position: static; }
	.ka-nav--mega .podmenu > ul { left: auto; right: 0; width: min(56rem, 100vw - 2rem); padding: var(--ka-mezera-s); }
	.ka-nav--mega .podmenu:hover > ul, .ka-nav--mega .podmenu:focus-within > ul { display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: var(--ka-mezera-2xs); }
	.ka-nav--mega .podmenu > ul a { padding: 0.8em 1em; }
}
@media (max-width: 767px) {
	.ka-nav-tl { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: var(--ka-nav-tlacitko-okraj, 1px solid var(--ka-barva-linka)); border-radius: 50%; background: var(--ka-barva-pozadi); color: var(--ka-barva-text); cursor: pointer; }
	.ka-nav-tl span, .ka-nav-tl span::before, .ka-nav-tl span::after { display: block; width: 1.1rem; height: 2px; background: currentColor; }
	.ka-nav-tl span { position: relative; }
	.ka-nav-tl span::before, .ka-nav-tl span::after { content: ""; position: absolute; left: 0; }
	.ka-nav-tl span::before { top: -6px; }
	.ka-nav-tl span::after { top: 6px; }
	.ka-nav-menu[popover] { position: fixed; inset: 4.5rem var(--ka-mezera-m) auto; flex-direction: column; align-items: stretch; padding: var(--ka-mezera-s); border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); box-shadow: var(--ka-stin-l); }
	.ka-nav-menu[popover]:not(:popover-open) { display: none; }
	/* klávesnice: Tab za poslední položku menu – otevřené menu se schová, aby nezakrylo prvek, na který fokus přešel (WCAG 2.4.11),
	   a ukáže se zase, když se fokus do navigace vrátí; Esc nebo klepnutí mimo ho zavře úplně (Popover API, bez JavaScriptu) */
	:root:has(:focus-visible) .ka-nav:not(:has(:focus-visible)) > .ka-nav-menu[popover]:popover-open { display: none; }
	.ka-nav-menu[popover] ul { flex-direction: column; }
	.ka-nav-menu[popover] .podmenu > ul { display: flex; position: static; min-width: 0; padding: 0 0 0 1rem; border: 0; box-shadow: none; }
	.ka-nav-menu[popover] .podmenu > a::after, .ka-nav-menu[popover] .podmenu > .menu-skupina::after { display: none; }
}';
    }

    public static function vykresli(array $p, string $a, string $deti, Kontext $k): string
    {
        $menu = $k->menu[$p['obsah']['menu'] ?? 'hlavni'] ?? [];
        if (!$p['obsah']['novinky']) {
            $menu = array_values(array_filter($menu, fn (array $x): bool => empty($x['auto'])));
        }
        $polozky = \Kaleta\Core\Menu::html($menu, $k->cesta, $k->url(''));
        if ($polozky === '' && $k->editor) {
            $polozky = '<li><span>' . e(t('Menu sestavíte ve Vzhled → Menu')) . '</span></li>';
        }
        $menu = '<ul>' . $polozky . '</ul>' . $k->jazyky;
        $tridy = 'ka-nav' . (!empty($p['obsah']['mega']) ? ' ka-nav--mega' : '') . (($p['obsah']['zvyrazneni'] ?? '') === 'podtrzeni' ? ' ka-nav--podtrzeni' : '');
        if (!$p['obsah']['mobil']) {
            return '<nav' . Text::sTridou($a, $tridy) . ' aria-label="' . e(t('Hlavní navigace')) . '"><div class="ka-nav-menu">' . $menu . '</div></nav>';
        }
        $id = 'ka-nav-' . $p['id'];

        return '<nav' . Text::sTridou($a, $tridy) . ' aria-label="' . e(t('Hlavní navigace')) . '">'
            . '<button class="ka-nav-tl" type="button" popovertarget="' . e($id) . '" aria-label="' . e(t('Menu')) . '"><span aria-hidden="true"></span></button>'
            . '<div class="ka-nav-menu" id="' . e($id) . '" popover>' . $menu . '</div></nav>';
    }
}
