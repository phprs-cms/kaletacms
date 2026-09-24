<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Nahrané SVG (loga, ikony): vyčistí se na seznam povolených značek a atributů. Zmizí skripty, obsluhy událostí,
 * foreignObject, odkazy mimo soubor a vložené HTML – zůstane jen kresba. Server ho navíc posílá s přísnou CSP (media/.htaccess).
 */
final class Svg
{
    private const array ZNACKY = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs', 'lineargradient',
        'radialgradient', 'stop', 'clippath', 'mask', 'title', 'desc', 'use', 'symbol', 'pattern'];

    private const array ATRIBUTY = ['id', 'class', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points',
        'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-opacity', 'opacity', 'transform', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod',
        'fx', 'fy', 'clip-path', 'mask', 'font-family', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'preserveaspectratio',
        'xmlns', 'version', 'href', 'xlink:href', 'style', 'role', 'aria-label', 'aria-hidden', 'focusable', 'patternunits', 'vector-effect', 'color'];

    /** Vyčištěné SVG, nebo null, když soubor SVG není. */
    public static function vycisti(string $svg): ?string
    {
        if (strlen($svg) > 2_000_000 || !str_contains($svg, '<svg')) {
            return null;
        }
        $dom = new \DOMDocument();
        $puvodni = libxml_use_internal_errors(true);
        // bez DTD a externích entit (XXE) a bez sítě
        $ok = $dom->loadXML(preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg) ?? '', LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_use_internal_errors($puvodni);
        if (!$ok || $dom->documentElement === null || strtolower($dom->documentElement->localName) !== 'svg') {
            return null;
        }
        self::uzel($dom->documentElement);
        $vystup = $dom->saveXML($dom->documentElement);

        return $vystup === false ? null : $vystup;
    }

    /** @return array{0: int, 1: int} šířka a výška podle width/height nebo viewBox (0 = neznámé) */
    public static function rozmery(string $svg): array
    {
        if (preg_match('/<svg[^>]*\bviewBox="[-\d.]+[ ,]+[-\d.]+[ ,]+([\d.]+)[ ,]+([\d.]+)"/i', $svg, $m)) {
            return [(int) round((float) $m[1]), (int) round((float) $m[2])];
        }
        if (preg_match('/<svg[^>]*\bwidth="([\d.]+)(px)?"[^>]*\bheight="([\d.]+)(px)?"/i', $svg, $m)) {
            return [(int) $m[1], (int) $m[3]];
        }

        return [0, 0];
    }

    private static function uzel(\DOMElement $el): void
    {
        foreach (iterator_to_array($el->childNodes) as $dite) {
            if ($dite instanceof \DOMElement) {
                if (!in_array(strtolower($dite->localName), self::ZNACKY, true)) {
                    $el->removeChild($dite);
                    continue;
                }
                self::uzel($dite);
            } elseif ($dite instanceof \DOMProcessingInstruction || $dite instanceof \DOMCdataSection) {
                $el->removeChild($dite);
            }
        }
        foreach (iterator_to_array($el->attributes) as $atr) {
            $nazev = strtolower($atr->nodeName);
            $hodnota = $atr->nodeValue ?? '';
            $zly = !in_array($nazev, self::ATRIBUTY, true)
                || (in_array($nazev, ['href', 'xlink:href'], true) && !str_starts_with($hodnota, '#'))
                || preg_match('/javascript:|expression\(|@import|url\((?!\s*[\'"]?#)/i', $hodnota);
            if ($zly) {
                $el->removeAttributeNode($atr);
            }
        }
    }
}
