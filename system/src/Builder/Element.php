<?php

declare(strict_types=1);

namespace Kaleta\Builder;

/**
 * Builder element type. Each type = one class in Builder\Elements with a content schema (properties()), allowed HTML tags,
 * default style and rendering. The output is always one tag per element (the only exceptions are compound elements like FAQ or the news list).
 *
 * Content fields – types: text (a line), inline (short text with bold/italic/link), html (formatted text), radky (several lines),
 * odkaz, obrazek, vyber, cislo, prepinac, polozky (a list of objects with the fields „pole“).
 */
abstract class Element
{
    public const string TYPE = '';
    public const string NAME = '';
    public const string DESCRIPTION = '';
    public const string ICON = 'blok';
    public const string GROUP = 'Content';
    /** Can contain other elements. */
    public const bool CONTAINER = false;
    /** Allowed tags, the first is the default. */
    public const array HTML_TAGS = ['div'];
    /** Only an administrator can insert and change it (custom HTML). */
    public const bool ADMIN_ONLY = false;
    /** Extension key (Core\Extensions) without which the element cannot be inserted and is not rendered on the site; empty = always. */
    public const string EXTENSION = '';
    /** Offered only in site parts (header, footer, wrappers) – logo, navigation, page content. */
    public const bool PARTS_ONLY = false;

    /** @return array<string, array<string, mixed>> content fields: key => [typ, popisek, vychozi, moznosti, pole, max] */
    public static function properties(): array
    {
        return [];
    }

    /** @return list<array<string, mixed>> default inner content of a newly inserted container (the editor gives it new ids) */
    public static function defaultChildren(): array
    {
        return [];
    }

    /** @return array<string, array<string, string>> default style of a newly inserted element */
    public static function defaultStyle(): array
    {
        return [];
    }

    /** Base CSS of the type (layer „stavitel“), output only on pages where the type is used. */
    public static function baseCss(): string
    {
        return '';
    }

    /**
     * What a right-to-left page (Arabic) adds after the base CSS: the few rules logical properties cannot say – a centring
     * translate, a clip or a transform origin, a position on a photo that must not mirror. Output only on such pages, so a
     * left-to-right page keeps its CSS as it was.
     */
    public static function rtlCss(): string
    {
        return '';
    }

    /**
     * Declarations a content property adds to this element's own style rule (layer „prvky“, after the style, so that the
     * element's style cannot undo them – e.g. the header that is transparent at the top must be fixed even when its style says sticky).
     * Empty = none; a non-empty result gives the element an id and a style rule even without a style of its own.
     *
     * @param array<string, mixed> $p sanitized element
     */
    public static function behaviourCss(array $p, Context $k): string
    {
        return '';
    }

    /**
     * @param array<string, mixed> $p       sanitized element (typ, znacka, obsah, …)
     * @param string               $a       finished attributes (id, class, data-ka-id) starting with a space
     * @param string               $children    rendered nested elements
     */
    abstract public static function render(array $p, string $a, string $children, Context $k): string;
}
