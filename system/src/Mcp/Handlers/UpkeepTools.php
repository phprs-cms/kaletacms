<?php

declare(strict_types=1);

namespace Kaleta\Mcp\Handlers;

use Kaleta\Core\InternalLinks;
use Kaleta\Core\Links;
use Kaleta\Mcp\Schema;

/**
 * MCP tools for links that look after themselves (2.14): the broken links the background check found across the site
 * (Core\Links) and orphan pages with the pages a link to them would fit on (Core\InternalLinks). Both read-only: Claude
 * proposes the fix as a draft with the usual build tools. Part of Mcp\Tools.
 *
 * @phpstan-ignore trait.unused
 */
trait UpkeepTools
{
    /**
     * The MCP definitions of the tools in this trait (3.49 P0): stored name => [description, input schema]. Tools::definitions()
     * lists them in the order of Tools::ORDER; the English names and texts are in Translator, the annotations in Catalog.
     *
     * @return array<string, array{0: string, 1: array<string, mixed>}>
     */
    public static function upkeepToolsDefinitions(): array
    {
        return [
            'list_broken_links' => ['Broken links the background check found across the site (2.14; read-only; administrators and editors): in news items, published page builds (with the element id) and collection items, each with where it is (kind, id, title, target), the status (no response or the HTTP code) and a hint – for an outside address the archived copy at web.archive.org to look at; nothing is fetched from there. Propose the replacement or the removal as a draft with edit_build / update_page / update_news / save_collection_item; the user decides. The check runs one record every five minutes, each once a month.',
                Schema::object(['kind' => Schema::text('only one kind: news | page | item (optional)'), 'limit' => Schema::number('how many, 1–300, default 100')])],
            'suggest_internal_links' => ['Orphan pages (2.14; read-only; administrators and editors of pages): published pages, news items and item pages that no published build, menu or text links to, each with up to five candidate source pages whose title or text share words of the orphan’s title (shared_words). Add the link from a candidate as a draft with the build tools and show the preview; nothing is edited by itself.',
                Schema::object(['limit' => Schema::number('how many orphans, 1–100, default 20')])],
        ];
    }

    /** list_broken_links */
    private function toolListBrokenLinks(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() && !$auth->hasModule('pages') && !$auth->hasModule('news')) {
            throw new \DomainException('Broken links are for administrators and editors.');
        }
        $kind = is_string($a['kind'] ?? null) && isset(Links::KINDS[$a['kind']]) ? $a['kind'] : '';
        $limit = max(1, min(300, (int) ($a['limit'] ?? 100)));
        $pages = $auth->isAdmin() || $auth->hasModule('pages');
        $links = array_values(array_filter(Links::broken($this->app, 300, $auth->articleScope('c.')),
            fn (array $l): bool => ($kind === '' || $l['kind'] === $kind) && ($l['kind'] === 'news' || $pages)));

        return [
            'total' => count($links),
            'checking' => $this->app->settings()->bool('link_check'),
            'links' => array_map(fn (array $l): array => ['kind' => $l['kind'], 'id' => $l['id'], 'title' => $l['title'], 'page' => $l['page'] === '' ? null : '/' . $l['page'], 'target' => $l['target'],
                'element' => $l['element'] !== '' ? $l['element'] : null, 'url' => $l['url'], 'status' => $l['status'] === 0 ? 'no response' : 'HTTP ' . $l['status'], 'found' => $l['found'], 'hint' => $l['hint']], array_slice($links, 0, $limit)),
            'next' => 'The background check tests one record every five minutes, each once a month, and only stores links that fail. Propose the replacement or the removal of each link as a draft '
                . '(edit_build by element id for a page build, update_page for a text page, update_news for a news item, save_collection_item for an item) and let the user decide; the archived copy in hint is a suggestion to look at, nothing was fetched.',
        ];
    }

    /** suggest_internal_links */
    private function toolSuggestInternalLinks(string $name, array $a): mixed
    {
        $auth = $this->app->auth();
        if (!$auth->isAdmin() && !$auth->hasModule('pages')) {
            throw new \DomainException('Internal link suggestions are for administrators and editors of pages.');
        }
        $suggestions = InternalLinks::suggestions($this->app, max(1, min(100, (int) ($a['limit'] ?? 20))));

        return [
            'total' => count($suggestions),
            'orphans' => array_map(fn (array $o): array => ['kind' => $o['kind'], 'id' => $o['id'], 'title' => $o['title'], 'page' => '/' . $o['path'], 'target' => $o['target'], 'title_words' => $o['title_words'],
                'candidates' => array_map(fn (array $c): array => ['page_id' => $c['id'], 'title' => $c['title'], 'page' => '/' . $c['path'], 'shared_words' => $c['shared_words'], 'target' => $c['target']], $o['candidates'])], $suggestions),
            'next' => $suggestions === [] ? 'Every published page, news item and item page is linked from somewhere on the site.'
                : 'An orphan is published content no published build, menu or text links to. Add a link to it from a candidate page as a draft (get_build, then edit_build adding a sentence with the link, or a button) and show the preview; publish only when the user asks. Nothing is edited by itself.',
        ];
    }
}
