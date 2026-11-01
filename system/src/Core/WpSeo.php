<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * SEO data of WordPress SEO plugins in a WXR export: the custom <title>, meta description and noindex of a post or page.
 *
 * The plugins keep them in <wp:postmeta> of each item. Core\WpFile collects the keys listed in PLUGINS, this class turns them
 * into our fields (ka_novinky.seo_titulek / seo_popis / noindex, ka_stranky.seo_titulek / popis / noindex):
 *  - plugin titles are templates with variables (Yoast and SmartCrawl "%%title%% %%sep%% %%sitename%%", Rank Math "%title% %sep% %sitename%");
 *    the common ones are filled in or removed, a title with an unknown variable is dropped rather than imported broken;
 *  - a title made only of variables (the plugin's default pattern) is not imported at all – the site builds "title – site name" itself;
 *  - Rank Math stores robots as a serialized PHP array; it is never unserialized, "noindex" is looked up in the string.
 * Canonical URLs go to canonical_url since 3.22 (Core\CanonicalUrl::fromImport, counted in the preview too); Open Graph and Twitter fields are ignored. Redirects of these plugins live in
 * options or their own tables, not in the export, so they are out of scope here.
 *
 * Pure functions, covered by tools/unit-tests.php.
 */
final class WpSeo
{
    /**
     * Meta keys per plugin (verified against the plugins' source: Yoast inc/class-wpseo-meta.php, Rank Math's importers, SmartCrawl core.php
     * and entities/class-post.php). "noindex" holds "1" for noindex; "robots" is Rank Math's serialized array that may contain "noindex".
     * To support another plugin, add a row here.
     */
    public const array PLUGINS = [
        'SmartCrawl' => ['title' => '_wds_title', 'description' => '_wds_metadesc', 'noindex' => '_wds_meta-robots-noindex', 'canonical' => '_wds_canonical'],
        'Yoast SEO' => ['title' => '_yoast_wpseo_title', 'description' => '_yoast_wpseo_metadesc', 'noindex' => '_yoast_wpseo_meta-robots-noindex', 'canonical' => '_yoast_wpseo_canonical'],
        'Rank Math' => ['title' => 'rank_math_title', 'description' => 'rank_math_description', 'robots' => 'rank_math_robots', 'canonical' => 'rank_math_canonical_url'],
    ];

    /** Variables that have a value of ours (the key is the variable name without the percent signs, in any plugin's syntax). */
    private const array VALUES = ['title' => 'title', 'sitename' => 'sitename', 'sitedesc' => 'sitedesc', 'sep' => 'sep', 'excerpt' => 'excerpt', 'excerpt_only' => 'excerpt',
        'category' => 'category', 'primary_category' => 'category', 'term_title' => 'category', 'currentyear' => 'year'];

    /** Variables that have no meaning on the new site and are simply removed (paging, dates, custom fields...). */
    private const array REMOVED = ['page', 'pagenumber', 'pagetotal', 'spell_page', 'spell_pagenumber', 'spell_pagetotal', 'currentdate', 'currentmonth', 'currenttime',
        'currentday', 'date', 'modified', 'id', 'name', 'userid', 'user_description', 'caption', 'focuskw', 'focus_keyword', 'keyword', 'tag', 'tags', 'parent_title', 'post_year', 'post_month', 'post_day'];

    /** Both syntaxes: Yoast and SmartCrawl %%title%%, Rank Math %title%. */
    private const string VARIABLE = '/%%?([a-z0-9_\-]+(?:\([^)%]*\))?)%%?/i';

    /** @return list<string> all meta keys worth reading from the export */
    public static function keys(): array
    {
        static $keys = null;

        return $keys ??= array_merge(...array_map('array_values', array_values(self::PLUGINS)));
    }

    /**
     * Raw plugin values of one post: the first plugin that has any of its keys filled in wins (a site that switched plugins
     * usually keeps the old meta behind).
     *
     * @param array<string, string> $meta  postmeta key => value (only the keys from keys())
     * @return array{plugin:string, title:string, description:string, noindex:bool, canonical:string}
     */
    public static function raw(array $meta): array
    {
        foreach (self::PLUGINS as $plugin => $keys) {
            $values = [];
            foreach ($keys as $field => $key) {
                $values[$field] = trim((string) ($meta[$key] ?? ''));
            }
            if (implode('', $values) === '') {
                continue;
            }

            return [
                'plugin' => $plugin,
                'title' => $values['title'] ?? '',
                'description' => $values['description'] ?? '',
                'noindex' => isset($keys['robots']) ? self::robotsNoindex($values['robots'] ?? '') : ($values['noindex'] ?? '') === '1',
                'canonical' => $values['canonical'] ?? '',
            ];
        }

        return ['plugin' => '', 'title' => '', 'description' => '', 'noindex' => false, 'canonical' => ''];
    }

    /**
     * Rank Math robots: a serialized array (a:2:{i:0;s:7:"noindex";i:1;s:8:"nofollow";}) or a plain string. It is never unserialized –
     * "noindex" as a whole word is enough ("noimageindex" does not count).
     */
    public static function robotsNoindex(string $value): bool
    {
        return preg_match('/(?<![a-z])noindex(?![a-z])/i', $value) === 1;
    }

    /**
     * The custom title for our seo_titulek: variables filled in, trimmed to the column. Empty = nothing to import: no title,
     * the plugin's default pattern (variables only), an unknown variable left, or the result equals the post title.
     *
     * @param array{title:string, sitename:string, sitedesc?:string, excerpt?:string, category?:string} $context  values of the new site and the post
     */
    public static function title(string $template, array $context, int $limit = 200): string
    {
        if (self::isDefaultPattern($template)) {
            return '';
        }
        $resolved = self::resolve($template, $context);

        return $resolved === null || $resolved === trim($context['title']) ? '' : mb_substr($resolved, 0, $limit);
    }

    /**
     * The custom meta description for our seo_popis / popis. A description made only of variables ("%%excerpt%%") is skipped –
     * the site builds its own description from the intro.
     *
     * @param array{title:string, sitename:string, sitedesc?:string, excerpt?:string, category?:string} $context
     */
    public static function description(string $template, array $context, int $limit = 300): string
    {
        if (self::isDefaultPattern($template)) {
            return '';
        }
        $resolved = self::resolve($template, $context);

        return $resolved === null ? '' : mb_substr($resolved, 0, $limit);
    }

    /** True when the template is only variables and separators (e.g. "%%title%% %%page%% %%sep%% %%sitename%%" or "%title% %sep% %sitename%"). */
    public static function isDefaultPattern(string $template): bool
    {
        $literal = (string) preg_replace(self::VARIABLE, '', $template);

        return trim($template) === '' || self::trimSeparators($literal, true) === '';
    }

    /**
     * Whitespace and separators (dashes, pipes, dots as bullets) at both ends removed; trim() works per byte and would cut a multibyte dash in half.
     *
     * @param bool $punctuation also colons, commas and full stops – for the default-pattern test, never for the final text ("Open daily." keeps its stop)
     */
    private static function trimSeparators(string $text, bool $punctuation = false): string
    {
        $set = $punctuation ? '[\s–—|·•\-:,.]' : '[\s–—|·•\-]';

        return (string) preg_replace('/^' . $set . '+|' . $set . '+$/u', '', $text);
    }

    /**
     * Fills in the variables; null = an unknown variable stayed (a custom field, a plugin we do not know) – such a title is dropped.
     *
     * @param array{title:string, sitename:string, sitedesc?:string, excerpt?:string, category?:string} $context
     */
    public static function resolve(string $template, array $context): ?string
    {
        $unknown = false;
        $text = (string) preg_replace_callback(self::VARIABLE, function (array $m) use ($context, &$unknown): string {
            $name = strtolower($m[1]);
            if (isset(self::VALUES[$name])) {
                return match (self::VALUES[$name]) {
                    'title' => $context['title'],
                    'sitename' => $context['sitename'],
                    'sitedesc' => $context['sitedesc'] ?? '',
                    'sep' => '–',
                    'excerpt' => $context['excerpt'] ?? '',
                    'category' => $context['category'] ?? '',
                    default => date('Y'),
                };
            }
            if (in_array($name, self::REMOVED, true) || preg_match('/^(?:ct_|cf_|customfield|customterm|term)/', $name)) {
                return '';
            }
            $unknown = true;

            return '';
        }, html_entity_decode(strip_tags($template), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($unknown) {
            return null;
        }
        // a removed variable leaves doubled separators or a separator at the edge: "Title –  – Site" → "Title – Site"
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        $text = (string) preg_replace('/(?:\s*[–—|·•-]\s*){2,}/u', ' – ', $text);

        return self::trimSeparators($text);
    }
}
