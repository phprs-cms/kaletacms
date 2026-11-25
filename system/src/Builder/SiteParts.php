<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * Site parts from the builder (table ka_casti): header and footer on all pages and wrappers around the content that the
 * system assembles (news item page, news list, 404 page). A part without a published build = the part from the layout.
 */
final class SiteParts
{
    /** type => [name, description] */
    public const array TYPES = [
        'hlavicka' => ['Header', 'Logo and navigation at the top of every page.'],
        'paticka' => ['Footer', 'Contacts, links and copyright at the bottom of every page.'],
        'novinka' => ['Detail novinky', 'A wrapper around the news item – a call to action or more news below the text, for example.'],
        'vypis' => ['News list', 'A wrapper around the news list, category, tag and search results.'],
        'nenalezeno' => ['Page not found (404)', 'A wrapper around the page-not-found message – e.g. with links onward.'],
    ];

    /** Parts that can have variants for selected pages (a landing page without navigation, a different footer…). */
    public const array WITH_VARIANTS = ['hlavicka', 'paticka'];

    public const string VARIANT_PATTERN = '/^[a-z0-9][a-z0-9-]{0,39}$/D';

    /**
     * 3.54 review: the build source of a site part as Front\Kernel writes it – cast:<type>:<language>[:<variant>] – read
     * back as [type, language, variant], or null. The language is any offered code ('' = the default one), the long
     * ones too (en-gb, pt-br, zh-hant – a two-letter pattern never found a form or a consent in their header or footer).
     *
     * @return array{0: string, 1: string, 2: string}|null
     */
    public static function parseSource(string $source): ?array
    {
        if (preg_match('/^cast:([a-z]+):(' . \Kaleta\Core\Language::TAG . ')?(?::([a-z0-9-]{1,40}))?$/D', $source, $m) !== 1 || !isset(self::TYPES[$m[1]])) {
            return null;
        }
        $language = $m[2] ?? '';
        if ($language !== '' && !\Kaleta\Core\Language::isOffered($language)) {
            return null;
        }

        return [$m[1], $language, $m[3] ?? ''];
    }

    /** @return array<string, mixed>|null row of the part (variant '' = the default version) */
    public static function row(Db $db, string $type, string $language, string $variant = ''): ?array
    {
        return $db->one('SELECT * FROM {casti} WHERE typ = ? AND jazyk = ? AND varianta = ?', [$type, $language, $variant]);
    }

    /**
     * Rules of a variant besides its pages (3.6, column pravidla): news items, the news list (with categories, tags and
     * search), item pages of the listed collections, pages under the listed parent pages (at any depth). The collection
     * slugs and page IDs are checked by the pop-up rules (Popups::sanitizeRules).
     *
     * @return array{novinky: bool, vypis: bool, kolekce: list<string>, nadrazene: list<int>}
     */
    public static function sanitizeRules(mixed $p): array
    {
        $p = is_array($p) ? $p : [];
        $checked = Popups::sanitizeRules(['kolekce' => $p['kolekce'] ?? [], 'stranky' => $p['nadrazene'] ?? []]);

        return ['novinky' => !empty($p['novinky']), 'vypis' => !empty($p['vypis']),
            'kolekce' => array_values(array_filter(is_array($checked['kolekce'] ?? null) ? $checked['kolekce'] : [], 'is_string')),
            'nadrazene' => array_values(array_filter(is_array($checked['stranky'] ?? null) ? $checked['stranky'] : [], 'is_int'))];
    }

    /** @param array{novinky: bool, vypis: bool, kolekce: list<string>, nadrazene: list<int>} $rules */
    public static function hasRules(array $rules): bool
    {
        return $rules['novinky'] || $rules['vypis'] || $rules['kolekce'] !== [] || $rules['nadrazene'] !== [];
    }

    /**
     * Do the rules take the displayed content? $where: the page shown (ids) and its parents, the collection of an item
     * page, a news item or the news list.
     *
     * @param array{novinky: bool, vypis: bool, kolekce: list<string>, nadrazene: list<int>} $rules
     * @param array{kolekce: ?string, novinka: bool, vypis: bool, predci: list<int>} $where
     */
    public static function matchesRules(array $rules, array $where): bool
    {
        return ($rules['novinky'] && $where['novinka']) || ($rules['vypis'] && $where['vypis'])
            || ($where['kolekce'] !== null && in_array($where['kolekce'], $rules['kolekce'], true))
            || array_intersect($where['predci'], $rules['nadrazene']) !== [];
    }

    /**
     * Saves a header or footer variant (name, the pages it applies to and, since 3.6, its rules) and returns its key. A new
     * variant starts as a draft copy of the published default version (otherwise of the version from the layout). For the
     * admin and Claude (MCP). $rules null keeps the rules of an existing variant (a caller that does not know them).
     *
     * @param list<int> $pages
     * @param array<string, mixed>|null $rules
     */
    public static function saveVariant(Db $db, string $type, string $language, string $variant, string $name, array $pages, string $contentLanguage, ?array $rules = null): string
    {
        $items = (string) json_encode(array_values(array_unique(array_map('intval', $pages))));
        $ruleJson = $rules === null ? null : (self::hasRules($checked = self::sanitizeRules($rules)) ? (string) json_encode($checked, JSON_UNESCAPED_UNICODE) : null);
        if (preg_match(self::VARIANT_PATTERN, $variant) && self::row($db, $type, $language, $variant) !== null) {
            $db->update('casti', ['nazev' => $name, 'stranky' => $items, 'zmeneno' => date('Y-m-d H:i:s')] + ($rules !== null ? ['pravidla' => $ruleJson] : []),
                ['typ' => $type, 'jazyk' => $language, 'varianta' => $variant]);

            return $variant;
        }
        $variant = substr(slugify($name, 40), 0, 40);
        for ($i = 2, $base = $variant; self::row($db, $type, $language, $variant) !== null; $i++) {
            $variant = substr($base, 0, 36) . '-' . $i;
        }
        $defaults = self::row($db, $type, $language);
        $db->insert('casti', ['typ' => $type, 'jazyk' => $language, 'varianta' => $variant, 'nazev' => $name, 'stranky' => $items, 'pravidla' => $ruleJson, 'zmeneno' => date('Y-m-d H:i:s'),
            'stavba_koncept' => $defaults['stavba'] ?? Build::toJson(self::defaults($type, $contentLanguage))]);

        return $variant;
    }

    /** Published build of the part (or the work in progress for the preview in the editor); null = the part from the layout. */
    public static function build(Db $db, string $type, string $language, bool $draft = false, string $variant = ''): ?array
    {
        $r = self::row($db, $type, $language, $variant);

        return $r === null ? null : Build::fromJson($draft ? ($r['stavba_koncept'] ?? $r['stavba']) : $r['stavba']);
    }

    /**
     * Variant of the part for what is displayed (3.6), '' = the default. A published variant that lists the page always
     * wins (as before 3.6); otherwise the first published variant (by key) whose rules take the content: a news item, the
     * news list, an item page of a collection, a page under a parent.
     *
     * @param array{ids: ?int, nadrazena: ?int, kolekce: ?string, novinka: bool, vypis: bool} $where
     */
    public static function variantFor(Db $db, string $type, string $language, array $where): string
    {
        if (!in_array($type, self::WITH_VARIANTS, true)) {
            return '';
        }
        $rows = $db->all("SELECT varianta, stranky, pravidla FROM {casti} WHERE typ = ? AND jazyk = ? AND varianta <> '' AND stavba IS NOT NULL ORDER BY varianta", [$type, $language]);
        if ($where['ids'] !== null) {
            foreach ($rows as $r) {
                if (in_array($where['ids'], array_map('intval', json_decode((string) $r['stranky'], true) ?: []), true)) {
                    return (string) $r['varianta'];
                }
            }
        }
        $ancestors = null;
        foreach ($rows as $r) {
            $rules = self::sanitizeRules(json_decode((string) ($r['pravidla'] ?? ''), true));
            if ($rules['nadrazene'] !== [] && $ancestors === null) {
                $ancestors = self::ancestors($db, $where['nadrazena']);
            }
            if (self::matchesRules($rules, ['kolekce' => $where['kolekce'], 'novinka' => $where['novinka'], 'vypis' => $where['vypis'], 'predci' => $ancestors ?? []])) {
                return (string) $r['varianta'];
            }
        }

        return '';
    }

    /** The parent, grandparent… of a page from its parent's ID (at most 10 levels). @return list<int> */
    private static function ancestors(Db $db, ?int $parent): array
    {
        $out = [];
        while ($parent !== null && $parent > 0 && count($out) < 10 && !in_array($parent, $out, true)) {
            $out[] = $parent;
            $next = $db->value('SELECT nadrazena FROM {stranky} WHERE ids = ?', [$parent]);
            $parent = is_numeric($next) ? (int) $next : null;
        }

        return $out;
    }

    /**
     * Draft of a new part in the language $column (JSON): another language starts with a copy of the same part in the default
     * language (same appearance, the texts get translated), otherwise with the default build from the layout in the content language $language.
     */
    public static function initialDraft(Db $db, string $type, string $column, string $language): string
    {
        $defaults = $column !== '' ? self::row($db, $type, '') : null;
        $json = $defaults['stavba_koncept'] ?? $defaults['stavba'] ?? null;

        return $json !== null ? (string) $json : Build::toJson(self::defaults($type, $language));
    }

    /** The build the part first opens with in the builder (it matches what the layout has drawn so far). */
    public static function defaults(string $type, string $language): array
    {
        return \Kaleta\Core\Language::runWith($language, function () use ($type): array {
            $n = Build::fresh(...);
            $s = fn (array $p, array $style): array => ['styl' => $style] + $p;
            $z = fn (array $p, string $htmlTag): array => ['znacka' => $htmlTag] + $p;
            $children = match ($type) {
                'hlavicka' => [$s($z($n('sekce', [], [
                    $s($n('kontejner', [], [$n('logo'), $n('navigace')]), ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'row', 'rozmisteni' => 'space-between', 'zarovnani' => 'center', 'mezera' => 'm']]),
                ]), 'header'), ['zaklad' => ['odsazeni_y' => 's', 'pozadi' => 'pozadi', 'linka_dole' => '1px solid var(--ka-barva-linka)', 'pozice' => 'sticky', 'odshora' => '0', 'vrstva' => '10']])],
                'paticka' => [$s($z($n('sekce', [], [
                    $s($n('mrizka', [], [
                        $n('kontejner', [], [$s($z($n('udaje', ['udaj' => 'nazev']), 'p'), ['zaklad' => ['tloustka_pisma' => '700']]), $n('udaje', ['udaj' => 'popis']), $n('udaje', ['udaj' => 'email'])]),
                        $n('kontejner', [], [$n('navigace', ['menu' => 'paticka', 'novinky' => false, 'mobil' => false]), $n('udaje', ['udaj' => 'site'])]), // RSS only in <link rel="alternate">, a company footer does not need it
                    ]), ['zaklad' => ['zobrazeni' => 'grid', 'sloupce' => '2', 'mezera' => 'l'], 'mobil' => ['sloupce' => '1']]),
                    $s($n('udaje', ['udaj' => 'copyright']), ['zaklad' => ['okraj_nahore' => 'l', 'velikost_pisma' => '-1', 'barva' => 'tlumeny']]),
                ]), 'footer'), ['zaklad' => ['odsazeni_y' => 'xl', 'pozadi' => 'plocha', 'linka_nahore' => '1px solid var(--ka-barva-linka)']])],
                'novinka' => [$n('obsah', [], []), Library::section('vyzva', \Kaleta\Core\Language::code())['prvek']],
                default => [$n('obsah', [], [])],
            };

            return Build::sanitize(['v' => Build::VERSION, 'deti' => $children])[0];
        });
    }

    /**
     * A ready-made template (PartTemplates) into the part's draft: the published version stays until publishing, and a
     * part that does not exist yet is created with the template as its draft. Returns false for an unknown template.
     *
     * @param list<string> $extensions
     */
    public static function applyTemplate(Db $db, string $type, string $language, string $variant, string $key, string $contentLanguage, array $extensions): bool
    {
        $build = PartTemplates::build($type, $key, $contentLanguage, $extensions);
        if ($build === null) {
            return false;
        }
        if (self::row($db, $type, $language, $variant) === null) {
            if ($variant !== '') {
                return false;
            }
            $db->insert('casti', ['typ' => $type, 'jazyk' => $language, 'stavba_koncept' => Build::toJson($build), 'zmeneno' => date('Y-m-d H:i:s')]);
        } else {
            $db->update('casti', ['stavba_koncept' => Build::toJson($build)], ['typ' => $type, 'jazyk' => $language, 'varianta' => $variant]);
        }

        return true;
    }

    public static function versionKey(string $type, string $language, string $variant = ''): string
    {
        return $type . ':' . $language . ($variant !== '' ? ':' . $variant : '');
    }
}
