<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The text of a builder page for the search index (3.53, B5): what Core\BuildText writes (the page content kept in the
 * text column, llms.txt, the .md version and the API – they do not change) plus what a visitor reads on the page but that
 * content leaves out: the custom HTML element, image and gallery descriptions, the carousel name, rating, before/after
 * and progress labels, an icon's description, an embed's title, the enquiry button and the text inside components (the
 * published build with the values of that use). Every element type has a decision in DECISIONS (a unit test holds that
 * for every type in Builder\Build::ELEMENTS). Lives in Core because the index job (Core\SearchIndex) uses it.
 */
final class SearchText
{
    /**
     * The decision for every element type: 'content' = the text Core\BuildText writes, 'more' = that and what this class
     * adds, 'children' = only the elements inside, 'none' = no text (a form, map, countdown, lists the system fills in,
     * parts-only elements; the inside of a Collection list is a pattern with {{tags}}, not page content).
     */
    public const array DECISIONS = [
        'sekce' => 'children', 'kontejner' => 'children', 'mrizka' => 'children',
        'nadpis' => 'content', 'text' => 'content', 'obrazek' => 'more', 'tlacitko' => 'content', 'seznam' => 'content',
        'citat' => 'content', 'faq' => 'content', 'video' => 'content', 'oddelovac' => 'content',
        'ikona' => 'more', 'galerie' => 'more', 'zalozky' => 'content', 'karusel' => 'more', 'cenik' => 'content', 'pred_po' => 'more',
        'hotspoty' => 'content', 'casova_osa' => 'content',
        'mapa' => 'none', 'vlozeni' => 'more', 'drobecky' => 'none',
        'pocitadlo' => 'content', 'prubeh' => 'more', 'hodnoceni' => 'more', 'odpocet' => 'none', 'socialni' => 'none', 'hledani' => 'none',
        'novinky' => 'none', 'recenze_google' => 'none', 'kolekce' => 'none', 'predchozi_dalsi' => 'none', 'do_poptavky' => 'more',
        'pobocky' => 'none', 'formular' => 'none', 'rezervace' => 'none', 'newsletter' => 'none', 'komponenta' => 'more', 'html' => 'more',
        'nahoru' => 'none',
        'logo' => 'none', 'navigace' => 'none', 'jazyky' => 'none', 'udaje' => 'none', 'obsah' => 'none',
    ];

    /** A component inside a component… at most this deep (Builder\Components::MAX_NESTING, as when rendering). */
    public const int MAX_NESTING = 4;

    /**
     * The search text of a build as HTML (the index makes plain text of it). $component = fn (int $idm): ?array giving a
     * component's published build (array) and its properties (list of klic/typ/vychozi) as ['stavba' => …, 'vlastnosti' => …],
     * or null when there is none; without it components add nothing.
     *
     * @param array<string, mixed> $build
     */
    public static function of(array $build, ?\Closure $component = null): string
    {
        return trim(self::walk(is_array($build['deti'] ?? null) ? $build['deti'] : [], $component, []));
    }

    /**
     * @param list<mixed> $children
     * @param list<int> $nesting the components being walked (a component inside itself would never end)
     */
    private static function walk(array $children, ?\Closure $component, array $nesting): string
    {
        $html = '';
        foreach ($children as $p) {
            if (!is_array($p)) {
                continue;
            }
            $type = (string) ($p['typ'] ?? '');
            $o = is_array($p['obsah'] ?? null) ? $p['obsah'] : [];
            $decision = self::DECISIONS[$type] ?? 'none';
            if ($decision === 'content' || $decision === 'more') {
                $html .= BuildText::of(['deti' => [['deti' => []] + $p]]) . "\n";
            }
            if ($decision === 'more') {
                $html .= $type === 'komponenta' ? self::component($o, $component, $nesting) : self::more($type, $o);
            }
            if ($type !== 'kolekce' && is_array($p['deti'] ?? null)) {
                $html .= self::walk($p['deti'], $component, $nesting);
            }
        }

        return $html;
    }

    /**
     * What the index adds to the content of one element.
     *
     * @param array<string, mixed> $o
     */
    private static function more(string $type, array $o): string
    {
        $s = fn (string $key): string => is_scalar($o[$key] ?? null) ? trim((string) $o[$key]) : '';
        $rows = fn (string $key): array => array_values(array_filter(is_array($o[$key] ?? null) ? $o[$key] : [], 'is_array'));
        $texts = match ($type) {
            'obrazek' => [$s('alt')], // the caption is content already
            'galerie' => [...array_map(fn (array $f): string => is_scalar($f['alt'] ?? null) ? (string) $f['alt'] : '', $rows('fotky')), $s('popisek')],
            'karusel', 'ikona' => [$s('popis')],
            'pred_po' => [$s('popisek_pred'), $s('alt_pred'), $s('popisek_po'), $s('alt_po')],
            'prubeh' => array_map(fn (array $r): string => trim((is_scalar($r['nazev'] ?? null) ? (string) $r['nazev'] : '') . ' ' . (is_scalar($r['hodnota'] ?? null) ? $r['hodnota'] . ' %' : '')), $rows('polozky')),
            'hodnoceni' => [trim($s('hodnota') . ' ' . $s('text'))],
            'vlozeni' => [$s('titulek')],
            'do_poptavky' => [$s('text')],
            'html' => [self::code($s('kod'))],
            default => [],
        };
        $texts = array_filter(array_map('trim', $texts), fn (string $t): bool => $t !== '');

        return $texts === [] ? '' : '<p>' . implode('</p><p>', array_map(fn (string $t): string => $type === 'html' ? $t : e($t), $texts)) . "</p>\n";
    }

    /** The words of custom HTML: no script or style, a tag is a word boundary (cells and items do not glue together). */
    private static function code(string $html): string
    {
        $html = (string) preg_replace('#<(script|style|template)\b[^>]*>.*?</\1\s*>#is', ' ', $html);

        return e(trim((string) preg_replace('/\s+/u', ' ', html_entity_decode((string) preg_replace('/<[^>]*>/', ' ', $html), ENT_QUOTES | ENT_HTML5, 'UTF-8'))));
    }

    /**
     * The text of a component where it is used: its published build with this use's values in place of {{tags}} (the
     * defaults where the use leaves a value empty; an image or link value is not text). A tag without a value is dropped,
     * as when rendering.
     *
     * @param array<string, mixed> $o
     * @param list<int> $nesting
     */
    private static function component(array $o, ?\Closure $component, array $nesting): string
    {
        $id = is_scalar($o['komponenta'] ?? null) ? (int) $o['komponenta'] : 0;
        if ($component === null || $id <= 0 || in_array($id, $nesting, true) || count($nesting) >= self::MAX_NESTING) {
            return '';
        }
        $found = $component($id);
        if (!is_array($found) || !is_array($found['stavba'] ?? null)) {
            return '';
        }
        $text = self::walk(is_array($found['stavba']['deti'] ?? null) ? $found['stavba']['deti'] : [], $component, [...$nesting, $id]);
        $given = is_array($o['hodnoty'] ?? null) ? $o['hodnoty'] : [];
        $values = [];
        foreach (is_array($found['vlastnosti'] ?? null) ? $found['vlastnosti'] : [] as $v) {
            $key = is_array($v) ? (string) ($v['klic'] ?? '') : '';
            if ($key === '' || in_array($v['typ'] ?? 'text', ['obrazek', 'odkaz'], true)) {
                continue;
            }
            $value = is_scalar($given[$key] ?? null) && trim((string) $given[$key]) !== '' ? (string) $given[$key] : (is_scalar($v['vychozi'] ?? null) ? (string) $v['vychozi'] : '');
            $values[$key] = ($v['typ'] ?? 'text') === 'html' ? ' ' . $value . ' ' : e($value);
        }

        // the same tag as Builder\Collections::PLACEHOLDER_PATTERN
        return (string) preg_replace_callback('/\{\{([a-z][a-z0-9_]{0,30})\}\}/', fn (array $m): string => $values[$m[1]] ?? '', $text);
    }
}
