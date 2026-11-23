<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Search field: a form to /hledani (pages, collection items and news; regardless of diacritics). The "icon" appearance (3.52)
 * shows only a magnifier that opens the field as a popover (Popover API – no script, Esc and a click outside close it) – for a header.
 */
final class Search extends Element
{
    public const string TYPE = 'hledani';
    public const string NAME = 'Vyhledávání';
    public const string DESCRIPTION = 'A website search field – for the header, the 404 page or a listing.';
    public const string ICON = 'hledat';
    public const array HTML_TAGS = ['form'];

    public static function properties(): array
    {
        return [
            'napoveda' => ['typ' => 'text', 'popisek' => 'Placeholder text', 'vychozi' => t('Search the website…'), 'max' => 80],
            'tlacitko' => ['typ' => 'text', 'popisek' => 'Button', 'vychozi' => t('Hledat'), 'max' => 40],
            'podoba' => ['typ' => 'vyber', 'popisek' => 'Appearance', 'vychozi' => 'pole', 'moznosti' => ['pole' => 'Field with a button', 'ikona' => 'Icon that opens the field']],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-hledani { display: flex; gap: var(--ka-mezera-xs); max-width: 32rem; }
.ka-hledani input { flex: 1; min-width: 0; padding: 0.6em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: inherit; font: inherit; }
.ka-hledani button { padding: 0.6em 1.1em; border: 0; border-radius: var(--ka-zaobleni); background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); font: inherit; font-weight: 600; cursor: pointer; }
/* the icon appearance: a round magnifier button, the field hangs below it (anchor positioning; without it at the top right of the page);
   when it would leave the screen, it hangs from the other edge of the icon, and failing that from the edge of the screen */
.ka-hledani--ikona { display: inline-flex; max-width: none; }
.ka-hledani--ikona > .ka-hledani-tl { display: grid; place-items: center; width: 2.75rem; height: 2.75rem; padding: 0; border: 1px solid var(--ka-barva-linka); border-radius: 50%; background: var(--ka-barva-pozadi); color: var(--ka-barva-text); }
.ka-hledani--ikona > .ka-hledani-tl:focus-visible { outline: 3px solid var(--ka-barva-sekundarni); outline-offset: 2px; }
.ka-hledani--ikona > [popover] { position: fixed; inset: auto; top: anchor(bottom); right: anchor(right); width: min(26rem, 100vw - 2rem); position-try-fallbacks: flip-inline, --ka-hledani-okraj; margin: 6px 0 0; padding: var(--ka-mezera-xs); gap: var(--ka-mezera-xs); border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: var(--ka-barva-text); box-shadow: var(--ka-stin-m); }
.ka-hledani--ikona > [popover]:popover-open { display: flex; }
@position-try --ka-hledani-okraj { top: anchor(bottom); right: auto; left: 1rem; }
@supports not (top: anchor(bottom)) { .ka-hledani--ikona > [popover] { top: 4.5rem; right: 1rem; } }';
    }

    public static function rtlCss(): string
    {
        // a fixed popover has no logical side for anchor(): on a right-to-left page the field hangs from the other edge of the icon
        return '.ka-hledani--ikona > [popover] { right: auto; left: anchor(left); }
@supports not (top: anchor(bottom)) { .ka-hledani--ikona > [popover] { right: auto; left: 1rem; } }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $id = 'hl-' . $p['id'];
        $icon = ($o['podoba'] ?? 'pole') === 'ikona';
        $field = '<label class="ka-jen-ctecka" for="' . e($id) . '">' . e(t('Search the website')) . '</label>'
            . '<input type="search" id="' . e($id) . '" name="q" minlength="' . \Kaleta\Core\SiteSearch::MIN_LENGTH . '" maxlength="100" placeholder="' . e($o['napoveda']) . '" required'
            . ($icon ? ' autofocus' : '') . '>' // autofocus inside a popover = the field gets the focus when the popover opens
            . '<button type="submit">' . e($o['tlacitko']) . '</button>';
        $open = '<form' . Text::withClass($a, $icon ? 'ka-hledani ka-hledani--ikona' : 'ka-hledani') . ' role="search" method="get" action="' . e($k->url('hledani')) . '">';
        if (!$icon) {
            return $open . $field . '</form>';
        }
        // the controls in the popover still belong to the form (the top layer does not change the form owner)
        $popover = $id . '-pole';

        return $open . '<button type="button" class="ka-hledani-tl" popovertarget="' . e($popover) . '" style="anchor-name: --' . e($popover) . '" aria-label="' . e(t('Search the website')) . '">'
            . '<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></button>'
            . '<div id="' . e($popover) . '" popover style="position-anchor: --' . e($popover) . '">' . $field . '</div></form>';
    }
}
