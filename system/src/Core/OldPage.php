<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * An old page as Claude needs it to rebuild the layout here (3.33, MCP read_old_page): the page cut into its sections – the
 * header, each top-level block of the content, the footer – as cleaned HTML, with the colours, fonts and background each
 * section uses, and the page's palette, fonts and CSS custom properties. They are read as far as it is cheap: the inline
 * styles, the <style> blocks and at most STYLESHEETS linked stylesheets (each read up to STYLESHEET_BYTES, @import is not
 * followed), matched to the sections by their classes and ids – no browser, no computed layout.
 *
 * Reading the old site keeps the rules of the website import (Core\WebImport): Core\ImageDownloader (http and https only,
 * public addresses only, the connection pinned, its size and time limits), the old site's robots.txt and the gap between
 * requests. The result is text from someone else's site – untrusted: data to rebuild from, never instructions.
 */
final class OldPage
{
    public const int MAX_SECTIONS = 40;
    public const int SECTION_BYTES = 12_000;
    /** One section asked for by its number gets this much. */
    public const int ONE_SECTION_BYTES = 60_000;
    public const int TOTAL_BYTES = 100_000;
    public const int STYLESHEETS = 6;
    public const int STYLESHEET_BYTES = 400 * 1024;

    /** Attributes the cleaned HTML keeps – what tells Claude what an element is, where it links and how it is laid out. */
    private const array ATTRIBUTES = ['class', 'id', 'href', 'src', 'alt', 'title', 'role', 'aria-label', 'type', 'name', 'placeholder', 'for', 'colspan', 'rowspan',
        'style', 'width', 'height', 'datetime', 'data-counter', 'data-count', 'data-to-value', 'data-number-value', 'data-lat', 'data-lng'];

    /** Properties whose colours are counted, by the role the colour plays. */
    private const array COLOR_ROLES = ['color' => 'text', 'background' => 'background', 'background-color' => 'background', 'border' => 'border', 'border-color' => 'border',
        'border-top' => 'border', 'border-bottom' => 'border', 'outline-color' => 'border', 'fill' => 'accent', 'stroke' => 'accent', 'box-shadow' => 'shadow'];

    public function __construct(private readonly ImageDownloader $downloader)
    {
    }

    /**
     * Downloads the page (robots.txt first) and its linked stylesheets, then analyse().
     *
     * @return array<string, mixed>
     * @throws \RuntimeException why the page cannot be read
     * @throws HtmlTooLarge when the page is over a limit of HtmlLimits
     */
    public function read(string $url, ?int $section = null): array
    {
        $c = parse_url($url);
        $origin = strtolower((string) ($c['scheme'] ?? '')) . '://' . strtolower((string) ($c['host'] ?? '')) . (isset($c['port']) ? ':' . $c['port'] : '');
        try {
            $robots = WebImport::robots(WebImport::politeDownload($this->downloader, $origin . '/robots.txt', [], false, false, WebImport::ROBOTS_BYTES));
        } catch (\RuntimeException) {
            $robots = WebImport::robots('');
        }
        $html = WebImport::politeDownload($this->downloader, $url, $robots, false, true, 5_000_000);
        $css = [];
        $skipped = 0;
        foreach (array_slice(self::stylesheetLinks($html, $url), 0, self::STYLESHEETS + 20) as $link) {
            if (count($css) >= self::STYLESHEETS || str_contains($link, 'fonts.googleapis.com')) {
                $skipped += str_contains($link, 'fonts.googleapis.com') ? 0 : 1; // the font names are in the address itself
                continue;
            }
            try {
                $css[$link] = WebImport::politeDownload($this->downloader, $link, $robots, false, ImageDownloader::domainFromUrl($link) === ImageDownloader::domainFromUrl($url), self::STYLESHEET_BYTES);
            } catch (\RuntimeException) {
                $skipped++;
            }
        }

        return HtmlLimits::guard(fn (): array => self::analyse($html, $url, $css, $skipped, $section));
    }

    /**
     * Addresses of the page's linked stylesheets, absolute, in their order.
     *
     * @return list<string>
     */
    public static function stylesheetLinks(string $html, string $url): array
    {
        $links = [];
        foreach (HtmlLimits::document($html)->querySelectorAll('link[href]') as $link) {
            if (in_array('stylesheet', preg_split('/\s+/', strtolower((string) $link->getAttribute('rel'))) ?: [], true)) {
                $href = WebImport::absolute(html_entity_decode(trim((string) $link->getAttribute('href')), ENT_QUOTES | ENT_HTML5, 'UTF-8'), $url);
                if (preg_match('#^https?://#i', $href)) {
                    $links[] = $href;
                }
            }
        }

        return array_values(array_unique($links));
    }

    /**
     * The page as sections with their look (pure: the downloads are done).
     *
     * @param array<string, string> $stylesheets address => CSS
     * @return array<string, mixed>
     */
    public static function analyse(string $html, string $url, array $stylesheets = [], int $skipped = 0, ?int $only = null): array
    {
        $doc = HtmlLimits::document($html);
        $css = implode("\n", array_map(fn (\Dom\Element $s): string => $s->textContent, iterator_to_array($doc->querySelectorAll('style')))) . "\n" . implode("\n", $stylesheets);
        $rules = self::rules($css);
        $variables = self::variables($rules);
        $index = self::index($rules);
        $text = fn (?\Dom\Element $el): string => trim((string) preg_replace('/\s+/u', ' ', (string) $el?->textContent));
        $meta = fn (string $selector): string => trim((string) $doc->querySelector($selector)?->getAttribute('content'));
        $share = $meta('meta[property="og:image"]') ?: $meta('meta[name="twitter:image"]');

        $sections = [];
        foreach (self::sectionElements($doc) as [$role, $element]) {
            $sections[] = ['role' => $role, 'element' => $element];
        }
        $total = 0;
        $out = [];
        foreach (array_slice($sections, 0, self::MAX_SECTIONS) as $i => $s) {
            if ($only !== null && $only !== $i) {
                continue;
            }
            /** @var \Dom\Element $el */
            $el = $s['element'];
            $limit = $only !== null ? self::ONE_SECTION_BYTES : self::SECTION_BYTES;
            $clean = self::clean($el, $url);
            $cut = strlen($clean) > $limit || ($only === null && $total + strlen($clean) > self::TOTAL_BYTES);
            if ($cut) {
                $clean = self::cut($clean, $only === null ? max(0, min($limit, self::TOTAL_BYTES - $total)) : $limit);
            }
            $total += strlen($clean);
            $look = self::look($el, $index, $variables, $url);
            $out[] = array_filter(['index' => $i, 'role' => $s['role'], 'tag' => strtolower($el->localName), 'classes' => mb_substr(trim((string) $el->getAttribute('class')), 0, 200),
                'heading' => mb_substr($text($el->querySelector('h1, h2, h3')), 0, 160), 'html' => $clean, 'truncated' => $cut] + $look,
                fn (mixed $v): bool => $v !== '' && $v !== [] && $v !== false && $v !== null);
        }

        return [
            'url' => $url,
            'title' => mb_substr($text($doc->querySelector('title')), 0, 200),
            'description' => mb_substr($meta('meta[name="description"]'), 0, 320),
            'language' => mb_substr(trim((string) $doc->documentElement?->getAttribute('lang')), 0, 35),
            'share_image' => $share !== '' ? WebImport::absolute($share, $url) : '',
            'sections' => $out,
            'more_sections' => max(0, count($sections) - self::MAX_SECTIONS),
            'colors' => self::palette($rules, $variables, $doc),
            'fonts' => self::fonts($rules, $variables, $doc),
            'base' => self::base($index, $variables),
            'css_variables' => array_slice($variables, 0, 40, true),
            'stylesheets' => ['read' => count($stylesheets), 'not_read' => $skipped],
        ];
    }

    /**
     * The sections of a page: the header, the top-level blocks of the main content (a wrapper with one child is gone
     * through), the footer.
     *
     * @return list<array{0: string, 1: \Dom\Element}>
     */
    private static function sectionElements(\Dom\HTMLDocument $doc): array
    {
        $body = $doc->body;
        if ($body === null) {
            return [];
        }
        $main = $doc->querySelector('main, [role="main"], #content, #main, #main-content, .site-main, #page-container') ?? $body;
        $found = [];
        $header = $doc->querySelector('header, [role="banner"]');
        if ($header !== null && !$main->contains($header)) {
            $found[] = ['header', $header];
        }
        $root = $main;
        for ($depth = 0; $depth < 12; $depth++) {
            $children = self::blocks($root);
            if (count($children) !== 1 || in_array(strtolower($children[0]->localName), ['section', 'header', 'footer'], true) && $root !== $main) {
                break;
            }
            $root = $children[0];
        }
        foreach (self::blocks($root) as $child) {
            $tag = strtolower($child->localName);
            if ($root === $body && (in_array($tag, ['header', 'footer', 'nav'], true) || $child === $header)) {
                continue;
            }
            $found[] = ['section', $child];
        }
        $footer = null;
        foreach ($doc->querySelectorAll('footer, [role="contentinfo"]') as $candidate) {
            if (!$main->contains($candidate) || $main === $body) {
                $footer = $candidate; // the last one is the page's footer
            }
        }
        if ($footer !== null && ($found === [] || $found[array_key_last($found)][1] !== $footer)) {
            $found[] = ['footer', $footer];
        }

        return $found;
    }

    /**
     * The element children that hold something (not scripts, styles or empty wrappers).
     *
     * @return list<\Dom\Element>
     */
    private static function blocks(\Dom\Element $parent): array
    {
        $out = [];
        for ($child = $parent->firstElementChild; $child !== null; $child = $child->nextElementSibling) {
            if (!in_array(strtolower($child->localName), ['script', 'style', 'noscript', 'template', 'link', 'meta'], true)
                && (trim($child->textContent) !== '' || $child->querySelector('img, iframe, video, picture, svg, form') !== null || in_array(strtolower($child->localName), ['img', 'iframe', 'video', 'picture'], true))) {
                $out[] = $child;
            }
        }

        return $out;
    }

    /** A section's HTML without scripts, styles, comments, icons' paths and attributes that say nothing; addresses absolute. */
    private static function clean(\Dom\Element $element, string $url): string
    {
        $copy = $element->cloneNode(true);
        foreach (iterator_to_array($copy->querySelectorAll('script, style, noscript, template, link, meta')) as $node) {
            if ($copy->contains($node)) {
                $node->remove();
            }
        }
        foreach (iterator_to_array($copy->querySelectorAll('svg')) as $svg) {
            if ($copy->contains($svg)) {
                foreach (iterator_to_array($svg->childNodes) as $inner) {
                    $svg->removeChild($inner); // an icon: its place and size are enough
                }
            }
        }
        foreach ([$copy, ...iterator_to_array($copy->querySelectorAll('*'))] as $node) {
            $lazy = $node->getAttribute('data-src') ?: $node->getAttribute('data-lazy-src');
            foreach (iterator_to_array($node->attributes, false) as $attribute) {
                $name = strtolower($attribute->nodeName);
                if (!in_array($name, self::ATTRIBUTES, true) && !str_starts_with($name, 'aria-')) {
                    $node->removeAttributeNode($attribute);
                }
            }
            if ($lazy !== null && $lazy !== '' && in_array(strtolower($node->localName), ['img', 'iframe'], true)) {
                $node->setAttribute('src', $lazy);
            }
            foreach (['href', 'src'] as $name) {
                $value = (string) $node->getAttribute($name);
                if ($value === '') {
                    continue;
                }
                if (preg_match('#^\s*(javascript|data|vbscript):#i', $value)) {
                    $node->removeAttribute($name);
                } elseif (!str_starts_with($value, '#') && !preg_match('#^(mailto|tel):#i', $value)) {
                    $node->setAttribute($name, WebImport::absolute($value, $url));
                }
            }
            if ($node->hasAttribute('style')) {
                $node->setAttribute('style', mb_substr(trim((string) preg_replace('/\s+/', ' ', (string) $node->getAttribute('style'))), 0, 300));
            }
        }

        return trim((string) preg_replace(['/<!--.*?-->/s', '/\s+/u'], ['', ' '], Html::outer($copy)));
    }

    /** HTML cut to a length at the end of a tag. */
    private static function cut(string $html, int $bytes): string
    {
        if ($bytes <= 0) {
            return '';
        }
        $part = mb_strcut($html, 0, $bytes);
        $end = strrpos($part, '>');

        return $end === false ? '' : substr($part, 0, $end + 1);
    }

    /* ---------- the look ---------- */

    /**
     * CSS rules as [selector, declarations property => value] – comments out, @media and @supports blocks read as if
     * their condition held, @font-face, @keyframes and @import skipped.
     *
     * @return list<array{0: string, 1: array<string, string>}>
     */
    public static function rules(string $css): array
    {
        $css = (string) preg_replace(['#/\*.*?\*/#s', '/@(?:font-face|keyframes|-webkit-keyframes|page)[^{]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}/i', '/@import[^;]*;/i'], ' ', $css);
        $rules = [];
        if (preg_match_all('/([^{}@;]+)\{([^{}]*)\}/', $css, $m, PREG_SET_ORDER)) {
            foreach (array_slice($m, 0, 20_000) as [, $selector, $body]) {
                $declarations = [];
                foreach (preg_split('/;(?![^(]*\))/', $body) ?: [] as $d) {
                    if (str_contains($d, ':')) {
                        [$property, $value] = array_map('trim', explode(':', $d, 2));
                        if ($property !== '' && $value !== '') {
                            $declarations[strtolower($property)] = (string) preg_replace('/\s*!important$/i', '', $value);
                        }
                    }
                }
                if ($declarations !== []) {
                    $rules[] = [trim((string) preg_replace('/\s+/', ' ', $selector)), $declarations];
                }
            }
        }

        return $rules;
    }

    /**
     * The custom properties of :root, html and body (--brand: #1f4e3d), with var() inside them filled in.
     *
     * @param list<array{0: string, 1: array<string, string>}> $rules
     * @return array<string, string>
     */
    public static function variables(array $rules): array
    {
        $variables = [];
        foreach ($rules as [$selector, $declarations]) {
            if (preg_match('/(^|,)\s*(:root|html|body)\s*(,|$)/i', $selector)) {
                foreach ($declarations as $property => $value) {
                    if (str_starts_with($property, '--') && strlen($value) <= 200) {
                        $variables[$property] = $value;
                    }
                }
            }
        }
        foreach ($variables as $name => $value) {
            $variables[$name] = self::fill($value, $variables);
        }

        return $variables;
    }

    /** @param array<string, string> $variables */
    private static function fill(string $value, array $variables): string
    {
        for ($i = 0; $i < 3 && str_contains($value, 'var('); $i++) {
            $value = (string) preg_replace_callback('/var\(\s*(--[\w-]+)\s*(?:,\s*([^()]*))?\)/', fn (array $m): string => $variables[$m[1]] ?? trim($m[2] ?? ''), $value);
        }

        return $value;
    }

    /**
     * The declarations by the class or id of the last part of each selector (.hero .button → button) and by the tag
     * (body, h1, a…), without :hover and the like.
     *
     * @param list<array{0: string, 1: array<string, string>}> $rules
     * @return array<string, array<string, string>>
     */
    private static function index(array $rules): array
    {
        $index = [];
        foreach ($rules as [$selector, $declarations]) {
            foreach (explode(',', $selector) as $one) {
                $last = trim((string) preg_replace('/^.*[\s>+~]/', '', trim($one)));
                if ($last === '' || preg_match('/:(?!root)/', $last)) {
                    continue;
                }
                preg_match_all('/([.#])([\w-]+)/', $last, $parts, PREG_SET_ORDER);
                $keys = array_map(fn (array $p): string => $p[1] . $p[2], $parts);
                if ($keys === [] && preg_match('/^([a-z][a-z0-9]*)$/iD', $last, $tag)) {
                    $keys = [strtolower($tag[1])];
                }
                foreach ($keys as $key) {
                    $index[$key] = array_merge($index[$key] ?? [], $declarations);
                }
            }
        }

        return $index;
    }

    /**
     * What a section looks like: its background (colour or image), text colour and fonts – from its inline style and the
     * rules of its classes and id – and the colours used inside it.
     *
     * @param array<string, array<string, string>> $index
     * @param array<string, string> $variables
     * @return array<string, mixed>
     */
    private static function look(\Dom\Element $el, array $index, array $variables, string $url): array
    {
        $own = [];
        foreach (self::keysOf($el) as $key) {
            $own = array_merge($own, $index[$key] ?? []);
        }
        $own = array_merge($own, self::inline((string) $el->getAttribute('style')));
        $background = self::colors(self::fill((string) ($own['background-color'] ?? $own['background'] ?? ''), $variables));
        $image = preg_match('/url\(\s*[\'"]?([^\'")]+)/i', (string) ($own['background-image'] ?? $own['background'] ?? ''), $m) && !str_starts_with($m[1], 'data:') ? WebImport::absolute($m[1], $url) : '';
        $counts = [];
        $fonts = [];
        foreach ([$el, ...iterator_to_array($el->querySelectorAll('*'))] as $i => $node) {
            if ($i > 400) {
                break;
            }
            $declarations = self::inline((string) $node->getAttribute('style'));
            foreach (self::keysOf($node) as $key) {
                $declarations = array_merge($index[$key] ?? [], $declarations);
            }
            foreach ($declarations as $property => $value) {
                if (isset(self::COLOR_ROLES[$property])) {
                    foreach (self::colors(self::fill($value, $variables)) as $color) {
                        $counts[$color] = ($counts[$color] ?? 0) + 1;
                    }
                } elseif ($property === 'font-family') {
                    $family = self::family(self::fill($value, $variables));
                    if ($family !== '') {
                        $fonts[$family] = true;
                    }
                }
            }
        }
        arsort($counts);

        return ['background' => $background[0] ?? '', 'background_image' => $image, 'foreground' => self::colors(self::fill((string) ($own['color'] ?? ''), $variables))[0] ?? '',
            'colors' => array_slice(array_keys($counts), 0, 8), 'fonts' => array_slice(array_keys($fonts), 0, 4)];
    }

    /**
     * The page's colours by how often its rules and inline styles use them, with the roles they play.
     *
     * @param list<array{0: string, 1: array<string, string>}> $rules
     * @param array<string, string> $variables
     * @return list<array{value: string, uses: int, roles: list<string>}>
     */
    private static function palette(array $rules, array $variables, \Dom\HTMLDocument $doc): array
    {
        $declarations = array_column($rules, 1);
        foreach ($doc->querySelectorAll('body [style]') as $i => $node) {
            if ($i < 2000) {
                $declarations[] = self::inline((string) $node->getAttribute('style'));
            }
        }
        $found = [];
        foreach ($declarations as $set) {
            foreach ($set as $property => $value) {
                $role = self::COLOR_ROLES[$property] ?? (str_starts_with($property, '--') ? 'variable' : null);
                if ($role === null) {
                    continue;
                }
                foreach (self::colors(self::fill($value, $variables)) as $color) {
                    $found[$color]['uses'] = ($found[$color]['uses'] ?? 0) + 1;
                    $found[$color]['roles'][$role] = true;
                }
            }
        }
        uasort($found, fn (array $a, array $b): int => $b['uses'] <=> $a['uses']);

        return array_map(fn (string $color, array $f): array => ['value' => $color, 'uses' => $f['uses'], 'roles' => array_keys($f['roles'])], array_keys(array_slice($found, 0, 12, true)), array_slice($found, 0, 12, true));
    }

    /**
     * The fonts: the families of font-family (first choice), the Google Fonts the page links and @font-face families.
     *
     * @param list<array{0: string, 1: array<string, string>}> $rules
     * @param array<string, string> $variables
     * @return list<array{family: string, uses: int, source?: string}>
     */
    private static function fonts(array $rules, array $variables, \Dom\HTMLDocument $doc): array
    {
        $found = [];
        foreach ($rules as [, $declarations]) {
            foreach (['font-family', 'font'] as $property) {
                $family = isset($declarations[$property]) ? self::family(self::fill($declarations[$property], $variables), $property === 'font') : '';
                if ($family !== '') {
                    $found[$family] = ($found[$family] ?? 0) + 1;
                }
            }
        }
        $google = [];
        foreach ($doc->querySelectorAll('link[href*="fonts.googleapis.com"]') as $link) {
            parse_str(str_replace('family=', 'family[]=', (string) parse_url(html_entity_decode((string) $link->getAttribute('href')), PHP_URL_QUERY)), $q);
            foreach ((array) ($q['family'] ?? []) as $families) {
                foreach (explode('|', (string) $families) as $family) {
                    $name = trim(str_replace('+', ' ', explode(':', $family)[0]));
                    if ($name !== '') {
                        $google[$name] = true;
                    }
                }
            }
        }
        arsort($found);
        $out = array_map(fn (string $family, int $uses): array => ['family' => $family, 'uses' => $uses] + (isset($google[$family]) ? ['source' => 'Google Fonts'] : []), array_keys($found), $found);
        foreach (array_keys($google) as $family) {
            if (!isset($found[$family])) {
                $out[] = ['family' => $family, 'uses' => 0, 'source' => 'Google Fonts'];
            }
        }

        return array_slice($out, 0, 10);
    }

    /**
     * The base of the page: the text and background of the body, the headings, the links and the buttons.
     *
     * @param array<string, array<string, string>> $index
     * @param array<string, string> $variables
     * @return array<string, array<string, string>>
     */
    private static function base(array $index, array $variables): array
    {
        $pick = function (array $keys, array $properties) use ($index, $variables): array {
            $out = [];
            foreach ($keys as $key) {
                foreach ($properties as $name => $property) {
                    $value = self::fill((string) ($index[$key][$property] ?? ''), $variables);
                    if ($value !== '' && !isset($out[$name])) {
                        $out[$name] = $name === 'font' ? self::family($value) : (in_array($name, ['foreground', 'background'], true) ? (self::colors($value)[0] ?? '') : mb_substr($value, 0, 60));
                    }
                }
            }

            return array_filter($out, fn (string $v): bool => $v !== '');
        };
        $buttons = array_values(array_filter(array_keys($index), fn (string $k): bool => (bool) preg_match('/^\.(?:.*[_-])?(button|btn)(?:$|[_-])/i', $k)));

        return array_filter([
            'body' => $pick(['body', 'html'], ['foreground' => 'color', 'background' => 'background-color', 'font' => 'font-family', 'size' => 'font-size', 'line_height' => 'line-height']),
            'headings' => $pick(['h1', 'h2', 'h3'], ['foreground' => 'color', 'font' => 'font-family', 'weight' => 'font-weight']),
            'links' => $pick(['a'], ['foreground' => 'color']),
            'buttons' => $pick(array_slice($buttons, 0, 5), ['foreground' => 'color', 'background' => 'background-color', 'radius' => 'border-radius', 'padding' => 'padding']),
        ]);
    }

    /**
     * The keys a node is found under in the index: its tag, its classes, its id.
     *
     * @return list<string>
     */
    private static function keysOf(\Dom\Element $node): array
    {
        $keys = [strtolower($node->localName)];
        foreach (preg_split('/\s+/', trim((string) $node->getAttribute('class'))) ?: [] as $class) {
            if ($class !== '') {
                $keys[] = '.' . $class;
            }
        }
        $id = trim((string) $node->getAttribute('id'));

        return $id !== '' ? [...$keys, '#' . $id] : $keys;
    }

    /** @return array<string, string> the declarations of a style attribute */
    private static function inline(string $style): array
    {
        return $style === '' ? [] : (self::rules('x{' . $style . '}')[0][1] ?? []);
    }

    /**
     * The colours in a value as #rrggbb (hex, rgb(), rgba() with some opacity, hsl()); transparent and currentColor are not colours.
     *
     * @return list<string>
     */
    public static function colors(string $value): array
    {
        $out = [];
        if (preg_match_all('/#([0-9a-f]{8}|[0-9a-f]{6}|[0-9a-f]{3,4})\b|rgba?\(\s*(\d{1,3})[\s,]+(\d{1,3})[\s,]+(\d{1,3})\s*(?:[,\/]\s*([\d.]+%?))?\s*\)|hsla?\(\s*([\d.]+)(?:deg)?[\s,]+([\d.]+)%[\s,]+([\d.]+)%\s*(?:[,\/]\s*([\d.]+%?))?\s*\)/i', $value, $m, PREG_SET_ORDER)) {
            foreach ($m as $c) {
                if (($c[1] ?? '') !== '') {
                    $hex = strtolower($c[1]);
                    if (strlen($hex) <= 4) {
                        $hex = implode('', array_map(fn (string $d): string => $d . $d, str_split(substr($hex, 0, 3))));
                    }
                    $out[] = '#' . substr($hex, 0, 6);
                } elseif (($c[2] ?? '') !== '') {
                    if (isset($c[5]) && (float) $c[5] === 0.0) { // a matched alpha is never empty
                        continue; // fully transparent
                    }
                    $out[] = sprintf('#%02x%02x%02x', min(255, (int) $c[2]), min(255, (int) $c[3]), min(255, (int) $c[4]));
                } elseif (($c[6] ?? '') !== '') {
                    [$h, $s, $l] = [fmod((float) $c[6], 360) / 360, (float) $c[7] / 100, (float) $c[8] / 100];
                    $q = $l < 0.5 ? $l * (1 + $s) : $l + $s - $l * $s;
                    $p = 2 * $l - $q;
                    $channel = function (float $t) use ($p, $q): int {
                        $t = $t < 0 ? $t + 1 : ($t > 1 ? $t - 1 : $t);
                        $v = $t < 1 / 6 ? $p + ($q - $p) * 6 * $t : ($t < 1 / 2 ? $q : ($t < 2 / 3 ? $p + ($q - $p) * (2 / 3 - $t) * 6 : $p));

                        return (int) round($v * 255);
                    };
                    $out[] = sprintf('#%02x%02x%02x', $channel($h + 1 / 3), $channel($h), $channel($h - 1 / 3));
                }
            }
        }
        if (preg_match('/^\s*(white|black)\s*$/i', $value, $named)) {
            $out[] = strtolower($named[1]) === 'white' ? '#ffffff' : '#000000';
        }

        return array_values(array_unique($out));
    }

    /** The first family of a font-family value (or of the font shorthand), without quotes; a generic family alone is kept. */
    private static function family(string $value, bool $shorthand = false): string
    {
        if ($shorthand) {
            // font: italic 700 1rem/1.5 "Inter", sans-serif → the part after the size
            if (!preg_match('/\d(?:px|rem|em|%|pt|vw)?(?:\/[\d.]+\w*)?\s+(.+)$/i', $value, $m)) {
                return '';
            }
            $value = $m[1];
        }
        $first = trim(explode(',', $value)[0], " \t\"'");

        return preg_match('/^[\p{L}\p{N} _-]{1,60}$/u', $first) && !preg_match('/^(inherit|initial|unset|revert|var)$/i', $first) ? $first : '';
    }
}
