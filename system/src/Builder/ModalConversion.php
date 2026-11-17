<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\Db;

/**
 * Kaleta 2.0 has one pop-up system: the per-page Modal element (type "okno") of 1.x becomes a site pop-up (ka_popupy).
 *
 * Each Modal found in a build turns into a pop-up of the type "window" shown only where the Modal was (its page, its
 * collection's item pages, the pages of its header or footer variant), with the same content, trigger and frequency:
 * "only via a link" becomes the trigger "click", after N seconds / after half the page / on leaving stay, "once a week"
 * becomes "once in 7 days", "never again" becomes "until closed". Links to the old anchor (#nabidka) are rewritten to the
 * pop-up (#popup-nabidka); the old anchor stays on the content, so an address like /page#nabidka still opens it.
 *
 * Used by the database migration 0034 and by the import of a 1.x export (Core\SiteImport).
 */
final class ModalConversion
{
    /** The Modal's "open automatically" => [pop-up trigger, value]. */
    private const array TRIGGERS = ['0' => ['klik', 0], '5' => ['cas', 5], '15' => ['cas', 15], '30' => ['cas', 30], 'posun' => ['posun', 50], 'odchod' => ['odchod', 0]];

    /** The Modal's "open again" => [pop-up frequency, days]. */
    private const array FREQUENCIES = ['relace' => ['relace', 7], 'tyden' => ['dni', 7], 'nikdy' => ['zavreni', 7]];

    /** Tables with builds: table => [key column, columns with builds]. */
    private const array SOURCES = ['stranky' => ['ids', ['stavba', 'stavba_koncept']], 'casti' => [null, ['stavba', 'stavba_koncept']],
        'kolekce' => ['idk', ['stavba', 'stavba_koncept']], 'kolekce_sablony' => [null, ['stavba', 'stavba_koncept']],
        'komponenty' => ['idm', ['stavba', 'stavba_koncept']], 'popupy' => ['idpp', ['stavba', 'stavba_koncept']]];

    /** Converts every Modal on the site (the migration). Returns how many pop-ups were created. */
    public static function run(Db $db): int
    {
        $created = 0;
        foreach (self::SOURCES as $table => [$key, $columns]) {
            foreach ($db->all('SELECT * FROM {' . $table . '} WHERE stavba LIKE ? OR stavba_koncept LIKE ?', ['%"typ":"okno"%', '%"typ":"okno"%']) as $row) {
                $builds = [];
                foreach ($columns as $column) {
                    $builds[$column] = $row[$column] === null ? null : (json_decode((string) $row[$column], true) ?: null);
                }
                [$builds, $count] = self::convertRow($db, $table, $row, $builds);
                $created += $count;
                $changes = array_map(fn (?array $b): ?string => $b === null ? null : Build::toJson($b), $builds);
                $db->update($table, $changes, self::where($table, $key, $row));
            }
        }
        // saved sections of the builder: the Modal's content stays, without the window around it
        foreach ($db->all('SELECT idx, prvek FROM {sekce} WHERE prvek LIKE ?', ['%"typ":"okno"%']) as $r) {
            $element = json_decode((string) $r['prvek'], true);
            if (is_array($element)) {
                $db->update('sekce', ['prvek' => (string) json_encode(self::unwrap(['deti' => [$element]])['deti'][0] ?? $element, JSON_UNESCAPED_UNICODE)], ['idx' => $r['idx']]);
            }
        }
        if ($created > 0) {
            \Kaleta\Core\PageCache::clear();
        }

        return $created;
    }

    /**
     * One row (a page, a site part, a collection template, a component, a pop-up): pop-ups out of its Modals, the builds
     * without them and with links to the pop-ups. A Modal inside a pop-up only loses its window.
     *
     * @param array<string, ?array> $builds column => decoded build
     * @return array{0: array<string, ?array>, 1: int} the new builds and the number of pop-ups created
     */
    public static function convertRow(Db $db, string $table, array $row, array $builds): array
    {
        if ($table === 'popupy') {
            return [array_map(fn (?array $b): ?array => $b === null ? null : self::unwrap($b), $builds), 0];
        }
        // the same Modal is usually in the published build and in the draft: one pop-up with both
        $found = [];
        foreach ($builds as $column => $build) {
            if ($build === null) {
                continue;
            }
            [$builds[$column], $modals] = self::extract($build);
            foreach ($modals as $m) {
                $found[self::anchor($m)][$column] = $m;
            }
        }
        $map = [];
        foreach ($found as $anchor => $versions) {
            $map[$anchor] = self::popup($db, $versions['stavba'] ?? null, $versions['stavba_koncept'] ?? null, self::rules($db, $table, $row), self::name($table, $row, $versions['stavba'] ?? $versions['stavba_koncept']));
        }
        foreach ($builds as $column => $build) {
            if ($build !== null && $map !== []) {
                $builds[$column] = json_decode(self::rewriteLinks((string) json_encode($build, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $map), true);
            }
        }

        return [$builds, count($map)];
    }

    /** @return array{0: array, 1: list<array>} the build without Modals (at any depth) and the Modals taken out */
    public static function extract(array $build): array
    {
        $modals = [];
        $walk = function (array $nodes) use (&$walk, &$modals): array {
            $out = [];
            foreach ($nodes as $n) {
                if (!is_array($n)) {
                    continue;
                }
                if (($n['typ'] ?? '') === 'okno') {
                    $modals[] = $n;
                    continue;
                }
                if (is_array($n['deti'] ?? null)) {
                    $n['deti'] = $walk($n['deti']);
                }
                $out[] = $n;
            }

            return $out;
        };
        $build['deti'] = $walk(is_array($build['deti'] ?? null) ? $build['deti'] : []);

        return [$build, $modals];
    }

    /** The anchor a link opened the Modal with: an id among its attributes, its own anchor, otherwise okno-<id>. */
    public static function anchor(array $modal): string
    {
        $id = $modal['atributy']['id'] ?? $modal['kotva'] ?? null;

        return is_string($id) && $id !== '' ? $id : 'okno-' . (string) ($modal['id'] ?? '');
    }

    /** Creates the pop-up; returns its address (the link #popup-<address> opens it). */
    public static function popup(Db $db, ?array $published, ?array $draft, array $rules, string $name): string
    {
        $modal = $published ?? $draft ?? [];
        $content = is_array($modal['obsah'] ?? null) ? $modal['obsah'] : [];
        [$trigger, $value] = self::TRIGGERS[(string) ($content['samo'] ?? '0')] ?? self::TRIGGERS['0'];
        [$frequency, $days] = self::FREQUENCIES[(string) ($content['znovu'] ?? 'relace')] ?? self::FREQUENCIES['relace'];
        $conditions = is_array($modal['podminky'] ?? null) ? $modal['podminky'] : [];
        $rules = Popups::sanitizeRules($rules + ['od' => $conditions['od'] ?? '', 'do' => $conditions['do'] ?? '']);
        $anchor = self::anchor($modal);
        $address = Popups::address($db, $anchor);

        return (string) $db->value('SELECT adresa FROM {popupy} WHERE idpp = ?', [$db->insert('popupy', [
            'nazev' => mb_substr($name, 0, 100), 'adresa' => $address, 'typ' => 'okno', 'spoustec' => $trigger, 'hodnota' => $value,
            'pravidla' => (string) json_encode($rules, JSON_UNESCAPED_UNICODE), 'cetnost' => $frequency, 'dni' => $days, 'aktivni' => $published !== null ? 1 : 0, 'poradi' => 100,
            'stavba' => $published !== null ? self::content($published, $anchor) : null,
            'stavba_koncept' => $draft !== null && $draft !== $published ? self::content($draft, $anchor) : null,
            'zmeneno' => date('Y-m-d H:i:s'),
        ])]);
    }

    /** The pop-up's build: the Modal's content in a container that keeps its style and the old anchor. */
    private static function content(array $modal, string $anchor): string
    {
        $container = ['typ' => 'kontejner', 'deti' => is_array($modal['deti'] ?? null) ? $modal['deti'] : []]
            + array_intersect_key($modal, array_flip(['styl', 'tridy', 'css']))
            + (preg_match('/^[a-z][a-z0-9-]{0,40}$/D', $anchor) && !preg_match('/^(s|ka)-/', $anchor) ? ['kotva' => $anchor] : []);
        [$clean] = Build::sanitize(['v' => Build::VERSION, 'deti' => [$container]], true);

        return Build::toJson($clean);
    }

    /** Where the pop-up shows: where the Modal was. */
    private static function rules(Db $db, string $table, array $row): array
    {
        $pages = fn (mixed $json): array => array_values(array_filter(array_map('intval', is_array($json) ? $json : (json_decode((string) $json, true) ?: [])), fn (int $i): bool => $i > 0));

        return match ($table) {
            'stranky' => ['kde' => 'vybrane', 'stranky' => [(int) $row['ids']]],
            'casti' => $pages($row['stranky'] ?? null) !== [] ? ['kde' => 'vybrane', 'stranky' => $pages($row['stranky'])] : ['kde' => 'vse', 'jazyk' => (string) ($row['jazyk'] ?? '')],
            'kolekce' => ['kde' => 'vybrane', 'kolekce' => [(string) $row['seo_link']]],
            'kolekce_sablony' => ['kde' => 'vybrane', 'kolekce' => [(string) $db->value('SELECT seo_link FROM {kolekce} WHERE idk = ?', [$row['idk']])], 'jazyk' => (string) ($row['jazyk'] ?? '')],
            default => ['kde' => 'vse'], // a component: wherever it is used – check the rules after the update
        };
    }

    private static function name(string $table, array $row, array $modal): string
    {
        $label = trim((string) ($modal['popis'] ?? ''));
        if ($label !== '') {
            return $label;
        }
        $where = (string) ($row['titulek'] ?? $row['nazev'] ?? $row['typ'] ?? $table);

        return 'Pop-up from “' . $where . '”';
    }

    /** Links to the old anchor (#nabidka in a button or in text) lead to the pop-up (#popup-<address>). */
    public static function rewriteLinks(string $json, array $map): string
    {
        foreach ($map as $anchor => $address) {
            $json = str_replace(['"#' . $anchor . '"', '\\"#' . $anchor . '\\"'], ['"#popup-' . $address . '"', '\\"#popup-' . $address . '\\"'], $json);
        }

        return $json;
    }

    /** A Modal in a pop-up or a saved section: its children stay, in a container instead of the window. */
    private static function unwrap(array $build): array
    {
        $walk = function (array $nodes) use (&$walk): array {
            foreach ($nodes as $i => $n) {
                if (!is_array($n)) {
                    continue;
                }
                if (($n['typ'] ?? '') === 'okno') {
                    $n = ['typ' => 'kontejner'] + array_intersect_key($n, array_flip(['id', 'styl', 'tridy', 'css', 'kotva', 'deti']));
                }
                if (is_array($n['deti'] ?? null)) {
                    $n['deti'] = $walk($n['deti']);
                }
                $nodes[$i] = $n;
            }

            return $nodes;
        };
        $build['deti'] = $walk(is_array($build['deti'] ?? null) ? $build['deti'] : []);

        return $build;
    }

    /** @return array<string, mixed> the WHERE of a row (site parts and collection templates have composite keys) */
    private static function where(string $table, ?string $key, array $row): array
    {
        return match ($table) {
            'casti' => ['typ' => $row['typ'], 'jazyk' => $row['jazyk'], 'varianta' => $row['varianta']],
            'kolekce_sablony' => ['idk' => $row['idk'], 'jazyk' => $row['jazyk']],
            default => [(string) $key => $row[$key]],
        };
    }
}
