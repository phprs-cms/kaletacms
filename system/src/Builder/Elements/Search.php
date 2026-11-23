<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/** Search field: a form to /hledani (pages, collection items and news; regardless of diacritics). */
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
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-hledani { display: flex; gap: var(--ka-mezera-xs); max-width: 32rem; }
.ka-hledani input { flex: 1; min-width: 0; padding: 0.6em 0.9em; border: 1px solid var(--ka-barva-linka); border-radius: var(--ka-zaobleni); background: var(--ka-barva-pozadi); color: inherit; font: inherit; }
.ka-hledani button { padding: 0.6em 1.1em; border: 0; border-radius: var(--ka-zaobleni); background: var(--ka-barva-primarni); color: var(--ka-barva-na-primarni); font: inherit; font-weight: 600; cursor: pointer; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $id = 'hl-' . $p['id'];

        return '<form' . Text::withClass($a, 'ka-hledani') . ' role="search" method="get" action="' . e($k->url('hledani')) . '">'
            . '<label class="ka-jen-ctecka" for="' . e($id) . '">' . e(t('Search the website')) . '</label>'
            . '<input type="search" id="' . e($id) . '" name="q" minlength="' . \Kaleta\Core\SiteSearch::MIN_LENGTH . '" maxlength="100" placeholder="' . e($o['napoveda']) . '" required>'
            . '<button type="submit">' . e($o['tlacitko']) . '</button></form>';
    }
}
