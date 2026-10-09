<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * "Add to enquiry" (2.11, Builder\Products): on a product's page or card, a variant, a quantity and a button that puts the
 * product into the visitor's enquiry basket (their browser only), and a box to compare it with others. The basket is sent
 * with a Form that has a basket field – by default on the products list page (#poptavka).
 *
 * Without JavaScript the button opens the basket page with the product in the address, and the basket field takes it from
 * there; with it the product goes to the basket and the visitor stays on the page.
 */
final class EnquiryButton extends Element
{
    public const string TYPE = 'do_poptavky';
    public const string NAME = 'Add to enquiry';
    public const string DESCRIPTION = 'A product\'s variant, quantity and a button that adds it to the enquiry basket, with a box to compare products.';
    public const string ICON = 'kosik';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['form'];

    public static function properties(): array
    {
        return [
            'text' => ['typ' => 'text', 'popisek' => 'Button text', 'vychozi' => t('Add to enquiry'), 'max' => 60],
            'kosik' => ['typ' => 'odkaz', 'popisek' => 'Page with the enquiry form (empty = the products list page)', 'vychozi' => ''],
            'mnozstvi' => ['typ' => 'prepinac', 'popisek' => 'Quantity', 'vychozi' => true],
            'porovnani' => ['typ' => 'prepinac', 'popisek' => 'Compare box', 'vychozi' => true],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-do-poptavky { display: flex; flex-wrap: wrap; align-items: end; gap: var(--ka-mezera-s); }
.ka-do-poptavky label { display: grid; gap: 0.25em; font-size: 0.9em; }
.ka-do-poptavky input[type=number] { width: 6em; }
.ka-do-poptavky .ka-porovnat { display: flex; align-items: center; gap: 0.4em; }
.ka-do-poptavky-stav { flex-basis: 100%; margin: 0; font-size: 0.9em; }
.ka-do-poptavky-stav:empty { display: none; }
.ka-porovnani { width: 100%; border-collapse: collapse; }
.ka-porovnani th, .ka-porovnani td { padding: 0.6em 0.8em; border-bottom: 1px solid var(--ka-barva-linka); text-align: left; vertical-align: top; }
.ka-porovnani thead img { display: block; width: 100%; max-width: 12rem; height: auto; margin-bottom: 0.5em; }
.ka-porovnani-obal { overflow-x: auto; }
.ka-porovnani-stranka { display: grid; gap: var(--ka-mezera-m); max-width: var(--ka-sirka); margin-inline: auto; padding: var(--ka-mezera-xl) var(--ka-mezera-m); }
.ka-lista-porovnani { position: fixed; inset: auto 1rem 1rem auto; z-index: 60; display: flex; gap: 0.5em; align-items: center; padding: 0.5em 0.75em; border-radius: var(--ka-zaobleni-m);
	background: var(--ka-barva-text); color: var(--ka-barva-pozadi); box-shadow: var(--ka-stin-m); font-size: 0.9em; }
.ka-lista-porovnani a, .ka-lista-porovnani button { color: inherit; font: inherit; }
.ka-lista-porovnani button { background: none; border: 0; text-decoration: underline; cursor: pointer; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $product = json_decode((string) ($k->item['_product'][0] ?? ''), true);
        if (!is_array($product)) {
            return $k->editor ? '<p' . $a . '><small>' . e(t('Add to enquiry – shows on the pages and cards of a products collection.')) . '</small></p>' : '';
        }
        $basket = (string) $o['kosik'] !== '' ? (string) $o['kosik'] : $k->url((string) $product['c']) . '#poptavka';
        $id = 'p-' . $p['id'] . '-' . substr(md5((string) $product['i']), 0, 6); // unique also in a list of several products
        $variants = (array) ($product['v'] ?? []);
        $html = '<input type="hidden" name="product" value="' . e($product['c'] . '/' . $product['i']) . '">';
        if ($variants !== []) {
            $html .= '<label for="' . $id . '-v">' . e(t('Variant')) . '<select id="' . $id . '-v" name="variant">'
                . implode('', array_map(fn (mixed $v): string => '<option>' . e((string) $v) . '</option>', $variants)) . '</select></label>';
        }
        if ($o['mnozstvi']) {
            $html .= '<label for="' . $id . '-q">' . e(t('Quantity')) . '<input id="' . $id . '-q" name="quantity" type="number" value="1" min="1" max="9999" inputmode="numeric"></label>';
        }
        $html .= '<button class="ka-tlacitko ka-tlacitko--primarni" type="submit">' . e($o['text']) . '</button>';
        if ($o['porovnani']) {
            $html .= '<label class="ka-porovnat"><input type="checkbox" data-porovnat> ' . e(t('Compare')) . '</label>';
        }
        $k->types['tlacitko'] = true;

        return '<form' . Text::withClass($a, 'ka-do-poptavky') . ' method="get" action="' . e(preg_replace('/#.*$/', '', $basket) ?? $basket) . '" data-produkt="' . e((string) json_encode(
            ['c' => $product['c'], 'i' => $product['i'], 'n' => $product['n']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) . '" data-kosik="' . e($basket) . '" data-porovnani="' . e($k->url($product['c'] . '/_compare')) . '">'
            . $html . '<p class="ka-do-poptavky-stav" role="status" aria-live="polite"></p></form>';
    }
}
