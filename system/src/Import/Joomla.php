<?php

declare(strict_types=1);

namespace Kaleta\Import;

use Kaleta\Core\Language;

/**
 * Joomla 4 and 5: the Web Services API (/api/index.php/v1/…) read by Import\Fetch with the token from an API-enabled user
 * (X-Joomla-Token) into joomla-<domain>.json; this class reads that file.
 *
 * Steps: content/articles (required), content/categories (nested through parent_id; their path gives the old URL),
 * users (names only – e-mails are not kept) and tags (when the endpoint answers). An article's state 1 published and 2
 * archived become published (a future publish_up = scheduled), 0 unpublished a draft, -2 trashed is skipped. The text is
 * introtext + fulltext with the "Read more" mark between them, so the intro becomes the news item's intro. The featured
 * image comes from images.image_intro or image_fulltext (the #joomlaImage… suffix is cut off), relative addresses
 * (images/…) are made absolute with the site address. The API does not return an article's URL: the old address is the
 * best guess /<category path>/<id>-<alias>; SEF URL rewriting differs per site, so the preview says to check the redirects.
 * Modules, menus, the template, custom fields and extensions' content are not transferred.
 */
final class Joomla implements Source, Remote
{
    private const int PAGE_SIZE = 50;

    /** @var array<string, mixed>|null the fetched file, parsed once per request */
    private ?array $data = null;

    /** @var array<string, string> category id => its path (a/b/c), built from the fetched categories */
    private array $paths = [];

    public function __construct(private readonly string $path, private readonly string $siteUrl = '')
    {
    }

    public static function key(): string
    {
        return 'joomla';
    }

    public static function name(): string
    {
        return 'Joomla';
    }

    public static function extensions(): array
    {
        return ['json'];
    }

    public static function hint(): string
    {
        return 'Joomla 4 and 5 have no export file: enter the site address and an API token below and the content is fetched from its Web Services API.';
    }

    /* ---------- Remote: the fetch ---------- */

    public static function steps(): array
    {
        return ['articles' => 'articles', 'categories' => 'categories', 'users' => 'authors (names only)', 'tags' => 'tags'];
    }

    public static function tokenHint(): string
    {
        return 'In Joomla open Users → Manage → the user → Joomla API Token, tick Enabled and copy the token. The user must be allowed to read articles, categories and users. The Web Services API plugins must be switched on (System → Plugins → Web Services).';
    }

    public static function headers(string $token): array
    {
        $headers = ['Accept: application/vnd.api+json'];
        if ($token !== '') {
            $headers[] = 'X-Joomla-Token: ' . $token;
        }

        return $headers;
    }

    public static function firstPage(string $siteUrl, string $step): string
    {
        $endpoint = match ($step) {
            'articles' => 'content/articles',
            'categories' => 'content/categories',
            'users' => 'users',
            default => 'tags',
        };

        return rtrim($siteUrl, '/') . '/api/index.php/v1/' . $endpoint . '?page[offset]=0&page[limit]=' . self::PAGE_SIZE;
    }

    public static function page(array $json, string $step): array
    {
        if (!is_array($json['data'] ?? null) || !is_array($json['links'] ?? null)) {
            throw new \RuntimeException('This is not the Web Services API of Joomla – the answer has no "data" and "links".');
        }
        $items = array_values(array_filter($json['data'], fn (mixed $item): bool => is_array($item) && is_array($item['attributes'] ?? null)));
        $next = isset($json['links']['next']) && is_string($json['links']['next']) ? trim($json['links']['next']) : '';

        return [$items, [], $next];
    }

    /* ---------- Source: reading the fetched file ---------- */

    public function verify(): void
    {
        $size = (int) @filesize($this->path);
        if ($size === 0 || $size > Batch::MAX_BYTES) {
            throw new \RuntimeException('The file is empty or larger than 256 MB.');
        }
        $this->load();
    }

    public function site(): array
    {
        return ['nazev' => '', 'adresa' => $this->siteAddress()];
    }

    public function imagesFromAnyHost(): bool
    {
        return false;
    }

    public function notes(): array
    {
        $notes = [
            'The API does not say an article’s address. Old addresses are the best guess /category-path/id-alias; SEF URL rewriting differs per site, so check the redirects with the migration report after the import.',
            'Trashed articles are skipped, unpublished ones come as drafts, archived ones as published.',
            'Modules, menus, the template, custom fields and the content of other extensions are not transferred.',
        ];
        foreach ($this->load()['kaleta_fetch']['skipped'] ?? [] as $step) {
            $notes[] = 'The site does not offer ' . (is_string($step) ? $step : '?') . ' over the API, so they were not fetched.';
        }

        return $notes;
    }

    public function read(int $skip = 0): \Generator
    {
        $d = $this->load();
        $order = 0;
        foreach ($d['steps']['users']['data'] ?? [] as $u) {
            $id = self::id($u);
            if ($id !== '') {
                $record = new Author($id, self::text($u['attributes']['name'] ?? ''));
                if ($order++ >= $skip) {
                    yield $order - 1 => $record;
                }
            }
        }
        $this->paths();
        foreach ($d['steps']['categories']['data'] ?? [] as $c) {
            $id = self::id($c);
            if ($id !== '') {
                $a = $c['attributes'];
                $record = new Category($id, self::text($a['title'] ?? ''), self::text($a['alias'] ?? ''), self::text($a['parent_id'] ?? ''));
                if ($order++ >= $skip) {
                    yield $order - 1 => $record;
                }
            }
        }
        foreach ($d['steps']['tags']['data'] ?? [] as $t) {
            $id = self::id($t);
            if ($id !== '') {
                $record = new Tag($id, self::text($t['attributes']['title'] ?? ''), self::text($t['attributes']['alias'] ?? ''));
                if ($order++ >= $skip) {
                    yield $order - 1 => $record;
                }
            }
        }
        foreach ($d['steps']['articles']['data'] ?? [] as $article) {
            $id = self::id($article);
            if ($id === '' || (int) ($article['attributes']['state'] ?? 1) === -2) {
                continue; // trashed – skipped without taking an order number
            }
            if ($order++ >= $skip) {
                yield $order - 1 => $this->post($id, $article['attributes'], is_array($article['relationships'] ?? null) ? $article['relationships'] : []);
            }
        }
    }

    /* ---------- one article (covered by tools/unit-tests.php through the fake's data) ---------- */

    /**
     * @param array<string, mixed> $a attributes
     * @param array<string, mixed> $r relationships
     */
    private function post(string $id, array $a, array $r): Post
    {
        $intro = $this->absolute((string) ($a['introtext'] ?? ''));
        $full = $this->absolute((string) ($a['fulltext'] ?? ''));
        $html = trim($full) !== '' ? $intro . "\n<!--more-->\n" . $full : ($intro !== '' ? $intro : $this->absolute((string) ($a['text'] ?? '')));
        $catid = self::text($a['catid'] ?? ($r['category']['data']['id'] ?? ''));
        $alias = self::text($a['alias'] ?? '');
        $publishUp = self::text($a['publish_up'] ?? '');
        $state = (int) ($a['state'] ?? 1);
        $tags = [];
        if (is_array($a['tags'] ?? null)) {
            $tags = array_map('strval', array_keys($a['tags'])); // {"2": "Tag title"}
        } elseif (is_array($r['tags']['data'] ?? null)) {
            $tags = array_values(array_filter(array_map(fn (mixed $t): string => is_array($t) ? self::text($t['id'] ?? '') : '', $r['tags']['data'])));
        }
        $images = $a['images'] ?? [];
        $images = is_string($images) ? (json_decode($images, true) ?: []) : (is_array($images) ? $images : []);
        $image = self::text($images['image_intro'] ?? '') !== '' ? self::text($images['image_intro']) : self::text($images['image_fulltext'] ?? '');
        $path = $this->paths[$catid] ?? '';

        return new Post(
            key: $id,
            type: 'post',
            title: self::text($a['title'] ?? ''),
            slug: $alias,
            html: $html,
            status: match (true) {
                $state === 0 => 'draft',
                $publishUp !== '' && strtotime($publishUp) > time() => 'scheduled',
                default => 'published',
            },
            publishedAt: $publishUp !== '' ? $publishUp : self::text($a['created'] ?? ''),
            updatedAt: self::text($a['modified'] ?? ''),
            authorKey: self::text($a['created_by'] ?? ($r['created_by']['data']['id'] ?? '')),
            categoryKeys: $catid !== '' ? [$catid] : [],
            tagKeys: array_slice($tags, 0, 20),
            featureImageUrl: $image !== '' ? $this->absoluteUrl(self::imagePath($image)) : '',
            seoDescription: self::text($a['metadesc'] ?? ''),
            oldUrl: $alias !== '' ? '/' . ($path !== '' ? $path . '/' : '') . $id . '-' . $alias : '',
            language: Language::fromForeign(self::text($a['language'] ?? '')),
        );
    }

    /** Joomla's image value: "images/a.jpg#joomlaImage://local-images/a.jpg?width=…" → "images/a.jpg". */
    public static function imagePath(string $value): string
    {
        return trim((string) strtok($value, '#'));
    }

    /** The categories' paths: from the "path" attribute, or walked up through parent_id when a site does not send it. */
    private function paths(): void
    {
        $byId = [];
        foreach ($this->load()['steps']['categories']['data'] ?? [] as $c) {
            $id = self::id($c);
            if ($id !== '') {
                $byId[$id] = $c['attributes'];
            }
        }
        foreach ($byId as $id => $a) {
            $path = self::text($a['path'] ?? '');
            if ($path === '') {
                $parts = [];
                for ($cursor = $id, $depth = 0; isset($byId[$cursor]) && $depth < 20; $cursor = self::text($byId[$cursor]['parent_id'] ?? ''), $depth++) {
                    $alias = self::text($byId[$cursor]['alias'] ?? '');
                    if ($alias === '' || $alias === 'root') {
                        break;
                    }
                    array_unshift($parts, $alias);
                }
                $path = implode('/', $parts);
            }
            $this->paths[(string) $id] = trim($path, '/');
        }
    }

    /** Relative src and href (images/a.jpg, /images/a.jpg) in the text become absolute, so that they can be downloaded and keep working. */
    private function absolute(string $html): string
    {
        $site = $this->siteAddress();
        if ($site === '' || $html === '') {
            return $html;
        }

        return (string) preg_replace_callback('#\b(src|href)=(["\'])(?![a-z][a-z0-9+.-]*:|//|\#)([^"\']*)\2#i', fn (array $m): string => $m[1] . '=' . $m[2] . $this->absoluteUrl($m[3]) . $m[2], $html);
    }

    private function absoluteUrl(string $path): string
    {
        $site = $this->siteAddress();
        if ($path === '' || preg_match('#^[a-z][a-z0-9+.-]*:|^//#i', $path) || $site === '') {
            return $path;
        }

        return $site . '/' . ltrim($path, '/');
    }

    private function siteAddress(): string
    {
        $fromFile = self::text($this->load()['kaleta_fetch']['site'] ?? '');

        return rtrim($fromFile !== '' ? $fromFile : $this->siteUrl, '/');
    }

    /**
     * @return array<string, mixed>
     * @throws \RuntimeException
     */
    private function load(): array
    {
        if ($this->data !== null) {
            return $this->data;
        }
        $json = json_decode((string) @file_get_contents($this->path), true, 64);
        if (!is_array($json) || ($json['kaleta_fetch']['system'] ?? '') !== self::key() || !is_array($json['steps'] ?? null)) {
            throw new \RuntimeException('This is not a Joomla fetch. Enter the site address and the API token in Import and export → From another system and fetch the content again.');
        }
        if (($json['kaleta_fetch']['done'] ?? false) !== true) {
            throw new \RuntimeException('The fetch from the site did not finish. Start it again.');
        }

        return $this->data = $json;
    }

    /** The id of an API item as a string; '' when it has none. */
    private static function id(mixed $item): string
    {
        return is_array($item) && is_array($item['attributes'] ?? null) ? self::text($item['id'] ?? ($item['attributes']['id'] ?? '')) : '';
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
