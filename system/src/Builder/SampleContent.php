<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Language;

/**
 * Sample content of the starter sites, the page templates and the section library (3.11, UX310-1). Kaleta never fabricates
 * content: what a starter page brings – placeholders in square brackets ([number], [Customer’s name]) and the sample
 * paragraphs written for the owner to replace – must not go live unnoticed. find() names it in a build; the builder's
 * pre-publish check, MCP (save_build, publish_build and the other build tools) and the site audit (kind sample) report it.
 *
 * Nothing is stored in the build: sample content is recognised by its words – the library's texts in every site language
 * and those of earlier releases (legacy()) – so it stops being reported the moment someone rewrites it, in the builder, in
 * place on the site or through MCP, and pages built before 3.11 are covered too.
 */
final class SampleContent
{
    /** A placeholder: a short text in square brackets with a letter in it – [number], [Customer’s name], [COMPANY NAME]. */
    public const string PLACEHOLDER = '/\[[^\[\]{}<>\n]{0,60}\p{L}[^\[\]{}<>\n]{0,60}\]/u';

    /** A library paragraph counts as sample text from this many words – short labels ("years in business") stay the owner's. */
    private const int MIN_WORDS = 4;

    /** The site dictionaries the library texts come from (code, register) – a regional version uses its language's. */
    private const array LANGUAGES = [['cs', null], ['en', null], ['de', 'formal'], ['de', 'informal'], ['es', null], ['fr', null], ['it', null], ['pl', null], ['sk', null]];

    /** Kinds in the order of importance: an invented review is worse than an invented number, that worse than filler text. */
    private const array KINDS = ['testimonial' => 3, 'figure' => 2, 'text' => 1];

    /** Where the recognised texts are kept between requests – building them reads every section in nine dictionaries. */
    private const string CACHE = KALETA_ROOT . '/storage/cache/sample-content.json';

    /** @var array<string, string>|null normalized text => kind */
    private static ?array $known = null;

    /**
     * Elements of a build with sample content, in the order of the page.
     *
     * @param array<string, mixed> $build a sanitized build
     * @return list<array{id: ?string, kind: string, text: string}> kind = testimonial | figure | text; text = what was recognised
     */
    public static function find(array $build): array
    {
        $known = self::known();
        $found = [];
        $walk = static function (array $children) use (&$walk, &$found, $known): void {
            foreach ($children as $p) {
                if (!is_array($p)) {
                    continue;
                }
                $type = (string) ($p['typ'] ?? '');
                $kind = null;
                $text = '';
                foreach (self::units($type, is_array($p['obsah'] ?? null) ? $p['obsah'] : []) as $unit) {
                    $key = self::normalize($unit);
                    $match = $known[$key] ?? null;
                    if ($match === 'text' && count(explode(' ', $key)) < self::MIN_WORDS) {
                        $match = null; // a short library text (a label) is not sample text on its own
                    }
                    if ($match === null && preg_match(self::PLACEHOLDER, $unit, $m)) {
                        [$match, $unit] = ['text', $m[0]];
                    }
                    if ($match !== null && ($kind === null || self::KINDS[$match] > self::KINDS[$kind])) {
                        [$kind, $text] = [$match, $unit];
                    }
                }
                if ($kind !== null) {
                    $found[] = ['id' => isset($p['id']) ? (string) $p['id'] : null, 'kind' => $type === 'citat' ? 'testimonial' : $kind, 'text' => mb_substr(trim($text), 0, 80)];
                }
                if (is_array($p['deti'] ?? null) && $type !== 'komponenta') {
                    $walk($p['deti']);
                }
            }
        };
        $walk(is_array($build['deti'] ?? null) ? $build['deti'] : []);

        return $found;
    }

    /**
     * The findings for the pre-publish check: a summary with the counts first (on the first sample element), then one line
     * per element, at most $max lines in all.
     *
     * @param array<string, mixed> $build a sanitized build
     * @return list<array{id: ?string, zprava: string}>
     */
    public static function findings(array $build, int $max = Check::MAX): array
    {
        $found = self::find($build);
        if ($found === []) {
            return [];
        }
        $lines = [['id' => $found[0]['id'], 'zprava' => self::summary($found)]];
        foreach ($found as $f) {
            $lines[] = ['id' => $f['id'], 'zprava' => match ($f['kind']) {
                'testimonial' => t('Sample testimonial “%s” – use a real customer’s words, with their permission, or delete it.', $f['text']),
                'figure' => t('Placeholder figure “%s” – put in your real number or delete it.', $f['text']),
                default => t('Sample text “%s” – write your own or delete it.', $f['text']),
            }];
        }

        return array_slice($lines, 0, $max);
    }

    /** @param list<array{id: ?string, kind: string, text: string}> $found */
    public static function summary(array $found): string
    {
        $counts = array_count_values(array_column($found, 'kind'));
        $parts = array_filter([
            isset($counts['testimonial']) ? t('sample testimonials: %d', $counts['testimonial']) : '',
            isset($counts['figure']) ? t('placeholder figures: %d', $counts['figure']) : '',
            isset($counts['text']) ? t('sample texts: %d', $counts['text']) : '',
        ]);

        return t('This page still has sample content from the starter site (%s). Replace or delete it before publishing – invented reviews and numbers can mislead visitors.', implode(', ', $parts));
    }

    /**
     * The texts of an element that can be sample content.
     *
     * @param array<string, mixed> $o content of the element
     * @return list<string>
     */
    private static function units(string $type, array $o): array
    {
        $plain = static fn (mixed $x): string => trim(html_entity_decode(strip_tags(is_scalar($x) ? (string) $x : ''), ENT_QUOTES | ENT_HTML5));

        return match ($type) {
            'text' => self::paragraphs((string) ($o['html'] ?? '')),
            'citat' => [$plain($o['text'] ?? ''), $plain($o['autor'] ?? ''), $plain($o['pozice'] ?? '')],
            'nadpis', 'hodnoceni' => [$plain($o['text'] ?? '')],
            'pocitadlo' => [$plain($o['cislo'] ?? ''), $plain($o['popisek'] ?? '')],
            'seznam' => array_map(trim(...), preg_split('/\R/u', (string) ($o['polozky'] ?? '')) ?: []),
            'faq' => array_merge(...array_map(static fn (mixed $i): array => is_array($i) ? [$plain($i['otazka'] ?? ''), ...self::paragraphs((string) ($i['odpoved'] ?? ''))] : [], is_array($o['polozky'] ?? null) ? $o['polozky'] : [])),
            default => [],
        };
    }

    /** @return list<string> the paragraphs of an HTML text, as plain text */
    private static function paragraphs(string $html): array
    {
        $blocks = preg_split('#<(?:/p|/li|/h[1-6]|/blockquote|br\s*/?)>#i', $html) ?: [];

        return array_values(array_filter(array_map(static fn (string $b): string => trim(html_entity_decode(strip_tags($b), ENT_QUOTES | ENT_HTML5)), $blocks), static fn (string $b): bool => $b !== ''));
    }

    private static function normalize(string $text): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $text)));
    }

    /**
     * Everything recognised as sample content, in every site language: the placeholders the library uses, its paragraphs
     * and quotes, and the sample reviews and names of earlier releases.
     *
     * @return array<string, string> normalized text => kind
     */
    private static function known(): array
    {
        if (self::$known !== null) {
            return self::$known;
        }
        // the cache follows the texts: a new release or a changed dictionary builds it again
        $sources = [__FILE__, __DIR__ . '/Library.php', __DIR__ . '/Elements/Faq.php', ...(glob(KALETA_SYSTEM . '/jazyky/[a-z][a-z].php') ?: []), ...(glob(KALETA_SYSTEM . '/jazyky/[a-z][a-z]-du.php') ?: [])];
        $stamp = KALETA_VERSION . '|' . implode(',', array_map(static fn (string $f): string => (string) @filemtime($f) . ':' . (string) @filesize($f), $sources));
        $cached = is_file(self::CACHE) ? json_decode((string) @file_get_contents(self::CACHE), true) : null;
        if (is_array($cached) && ($cached['stamp'] ?? null) === $stamp && is_array($cached['known'] ?? null)) {
            return self::$known = $cached['known'];
        }
        $known = [];
        $add = static function (string $text, string $kind) use (&$known): void {
            $key = self::normalize($text);
            if ($key !== '' && (!isset($known[$key]) || self::KINDS[$kind] > self::KINDS[$known[$key]])) {
                $known[$key] = $kind;
            }
        };
        foreach (self::LANGUAGES as [$code, $register]) {
            Language::runWith($code, static function () use ($add): void {
                foreach (self::placeholders() as $kind => $texts) {
                    foreach ($texts as $text) {
                        $add($text, $kind);
                    }
                }
                foreach (self::legacy() as $text) {
                    $add($text, 'testimonial');
                }
                // the sample answers an FAQ element starts with
                foreach (Elements\Faq::properties()['polozky']['vychozi'] as $item) {
                    foreach (self::paragraphs((string) $item['odpoved']) as $paragraph) {
                        $add($paragraph, 'text');
                    }
                }
                foreach (array_column(Library::listAll(), 'klic') as $key) {
                    $walk = static function (array $p) use (&$walk, $add): void {
                        if (($p['typ'] ?? '') === 'text') {
                            foreach (self::paragraphs((string) ($p['obsah']['html'] ?? '')) as $paragraph) {
                                $add($paragraph, 'text');
                            }
                        } elseif (($p['typ'] ?? '') === 'citat') {
                            $add((string) ($p['obsah']['text'] ?? ''), 'testimonial');
                        }
                        foreach ($p['deti'] ?? [] as $d) {
                            $walk($d);
                        }
                    };
                    $walk(Library::section($key, Language::code())['prvek'] ?? []);
                }
            }, '', $register);
        }

        @file_put_contents(self::CACHE, (string) json_encode(['stamp' => $stamp, 'known' => $known], JSON_UNESCAPED_UNICODE), LOCK_EX);

        return self::$known = $known;
    }

    /**
     * The placeholders of the library and of the element defaults by kind – a figure or a review the owner must supply.
     *
     * @return array<string, list<string>>
     */
    private static function placeholders(): array
    {
        return [
            'figure' => [t('[number]'), t('[price]'), t('[year]'), t('[rating]')],
            'testimonial' => [t('[A customer’s words – a real quote, used with their permission]'), t('[Customer’s name]'), t('[Role, company]'), t('[A short review from a real customer]')],
        ];
    }

    /**
     * Sample reviews and names of releases before 3.11, which made them look real – a starter page built then still has them.
     *
     * @return list<string>
     */
    private static function legacy(): array
    {
        return [
            t('Everything went exactly as agreed, on time and on budget. We will gladly come back.'), t('David Clarke'), t('Managing Director, Clarke Ltd'),
            t('We appreciate the fast communication and that they always recommended the best solution.'), t('Emma Johnson'),
            t('The best company we have ever worked with. They kept every deadline and the result exceeded our expectations.'), t('Sarah Miller'),
            t('Fast, clean and for the agreed price.'), t('Helpful people, great communication.'), t('I recommend them to all my friends.'),
            t('Working with them was quick and hassle-free. Recommended.'), t('Jane Doe'), t('out of 5 · 120 reviews'),
        ];
    }
}
