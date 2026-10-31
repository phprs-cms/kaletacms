<?php

declare(strict_types=1);

namespace Kaleta\Builder;

use Kaleta\Core\App;
use Kaleta\Core\Db;

/**
 * Publishing a build draft (page, site part, collection, component or popup) – from the editor and from MCP. The previous published version goes to the history
 * (ka_stavba_revize, the last 20 for each target), the site cache is cleared. Only a person or a connection that may publish
 * gets here, so the files waiting for approval the build uses – its own and those of the shared classes it lists – go public
 * with it (Core\MediaStaging::promoteBuild).
 */
final class Publisher
{
    public const int VERSIONS_KEPT = 20;

    /** Page: the content without the layout is saved into the text – search, llms.txt, the API and the return to text draw on it. */
    public static function page(App $app, array $page): void
    {
        $new = $page['stavba_koncept'] ?? $page['stavba'];
        self::version($app, ['ids' => $page['ids']], $page['stavba'], $new, $page['zmeneno'] ?? null);
        $text = Build::asText(Build::fromJson($new) ?? []);
        $app->db()->update('stranky', ['stavba' => $new, 'stavba_koncept' => null] + \Kaleta\Admin\Modules\Pages::edited($app->auth()->id()) + ($text !== '' ? ['text' => $text] : []), ['ids' => $page['ids']]); // when and who (3.20, C11)
        // a page that waited hidden for its first build (3.5, Modules\Pages::visibility) goes on the site with it – unless a
        // publisher set a publish date: then the schedule shows it, not this publish (N35-1)
        $now = date('Y-m-d H:i:s');
        $app->db()->run('UPDATE {stranky} SET zobrazit = 1, show_on_publish = 0, zverejnit_od = NULL WHERE ids = ? AND show_on_publish = 1 AND smazano IS NULL AND (zverejnit_od IS NULL OR zverejnit_od <= ?)', [$page['ids'], $now]);
        $app->db()->run('UPDATE {stranky} SET show_on_publish = 0 WHERE ids = ? AND show_on_publish = 1 AND zverejnit_od > ?', [$page['ids'], $now]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Front\Cache::clear();
    }

    public static function part(App $app, array $row): void
    {
        $new = $row['stavba_koncept'] ?? $row['stavba'];
        $variant = (string) ($row['varianta'] ?? '');
        self::version($app, ['cast' => SiteParts::versionKey($row['typ'], $row['jazyk'], $variant)], $row['stavba'], $new, $row['zmeneno'] ?? null);
        $app->db()->update('casti', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['typ' => $row['typ'], 'jazyk' => $row['jazyk'], 'varianta' => $variant]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Front\Cache::clear();
    }

    /**
     * Item template of a collection in the language from Collections::inLanguage (versions under the key „kolekce:<idk>“,
     * for another language „kolekce:<idk>:<jazyk>“).
     */
    public static function collection(App $app, array $collection): void
    {
        $new = $collection['stavba_koncept'] ?? $collection['stavba'];
        self::version($app, ['cast' => Collections::templateKey($collection)], $collection['stavba'], $new, $collection['zmeneno'] ?? null);
        Collections::writeTemplate($app->db(), $collection, ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Front\Cache::clear();
    }

    /** Popup (versions under the key „popup:<idpp>“). */
    public static function popup(App $app, array $popup): void
    {
        $new = $popup['stavba_koncept'] ?? $popup['stavba'];
        self::version($app, ['cast' => 'popup:' . (int) $popup['idpp']], $popup['stavba'], $new, $popup['zmeneno'] ?? null);
        $app->db()->update('popupy', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['idpp' => $popup['idpp']]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Front\Cache::clear();
    }

    /** Component (versions under the key „komponenta:<idm>“) – the change shows on all pages where it is used. */
    public static function component(App $app, array $component): void
    {
        $new = $component['stavba_koncept'] ?? $component['stavba'];
        self::version($app, ['cast' => 'komponenta:' . (int) $component['idm']], $component['stavba'], $new, $component['zmeneno'] ?? null);
        $app->db()->update('komponenty', ['stavba' => $new, 'stavba_koncept' => null, 'zmeneno' => date('Y-m-d H:i:s')], ['idm' => $component['idm']]);
        \Kaleta\Core\MediaStaging::promoteBuild($app->db(), (string) $new); // 3.15: its waiting files and those of its classes (N315-1)
        \Kaleta\Front\Cache::clear();
    }

    /** Saves the previous published version to the history. @param array{ids?: int|string, cast?: string} $target */
    public static function version(App $app, array $target, ?string $old, ?string $newVersion, ?string $date): void
    {
        // 2.8: every publishing is an event (who: a user id and whether it came through a Claude connection)
        \Kaleta\Core\Events::record($app->db(), 'build.published', 'info', t('Published: %s', isset($target['ids']) ? 'page ' . (int) $target['ids'] : (string) ($target['cast'] ?? '')),
            $target + ['user' => $app->auth()->id() ?: null, 'claude' => $app->auth()->connection() !== null]);
        if ($old === null || $old === $newVersion) {
            return;
        }
        $db = $app->db();
        $db->insert('stavba_revize', $target + ['datum' => $date ?? date('Y-m-d H:i:s'), 'kdo' => $app->auth()->id() ?: null, 'stavba' => $old]);
        [$whereParts, $value] = self::whereClause($target);
        $boundary = $db->value('SELECT idr FROM {stavba_revize} WHERE ' . $whereParts . ' ORDER BY idr DESC LIMIT 1 OFFSET ' . self::VERSIONS_KEPT, [$value]);
        if ($boundary !== null) {
            $db->run('DELETE FROM {stavba_revize} WHERE ' . $whereParts . ' AND idr <= ?', [$value, $boundary]);
        }
    }

    /** @param array{ids?: int|string, cast?: string} $target @return list<array<string, mixed>> */
    public static function listAll(Db $db, array $target): array
    {
        [$whereParts, $value] = self::whereClause($target);

        return $db->all("SELECT r.idr, r.datum, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS kdo FROM {stavba_revize} r LEFT JOIN {uzivatele} u ON u.idu = r.kdo WHERE r." . $whereParts . ' ORDER BY r.idr DESC', [$value]);
    }

    /** @param array{ids?: int|string, cast?: string} $target */
    public static function load(Db $db, array $target, int $idr): ?string
    {
        [$whereParts, $value] = self::whereClause($target);
        $build = $db->value('SELECT stavba FROM {stavba_revize} WHERE idr = ? AND ' . $whereParts, [$idr, $value]);

        return $build === null ? null : (string) $build;
    }

    /** @return array{0: string, 1: int|string} */
    private static function whereClause(array $target): array
    {
        return isset($target['ids']) ? ['ids = ?', (int) $target['ids']] : ['cast = ?', (string) $target['cast']];
    }
}
