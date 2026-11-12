<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Dom\Element;
use Kaleta\Builder\HtmlWidgets;

/**
 * A section of an old page as clean semantic HTML (3.36, MCP read_old_page with format "semantic"): what any page builder
 * wrote – Breakdance, Elementor, Gutenberg, Webflow, hand-made – reduced to headings, text, lists, images, links, buttons,
 * tables, quotes and forms, ready for build_from_html. Builder-agnostic by design: no code path per builder, only the
 * meaning of the markup.
 *
 *  - The wrapper soup goes: a wrapper with one child is that child; a wrapper with several children stays one plain <div>
 *    (a row of columns or cards keeps its grouping); classes, ids, inline styles, data and ARIA attributes go.
 *  - Scripts, styles, icons, screen-reader copies, hidden fields and loose buttons go.
 *  - The widgets Builder\HtmlWidgets recognises are written in the shape build_from_html turns into their elements: an
 *    accordion as <details>, tabs as role="tab"/role="tabpanel", a video, map or booking page as its <iframe>, a counter as
 *    a number with data-counter, a form with its fields. A form a script draws and other frames are only listed (widgets).
 *  - A link alone in a paragraph, or one that looks like a button, stands on its own (build_from_html makes it a Button).
 *
 * Pure (no database, no network); addresses are made absolute against the page's address. The text is someone else's:
 * the caller marks it untrusted.
 */
final class SemanticHtml
{
    /** Tags whose content is dropped. */
    private const array DROP = ['script', 'style', 'noscript', 'template', 'link', 'meta', 'svg', 'canvas', 'object', 'embed', 'head', 'title', 'base', 'dialog'];

    /** Text-level tags kept as they are (without attributes). */
    private const array INLINE = ['strong', 'em', 'b', 'i', 'u', 'mark', 'small', 'sub', 'sup', 'code', 'cite', 'abbr', 'q', 's', 'del', 'ins', 'kbd', 'time'];

    /** Block tags kept as they are (without attributes). */
    private const array BLOCKS = ['p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'li', 'dl', 'dt', 'dd', 'blockquote', 'pre', 'address', 'figure', 'figcaption',
        'table', 'thead', 'tbody', 'tfoot', 'tr', 'caption'];

    /** Wrappers: kept only as a plain <div> when they hold more than one thing. */
    private const array WRAPPERS = ['div', 'section', 'article', 'header', 'footer', 'aside', 'main', 'nav', 'span', 'center', 'font', 'body', 'html'];

    /** Classes of text meant only for screen readers. */
    private const string SCREEN_READER = '/(?:^|\s)(?:sr-only|screen-reader-text|visually-hidden|visuallyhidden|elementor-screen-only|assistive-text|hidden)(?:\s|$)/i';

    /** A link that looks like a button (by its class or role). */
    private const string BUTTON = '/(?:^|[\s_-])(?:btn|button|cta|tlacitko)(?:$|[\s_-])/i';

    /** @var list<string> */
    private array $widgets = [];

    private int $tabs = 0;

    private function __construct(private readonly string $url)
    {
    }

    /**
     * @param string $tag the tag of the result: section, header or footer
     * @return array{html: string, widgets: list<string>} widgets: the kinds found (accordion, tabs, video, map, embed, counter,
     *         form, unreadable_form, frame – a frame of another kind, left out)
     */
    public static function section(Element $element, string $url, string $tag = 'section'): array
    {
        $self = new self($url);
        // the section's own wrappers with one child are gone through (section > container > row); a wrapper gives its content,
        // any other top-level block (an <h1>, a <p>, a widget) is itself the content
        $top = $element;
        for ($depth = 0; $depth < 12 && in_array(strtolower($top->localName), self::WRAPPERS, true) && HtmlWidgets::kind($top) === null; $depth++) {
            $only = self::onlyChild($top);
            if ($only === null || !in_array(strtolower($only->localName), self::WRAPPERS, true)) {
                break;
            }
            $top = $only;
        }
        $inner = in_array(strtolower($top->localName), self::WRAPPERS, true) && HtmlWidgets::kind($top) === null ? $self->wrapperContent($top)['html'] : $self->node($top);
        $id = (string) $element->getAttribute('id');
        $anchor = preg_match('/^[a-z][a-z-]{1,40}$/D', $id) ? ' id="' . e($id) . '"' : ''; // an in-page anchor (#contact) – build_from_html keeps it; generated ids (with digits) go

        return ['html' => trim($inner) === '' ? '' : '<' . $tag . $anchor . '>' . $inner . '</' . $tag . '>', 'widgets' => array_values(array_unique($self->widgets))];
    }

    /** The one child element that holds something, when there is exactly one and no text beside it; else null. */
    private static function onlyChild(Element $el): ?Element
    {
        $found = null;
        foreach ($el->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent, " \t\n\r\0\x0B\u{A0}") !== '') {
                return null;
            }
            if (!$node instanceof Element || in_array(strtolower($node->localName), self::DROP, true)
                || (trim($node->textContent) === '' && $node->querySelector('img, iframe, video, form, picture') === null && !in_array(strtolower($node->localName), ['img', 'iframe', 'video', 'picture', 'hr'], true))) {
                continue;
            }
            if ($found !== null) {
                return null;
            }
            $found = $node;
        }

        return $found;
    }

    /** The children of an element, converted. */
    private function children(Element $el): string
    {
        return implode('', $this->parts($el));
    }

    /**
     * The converted children, one string each (empty ones left out).
     *
     * @return list<string>
     */
    private function parts(Element $el): array
    {
        $out = [];
        foreach ($el->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = (string) preg_replace('/[\s\x{A0}]+/u', ' ', $node->textContent);
                if (trim($text) !== '') {
                    $out[] = e($text);
                } elseif ($text !== '' && $out !== []) {
                    $out[] = ' '; // the space between two inline elements
                }
            } elseif ($node instanceof Element) {
                $html = $this->node($node);
                if ($html !== '') {
                    $out[] = $html;
                }
            }
        }

        return $out;
    }

    private function node(Element $el): string
    {
        $tag = strtolower($el->localName);
        if (in_array($tag, self::DROP, true) || $this->hidden($el)) {
            return '';
        }
        if ($tag === 'form') {
            return $this->form($el);
        }
        $kind = in_array($tag, ['img', 'picture', 'a', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'table', 'br', 'hr'], true) ? null : HtmlWidgets::kind($el);
        if ($kind !== null && ($widget = $this->widget($kind, $el)) !== null) {
            $this->widgets[] = $kind;

            return $widget;
        }

        return match (true) {
            $tag === 'br' => '<br>',
            $tag === 'hr' => '<hr>',
            $tag === 'img' => $this->image($el),
            $tag === 'picture' => ($img = $el->querySelector('img')) !== null ? $this->image($img) : '',
            $tag === 'a' => $this->link($el),
            $tag === 'iframe' || $tag === 'video' || $tag === 'audio' => $this->frame(),
            $tag === 'button', $tag === 'input', $tag === 'select', $tag === 'textarea', $tag === 'label' => '', // outside a form they only open and close things
            $tag === 'p' => $this->paragraph($el),
            $tag === 'th' || $tag === 'td' => $this->cell($el, $tag),
            in_array($tag, self::BLOCKS, true), in_array($tag, self::INLINE, true) => $this->plain($el, $tag),
            $tag === 'details' => $this->plain($el, 'details'),
            $tag === 'summary' => '<summary>' . $this->children($el) . '</summary>',
            default => $this->wrapper($el),
        };
    }

    /** An element hidden for everyone (hidden, aria-hidden, a screen-reader copy) – a widget's closed panel is read by its widget first. */
    private function hidden(Element $el): bool
    {
        return $el->hasAttribute('hidden') && $el->querySelector('img') === null && HtmlWidgets::kind($el) === null
            || strtolower((string) $el->getAttribute('aria-hidden')) === 'true' && trim($el->textContent) === ''
            || preg_match(self::SCREEN_READER, (string) $el->getAttribute('class')) === 1;
    }

    /** A tag kept without its attributes; empty when nothing is inside. */
    private function plain(Element $el, string $tag): string
    {
        $inner = $this->children($el);

        return trim(strip_tags($inner, '<img><iframe><video><hr><br>')) === '' && !str_contains($inner, '<img') ? '' : '<' . $tag . '>' . trim($inner) . '</' . $tag . '>';
    }

    private function cell(Element $el, string $tag): string
    {
        $span = '';
        foreach (['colspan', 'rowspan'] as $name) {
            $value = (int) $el->getAttribute($name);
            $span .= $value > 1 && $value < 100 ? ' ' . $name . '="' . $value . '"' : '';
        }

        return '<' . $tag . $span . '>' . trim($this->children($el)) . '</' . $tag . '>';
    }

    /** A paragraph; a paragraph that holds only a button-like link is the link (build_from_html makes it a Button). */
    private function paragraph(Element $el): string
    {
        $links = iterator_to_array($el->querySelectorAll('a[href]'), false);
        if (count($links) === 1 && trim($links[0]->textContent) === trim($el->textContent) && $this->buttonLike($links[0])) {
            return $this->link($links[0]);
        }
        if ($el->querySelector('div, section, article, figure, iframe, video, form, ul, ol, table, h1, h2, h3, h4, h5, h6') !== null) {
            return $this->wrapper($el); // a builder's <p> that wraps blocks
        }

        return $this->plain($el, 'p');
    }

    /** A wrapper: one child = the child itself; several = one plain <div> around them; inline content only = a paragraph. */
    private function wrapper(Element $el): string
    {
        $content = $this->wrapperContent($el);

        return $content['several'] ? '<div>' . $content['html'] . '</div>' : $content['html'];
    }

    /**
     * What a wrapper holds, without the wrapper: html and whether it is several blocks (then a <div> keeps them together).
     * Links side by side (a menu) become a list; inline content only becomes one paragraph.
     *
     * @return array{html: string, several: bool}
     */
    private function wrapperContent(Element $el): array
    {
        $all = $this->parts($el);
        $parts = array_values(array_filter($all, fn (string $p): bool => trim($p) !== ''));
        if ($parts === []) {
            return ['html' => '', 'several' => false];
        }
        if (count($parts) >= 2 && array_filter($parts, fn (string $p): bool => !preg_match('/^<a href=[^>]*>.*<\/a>$/s', $p)) === []) {
            return ['html' => '<ul>' . implode('', array_map(fn (string $p): string => '<li>' . $p . '</li>', $parts)) . '</ul>', 'several' => false]; // a menu
        }
        $blocks = array_filter($parts, fn (string $p): bool => (bool) preg_match('/^<(?:div|p|h[1-6]|ul|ol|dl|table|blockquote|figure|pre|address|details|form|iframe|video|hr|section|a\s+class="button)\b/', $p));
        if ($blocks === []) {
            // text and inline elements only: one paragraph (a span soup with words in it)
            $inline = trim(implode('', $all));
            if (in_array(strtolower($el->localName), ['span', 'font', 'abbr'], true)) {
                return ['html' => $inline, 'several' => false];
            }

            return ['html' => preg_match('/^<(?:img|iframe)\b[^>]*>$/', $inline) ? $inline : '<p>' . $inline . '</p>', 'several' => false];
        }
        if (count($parts) === 1) {
            return ['html' => $parts[0], 'several' => false];
        }
        // loose inline pieces between blocks get their own paragraphs, so a block never sits inside text
        $out = '';
        $run = '';
        foreach ($parts as $p) {
            if (in_array($p, $blocks, true)) {
                $out .= trim($run) !== '' ? '<p>' . trim($run) . '</p>' : '';
                $run = '';
                $out .= $p;
            } else {
                $run .= $p;
            }
        }
        $out .= trim($run) !== '' ? '<p>' . trim($run) . '</p>' : '';

        return ['html' => $out, 'several' => true];
    }

    private function image(Element $img): string
    {
        $src = '';
        foreach (['data-src', 'data-lazy-src', 'data-original', 'src'] as $name) {
            $value = trim((string) $img->getAttribute($name));
            if ($value !== '' && !preg_match('#^\s*data:#i', $value)) {
                $src = $value;
                break;
            }
        }
        if ($src === '' && ($set = trim((string) ($img->getAttribute('data-srcset') ?: $img->getAttribute('srcset')))) !== '') {
            $src = trim(explode(' ', trim(explode(',', $set)[0]))[0]);
        }
        $width = (int) $img->getAttribute('width');
        $height = (int) $img->getAttribute('height');
        if ($src === '' || ($width > 0 && $width <= 2) || ($height > 0 && $height <= 2)) {
            return ''; // a spacer or a tracking pixel
        }
        $src = $this->absolute($src);
        if ($src === '') {
            return '';
        }
        $size = ($width > 2 ? ' width="' . $width . '"' : '') . ($height > 2 ? ' height="' . $height . '"' : '');

        return '<img src="' . e($src) . '" alt="' . e(trim((string) $img->getAttribute('alt'))) . '"' . $size . '>';
    }

    private function link(Element $a): string
    {
        $href = trim((string) $a->getAttribute('href'));
        $inner = trim($this->children($a));
        if ($inner === '') {
            return '';
        }
        $href = str_starts_with($href, '#') || preg_match('#^(mailto|tel):#i', $href) ? $href : $this->absolute($href);
        if ($href === '') {
            return $inner; // a javascript: link – its text stays
        }
        $class = '';
        if ($this->buttonLike($a) && !preg_match('/<(?:div|p|h[1-6]|img|ul|ol)\b/', $inner)) {
            $class = preg_match('/outline|ghost|secondary|border|light|alt/i', (string) $a->getAttribute('class')) ? ' class="button secondary"' : ' class="button"';
        }
        $target = strtolower((string) $a->getAttribute('target')) === '_blank' ? ' target="_blank"' : '';

        return '<a' . $class . ' href="' . e($href) . '"' . $target . '>' . $inner . '</a>';
    }

    private function buttonLike(Element $a): bool
    {
        return strtolower((string) $a->getAttribute('role')) === 'button' || preg_match(self::BUTTON, (string) $a->getAttribute('class') . ' ' . (string) $a->parentElement?->getAttribute('class')) === 1;
    }

    /** A frame build_from_html would not keep (not a video, map or booking page): left out and listed. */
    private function frame(): string
    {
        $this->widgets[] = 'frame';

        return '';
    }

    /** A recognised widget in the shape build_from_html converts; null = write the element as ordinary markup. */
    private function widget(string $kind, Element $el): ?string
    {
        switch ($kind) {
            case 'accordion':
                $items = HtmlWidgets::accordion($el) ?? [];
                $html = '';
                foreach ($items as [$question, $answer]) {
                    $html .= '<details><summary>' . e($question) . '</summary>' . $this->fragment($answer) . '</details>';
                }

                return $html !== '' ? '<div>' . $html . '</div>' : null;
            case 'tabs':
                $tabs = HtmlWidgets::tabs($el) ?? [];
                if ($tabs === []) {
                    return null;
                }
                $n = ++$this->tabs;
                $list = '';
                $panels = '';
                foreach ($tabs as $i => [$name, $content]) {
                    $id = 'tab-' . $n . '-' . ($i + 1);
                    $list .= '<button role="tab" aria-controls="' . $id . '">' . e($name) . '</button>';
                    $panels .= '<div role="tabpanel" id="' . $id . '">' . $this->fragment($content) . '</div>';
                }

                return '<div><div role="tablist">' . $list . '</div>' . $panels . '</div>';
            case 'video':
                $video = HtmlWidgets::video($el);
                if ($video === null) {
                    return null;
                }
                $title = $video[1] !== '' ? ' title="' . e($video[1]) . '"' : '';

                return preg_match('#\.(mp4|webm|m4v)(?:[?\#].*)?$#iD', $video[0]) ? '<video src="' . e($this->absolute($video[0])) . '"' . $title . ' controls></video>'
                    : '<iframe src="' . e($video[0]) . '"' . $title . '></iframe>';
            case 'map':
                $src = strtolower($el->localName) === 'iframe' ? HtmlWidgets::frameSource($el) : '';
                if ($src === '') {
                    $place = HtmlWidgets::mapOfData($el);
                    $src = $place !== null ? 'https://maps.google.com/maps?q=' . rawurlencode($place[0]) . '&z=' . $place[1] . '&output=embed' : '';
                }

                return $src !== '' ? '<iframe src="' . e($src) . '"></iframe>' : null;
            case 'embed':
                $src = strtolower($el->localName) === 'iframe' ? HtmlWidgets::frameSource($el) : (string) HtmlWidgets::embedOfData($el);

                return $src !== '' ? '<iframe src="' . e($src) . '"></iframe>' : null;
            case 'counter':
                $c = HtmlWidgets::counter($el);
                if ($c === null) {
                    return null;
                }

                return '<div>' . e(trim($c['pred'])) . '<strong data-counter="' . e($c['cislo']) . '">' . e($c['cislo']) . '</strong>' . e(trim($c['za']))
                    . ($c['popisek'] !== '' ? '<p>' . e($c['popisek']) . '</p>' : '') . '</div>';
            case 'unreadable_form':
                return ''; // listed in widgets: the fields are drawn by a script, nothing to write
            default:
                return null;
        }
    }

    /** A widget's part (HTML as the page wrote it) converted the same way. */
    private function fragment(string $html): string
    {
        $body = HtmlLimits::fragment($html)?->body; // the parts come from a page that passed the limits already
        if ($body === null) {
            return '';
        }
        $parts = $this->parts($body);
        $inline = array_filter($parts, fn (string $p): bool => !preg_match('/^<(?:div|p|h[1-6]|ul|ol|dl|table|blockquote|figure|pre|iframe|video|details|form)\b/', $p));

        return count($inline) === count($parts) && trim(implode('', $parts)) !== '' ? '<p>' . trim(implode('', $parts)) . '</p>' : implode('', $parts);
    }

    /** A form with its fields, labels and button (build_from_html makes it a Form element); hidden, honeypot and captcha fields go. */
    private function form(Element $form): string
    {
        if (HtmlWidgets::isSearchForm($form)) {
            return ''; // a search box: the site has its own
        }
        if (HtmlWidgets::formFields($form) === 0) {
            $this->widgets[] = 'unreadable_form';

            return '';
        }
        $this->widgets[] = 'form';

        return '<form>' . $this->formChildren($form, $form) . '</form>';
    }

    private function formChildren(Element $el, Element $form): string
    {
        $out = '';
        foreach ($el->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                $text = (string) preg_replace('/[\s\x{A0}]+/u', ' ', $node->textContent);
                $out .= trim($text) !== '' ? e($text) : '';
                continue;
            }
            if (!$node instanceof Element) {
                continue;
            }
            $tag = strtolower($node->localName);
            if (in_array($tag, self::DROP, true) || preg_match(self::SCREEN_READER, (string) $node->getAttribute('class'))) {
                continue;
            }
            if (in_array($tag, ['input', 'select', 'textarea'], true)) {
                $type = strtolower((string) ($node->getAttribute('type') ?? 'text'));
                if ($type === 'hidden' || HtmlWidgets::trapField($node, $form)) {
                    continue;
                }
                $attributes = $this->attributes($node, ['type', 'name', 'id', 'placeholder', 'value', 'required', 'multiple', 'checked']);
                $out .= match ($tag) {
                    'input' => '<input' . $attributes . '>',
                    'textarea' => '<textarea' . $attributes . '></textarea>',
                    default => '<select' . $attributes . '>' . implode('', array_map(fn (Element $o): string => '<option' . $this->attributes($o, ['value']) . '>' . e(trim($o->textContent)) . '</option>',
                        iterator_to_array($node->querySelectorAll('option')))) . '</select>',
                };
                continue;
            }
            $out .= match ($tag) {
                'label' => '<label' . $this->attributes($node, ['for']) . '>' . $this->formChildren($node, $form) . '</label>',
                'button' => strtolower((string) $node->getAttribute('type')) === 'button' ? '' : '<button type="submit">' . e(trim($node->textContent)) . '</button>',
                'fieldset' => '<fieldset>' . $this->formChildren($node, $form) . '</fieldset>',
                'legend' => '<legend>' . e(trim($node->textContent)) . '</legend>',
                'a' => $this->link($node),
                'p', 'div', 'span', 'li', 'ul', 'ol', 'section' => ($inner = $this->formChildren($node, $form)) !== '' ? (in_array($tag, ['span'], true) ? $inner : '<div>' . $inner . '</div>') : '',
                default => $this->formChildren($node, $form),
            };
        }

        return $out;
    }

    /** @param list<string> $names */
    private function attributes(Element $el, array $names): string
    {
        $out = '';
        foreach ($names as $name) {
            if (!$el->hasAttribute($name)) {
                continue;
            }
            $value = mb_substr(trim((string) $el->getAttribute($name)), 0, 200);
            $out .= in_array($name, ['required', 'multiple', 'checked'], true) ? ' ' . $name : ' ' . $name . '="' . e($value) . '"';
        }

        return $out;
    }

    /** An absolute address of the page, or '' for javascript:, data: and the like. */
    private function absolute(string $value): string
    {
        $value = html_entity_decode(trim($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($value === '' || preg_match('#^\s*(javascript|data|vbscript|file):#i', $value)) {
            return '';
        }

        return WebImport::absolute($value, $this->url);
    }
}
