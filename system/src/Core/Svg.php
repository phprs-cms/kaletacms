<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Uploaded SVG (logos, icons): it is cleaned down to a list of allowed tags and attributes. Scripts, event handlers,
 * foreignObject, links outside the file and embedded HTML disappear – only the drawing remains. The server also sends it
 * with a strict CSP (media/.htaccess).
 */
final class Svg
{
    private const array HTML_TAGS = ['svg', 'g', 'path', 'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'text', 'tspan', 'defs', 'lineargradient',
        'radialgradient', 'stop', 'clippath', 'mask', 'title', 'desc', 'use', 'symbol', 'pattern'];

    private const array ATTRIBUTES = ['id', 'class', 'viewbox', 'width', 'height', 'x', 'y', 'x1', 'y1', 'x2', 'y2', 'cx', 'cy', 'r', 'rx', 'ry', 'd', 'points',
        'fill', 'fill-opacity', 'fill-rule', 'clip-rule', 'stroke', 'stroke-width', 'stroke-linecap', 'stroke-linejoin', 'stroke-miterlimit', 'stroke-dasharray',
        'stroke-dashoffset', 'stroke-opacity', 'opacity', 'transform', 'offset', 'stop-color', 'stop-opacity', 'gradientunits', 'gradienttransform', 'spreadmethod',
        'fx', 'fy', 'clip-path', 'mask', 'font-family', 'font-size', 'font-weight', 'text-anchor', 'dominant-baseline', 'letter-spacing', 'preserveaspectratio',
        'xmlns', 'version', 'href', 'xlink:href', 'style', 'role', 'aria-label', 'aria-hidden', 'focusable', 'patternunits', 'vector-effect', 'color'];

    /**
     * The cleaned SVG, or null when the file is not an SVG.
     *
     * @throws HtmlTooLarge when it is over a limit of HtmlLimits (nested too deep, too many attributes): refused, never parsed
     */
    public static function sanitize(string $svg): ?string
    {
        if (strlen($svg) > 2_000_000 || !str_contains($svg, '<svg')) {
            return null;
        }
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            // without DTD and external entities (XXE) and without network access
            $ok = HtmlLimits::xml($dom, preg_replace('/<!DOCTYPE[^>]*>/i', '', $svg) ?? '', LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_use_internal_errors($previous);
        }
        if (!$ok || $dom->documentElement === null || strtolower($dom->documentElement->localName) !== 'svg') {
            return null;
        }
        self::inlineClassStyles($dom);
        self::node($dom->documentElement);
        $output = $dom->saveXML($dom->documentElement);

        return $output === false ? null : $output;
    }

    /** @return array{0: int, 1: int} width and height from width/height or viewBox (0 = unknown) */
    public static function dimensions(string $svg): array
    {
        if (preg_match('/<svg[^>]*\bviewBox="[-\d.]+[ ,]+[-\d.]+[ ,]+([\d.]+)[ ,]+([\d.]+)"/i', $svg, $m)) {
            return [(int) round((float) $m[1]), (int) round((float) $m[2])];
        }
        if (preg_match('/<svg[^>]*\bwidth="([\d.]+)(px)?"[^>]*\bheight="([\d.]+)(px)?"/i', $svg, $m)) {
            return [(int) $m[1], (int) $m[3]];
        }

        return [0, 0];
    }

    /**
     * Logos from design tools colour their shapes by classes in a <style> block (.cls-1 { fill: #f0eae4 }). The block itself
     * never stays (CSS can load things), so simple rules of class selectors become presentation attributes of the shapes –
     * without that a light logo turns black. Only presentation properties with plain values (colours, numbers, url(#id)).
     */
    private static function inlineClassStyles(\DOMDocument $dom): void
    {
        $css = '';
        foreach (iterator_to_array($dom->getElementsByTagName('style')) as $style) {
            $css .= $style->textContent . "\n";
        }
        if ($css === '') {
            return;
        }
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? '';
        $byClass = [];
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        foreach ($rules as [, $selectors, $body]) {
            $declarations = [];
            foreach (explode(';', $body) as $declaration) {
                [$property, $value] = array_map('trim', explode(':', $declaration, 2)) + [1 => ''];
                $property = strtolower($property);
                if ($property !== 'style' && in_array($property, self::ATTRIBUTES, true) && preg_match('/^(url\(\s*#[\w-]+\s*\)|[#\w\s.,%()-]+)$/', $value)
                    && !preg_match('/javascript|expression/i', $value)) {
                    $declarations[$property] = $value;
                }
            }
            foreach (explode(',', $selectors) as $selector) {
                if ($declarations !== [] && preg_match('/^\.([\w-]+)$/', trim($selector), $m)) {
                    $byClass[$m[1]] = $declarations + ($byClass[$m[1]] ?? []);
                }
            }
        }
        if ($byClass === []) {
            return;
        }
        foreach (iterator_to_array($dom->getElementsByTagName('*')) as $el) {
            foreach (preg_split('/\s+/', $el->getAttribute('class'), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $class) {
                foreach ($byClass[$class] ?? [] as $property => $value) {
                    $el->setAttribute($property, $value); // a class rule wins over a presentation attribute, as in CSS
                }
            }
        }
    }

    private static function node(\DOMElement $el): void
    {
        foreach (iterator_to_array($el->childNodes) as $child) {
            if ($child instanceof \DOMElement) {
                if (!in_array(strtolower($child->localName), self::HTML_TAGS, true)) {
                    $el->removeChild($child);
                    continue;
                }
                self::node($child);
            } elseif ($child instanceof \DOMProcessingInstruction || $child instanceof \DOMCdataSection) {
                $el->removeChild($child);
            }
        }
        foreach (iterator_to_array($el->attributes, false) as $attributes) { // a list: keyed by local name, x:onload would hide onload
            $name = strtolower($attributes->nodeName);
            $value = $attributes->nodeValue ?? '';
            $bad = !in_array($name, self::ATTRIBUTES, true)
                || (in_array($name, ['href', 'xlink:href'], true) && !str_starts_with($value, '#'))
                || preg_match('/javascript:|expression\(|@import|url\((?!\s*[\'"]?#)/i', $value);
            if ($bad) {
                $el->removeAttributeNode($attributes);
            }
        }
    }
}
