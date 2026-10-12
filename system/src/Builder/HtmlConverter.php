<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Dom\Element;
use Dom\Node;

/**
 * Converting HTML to a build: the language model (or an import) writes ordinary semantic HTML with a <style> block and a clean
 * build results from it – one element per tag, the appearance in shared classes; <form> becomes a Form element. What cannot be
 * converted (scripts, inline styles, complex selectors) is left out and reported, so that the author knows what to add in the builder.
 *
 * The class is pure (no database): it returns the build, the classes from <style> and the messages. The caller does saving and permission checks.
 */
final class HtmlConverter
{
    /** Tags converted to the content of a Text element (a continuous flow of text is merged into one element). */
    private const array TEXT_TAGS = ['p', 'ul', 'ol', 'table', 'pre', 'dl', 'address'];

    /** Wrapper tags without their own meaning for the build – only their content is converted. */
    private const array UNWRAP = ['html', 'body', 'main'];

    /** Tags that have no counterpart in the build and are always left out. */
    private const array SKIP = ['script', 'noscript', 'style', 'link', 'meta', 'template', 'input', 'select', 'textarea', 'button', 'label', 'canvas', 'object', 'embed'];

    /** @var list<string> */
    private array $messages = [];

    /** @var array<string, string> class => safe declarations */
    private array $classes = [];

    /** @var array<string, array<string, array<string, string>>> class => state (tablet, mobil, hover…) => style properties */
    private array $classStyles = [];

    private function __construct(private readonly bool $admin)
    {
    }

    /**
     * @param bool $admin a Custom HTML element may be created (for SVG and embedded maps)
     * @return array{stavba: array<string, mixed>, tridy: array<string, string>, tridy_styl: array<string, array<string, array<string, string>>>, hlaseni: list<string>}
     * @throws \Kaleta\Core\HtmlTooLarge when the HTML is over a limit of Core\HtmlLimits (nothing is converted)
     */
    public static function convert(string $html, bool $admin = false): array
    {
        $conversion = new self($admin);
        $document = \Kaleta\Core\HtmlLimits::fragmentOrFail($html, null);
        foreach ($document->querySelectorAll('style') as $style) {
            $conversion->styles($style->textContent);
        }
        $elements = $conversion->children($document->body);

        // sections make up the top level of the build: continuous runs of other elements are wrapped into one section
        $root = [];
        $sequence = [];
        foreach ($elements as $p) {
            if ($p['typ'] === 'sekce') {
                if ($sequence !== []) {
                    $root[] = Build::fresh('sekce', [], $sequence);
                    $sequence = [];
                }
                $root[] = $p;
            } else {
                $sequence[] = $p;
            }
        }
        if ($sequence !== []) {
            $root[] = Build::fresh('sekce', [], $sequence);
        }

        return ['stavba' => ['v' => Build::VERSION, 'deti' => $root], 'tridy' => $conversion->classes, 'tridy_styl' => $conversion->classStyles,
            'hlaseni' => array_values(array_unique($conversion->messages))];
    }

    /**
     * HTML from the language model (MCP, the assistant in the builder) into the site: conversion, saving new classes from <style>
     * (an existing class of the site is overwritten only with $overwrite) and removing classes without a style.
     *
     * @return array{stavba: array<string, mixed>, hlaseni: list<string>}
     */
    public static function saveToSite(\Kaleta\Core\Db $db, string $html, bool $admin, bool $overwrite = false, ?\Kaleta\Core\Settings $settings = null): array
    {
        $conversion = self::convert($html, $admin);
        $messages = $conversion['hlaseni'];
        $existing = array_column($db->all('SELECT nazev FROM {tridy}'), 'nazev');
        foreach (array_unique(array_merge(array_keys($conversion['tridy']), array_keys($conversion['tridy_styl']))) as $className) {
            if (in_array($className, $existing, true) && !$overwrite) {
                $messages[] = 'Třída .' . $className . ' už na webu je – ponechána beze změny.';
                continue;
            }
            if (in_array($className, $existing, true) && $settings !== null) {
                // a change of a class the site has goes to the draft look (Core\Look)
                \Kaleta\Core\Look::setClass($settings, $className, ['styl' => $conversion['tridy_styl'][$className] ?? [], 'css' => $conversion['tridy'][$className] ?? '']);
                $messages[] = 'Class .' . $className . ' changed in the draft look – the site shows it after publish_look.';
                continue;
            }
            $style = (string) json_encode($conversion['tridy_styl'][$className] ?? new \stdClass(), JSON_UNESCAPED_UNICODE);
            $db->run('INSERT INTO {tridy} (nazev, styl, css, zmeneno) VALUES (?, ?, ?, ?) ON DUPLICATE KEY UPDATE styl = VALUES(styl), css = VALUES(css), zmeneno = VALUES(zmeneno)',
                [$className, $style, $conversion['tridy'][$className] ?? '', \Kaleta\Core\Clock::now()]);
        }
        $skipped = [];
        $build = self::withoutClasses($conversion['stavba'], array_merge($existing, array_keys($conversion['tridy']), array_keys($conversion['tridy_styl'])), $skipped);
        if ($skipped !== []) {
            $messages[] = 'Třídy bez stylu vynechány: ' . implode(', ', array_unique($skipped)) . '.';
        }

        return ['stavba' => $build, 'hlaseni' => $messages];
    }

    /**
     * Removes classes that have no style (from third-party CSS frameworks, WordPress…) – they would only take up space.
     *
     * @param list<string> $known classes that have a style
     * @param list<string> $skipped the removed ones are written here
     */
    public static function withoutClasses(array $node, array $known, array &$skipped = []): array
    {
        foreach ($node['deti'] ?? [] as $i => $p) {
            if (isset($p['tridy'])) {
                $skipped = array_merge($skipped, array_diff($p['tridy'], $known));
                $p['tridy'] = array_values(array_intersect($p['tridy'], $known));
                if ($p['tridy'] === []) {
                    unset($p['tridy']);
                }
            }
            $node['deti'][$i] = self::withoutClasses($p, $known, $skipped);
        }

        return $node;
    }

    /** @return list<array<string, mixed>> */
    private function children(Node $parent, int $depth = 0): array
    {
        $output = [];
        $flow = '';          // continuous text (paragraphs, lists) waiting to be merged into one Text element
        $questions = [];       // continuous <details> waiting to be merged into an FAQ element
        $flushFlow = function () use (&$output, &$flow, &$questions): void {
            if (trim(strip_tags($flow, '<img>')) !== '') {
                $output[] = Build::fresh('text', ['html' => $flow]);
            }
            $flow = '';
            if ($questions !== []) {
                $output[] = Build::fresh('faq', ['polozky' => $questions]);
                $questions = [];
            }
        };

        foreach ($parent->childNodes as $node) {
            if ($node->nodeType === XML_TEXT_NODE) {
                if (trim($node->textContent) !== '') {
                    $flow .= '<p>' . e(trim($node->textContent)) . '</p>';
                }
                continue;
            }
            if (!$node instanceof Element) {
                continue;
            }
            $htmlTag = strtolower($node->localName);
            if ($htmlTag === 'details' && ($question = $node->querySelector('summary')) !== null) {
                if ($flow !== '') {
                    $output[] = Build::fresh('text', ['html' => $flow]);
                    $flow = '';
                }
                $response = $node->cloneNode(true);
                $response->querySelector('summary')?->remove();
                $questions[] = ['otazka' => trim($question->textContent), 'odpoved' => trim($response->innerHTML)];
                continue;
            }
            // a paragraph without a class joins the continuous text; with a class it is a separate element, so that the class has a place
            if (in_array($htmlTag, self::TEXT_TAGS, true) && $this->classesOf($node) === [] && !$this->hasBlocks($node)) {
                if ($questions !== []) {
                    $flushFlow();
                }
                $flow .= self::html($node);
                continue;
            }
            $flushFlow();
            foreach ($this->element($node, $depth) as $p) {
                $output[] = $p;
            }
        }
        $flushFlow();

        return $output;
    }

    /** @return list<array<string, mixed>> one element, several elements (an unwrapped wrapper) or nothing */
    private function element(Element $el, int $depth): array
    {
        $htmlTag = strtolower($el->localName);
        if (in_array($htmlTag, self::UNWRAP, true)) {
            return $this->children($el, $depth);
        }
        if (in_array($htmlTag, self::SKIP, true)) {
            if ($htmlTag !== 'style') {
                $this->messages[] = 'Značka <' . $htmlTag . '> mimo formulář nemá ve stavbě obdobu – vynechána.';
            }

            return [];
        }
        if ($el->hasAttribute('style')) {
            $this->messages[] = 'Vložené styly (atribut style) se nepřevádějí – vzhled patří do tříd v <style>.';
        }
        if ($depth >= Build::MAX_DEPTH - 1) {
            $this->messages[] = 'Příliš hluboké vnoření – nejhlubší část převedena jako text.';

            return [Build::fresh('text', ['html' => self::html($el)])];
        }

        $p = match (true) {
            in_array($htmlTag, ['section', 'header', 'footer', 'aside', 'article', 'nav', 'div', 'li'], true) => $this->wrapper($el, $htmlTag, $depth),
            (bool) preg_match('/^h[1-6]$/', $htmlTag) => ['znacka' => $htmlTag] + Build::fresh('nadpis', ['text' => trim($el->innerHTML)]),
            in_array($htmlTag, self::TEXT_TAGS, true) => $this->textOrWrapper($el, $htmlTag, $depth),
            $htmlTag === 'img' => Build::fresh('obrazek', ['src' => $el->getAttribute('src') ?? '', 'alt' => $el->getAttribute('alt') ?? '']),
            $htmlTag === 'figure' => $this->figure($el, $depth),
            $htmlTag === 'a' => $this->link($el, $depth),
            $htmlTag === 'blockquote' => $this->quote($el),
            $htmlTag === 'hr' => Build::fresh('oddelovac'),
            $htmlTag === 'form' => $this->form($el),
            in_array($htmlTag, ['iframe', 'video'], true) && ($video = $this->video($el)) !== null => $video,
            in_array($htmlTag, ['svg', 'iframe', 'video', 'picture', 'audio'], true) => $this->customHtml($el),
            default => Build::fresh('text', ['html' => '<p>' . trim($el->innerHTML) . '</p>']),
        };
        if ($p === null) {
            return [];
        }
        if (($classes = $this->classesOf($el)) !== []) {
            $p['tridy'] = $classes;
            if (array_intersect($classes, array_keys($this->classes + $this->classStyles)) !== []) {
                // the appearance comes from the class in <style>: the element's default style (container flex, section padding) would override it – the elements layer comes after the classes in the cascade
                $p['styl'] = [];
            }
        }
        if (($id = $el->getAttribute('id')) !== null && preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $id)) {
            $p['kotva'] = $id;
        }

        return [$p];
    }

    /** section/div/… – at the top level a section (section, header, footer), elsewhere a container or grid. */
    private function wrapper(Element $el, string $htmlTag, int $depth): array
    {
        $children = $this->children($el, $depth + 1);
        if ($depth === 0 && in_array($htmlTag, ['section', 'header', 'footer', 'aside', 'article'], true)) {
            // the site's inner wrapper (.container, .wrapper) is needless in a section – the section has its own; it stays only when its class has a style
            if (count($children) === 1 && $children[0]['typ'] === 'kontejner' && array_intersect($children[0]['tridy'] ?? [], array_keys($this->classes)) === []) {
                $children = $children[0]['deti'];
            }

            return ['znacka' => $htmlTag] + Build::fresh('sekce', [], $children);
        }
        $htmlTag = in_array($htmlTag, Elements\Container::HTML_TAGS, true) ? $htmlTag : 'div';

        return ['znacka' => $htmlTag] + Build::fresh('kontejner', [], $children);
    }

    /** A list with a class or with complex items (cards in <ul>) is a container, a simple list with a class is a List element. */
    private function textOrWrapper(Element $el, string $htmlTag, int $depth): array
    {
        if (in_array($htmlTag, ['ul', 'ol'], true)) {
            if (!$this->hasBlocks($el)) {
                $items = [];
                // Element::$children exists only from PHP 8.5 (on 8.4 the list came out empty, with warnings)
                for ($li = $el->firstElementChild; $li !== null; $li = $li->nextElementSibling) {
                    $items[] = trim($li->textContent);
                }

                return ['znacka' => $htmlTag] + Build::fresh('seznam', ['polozky' => implode("\n", $items)]);
            }

            return ['znacka' => 'ul'] + Build::fresh('kontejner', [], array_map(
                fn (array $p): array => $p['typ'] === 'kontejner' ? ['znacka' => 'li'] + $p : ['znacka' => 'li'] + Build::fresh('kontejner', [], [$p]),
                $this->children($el, $depth + 1),
            ));
        }

        return Build::fresh('text', ['html' => self::html($el)]);
    }

    private function figure(Element $el, int $depth): ?array
    {
        $image = $el->querySelector('img');
        if ($image === null) {
            return ['znacka' => 'div'] + Build::fresh('kontejner', [], $this->children($el, $depth + 1));
        }

        return Build::fresh('obrazek', ['src' => $image->getAttribute('src') ?? '', 'alt' => $image->getAttribute('alt') ?? '', 'popisek' => trim($el->querySelector('figcaption')?->textContent ?? '')]);
    }

    /** A link with block content (a card) is a link container, a standalone text link is a button. */
    private function link(Element $el, int $depth): array
    {
        $url = $el->getAttribute('href') ?? '';
        if ($this->hasBlocks($el) || $el->querySelector('img') !== null) {
            return ['znacka' => 'div'] + Build::fresh('kontejner', ['odkaz' => $url], $this->children($el, $depth + 1));
        }
        $className = strtolower((string) $el->getAttribute('class'));
        $variant = match (true) {
            (bool) preg_match('/outline|obrys|ghost|secondary|sekundar/', $className) => 'obrys',
            (bool) preg_match('/\blink\b|odkaz/', $className) => 'odkaz',
            default => 'primarni',
        };

        return Build::fresh('tlacitko', ['text' => trim($el->textContent), 'odkaz' => $url, 'varianta' => $variant, 'nove_okno' => $el->getAttribute('target') === '_blank']);
    }

    /** Form → Form element: fields by the form controls and their labels; it always sends to the site's Enquiries. */
    private function form(Element $el): array
    {
        $field = [];
        $radios = []; // groups of <input type="radio"> by name → one choice field
        foreach ($el->querySelectorAll('input, select, textarea') as $input) {
            $type = strtolower((string) ($input->getAttribute('type') ?? 'text'));
            if (in_array($type, ['hidden', 'submit', 'button', 'reset', 'image', 'file', 'password'], true)) {
                if (in_array($type, ['file', 'password'], true)) {
                    $this->messages[] = 'Pole typu ' . $type . ' formulář nepodporuje – vynecháno.';
                }
                continue;
            }
            $labelText = $this->fieldLabel($el, $input);
            $required = $input->hasAttribute('required');
            if ($type === 'radio') {
                $displayName = (string) $input->getAttribute('name');
                if (!isset($radios[$displayName])) {
                    $radios[$displayName] = count($field);
                    $group = $input->closest('fieldset')?->querySelector('legend')?->textContent;
                    $field[] = ['popisek' => trim($group ?? $displayName), 'typ' => 'vyber', 'povinne' => $required, 'moznosti' => ''];
                }
                $field[$radios[$displayName]]['moznosti'] = ltrim($field[$radios[$displayName]]['moznosti'] . "\n" . $labelText);
                continue;
            }
            $field[] = match (true) {
                strtolower($input->localName) === 'textarea' => ['popisek' => $labelText, 'typ' => 'textarea', 'povinne' => $required, 'moznosti' => ''],
                strtolower($input->localName) === 'select' => ['popisek' => $labelText, 'typ' => 'vyber', 'povinne' => $required, 'moznosti' => implode("\n", array_filter(array_map(
                    fn (Element $o): string => ($o->getAttribute('value') ?? 'x') === '' ? '' : trim($o->textContent), iterator_to_array($input->querySelectorAll('option')),
                )))],
                $type === 'checkbox' => ['popisek' => $labelText, 'typ' => 'souhlas', 'povinne' => $required, 'moznosti' => ''],
                default => ['popisek' => $labelText, 'typ' => in_array($type, ['email', 'tel'], true) ? $type : 'text', 'povinne' => $required, 'moznosti' => ''],
            };
        }
        $button = $el->querySelector('button:not([type="button"]):not([type="reset"]), input[type="submit"]');
        $text = trim($button === null ? '' : ($button->localName === 'input' ? (string) $button->getAttribute('value') : $button->textContent));
        $this->messages[] = 'Formulář převeden na prvek Formulář: odesílá se do Poptávek webu a e-mailem (adresa v action se nepoužije).';

        return Build::fresh('formular', array_filter(['pole' => array_slice($field, 0, 20), 'tlacitko' => $text], fn (mixed $v): bool => $v !== '' && $v !== []));
    }

    private function fieldLabel(Element $form, Element $input): string
    {
        $id = $input->getAttribute('id');
        $label = $id !== null && $id !== '' ? $form->querySelector('label[for="' . addcslashes($id, '"\\') . '"]') : null;
        $label ??= $input->closest('label');
        if ($label !== null) {
            $copy = $label->cloneNode(true);
            foreach ($copy->querySelectorAll('input, select, textarea') as $v) {
                $v->remove();
            }
            $text = trim((string) preg_replace('/\s+/', ' ', $copy->textContent));
            if ($text !== '') {
                return rtrim($text, ' *:');
            }
        }

        $firstOption = strtolower($input->localName) === 'select' ? $input->querySelector('option[value=""]')?->textContent : null;

        return trim((string) ($input->getAttribute('placeholder') ?? $input->getAttribute('aria-label') ?? $firstOption ?? $input->getAttribute('name') ?? ''));
    }

    private function quote(Element $el): array
    {
        $signature = $el->querySelector('footer, cite, figcaption');
        $author = trim($signature?->textContent ?? '');
        $signature?->remove();
        $text = trim(preg_replace('#</?p[^>]*>#', ' ', $el->innerHTML) ?? '');

        return Build::fresh('citat', ['text' => $text, 'autor' => ltrim($author, "—–- \t")]);
    }

    private function video(Element $el): ?array
    {
        $src = $el->getAttribute('src') ?? $el->querySelector('source')?->getAttribute('src') ?? '';
        if (preg_match('#(youtube\.com/embed/|youtube-nocookie\.com/embed/)([\w-]{6,})#', $src, $m)) {
            return Build::fresh('video', ['url' => 'https://www.youtube.com/watch?v=' . $m[2], 'titulek' => $el->getAttribute('title') ?? '']);
        }
        if (preg_match('#player\.vimeo\.com/video/(\d+)#', $src, $m)) {
            return Build::fresh('video', ['url' => 'https://vimeo.com/' . $m[1], 'titulek' => $el->getAttribute('title') ?? '']);
        }

        return null;
    }

    private function customHtml(Element $el): ?array
    {
        if (!$this->admin) {
            $this->messages[] = 'Značka <' . strtolower($el->localName) . '> jde vložit jen jako Vlastní HTML, a to smí jen správce webu – vynechána.';

            return null;
        }

        return Build::fresh('html', ['kod' => self::html($el)]);
    }

    /** Does the element contain block tags (then it is not plain text but structure)? */
    private function hasBlocks(Element $el): bool
    {
        return $el->querySelector('div, section, article, header, footer, aside, nav, h1, h2, h3, h4, h5, h6, figure, img, blockquote, details, a.btn, a.button, a[class*="tlacitko"]') !== null;
    }

    /** @return list<string> classes in a form the build accepts */
    private function classesOf(Element $el): array
    {
        $classes = preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [];

        return array_values(array_filter($classes, fn (string $t): bool => preg_match(Build::CLASS_PATTERN, $t) === 1));
    }

    /**
     * Rules ".class { … }" from <style> become shared classes. ".class:hover" and @media (max-width: …) are converted to class
     * states (hover, tablet up to 1023 px, mobile up to 767 px) – the declarations that have a counterpart in the builder style. The rest is reported.
     */
    private function styles(string $css): void
    {
        $css = (string) preg_replace('#/\*.*?\*/#s', '', $css);
        // @media with max-width = a builder breakpoint; the block is processed and removed from the CSS
        $css = (string) preg_replace_callback('/@media\s*([^{]*)\{((?:[^{}]*\{[^{}]*\})*[^{}]*)\}/i', function (array $m): string {
            $state = $this->stateFromMedia($m[1]);
            if ($state === null) {
                $this->messages[] = 'Pravidlo @media ' . trim(mb_substr($m[1], 0, 60)) . ' se nepřevádí – builder má breakpointy @media (max-width: 1023px) = tablet a (max-width: 767px) = mobil; stylujte od desktopu dolů.';

                return '';
            }
            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $m[2], $rules, PREG_SET_ORDER);
            foreach ($rules as [, $selectors, $declarations]) {
                foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                    if (preg_match('/^\.([a-z][a-z0-9_-]*)(:hover|:focus-visible)?$/D', $selector, $t) && preg_match(Build::CLASS_PATTERN, $t[1])) {
                        $this->addClassState($t[1], isset($t[2]) ? 'hover_' . $state : $state, $declarations);
                    } elseif ($selector !== '') {
                        $this->messages[] = 'V @media se převádějí jen selektory jedné třídy; vynecháno: ' . mb_substr($selector, 0, 60) . '.';
                    }
                }
            }

            return '';
        }, $css);
        if (preg_match_all('/@(media|supports|container|keyframes|font-face|import|layer)\b/i', $css, $m)) {
            $this->messages[] = 'Pravidla @' . implode(', @', array_unique(array_map('strtolower', $m[1]))) . ' se nepřevádějí – nastavte je ve stylu prvku nebo třídy v builderu.';
            // nested blocks are removed so that their declarations do not end up in the wrong classes
            do {
                $css = (string) preg_replace('/@[a-z-]+[^{;]*\{(?:[^{}]*\{[^{}]*\})*[^{}]*\}|@[a-z-]+[^{;]*;/i', '', $css, -1, $count);
            } while ($count > 0);
        }
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
        $other = [];
        foreach ($rules as [, $selectors, $declarations]) {
            foreach (array_map('trim', explode(',', $selectors)) as $selector) {
                if (preg_match('/^\.([a-z][a-z0-9_-]*)$/D', $selector, $t) && preg_match(Build::CLASS_PATTERN, $t[1])) {
                    $discarded = [];
                    $safe = Style::customCss($declarations, $discarded);
                    $this->classes[$t[1]] = trim(($this->classes[$t[1]] ?? '') . ' ' . $safe);
                    foreach ($discarded as $d) {
                        $this->messages[] = 'Třída .' . $t[1] . ': nepovolená deklarace „' . mb_substr($d, 0, 60) . '“ vynechána.';
                    }
                } elseif (preg_match('/^\.([a-z][a-z0-9_-]*)(:hover|:focus-visible|:active)$/D', $selector, $t) && preg_match(Build::CLASS_PATTERN, $t[1])) {
                    $this->addClassState($t[1], $t[2] === ':active' ? 'aktivni' : 'hover', $declarations);
                } elseif ($selector !== '') {
                    $other[] = $selector;
                }
            }
        }
        if ($other !== []) {
            $this->messages[] = 'Převádějí se jen selektory jedné třídy (.karta, .karta:hover); vynecháno: ' . mb_substr(implode(', ', array_unique($other)), 0, 200) . '.';
        }
    }

    /** Builder breakpoint by the @media condition: max-width up to 767 px = mobile, up to 1023 px = tablet; another condition = null. */
    private function stateFromMedia(string $condition): ?string
    {
        if (!preg_match('/^\s*(?:screen\s+and\s+)?\(\s*max-width\s*:\s*(\d+(?:\.\d+)?)(px|rem|em)\s*\)\s*$/i', $condition, $m)) {
            return null;
        }
        $px = (float) $m[1] * (strtolower($m[2]) === 'px' ? 1 : 16);

        return match (true) {
            $px >= 600 && $px < 900 => 'mobil',
            $px >= 900 && $px <= 1280 => 'tablet',
            default => null,
        };
    }

    /** Declarations into a class state (hover, tablet…) as style properties; what cannot be converted is reported. */
    private function addClassState(string $className, string $state, string $declarations): void
    {
        if (!isset(Style::STATUSES[$state])) {
            return;
        }
        foreach (preg_split('/;(?![^(]*\))/', $declarations) ?: [] as $d) {
            if (!str_contains($d, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $d, 2));
            $properties = Style::fromCss($property, $value);
            if ($properties === null) {
                $this->messages[] = 'Třída .' . $className . ' (' . $state . '): deklaraci „' . mb_substr(trim($d), 0, 60) . '“ builder ve stavu neumí – vynechána.';
                continue;
            }
            $this->classStyles[$className][$state] = array_merge($this->classStyles[$className][$state] ?? [], $properties);
        }
    }

    /** The element's whole HTML including the tag. Dom\Element has the outerHTML property only from PHP 8.5 – Kaleta runs on 8.3 and 8.4 too. */
    private static function html(\Dom\Element $el): string
    {
        return $el->ownerDocument->saveHtml($el);
    }
}
