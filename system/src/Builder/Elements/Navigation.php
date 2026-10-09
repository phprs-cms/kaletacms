<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Navigation: the menu from „Vzhled → Menu“ (Appearance → Menu; main or in the footer, with submenus too) and the language switcher.
 * On a phone it hides behind a button and opens as a popover (Popover API – without JavaScript, Esc and a click outside close it).
 */
final class Navigation extends Element
{
    public const string TYPE = 'navigace';
    public const string NAME = 'Navigation';
    public const string DESCRIPTION = 'Site menu (Appearance → Menu) with submenus and the language switcher; collapsible on phones.';
    public const string ICON = 'menu';
    public const string GROUP = 'Site parts';
    public const array HTML_TAGS = ['nav'];
    public const bool PARTS_ONLY = true;

    public static function properties(): array
    {
        return [
            'menu' => ['typ' => 'vyber', 'popisek' => 'Which menu', 'vychozi' => 'hlavni', 'moznosti' => \Kaleta\Core\Menu::LOCATIONS],
            'novinky' => ['typ' => 'prepinac', 'popisek' => 'Link to news (in the automatic menu)', 'vychozi' => true],
            'mobil' => ['typ' => 'prepinac', 'popisek' => 'Hide behind a button on phones', 'vychozi' => true],
            'mobil_tablet' => ['typ' => 'prepinac', 'popisek' => 'Also on tablets (up to 1023 px) – for a long menu', 'vychozi' => false],
            'mega' => ['typ' => 'prepinac', 'popisek' => 'Submenu as a wide panel (mega menu)', 'vychozi' => false],
            'zvyrazneni' => ['typ' => 'vyber', 'popisek' => 'Current item highlight', 'vychozi' => 'pozadi', 'moznosti' => ['pozadi' => 'podbarvení', 'podtrzeni' => 'underline in the secondary colour']],
            'jazyky' => ['typ' => 'prepinac', 'popisek' => 'Language switcher (turn it off when it is elsewhere, for example in the footer)', 'vychozi' => true],
        ];
    }

    public static function baseCss(): string
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
.ka-nav li > .menu-skupina { display: block; border: 0; background: none; color: inherit; font: inherit; text-align: start; padding: var(--ka-nav-odsazeni, 0.5em 0.8em); font-weight: var(--ka-nav-tloustka, 600); cursor: default; }
/* 3.6: a submenu parent is a disclosure (Core\Menu::html) – a group is its own toggle button, a linked item has a toggle next to its link */
.ka-nav li.podmenu { display: flex; flex-wrap: wrap; align-items: center; }
.ka-nav .podmenu > .menu-skupina { cursor: pointer; border-radius: var(--ka-zaobleni-plne); }
/* 3.9.1: -0.225em puts the arrow of a toggle 0.45em after the link, as far as a group without a page has it after its name */
.ka-nav .menu-rozbalit { display: grid; place-items: center; min-width: 1.75em; min-height: 1.75em; margin-inline-start: -0.225em; padding: 0; border: 0; border-radius: var(--ka-zaobleni-plne); background: none; color: inherit; font: inherit; cursor: pointer; }
.ka-nav .podmenu > .menu-skupina::after, .ka-nav .menu-rozbalit::after { content: ""; display: inline-block; width: 0.4em; height: 0.4em; margin-inline-start: 0.45em; border: solid currentColor; border-width: 0 2px 2px 0; transform: translateY(-0.2em) rotate(45deg); }
.ka-nav .menu-rozbalit::after { margin: 0; transform: translateY(-0.1em) rotate(45deg); }
.ka-nav .podmenu > [aria-expanded="true"]::after { transform: translateY(0.1em) rotate(-135deg); }
.ka-nav .podmenu > button:hover { background: var(--ka-barva-plocha); }
.ka-nav .podmenu > button:focus-visible { outline: 3px solid var(--ka-barva-sekundarni); outline-offset: 2px; }
.ka-nav .podmenu.aktivni > a, .ka-nav .podmenu.aktivni > .menu-skupina { color: var(--ka-barva-primarni); }
.ka-nav .podmenu > ul { display: none; position: absolute; top: 100%; left: 0; z-index: 60; flex-direction: column; flex-wrap: nowrap; min-width: 14rem; padding: var(--ka-mezera-2xs); border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); box-shadow: var(--ka-stin-m); gap: 2px; }
/* a submenu has its own gaps and padding – --ka-nav-mezera and --ka-nav-odsazeni belong to the items of the main bar */
.ka-nav .podmenu > ul a { padding: 0.55em 0.8em; border-radius: calc(var(--ka-zaobleni) / 1.5); font-weight: 500; }
/* open: without image/web.js (no aria-expanded yet) on hover and keyboard focus; with it by the toggle button, and on hover
   only for a real pointer – a tap on a touch screen opens it through the button, not through a sticky :hover */
.ka-nav .podmenu:not(:has(> [aria-expanded])):is(:hover, :focus-within) > ul, .ka-nav .podmenu > [aria-expanded="true"] + ul { display: var(--ka-nav-panel, flex); }
@media (hover: hover) { .ka-nav .podmenu:hover > ul { display: var(--ka-nav-panel, flex); } }
.ka-nav li.podmenu.zavreno > ul { display: none; } /* Esc closed a submenu opened by focus or the mouse (web.js) */
/* icon before the label (Core\Menu), a group inside a submenu = a heading with its items (a column of the mega menu), a description under a mega menu item */
.ka-nav .menu-ikona { display: inline-block; width: 1.1em; height: 1.1em; margin-inline-end: 0.45em; vertical-align: -0.2em; }
.ka-nav .menu-sloupec > ul { flex-direction: column; flex-wrap: nowrap; gap: 2px; }
.ka-nav .menu-nadpis { display: block; padding: 0.55em 0.8em 0.25em; color: var(--ka-barva-tlumeny); font-size: 0.8em; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
.ka-nav .menu-popis { display: block; margin-block-start: 0.15em; color: var(--ka-barva-tlumeny); font-size: 0.85em; font-weight: 400; }
/* the language switcher in the navigation (image/web.css): the menu rules (.ka-nav a, .ka-nav ul) do not apply to it */
.ka-nav .ka-jazyky a { padding: 0.45em 0.6em; font-weight: 600; }
.ka-nav .ka-jazyky a[aria-current] { background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); }
.ka-nav .ka-jazyky-vyber [popover]:popover-open { display: flex; flex-direction: column; flex-wrap: nowrap; gap: 0; }
.ka-nav .ka-jazyky-vyber [popover] a { display: flex; padding: 0.55em 0.75em; border-radius: calc(var(--ka-zaobleni) / 1.5); font-weight: 500; }
.ka-nav .ka-jazyky-vyber [popover] a[aria-current] { background: var(--ka-barva-primarni-jemna); color: var(--ka-barva-primarni); font-weight: 600; }
.ka-nav-tl { display: none; }
.ka-nav-menu[popover] { position: static; inset: auto; width: auto; margin: 0; padding: 0; border: 0; background: none; color: inherit; overflow: visible; }
' . self::layoutCss();
    }

    /**
     * The wide submenu panel and the phone menu. The phone menu applies below 768 px, and with "Also on tablets" (3.5)
     * also from 768 to 1023 px – a long menu otherwise wraps into several rows around the logo on tablets.
     */
    private static function layoutCss(): string
    {
        $mega = <<<'CSS'
	/* the panel is centred under the whole navigation – aligning it to the menu edge pushed it off the page for a menu on the left or right */
	{M} { position: relative; }
	{M} .ka-nav-menu, {M} li.podmenu { position: static; }
	{M} .podmenu > ul { --ka-nav-panel: grid; left: 50%; right: auto; translate: -50% 0; width: min(56rem, 100vw - 2rem); padding: var(--ka-mezera-s); grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: var(--ka-mezera-2xs); }
	{M} .podmenu > ul a { padding: 0.8em 1em; }
	{M} .podmenu > ul > li:not(.menu-sloupec) { align-self: start; }
	{M} .menu-sloupec > ul a { padding: 0.55em 1em; }
CSS;
        $phone = <<<'CSS'
	{N}.ka-nav-tl { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; border: var(--ka-nav-tlacitko-okraj, 1px solid var(--ka-barva-linka)); border-radius: 50%; background: var(--ka-barva-pozadi); color: var(--ka-barva-text); cursor: pointer; }
	{N}.ka-nav-tl span, {N}.ka-nav-tl span::before, {N}.ka-nav-tl span::after { display: block; width: 1.1rem; height: 2px; background: currentColor; }
	{N}.ka-nav-tl span { position: relative; }
	{N}.ka-nav-tl span::before, {N}.ka-nav-tl span::after { content: ""; position: absolute; left: 0; }
	{N}.ka-nav-tl span::before { top: -6px; }
	{N}.ka-nav-tl span::after { top: 6px; }
	{N}.ka-nav-menu[popover] { position: fixed; inset: 4.5rem var(--ka-mezera-m) auto; flex-direction: column; align-items: stretch; padding: var(--ka-mezera-s); border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); box-shadow: var(--ka-stin-l); max-height: calc(100dvh - 5.5rem); overflow-y: auto; overscroll-behavior: contain; }
	{N}.ka-nav-menu[popover]:not(:popover-open) { display: none; }
	/* keyboard: Tab past the last menu item hides the open menu, so it does not cover the element the focus moved to (WCAG 2.4.11),
	   and it shows again when the focus comes back to the navigation; Esc or a click outside closes it completely (Popover API, no JavaScript) */
	:root:has(:focus-visible) .ka-nav{T}:not(:has(:focus-visible)) > .ka-nav-menu[popover]:popover-open { display: none; }
	/* 3.9.1: the rows of the sheet sit close together – the gap of the element (--ka-nav-mezera) belongs to the bar on a wide screen */
	{N}.ka-nav-menu[popover] ul { flex-direction: column; gap: 2px; }
	{N}.ka-nav-menu[popover] .podmenu > ul { display: flex; flex-basis: 100%; position: static; min-width: 0; padding: 0 0 0 1rem; border: 0; box-shadow: none; }
	/* 3.6: the groups are an accordion – closed, the one with the current page opened by image/web.js when the menu opens;
	   without the script every submenu stays expanded and the toggles, which would do nothing, are hidden */
	{N}.ka-nav-menu[popover] .podmenu > [aria-expanded="false"] + ul { display: none; }
	{N}.ka-nav-menu[popover] .podmenu > a, {N}.ka-nav-menu[popover] .podmenu > .menu-skupina { flex: 1; }
	{N}.ka-nav-menu[popover] .podmenu > .menu-skupina { display: flex; justify-content: space-between; align-items: center; min-height: 2.75rem; border-radius: calc(var(--ka-zaobleni) / 1.5); }
	/* 3.9.1: a group without a page has its arrow where a group with a page has its toggle – in the middle of the last 2.75rem */
	{N}.ka-nav-menu[popover] .podmenu > .menu-skupina::after { margin-inline: 0 calc(1.375rem - 0.2em); }
	{N}.ka-nav-menu[popover] .menu-rozbalit { min-width: 2.75rem; min-height: 2.75rem; margin: 0; border-radius: calc(var(--ka-zaobleni) / 1.5); }
	{N}.ka-nav-menu[popover] .menu-rozbalit:not([aria-expanded]), {N}.ka-nav-menu[popover] .podmenu > .menu-skupina:not([aria-expanded])::after { display: none; }
	{N}.ka-nav-menu[popover] .menu-sloupec > ul { padding-inline-start: 1rem; }
	{N}.ka-nav-menu[popover] .menu-popis { display: none; } /* the descriptions belong to the wide panel; on a phone the list stays short */
CSS;
        $panel = static fn (string $m): string => str_replace('{M}', $m, $mega);
        $sheet = static fn (string $n, string $t): string => str_replace(['{N}', '{T}'], [$n, $t], $phone);

        return "@media (min-width: 768px) {\n" . $panel('.ka-nav--mega:not(.ka-nav--tablet)') . "}\n"
            . "@media (min-width: 1024px) {\n" . $panel('.ka-nav--mega.ka-nav--tablet') . "}\n"
            . "@media (max-width: 767px) {\n" . $sheet('', '') . "}\n"
            . "@media (min-width: 768px) and (max-width: 1023px) {\n" . $sheet('.ka-nav--tablet ', '.ka-nav--tablet') . "}";
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $menu = $k->menu[$p['obsah']['menu'] ?? 'hlavni'] ?? [];
        if (!$p['obsah']['novinky']) {
            $menu = array_values(array_filter($menu, fn (array $x): bool => empty($x['auto'])));
        }
        $items = \Kaleta\Core\Menu::html($menu, $k->path, $k->url(''), !empty($p['obsah']['mega']));
        if ($items === '' && $k->editor) {
            $items = '<li><span>' . e(t('Build the menu in Appearance → Menu')) . '</span></li>';
        }
        $menu = '<ul>' . $items . '</ul>' . (($p['obsah']['jazyky'] ?? true) ? $k->languages : '') . $k->colorScheme;
        $classes = 'ka-nav' . (!empty($p['obsah']['mega']) ? ' ka-nav--mega' : '') . (($p['obsah']['zvyrazneni'] ?? '') === 'podtrzeni' ? ' ka-nav--podtrzeni' : '');
        if (!$p['obsah']['mobil']) {
            return '<nav' . Text::withClass($a, $classes) . ' aria-label="' . e(t('Main navigation')) . '"><div class="ka-nav-menu">' . $menu . '</div></nav>';
        }
        $id = 'ka-nav-' . $p['id'];
        if (!empty($p['obsah']['mobil_tablet'])) {
            $classes .= ' ka-nav--tablet';
        }

        return '<nav' . Text::withClass($a, $classes) . ' aria-label="' . e(t('Main navigation')) . '">'
            . '<button class="ka-nav-tl" type="button" popovertarget="' . e($id) . '" aria-label="' . e(t('Menu')) . '"><span aria-hidden="true"></span></button>'
            . '<div class="ka-nav-menu" id="' . e($id) . '" popover>' . $menu . '</div></nav>';
    }
}
