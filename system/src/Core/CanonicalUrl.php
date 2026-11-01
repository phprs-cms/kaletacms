<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * The optional canonical address of a page, a news item or a collection item (3.22, C13): column canonical_url, under
 * "Search engines and sharing". Empty = the page itself (the address the site prints anyway). Otherwise an absolute
 * http(s) address – an article first published elsewhere – or a path on this site ("/services/web"), the original of a
 * duplicate such as a campaign copy or a print page. Nothing else is accepted (javascript:, data:, a relative path, a
 * protocol-relative //host). Front\Kernel prints it as the one <link rel="canonical"> of the page.
 */
final class CanonicalUrl
{
    /** Length of the column. */
    public const int MAX = 500;

    /** The answer to a value that is not an address (the admin forms and MCP). */
    public const string REFUSAL = 'The canonical address must start with https:// (or http://) or be a path on this site starting with / – leave it empty for the page itself.';

    /**
     * The value to store: '' for empty, the trimmed address, or null when it is not a valid canonical address.
     */
    public static function clean(mixed $value): ?string
    {
        if (!is_string($value)) {
            return $value === null ? '' : null;
        }
        $url = trim($value);
        if ($url === '') {
            return '';
        }
        // no white space, quotes, angle brackets or control characters anywhere; a length the column takes
        if (strlen($url) > self::MAX || preg_match('/[\x00-\x20\x7F"\'<>\\\\`]/', $url)) {
            return null;
        }
        if (str_starts_with($url, '/')) {
            // a path on the site – never //host (protocol-relative, another site)
            return str_starts_with($url, '//') ? null : $url;
        }
        if (!preg_match('#^https?://#i', $url)) {
            return null; // javascript:, data:, mailto:, a relative path…
        }
        $parts = parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if ($host === '' || isset($parts['user']) || isset($parts['pass']) || !preg_match('/^[a-z0-9.\-]+$/iD', $host)
            || (!str_contains($host, '.') && strtolower($host) !== 'localhost')) {
            return null; // no host, credentials in the address, or not a host name
        }

        return strtolower(substr($url, 0, 5)) === 'https' ? 'https' . substr($url, 5) : 'http' . substr($url, 4);
    }

    /**
     * The absolute address for <link rel="canonical">: a stored path is put behind the site address ($origin, from
     * site_url, and the folder Kaleta runs in); an absolute one stays as it is. '' = none set (the page's own address applies).
     */
    public static function absolute(string $stored, string $origin, string $basePath): string
    {
        $url = self::clean($stored);
        if ($url === null || $url === '') {
            return '';
        }

        return str_starts_with($url, '/') ? rtrim($origin, '/') . $basePath . $url : $url;
    }

    /**
     * The canonical address an SEO plugin of an imported WordPress post gave (Core\WpSeo): an address on the old site
     * becomes a path on this one (the importer redirects every old address to the new one), the post's own address is
     * no canonical at all, another site stays absolute. '' = nothing to store.
     */
    public static function fromImport(string $raw, string $oldSite, string $ownUrl): string
    {
        $url = self::clean(html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($url === null || $url === '') {
            return '';
        }
        $host = fn (string $u): string => (string) preg_replace('/^www\./', '', strtolower((string) parse_url($u, PHP_URL_HOST)));
        $path = fn (string $u): string => rtrim((string) parse_url($u, PHP_URL_PATH), '/') . (($q = parse_url($u, PHP_URL_QUERY)) ? '?' . $q : '');
        if (str_starts_with($url, '/') || ($oldSite !== '' && $host($url) === $host($oldSite))) {
            $local = str_starts_with($url, '/') ? rtrim($url, '/') : $path($url);
            if ($ownUrl !== '' && $local === $path($ownUrl)) {
                return ''; // the post itself – the new site prints its own address
            }

            return self::clean($local === '' ? '/' : $local) ?? '';
        }

        return $url;
    }
}
