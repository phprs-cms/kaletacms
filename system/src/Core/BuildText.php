<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The content of a build (the JSON tree of builder elements) as plain semantic HTML without layout and style: headings,
 * paragraphs, lists, links, images (3.53, A3 – moved here from Builder\Build::asText, which calls it). On publish it is
 * saved into the text column of a page – search, llms.txt, the .md version, the API, MCP and export draw on it – and the
 * search index makes the text of a page from it (Core\SearchIndex). Pure: reads only the tree.
 */
final class BuildText
{
    /** @param array<string, mixed> $build */
    public static function of(array $build): string
    {
        $html = '';
        $walk = function (array $children) use (&$walk, &$html): void {
            foreach ($children as $p) {
                $o = $p['obsah'] ?? [];
                $z = preg_match('/^h[1-6]$/', $p['znacka'] ?? '') ? $p['znacka'] : 'p';
                $html .= match ($p['typ'] ?? '') {
                    'nadpis' => "<{$z}>" . ($o['text'] ?? '') . "</{$z}>\n",
                    'text' => ($o['html'] ?? '') . "\n",
                    'obrazek' => ($o['src'] ?? '') !== '' ? '<figure><img src="' . e($o['src']) . '" alt="' . e($o['alt'] ?? '') . '">' . (($o['popisek'] ?? '') !== '' ? '<figcaption>' . e($o['popisek']) . '</figcaption>' : '') . "</figure>\n" : '',
                    'tlacitko' => ($o['text'] ?? '') !== '' ? '<p>' . (($o['odkaz'] ?? '') !== '' ? '<a href="' . e($o['odkaz']) . '">' . e($o['text']) . '</a>' : e($o['text'])) . "</p>\n" : '',
                    'seznam' => ($rows = array_filter(array_map('trim', explode("\n", (string) ($o['polozky'] ?? ''))))) !== []
                        ? "<{$p['znacka']}>" . implode('', array_map(fn (string $r): string => '<li>' . e($r) . '</li>', $rows)) . "</{$p['znacka']}>\n" : '',
                    'citat' => '<blockquote><p>' . ($o['text'] ?? '') . '</p>' . (($o['autor'] ?? '') !== '' ? '<p>– ' . e($o['autor']) . (($o['pozice'] ?? '') !== '' ? ', ' . e($o['pozice']) : '') . '</p>' : '') . "</blockquote>\n",
                    'faq' => implode('', array_map(fn (array $f): string => '<h3>' . e($f['otazka'] ?? '') . '</h3>' . ($f['odpoved'] ?? '') . "\n", $o['polozky'] ?? [])),
                    // 3.33: the content of tabs is page content too (an import keeps it, search sees it)
                    'zalozky' => implode('', array_map(fn (array $t): string => '<h3>' . e($t['nazev'] ?? '') . '</h3>' . ($t['obsah'] ?? '') . "\n", $o['karty'] ?? [])),
                    'video' => ($o['url'] ?? '') !== '' ? '<p><a href="' . e($o['url']) . '">' . e(($o['titulek'] ?? '') !== '' ? $o['titulek'] : $o['url']) . "</a></p>\n" : '',
                    'oddelovac' => "<hr>\n",
                    // the counter states a number (or a fact token, 2.10) with its label – content, so facts see it too
                    'pocitadlo' => (string) ($o['cislo'] ?? '') !== '' ? '<p>' . e((string) ($o['pred'] ?? '') . (string) $o['cislo'] . (string) ($o['za'] ?? '')) . ((string) ($o['popisek'] ?? '') !== '' ? ' ' . e((string) $o['popisek']) : '') . "</p>\n" : '',
                    // 2.12: plans, points and milestones are content too – search and the .md version see the prices and features
                    'cenik' => implode('', array_map(fn (array $plan): string => ($plan['nazev'] ?? '') === '' ? '' : '<h3>' . e($plan['nazev']) . '</h3>'
                        . (($plan['cena'] ?? '') !== '' ? '<p>' . e(trim($plan['cena'] . ' ' . ($plan['obdobi'] ?? ''))) . '</p>' : '') . (($plan['popis'] ?? '') !== '' ? '<p>' . e($plan['popis']) . '</p>' : '')
                        . (($rows = self::features((string) ($plan['funkce'] ?? ''))) !== [] ? '<ul>' . implode('', array_map(fn (array $f): string => '<li>' . e($f[1]) . ($f[0] ? '' : ' (' . e(t('not included')) . ')') . '</li>', $rows)) . '</ul>' : '') . "\n", $o['plany'] ?? [])),
                    'hotspoty' => ($rows = array_filter($o['body'] ?? [], fn (array $b): bool => ($b['nazev'] ?? '') !== '')) !== []
                        ? '<ol>' . implode('', array_map(fn (array $b): string => '<li>' . e($b['nazev']) . (($b['popis'] ?? '') !== '' ? ' – ' . e($b['popis']) : '') . '</li>', $rows)) . "</ol>\n" : '',
                    'casova_osa' => implode('', array_map(fn (array $m): string => ($m['nazev'] ?? '') === '' && ($m['datum'] ?? '') === '' ? '' : '<h3>' . e(implode(' – ', array_filter([$m['datum'] ?? '', $m['nazev'] ?? ''], fn (string $s): bool => $s !== ''))) . '</h3>' . ($m['obsah'] ?? '') . "\n", $o['udalosti'] ?? [])),
                    default => '',
                };
                // the inside of a Collection list is a pattern with {{tags}}, not page content
                if (is_array($p['deti'] ?? null) && ($p['typ'] ?? '') !== 'kolekce') {
                    $walk($p['deti']);
                }
            }
        };
        $walk($build['deti'] ?? []);

        return trim($html);
    }

    /**
     * The features of a pricing plan as [included, text] – a line starting with "-" is not included (the element
     * Builder\Elements\PricingTable reads them the same way).
     *
     * @return list<array{0: bool, 1: string}>
     */
    public static function features(string $lines): array
    {
        $features = [];
        foreach (array_filter(array_map('trim', explode("\n", $lines)), fn (string $l): bool => $l !== '') as $line) {
            $excluded = str_starts_with($line, '-');
            $text = trim($excluded ? substr($line, 1) : $line);
            if ($text !== '') {
                $features[] = [!$excluded, $text];
            }
        }

        return $features;
    }
}
