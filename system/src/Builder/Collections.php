<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;
use Kaleta\Core\WpContent;

/**
 * Collections – custom content types (references, team, products, branches…): field definitions, items and values for the builder.
 *
 * In the builder the Collection list element lists them: its inside repeats for each item and the {{field}} placeholders
 * in texts, images and links are replaced by the item's values. {{nazev}}, {{url}} (item page), {{datum}} and {{obrazek}} (the item page's image) are always available.
 */
final class Collections
{
    /** Field types (key => label). */
    public const array FIELD_TYPES = ['text' => 'short text', 'radky' => 'longer text', 'html' => 'formatted text', 'obrazek' => 'obrázek', 'odkaz' => 'odkaz', 'cislo' => 'číslo', 'datum' => 'datum',
        'termin' => 'date and time', 'soubor' => 'file', 'poloha' => 'location (latitude, longitude)', 'volba' => 'choice from options',
        'parametry' => 'parameters (Name: value per line)', 'varianty' => 'variants (name | code | price per line)', 'polozka' => 'item of another collection'];

    /** A file or an image: an https address or a path in Media. */
    public const string MEDIA_PATTERN = '#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#D';

    /** An item link (2.10) stores the address (seo_link) of the linked item – the same in every language version. */
    public const string ITEM_LINK_PATTERN = '/^[a-z0-9][a-z0-9-]{0,119}$/D';

    /** @var array<string, array<string, array{0: string, 1: string}>> linked items for this request: "collection|language" => slug => [name, path] */
    private static array $linked = [];

    /** Built-in values of every item – custom fields must not use them. */
    public const array BUILT_IN = ['nazev', 'url', 'datum', 'seo'];

    public const string PLACEHOLDER_PATTERN = '/\{\{([a-z][a-z0-9_]{0,30})\}\}/';

    public const string KEY_PATTERN = '/^[a-z][a-z0-9_]{0,30}$/D';

    /** Where a hidden item's page may redirect: '' (404), a path on the site (/team) or an https address; null = not valid. */
    public static function cleanRedirect(string $value): ?string
    {
        $value = trim($value);

        return $value === '' || preg_match('#^/[^\s"<>]{0,250}$#D', $value) === 1 || (preg_match('#^https://[^\s"<>]{3,250}$#iD', $value) === 1) ? $value : null;
    }

    /**
     * A date and time field (2.11): YYYY-MM-DD HH:MM, or only the day (an all-day event); the T of a date-time input and
     * midnight are accepted ("…T00:00" = the whole day). '' stays empty, null = not valid.
     */
    public static function cleanDateTime(string $value): ?string
    {
        $value = trim(str_replace('T', ' ', $value));
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})(?: (\d{1,2}):(\d{2})(?::\d{2})?)?$/', $value, $m) !== 1 || !checkdate((int) substr($m[1], 5, 2), (int) substr($m[1], 8, 2), (int) substr($m[1], 0, 4))) {
            return null;
        }
        if (!isset($m[2]) || ((int) $m[2] === 0 && (int) $m[3] === 0)) {
            return $m[1];
        }

        return (int) $m[2] > 23 || (int) $m[3] > 59 ? null : sprintf('%s %02d:%02d', $m[1], (int) $m[2], (int) $m[3]);
    }

    /** A location (2.11): "latitude, longitude" in degrees, kept with up to six decimals. '' stays empty, null = not valid. */
    public static function cleanLocation(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('/^(-?\d{1,2}(?:[.]\d+)?)\s*[,;]\s*(-?\d{1,3}(?:[.]\d+)?)$/', $value, $m) !== 1 || abs((float) $m[1]) > 90 || abs((float) $m[2]) > 180) {
            return null;
        }

        return rtrim(rtrim(number_format((float) $m[1], 6, '.', ''), '0'), '.') . ', ' . rtrim(rtrim(number_format((float) $m[2], 6, '.', ''), '0'), '.');
    }

    /** A date and time field for visitors: the day in the site's format, with the time when it has one. */
    public static function formatDateTime(string $value): string
    {
        if ($value === '') {
            return '';
        }

        return strlen($value) > 10 ? format_date($value, true) : format_date($value);
    }

    /** @return list<array<string, mixed>> */
    public static function all(Db $db): array
    {
        return array_map(self::extract(...), $db->all('SELECT * FROM {kolekce} ORDER BY nazev'));
    }

    /** @return array<string, mixed>|null */
    public static function bySlug(Db $db, string $seo): ?array
    {
        $r = $db->one('SELECT * FROM {kolekce} WHERE seo_link = ?', [$seo]);

        return $r === null ? null : self::extract($r);
    }

    /** @return array<string, mixed>|null */
    public static function byId(Db $db, int $idk): ?array
    {
        $r = $db->one('SELECT * FROM {kolekce} WHERE idk = ?', [$idk]);

        return $r === null ? null : self::extract($r);
    }

    /**
     * Item template in a language version: the collection whose build and draft belong to the language (sablona_jazyk). The default
     * language ('') has its template in ka_kolekce, other languages in ka_kolekce_sablony; a language without its own template has
     * both build and draft null.
     *
     * @param array<string, mixed> $collection @return array<string, mixed>
     */
    public static function inLanguage(Db $db, array $collection, string $language): array
    {
        $collection['sablona_jazyk'] = $language;
        if ($language === '') {
            return $collection;
        }
        $r = $db->one('SELECT stavba, stavba_koncept, zmeneno FROM {kolekce_sablony} WHERE idk = ? AND jazyk = ?', [$collection['idk'], $language]);

        return ['stavba' => $r['stavba'] ?? null, 'stavba_koncept' => $r['stavba_koncept'] ?? null, 'zmeneno' => $r['zmeneno'] ?? null] + $collection;
    }

    /**
     * Writes the columns of the language template returned by inLanguage (the default into ka_kolekce, another language creates a row).
     *
     * @param array<string, mixed> $columns
     */
    public static function writeTemplate(Db $db, array $collection, array $columns): void
    {
        if (($collection['sablona_druh'] ?? '') === 'kategorie') {
            CollectionCategories::writeTemplate($db, $collection, $columns); // the category template (3.7)

            return;
        }
        $language = (string) ($collection['sablona_jazyk'] ?? '');
        if ($language === '') {
            $db->update('kolekce', $columns, ['idk' => $collection['idk']]);
        } elseif ($db->value('SELECT 1 FROM {kolekce_sablony} WHERE idk = ? AND jazyk = ?', [$collection['idk'], $language]) !== null) {
            $db->update('kolekce_sablony', $columns, ['idk' => $collection['idk'], 'jazyk' => $language]);
        } else {
            $db->insert('kolekce_sablony', $columns + ['idk' => $collection['idk'], 'jazyk' => $language]);
        }
    }

    /**
     * Key of the template's versions and signed preview: kolekce:<idk>, for another language kolekce:<idk>:<jazyk>; the
     * category template (3.7) kategorie:<idk>[:<jazyk>].
     */
    public static function templateKey(array $collection): string
    {
        $language = (string) ($collection['sablona_jazyk'] ?? '');
        $prefix = ($collection['sablona_druh'] ?? '') === 'kategorie' ? CollectionCategories::TEMPLATE_PREFIX : 'kolekce:';

        return $prefix . (int) $collection['idk'] . ($language !== '' ? ':' . $language : '');
    }

    /**
     * The draft the builder opens a language's template with until anyone saves it: another language starts with a copy of the
     * default language's template, the default language with a template assembled from the collection fields.
     */
    public static function initialTemplateDraft(Db $db, array $collection): string
    {
        if (($collection['sablona_druh'] ?? '') === 'kategorie') {
            return CollectionCategories::initialTemplateDraft($db, $collection);
        }
        if (($collection['sablona_jazyk'] ?? '') !== '') {
            $defaults = (array) self::byId($db, (int) $collection['idk']);
            if (($defaults['stavba_koncept'] ?? $defaults['stavba'] ?? null) !== null) {
                return (string) ($defaults['stavba_koncept'] ?? $defaults['stavba']);
            }
        }

        return Build::toJson(self::defaultTemplate($collection));
    }

    private static function extract(array $r): array
    {
        $r['pole'] = json_decode((string) $r['pole'], true) ?: [];

        return $r;
    }

    /**
     * Field definitions from the form or from AI: the key only lowercase letters, digits and underscore (made from the label), a known type.
     *
     * @return list<array{klic: string, popisek: string, typ: string, kolekce?: string, moznosti?: list<string>}>
     */
    public static function sanitizeFields(mixed $input): array
    {
        $field = [];
        $keys = [];
        foreach (is_array($input) ? $input : [] as $p) {
            $labelText = mb_substr(trim(strip_tags((string) ($p['popisek'] ?? ''))), 0, 80);
            if ($labelText === '') {
                continue;
            }
            $key = (string) ($p['klic'] ?? '');
            $key = preg_match('/^[a-z][a-z0-9_]{0,30}$/D', $key) ? $key : substr(str_replace('-', '_', slugify($labelText, 30)), 0, 30);
            if (!preg_match('/^[a-z]/', $key)) {
                $key = 'pole_' . $key;
            }
            while (in_array($key, self::BUILT_IN, true) || isset($keys[$key])) {
                $key .= '_2';
            }
            $keys[$key] = true;
            $type = isset(self::FIELD_TYPES[$p['typ'] ?? '']) ? $p['typ'] : 'text';
            // a link to an item of another collection (2.10) knows which collection; without one it is a short text
            $target = (string) ($p['kolekce'] ?? '');
            if ($type === 'polozka' && preg_match('/^[a-z0-9][a-z0-9-]{0,109}$/D', $target) !== 1) {
                $type = 'text';
            }
            // a choice (2.11) keeps its options: up to 30 short texts, one per line in the form
            $options = $type === 'volba' ? self::cleanOptions($p['moznosti'] ?? []) : [];
            if ($type === 'volba' && $options === []) {
                $type = 'text';
            }
            $field[] = ['klic' => $key, 'popisek' => $labelText, 'typ' => $type] + ($type === 'polozka' ? ['kolekce' => $target] : []) + ($type === 'volba' ? ['moznosti' => $options] : []);
        }

        return array_slice($field, 0, 30);
    }

    /** Options of a choice field: a list or lines of text, trimmed, without tags and duplicates, at most 30 of 80 characters. @return list<string> */
    public static function cleanOptions(mixed $input): array
    {
        $lines = is_array($input) ? $input : preg_split('/\R/', (string) (is_scalar($input) ? $input : ''));
        $out = [];
        foreach ((array) $lines as $line) {
            $line = mb_substr(trim(strip_tags(is_scalar($line) ? (string) $line : '')), 0, 80);
            if ($line !== '' && !in_array($line, $out, true)) {
                $out[] = $line;
            }
        }

        return array_slice($out, 0, 30);
    }

    /**
     * Item values by the field definitions. An invalid value is discarded and reported.
     *
     * @param list<array{klic: string, popisek: string, typ: string, kolekce?: string, moznosti?: list<string>}> $field
     * @param array<string, string> $errors
     * @param array{limit: string, value: int, max: int}|null $tooLarge the first HTML field over a limit of Core\HtmlLimits (left empty)
     * @return array<string, string>
     */
    public static function sanitizeData(array $field, array $input, array &$errors = [], ?array &$tooLarge = null): array
    {
        $data = [];
        foreach ($field as $p) {
            $h = trim((string) (is_scalar($input[$p['klic']] ?? null) ? $input[$p['klic']] : ''));
            $limit = null;
            $clean = match ($p['typ']) {
                'text' => mb_substr(strip_tags(str_replace(["\r", "\n"], ' ', $h)), 0, 500),
                'radky' => mb_substr(strip_tags(str_replace("\r\n", "\n", $h)), 0, 5000),
                'html' => self::htmlField(mb_substr($h, 0, 100000), $limit),
                'obrazek' => $h === '' || preg_match('#^(https://[^\s"\'<>]{1,500}|/?([A-Za-z0-9_.-]+/){0,3}media/[A-Za-z0-9/_.-]{1,300})$#D', $h) ? $h : null,
                'odkaz' => $h === '' || (WpContent::isSafeUrl($h) && !preg_match('/[\s"<>]/', $h)) ? mb_substr($h, 0, 500) : null,
                'cislo' => $h === '' || is_numeric(str_replace([' ', ','], ['', '.'], $h)) ? str_replace(' ', '', $h) : null,
                'datum' => $h === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $h) && strtotime($h) !== false) ? $h : null,
                'termin' => self::cleanDateTime($h),
                'soubor' => $h === '' || (preg_match(self::MEDIA_PATTERN, $h) === 1 && !str_contains($h, '..')) ? $h : null,
                'poloha' => self::cleanLocation($h),
                'volba' => $h === '' || in_array($h, (array) ($p['moznosti'] ?? []), true) ? $h : null,
                'parametry' => \Kaleta\Builder\Products::cleanParameters($h),
                'varianty' => \Kaleta\Builder\Products::cleanVariants($h),
                'polozka' => $h === '' || preg_match(self::ITEM_LINK_PATTERN, $h) === 1 ? $h : null,
                default => '',
            };
            if ($clean === null) {
                $errors[$p['klic']] = $limit === null ? $p['popisek'] : $p['popisek'] . ' – ' . \Kaleta\Core\HtmlLimits::message($limit);
                $tooLarge ??= $limit;
                $clean = '';
            }
            $data[$p['klic']] = $clean;
        }

        return $data;
    }

    /**
     * A rich text value (WpContent::safeHtml); null when it is over a limit of Core\HtmlLimits – the field is then left empty
     * and reported, never kept as it came.
     *
     * @param array{limit: string, value: int, max: int}|null $limit
     */
    private static function htmlField(string $html, ?array &$limit): ?string
    {
        try {
            return \Kaleta\Core\HtmlLimits::guard(fn (): string => WpContent::safeHtml($html));
        } catch (\Kaleta\Core\HtmlTooLarge $e) {
            $limit = $e->violation;

            return null;
        }
    }

    /**
     * Visible items of a collection in the site language: filter by field value, sorting (also by a custom field) and pagination.
     *
     * @param array{0: string, 1: string}|null $filter [field key, value]
     * @param array{0: string, 1: string, 2: string}|null $period [PERIODS key, start field, end field] (2.11)
     * @param list<int>|null $categories only items in one of these categories (3.7); [] = none, null = no filter
     * @return array{0: list<array<string, mixed>>, 1: int} [items, total]
     */
    public static function items(Db $db, int $idk, string $language, int $count, string $sort = 'poradi', ?array $filter = null, int $pageNumber = 1, string $sortField = '', ?array $period = null, ?array $categories = null): array
    {
        $field = fn (string $key): string => "JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "'))"; // the key passed KEY_PATTERN
        $whereParts = 'idk = ? AND zobrazit = 1 AND jazyk = ?';
        $params = [$idk, $language];
        if ($filter !== null && preg_match(self::KEY_PATTERN, $filter[0]) && $filter[1] !== '') {
            $whereParts .= ' AND ' . $field($filter[0]) . ' = ?';
            $params[] = $filter[1];
        }
        if ($period !== null && ($condition = self::periodCondition($period[0], $period[1], $period[2], date('Y-m-d H:i'))) !== null) {
            $whereParts .= ' AND ' . $condition[0];
            $params = [...$params, ...$condition[1]];
        }
        if ($categories !== null) {
            $categories = array_values(array_unique(array_map(intval(...), $categories)));
            $whereParts .= $categories === [] ? ' AND 0 = 1'
                : ' AND idp IN (SELECT idp FROM {collection_item_categories} WHERE category_id IN (' . implode(',', array_fill(0, count($categories), '?')) . '))';
            $params = [...$params, ...$categories];
        }
        $byField = preg_match(self::KEY_PATTERN, $sortField) === 1;
        $order = match (true) {
            $sort === 'nazev' => 'nazev',
            $sort === 'nejnovejsi' => 'datum DESC, idp DESC',
            // numbers sort as numbers, everything else as text
            $sort === 'pole' && $byField => '(' . $field($sortField) . ' + 0) ASC, ' . $field($sortField) . ' ASC, nazev',
            $sort === 'pole_sestupne' && $byField => '(' . $field($sortField) . ' + 0) DESC, ' . $field($sortField) . ' DESC, nazev',
            default => 'poradi, nazev',
        };
        $count = max(1, min(100, $count));
        $total = (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE ' . $whereParts, $params);
        $items = array_map(function (array $r): array {
            $r['data'] = json_decode((string) $r['data'], true) ?: [];

            return $r;
        }, $db->all('SELECT * FROM {kolekce_polozky} WHERE ' . $whereParts . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', [...$params, $count, (max(1, $pageNumber) - 1) * $count]));

        return [$items, $total];
    }

    /**
     * The previous and the next visible item of the same language (3.7, Previous / next item): by the order in the
     * administration (order, name – as lists sort them), or by date (previous = older, next = newer, as blogs have it);
     * optionally only among items in the given categories.
     *
     * @param list<int>|null $categories
     * @return array{0: ?array<string, mixed>, 1: ?array<string, mixed>} [previous, next]
     */
    public static function neighbours(Db $db, array $item, string $by, ?array $categories = null): array
    {
        $where = 'idk = ? AND jazyk = ? AND zobrazit = 1 AND smazano IS NULL AND idp <> ?';
        $params = [(int) $item['idk'], (string) $item['jazyk'], (int) $item['idp']];
        if ($categories !== null && $categories !== []) {
            $where .= ' AND idp IN (SELECT idp FROM {collection_item_categories} WHERE category_id IN (' . implode(',', array_fill(0, count($categories), '?')) . '))';
            $params = [...$params, ...array_map(intval(...), $categories)];
        }
        if ($by === 'datum') {
            $key = [(string) $item['datum'], (int) $item['idp']];
            $before = '(datum < ? OR (datum = ? AND idp < ?))';
            $after = '(datum > ? OR (datum = ? AND idp > ?))';
            $keyParams = [$key[0], $key[0], $key[1]];
            [$down, $up] = ['datum DESC, idp DESC', 'datum, idp'];
        } else {
            $key = [(int) $item['poradi'], (string) $item['nazev'], (int) $item['idp']];
            $before = '(poradi < ? OR (poradi = ? AND nazev < ?) OR (poradi = ? AND nazev = ? AND idp < ?))';
            $after = '(poradi > ? OR (poradi = ? AND nazev > ?) OR (poradi = ? AND nazev = ? AND idp > ?))';
            $keyParams = [$key[0], $key[0], $key[1], $key[0], $key[1], $key[2]];
            [$down, $up] = ['poradi DESC, nazev DESC, idp DESC', 'poradi, nazev, idp'];
        }
        $find = fn (string $condition, string $order): ?array => $db->one('SELECT * FROM {kolekce_polozky} WHERE ' . $where . ' AND ' . $condition . ' ORDER BY ' . $order . ' LIMIT 1', [...$params, ...$keyParams]);

        return [$find($before, $down), $find($after, $up)];
    }

    /** Which items a list shows by their dates (2.11): events that are still to come, notices that are posted now, the archive. */
    public const array PERIODS = ['' => 'all items', 'nadchazejici' => 'upcoming – not ended yet', 'probihajici' => 'current – started and not ended', 'minule' => 'past – ended'];

    /**
     * The SQL condition of a period over a start field and an optional end field (date, or date and time; a day without a
     * time starts at 00:00 and lasts until 23:59):
     *
     *  - upcoming: has a start and has not ended – it ends at its end, or at its start when it has none (an event);
     *  - past: has ended by the same rule (the archive);
     *  - current: has started (or has no start) and has not ended – without an end it never ends, so a notice with no
     *    takedown date stays up; without an end field the start's day is the whole period. An item with no dates is never current.
     *
     * null = no condition (an unknown period or field key).
     *
     * @return array{0: string, 1: list<string>}|null
     */
    public static function periodCondition(string $period, string $startKey, string $endKey, string $now): ?array
    {
        if ($period === '' || !isset(self::PERIODS[$period]) || preg_match(self::KEY_PATTERN, $startKey) !== 1 || ($endKey !== '' && preg_match(self::KEY_PATTERN, $endKey) !== 1)) {
            return null;
        }
        $value = fn (string $key): string => "COALESCE(JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "')), '')";
        $endOfDay = fn (string $v): string => 'IF(LENGTH(' . $v . ') = 10, CONCAT(' . $v . ", ' 23:59'), " . $v . ')';
        $start = $value($startKey);
        $startOfDay = 'IF(LENGTH(' . $start . ') = 10, CONCAT(' . $start . ", ' 00:00'), " . $start . ')';
        $ends = $endKey !== '' ? 'COALESCE(NULLIF(' . $value($endKey) . ", ''), " . $start . ')' : $start; // upcoming and past
        $end = $endKey !== '' ? $value($endKey) : $start; // current

        return match ($period) {
            'nadchazejici' => ['(' . $start . " <> '' AND " . $endOfDay($ends) . ' >= ?)', [$now]],
            'minule' => ['(' . $ends . " <> '' AND " . $endOfDay($ends) . ' < ?)', [$now]],
            default => ['((' . $start . " <> '' OR " . $end . " <> '') AND (" . $start . " = '' OR " . $startOfDay . ' <= ?) AND (' . $end . " = '' OR " . $endOfDay($end) . ' >= ?))', [$now, $now]],
        };
    }

    /** Distinct values of a field among the visible items (filter buttons in the list). @return list<string> */
    public static function fieldValues(Db $db, int $idk, string $language, string $key): array
    {
        if (!preg_match(self::KEY_PATTERN, $key)) {
            return [];
        }

        return array_values(array_filter(array_map('strval', array_column($db->all(
            "SELECT DISTINCT JSON_UNQUOTE(JSON_EXTRACT(data, '$." . $key . "')) AS h FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 AND jazyk = ? ORDER BY h LIMIT 30",
            [$idk, $language],
        ), 'h')), fn (string $h): bool => $h !== '' && $h !== 'null'));
    }

    /**
     * Values for the placeholders: key => [value, type].
     *
     * @param callable(string): string $url url within the site
     * @return array<string, array{0: string, 1: string}>
     */
    public static function values(array $collection, array $item, callable $url, ?Db $db = null): array
    {
        $h = [
            'nazev' => [(string) $item['nazev'], 'text'],
            'url' => [$collection['detail'] ? $url($collection['seo_link'] . '/' . $item['seo_link']) : '', 'odkaz'],
            'datum' => [format_date((string) $item['datum']), 'text'],
            'seo' => [(string) $item['seo_link'], 'text'],
        ];
        foreach ($collection['pole'] as $p) {
            $value = (string) ($item['data'][$p['klic']] ?? '');
            if ($p['typ'] === 'polozka') {
                // {{branch}} = the name of the linked item, {{branch_url}} its page, {{branch_seo}} its address (for related lists)
                $linked = $db !== null && $value !== '' ? (self::linked($db, (string) ($p['kolekce'] ?? ''))[$value] ?? null) : null;
                $h[$p['klic']] = [$linked[0] ?? '', 'text'];
                $h[$p['klic'] . '_url'] ??= [$linked !== null && $linked[1] !== '' ? $url($linked[1]) : '', 'odkaz'];
                $h[$p['klic'] . '_seo'] ??= [$value, 'text'];
                continue;
            }
            if ($p['typ'] === 'termin') {
                // {{start}} = the day (and time) for visitors, {{start_iso}} = as stored, for machines (a time element, iCal)
                $h[$p['klic']] = [self::formatDateTime($value), 'text'];
                $h[$p['klic'] . '_iso'] ??= [$value, 'text'];
                continue;
            }
            if ($p['typ'] === 'soubor') {
                // {{datasheet}} = the file's address (a link or a button), {{datasheet_name}} = its file name
                $h[$p['klic']] = [$value, 'odkaz'];
                $h[$p['klic'] . '_name'] ??= [$value !== '' ? rawurldecode(basename((string) parse_url($value, PHP_URL_PATH))) : '', 'text'];
                continue;
            }
            if ($p['typ'] === 'parametry' || $p['typ'] === 'varianty') {
                // a table for visitors (2.11): parameters to compare, variants with their code and price
                $h[$p['klic']] = [$p['typ'] === 'parametry' ? Products::parametersTable($value) : Products::variantsTable($value), 'html'];
                continue;
            }
            if ($p['typ'] === 'volba') {
                $h[$p['klic']] = [$value !== '' ? t($value) : '', 'text']; // a preset's options are English keys with site translations
                continue;
            }
            $h[$p['klic']] = [$value, $p['typ']];
        }
        // {{obrazek}} without a field of that name (3.9.2) = the image of the item's page (for sharing), else its first image
        // field – the same image search engines and social networks show, so a card or a hero has a photo without a new field
        if (!isset($h['obrazek'])) {
            $image = (string) ($item['obrazek'] ?? '');
            foreach ($collection['pole'] as $p) {
                $image = $image === '' && $p['typ'] === 'obrazek' ? (string) ($item['data'][$p['klic']] ?? '') : $image;
            }
            $h['obrazek'] = [$image, 'obrazek'];
        }
        if ($db !== null && ($collection['preset'] ?? '') !== '') {
            $h += \Kaleta\Core\Calendar::values($db, $collection, $item, $url, date('Y-m-d H:i')); // an event's when, where, status, iCal (2.11)
            $h += Products::values($collection, $item); // a product for the enquiry basket and comparison (2.11)
        }
        foreach (\Kaleta\Core\Notices::placeholders($collection, $item) as $key => $value) {
            $h[$key] ??= $value; // {{notice_status}} of an official notice board (2.11) – a field with that key wins
        }

        return $h;
    }

    /**
     * Visible items of a collection for item links (2.10): address => [name, path of its page ('' without item pages)], in the
     * language version of the site with the default language where the version has no own item.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function linked(Db $db, string $collectionSlug, ?string $language = null): array
    {
        $language ??= \Kaleta\Core\Language::siteColumn();
        $key = $collectionSlug . '|' . $language;
        if (isset(self::$linked[$key])) {
            return self::$linked[$key];
        }
        $collection = $collectionSlug !== '' ? self::bySlug($db, $collectionSlug) : null;
        $out = [];
        if ($collection !== null) {
            foreach ($db->all("SELECT nazev, seo_link, jazyk FROM {kolekce_polozky} WHERE idk = ? AND zobrazit = 1 AND smazano IS NULL AND jazyk IN ('', ?) ORDER BY jazyk = '' DESC, nazev",
                [(int) $collection['idk'], $language]) as $r) {
                $out[(string) $r['seo_link']] = [(string) $r['nazev'], $collection['detail'] ? $collection['seo_link'] . '/' . $r['seo_link'] : '']; // a translation overwrites the default
            }
        }

        return self::$linked[$key] = $out;
    }

    /**
     * Items of a collection to choose from in the admin (all, also hidden ones), name => address.
     *
     * @return array<string, string> address => name
     */
    public static function choices(Db $db, string $collectionSlug): array
    {
        $collection = $collectionSlug !== '' ? self::bySlug($db, $collectionSlug) : null;

        return $collection === null ? [] : $db->pairs("SELECT seo_link, nazev FROM {kolekce_polozky} WHERE idk = ? AND jazyk = '' AND smazano IS NULL ORDER BY nazev", [(int) $collection['idk']]);
    }

    /** Sample values for the editor when the collection has no items yet: field labels in square brackets. */
    public static function sample(array $collection): array
    {
        $h = ['nazev' => ['[' . t('Název') . ']', 'text'], 'url' => ['#', 'odkaz'], 'datum' => [format_date(date('Y-m-d H:i:s')), 'text'], 'seo' => ['', 'text']];
        foreach ($collection['pole'] as $p) {
            $h[$p['klic']] = [in_array($p['typ'], ['obrazek', 'odkaz', 'soubor'], true) ? '' : '[' . $p['popisek'] . ']', $p['typ'] === 'soubor' ? 'odkaz' : (in_array($p['typ'], ['termin', 'volba', 'poloha', 'parametry', 'varianty'], true) ? 'text' : $p['typ'])];
        }
        if (\Kaleta\Core\Notices::isBoard($collection)) {
            $h['notice_status'] ??= ['[' . t('Notice status') . ']', 'text'];
        }
        $h['obrazek'] ??= ['', 'obrazek'];

        return $h;
    }

    /**
     * Fills values into an element's content field by the type of the target field (text is escaped only when rendering, inline and html right away).
     *
     * @param array<string, array{0: string, 1: string}> $values
     */
    public static function fill(string $text, string $target, array $values): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        // one pass: a filled-in value is not scanned again ({{…}} tags written in a field's text stay text)
        $htmlTag = substr(self::PLACEHOLDER_PATTERN, 1, -1);
        $pattern = $target === 'html' ? '#<p>\s*' . $htmlTag . '\s*</p>|' . $htmlTag . '#' : self::PLACEHOLDER_PATTERN;
        $result = (string) preg_replace_callback($pattern, function (array $m) use ($target, $values): string {
            $key = ($m[1] ?? '') !== '' ? $m[1] : $m[2];
            [$h, $type] = $values[$key] ?? ['', 'text'];
            if ($target === 'html' && ($m[1] ?? '') !== '') {
                // a paragraph with only a tag of formatted or longer text is replaced whole (otherwise <p><p>…</p></p> would result)
                return match ($type) {
                    'html' => $h,
                    'radky' => $h === '' ? '' : '<p>' . nl2br(e($h), false) . '</p>',
                    default => $h === '' ? '' : '<p>' . e($h) . '</p>',
                };
            }
            $plain = $type === 'html' ? trim(html_entity_decode(strip_tags($h), ENT_QUOTES | ENT_HTML5)) : $h;

            return match ($target) {
                'html' => $type === 'html' ? $h : ($type === 'radky' ? nl2br(e($h), false) : e($h)),
                'inline' => $type === 'radky' ? nl2br(e($h), false) : e($plain),
                // Custom HTML is output as it is (the code filter ran on save, the filling only now): the value must not bring tags
                'kod' => $type === 'html' ? \Kaleta\Core\Html::safe($h) : ($type === 'radky' ? nl2br(e($h), false) : e($h)),
                default => $plain,
            };
        }, $text);
        if ($target === 'odkaz' && $result !== '' && !WpContent::isSafeUrl($result)) {
            return '';
        }
        if ($target === 'obrazek' && $result !== '' && !preg_match(self::MEDIA_PATTERN, $result)) {
            return '';
        }

        return $result;
    }

    /** Item template until the administrator edits it in the builder: heading, image and all fields one below another. */
    public static function defaultTemplate(array $collection): array
    {
        $n = Build::fresh(...);
        $children = [['znacka' => 'h1'] + $n('nadpis', ['text' => '{{nazev}}'])];
        foreach ($collection['pole'] as $p) {
            $children[] = match ($p['typ']) {
                'obrazek' => $n('obrazek', ['src' => '{{' . $p['klic'] . '}}', 'alt' => '{{nazev}}']),
                'odkaz' => $n('tlacitko', ['text' => $p['popisek'], 'odkaz' => '{{' . $p['klic'] . '}}', 'varianta' => 'obrys']),
                'soubor' => $n('tlacitko', ['text' => $p['popisek'] . ' ({{' . $p['klic'] . '_name}})', 'odkaz' => '{{' . $p['klic'] . '}}', 'varianta' => 'obrys']),
                'html', 'radky' => $n('text', ['html' => '{{' . $p['klic'] . '}}']),
                default => $n('text', ['html' => '<p><strong>' . e($p['popisek']) . ':</strong> {{' . $p['klic'] . '}}</p>']),
            };
        }

        return Build::sanitize(['v' => Build::VERSION, 'deti' => [$n('sekce', ['sirka' => 'uzka'], [
            ['styl' => ['zaklad' => ['zobrazeni' => 'flex', 'smer' => 'column', 'mezera' => 'm']]] + $n('kontejner', [], $children),
        ])]])[0];
    }

    /* ---------- items as full pages (1.9) ---------- */

    /** Columns of an item that make up one version in the history (ka_stavba_revize, cast polozka:<idp>). */
    public const array VERSIONED = ['nazev', 'seo_link', 'data', 'seo_titulek', 'popis', 'obrazek', 'noindex'];

    /**
     * SEO fields and scheduled publishing of an item from a form or from Claude. A hidden item with a future time
     * publishes itself then (Notifications::process); a past time publishes it at once.
     *
     * @param array{seo_titulek?: mixed, popis?: mixed, obrazek?: mixed, noindex?: mixed, zverejnit_od?: mixed} $input
     * @return array{seo_titulek: string, popis: string, obrazek: string, noindex: int, zverejnit_od: ?string, zobrazit: int}
     */
    public static function pageFields(array $input, bool $visible): array
    {
        $text = fn (string $key, int $max): string => mb_substr(trim(is_scalar($input[$key] ?? null) ? (string) $input[$key] : ''), 0, $max);
        $image = $text('obrazek', 255);
        $from = is_string($input['zverejnit_od'] ?? null) && $input['zverejnit_od'] !== '' ? (strtotime(str_replace('T', ' ', $input['zverejnit_od'])) ?: null) : null;

        return [
            'seo_titulek' => $text('seo_titulek', 200), 'popis' => $text('popis', 300),
            'obrazek' => preg_match('#^(/?media/|https://)[^\s"\'<>]+$#D', $image) && !str_contains($image, '..') ? $image : '',
            'noindex' => filter_var($input['noindex'] ?? false, FILTER_VALIDATE_BOOL) ? 1 : 0,
            'zverejnit_od' => !$visible && $from !== null && $from > time() ? date('Y-m-d H:i:s', $from) : null,
            'zobrazit' => $visible || ($from !== null && $from <= time()) ? 1 : 0,
        ];
    }

    /**
     * Keeps the item as it was before a save in its history (the last Publisher::VERSIONS_KEPT) – and, for a document
     * whose file changes, the previous file for good (2.11, Core\Documents).
     */
    public static function saveVersion(\Kaleta\Core\App $app, array $previous, array $new): void
    {
        \Kaleta\Core\Documents::keepVersion($app, $previous, $new);
        $snapshot = fn (array $r): string => (string) json_encode(array_intersect_key($r, array_flip(self::VERSIONED)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $old = $snapshot($previous);
        $new = $snapshot(array_replace($previous, $new));
        Publisher::version($app, ['cast' => 'polozka:' . (int) $previous['idp']], $old, $new, $previous['zmeneno'] ?? $previous['datum'] ?? null);
    }

    /** @return array<string, mixed>|null the item columns stored in one version */
    public static function loadVersion(Db $db, int $idp, int $idr): ?array
    {
        $stored = json_decode((string) Publisher::load($db, ['cast' => 'polozka:' . $idp], $idr), true);

        return is_array($stored) ? array_intersect_key($stored, array_flip(self::VERSIONED)) : null;
    }
}
