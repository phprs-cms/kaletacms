<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;

/**
 * Render state of one page of the site: what was used (so that the CSS covers only what is needed) across the page build and
 * the site parts (header, footer, wrapper), so that the page gets a single CSS block. Editor mode switches with the build being rendered.
 */
final class Context
{
    /** The page has an element with a display condition – it must not go into the page cache. */
    public bool $withoutCache = false;

    /** @var array<string, true> element types on the page */
    public array $types = [];

    /** @var array<string, true> classes on the page */
    public array $classes = [];

    /** CSS of elements with their own style (layer „prvky“). */
    public string $css = '';

    /** @var list<array{0:string, 1:string}> questions and answers from FAQ elements – for the page's structured data */
    public array $faq = [];

    /** @var array<string, list<array<string, mixed>>> the menus by location (hlavni, paticka) as Core\Menu::items() gives them (supplied by the site) */
    public array $menu = [];

    /** Path of the displayed page (for aria-current in the navigation). */
    public string $path = '';

    /** Finished switcher of the site's language versions (empty on a single-language site). */
    public string $languages = '';

    /** @var array<string, array{nazev:string, url:string, aktivni:bool, preklad:bool}> language versions for the Language switcher element */
    public array $languageList = [];

    /** Light and dark color scheme switcher for visitors (empty when disabled); the Navigation element adds it after the menu. */
    public string $colorScheme = '';

    /** @var array<string, array{0: string, 1: string}>|null values of the collection item for {{tags}} (inside a Collection list and on the item page) */
    public ?array $item = null;

    /** @var array{idk: int, id: int, ids: list<int>}|null the category page being shown (3.7): the category and it with its subcategories */
    public ?array $category = null;

    /** @var array{kolekce: array<string, mixed>, polozka: array<string, mixed>}|null the item whose page is shown (3.7, Previous / next item) */
    public ?array $itemPage = null;

    /** The item list of the category page shown asked for a page past its last one (3.7) – the site answers 404. */
    public bool $pastEnd = false;

    /** Collection list depth: the elements inside repeat, so they get their style through a class, not through the id. */
    public int $inLoop = 0;

    /** @var array<int, array<string, mixed>|null> loaded components (one component is often on a page several times) */
    public array $components = [];

    /** @var list<int> components being rendered right now (protection against a component inside itself) */
    public array $nesting = [];

    /** @var array<string, array{pred: string, za: string}> controls around an element (filters and pagination of a collection list) by id */
    public array $surroundings = [];

    /** @var array<string, true> elements whose CSS is already on the page */
    public array $styles = [];

    /** Where the build being rendered comes from: „stranka:<id>“ or „cast:<typ>:<jazyk>“ (the form finds its fields by it). */
    public string $source = '';

    /** @var list<array{0: string, 1: string}> breadcrumbs of the displayed page: [text, url]; the last one is the page itself (url '') */
    public array $breadcrumbs = [];

    /** Content the system inserts into the wrapper (the „Obsah stránky“ (page content) element): news item, list, 404 page. */
    public string $content = '';

    /** Heading anchors from texts on the page (Elements\Text) – so that they do not repeat on one page. @var array<string, true> */
    public array $anchors = [];

    /** Id of the image that loads at once without its editor asking (the first image of a page's first section, 3.5); '' = none. */
    public string $leadImage = '';

    /** Comment mode of a shared preview (2.15, Core\DraftComments): elements carry data-ka-id so a comment can point at one, nothing else of the editor. */
    public bool $markIds = false;

    public function __construct(public readonly App $app, public bool $editor = false)
    {
    }

    public function url(string $path): string
    {
        return $this->app->url($path);
    }

    /** Image url from media/ completed with the installation path; a foreign url stays. */
    public function image(string $src): string
    {
        return preg_match('#^(https?:)?//|^/#', $src) ? $src : $this->app->request->basePath() . '/' . $src;
    }
}
