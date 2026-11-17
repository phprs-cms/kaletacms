<?php

declare(strict_types=1);

namespace Kaleta\Core;

use Kaleta\Builder\Collections;
use Kaleta\Builder\Presets;

/**
 * Official notice board (2.11): a collection made from the notices preset (Builder\Presets, system/presets/notices.php)
 * for municipalities and public bodies. A notice is posted on a date and taken down on a date; while posted it is on the
 * board (a Collection list of the current period), afterwards in the archive (the past period) – never deleted – and
 * every change is traceable:
 *
 *  - {{notice_status}} on the item page says whether the notice is posted, to be posted or taken down (statusText);
 *  - a notice cannot go to the trash (admin and MCP refuse), and cannot be hidden once its posting date has come – hiding
 *    is for a notice still to be posted; the collection cannot be deleted while it has notices;
 *  - ka_notice_log is the append-only audit trail: created and changed (what changed: key => [old, new]) on every save
 *    – admin, MCP, import – and posted / taken_down written once each by the hourly job 'notices' (Core\Scheduler) when
 *    the day comes. Nothing edits or deletes its rows: no UI, no MCP. The admin item form shows it; the administrator
 *    downloads the whole log as CSV; Claude reads it with list_notice_log.
 *
 * The selection and the status are pure helpers (unit-tested); the preset key is remembered in ka_kolekce.preset, so a
 * board is recognised even after the administrator renames the collection – and only while it still has the posting
 * date field (Presets::field).
 */
final class Notices
{
    public const string PRESET = 'notices';

    public const array ACTIONS = ['created', 'changed', 'posted', 'taken_down'];

    /** The refusals: English for MCP, t() in the admin (the texts are in the admin dictionaries). */
    public const string REFUSAL_DELETE = 'Notices stay in the archive – change the takedown date instead.';
    public const string REFUSAL_HIDE = 'A notice that is or was posted cannot be hidden – it stays on the board until its takedown date and then in the archive. Change the dates instead.';
    public const string REFUSAL_COLLECTION = 'The notice board has %s notices – they stay in the archive, so the collection cannot be deleted.';

    /** Written by the job and by an import that brings no log of its own. */
    public const string SYSTEM = 'system';

    /** A collection created from the notices preset (whatever happened to its fields since). */
    public static function isNotices(array $collection): bool
    {
        return ($collection['preset'] ?? '') === self::PRESET;
    }

    /** A board the features can work with: made from the preset and the posting date field is still there. */
    public static function isBoard(array $collection): bool
    {
        return Presets::field($collection, self::PRESET, 'posted', ['datum']) !== null;
    }

    /**
     * The posting and takedown dates of an item (YYYY-MM-DD or ''); '' also when the field is gone.
     *
     * @param array<string, mixed> $data the item's field values
     * @return array{0: string, 1: string}
     */
    public static function dates(array $collection, array $data): array
    {
        $posted = Presets::field($collection, self::PRESET, 'posted', ['datum']);
        $takenDown = Presets::field($collection, self::PRESET, 'taken_down', ['datum']);

        return [$posted !== null ? (string) ($data[$posted] ?? '') : '', $takenDown !== null ? (string) ($data[$takenDown] ?? '') : ''];
    }

    /* ---------- status (pure) ---------- */

    /**
     * Where a notice is by its dates: 'upcoming' (to be posted), 'current' (on the board – the takedown day still counts),
     * 'archived' (the takedown day has passed), '' without dates. The same rule as the list periods (Collections::PERIODS):
     * a notice without a takedown date stays up.
     */
    public static function status(string $posted, string $takenDown, string $today): string
    {
        if ($posted === '' && $takenDown === '') {
            return '';
        }
        if ($takenDown !== '' && $takenDown < $today) {
            return 'archived';
        }

        return $posted !== '' && $posted > $today ? 'upcoming' : 'current';
    }

    /** The status for visitors ({{notice_status}}): "Posted from 3 Oct 2026 to 18 Oct 2026", "Taken down on … – archived", "To be posted on …". */
    public static function statusText(string $posted, string $takenDown, string $today): string
    {
        return match (self::status($posted, $takenDown, $today)) {
            'archived' => t('Taken down on %s – archived', format_date($takenDown)),
            'upcoming' => t('To be posted on %s', format_date($posted)),
            'current' => match (true) {
                $posted !== '' && $takenDown !== '' => t('Posted from %s to %s', format_date($posted), format_date($takenDown)),
                $posted !== '' => t('Posted from %s', format_date($posted)),
                default => t('Posted until %s', format_date($takenDown)),
            },
            default => '',
        };
    }

    /**
     * Placeholders only a notice board has (Collections::values): {{notice_status}}. Nothing for other collections.
     *
     * @param array<string, mixed> $item with data
     * @return array<string, array{0: string, 1: string}>
     */
    public static function placeholders(array $collection, array $item): array
    {
        if (!self::isBoard($collection)) {
            return [];
        }
        [$posted, $takenDown] = self::dates($collection, is_array($item['data'] ?? null) ? $item['data'] : []);

        return ['notice_status' => [self::statusText($posted, $takenDown, date('Y-m-d')), 'text']];
    }

    /* ---------- the permanent archive ---------- */

    /** A notice may be hidden only while it is still to be posted (or has no posting date yet). */
    public static function canHide(string $posted, string $today): bool
    {
        return $posted === '' || $posted > $today;
    }

    /**
     * Whether a save must be refused because it would hide a notice that is (or was) on the board.
     *
     * @param array<string, mixed> $data the field values being saved
     */
    public static function refusesHiding(array $collection, array $data, bool $visible): bool
    {
        if ($visible || !self::isBoard($collection)) {
            return false;
        }

        return !self::canHide(self::dates($collection, $data)[0], date('Y-m-d'));
    }

    /** How many notices a board has (also hidden ones and the trash – none of them may be lost); 0 for any other collection. */
    public static function count(Db $db, array $collection): int
    {
        return self::isNotices($collection) ? (int) $db->value('SELECT COUNT(*) FROM {kolekce_polozky} WHERE idk = ?', [(int) $collection['idk']]) : 0;
    }

    /* ---------- the audit trail ---------- */

    /** Who makes the change: the user's name, "Claude" through a connection, "system" otherwise (the job). */
    public static function actor(App $app): string
    {
        if ($app->auth()->connection() !== null) {
            return 'Claude';
        }
        $user = $app->auth()->user();
        $name = (string) ($user['jmeno'] ?? '') ?: (string) ($user['user'] ?? '');

        return $name !== '' ? mb_substr($name, 0, 100) : self::SYSTEM;
    }

    /**
     * Appends one row. The only way anything gets into ka_notice_log – there is no update or delete of it anywhere.
     *
     * @param array<string, mixed> $fields what changed: key => [old, new]; the job writes [action => date]
     */
    public static function log(Db $db, int $idp, string $action, array $fields, string $by): void
    {
        if (!in_array($action, self::ACTIONS, true)) {
            throw new \InvalidArgumentException('Unknown notice log action ' . $action);
        }
        $db->insert('notice_log', ['idp' => $idp, 'action' => $action, 'at' => date('Y-m-d H:i:s'), 'by' => mb_substr($by, 0, 100),
            'fields' => (string) json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    }

    /**
     * After a save of an item (admin, MCP, a restored version): 'created' for a new notice with its values, 'changed'
     * with what changed; a save that changed nothing writes nothing. Other collections are not logged.
     *
     * @param array<string, mixed>|null $previous the row before the save (null = new)
     * @param array<string, mixed> $row the columns written (data as JSON or an array)
     */
    public static function recordSave(App $app, array $collection, ?array $previous, array $row, int $idp, ?string $by = null): void
    {
        if (!self::isNotices($collection)) {
            return;
        }
        $changes = self::changes($collection, $previous, $row);
        if ($previous !== null && $changes === []) {
            return;
        }
        self::log($app->db(), $idp, $previous === null ? 'created' : 'changed', $changes, $by ?? self::actor($app));
    }

    /**
     * What a save changes (pure): the name, the address, the visibility and every field of the collection, as
     * key => [old, new]. For a new item every value that is not empty, with '' as the old one.
     *
     * @param array<string, mixed>|null $previous
     * @param array<string, mixed> $row
     * @return array<string, array{0: string, 1: string}>
     */
    public static function changes(array $collection, ?array $previous, array $row): array
    {
        $values = function (array $r) use ($collection): array {
            $data = is_array($r['data'] ?? null) ? $r['data'] : (json_decode((string) ($r['data'] ?? ''), true) ?: []);
            $out = ['name' => (string) ($r['nazev'] ?? ''), 'slug' => (string) ($r['seo_link'] ?? '')];
            if (array_key_exists('zobrazit', $r)) {
                $out['visible'] = (int) $r['zobrazit'] === 1 ? 'yes' : 'no';
            }
            foreach ((array) ($collection['pole'] ?? []) as $f) {
                $out[(string) $f['klic']] = (string) ($data[$f['klic']] ?? '');
            }

            return $out;
        };
        $old = $previous === null ? [] : $values($previous);
        $new = $values($previous === null ? $row : array_replace($previous, $row));
        $changes = [];
        foreach ($new as $key => $value) {
            $before = (string) ($old[$key] ?? '');
            if ($previous === null ? $value !== '' : $before !== $value) {
                $changes[$key] = [$before, $value];
            }
        }

        return $changes;
    }

    /**
     * The log of one notice, or of the whole board, oldest first – with the notice's name.
     *
     * @return list<array<string, mixed>> id, idp, action, at, by, fields (decoded), nazev
     */
    public static function entries(Db $db, int $idk, ?int $idp = null): array
    {
        $rows = $db->all('SELECT l.id, l.idp, l.action, l.`at`, l.`by`, l.fields, p.nazev FROM {notice_log} l LEFT JOIN {kolekce_polozky} p ON p.idp = l.idp WHERE '
            . ($idp !== null ? 'l.idp = ?' : 'l.idp IN (SELECT idp FROM {kolekce_polozky} WHERE idk = ?)') . ' ORDER BY l.id', [$idp ?? $idk]);
        foreach ($rows as &$r) {
            $r['fields'] = json_decode((string) $r['fields'], true) ?: [];
        }

        return $rows;
    }

    /** The changes of one row as text: "posted: → 2026-10-03; name: Draft → Budget 2026". */
    public static function changesText(array $fields): string
    {
        return implode('; ', array_map(fn (string $key, mixed $value): string => $key . ': ' . (is_array($value) ? (($value[0] ?? '') !== '' ? (string) $value[0] . ' → ' : '→ ') . (string) ($value[1] ?? '') : (string) $value),
            array_keys($fields), $fields));
    }

    /** The whole log of a board as CSV (UTF-8 with BOM, semicolon – opens directly in Excel), like the enquiries export. */
    public static function csv(Db $db, array $collection): string
    {
        $f = fopen('php://temp', 'w+');
        fwrite($f, "\xEF\xBB\xBF");
        fputcsv($f, [t('Number'), t('Date'), t('Notice'), t('Název'), t('Action'), t('By'), t('Changes')], ';', '"', '');
        foreach (self::entries($db, (int) $collection['idk']) as $r) {
            // a cell starting with = + - @ would run as a formula in a spreadsheet
            $row = array_map(fn (string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v,
                [(string) $r['id'], (string) $r['at'], (string) $r['idp'], (string) ($r['nazev'] ?? ''), (string) $r['action'], (string) $r['by'], self::changesText($r['fields'])]);
            fputcsv($f, $row, ';', '"', '');
        }
        rewind($f);
        $csv = (string) stream_get_contents($f);
        fclose($f);

        return $csv;
    }

    /* ---------- the hourly job ---------- */

    /** The hourly run (Core\Scheduler 'notices'): records what the boards posted and took down. Returns a short result. */
    public static function run(App $app): string
    {
        $db = $app->db();
        $today = date('Y-m-d');
        $posted = 0;
        $takenDown = 0;
        foreach (Collections::all($db) as $collection) {
            if (!self::isBoard($collection)) {
                continue;
            }
            $items = [];
            foreach ($db->all('SELECT idp, zobrazit, data FROM {kolekce_polozky} WHERE idk = ? AND smazano IS NULL', [(int) $collection['idk']]) as $r) {
                [$from, $to] = self::dates($collection, json_decode((string) $r['data'], true) ?: []);
                $items[] = ['idp' => (int) $r['idp'], 'visible' => (bool) $r['zobrazit'], 'posted' => $from, 'taken_down' => $to];
            }
            $logged = [];
            foreach ($db->all("SELECT idp, action FROM {notice_log} WHERE action IN ('posted', 'taken_down') AND idp IN (SELECT idp FROM {kolekce_polozky} WHERE idk = ?)", [(int) $collection['idk']]) as $r) {
                $logged[(int) $r['idp']][(string) $r['action']] = true;
            }
            foreach (self::due($items, $logged, $today) as [$idp, $action, $date]) {
                self::log($db, $idp, $action, [$action => $date], self::SYSTEM);
                $action === 'posted' ? $posted++ : $takenDown++;
            }
        }
        if ($posted + $takenDown > 0) {
            \Kaleta\Core\PageCache::clear(); // the board and the archive change; the item pages are never cached (Front\Kernel)
        }

        return 'posted ' . $posted . ', taken down ' . $takenDown;
    }

    /**
     * What the job records today (pure): 'posted' for a visible notice whose posting day has come and has no posted row
     * yet; 'taken_down' once the takedown day has passed, for a notice that was posted (a row exists or is added now) –
     * each once.
     *
     * @param list<array{idp: int, visible: bool, posted: string, taken_down: string}> $items
     * @param array<int, array<string, true>> $logged idp => [action => true] of the rows already written
     * @return list<array{0: int, 1: string, 2: string}> [idp, action, the date]
     */
    public static function due(array $items, array $logged, string $today): array
    {
        $out = [];
        foreach ($items as $i) {
            $idp = (int) $i['idp'];
            $wasPosted = isset($logged[$idp]['posted']);
            if (!$wasPosted && !empty($i['visible']) && $i['posted'] !== '' && $i['posted'] <= $today) {
                $out[] = [$idp, 'posted', $i['posted']];
                $wasPosted = true;
            }
            if ($wasPosted && !isset($logged[$idp]['taken_down']) && $i['taken_down'] !== '' && $i['taken_down'] < $today) {
                $out[] = [$idp, 'taken_down', $i['taken_down']];
            }
        }

        return $out;
    }
}
