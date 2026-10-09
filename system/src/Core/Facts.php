<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Admin\ChangeLog;
use Kaleta\Builder\Build;

/**
 * Business facts (2.10): what the site states in many places – the year the company was founded, the number of
 * projects, a price from, the phone – kept once and written as {{fact.key}} in pages, site parts, pop-ups, components
 * and news. The site fills them in when a page is shown (never in the builder, so the token stays in the build), adds
 * facts with a schema.org property to the organisation, lists them in llms.txt and offers them to Claude.
 *
 *  - Built-in facts come from Business details (company_phone, company_address…), the site name and the current year;
 *    they cannot be edited here.
 *  - A language version may have its own value of a fact; without one the default value is used.
 *  - Every change of a value is kept (ka_fact_history), and occurrences() finds the sentences that still state the old
 *    value as plain text – the claims inventory.
 *  - Computed facts (COMPUTED_PATTERN, computed()) are worked out when a page is shown: years since a year, a date or a
 *    fact, the number of visible items of a collection or of published news – they never go stale.
 */
final class Facts
{
    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/D';

    /** {{fact.key}} – spaces inside the braces are allowed. */
    public const string TOKEN_PATTERN = '/\{\{\s*fact\.([a-z][a-z0-9_]{1,39})\s*\}\}/';

    /** <code>…</code> and <pre>…</pre>: tokens inside are examples and are never filled in. */
    private const string CODE_PATTERN = '#(<code\b[^>]*>.*?</code>|<pre\b[^>]*>.*?</pre>)#is';

    /** {{hours.status}} and {{hours.today}} – opening hours with their exceptions (Core\Hours). */
    public const string HOURS_PATTERN = '/\{\{\s*hours\.(status|today)\s*\}\}/';

    /**
     * Computed facts – numbers the site works out when a page is shown, so they never go stale:
     * {{years_since:2004}} or {{years_since:2004-05-01}} (full years since), {{years_since:fact.founded}} (from a fact
     * with a year or a date), {{count:<collection address>}} (visible items) and {{count:news}} (published news).
     */
    public const string COMPUTED_PATTERN = '/\{\{\s*(years_since|count):\s*([a-z0-9][a-z0-9_.-]{0,120})\s*\}\}/';

    /**
     * Attributes a browser follows as an address. A token in one is filled and the whole value is then checked like any
     * other link (3.3.2, N26): a text fact "javascript:…" behind {{fact.promo}} in a button, page text or Custom HTML must
     * never become a script link. The check runs on the filled-in value, never only on the token. srcset and imagesrcset
     * hold a list of addresses; each one is checked (3.3.3, N64).
     */
    private const array URL_ATTRIBUTES = ['href', 'src', 'srcset', 'imagesrcset', 'action', 'formaction', 'poster', 'cite', 'background', 'data', 'ping', 'xlink:href',
        'longdesc', 'lowsrc', 'dynsrc', 'codebase', 'usemap', 'manifest', 'icon'];

    /** Any token fill() replaces, anchored where the reader stands – inside a tag it is one unit, as the browser sees the value that replaces it. */
    private const string ANY_TOKEN = '/\G\{\{\s*(?:fact\.[a-z][a-z0-9_]{1,39}|hours\.(?:status|today)|(?:years_since|count):\s*[a-z0-9][a-z0-9_.-]{0,120})\s*\}\}/';

    /** Elements whose content the browser reads as text up to their end tag, never as markup (in HTML – not inside <svg> or <math>). */
    private const array RAW_TEXT = ['script', 'style', 'xmp', 'iframe', 'noembed', 'noframes', 'noscript', 'textarea', 'title'];

    /** Start tags that end <svg> and <math> content (the HTML standard's breakout list; <font> only with color, face or size). */
    private const array BREAKOUT = ['b', 'big', 'blockquote', 'body', 'br', 'center', 'code', 'dd', 'div', 'dl', 'dt', 'em', 'embed', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'head', 'hr', 'i', 'img', 'li', 'listing', 'menu', 'meta', 'nobr', 'ol', 'p', 'pre', 'ruby', 's', 'small', 'span', 'strong', 'strike', 'sub', 'sup', 'table', 'tt', 'u', 'ul', 'var'];

    /** Elements inside <svg> and <math> whose content is HTML again. */
    private const array INTEGRATION = ['svg' => ['foreignobject', 'desc', 'title'], 'math' => ['mi', 'mo', 'mn', 'ms', 'mtext']];

    /** A fact or computed token on its own – what a number field of an element may hold instead of digits. */
    public const string NUMBER_TOKEN_PATTERN = '/^\{\{\s*(fact\.[a-z][a-z0-9_]{1,39}|(years_since|count):\s*[a-z0-9][a-z0-9_.-]{0,120})\s*\}\}$/D';

    public const array TYPES = ['text' => 'text', 'number' => 'number', 'money' => 'amount of money', 'date' => 'date', 'year' => 'year', 'phone' => 'phone', 'email' => 'e-mail', 'url' => 'web address'];

    /** schema.org properties of the organisation a fact may fill (Front\Company::schema). */
    public const array SCHEMA_PROPS = ['' => '—', 'foundingDate' => 'founding date', 'numberOfEmployees' => 'number of employees', 'priceRange' => 'price range',
        'slogan' => 'slogan', 'award' => 'award', 'areaServed' => 'area served', 'knowsLanguage' => 'languages', 'founder' => 'founder'];

    /** Built-in facts from the settings (read-only) => label. */
    public const array BUILT_IN = ['site_name' => 'Site name', 'company_name' => 'Company name', 'company_phone' => 'Phone', 'company_email' => 'E-mail',
        'company_address' => 'Address', 'company_id' => 'Company ID', 'company_vat_id' => 'VAT ID', 'year' => 'Current year',
        'google_rating' => 'Google rating', 'google_reviews' => 'Google reviews (count)']; // the last two from the Business Profile (2.13, Core\GoogleBusiness)

    /** @var array<string, array<string, array<string, mixed>>> facts by language for this request */
    private static array $cache = [];

    /** @var array<string, ?int> counts of collections and news for this request (null = cannot be counted) */
    private static array $counts = [];

    /**
     * Every fact for a language version: the site's own ones (with the default value where the language has none) and
     * the built-in ones.
     *
     * @return array<string, array{key: string, label: string, type: string, value: string, display: string, schema: string, source: string, updated: ?string, builtIn: bool, translated: bool}>
     */
    public static function all(App $app, string $language = ''): array
    {
        if (isset(self::$cache[$language])) {
            return self::$cache[$language];
        }
        $s = $app->settings();
        $out = [];
        foreach (self::BUILT_IN as $key => $label) {
            $value = match ($key) {
                'site_name' => $s->get('site_name'),
                'company_address' => implode(', ', \Kaleta\Front\Company::address($s)),
                'year' => date('Y'),
                default => $s->get($key),
            };
            $type = match ($key) { 'company_phone' => 'phone', 'company_email' => 'email', 'year' => 'year', 'google_rating', 'google_reviews' => 'number', default => 'text' };
            $out[$key] = ['key' => $key, 'label' => t($label), 'type' => $type, 'value' => $value, 'display' => $value, 'schema' => '', 'source' => '', 'updated' => null, 'builtIn' => true, 'translated' => false];
        }
        try {
            $rows = $app->db()->all('SELECT * FROM {facts} WHERE language IN (?, ?) ORDER BY fact_key, language', ['', $language]);
        } catch (\Throwable) {
            $rows = []; // before the 2.10 migration
        }
        foreach ($rows as $r) {
            $key = (string) $r['fact_key'];
            if ((string) $r['language'] !== '' && isset($out[$key])) {
                $out[$key]['value'] = (string) $r['value'];
                $out[$key]['translated'] = true;
                $out[$key]['display'] = self::display((string) $out[$key]['type'], (string) $r['value']);
                continue;
            }
            $out[$key] = ['key' => $key, 'label' => (string) $r['label'], 'type' => (string) $r['type'], 'value' => (string) $r['value'], 'display' => self::display((string) $r['type'], (string) $r['value']),
                'schema' => (string) $r['schema_prop'], 'source' => (string) $r['source'], 'updated' => (string) $r['updated_at'], 'builtIn' => false, 'translated' => false];
        }

        return self::$cache[$language] = $out;
    }

    /** The value as the site shows it: numbers with the thousands separator of the language, a date in its format. */
    public static function display(string $type, string $value): string
    {
        return match ($type) {
            'number' => is_numeric($value) ? format_count((float) $value, str_contains($value, '.') ? strlen(substr(strrchr($value, '.') ?: '', 1)) : 0) : $value,
            'money' => preg_match('/^(\d+(?:\.\d+)?)\s*([A-Z]{3})?$/', $value, $m) ? format_count((float) $m[1], str_contains($m[1], '.') ? 2 : 0) . (($m[2] ?? '') !== '' ? "\u{00A0}" . $m[2] : '') : $value,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? format_date($value) : $value,
            default => $value,
        };
    }

    /**
     * Does a text value begin with an address scheme other than http, https, mailto or tel – javascript:, data:,
     * vbscript:…? A text fact like that is refused when it is saved or imported (3.3.3, N50, defence in depth: every filled
     * address is checked on the site anyway). The value is read as a browser reads an address: control characters and
     * spaces at the ends and tabs and line breaks inside do not count. Ordinary text with a space after the colon
     * ("Note: …", "Open: 8–16") stays allowed, except after a scheme that runs code.
     */
    public static function startsWithScheme(string $value): bool
    {
        $value = (string) preg_replace('/[\t\n\r]+/', '', trim($value, "\x00..\x20"));
        if (preg_match('/^([a-z][a-z0-9+.\-]*):(.?)/is', $value, $m) !== 1 || in_array(strtolower($m[1]), ['http', 'https', 'mailto', 'tel'], true)) {
            return false;
        }

        return in_array(strtolower($m[1]), ['javascript', 'vbscript', 'livescript', 'data'], true) || preg_match('/^[\x00-\x20]$/', $m[2]) !== 1;
    }

    /** A value checked by the type of the fact; null = not valid. */
    public static function clean(string $type, string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, '{{')) {
            return $value === '' ? '' : null; // a fact never contains another token
        }

        return match ($type) {
            'number' => preg_match('/^-?\d+(\.\d+)?$/', str_replace([' ', "\u{00A0}"], '', $value)) ? str_replace([' ', "\u{00A0}"], '', $value) : null,
            'money' => preg_match('/^\d+(\.\d{1,2})?(\s+[A-Z]{3})?$/', $value) ? $value : null,
            'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) && strtotime($value) !== false ? $value : null,
            'year' => preg_match('/^\d{4}$/', $value) ? $value : null,
            'phone' => preg_match('/^[+()\d\s\/.-]{3,30}$/', $value) ? $value : null,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false ? $value : null,
            'url' => preg_match('#^https?://[^\s"<>]{3,400}$#iD', $value) ? $value : null,
            default => mb_substr(strip_tags($value), 0, 500),
        };
    }

    /**
     * Fills {{fact.key}}, {{hours.*}} and the computed tokens in HTML of the site (escaped); an unknown fact or a token that
     * cannot be computed becomes empty – the site audit reports it.
     *
     * The HTML is read tag by tag the way a browser reads it (3.3.3, N50), never with one regular expression across the
     * text, so a stray src=" in a sentence or inside another attribute's value cannot hide a real address attribute:
     *  - in text, comments and the content of <script>, <style>… a value is escaped (e());
     *  - inside a tag a token counts only in an attribute value. In an address attribute (URL_ATTRIBUTES) it is filled as
     *    plain text and the whole value is checked like any other link (WpContent::isSafeUrl – https, http, mailto, tel or
     *    an address on the site); an unsafe value leaves a link to "#" (an empty src), so the page renders without an
     *    error. In an unquoted value of another attribute the value is filled and quoted. In a tag or attribute name a
     *    token is left out;
     *  - a token inside <code> or <pre> is an example of a token (a guide, documentation) and stays as written (2.10.1).
     */
    public static function fill(string $html, App $app): string
    {
        if (!str_contains($html, '{{')) {
            return $html;
        }
        $text = fn (string $part, bool $fill): string => $fill && str_contains($part, '{{') ? self::replace($part, $app, e(...)) : $part;
        $length = strlen($html);
        $out = '';
        $pos = 0;
        $code = 0;     // depth of <code> and <pre>: nothing is filled inside
        $foreign = []; // open <svg> and <math>, and the elements inside them whose content is HTML again: [name, html?]
        while ($pos < $length) {
            $lt = strpos($html, '<', $pos);
            if ($lt === false) {
                $out .= $text(substr($html, $pos), $code === 0);
                break;
            }
            $out .= $text(substr($html, $pos, $lt - $pos), $code === 0);
            $pos = $lt;
            $inForeign = $foreign !== [] && !$foreign[array_key_last($foreign)][1];
            $next = $html[$pos + 1] ?? '';
            $after = $html[$pos + 2] ?? '';
            if (substr_compare($html, '<!--', $pos, 4) === 0) {
                $end = preg_match('/\G-?>/', $html, $m, 0, $pos + 4) === 1 ? $pos + 4 + strlen($m[0])
                    : (preg_match('/--!?>/', $html, $m, PREG_OFFSET_CAPTURE, $pos + 4) === 1 ? $m[0][1] + strlen($m[0][0]) : $length);
            } elseif ($next === '!' && $inForeign && substr_compare($html, '<![CDATA[', $pos, 9) === 0) {
                $end = ($close = strpos($html, ']]>', $pos + 9)) === false ? $length : $close + 3;
            } elseif ($next === '!' || $next === '?' || ($next === '/' && $after !== '' && $after !== '>' && !ctype_alpha($after))) {
                $end = ($close = strpos($html, '>', $pos + 2)) === false ? $length : $close + 1; // a doctype or a bogus comment
            } elseif (ctype_alpha($next) || ($next === '/' && ctype_alpha($after))) {
                $closing = $next === '/';
                [$name, $pos, $tag, $attributes] = self::tag($html, $pos + ($closing ? 2 : 1), $app, $code === 0);
                $out .= ($closing ? '</' : '<') . $tag;
                if ($closing) {
                    $code = in_array($name, ['code', 'pre'], true) ? max(0, $code - 1) : $code;
                    self::closeForeign($foreign, $name);
                    continue;
                }
                $code += in_array($name, ['code', 'pre'], true) ? 1 : 0;
                if ($inForeign && (in_array($name, self::BREAKOUT, true) || ($name === 'font' && array_intersect_key($attributes, ['color' => 1, 'face' => 1, 'size' => 1]) !== []))) {
                    while ($foreign !== [] && !$foreign[array_key_last($foreign)][1]) {
                        array_pop($foreign); // the browser closes the <svg> or <math> and reads this tag as HTML
                    }
                    $inForeign = false;
                }
                $selfClosing = str_ends_with($tag, '/>');
                if ($inForeign) {
                    $root = '';
                    foreach ($foreign as [$open, $isHtml]) {
                        $root = $isHtml ? $root : $open;
                    }
                    $integration = in_array($name, self::INTEGRATION[$root] ?? [], true)
                        || ($name === 'annotation-xml' && in_array(strtolower($attributes['encoding'] ?? ''), ['text/html', 'application/xhtml+xml'], true));
                    if (!$selfClosing && ($integration || $name === 'svg' || $name === 'math')) {
                        $foreign[] = [$name, $integration];
                    }
                    continue;
                }
                if (($name === 'svg' || $name === 'math') && !$selfClosing) {
                    $foreign[] = [$name, false];
                    continue;
                }
                if ($name === 'plaintext') {
                    $out .= $text(substr($html, $pos), $code === 0);
                    break;
                }
                if (!in_array($name, self::RAW_TEXT, true)) {
                    continue;
                }
                $end = $name === 'script' ? self::scriptEnd($html, $pos)
                    : (preg_match('#</' . $name . '[\t\n\f\r />]#i', $html, $m, PREG_OFFSET_CAPTURE, $pos) === 1 ? $m[0][1] : $length);
            } else {
                $end = $pos + 1; // a "<" that starts nothing is text
            }
            $out .= $text(substr($html, $pos, $end - $pos), $code === 0);
            $pos = $end;
        }

        return $out;
    }

    /**
     * One start or end tag from its name to its ">" (or the end of the HTML), read like the browser's tokenizer, with the
     * tokens of its attribute values filled when $fill (see fill()). Returns the name in lowercase, the position after the
     * tag, the tag as it goes out (from the name on) and its attributes (lowercase names, decoded values as written).
     *
     * @return array{0: string, 1: int, 2: string, 3: array<string, string>}
     */
    private static function tag(string $html, int $pos, App $app, bool $fill): array
    {
        $length = strlen($html);
        $space = " \t\n\f\r";
        [$name, $pos] = self::word($html, $pos, $space . '/>', false, $fill);
        $out = $name;
        $attributes = [];
        while ($pos < $length) {
            $skip = strspn($html, $space, $pos);
            $out .= substr($html, $pos, $skip);
            $pos += $skip;
            $c = $html[$pos] ?? '';
            if ($c === '' || $c === '>' || $c === '/') {
                $out .= $c;
                $pos += strlen($c);
                if ($c === '/') {
                    continue;
                }
                break;
            }
            if ($c === '{' && preg_match(self::ANY_TOKEN, $html, $m, 0, $pos) === 1) {
                $out .= $fill ? '' : $m[0]; // a token where an attribute name stands
                $pos += strlen($m[0]);
                continue;
            }
            // an attribute name (its first character may be "="), then optionally "=" and a value
            [$attribute, $after] = self::word($html, $pos + 1, $space . '/>=', false, $fill);
            $attribute = $c . $attribute;
            $equals = $after + strspn($html, $space, $after);
            if (($html[$equals] ?? '') !== '=') {
                $out .= $attribute;
                $pos = $after;
                $attributes[strtolower($attribute)] ??= '';
                continue;
            }
            $start = $equals + 1 + strspn($html, $space, $equals + 1);
            $quote = $html[$start] ?? '';
            if ($quote === '>' || $quote === '') {
                $out .= $attribute . substr($html, $after, $start - $after); // "name=" without a value
                $pos = $start;
                continue;
            }
            if ($quote === '"' || $quote === "'") {
                $close = strpos($html, $quote, $start + 1);
                $end = $close === false ? $length : $close + 1;
                $raw = substr($html, $start + 1, ($close === false ? $length : $close) - $start - 1);
            } else {
                $quote = '';
                [$raw, $end] = self::word($html, $start, $space . '>', true, $fill);
            }
            $lower = strtolower($attribute);
            $attributes[$lower] ??= html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $pos = $end;
            $isAddress = in_array($lower, self::URL_ATTRIBUTES, true);
            if (!$fill || !str_contains($raw, '{{')) {
                $out .= $attribute . substr($html, $after, $end - $after);
            } elseif (!$isAddress && $quote !== '') {
                $out .= $attribute . substr($html, $after, $start + 1 - $after) . self::replace($raw, $app, e(...)) . $quote;
            } else {
                $value = self::replace(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $app, fn (string $s): string => $s);
                if ($isAddress) {
                    $value = trim($value);
                    $safe = in_array($lower, ['srcset', 'imagesrcset'], true) ? self::isSafeSrcset($value) : WpContent::isSafeUrl($value);
                    if ($value !== '' && (str_contains($value, '{{') || !$safe)) {
                        $value = in_array($lower, ['href', 'xlink:href'], true) ? '#' : '';
                    }
                }
                $out .= $attribute . '="' . e($value) . '"';
            }
        }

        return [strtolower($name), $pos, $out, $attributes];
    }

    /**
     * A tag name, an attribute name or an unquoted value from $pos up to one of $stop. A token is one unit, spaces and all:
     * in a value it is kept whole, in a name it is left out when $fill – the browser would read the value that replaces it.
     *
     * @return array{0: string, 1: int}
     */
    private static function word(string $html, int $pos, string $stop, bool $value, bool $fill): array
    {
        $length = strlen($html);
        $out = '';
        while ($pos < $length) {
            $run = strcspn($html, $stop . '{', $pos);
            $out .= substr($html, $pos, $run);
            $pos += $run;
            if (($html[$pos] ?? '') !== '{') {
                break;
            }
            if (preg_match(self::ANY_TOKEN, $html, $m, 0, $pos) === 1) {
                $out .= $value || !$fill ? $m[0] : '';
                $pos += strlen($m[0]);
                continue;
            }
            $out .= '{';
            $pos++;
        }

        return [$out, $pos];
    }

    /** Where a <script> ends: its first "</script" outside the escaped "<!-- <script> … -->" part the browser reads past. */
    private static function scriptEnd(string $html, int $pos): int
    {
        $state = 0; // 0 = script, 1 = escaped (after "<!--"), 2 = double escaped (after "<!-- <script")
        while (preg_match('#<!--|-->|</?script(?=[\t\n\f\r />])#i', $html, $m, PREG_OFFSET_CAPTURE, $pos) === 1) {
            [$found, $at] = $m[0];
            $pos = $at + strlen($found);
            $found = strtolower($found);
            if ($found === '<!--') {
                $state = $state === 0 && preg_match('/\G-?>/', $html, $n, 0, $pos) !== 1 ? 1 : $state; // "<!-->" and "<!--->" end at once
            } elseif ($found === '-->') {
                $state = 0;
            } elseif ($found === '</script') {
                if ($state !== 2) {
                    return $at;
                }
                $state = 1;
            } elseif ($state === 1) {
                $state = 2;
            }
        }

        return strlen($html);
    }

    /**
     * An end tag closes what the browser closes: the element on top, everything up to an <svg> or <math>, or – </p> and
     * </br> inside <svg> or <math> – the foreign content.
     *
     * @param list<array{0: string, 1: bool}> $foreign
     */
    private static function closeForeign(array &$foreign, string $name): void
    {
        if ($foreign === []) {
            return;
        }
        $top = $foreign[array_key_last($foreign)];
        if ($top[0] === $name) {
            array_pop($foreign);
        } elseif ($name === 'svg' || $name === 'math') {
            do { // pop up to and including the matching svg/math – what is open inside it is closed with it
                $popped = array_pop($foreign);
            } while ($popped !== null && $popped[0] !== $name);
        } elseif (($name === 'p' || $name === 'br') && !$top[1]) {
            while ($foreign !== [] && !$foreign[array_key_last($foreign)][1]) {
                array_pop($foreign);
            }
        }
    }

    /** Every address of a srcset list is safe (WpContent::isSafeUrl), split at spaces and commas – a piece never reaches past what the browser reads as one address. */
    private static function isSafeSrcset(string $value): bool
    {
        foreach (preg_split('/[\s,]+/', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            if (!WpContent::isSafeUrl($piece)) {
                return false;
            }
        }

        return true;
    }

    /** HTML without its <code> and <pre> blocks – where tokens are only examples (the audit and the usage look past them). */
    public static function withoutCode(string $html): string
    {
        return (string) preg_replace(self::CODE_PATTERN, ' ', $html);
    }

    /** The same in plain text (titles, descriptions, llms.txt) – not escaped. */
    public static function fillText(string $text, App $app): string
    {
        return str_contains($text, '{{') ? self::replace($text, $app, fn (string $s): string => $s) : $text;
    }

    /** @param callable(string): string $out how a value goes into the text (escaped for HTML, as is for plain text) */
    private static function replace(string $text, App $app, callable $out): string
    {
        $facts = self::all($app, Language::siteColumn());
        $text = (string) preg_replace_callback(self::TOKEN_PATTERN, fn (array $m): string => $out((string) ($facts[$m[1]]['display'] ?? '')), $text);
        $text = (string) preg_replace_callback(self::HOURS_PATTERN, fn (array $m): string => $out(self::hours($app, $m[1])), $text);

        return (string) preg_replace_callback(self::COMPUTED_PATTERN, fn (array $m): string => $out((string) self::computed($app, $m[1], $m[2])), $text);
    }

    /** {{hours.status}} = open now / closed, until when; {{hours.today}} = today's hours (2.10, Core\Hours). */
    private static function hours(App $app, string $what): string
    {
        return $what === 'status' ? Hours::statusText($app) : Hours::todayText($app);
    }

    /**
     * The value of a computed token as the site shows it (format_count), or null when it cannot be computed: years_since
     * of something that is not a year or a date (or of a fact without one), count of a collection that does not exist.
     * Visitors then see nothing in its place and the site audit says so.
     *
     * @param string $kind years_since | count
     * @param \DateTimeImmutable|null $now the moment to count from (tests); null = now
     */
    public static function computed(App $app, string $kind, string $argument, ?\DateTimeImmutable $now = null): ?string
    {
        if ($kind === 'years_since') {
            $value = str_starts_with($argument, 'fact.') ? (string) (self::all($app, Language::siteColumn())[substr($argument, 5)]['value'] ?? '') : $argument;
            $years = self::yearsSince($value, $now ?? new \DateTimeImmutable());

            return $years === null ? null : format_count($years);
        }
        $count = self::count($app, $argument);

        return $count === null ? null : format_count($count);
    }

    /** Full years since a year (2004) or a date (2004-05-01, the full years elapsed); null = not a year or a date, or still ahead. */
    public static function yearsSince(string $value, \DateTimeImmutable $now): ?int
    {
        $value = trim($value);
        if (preg_match('/^\d{4}$/', $value)) {
            $years = (int) $now->format('Y') - (int) $value;

            return $years >= 0 ? $years : null;
        }
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        $date = new \DateTimeImmutable($value . ' 00:00:00', $now->getTimezone());

        return $date > $now ? null : $date->diff($now)->y;
    }

    /** Visible items of a collection (by its address) or published news in the current language version; null = no such collection, or news is off. */
    private static function count(App $app, string $what): ?int
    {
        if (array_key_exists($what, self::$counts)) {
            return self::$counts[$what];
        }
        $db = $app->db();
        $language = Language::siteColumn();
        $count = null;
        try {
            if ($what === 'news') {
                $count = Extensions::isEnabled($app->settings(), 'novinky')
                    ? (int) $db->value('SELECT COUNT(*) FROM {novinky} WHERE visible = 1 AND datum <= NOW() AND smazano IS NULL AND jazyk = ?', [$language]) : null;
            } elseif (preg_match('/^[a-z0-9][a-z0-9-]{0,109}$/D', $what) && $db->one('SELECT idk FROM {kolekce} WHERE seo_link = ?', [$what]) !== null) {
                $count = (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE k.seo_link = ? AND p.zobrazit = 1 AND p.smazano IS NULL AND p.jazyk = ?', [$what, $language]);
            }
        } catch (\Throwable) {
            $count = null;
        }

        return self::$counts[$what] = $count;
    }

    /**
     * The computed tokens with an example of each form and its value now – the Facts list and list_facts (2.10). The
     * examples use the site's own first fact with a year or a date and its first collection, where it has them.
     *
     * @return list<array{token: string, value: string, about: string}> about = English, the admin translates it
     */
    public static function computedExamples(App $app): array
    {
        $dated = array_filter(self::all($app), fn (array $f): bool => !$f['builtIn'] && in_array($f['type'], ['year', 'date'], true) && $f['value'] !== '');
        $factKey = array_key_first($dated) ?? 'founded';
        try {
            $collection = (string) ($app->db()->value('SELECT seo_link FROM {kolekce} ORDER BY idk LIMIT 1') ?? 'reference');
        } catch (\Throwable) {
            $collection = 'reference';
        }
        $out = [];
        foreach ([
            ['years_since', '2004', 'Full years since a year or a date (2004 or 2004-05-01).'],
            ['years_since', 'fact.' . $factKey, 'The same from a fact whose value is a year or a date.'],
            ['count', $collection, 'The number of visible items of a collection (by its address).'],
            ['count', 'news', 'The number of published news items.'],
        ] as [$kind, $argument, $about]) {
            $out[] = ['token' => '{{' . $kind . ':' . $argument . '}}', 'value' => (string) self::computed($app, $kind, $argument), 'about' => $about];
        }

        return $out;
    }

    /**
     * Proof numbers typed in as plain digits in the number elements of a build (the counter) instead of a fact or a
     * computed token – the site audit asks for a fact, so the number stays true.
     *
     * @param array<string, mixed> $build
     * @return list<array{id: string, number: string}>
     */
    public static function typedNumbers(array $build): array
    {
        $out = [];
        $walk = function (array $nodes) use (&$walk, &$out): void {
            foreach ($nodes as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $number = is_array($n['obsah'] ?? null) && is_scalar($n['obsah']['cislo'] ?? null) ? trim((string) $n['obsah']['cislo']) : '';
                if (($n['typ'] ?? '') === 'pocitadlo' && preg_match('/^\d+$/', $number)) {
                    $out[] = ['id' => (string) ($n['id'] ?? ''), 'number' => $number];
                }
                if (is_array($n['deti'] ?? null)) {
                    $walk($n['deti']);
                }
            }
        };
        $walk(is_array($build['deti'] ?? null) ? $build['deti'] : []);

        return $out;
    }

    /**
     * schema.org properties of the organisation from facts that have one (Front\Seo adds them to the company node).
     *
     * @return array<string, mixed>
     */
    public static function schema(App $app): array
    {
        $out = [];
        foreach (self::all($app, Language::siteColumn()) as $f) {
            if ($f['schema'] === '' || $f['value'] === '') {
                continue;
            }
            $out[$f['schema']] = $f['schema'] === 'numberOfEmployees' ? ['@type' => 'QuantitativeValue', 'value' => is_numeric($f['value']) ? (float) $f['value'] + 0 : $f['value']]
                : ($f['schema'] === 'founder' ? ['@type' => 'Person', 'name' => $f['value']] : $f['value']);
        }

        return $out;
    }

    /**
     * Saves a fact (or its value in a language version). Returns null, or the error.
     *
     * @param array{label?: string, type?: string, value?: string, schema?: string, source?: string} $data
     */
    public static function save(App $app, string $key, array $data, string $language = ''): ?string
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return 'The key may contain lowercase letters, digits and _ and must start with a letter (2–40 characters).';
        }
        if (isset(self::BUILT_IN[$key])) {
            return 'This fact comes from the settings (Business details) – change it there.';
        }
        $db = $app->db();
        $existing = $db->one('SELECT * FROM {facts} WHERE fact_key = ? AND language = ?', [$key, $language]);
        $base = $language === '' ? $existing : $db->one("SELECT * FROM {facts} WHERE fact_key = ? AND language = ''", [$key]);
        if ($language !== '' && $base === null) {
            return 'Create the fact in the default language first.';
        }
        $type = $language !== '' ? (string) $base['type'] : (isset(self::TYPES[$data['type'] ?? '']) ? (string) $data['type'] : (string) ($existing['type'] ?? 'text'));
        $value = self::clean($type, (string) ($data['value'] ?? ($existing['value'] ?? '')));
        if ($value === null) {
            return t('The value does not fit the type of the fact (%s).', t(self::TYPES[$type]));
        }
        if ($type === 'text' && self::startsWithScheme($value)) {
            return 'A text fact cannot begin with an address scheme other than http, https, mailto or tel (javascript:, data:…). For a link use the type web address.';
        }
        $row = ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')];
        if ($language === '') {
            $row += ['type' => $type, 'label' => mb_substr(trim((string) ($data['label'] ?? ($existing['label'] ?? $key))), 0, 150),
                'schema_prop' => isset($data['schema']) && isset(self::SCHEMA_PROPS[$data['schema']]) ? (string) $data['schema'] : (string) ($existing['schema_prop'] ?? ''),
                'source' => mb_substr(trim(strip_tags((string) ($data['source'] ?? ($existing['source'] ?? '')))), 0, 255)];
        }
        if ($existing === null) {
            $db->insert('facts', $row + ['fact_key' => $key, 'language' => $language]);
        } else {
            $db->update('facts', $row, ['id' => (int) $existing['id']]);
        }
        if ($existing !== null && (string) $existing['value'] !== $value) {
            $db->insert('fact_history', ['fact_key' => $key, 'language' => $language, 'old_value' => (string) $existing['value'], 'new_value' => $value, 'changed_at' => date('Y-m-d H:i:s')]);
            Events::record($db, 'fact.changed', 'info', t('The fact %s changed.', $key), ['fact' => $key, 'language' => $language]);
        }
        ChangeLog::write($app, 'facts', $existing === null ? 'create' : 'update', $key . ($language !== '' ? ' (' . $language . ')' : ''));
        self::$cache = [];
        \Kaleta\Front\Cache::clear(); // cached pages show the new value right away

        return null;
    }

    public static function delete(App $app, string $key): bool
    {
        $deleted = $app->db()->delete('facts', ['fact_key' => $key]) > 0;
        if ($deleted) {
            ChangeLog::write($app, 'facts', 'delete', $key);
            self::$cache = [];
            \Kaleta\Front\Cache::clear();
        }

        return $deleted;
    }

    /** @return list<array{language: string, old_value: string, new_value: string, changed_at: string}> */
    public static function history(Db $db, string $key): array
    {
        return array_map(fn (array $r): array => ['language' => (string) $r['language'], 'old_value' => (string) $r['old_value'], 'new_value' => (string) $r['new_value'], 'changed_at' => (string) $r['changed_at']],
            $db->all('SELECT language, old_value, new_value, changed_at FROM {fact_history} WHERE fact_key = ? ORDER BY id DESC LIMIT 20', [$key]));
    }

    /**
     * Every piece of content as plain text, with where it is: pages, news, collection items, site parts, pop-ups and
     * components (published versions).
     *
     * @return \Generator<array{kind: string, where: string, target: array<string, int|string>, edit: string, text: string, build: ?array<string, mixed>}> build = the published build where the content is one (the audit looks at its elements)
     */
    public static function texts(Db $db): \Generator
    {
        foreach ($db->all('SELECT ids, titulek, text, stavba, jazyk FROM {stranky} WHERE smazano IS NULL') as $p) {
            $build = Build::fromJson((string) ($p['stavba'] ?? ''));
            yield ['kind' => 'page', 'where' => (string) $p['titulek'], 'target' => ['page' => (int) $p['ids']], 'edit' => 'admin.php?module=pages&action=' . ($build !== null ? 'builder' : 'edit') . '&id=' . (int) $p['ids'],
                'text' => $build !== null ? Build::asText($build) : (string) $p['text'], 'build' => $build]; // HTML – sentences() breaks it at block ends
        }
        foreach ($db->all('SELECT idc, titulek, uvod, text FROM {novinky} WHERE smazano IS NULL') as $n) {
            yield ['kind' => 'news', 'where' => (string) $n['titulek'], 'target' => ['news' => (int) $n['idc']], 'edit' => 'admin.php?module=news&action=edit&id=' . (int) $n['idc'],
                'text' => $n['titulek'] . "\n" . $n['uvod'] . "\n" . $n['text'], 'build' => null];
        }
        foreach ($db->all('SELECT p.idp, p.idk, p.nazev, p.popis, p.data, k.nazev AS kolekce FROM {kolekce_polozky} p JOIN {kolekce} k ON k.idk = p.idk WHERE p.smazano IS NULL') as $i) {
            $values = json_decode((string) $i['data'], true);
            yield ['kind' => 'item', 'where' => $i['kolekce'] . ': ' . $i['nazev'], 'target' => ['collection' => (int) $i['idk'], 'item' => (int) $i['idp']],
                'edit' => 'admin.php?module=collections&action=item&id=' . (int) $i['idk'] . '&item=' . (int) $i['idp'],
                'text' => $i['nazev'] . "\n" . $i['popis'] . "\n" . implode("\n", array_map(fn (mixed $v): string => is_scalar($v) ? (string) $v : '', is_array($values) ? $values : [])), 'build' => null];
        }
        foreach ($db->all('SELECT typ, jazyk, varianta, nazev, stavba FROM {casti}') as $c) {
            $build = Build::fromJson((string) ($c['stavba'] ?? ''));
            if ($build !== null) {
                yield ['kind' => 'part', 'where' => (string) ($c['nazev'] ?: $c['typ']), 'target' => ['part' => (string) $c['typ']], 'edit' => 'admin.php?module=parts', 'text' => Build::asText($build), 'build' => $build];
            }
        }
        foreach ($db->all('SELECT idpp, nazev, stavba FROM {popupy}') as $p) {
            $build = Build::fromJson((string) ($p['stavba'] ?? ''));
            if ($build !== null) {
                yield ['kind' => 'popup', 'where' => (string) $p['nazev'], 'target' => ['popup' => (int) $p['idpp']], 'edit' => 'admin.php?module=popups&action=edit&id=' . (int) $p['idpp'], 'text' => Build::asText($build), 'build' => $build];
            }
        }
        foreach ($db->all('SELECT idm, nazev, stavba FROM {komponenty}') as $m) {
            $build = Build::fromJson((string) ($m['stavba'] ?? ''));
            if ($build !== null) {
                yield ['kind' => 'component', 'where' => (string) $m['nazev'], 'target' => ['component' => (int) $m['idm']], 'edit' => 'admin.php?module=components&action=edit&id=' . (int) $m['idm'], 'text' => Build::asText($build), 'build' => $build];
            }
        }
    }

    /**
     * Where the text is stated in the content as plain text (not as a token) – e.g. the old value of a changed fact.
     * Digits match also with different spaces in between (1500, 1 500, 1 500).
     *
     * @return list<array{kind: string, where: string, target: array<string, int|string>, edit: string, sentence: string}>
     */
    public static function occurrences(Db $db, string $needle, int $limit = 100): array
    {
        $needle = trim($needle);
        if (mb_strlen($needle) < 2) {
            return [];
        }
        $pattern = '/' . implode('[\s\x{00A0}\x{202F}]*', array_map(fn (string $ch): string => preg_quote($ch, '/'), preg_split('//u', preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $needle) ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [])) . '/iu';
        // a number must not be part of a longer one (2004 in 12004)
        if (preg_match('/^\d/', $needle)) {
            $pattern = '/(?<![\d])' . substr($pattern, 1, -3) . '(?![\d])/iu';
        }
        $out = [];
        foreach (self::texts($db) as $t) {
            foreach (self::sentences($t['text']) as $sentence) {
                if (preg_match($pattern, $sentence) === 1) {
                    $out[] = ['kind' => $t['kind'], 'where' => $t['where'], 'target' => $t['target'], 'edit' => $t['edit'], 'sentence' => mb_substr($sentence, 0, 300)];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }

        return $out;
    }

    /**
     * Sentences that state something countable – a year, a number, a percentage, an amount – without a fact token: the
     * candidates for facts (the claims inventory for Claude).
     *
     * @return list<array{kind: string, where: string, target: array<string, int|string>, edit: string, sentence: string}>
     */
    public static function claims(Db $db, int $limit = 200): array
    {
        $out = [];
        foreach (self::texts($db) as $t) {
            foreach (self::sentences($t['text']) as $sentence) {
                if (self::isClaim($sentence)) {
                    $out[] = ['kind' => $t['kind'], 'where' => $t['where'], 'target' => $t['target'], 'edit' => $t['edit'], 'sentence' => mb_substr($sentence, 0, 300)];
                    if (count($out) >= $limit) {
                        return $out;
                    }
                }
            }
        }

        return $out;
    }

    /** A sentence that states a year, a count, a percentage or an amount as plain text – one with a fact, hours or computed token already is not a claim. */
    public static function isClaim(string $sentence): bool
    {
        return preg_match('/\d/', $sentence) === 1 && !str_contains($sentence, '{{')
            && preg_match('/(\b(19|20)\d{2}\b|\d[\d\s\x{00A0}]*\s*(%|\+|×|x\b|let|years|jahre|klient|client|kunden|projekt|project|realiz|zakáz|kč|czk|eur|€|\$))/iu', $sentence) === 1;
    }

    /**
     * Which facts are used where (by their tokens).
     *
     * @return array<string, int> key => number of places
     */
    public static function usage(Db $db): array
    {
        $counts = [];
        foreach (self::texts($db) as $t) {
            preg_match_all(self::TOKEN_PATTERN, self::withoutCode($t['text']), $m);
            foreach (array_unique($m[1]) as $key) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
            }
        }

        return $counts;
    }

    /** @return list<string> */
    private static function sentences(string $text): array
    {
        // builds give HTML: block ends become line breaks, the tags go
        $text = strip_tags((string) preg_replace('#<(br|/p|/h[1-6]|/li|/div|/td|/th|/blockquote|/figcaption)\b[^>]*>#i', "\n", $text));
        $text = (string) preg_replace('/[ \t]+/u', ' ', html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+|\R+/u', $text) ?: []), fn (string $s): bool => $s !== ''));
    }
}
