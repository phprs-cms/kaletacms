<?php

declare(strict_types=1);

namespace Kaleta\Builder\Elements;

use Kaleta\Builder\CollectionCategories;
use Kaleta\Builder\Collections;
use Kaleta\Builder\Context;
use Kaleta\Builder\Element;

/**
 * Previous / next item (3.7): links to the neighbouring items on an item page of a collection – in the order of the
 * administration or by date, within the item's category when it has one. A nav landmark with rel="prev" and rel="next"
 * links, optionally with thumbnails. Anywhere else than in an item template it renders nothing.
 */
final class ItemNavigation extends Element
{
    public const string TYPE = 'predchozi_dalsi';
    public const string NAME = 'Previous / next item';
    public const string DESCRIPTION = 'Links to the previous and the next item on an item page (references, products, projects) – in the collection order or by date, within the item’s category.';
    public const string ICON = 'predchozi-dalsi';
    public const string GROUP = 'Dynamic';
    public const array HTML_TAGS = ['nav'];

    public static function properties(): array
    {
        return [
            'podle' => ['typ' => 'vyber', 'popisek' => 'Order', 'vychozi' => 'poradi', 'moznosti' => ['poradi' => 'as in the administration (order, then name)', 'datum' => 'by date (previous = older)']],
            'stejna_kategorie' => ['typ' => 'prepinac', 'popisek' => 'Only within the item’s category (when it has one)', 'vychozi' => true],
            'popisek_predchozi' => ['typ' => 'text', 'popisek' => 'Label of the previous item', 'vychozi' => t('Previous'), 'max' => 60],
            'popisek_dalsi' => ['typ' => 'text', 'popisek' => 'Label of the next item', 'vychozi' => t('Next'), 'max' => 60],
            'nazvy' => ['typ' => 'prepinac', 'popisek' => 'Show the item names', 'vychozi' => true],
            'nahledy' => ['typ' => 'prepinac', 'popisek' => 'Show thumbnails', 'vychozi' => false],
            'pole_obrazku' => ['typ' => 'text', 'popisek' => 'Thumbnail field (key of an image field; empty = the first image field)', 'vychozi' => '', 'max' => 31],
        ];
    }

    public static function baseCss(): string
    {
        return '.ka-predchozi-dalsi { display: flex; flex-wrap: wrap; justify-content: space-between; gap: var(--ka-mezera-m); padding-block-start: var(--ka-mezera-m); border-block-start: 1px solid var(--ka-barva-linka); }
.ka-predchozi-dalsi a { display: flex; align-items: center; gap: var(--ka-mezera-s); max-width: min(100%, 28rem); color: inherit; text-decoration: none; }
.ka-predchozi-dalsi a:hover .ka-pd-nazev, .ka-predchozi-dalsi a:focus-visible .ka-pd-nazev { text-decoration: underline; }
.ka-predchozi-dalsi .ka-pd-dalsi { margin-inline-start: auto; flex-direction: row-reverse; text-align: end; }
.ka-predchozi-dalsi img { flex: none; width: 4.5rem; height: 4.5rem; object-fit: cover; border-radius: var(--ka-zaobleni); }
.ka-pd-text { display: grid; gap: 0.15em; }
.ka-pd-popisek { color: var(--ka-barva-tlumeny); font-size: var(--ka-krok--1); }
.ka-pd-nazev { font-weight: 600; }';
    }

    public static function render(array $p, string $a, string $children, Context $k): string
    {
        $o = $p['obsah'];
        $page = $k->itemPage;
        if ($page === null || !$page['kolekce']['detail']) {
            if (!$k->editor) {
                return '';
            }
            // the template in the builder (a sample item, or not an item template at all): what the links will look like
            $sample = ['nazev' => '[' . t('Item name') . ']', 'seo_link' => '', 'data' => [], 'obrazek' => ''];

            return self::html($a, $o, $sample, $sample, '#', '#', '', '', $k);
        }
        $db = $k->app->db();
        $collection = $page['kolekce'];
        $item = $page['polozka'];
        $categories = null;
        if (!empty($o['stejna_kategorie']) && ($first = CollectionCategories::ofItem($db, (int) $item['idp'], true)[0] ?? null) !== null) {
            $categories = CollectionCategories::withChildren($db, $first);
        }
        [$previous, $next] = Collections::neighbours($db, $item, (string) $o['podle'], $categories);
        if ($previous === null && $next === null) {
            return '';
        }
        $url = fn (?array $row): string => $row === null ? '' : $k->url($collection['seo_link'] . '/' . $row['seo_link']);

        return self::html($a, $o, $previous, $next, $url($previous), $url($next), self::thumbnail($collection, $previous, $o), self::thumbnail($collection, $next, $o), $k);
    }

    /** The image of a neighbour: the chosen image field, the first image field, or the item's sharing image. */
    private static function thumbnail(array $collection, ?array $row, array $o): string
    {
        if ($row === null || empty($o['nahledy'])) {
            return '';
        }
        $data = is_array($row['data']) ? $row['data'] : (json_decode((string) $row['data'], true) ?: []);
        $fields = array_values(array_filter($collection['pole'], fn (array $f): bool => $f['typ'] === 'obrazek'));
        $key = (string) $o['pole_obrazku'];
        $chosen = array_values(array_filter($fields, fn (array $f): bool => $f['klic'] === $key))[0] ?? $fields[0] ?? null;
        $src = $chosen !== null ? (string) ($data[$chosen['klic']] ?? '') : '';
        $src = $src !== '' ? $src : (string) ($row['obrazek'] ?? '');

        return $src !== '' && preg_match(Collections::MEDIA_PATTERN, $src) === 1 ? $src : '';
    }

    private static function html(string $a, array $o, ?array $previous, ?array $next, string $previousUrl, string $nextUrl, string $previousImage, string $nextImage, Context $k): string
    {
        $link = function (array $row, string $url, string $image, string $rel, string $label, string $arrow) use ($o, $k): string {
            $text = '<span class="ka-pd-text"><span class="ka-pd-popisek">' . ($rel === 'prev' ? '<span aria-hidden="true">' . $arrow . '</span> ' . e($label) : e($label) . ' <span aria-hidden="true">' . $arrow . '</span>') . '</span>'
                . (!empty($o['nazvy']) ? '<span class="ka-pd-nazev">' . e((string) $row['nazev']) . '</span>' : '') . '</span>';

            return '<a class="ka-pd-' . ($rel === 'prev' ? 'predchozi' : 'dalsi') . '" href="' . e($url) . '" rel="' . $rel . '"'
                . (empty($o['nazvy']) ? ' aria-label="' . e($label . ': ' . $row['nazev']) . '"' : '') . '>'
                . ($image !== '' ? '<img src="' . e($k->image($image)) . '" alt="" loading="lazy" decoding="async">' : '') . $text . '</a>';
        };

        $rtl = \Kaleta\Core\Language::isRtl(\Kaleta\Core\Language::code()); // the previous item lies to the right in Arabic

        return '<nav' . Text::withClass($a, 'ka-predchozi-dalsi') . ' aria-label="' . e(t('Previous and next item')) . '">'
            . ($previous !== null ? $link($previous, $previousUrl, $previousImage, 'prev', (string) $o['popisek_predchozi'], $rtl ? '→' : '←') : '')
            . ($next !== null ? $link($next, $nextUrl, $nextImage, 'next', (string) $o['popisek_dalsi'], $rtl ? '←' : '→') : '')
            . '</nav>';
    }
}
