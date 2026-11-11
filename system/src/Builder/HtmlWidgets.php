<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Dom\Element;

/**
 * The widgets of page builders recognised in plain HTML (3.33): accordions and FAQ, tabs, video and map frames, embedded
 * booking pages and forms, counters, and forms whose fields cannot be read. By structure and meaning – the ARIA pattern
 * (a button with aria-controls and its panel, role="tab" and role="tabpanel"), <details>, the address of an <iframe>, a
 * data attribute holding a number – and a few class-name patterns the builders share (…accordion-item, …toggle_title,
 * …faq-answer, …tabs-nav). No builder has a code path of its own: Elementor, Divi, WPBakery, Gutenberg blocks, Breakdance
 * and the Wix, Squarespace and Webflow exports all use these shapes.
 *
 * Builder\HtmlConverter turns a recognised widget into its element (build_from_html); Core\WebImport takes the widgets out
 * of an old page before the page is sanitized (a closed accordion panel is aria-hidden, a question is a <button>, a map is an
 * <iframe> – the sanitizer would drop all of them) and hands them to the converter. Pure: no database, no network.
 */
final class HtmlWidgets
{
    /** What kind() answers. */
    public const array KINDS = ['form', 'unreadable_form', 'accordion', 'tabs', 'video', 'map', 'embed', 'counter'];

    /** Elements that may hold an accordion or tabs (a <section> keeps its own element – the widget is found inside it). */
    private const array CONTAINERS = ['div', 'ul', 'ol', 'dl', 'li', 'figure', 'span'];

    /** Elements that are never a widget themselves and never inside one we would see. */
    private const array SECTIONING = ['html', 'body', 'main', 'section', 'article', 'header', 'footer', 'aside', 'nav'];

    /** A class token of one accordion item: …accordion-item, …toggle-item, faq-item, schema-faq-section, kt-accordion-pane… */
    private const string ITEM = '/^(?:[a-z0-9]+[_-]{1,2})*(?:accordion|toggle|faq|qa|collapse|collapsible)[_-]{0,2}(?:item|section|pane|panel|entry|row|single)$|^(?:et_pb_toggle|et_pb_accordion_item|vc_toggle|vc_tta-panel)$/iD';

    /** A class token of the question inside an item. */
    private const string QUESTION = '/(?:title|question|header|heading|head|trigger|button|toggler|label|summary)$/iD';

    /** A class token of the answer inside an item. */
    private const string ANSWER = '/(?:content|answer|body|desc|description|text|dropdown|collapse|inner|panel)(?:[_-]?wrapper)?$/iD';

    /** A class token of the list of tab buttons (Divi et_pb_tabs_controls, WPBakery vc_tta-tabs-list, w-tab-menu…). */
    private const string TAB_NAV = '/(?:^|[_-])tabs?[_-]{0,2}(?:nav|navigation|list|controls|menu|titles|header|links|buttons|head)$/iD';

    /** A class token of one tab panel when the buttons do not point at it (Divi et_pb_tab, tab-pane, w-tab-pane…). */
    private const string TAB_PANEL = '/^(?:[a-z0-9]+[_-]{1,2})*tab[_-]{0,2}(?:pane|panel|content|item)$|^et_pb_tab$/iD';

    /** Data attributes that hold the target number of a counter. */
    private const array COUNTER_ATTRIBUTES = ['data-counter', 'data-count', 'data-countup', 'data-count-to', 'data-purecounter-end', 'data-to-value', 'data-number-value',
        'data-to', 'data-target', 'data-end', 'data-end-value', 'data-number', 'data-value', 'data-final', 'data-stop'];

    /** A class token or attribute name that says "this is a counter" (the generic data-to, data-target… need it). */
    private const string COUNTER_HINT = '/count|counter|number|odometer|stat(?!ic)|figure|milestone|kpi/i';

    /** Containers of forms a script draws in the browser – the page holds no fields to read (HubSpot, WPForms, Squarespace, Wix…). */
    private const string FORM_EMBED = '/(?:^|[\s_-])(?:hbspt-form|hs-form-frame|wpforms-container|gform_wrapper|nf-form-cont|frm_forms|fluentform|mc4wp-form|jotform|typeform-widget|form-block|sqs-block-form|react-form-contents|wixui-form|cognito|formstack|zf-templateWidth|ml-embedded|klaviyo-form)(?:$|[\s_-])/i';

    /** Hosts of form services whose frame the Embed element does not take. */
    private const string FORM_HOSTS = '~^https?://(?:[a-z0-9-]+\.)*(?:jotform\.com|hsforms\.com|hsforms\.net|zohopublic\.(?:com|eu)|cognitoforms\.com|formstack\.com|paperform\.co|wufoo\.com|123formbuilder\.com|surveymonkey\.com|docs\.google\.com/forms)(?:[/?#]|$)~i';

    /**
     * Which widget the element is, or null. Checked top-down, so an outer element that holds nothing but the widget is
     * the widget (its wrappers mean nothing in a build).
     */
    public static function kind(Element $el): ?string
    {
        $tag = strtolower($el->localName);
        if ($tag === 'form') {
            return self::isSearchForm($el) ? null : 'form';
        }
        if (in_array($tag, self::SECTIONING, true) || in_array($tag, ['a', 'script', 'style', 'template', 'noscript', 'svg', 'img', 'picture'], true)) {
            return null;
        }
        $frame = self::frameKind($el);
        if ($frame !== null) {
            return $frame;
        }
        if (in_array($tag, self::CONTAINERS, true)) {
            if (self::tabs($el) !== null) {
                return 'tabs';
            }
            if (self::accordion($el) !== null) {
                return 'accordion';
            }
            if (self::mapOfData($el) !== null) {
                return 'map';
            }
            if (self::embedOfData($el) !== null) {
                return 'embed';
            }
            if (preg_match(self::FORM_EMBED, (string) $el->getAttribute('class') . ' ' . (string) $el->getAttribute('id')) && $el->querySelector('form, input, textarea, select') === null) {
                return 'unreadable_form';
            }
        }

        return self::counter($el) !== null ? 'counter' : null;
    }

    /** A search box is not an enquiry form (role="search", only a search field, "search" in its class or action). */
    public static function isSearchForm(Element $form): bool
    {
        if (strtolower((string) $form->getAttribute('role')) === 'search' || preg_match('/search|hledat|suche|buscar/i', (string) $form->getAttribute('class') . ' ' . (string) $form->getAttribute('action'))) {
            return true;
        }
        $fields = $form->querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]), textarea, select');

        return $fields->length > 0 && $form->querySelectorAll('input[type="search"]')->length === $fields->length;
    }

    /** How many fields of a form a Form element can take (0 = the form cannot be read: its fields come from a script). */
    public static function formFields(Element $form): int
    {
        $count = 0;
        foreach ($form->querySelectorAll('input, select, textarea') as $input) {
            $type = strtolower((string) ($input->getAttribute('type') ?? 'text'));
            $count += in_array($type, ['hidden', 'submit', 'button', 'reset', 'image', 'file', 'password'], true) || self::trapField($input, $form) ? 0 : 1;
        }

        return $count;
    }

    /** Field names of captchas and honeypots (3.33 review L6) – never a question for a visitor. */
    private const string TRAP_NAMES = '/^(g-recaptcha-response|h-captcha-response|cf-turnstile-response|frc-captcha-solution|_gotcha|honeypot|hp|_hp|ak_hp_textarea|_wpcf7.*|wpforms\[hp\].*|fax_only)$/i';
    /** Classes of the wrappers page builders and form plugins give a honeypot. */
    private const string TRAP_CLASSES = '/(^|[\s_-])(honeypot|hp|wpforms-field-hp|gform_validation_container|ak_hp|nf-field-hp)($|[\s_-])/i';

    /**
     * A field a person never sees or fills in (3.33 review L6): a captcha's answer or a honeypot – hidden by an inline
     * display:none or visibility:hidden, the hidden or aria-hidden="true" attribute (on it or a wrapper inside the form),
     * tabindex="-1" on the field itself, a known name or a honeypot class. An imported form leaves it out.
     */
    public static function trapField(Element $input, Element $form): bool
    {
        if (preg_match(self::TRAP_NAMES, trim((string) $input->getAttribute('name'))) || trim((string) $input->getAttribute('tabindex')) === '-1') {
            return true;
        }
        for ($node = $input; $node instanceof Element && $node !== $form; $node = $node->parentElement) {
            $style = strtolower((string) preg_replace('/\s+/', '', (string) $node->getAttribute('style')));
            if ($node->hasAttribute('hidden') || strtolower(trim((string) $node->getAttribute('aria-hidden'))) === 'true'
                || str_contains($style, 'display:none') || str_contains($style, 'visibility:hidden')
                || preg_match(self::TRAP_CLASSES, (string) $node->getAttribute('class'))) {
                return true;
            }
        }

        return false;
    }

    /* ---------- frames: video, map, embedded pages ---------- */

    /** The kind of an <iframe>, a <video>, a lite-youtube player or an element whose attributes carry a video address. */
    private static function frameKind(Element $el): ?string
    {
        if (self::video($el) !== null) {
            return 'video';
        }
        $tag = strtolower($el->localName);
        if ($tag !== 'iframe') {
            return null;
        }
        $src = self::frameSource($el);

        return match (true) {
            self::mapOfUrl($src) !== null => 'map',
            Elements\Embed::resolve($src) !== null => 'embed',
            (bool) preg_match(self::FORM_HOSTS, $src) => 'unreadable_form',
            default => null,
        };
    }

    /** The address of a frame, also when it waits for lazy loading. */
    public static function frameSource(Element $el): string
    {
        return html_entity_decode(trim((string) ($el->getAttribute('src') ?: $el->getAttribute('data-src') ?: $el->getAttribute('data-lazy-src') ?: '')), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /**
     * A video: [address, title] – YouTube and Vimeo as their watch address, a file (mp4, webm, m4v) as it is written.
     * Also a player a script draws: Elementor keeps the address in data-settings, Squarespace in data-html, lite-youtube in
     * videoid. A link to a video stays a link.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function video(Element $el): ?array
    {
        $tag = strtolower($el->localName);
        $title = trim((string) $el->getAttribute('title'));
        if (in_array($tag, ['lite-youtube', 'lite-vimeo'], true) && preg_match('/^[\w-]{6,20}$/D', (string) $el->getAttribute('videoid'))) {
            return [$tag === 'lite-youtube' ? 'https://www.youtube.com/watch?v=' . $el->getAttribute('videoid') : 'https://vimeo.com/' . $el->getAttribute('videoid'), $title];
        }
        if ($tag === 'video') {
            $src = trim((string) ($el->getAttribute('src') ?: $el->getAttribute('data-src') ?: $el->querySelector('source[src]')?->getAttribute('src') ?: ''));

            return preg_match('#\.(mp4|webm|m4v)(?:[?\#].*)?$#iD', $src) ? [$src, $title] : (($online = self::videoOfUrl($src)) !== null ? [$online, $title] : null);
        }
        if ($tag === 'iframe') {
            $online = self::videoOfUrl(self::frameSource($el));

            return $online !== null ? [$online, $title] : null;
        }
        // a player a script draws: an element with (almost) no text whose attribute holds a video address
        if (mb_strlen(trim($el->textContent)) > 60 || $el->querySelector('img, iframe, video, a[href]') !== null) {
            return null;
        }
        foreach (iterator_to_array($el->attributes, false) as $attribute) {
            $name = strtolower($attribute->nodeName);
            if ($name === 'href' || $name === 'class' || $name === 'id' || !preg_match('/youtu|vimeo/i', $attribute->value)) {
                continue;
            }
            $online = self::videoOfUrl(str_replace('\\/', '/', html_entity_decode($attribute->value, ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            if ($online !== null) {
                return [$online, $title];
            }
        }

        return null;
    }

    /** The watch address of a YouTube or Vimeo address of any form (embed, short, watch, player), or null. */
    public static function videoOfUrl(string $url): ?string
    {
        $url = str_contains($url, '%2F') ? rawurldecode($url) : $url; // a player inside a player (Webflow's embedly frame: src=https%3A%2F%2Fwww.youtube.com…)
        if (preg_match('#(?:youtube(?:-nocookie)?\.com/(?:embed/|watch\?(?:[^"\'\s]*&)?v=|shorts/|live/|v/)|youtu\.be/)([\w-]{11})#', $url, $m)) {
            return 'https://www.youtube.com/watch?v=' . $m[1];
        }
        if (preg_match('#(?:player\.)?vimeo\.com/(?:video/|channels/[\w-]+/)?(\d{5,12})#', $url, $m)) {
            return 'https://vimeo.com/' . $m[1];
        }

        return null;
    }

    /**
     * A map frame as [address or "lat, lng", zoom of the Map element]: Google Maps (q=, the !3d!2d of the embed code), an
     * OpenStreetMap embed (marker, else the middle of bbox), Mapy.cz and Bing.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function mapOfUrl(string $url): ?array
    {
        $c = parse_url($url);
        if (!is_array($c) || !isset($c['host'])) {
            return null;
        }
        $host = strtolower($c['host']);
        parse_str((string) ($c['query'] ?? ''), $q);
        $q = array_map(fn (mixed $v): string => is_string($v) ? trim($v) : '', $q);
        $zoom = fn (mixed $z): string => self::zoom(is_numeric($z) ? (float) $z : 15.0);
        if (preg_match('/(^|\.)google\.[a-z.]+$/', $host) && (str_starts_with((string) ($c['path'] ?? ''), '/maps') || $host === 'maps.google.com' || str_starts_with($host, 'maps.google.'))) {
            if (($q['q'] ?? '') !== '' || ($q['query'] ?? '') !== '') {
                return [mb_substr(($q['q'] ?? '') !== '' ? $q['q'] : $q['query'], 0, 200), $zoom($q['z'] ?? 15)];
            }
            if (preg_match('/!3d(-?\d{1,3}\.\d+)!2d(-?\d{1,3}\.\d+)/', $q['pb'] ?? '', $m)) {
                return [self::point($m[1], $m[2]), '15'];
            }
            if (preg_match('/!2d(-?\d{1,3}\.\d+)!3d(-?\d{1,3}\.\d+)/', $q['pb'] ?? '', $m)) {
                return [self::point($m[2], $m[1]), '15'];
            }
            if (preg_match('#/@(-?\d{1,3}\.\d+),(-?\d{1,3}\.\d+),(\d+(?:\.\d+)?)z#', (string) ($c['path'] ?? ''), $m)) {
                return [self::point($m[1], $m[2]), $zoom($m[3])];
            }

            return null;
        }
        if (preg_match('/(^|\.)openstreetmap\.org$/', $host)) {
            if (preg_match('/^(-?\d{1,3}\.\d+),\s*(-?\d{1,3}\.\d+)$/', $q['marker'] ?? '', $m)) {
                return [self::point($m[1], $m[2]), '15'];
            }
            $box = array_map('floatval', explode(',', $q['bbox'] ?? ''));

            return count($box) === 4 ? [self::point((string) (($box[1] + $box[3]) / 2), (string) (($box[0] + $box[2]) / 2)), '15'] : null;
        }
        if (preg_match('/(^|\.)mapy\.(cz|com)$/', $host) && is_numeric($q['y'] ?? '') && is_numeric($q['x'] ?? '')) {
            return [self::point($q['y'], $q['x']), $zoom($q['z'] ?? 15)];
        }
        // 3.36: the Mapy.com embed of today (/fnc/v1/showmap?center=lng,lat&zoom=…)
        if (preg_match('/(^|\.)mapy\.(cz|com)$/', $host) && preg_match('/^(-?\d{1,3}(?:\.\d+)?),\s*(-?\d{1,3}(?:\.\d+)?)$/', $q['center'] ?? '', $m)) {
            return [self::point($m[2], $m[1]), $zoom($q['zoom'] ?? 15)];
        }
        if (preg_match('/(^|\.)bing\.com$/', $host) && preg_match('/^(-?\d{1,3}\.\d+)~(-?\d{1,3}\.\d+)$/', $q['cp'] ?? '', $m)) {
            return [self::point($m[1], $m[2]), $zoom($q['lvl'] ?? 15)];
        }

        return null;
    }

    /**
     * Is the address a map service's frame (3.36) – also one whose place cannot be read (a Mapy.cz short link, a Google
     * "My Maps" frame)? Then the conversion says to add a Map element with the address instead of a generic note.
     */
    public static function isMapFrame(string $url): bool
    {
        $c = parse_url($url);
        $host = is_array($c) ? strtolower((string) ($c['host'] ?? '')) : '';
        $path = is_array($c) ? (string) ($c['path'] ?? '') : '';

        return $host !== '' && (preg_match('/(^|\.)(openstreetmap\.org|mapy\.(cz|com)|waze\.com|maps\.apple\.com|here\.com|umap\.openstreetmap\.fr)$/', $host) === 1
            || (preg_match('/(^|\.)google\.[a-z.]+$/', $host) === 1 && (str_starts_with($path, '/maps') || str_starts_with($host, 'maps.')))
            || (preg_match('/(^|\.)bing\.com$/', $host) === 1 && str_starts_with($path, '/maps')));
    }

    /** A map drawn by a script from data attributes (Divi data-center-lat, data-lat and data-lng, data-address) on a map element. */
    public static function mapOfData(Element $el): ?array
    {
        if (!preg_match('/map/i', (string) $el->getAttribute('class') . ' ' . (string) $el->getAttribute('id'))) {
            return null;
        }
        // a map block whose pin or centre sits in a child (Divi: the map and its pins in one container)
        $point = $el->hasAttribute('data-lat') || $el->hasAttribute('data-latitude') || $el->hasAttribute('data-center-lat') ? $el
            : (mb_strlen(trim($el->textContent)) <= 200 && $el->querySelector('img, iframe, video, form') === null
                ? $el->querySelector('[data-center-lat][data-center-lng], [data-lat][data-lng], [data-latitude][data-longitude]') : null);
        if ($point === null) {
            $address = trim((string) $el->getAttribute('data-address'));

            return $address !== '' ? [mb_substr($address, 0, 200), '15'] : null;
        }
        $lat = $point->getAttribute('data-lat') ?? $point->getAttribute('data-latitude') ?? $point->getAttribute('data-center-lat');
        $lng = $point->getAttribute('data-lng') ?? $point->getAttribute('data-lon') ?? $point->getAttribute('data-longitude') ?? $point->getAttribute('data-center-lng');
        $zoom = $point->getAttribute('data-zoom') ?? $el->getAttribute('data-zoom');
        if (is_numeric($lat) && is_numeric($lng)) {
            return [self::point($lat, $lng), self::zoom(is_numeric($zoom) ? (float) $zoom : 15.0)];
        }

        return null;
    }

    /** An embedded booking page or form a script draws (Calendly's data-url, Typeform's data-tf-widget) as the address the Embed element takes. */
    public static function embedOfData(Element $el): ?string
    {
        $url = match (true) {
            $el->hasAttribute('data-tf-widget') && preg_match('/^[A-Za-z0-9]{4,20}$/D', (string) $el->getAttribute('data-tf-widget')) => 'https://form.typeform.com/to/' . $el->getAttribute('data-tf-widget'),
            str_contains((string) $el->getAttribute('class'), 'calendly-inline-widget') => trim((string) $el->getAttribute('data-url')),
            default => '',
        };

        return $url !== '' && Elements\Embed::resolve($url) !== null ? $url : null;
    }

    private static function point(string $lat, string $lng): string
    {
        return round((float) $lat, 6) . ', ' . round((float) $lng, 6);
    }

    /** The nearest zoom of the Map element (city 11, district 13, street 15, house 17). */
    private static function zoom(float $zoom): string
    {
        return match (true) {
            $zoom <= 12 => '11',
            $zoom <= 14 => '13',
            $zoom <= 16 => '15',
            default => '17',
        };
    }

    /* ---------- accordions and tabs ---------- */

    /**
     * The questions and answers of an accordion, when the element holds nothing else: <details> with <summary>, the ARIA
     * pattern (a button with aria-controls and its panel, also when the panel is hidden until opened), or items by the
     * class patterns the builders share. The answer is the panel's HTML as written – the caller sanitizes it.
     *
     * @return list<array{0: string, 1: string}>|null [question, answer HTML]
     */
    public static function accordion(Element $container): ?array
    {
        if (!in_array(strtolower($container->localName), self::CONTAINERS, true)
            || (strtolower($container->localName) !== 'dl' && $container->querySelector('details, dl, [aria-controls], [aria-expanded], [class*="accordion"], [class*="toggle"], [class*="faq"], [class*="collaps"], [class*="vc_tta-panel"], [class*="qa-"]') === null)) {
            return null;
        }
        $items = [];
        /** @var list<Element> $taken */
        $taken = [];
        $inside = function (Element $node) use (&$taken): bool { // by reference: the parts found so far
            return array_filter($taken, fn (Element $t): bool => $t === $node || $t->contains($node) || $node->contains($t)) !== [];
        };
        foreach ($container->querySelectorAll('details') as $details) {
            $summary = $details->querySelector('summary');
            if ($summary === null || $summary->closest('details') !== $details || $inside($details)) {
                continue;
            }
            $answer = self::clean($details);
            $answer->querySelector('summary')?->remove();
            $items[] = [self::label($summary), trim($answer->innerHTML)];
            $taken[] = $details;
        }
        $panels = [];
        foreach ($container->querySelectorAll('[aria-controls]') as $trigger) {
            if ($inside($trigger) || self::tabStyle($trigger, $container)) {
                continue;
            }
            $panel = self::target($container, (string) $trigger->getAttribute('aria-controls'));
            if ($panel === null || $panel->contains($trigger) || $trigger->contains($panel)) {
                continue;
            }
            $taken[] = $trigger;
            if (in_array($panel, $panels, true)) {
                continue; // a second button for the same panel (a mobile copy of the title)
            }
            $question = self::label($trigger);
            if ($question === '') {
                continue;
            }
            $panels[] = $panel;
            $taken[] = $panel;
            $items[] = [$question, trim(self::clean($panel)->innerHTML)];
        }
        // 3.36: a button with aria-expanded but no aria-controls opens the element right after it (or after the heading that
        // holds only the button) – never a menu: nothing inside a navigation, no aria-haspopup, no panel made of links only
        foreach ($container->querySelectorAll('[aria-expanded]') as $trigger) {
            if ($inside($trigger) || $trigger->hasAttribute('aria-controls') || $trigger->hasAttribute('aria-haspopup')
                || $trigger->closest('nav, header, [role="navigation"], [role="menubar"], [role="menu"]') !== null) {
                continue;
            }
            $panel = self::followingPanel($trigger, $container);
            $question = self::label($trigger);
            if ($panel === null || $question === '' || $inside($panel) || self::linksOnly($panel)) {
                continue;
            }
            $taken[] = $trigger;
            $taken[] = $panel;
            $items[] = [$question, trim(self::clean($panel)->innerHTML)];
        }
        // 3.36: a definition list of questions (<dt>) and answers (<dd>) – when it says it is an FAQ (class or id) or every term asks
        foreach (strtolower($container->localName) === 'dl' ? [$container] : iterator_to_array($container->querySelectorAll('dl')) as $list) {
            if ($inside($list)) {
                continue;
            }
            $pairs = self::definitionPairs($list);
            $hint = preg_match('/faq|question|accordion|otazk|q-?a(?:$|[\s_-])/i', (string) $list->getAttribute('class') . ' ' . (string) $list->getAttribute('id') . ' ' . (string) $container->getAttribute('class') . ' ' . (string) $container->getAttribute('id')) === 1;
            if ($pairs === [] || (!$hint && array_filter($pairs, fn (array $p): bool => !str_ends_with($p[0], '?')) !== [])) {
                continue;
            }
            $taken[] = $list;
            array_push($items, ...$pairs);
        }
        foreach ([$container, ...iterator_to_array($container->querySelectorAll('[class]'))] as $item) { // the element may be one item itself
            if (!self::hasToken($item, self::ITEM) || $inside($item)) {
                continue;
            }
            [$question, $answer] = self::itemParts($item);
            if ($question === null) {
                continue;
            }
            $taken[] = $item;
            $text = self::label($question);
            if ($text === '') {
                continue;
            }
            if ($answer !== null) {
                $html = trim(self::clean($answer)->innerHTML);
            } else {
                $rest = self::clean($item);
                foreach ($rest->querySelectorAll('[class]') as $copy) {
                    if ($copy->getAttribute('class') === $question->getAttribute('class') && self::label($copy) === $text) {
                        $copy->remove();
                        break;
                    }
                }
                $html = trim($rest->innerHTML);
            }
            $items[] = [$text, $html];
        }
        $items = array_values(array_filter($items, fn (array $i): bool => $i[0] !== ''));

        return $items !== [] && !self::contentOutside($container, $taken) ? array_slice($items, 0, 30) : null;
    }

    /**
     * The panel an aria-expanded button opens when it does not name it (3.36): the next element after the button, or after
     * the wrapper that holds only the button (<h3><button …>Question</button></h3><div>Answer</div>), with some text in it.
     */
    private static function followingPanel(Element $trigger, Element $container): ?Element
    {
        $node = $trigger;
        while ($node->nextElementSibling === null && ($parent = $node->parentElement) !== null && $parent !== $container && $container->contains($parent)
            && self::normalize($parent->textContent) === self::normalize($node->textContent)) {
            $node = $parent;
        }
        $panel = $node->nextElementSibling;

        return $panel !== null && !$panel->contains($trigger) && self::normalize($panel->textContent) !== '' ? $panel : null;
    }

    /** Is the element only links (a submenu, a list of pages) – then it is not an answer? */
    private static function linksOnly(Element $el): bool
    {
        $links = iterator_to_array($el->querySelectorAll('a[href]'));

        $bare = fn (string $text): string => (string) preg_replace('/[\s\x{A0}]+/u', '', $text);

        return count($links) >= 2 && $bare(implode('', array_map(fn (Element $a): string => $a->textContent, $links))) === $bare($el->textContent);
    }

    /**
     * The question and answer pairs of a definition list (3.36): each <dt> of this list (also inside the <div> rows HTML
     * allows) with the <dd> elements after it.
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function definitionPairs(Element $list): array
    {
        $pairs = [];
        foreach ($list->querySelectorAll('dt') as $term) {
            if ($term->closest('dl') !== $list) {
                continue;
            }
            $answer = '';
            for ($next = $term->nextElementSibling; $next !== null && strtolower($next->localName) === 'dd'; $next = $next->nextElementSibling) {
                $inner = trim(self::clean($next)->innerHTML);
                $answer .= $next->querySelector('p, ul, ol, div, table') !== null ? $inner : '<p>' . $inner . '</p>';
            }
            $question = self::label($term);
            if ($question !== '' && $answer !== '') {
                $pairs[] = [$question, $answer];
            }
        }

        return $pairs;
    }

    /**
     * The tabs of a tab widget, when the element holds nothing else: role="tab" (or the links of a tab list) pointing at
     * their panels by aria-controls or #id, or – Divi – the items of the tab list and the panels in the same order.
     *
     * @return list<array{0: string, 1: string}>|null [tab name, content HTML]
     */
    public static function tabs(Element $container): ?array
    {
        if (!in_array(strtolower($container->localName), self::CONTAINERS, true) || $container->querySelector('[role="tab"], [class*="tab"]') === null) {
            return null;
        }
        $tabs = [];
        $taken = [];
        $panels = [];
        $controls = iterator_to_array($container->querySelectorAll('[role="tab"]'));
        foreach ($container->querySelectorAll('[class]') as $nav) {
            if (self::hasToken($nav, self::TAB_NAV)) {
                array_push($controls, ...iterator_to_array($nav->querySelectorAll('a[href^="#"], button[aria-controls]')));
            }
        }
        foreach ($controls as $control) {
            $id = (string) ($control->getAttribute('aria-controls') ?: ltrim((string) $control->getAttribute('href'), '#'));
            $panel = $id !== '' ? self::target($container, $id) : null;
            if ($panel === null || $panel->contains($control) || !self::tabStyle($control, $container, $panel)) {
                continue;
            }
            if (in_array($panel, $panels, true)) {
                $taken[] = $control; // a second button for the same panel (Elementor's title for phones)
                continue;
            }
            $name = self::label($control);
            if ($name === '') {
                continue;
            }
            $panels[] = $panel;
            $taken[] = $control->closest('[role="tablist"]') ?? $control;
            $taken[] = $panel;
            $tabs[] = [$name, self::panelHtml($panel)];
        }
        foreach ($controls as $control) {
            // the copies of the titles next to their panels (Elementor's titles for phones) belong to the tabs too
            $panel = self::target($container, (string) ($control->getAttribute('aria-controls') ?: ltrim((string) $control->getAttribute('href'), '#')));
            if ($panel !== null && in_array($panel, $panels, true)) {
                $taken[] = $control;
            }
        }
        if ($tabs === []) {
            // Divi: the items of the tab list and the panels, in the same order
            foreach ($container->querySelectorAll('[class]') as $nav) {
                if (!self::hasToken($nav, self::TAB_NAV)) {
                    continue;
                }
                $names = array_values(array_filter(array_map(fn (Element $li): string => self::label($li), iterator_to_array($nav->querySelectorAll('li')))));
                $found = array_values(array_filter(iterator_to_array($container->querySelectorAll('[class]')), fn (Element $p): bool => self::hasToken($p, self::TAB_PANEL) && !$nav->contains($p)));
                $found = array_values(array_filter($found, fn (Element $p): bool => array_filter($found, fn (Element $outer): bool => $outer !== $p && $outer->contains($p)) === []));
                if (count($names) >= 2 && count($names) === count($found)) {
                    $taken = [$nav, ...$found];
                    $tabs = array_map(fn (string $name, Element $panel): array => [$name, self::panelHtml($panel)], $names, $found);
                }
                break;
            }
        }

        return count($tabs) >= 2 && !self::contentOutside($container, $taken) ? array_slice($tabs, 0, 12) : null;
    }

    /**
     * Is the control a tab (the tab list holds the buttons, the panels lie outside it)? Old Elementor accordions use
     * role="tab" too, but there every panel sits inside the role="tablist" next to its title.
     */
    private static function tabStyle(Element $control, Element $container, ?Element $panel = null): bool
    {
        $panel ??= self::target($container, (string) $control->getAttribute('aria-controls'));
        if ($panel === null) {
            return false;
        }
        $list = $control->closest('[role="tablist"]');
        if ($list !== null && $container->contains($list)) {
            return !$list->contains($panel);
        }
        $nav = $control->parentElement;
        for ($depth = 0; $nav !== null && $depth < 4 && $container->contains($nav); $depth++, $nav = $nav->parentElement) {
            if (self::hasToken($nav, self::TAB_NAV)) {
                return !$nav->contains($panel);
            }
        }

        return strtolower((string) $control->getAttribute('role')) === 'tab' && strtolower((string) $panel->getAttribute('role')) === 'tabpanel';
    }

    /** A tab panel's HTML without the copy of the tab title some builders put inside for phones (WPBakery, Elementor). */
    private static function panelHtml(Element $panel): string
    {
        $copy = self::clean($panel);
        foreach (iterator_to_array($copy->querySelectorAll('[class*="panel-heading"], [class*="mobile-title"], [class*="tab-title"]')) as $heading) {
            if ($copy->contains($heading)) {
                $heading->remove();
            }
        }

        return trim($copy->innerHTML);
    }

    /**
     * The question and the answer inside one accordion item found by its class: the question is a <summary>, an element
     * whose class ends like a title (…title, …question, …heading, …trigger), a heading or a button; the answer an element
     * whose class ends like content (…content, …answer, …body, …dropdown) outside the question.
     *
     * @return array{0: Element|null, 1: Element|null}
     */
    private static function itemParts(Element $item): array
    {
        $question = null;
        foreach ($item->querySelectorAll('summary, [class], h2, h3, h4, h5, h6, button, dt, strong') as $candidate) {
            $tag = strtolower($candidate->localName);
            if ($tag === 'summary' || self::hasToken($candidate, self::QUESTION) || ($tag !== 'strong' && in_array($tag, ['h2', 'h3', 'h4', 'h5', 'h6', 'button', 'dt'], true))
                || ($tag === 'strong' && $candidate->parentElement === $item)) {
                if (self::label($candidate) !== '') {
                    $question = $candidate;
                    break;
                }
            }
        }
        if ($question === null) {
            return [null, null];
        }
        $answer = null;
        foreach ($item->querySelectorAll('[class], dd') as $candidate) {
            if ((strtolower($candidate->localName) === 'dd' || self::hasToken($candidate, self::ANSWER)) && !$candidate->contains($question) && !$question->contains($candidate)
                && trim($candidate->textContent) !== '') {
                $answer = $candidate;
                break;
            }
        }

        return [$question, $answer];
    }

    /* ---------- counters ---------- */

    /**
     * A counter: the number (from a data attribute – the page often shows 0 until the number counts up – or the text), what
     * stands before and after it (+, %) and its label, when the element holds just that. A row of several counters is not
     * one, nor is a block with a heading and a separate label (the counter is found inside it).
     *
     * @return array{cislo: string, pred: string, za: string, popisek: string}|null
     */
    public static function counter(Element $el): ?array
    {
        $tag = strtolower($el->localName);
        if (in_array($tag, self::SECTIONING, true) || in_array($tag, ['a', 'button', 'img', 'iframe', 'table', 'ul', 'ol', 'form'], true)) {
            return null;
        }
        $all = self::normalize($el->textContent);
        if (mb_strlen($all) > 150 || $el->querySelector('img, iframe, video, form, table, a[href], button') !== null) {
            return null;
        }
        $numbers = array_values(array_filter([$el, ...iterator_to_array($el->querySelectorAll('*'))], self::isNumberElement(...)));
        if (count($numbers) !== 1 || self::depth($el, $numbers[0]) > 3) {
            return null;
        }
        $number = $numbers[0];
        $value = self::numberValue($number);
        $labels = self::counterLabels($el, $number);
        if ($value === null || count($labels) > 1) {
            return null;
        }
        $label = $labels !== [] ? self::normalize($labels[0]->textContent) : '';
        // the number's line is the rest: the number with what stands before and after it
        $line = $label !== '' ? trim(implode('', explode($label, $all, 2))) : $all;
        // the digits the number shows (often 0 until it counts up; nothing when the number's attribute sits on the whole block)
        $shown = $number !== $el && $number->querySelector('*') === null && preg_match('/\d[\d\s.,\x{A0}]*\d|\d/u', self::normalize($number->textContent), $m) ? $m[0] : '';
        if ($shown !== '' && str_contains($line, $shown)) {
            [$before, $after] = explode($shown, $line, 2);
        } elseif (preg_match('/^(.*?)\d[\d\s.,\x{A0}]*(.*)$/u', $line, $m)) {
            [$before, $after] = [$m[1], $m[2]];
        } else {
            [$before, $after] = ['', $line]; // the number shows only after it counts up: the rest is its sign (Divi: %)
        }
        // words after the sign are the label when no element holds one ("250+ projects")
        if ($label === '' && preg_match('/^([^\p{L}]{0,6})(\p{L}.*)$/u', $after, $m)) {
            [$after, $label] = [$m[1], trim($m[2])];
        }

        // a space between the number and a word stays as a no-break space (the builder trims the content's text fields): "15 years"
        $before = (string) preg_replace('/\s+$/u', "\u{A0}", ltrim($before));
        $after = (string) preg_replace('/^\s+/u', "\u{A0}", rtrim($after));

        return ['cislo' => $value, 'pred' => mb_substr(trim($before) === '' ? '' : $before, 0, 10), 'za' => mb_substr(trim($after) === '' ? '' : $after, 0, 20), 'popisek' => mb_substr($label, 0, 120)];
    }

    /** Levels between an element and a descendant. */
    private static function depth(Element $el, Element $descendant): int
    {
        $depth = 0;
        for ($node = $descendant; $node !== null && $node !== $el; $node = $node->parentElement) {
            $depth++;
        }

        return $depth;
    }

    /**
     * The elements that may be a counter's label: a heading, a paragraph or an element whose class ends like a label
     * (…title, …label, …text) – with letters, outside the number (inside it when the number's attribute sits on the whole
     * block, as in Divi), none inside another.
     *
     * @return list<Element>
     */
    private static function counterLabels(Element $el, Element $number): array
    {
        $found = [];
        foreach ($el->querySelectorAll('h1, h2, h3, h4, h5, h6, p, [class]') as $candidate) {
            $tag = strtolower($candidate->localName);
            if ($candidate === $number || $candidate->contains($number) || ($number !== $el && $number->contains($candidate))
                || (!preg_match('/^(h[1-6]|p)$/', $tag) && !self::hasToken($candidate, '/(?:title|label|text|desc|description|caption|name|subtitle)$/i'))
                || !preg_match('/\p{L}/u', $candidate->textContent) || array_filter($found, fn (Element $f): bool => $f->contains($candidate)) !== []) {
                continue;
            }
            $found[] = $candidate;
        }

        return $found;
    }

    /** Is this the element that holds a counter's number (a data attribute with a number, or a counter class and a number as its text)? */
    private static function isNumberElement(Element $el): bool
    {
        $hint = preg_match(self::COUNTER_HINT, (string) $el->getAttribute('class') . ' ' . (string) $el->parentElement?->getAttribute('class')) === 1;
        foreach (self::COUNTER_ATTRIBUTES as $name) {
            $value = trim((string) $el->getAttribute($name));
            if ($value !== '' && is_numeric(str_replace([',', ' '], '', $value)) && ($hint || preg_match('/count|number/', $name))) {
                return true;
            }
        }

        if ($el->firstElementChild !== null || preg_match('/^\D{0,3}\d[\d\s.,]*\D{0,6}$/u', self::normalize($el->textContent)) !== 1) {
            return false;
        }
        if (preg_match('/(?:^|[\s_-])(?:counter|count-?up|countup|odometer|purecounter|stat-number|counter-number)(?:$|[\s_-])/i', (string) $el->getAttribute('class'))) {
            return true;
        }
        // 3.36: a plain figure block – the number alone in an element whose class (or its parent's) says number, stat, kpi…
        // ("<div class="stat"><strong>250+</strong><span>projects</span></div>"); never a phone number, a price or a date
        $classes = (string) $el->getAttribute('class') . ' ' . (string) $el->parentElement?->getAttribute('class');

        return $hint && preg_match_all('/\d/', $el->textContent) <= 7 && !preg_match('/phone|tel(?:$|[\s_-])|fax|zip|postal|post-?code|price|cost|amount|date|time|clock|rating|ico|vat/i', $classes);
    }

    /** The number of a counter's number element: its data attribute (the page may show 0 until it counts), else the digits of its text. */
    private static function numberValue(Element $el): ?string
    {
        foreach (self::COUNTER_ATTRIBUTES as $name) {
            $value = trim((string) $el->getAttribute($name));
            if ($value !== '' && is_numeric(str_replace([',', ' '], '', $value))) {
                $float = (float) str_replace([',', ' '], '', $value);

                return $float == floor($float) && abs($float) < 1e9 ? (string) (int) $float : (string) round($float, 2);
            }
        }
        if (preg_match('/\d[\d\s.,\x{A0}]*/u', self::normalize($el->textContent), $m)) {
            $digits = trim($m[0], " .,\u{A0}");

            // 1,200 / 1 200 / 1.200 = twelve hundred; 4.9 or 4,9 = a decimal number
            return preg_match('/^\d{1,3}(?:[\s.,\x{A0}]\d{3})+$/u', $digits) ? (string) preg_replace('/\D/', '', $digits) : str_replace(',', '.', $digits);
        }

        return null;
    }

    /* ---------- shared helpers ---------- */

    /** The element $id names inside the container (aria-controls or #id), or null. */
    private static function target(Element $container, string $id): ?Element
    {
        $id = trim(explode(' ', trim($id))[0]);

        return preg_match('/^[\w:.-]{1,120}$/uD', $id) ? $container->querySelector('[id="' . $id . '"]') : null;
    }

    /** Does one of the element's classes match the pattern? */
    private static function hasToken(Element $el, string $pattern): bool
    {
        foreach (preg_split('/\s+/', trim((string) $el->getAttribute('class'))) ?: [] as $token) {
            if ($token !== '' && preg_match($pattern, $token)) {
                return true;
            }
        }

        return false;
    }

    /** The text of a question or a tab name without icons, screen-reader copies and the +/− signs around it. */
    public static function label(Element $el): string
    {
        $copy = $el->cloneNode(true);
        foreach (iterator_to_array($copy->querySelectorAll('svg, script, style, noscript, template, [aria-hidden="true"], [class*="icon"]')) as $noise) {
            if ($copy->contains($noise)) { // one inside another removed before is gone with it
                $noise->remove();
            }
        }

        return mb_substr(trim(self::normalize($copy->textContent), " +−–-×▾▸▼►›»⌄˅\u{A0}"), 0, 300);
    }

    /** A copy of the element without scripts, styles, icons and buttons that only open and close it. */
    private static function clean(Element $el): Element
    {
        $copy = $el->cloneNode(true);
        foreach (iterator_to_array($copy->querySelectorAll('script, style, noscript, template, svg, button[aria-controls], [class*="icon"]')) as $noise) {
            if ($copy->contains($noise)) {
                $noise->remove();
            }
        }

        return $copy;
    }

    /**
     * Is there content in the container outside the widget's parts – text, an image, a frame, a form, a table? Icons,
     * buttons, scripts and empty wrappers are not content.
     *
     * @param list<Element> $parts
     */
    private static function contentOutside(Element $container, array $parts): bool
    {
        if (in_array($container, $parts, true)) {
            return false; // the element is the one part (a single accordion item)
        }
        foreach ($container->childNodes as $node) {
            if ($node instanceof Element) {
                if (in_array($node, $parts, true)) {
                    continue;
                }
                $tag = strtolower($node->localName);
                if (in_array($tag, ['img', 'picture', 'video', 'iframe', 'form', 'input', 'select', 'textarea', 'table', 'canvas', 'object', 'embed'], true)) {
                    return true;
                }
                if (in_array($tag, ['svg', 'script', 'style', 'noscript', 'template', 'button'], true) || strtolower((string) $node->getAttribute('aria-hidden')) === 'true') {
                    continue;
                }
                if (self::contentOutside($node, $parts)) {
                    return true;
                }
            } elseif ($node->nodeType === XML_TEXT_NODE && trim($node->textContent, " \t\n\r\0\x0B\u{A0}") !== '') {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $text): string
    {
        return trim((string) preg_replace('/[\s\x{A0}]+/u', ' ', $text));
    }
}
