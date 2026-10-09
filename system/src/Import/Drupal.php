<?php

declare(strict_types=1);

namespace Kaleta\Import;

use Kaleta\Core\Language;

/**
 * Drupal 9, 10 and 11: the JSON:API core module (/jsonapi/…) read by Import\Fetch into drupal-<domain>.json; this class
 * reads that file.
 *
 * Steps: node/article (required, with include=field_image,field_tags,uid – the image files, tags and authors come along as
 * included resources), node/page (pages) and taxonomy_term/tags (the vocabulary of the tags; terms of other vocabularies
 * that articles refer to are read from the included resources). Articles become news items, pages become pages. The text
 * is body.processed (the rendered text) or body.value, status false is a draft, path.alias the old address, the metatag
 * field's description the SEO description when the site has it. Relative addresses (/sites/default/files/…) are made
 * absolute with the site address. Anonymous JSON:API answers published content only; unpublished content needs the
 * optional sign-in (user:password = Basic auth, anything else = a Bearer token). Blocks, menus, views, the theme, custom
 * content types and comments are not transferred.
 */
final class Drupal implements Source, Remote
{
    private const int PAGE_SIZE = 50;

    /** @var array<string, mixed>|null the fetched file, parsed once per request */
    private ?array $data = null;

    /** @var array<string, array<string, mixed>> included resources by "type/id" */
    private array $included = [];

    public function __construct(private readonly string $path, private readonly string $siteUrl = '')
    {
    }

    public static function key(): string
    {
        return 'drupal';
    }

    public static function name(): string
    {
        return 'Drupal';
    }

    public static function extensions(): array
    {
        return ['json'];
    }

    public static function hint(): string
    {
        return 'Drupal 9, 10 and 11 have no export file: enter the site address below and the content is fetched from its JSON:API (the core module must be switched on).';
    }

    /* ---------- Remote: the fetch ---------- */

    public static function steps(): array
    {
        return ['articles' => 'articles (with their images, tags and authors)', 'pages' => 'pages', 'tags' => 'tags'];
    }

    public static function tokenHint(): string
    {
        return 'Optional. Published content needs no sign-in. For unpublished content type user:password (HTTP Basic authentication must be switched on in Drupal) or an access token.';
    }

    public static function headers(string $token): array
    {
        $headers = ['Accept: application/vnd.api+json'];
        if ($token !== '') {
            $headers[] = str_contains($token, ':') ? 'Authorization: Basic ' . base64_encode($token) : 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    public static function firstPage(string $siteUrl, string $step): string
    {
        $path = match ($step) {
            'articles' => 'node/article?include=field_image,field_tags,uid&sort=created',
            'pages' => 'node/page?include=uid&sort=created',
            default => 'taxonomy_term/tags?sort=name',
        };

        return rtrim($siteUrl, '/') . '/jsonapi/' . $path . '&page[limit]=' . self::PAGE_SIZE;
    }

    public static function page(array $json, string $step): array
    {
        if (!is_array($json['data'] ?? null) || !isset($json['jsonapi'])) {
            throw new \RuntimeException('This is not the JSON:API of Drupal – the answer has no "jsonapi" and "data".');
        }
        $resource = fn (mixed $item): bool => is_array($item) && is_string($item['type'] ?? null) && is_string($item['id'] ?? null) && is_array($item['attributes'] ?? null);
        $items = array_values(array_filter($json['data'], $resource));
        $included = array_values(array_filter(is_array($json['included'] ?? null) ? $json['included'] : [], $resource));
        $next = $json['links']['next']['href'] ?? '';

        return [$items, $included, is_string($next) ? trim($next) : ''];
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
            'Old addresses are the path aliases (or /node/number). Articles become news items, basic pages become pages.',
            'Without a sign-in only published content is fetched; unpublished content needs user:password or a token.',
            'Blocks, menus, views, the theme, other content types, custom fields and comments are not transferred.',
        ];
        foreach ($this->load()['kaleta_fetch']['skipped'] ?? [] as $step) {
            $notes[] = 'The site does not offer ' . (is_string($step) ? $step : '?') . ' over the API, so they were not fetched.';
        }

        return $notes;
    }

    public function read(int $skip = 0): \Generator
    {
        $d = $this->load();
        $this->index();
        $order = 0;
        $seenTags = [];
        foreach ($this->included as $key => $resource) {
            if (str_starts_with($key, 'user--user/')) {
                $a = $resource['attributes'];
                $record = new Author((string) $resource['id'], self::text($a['display_name'] ?? ($a['name'] ?? '')));
                if ($order++ >= $skip) {
                    yield $order - 1 => $record;
                }
            }
        }
        foreach ([...($d['steps']['tags']['data'] ?? []), ...$this->included] as $resource) {
            if (!is_array($resource) || !str_starts_with((string) ($resource['type'] ?? ''), 'taxonomy_term--') || isset($seenTags[(string) ($resource['id'] ?? '')])) {
                continue;
            }
            $seenTags[(string) $resource['id']] = true;
            $a = is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
            $name = self::text($a['name'] ?? '');
            $alias = trim(basename(self::text($a['path']['alias'] ?? '')));
            $record = new Tag((string) $resource['id'], $name, $alias !== '' ? $alias : slugify($name, 90));
            if ($order++ >= $skip) {
                yield $order - 1 => $record;
            }
        }
        foreach (['articles' => 'post', 'pages' => 'page'] as $step => $type) {
            foreach ($d['steps'][$step]['data'] ?? [] as $node) {
                if (!is_array($node) || !is_string($node['id'] ?? null) || !is_array($node['attributes'] ?? null)) {
                    continue;
                }
                if ($order++ >= $skip) {
                    yield $order - 1 => $this->post($node, $type);
                }
            }
        }
    }

    /* ---------- one node (covered by tools/unit-tests.php through the fake's data) ---------- */

    /** @param array<string, mixed> $node */
    private function post(array $node, string $type): Post
    {
        $a = $node['attributes'];
        $r = is_array($node['relationships'] ?? null) ? $node['relationships'] : [];
        $body = is_array($a['body'] ?? null) ? $a['body'] : [];
        $html = self::text($body['processed'] ?? '') !== '' ? (string) $body['processed'] : (string) ($body['value'] ?? '');
        $alias = self::text($a['path']['alias'] ?? '');
        $nid = self::text($a['drupal_internal__nid'] ?? '');
        $tags = [];
        foreach (is_array($r['field_tags']['data'] ?? null) ? $r['field_tags']['data'] : [] as $t) {
            if (is_array($t) && is_string($t['id'] ?? null)) {
                $tags[] = $t['id'];
            }
        }
        $imageRef = $r['field_image']['data'] ?? null;
        $image = '';
        if (is_array($imageRef) && is_string($imageRef['id'] ?? null)) {
            $file = $this->included['file--file/' . $imageRef['id']] ?? null;
            $image = $file !== null ? $this->absoluteUrl(self::text($file['attributes']['uri']['url'] ?? '')) : '';
        }
        $description = '';
        foreach (is_array($a['metatag'] ?? null) ? $a['metatag'] : [] as $meta) {
            if (is_array($meta) && ($meta['attributes']['name'] ?? '') === 'description') {
                $description = self::text($meta['attributes']['content'] ?? '');
            }
        }
        $created = self::text($a['created'] ?? '');

        return new Post(
            key: (string) $node['id'],
            type: $type,
            title: self::text($a['title'] ?? ''),
            slug: $alias !== '' ? trim(basename($alias)) : '',
            html: $this->absolute($html),
            excerpt: self::text($body['summary'] ?? ''),
            status: ($a['status'] ?? true) === false || ($a['status'] ?? 1) === 0 ? 'draft' : ($created !== '' && strtotime($created) > time() ? 'scheduled' : 'published'),
            publishedAt: $created,
            updatedAt: self::text($a['changed'] ?? ''),
            authorKey: self::text($r['uid']['data']['id'] ?? ''),
            tagKeys: array_slice(array_values(array_unique($tags)), 0, 20),
            featureImageUrl: $image,
            seoDescription: $description,
            oldUrl: $alias !== '' ? $alias : ($nid !== '' ? '/node/' . $nid : ''),
            language: Language::fromForeign(self::text($a['langcode'] ?? '')),
        );
    }

    /** All included resources of all steps by "type/id" (a file, a tag or an author is included once per page – deduplicated here). */
    private function index(): void
    {
        if ($this->included !== []) {
            return;
        }
        foreach ($this->load()['steps'] as $step) {
            foreach (is_array($step['included'] ?? null) ? $step['included'] : [] as $resource) {
                if (is_array($resource) && is_string($resource['type'] ?? null) && is_string($resource['id'] ?? null) && is_array($resource['attributes'] ?? null)) {
                    $this->included[$resource['type'] . '/' . $resource['id']] ??= $resource;
                }
            }
        }
    }

    /** Relative src and href (/sites/default/files/a.jpg) in the text become absolute, so that they can be downloaded and keep working. */
    private function absolute(string $html): string
    {
        if ($this->siteAddress() === '' || $html === '') {
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
            throw new \RuntimeException('This is not a Drupal fetch. Enter the site address in Import and export → From another system and fetch the content again.');
        }
        if (($json['kaleta_fetch']['done'] ?? false) !== true) {
            throw new \RuntimeException('The fetch from the site did not finish. Start it again.');
        }

        return $this->data = $json;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }
}
