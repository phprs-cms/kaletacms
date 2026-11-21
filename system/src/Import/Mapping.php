<?php

declare(strict_types=1);

namespace Kaleta\Import;

/**
 * What the records of the old site become here – chosen by the administrator in the preview, kept in the import state.
 *
 *  posts            news | skip            posts → news items
 *  pages            page | news | skip     pages → builder pages (or news items on a site that only has news)
 *  categories       category | tag | skip  categories → news categories (the first one of a post), or tags
 *  tags             tag | skip
 *  authors          author key => our user id; a missing or unknown author → the user running the import
 *  language         code of a language version for the new categories and pages ('' = the site's default language)
 *  drafts           import drafts too (as hidden news items and pages)
 *  builder          pages straight into the builder (Builder\HtmlConverter); the text stays as a backup either way
 *  redirects        redirects from the old addresses (Core\RedirectStore::add, which also heals links)
 *  default_category news category for posts without one (0 = a new "Uncategorised" is created)
 *  site_url         address of the old site when the export does not carry it (Ghost) – for images and redirect paths
 *
 * It is a plain array (it lives in the JSON state file); normalize() is the only way values get in, so nothing
 * unexpected from a form reaches the runner.
 */
final class Mapping
{
    public const array POSTS = ['news', 'skip'];
    public const array PAGES = ['page', 'news', 'skip'];
    public const array CATEGORIES = ['category', 'tag', 'skip'];
    public const array TAGS = ['tag', 'skip'];

    public const array DEFAULTS = ['posts' => 'news', 'pages' => 'page', 'categories' => 'category', 'tags' => 'tag', 'authors' => [],
        'language' => '', 'drafts' => true, 'builder' => true, 'redirects' => true, 'default_category' => 0, 'site_url' => ''];

    /**
     * Valid mapping from any input (a form, a saved state): unknown choices fall back to the defaults, authors only
     * to existing users, the language only to a language version the site has.
     *
     * @param array<string, mixed> $input
     * @param list<string> $languages additional language versions of the site (Core\Language::additional)
     * @param list<int> $userIds ids of existing users; empty = no author mapping is accepted
     * @return array<string, mixed>
     */
    public static function normalize(array $input, array $languages = [], array $userIds = []): array
    {
        $choice = fn (string $key, array $allowed): string => in_array($input[$key] ?? null, $allowed, true) ? (string) $input[$key] : self::DEFAULTS[$key];
        $authors = [];
        foreach (is_array($input['authors'] ?? null) ? $input['authors'] : [] as $key => $user) {
            if (is_int($user) || (is_string($user) && ctype_digit($user))) {
                $user = (int) $user;
                if ($user > 0 && in_array($user, $userIds, true) && count($authors) < 200) {
                    $authors[mb_substr((string) $key, 0, 190)] = $user;
                }
            }
        }
        $url = trim((string) ($input['site_url'] ?? ''));
        $url = $url !== '' && !preg_match('#^https?://#i', $url) ? 'https://' . $url : $url;

        return [
            'posts' => $choice('posts', self::POSTS), 'pages' => $choice('pages', self::PAGES),
            'categories' => $choice('categories', self::CATEGORIES), 'tags' => $choice('tags', self::TAGS),
            'authors' => $authors,
            'language' => in_array($input['language'] ?? '', $languages, true) ? (string) $input['language'] : '',
            'drafts' => (bool) ($input['drafts'] ?? self::DEFAULTS['drafts']),
            'builder' => (bool) ($input['builder'] ?? self::DEFAULTS['builder']),
            'redirects' => (bool) ($input['redirects'] ?? self::DEFAULTS['redirects']),
            'default_category' => max(0, (int) ($input['default_category'] ?? 0)),
            'site_url' => \Kaleta\Core\WebImport::validUrl($url) ? rtrim($url, '/') : '',
        ];
    }
}
